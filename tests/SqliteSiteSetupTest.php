<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\SqliteSiteSetup;

final class SqliteSiteSetupTest extends TestCase
{
    private string $root;
    private string $implementation;
    private string $source;
    private string $database;
    private $server = null;

    protected function setUp(): void
    {
        $this->implementation = dirname(__DIR__) . '/dependencies/sqlite-database-integration';
        if (!is_file($this->implementation . '/wp-includes/database/load.php')) { self::markTestSkipped('The packaged official SQLite implementation is required.'); }
        require_once $this->implementation . '/wp-includes/database/load.php';
        $this->root = Fixture::temp(); $this->source = $this->root . '/original'; mkdir($this->source, 0700);
        mkdir($this->source . '/wp-includes', 0700); mkdir($this->source . '/wp-content', 0700);
        foreach (['wp-load.php', 'wp-settings.php', 'wp-includes/version.php', 'wp-config.php'] as $file) { file_put_contents($this->source . '/' . $file, '<?php /* original secret marker */'); }
        file_put_contents($this->source . '/wp-content/db.php', '<?php /* old database configuration */');
        $this->database = $this->root . '/original.sqlite';
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $this->database . ';dbname=sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE wp_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT \'yes\', PRIMARY KEY(option_id), UNIQUE KEY option_name(option_name))');
        $db->exec('CREATE TABLE wp_posts (ID bigint unsigned NOT NULL AUTO_INCREMENT, post_content longtext NOT NULL, PRIMARY KEY(ID))');
        $db->exec('CREATE TABLE wp_users (ID bigint unsigned NOT NULL AUTO_INCREMENT, user_login varchar(60) NOT NULL, user_pass varchar(255) NOT NULL, user_nicename varchar(50) NOT NULL, user_email varchar(100) NOT NULL, user_url varchar(100) NOT NULL, user_registered datetime NOT NULL, user_activation_key varchar(255) NOT NULL, user_status int NOT NULL, display_name varchar(250) NOT NULL, PRIMARY KEY(ID))');
        $db->exec('CREATE TABLE wp_usermeta (umeta_id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, meta_key varchar(255), meta_value longtext, PRIMARY KEY(umeta_id))');

