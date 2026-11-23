<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use WordPressR2Backup\{AgeProcess, LocalRepository, Manifest, PrivacyPolicy, RestoreService, SourceRoots, V4Capture, V4Format};

require_once __DIR__ . '/WordPressDatabaseIntegrationTest.php';

/** One synthetic WordPress-shaped v4 generation, for the restore screen's tests. Nothing here touches a real site, key or database. */
final class RestoreTestBed
{
    public const SOURCE_URL = 'https://source.invalid';
    public const TARGET_URL = 'https://new.example.com';
    public const ADMIN_PASSWORD = 'synthetic-admin-password-MARK1';

    public IsolatedMysql $server;
    public string $root;
    public string $repository;
    public string $unit;
    public string $snapshot;
    public string $identity;
    public string $identityFile = '';

    public static function available(): bool
    {
        return getenv('WRB_RUN_V4_MYSQL_INTEGRATION') === '1' && extension_loaded('mysqli') && extension_loaded('pdo_sqlite') && extension_loaded('pdo_mysql');
    }

    public static function build(string $engine = 'mysql', ?string $siteUrlOption = null, ?string $recordedUrl = null): self
    {
        $bed = new self();
        // A MariaDB source cannot list its events while the scheduler is disabled; a restore target must have it disabled.
        $bed->server = IsolatedMysql::start(null, $engine === 'mysql' ? 'DISABLED' : 'OFF', $engine);
        $schema = $bed->server->newSchema();
        $defaults = parse_ini_file($bed->server->defaultsFile(), true, INI_SCANNER_RAW);
        $connection = mysqli_init();
        if (!$connection || !mysqli_real_connect($connection, 'localhost', 'root', '', $schema, 0, $defaults['client']['socket'])) { throw new \RuntimeException('Cannot connect to the isolated source.'); }
        mysqli_set_charset($connection, 'utf8mb4');
        $source = new V4DatabaseMysqliAdapter($connection);
        $url = self::SOURCE_URL;
        $serialized = serialize(['url' => $url . '/page', 'keep' => 'https://source.invalid.example/x']);
        $source->executeBatch("SET SESSION sql_mode='';
CREATE TABLE wp_options(option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,option_name VARCHAR(191) NOT NULL,option_value LONGTEXT NOT NULL,autoload VARCHAR(20) NOT NULL DEFAULT 'yes',PRIMARY KEY(option_id),UNIQUE KEY option_name(option_name));
CREATE TABLE wp_posts(ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,post_title TEXT NOT NULL,post_content LONGTEXT NOT NULL,PRIMARY KEY(ID));
CREATE TABLE wp_users(ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_login VARCHAR(60) NOT NULL,user_pass VARCHAR(255) NOT NULL,user_nicename VARCHAR(50) NOT NULL,user_email VARCHAR(100) NOT NULL,user_url VARCHAR(100) NOT NULL,user_registered DATETIME NOT NULL,user_activation_key VARCHAR(255) NOT NULL,user_status INT NOT NULL,display_name VARCHAR(250) NOT NULL,PRIMARY KEY(ID));
CREATE TABLE wp_usermeta(umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id BIGINT UNSIGNED NOT NULL,meta_key VARCHAR(255),meta_value LONGTEXT,PRIMARY KEY(umeta_id));
INSERT INTO wp_options(option_name,option_value) VALUES('home','$url'),('siteurl','" . ($siteUrlOption ?? $url) . "'),('wrb_serialized','" . $connection->real_escape_string($serialized) . "'),('wrb_json','{\"u\":\"https:\\\\/\\\\/source.invalid\\\\/j\"}');
INSERT INTO wp_posts(ID,post_title,post_content) VALUES(12,'復元テスト','<a href=\"$url/a\">a</a> <a href=\"https://source.invalid.example/b\">b</a>');
INSERT INTO wp_users(ID,user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,user_status,display_name) VALUES(7,'synthetic','x','synthetic','a@example.invalid','','2020-01-01 00:00:00','',0,'Synthetic');");
        $base = Fixture::temp();
        $site = $base . '/source-site'; mkdir($site, 0700);
        foreach (self::siteFiles() as $path => $bytes) { Fixture::write($site, $path, $bytes); chmod($site . '/' . $path, 0640); }
        $bed->capture($source, $site, $recordedUrl ?? $url, $base);
        $connection->close();
        if ($engine !== 'mysql') { $bed->server->stop(); $bed->server = IsolatedMysql::start(null, 'DISABLED', $engine); }
        return $bed;
    }

    /** Encrypts one source directory and database into a new local repository under $base. */
    public function capture($source, string $site, string $url, string $base): void
    {
        $bed = $this; $bed->root = $base;
        $work = $base . '/source-work'; $bed->repository = $base . '/repository';
        foreach ([$work, $bed->repository] as $directory) { mkdir($directory, 0700); }
        $keys = Fixture::keys(2); $recipients = array_column($keys, 'public'); $age = new AgeProcess(Fixture::runtime()['age']);
        $bed->identityFile = $keys[0]['identity']; $bed->identity = (string) file_get_contents($keys[0]['identity']);
        $bed->unit = str_repeat('d', 64); $bed->snapshot = bin2hex(random_bytes(32));
        $scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'restore-screen-test', 'unitId' => $bed->unit, 'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $capture = (new V4Capture(new SourceRoots($site), $source, new PrivacyPolicy(['version' => 2, 'preset' => 'full']), $work, null, $url . '/'))->capture($age, $recipients, $bed->snapshot, $scope);
        $repository = LocalRepository::forWriting($bed->repository, $bed->unit);
        foreach ($capture['objects'] as $object) { $repository->putNew($object['object']['key'], $object['cipherFile']); }
        $rootCipher = $base . '/root.age'; $age->encryptStream([V4Format::json($capture['manifest'])], $rootCipher, $recipients);
        $receipt = $repository->putNew('manifests/' . $bed->snapshot . '.age', $rootCipher);
        $repository->publishWithObjects($bed->snapshot, $receipt, array_column($capture['objects'], 'object'));
    }

    /** Only the server, for a caller that captures its own source site. */
    public static function assemble(): self
    {
        $bed = new self();
        $bed->server = IsolatedMysql::start(null, 'DISABLED');
        return $bed;
    }

    /** A fresh pair of empty schemas and three accounts; returns the registered connection set. */
    public static function connectionSet(IsolatedMysql $server, string $id, bool $registeredAccounts = true): array
    {
        $tag = bin2hex(random_bytes(4));
        $schema = 'wrb_test_' . $tag;
        $engine = $server->engine();
        $bin = $engine === 'mysql' ? IsolatedMysql::mysqlBin() : ($engine === 'mariadb105' ? IsolatedMysql::mariadb105Bin() : IsolatedMysql::mariadbBin());
        foreach ([$schema, $schema . '_run'] as $name) { $server->query('', 'CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); }
        $importer = ['user' => 'wrb_imp_' . $tag, 'password' => 'importer-password-MARK2-' . $tag];
        $runner = ['user' => 'wrb_run_' . $tag, 'password' => 'runner-password-MARK3-' . $tag];
        $privileges = implode(', ', IsolatedMysql::IMPORTER_PRIVILEGES);
        foreach ([[$importer, $schema], [$runner, $schema . '_run']] as [$account, $name]) {
            $server->query('', "CREATE USER '" . $account['user'] . "'@'localhost' IDENTIFIED BY '" . $account['password'] . "'");
            $server->query('', 'GRANT ' . $privileges . ' ON `' . str_replace('_', '\\_', $name) . "`.* TO '" . $account['user'] . "'@'localhost'");
        }
        $set = ['id' => $id, 'label' => '合成 ' . $id, 'workspace' => $server->workspace(), 'socket' => $server->workspace() . '/mysql.sock', 'mysql' => $bin . ($engine === 'mysql' ? 'mysql' : 'mariadb'), 'mysqldump' => $bin . ($engine === 'mysql' ? 'mysqldump' : 'mariadb-dump')];
        if ($registeredAccounts) { $set += ['database' => $schema, 'inspector' => ['user' => 'root', 'password' => ''], 'importer' => $importer, 'runner' => $runner]; }
        return $set + ['_schema' => $schema, '_importer' => $importer, '_runner' => $runner];
    }

    public function environment(string $base, array $connections = [], array $extra = []): array
    {
        $registered = [];
        foreach ($connections as $set) { $registered[$set['id']] = array_diff_key($set, ['_schema' => 1, '_importer' => 1, '_runner' => 1]); }
        return $extra + ['base' => $base, 'age' => Fixture::runtime()['age'], 'source' => ['type' => 'repository', 'path' => $this->repository, 'unit' => $this->unit], 'connections' => $registered, 'viewerLocal' => true, 'php' => PHP_BINARY];
    }

    public function input(string $name, int $port, array $changes = []): array
    {
        return $changes + ['snapshot' => $this->snapshot, 'name' => $name, 'purpose' => 'startup-check', 'database' => 'sqlite', 'targetUrl' => self::TARGET_URL, 'previewPort' => (string) $port, 'tablePrefix' => 'wp_', 'createPreviewAdmin' => '1', 'adminLogin' => 'restore-admin'];
    }

    public function secrets(array $changes = []): array
    {
        return $changes + ['identity' => $this->identity, 'adminPassword' => self::ADMIN_PASSWORD];
    }

    public static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);
        return $port;
    }

    /** @return array{0:int,1:array<string,string>,2:string} status, headers (cookies joined by newline under set-cookie), body */
    public static function http(int $port, string $method, string $path, array $headers = [], string $body = ''): array
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $code, $message, 3.0);
        if ($socket === false) { throw new \RuntimeException('Cannot connect to the test server.'); }
        stream_set_timeout($socket, 30);
        $headers += ['Host' => '127.0.0.1:' . $port, 'Connection' => 'close'];
        if ($method === 'POST') { $headers += ['Content-Type' => 'application/x-www-form-urlencoded', 'Content-Length' => (string) strlen($body)]; }
        $request = $method . ' ' . $path . " HTTP/1.1\r\n";
        foreach ($headers as $name => $value) { $request .= $name . ': ' . $value . "\r\n"; }
        fwrite($socket, $request . "\r\n" . $body);
        $response = (string) stream_get_contents($socket); fclose($socket);
        [$head, $content] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        $lines = explode("\r\n", $head); $parsed = [];
        foreach (array_slice($lines, 1) as $line) { [$name, $value] = array_pad(explode(':', $line, 2), 2, ''); $key = strtolower($name); $parsed[$key] = $key === 'set-cookie' && isset($parsed[$key]) ? $parsed[$key] . "\n" . trim($value) : trim($value); }
        return [(int) (explode(' ', $lines[0])[1] ?? 0), $parsed, $content];
    }

    public static function sqliteValue(string $file, string $sql): string
    {
        $db = new \PDO('sqlite:' . $file); $value = $db->query($sql)->fetchColumn(); $db = null;
        return (string) $value;
    }

    private static function siteFiles(): array
    {
        return [
            'wp-load.php' => '<?php', 'wp-includes/version.php' => '<?php $wp_version = "synthetic";',
            'wp-content/uploads/画像.bin' => "\x00\xffimage", 'wp-content/themes/synthetic/style.css' => 'body{color:#222}',
            'wp-config.php' => "<?php // the source site's own settings: never copied into the running site\n",
            'wp-settings.php' => <<<'PHP'
<?php
if (defined('DB_ENGINE')) { $wrbDb = new PDO('sqlite:' . DB_PATH); }
else { $wrbDb = new PDO('mysql:unix_socket=' . substr(DB_HOST, strlen('localhost:')) . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD); }
$wrbHome = $wrbDb->query("SELECT option_value FROM {$table_prefix}options WHERE option_name='home'")->fetchColumn();
PHP,
            'index.php' => <<<'PHP'
<?php
require __DIR__ . '/wp-config.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$links = '<a href="' . $wrbHome . '/post">post</a><img src="//new.example.com/x.png"><script>var u="https:\/\/new.example.com\/api";fetch(u);</script><a href="/go?to=https%3A%2F%2Fnew.example.com%2Fa">e</a><a href="https://new.example.community/keep">keep</a><p>new.example.com</p><span>' . WP_HOME . '</span>';
if ($path === '/wp-json/wp/v2/posts') { header('Content-Type: application/json'); echo json_encode(['link' => $wrbHome . '/post', 'home' => WP_HOME]); exit; }
if ($path === '/wp-admin/') { echo '<title>admin</title>' . $links; exit; }
if ($path === '/redirect') { header('Location: ' . $wrbHome . '/elsewhere'); http_response_code(302); exit; }
if ($path === '/probe') {
    $results = [];
    $tcp = @stream_socket_client('tcp://127.0.0.1:' . (int) $_GET['port'], $c, $m, 1.0); $results['tcp'] = is_resource($tcp);
    $socket = @stream_socket_client('unix://' . $_GET['socket'], $c, $m, 1.0); $results['otherSocket'] = is_resource($socket);
    $results['neighbor'] = @file_get_contents(dirname(__DIR__) . '/job.json') !== false;
    if (isset($_GET['original'])) { try { $wrbDb->query('SELECT COUNT(*) FROM `' . $_GET['original'] . '`.wp_options')->fetchColumn(); $results['original'] = true; } catch (Throwable $e) { $results['original'] = false; } catch (Exception $e) { $results['original'] = false; } }
    $results['database'] = $wrbHome;
    header('Content-Type: application/json'); echo json_encode($results); exit;
}
echo $links;
PHP,
        ];
    }
}
