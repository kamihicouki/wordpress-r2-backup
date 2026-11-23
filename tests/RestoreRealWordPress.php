<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use RuntimeException;
use WordPressR2Backup\RestoreService;

/**
 * Real WordPress core with synthetic content only: a throwaway MySQL server, a freshly installed source site captured into a local
 * repository, and a second installed WordPress running this plugin. No real site, person's data, key or R2 is involved.
 * The official archive is given by the caller, e.g. Local's cached-wordpress directory, and only ever read.
 */
final class RestoreRealWordPress
{
    public RestoreTestBed $bed;
    public string $root;
    public string $core;
    public string $hostUrl = '';
    public string $hostPassword;
    public array $connections = [];
    public string $connectionsFile = '';
    public string $hostState = '';
    private $host = null;
    private string $sourceSchema = '';

    public static function build(string $archive, int $hostPort): self
    {
        if (!is_file($archive)) { throw new RuntimeException('The WordPress archive is missing.'); }
        $self = new self();
        $self->root = Fixture::temp();
        $extract = $self->root . '/core'; mkdir($extract, 0700);
        self::run(['/usr/bin/tar', '-xzf', $archive, '-C', $extract], $self->root);
        $self->core = $extract . '/wordpress';
        if (!is_file($self->core . '/wp-includes/version.php')) { throw new RuntimeException('The archive holds no WordPress core.'); }
        $self->bed = RestoreTestBed::assemble();
        $server = $self->bed->server;
        $self->buildSource();
        $self->buildHost($hostPort);
        return $self;
    }