        foreach (['home' => 'https://source.invalid', 'siteurl' => 'https://source.invalid', 'nested' => serialize(['url' => 'https://source.invalid/a', 'nested' => ['https://source.invalid' => '日本語']]), 'active_plugins' => serialize(['example/example.php'])] as $key => $value) { $db->exec('INSERT INTO wp_options(option_name,option_value) VALUES (' . $db->quote($key) . ',' . $db->quote($value) . ')'); }
        $db->exec('INSERT INTO wp_posts(post_content) VALUES (' . $db->quote('<img src="https://source.invalid/a.jpg"> {"url":"https:\\/\\/source.invalid/a"}') . ')');
        $statement = null; $db = null; chmod($this->database, 0600);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) { proc_terminate($this->server, 15); proc_close($this->server); $this->server = null; }
        Fixture::cleanup();
    }

    public function testItBuildsAnIndependentLocalCopyAndPreservesSerializedValuesAndActivePlugins(): void
    {
        $before = hash_file('sha256', $this->database);
        $hash = '$P$B' . str_repeat('a', 30);
        $result = $this->prepare(['login' => 'local-admin', 'passwordHash' => $hash]);
        self::assertSame('http://127.0.0.1:18889', $result['url']); self::assertTrue($result['administratorCreated']); self::assertTrue($result['osNetworkIsolationRequired']);
        self::assertSame($before, hash_file('sha256', $this->database));
        self::assertNotSame(lstat($this->database)['ino'], lstat($result['databaseFile'])['ino']);
        self::assertSame(0600, fileperms($result['databaseFile']) & 0777);
        self::assertStringNotContainsString('original secret marker', file_get_contents($result['siteRoot'] . '/wp-config.php'));
        self::assertStringContainsString("define('DB_ENGINE', 'sqlite')", file_get_contents($result['siteRoot'] . '/wp-config.php'));
        self::assertStringContainsString("define('DISABLE_WP_CRON', true)", file_get_contents($result['siteRoot'] . '/wp-config.php'));
        self::assertStringContainsString('pre_wp_mail', file_get_contents($result['siteRoot'] . '/wp-content/mu-plugins/000-wrb-local-isolation.php'));
        self::assertStringNotContainsString('old database configuration', file_get_contents($result['siteRoot'] . '/wp-content/db.php'));
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $result['databaseFile'] . ';dbname=sqlite');
        $options = $db->query('SELECT option_name,option_value FROM wp_options')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame('http://127.0.0.1:18889', $options['home']); self::assertSame($options['home'], $options['siteurl']);
        self::assertSame(['url' => 'http://127.0.0.1:18889/a', 'nested' => ['http://127.0.0.1:18889' => '日本語']], unserialize($options['nested'], ['allowed_classes' => false]));
        self::assertSame(['example/example.php'], unserialize($options['active_plugins']));
        $post = $db->query('SELECT post_content FROM wp_posts')->fetchColumn();
        self::assertStringContainsString('http://127.0.0.1:18889/a.jpg', $post); self::assertStringNotContainsString('source.invalid', $post);
        self::assertSame($hash, $db->query('SELECT user_pass FROM wp_users')->fetchColumn());
        self::assertSame(['administrator' => true], unserialize($db->query("SELECT meta_value FROM wp_usermeta WHERE meta_key='wp_capabilities'")->fetchColumn()));
        $db = null;
    }

    /** @dataProvider previewVariants */
    public function testMigrationDatabaseKeepsPermanentUrlsAndExcludesPreviewAdmin(?string $target, bool $createAdmin): void
    {
        $source = 'https://source.invalid';
        $login = $createAdmin ? ['login' => 'preview-only', 'passwordHash' => '$P$B' . str_repeat('.', 30)] : [];
        $migration = $this->root . '/migration.sqlite';
        (new SqliteSiteSetup($this->implementation))->prepare($this->source, $this->root . '/running', $this->database,
            $this->root . '/running.sqlite', $source, 'http://127.0.0.1:18889', 'wp_', $login, [], $target ?? $source, $migration);
        foreach ([$this->database => [$source, 0], $migration => [$target ?? $source, 0], $this->root . '/running.sqlite' => [$target ?? $source, (int) $createAdmin]] as $path => [$url, $users]) {
            $db = new \SQLite3($path, SQLITE3_OPEN_READONLY);
            try {
                self::assertSame($url, $db->querySingle("SELECT option_value FROM wp_options WHERE option_name='home'"));
                self::assertSame($url, $db->querySingle("SELECT option_value FROM wp_options WHERE option_name='siteurl'"));
                self::assertSame($users, $db->querySingle("SELECT COUNT(*) FROM wp_users WHERE user_login='preview-only'"));
            } finally { $db->close(); }
        }
    }

    public function previewVariants(): array
    {
        return [[null, false], [null, true], ['https://target.invalid', false], ['https://target.invalid', true]];
    }

    public function testAnExistingSiteIsRejectedBeforeAnyOverwrite(): void
    {
        mkdir($this->root . '/running', 0700); file_put_contents($this->root . '/running/keep', 'keep');
        $rejected = false;
        $rejected = false;
        $rejected = false;
            try { $this->prepare(); self::fail('An existing site must be refused.'); } catch (RuntimeException $error) { self::assertSame('keep', file_get_contents($this->root . '/running/keep')); }
        self::assertFileDoesNotExist($this->root . '/running.sqlite');
    }

    public function testAnExistingDatabaseIsRejectedBeforeTheSiteIsCreated(): void
    {
        file_put_contents($this->root . '/running.sqlite', 'keep');
        try { $this->prepare(); self::fail('An existing database must be refused.'); } catch (RuntimeException $error) { self::assertSame('keep', file_get_contents($this->root . '/running.sqlite')); }
        self::assertDirectoryDoesNotExist($this->root . '/running');
    }

    public function testPublicUrlsAndSourceOverlapAreRejectedWithoutCreatingDestinations(): void
    {
        $setup = new SqliteSiteSetup($this->implementation);
        try { $setup->prepare($this->source, $this->root . '/running', $this->database, $this->root . '/running.sqlite', 'https://source.invalid', 'http://public.invalid', 'wp_'); self::fail('A public address must be refused.'); }
        catch (RuntimeException $error) { self::assertDirectoryDoesNotExist($this->root . '/running'); }
        try { $setup->prepare($this->source, $this->source . '/inside', $this->database, $this->root . '/running.sqlite', 'https://source.invalid', 'http://localhost', 'wp_'); self::fail('An overlapping site must be refused.'); }
        catch (RuntimeException $error) { self::assertDirectoryDoesNotExist($this->source . '/inside'); }
    }

    public function testDatabaseInsideTheNewWebRootIsRejected(): void
    {
        $setup = new SqliteSiteSetup($this->implementation);
        $this->expectException(RuntimeException::class);
        $setup->prepare($this->source, $this->root . '/running', $this->database, $this->root . '/running/db.sqlite', 'https://source.invalid', 'http://localhost', 'wp_');
    }

    public function testSerializedObjectsContainingTheSourceUrlFailRatherThanLoseInformation(): void
    {
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $this->database . ';dbname=sqlite');
        $db->exec('UPDATE wp_options SET option_value=' . $db->quote(serialize((object) ['url' => 'https://source.invalid'])) . " WHERE option_name='nested'"); $db = null;
        $before = hash_file('sha256', $this->database);
        try { $this->prepare(); self::fail('Serialized objects must be refused.'); } catch (RuntimeException $error) { self::assertStringContainsString('rewrite', $error->getMessage()); }
        self::assertSame($before, hash_file('sha256', $this->database)); self::assertFileDoesNotExist($this->root . '/running/wp-config.php');
    }

    public function testInvalidAdministratorHashesAreRejectedBeforeCopying(): void
    {
        $this->expectException(RuntimeException::class);
        try { $this->prepare(['login' => 'local-admin', 'passwordHash' => 'plaintext']); }
        finally { self::assertDirectoryDoesNotExist($this->root . '/running'); }
    }

    public function testQuotedSqlLookingContentRemainsDataDuringUrlReplacement(): void
    {
        $value = "https://source.invalid/a'); DROP TABLE wp_users; -- 日本語";
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $this->database . ';dbname=sqlite');
        $db->exec('UPDATE wp_posts SET post_content=' . $db->quote($value)); $db = null;
        $result = $this->prepare();
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $result['databaseFile'] . ';dbname=sqlite');
        self::assertSame(str_replace('https://source.invalid', 'http://127.0.0.1:18889', $value), $db->query('SELECT post_content FROM wp_posts')->fetchColumn());
        self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM wp_users')->fetchColumn());
        $db = null;
    }

    public function testAReadOnlySourceLinkIsNeverFollowedIntoTheRunningCopy(): void
    {
        file_put_contents($this->root . '/outside', 'private marker');
        symlink($this->root . '/outside', $this->source . '/linked');
        try { $this->prepare(); self::fail('A source link must be refused.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('links', $error->getMessage()); }
        self::assertSame('private marker', file_get_contents($this->root . '/outside'));
        self::assertFileDoesNotExist($this->root . '/running/wp-config.php');
    }

    public function testStreamingRewriteKeepsEveryRowWhenUpdatesUseTheSameConnection(): void
    {
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $this->database . ';dbname=sqlite');
        $db->beginTransaction();
        for ($number = 2; $number <= 1001; ++$number) {
            $db->exec('INSERT INTO wp_posts(ID,post_content) VALUES (' . $number . ',' . $db->quote('https://source.invalid/item/' . $number) . ')');
        }
        $db->commit(); $db = null;
        $result = $this->prepare();
        self::assertSame(1004, $result['urlFieldsUpdated']);
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $result['databaseFile'] . ';dbname=sqlite');
        $cursor = $db->query('SELECT ID,post_content FROM wp_posts ORDER BY ID'); $count = 0;
        while (($row = $cursor->fetch(PDO::FETCH_ASSOC)) !== false) {
            ++$count;
            if ((int) $row['ID'] === 1) { self::assertStringNotContainsString('source.invalid', $row['post_content']); }
            else { self::assertSame('http://127.0.0.1:18889/item/' . $row['ID'], $row['post_content']); }
        }
        $cursor->closeCursor(); self::assertSame(1001, $count); $cursor = null; $db = null;
        $original = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $this->database . ';dbname=sqlite');
        self::assertSame('https://source.invalid/item/1001', $original->query('SELECT post_content FROM wp_posts WHERE ID=1001')->fetchColumn());
        $original = null;
    }

    public function testSourceAliasesReplaceBothOriginsInHtmlAndSerializedValuesInTheSameCopy(): void
    {
        $html = '<img src="https://source.invalid/dev.jpg"><img src="https://public.invalid/prod.jpg">';
        $serialized = serialize(['dev' => 'https://source.invalid/dev.jpg', 'prod' => 'https://public.invalid/prod.jpg', 'escaped' => 'https:\\/\\/public.invalid/a']);
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $this->database . ';dbname=sqlite');
        $db->exec('UPDATE wp_posts SET post_content=' . $db->quote($html));
        $db->exec('UPDATE wp_options SET option_value=' . $db->quote($serialized) . " WHERE option_name='nested'"); $db = null;
        $before = hash_file('sha256', $this->database);
        $result = $this->prepare([], ['https://public.invalid']);
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $result['databaseFile'] . ';dbname=sqlite');
        self::assertSame('<img src="http://127.0.0.1:18889/dev.jpg"><img src="http://127.0.0.1:18889/prod.jpg">', $db->query('SELECT post_content FROM wp_posts')->fetchColumn());
        self::assertSame(['dev' => 'http://127.0.0.1:18889/dev.jpg', 'prod' => 'http://127.0.0.1:18889/prod.jpg', 'escaped' => 'http:\\/\\/127.0.0.1:18889/a'], unserialize($db->query("SELECT option_value FROM wp_options WHERE option_name='nested'")->fetchColumn(), ['allowed_classes' => false]));
        self::assertSame($before, hash_file('sha256', $this->database)); $db = null;
    }

    public function testSourceUrlReplacementStopsAtOriginAndPathBoundaries(): void
    {
        $local = 'http://127.0.0.1:18889';
        $unchanged = [
            'host extension' => 'a https://source.invalid.example/x b', 'host suffix' => 'https://source.invalidx/', 'port' => 'https://source.invalid:8443/x',
            'user information' => 'https://source.invalid@other.example/', 'hyphenated host' => 'https://source.invalid-evil.example', 'encoded host' => 'https://source.invalid%2Eexample/',
        ];
        $changed = [
            'delimiters' => ['(https://source.invalid) "https://source.invalid/a" https://source.invalid. https://source.invalid, https://source.invalid?x=1 https://source.invalid#h https://source.invalid',
                "($local) \"$local/a\" $local. $local, $local?x=1 $local#h $local"],
            'json escaped' => ['{"a":"https:\\/\\/source.invalid\\/p","b":"https:\\/\\/source.invalid.example\\/p"}', '{"a":"http:\\/\\/127.0.0.1:18889\\/p","b":"https:\\/\\/source.invalid.example\\/p"}'],
            'longest alias' => ['https://source.invalid/blog/x https://source.invalid/blogger', "$local/x $local/blogger"],
        ];
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $this->database . ';dbname=sqlite');
        $db->exec('DELETE FROM wp_posts');
        $ids = [];
        foreach ($unchanged as $name => $text) { $db->exec('INSERT INTO wp_posts(post_content) VALUES (' . $db->quote($text) . ')'); $ids[$name] = (int) $db->lastInsertId(); }
        foreach ($changed as $name => [$text]) { $db->exec('INSERT INTO wp_posts(post_content) VALUES (' . $db->quote($text) . ')'); $ids[$name] = (int) $db->lastInsertId(); }
        $binary = "\xff\xfe https://source.invalid/a \x80\xc3";
        $db->exec('INSERT INTO wp_posts(post_content) VALUES (X\'' . bin2hex($binary) . '\')'); $ids['binary'] = (int) $db->lastInsertId();
        $serialized = serialize(['https://source.invalid:8443' => 'https://source.invalid/b', 'k' => 'https://source.invalid.example/a', 'https://source.invalid/blog' => ['https://source.invalid']]);
        $db->exec("INSERT INTO wp_options(option_name, option_value) VALUES ('boundary', " . $db->quote($serialized) . ')'); $db = null;
        $result = $this->prepare([], ['https://source.invalid/blog']);
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . $result['databaseFile'] . ';dbname=sqlite');
        $content = static function (int $id) use ($db) { return $db->query('SELECT HEX(post_content) FROM wp_posts WHERE ID=' . $id)->fetchColumn(); };
        foreach ($unchanged as $name => $text) { self::assertSame(strtoupper(bin2hex($text)), $content($ids[$name]), $name); }
        foreach ($changed as $name => [$text, $expected]) { self::assertSame(strtoupper(bin2hex($expected)), $content($ids[$name]), $name); }
        self::assertSame(strtoupper(bin2hex("\xff\xfe $local/a \x80\xc3")), $content($ids['binary']), 'binary');
        $value = unserialize($db->query("SELECT option_value FROM wp_options WHERE option_name='boundary'")->fetchColumn(), ['allowed_classes' => false]);
        self::assertSame(['https://source.invalid:8443' => "$local/b", 'k' => 'https://source.invalid.example/a', $local => [$local]], $value);
    }

    /** @dataProvider invalidSourceAliases */
    public function testInvalidAndCircularSourceAliasesAreRejectedBeforeCreatingTheSite(array $aliases): void
    {
        $this->expectException(RuntimeException::class);
        try { $this->prepare([], $aliases); }
        finally { self::assertDirectoryDoesNotExist($this->root . '/running'); self::assertFileDoesNotExist($this->root . '/running.sqlite'); }
    }

    public function invalidSourceAliases(): array
    {
        return [
            [['https://public.invalid', 'https://public.invalid/']],
            [['https://source.invalid/']],
            [['https://PUBLIC.invalid', 'https://public.invalid']],
            [['http://127.0.0.1:18889']],
            [['http://127.0.0.1:18889/site']],
            [['http://127.0.0.1']],
            [['not-a-url']],
            [['https://public.invalid/?secret=value']],
            [[7]],
            [['named' => 'https://public.invalid']],
            [array_fill(0, 9, 'https://public.invalid')],
        ];
    }

    public function testPreparedStorageInitializesInsideTheUnchangedServerSandbox(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin' || !is_executable('/usr/bin/sandbox-exec')) { self::markTestSkipped('macOS sandbox-exec is required.'); }
        file_put_contents($this->source . '/index.php', <<<'PROBE'
<?php
require __DIR__ . '/wp-config.php';
require __DIR__ . '/wp-content/plugins/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-storage.php';
try {
    $path = WP_SQLite_Storage::with_explicit_path(DB_PATH)->initialize();
    echo json_encode(['storageInitialized' => $path === DB_PATH]);
} catch (Throwable $error) { echo json_encode(['storageInitialized' => false]); }
PROBE
        );
        $result = $this->prepare();
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($listener); $address = stream_socket_get_name($listener, false); fclose($listener);
        $port = (int) substr(strrchr($address, ':'), 1); $url = 'http://127.0.0.1:' . $port;
        $router = dirname(__DIR__) . '/local-router.php';
        $script = '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true)
            . '; exit(WordPressR2Backup\\LocalSiteServer::run(' . var_export($result['siteRoot'], true) . ',' . var_export($result['databaseFile'], true) . ',' . var_export($url, true) . ',' . var_export($router, true) . '));';
        file_put_contents($this->root . '/serve.php', $script);
        $this->server = proc_open([PHP_BINARY, $this->root . '/serve.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server-errors.log', 'a']], $pipes);
        self::assertIsResource($this->server); $deadline = microtime(true) + 10; $stream = false;
        do {
            $status = proc_get_status($this->server); if (!$status['running']) { self::fail('The synthetic sandbox server must remain running.'); }
            $stream = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2);
            if (is_resource($stream)) { break; } usleep(100000);
        } while (microtime(true) < $deadline);
        self::assertIsResource($stream);
        try {
            stream_set_timeout($stream, 5); fwrite($stream, "GET /index.php HTTP/1.0\r\nHost: 127.0.0.1:$port\r\nConnection: close\r\n\r\n");
            $response = stream_get_contents($stream, 1048576);
        } finally { fclose($stream); }
        $parts = explode("\r\n\r\n", $response, 2); self::assertCount(2, $parts);
        self::assertSame(['storageInitialized' => true], json_decode($parts[1], true));
    }

    public function testMatchingStorageProtectionFilesArePreservedWithoutReplacement(): void
    {
        $expected = ['.htaccess' => 'DENY FROM ALL', 'index.php' => '<?php // Silence is golden.']; $identities = [];
        foreach ($expected as $name => $contents) { file_put_contents($this->root . '/' . $name, $contents); chmod($this->root . '/' . $name, 0600); $identities[$name] = lstat($this->root . '/' . $name)['ino']; }
        $this->prepare();
        foreach ($expected as $name => $contents) { self::assertSame($contents, file_get_contents($this->root . '/' . $name)); self::assertSame($identities[$name], lstat($this->root . '/' . $name)['ino']); }
    }

    /** @dataProvider unsafeStorageProtection */
    public function testUnsafeStorageProtectionIsRejectedBeforeCopying(string $name, string $kind): void
    {
        $path = $this->root . '/' . $name; file_put_contents($this->root . '/outside-protection', 'synthetic private marker');
        if ($kind === 'link') { symlink($this->root . '/outside-protection', $path); }
        elseif ($kind === 'permission') { file_put_contents($path, 'DENY FROM ALL'); chmod($path, 0644); }
        else { file_put_contents($path, 'unknown existing protection'); chmod($path, 0600); }
        $this->expectException(RuntimeException::class);
        try { $this->prepare(); }
        finally {
            self::assertDirectoryDoesNotExist($this->root . '/running'); self::assertFileDoesNotExist($this->root . '/running.sqlite');
            self::assertSame('synthetic private marker', file_get_contents($this->root . '/outside-protection'));
            if ($kind !== 'link') { self::assertSame($kind === 'permission' ? 'DENY FROM ALL' : 'unknown existing protection', file_get_contents($path)); }
        }
    }

    public function unsafeStorageProtection(): array
    {
        return [['.htaccess', 'link'], ['index.php', 'link'], ['.htaccess', 'contents'], ['index.php', 'contents'], ['.htaccess', 'permission'], ['.ht.sqlite.maintenance', 'contents']];
    }

    public function testPreflightAcceptsTheRequestPrepareAcceptsAndCreatesNothing(): void
    {
        $setup = new SqliteSiteSetup($this->implementation); $before = scandir($this->root);
        $setup->preflight($this->root . '/running', $this->root . '/running.sqlite', 'https://source.invalid', 'http://127.0.0.1:18889', 'wp_', [], ['https://alias.invalid'], $this->root . '/restored', $this->database);
        self::assertSame($before, scandir($this->root));
        $result = $this->prepare([], ['https://alias.invalid']);
        self::assertSame($this->root . '/running', $result['siteRoot']);
    }

    /** @dataProvider unacceptablePreflightRequests */
    public function testPreflightRejectsWhatPrepareWouldRejectAfterTheRestore(string $site, string $database, string $prefix, string $source, string $destination): void
    {
        $setup = new SqliteSiteSetup($this->implementation); $root = $this->root;
        file_put_contents($root . '/taken.sqlite', 'taken'); chmod($root . '/taken.sqlite', 0600); $before = scandir($root);
        $map = static function (string $path) use ($root): string { return str_replace('{root}', $root, $path); };
        try { $setup->preflight($map($site), $map($database), $source, 'http://127.0.0.1:18889', $prefix, [], [], $map($destination), $this->database); }
        catch (\RuntimeException $expected) { $rejected = true; }
        self::assertTrue($rejected, 'The request must be rejected before recovery.');
        self::assertSame($before, scandir($this->root));
    }

    /** @dataProvider unsafeDatabaseDirectoryContents */
    public function testPreflightRejectsAnUnsafeExistingDatabaseDirectoryBeforeRecovery(string $name, string $contents): void
    {
        $directory = $this->root . '/database-parent'; mkdir($directory, 0700);
        file_put_contents($directory . '/' . $name, $contents); chmod($directory . '/' . $name, 0600); $before = scandir($directory);
        try { (new SqliteSiteSetup($this->implementation))->preflight($this->root . '/running', $directory . '/running.sqlite', 'https://source.invalid', 'http://127.0.0.1:18889', 'wp_', [], [], $this->root . '/restored', $this->database); }
        catch (\RuntimeException $expected) { $rejected = true; }
        self::assertTrue($rejected, 'An unsafe database directory must be rejected before recovery.');
        self::assertSame($before, scandir($directory)); self::assertFileDoesNotExist($this->root . '/running');
    }

    public function unsafeDatabaseDirectoryContents(): array
    {
        return [['.ht.sqlite.maintenance', ''], ['.htaccess', 'unknown existing protection'], ['index.php', 'unknown existing protection']];
    }

    public function unacceptablePreflightRequests(): array
    {
        $ok = ['{root}/running', '{root}/running.sqlite', 'wp_', 'https://source.invalid', '{root}/restored'];
        $with = static function (int $index, string $value) use ($ok) { $copy = $ok; $copy[$index] = $value; return $copy; };
        return [
            'prefix' => $with(2, 'wp-'), 'source' => $with(3, 'ftp://source.invalid'), 'site exists' => $with(0, '{root}/original'), 'database exists' => $with(1, '{root}/taken.sqlite'),
            'missing parent' => $with(0, '{root}/missing/site'), 'site inside destination' => $with(0, '{root}/restored/site'), 'database is source' => $with(1, '{root}/original.sqlite'),
        ];
    }

    public function testLocalServerPreflightChecksTheRequestWithoutNeedingTheSiteToExist(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin' || !is_executable('/usr/bin/sandbox-exec')) { self::markTestSkipped('macOS sandbox-exec is required.'); }
        $router = dirname(__DIR__) . '/local-router.php';
        \WordPressR2Backup\LocalSiteServer::preflight($this->root . '/running', $this->root . '/running.sqlite', 'http://127.0.0.1:18889', $router);
        foreach ([['https://127.0.0.1:18889', $this->root . '/running.sqlite'], ['http://example.invalid:18889', $this->root . '/running.sqlite'], ['http://127.0.0.1:18889', $this->root . '/running/inside.sqlite']] as [$url, $database]) {
            try { \WordPressR2Backup\LocalSiteServer::preflight($this->root . '/running', $database, $url, $router); }
            catch (\RuntimeException $expected) { $rejected = true; }
            self::assertTrue($rejected, 'The local server request must be rejected before recovery.');
        }
        self::assertDirectoryDoesNotExist($this->root . '/running');
    }

    private function prepare(array $administrator = [], array $sourceAliases = []): array
    {
        return (new SqliteSiteSetup($this->implementation))->prepare($this->source, $this->root . '/running', $this->database, $this->root . '/running.sqlite', 'https://source.invalid', 'http://127.0.0.1:18889', 'wp_', $administrator, $sourceAliases);
    }
}