    private function buildSource(): void
    {
        $server = $this->bed->server; $schema = 'wrb_test_source' . bin2hex(random_bytes(3));
        $server->query('', 'CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $site = $this->root . '/source-site';
        $this->copyCore($site);
        Fixture::write($site, 'wp-content/uploads/2026/11/synthetic.bin', "\x00\xffsynthetic-media");
        $this->writeConfig($site, $schema, 'root', '', []);
        $sourceUrl = var_export(RestoreTestBed::SOURCE_URL, true);
        // Give wp_guess_url() the source's HTTPS admin request before WordPress creates its default content.
        $this->install($site, 'source.invalid', bin2hex(random_bytes(16)),
            "update_option('home', $sourceUrl); update_option('siteurl', $sourceUrl); "
            . "wp_insert_post(['post_title'=>'合成の公開記事','post_content'=>'<a href=\"" . RestoreTestBed::SOURCE_URL . "/wrb\">本文リンク</a>','post_status'=>'publish']);",
            ['HTTPS' => 'on', 'REQUEST_URI' => '/wp-admin/install.php']);
        $connection = mysqli_init();
        if (!$connection || !mysqli_real_connect($connection, 'localhost', 'root', '', $schema, 0, $server->workspace() . '/mysql.sock')) { throw new RuntimeException('Cannot connect to the source.'); }
        mysqli_set_charset($connection, 'utf8mb4');
        $this->bed->capture(new V4DatabaseMysqliAdapter($connection), $site, RestoreTestBed::SOURCE_URL . '/', $this->root);
        $connection->close();
        $this->sourceSchema = $schema;
    }

    private function buildHost(int $port): void
    {
        $server = $this->bed->server; $schema = 'wrb_test_host' . bin2hex(random_bytes(3));
        $server->query('', 'CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $site = $this->root . '/host-site'; $this->copyCore($site);
        $this->hostUrl = 'http://127.0.0.1:' . $port; $this->hostPassword = bin2hex(random_bytes(16));
        foreach (['plugin', 'independent'] as $id) { $this->connections[$id] = RestoreTestBed::connectionSet($server, $id); }
        $file = $this->root . '/connections.json';
        file_put_contents($file, json_encode(['version' => 1, 'connections' => array_values(array_map(static function (array $set): array { return array_diff_key($set, ['_schema' => 1, '_importer' => 1, '_runner' => 1]); }, $this->connections))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        chmod($file, 0600); $this->connectionsFile = $file;
        $this->hostState = $this->root . '/host-state'; mkdir($this->hostState, 0700);
        $temporary = $this->root . '/host-tmp'; mkdir($temporary, 0700);
        symlink(dirname(__DIR__), $site . '/wp-content/plugins/wordpress-r2-backup');
        $this->writeConfig($site, $schema, 'root', '', ['WP_HOME' => $this->hostUrl, 'WP_SITEURL' => $this->hostUrl, 'WRB_RESTORE_STATE_DIR' => $this->hostState, 'WRB_RESTORE_MYSQL_CONNECTIONS' => $file, 'WRB_RESTORE_LOCAL_REPOSITORY' => $this->bed->repository, 'WRB_RESTORE_LOCAL_UNIT' => $this->bed->unit, 'WRB_PHP_CLI' => PHP_BINARY]);
        $this->install($site, '127.0.0.1:' . $port, $this->hostPassword, "update_option('active_plugins',['wordpress-r2-backup/wordpress-r2-backup.php']);");
        $this->host = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $site], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $site, ['PATH' => '/usr/bin:/bin', 'HOME' => (string) getenv('HOME'), 'TMPDIR' => $temporary], ['bypass_shell' => true]);
        $deadline = microtime(true) + 15;
        do {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $c, $m, 0.3);
            if ($socket !== false) { fclose($socket); return; }
            usleep(100000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('The WordPress host did not start.');
    }

    public function stop(): void
    {
        if (is_resource($this->host)) { proc_terminate($this->host, 15); proc_close($this->host); $this->host = null; }
        // The plugin entry's running copy outlives its web request by design; a fixture must end it.
        foreach (is_dir($this->hostState . '/jobs') ? (scandir($this->hostState . '/jobs') ?: []) : [] as $name) {
            if ($name[0] === '.') { continue; }
            try { $service = new RestoreService($this->bed->environment($this->hostState)); $service->stopSite($name); $service->stop($name); } catch (\Throwable $ignored) { }
        }
        $this->bed->server->stop();
    }

    private function copyCore(string $destination): void
    {
        self::run(['/bin/cp', '-R', $this->core, $destination], $this->root);
        self::run(['/bin/chmod', '-R', 'go-rwx', $destination], $this->root);
    }

    private function writeConfig(string $site, string $schema, string $user, string $password, array $constants): void
    {
        $socket = $this->bed->server->workspace() . '/mysql.sock';
        $config = "<?php\n";
        foreach (['DB_NAME' => $schema, 'DB_USER' => $user, 'DB_PASSWORD' => $password, 'DB_HOST' => 'localhost:' . $socket, 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '', 'DISABLE_WP_CRON' => true, 'WP_HTTP_BLOCK_EXTERNAL' => true, 'AUTOMATIC_UPDATER_DISABLED' => true, 'DISALLOW_FILE_MODS' => true, 'WP_DEBUG' => false, 'WP_ENVIRONMENT_TYPE' => 'local'] + $constants as $name => $value) { $config .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n"; }
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) { $config .= "define('$name', '" . bin2hex(random_bytes(32)) . "');\n"; }
        file_put_contents($site . '/wp-config.php', $config . "\$table_prefix = 'wp_';\nif (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\nrequire_once ABSPATH . 'wp-settings.php';\n");
    }

    private function install(string $site, string $host, string $password, string $after, array $request = []): void
    {
        $script = $this->root . '/install-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, '<?php define("WP_INSTALLING", true); $_SERVER=' . var_export(['HTTP_HOST' => $host] + $request, true) . ' + $_SERVER; require ' . var_export($site . '/wp-load.php', true) . '; add_filter("pre_wp_mail", static function () { return true; }); require ABSPATH . "wp-admin/includes/upgrade.php"; wp_install("合成サイト", "synthetic-owner", "owner@example.invalid", true, "", ' . var_export($password, true) . ', "ja"); ' . $after);
        self::run([PHP_BINARY, $script], $site);
        unlink($script);
    }

    private static function run(array $command, string $directory): void
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, null, ['bypass_shell' => true]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException('A fixture command failed: ' . substr((string) $error, 0, 400)); }
    }
}
