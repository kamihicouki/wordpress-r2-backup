<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{FileSafety, RestoreError, RestorePlan, RestoreService};

/** @group integration */
final class RestoreServiceTest extends TestCase
{
    private static ?RestoreTestBed $bed = null;
    private array $services = [];

    public static function setUpBeforeClass(): void
    {
        if (RestoreTestBed::available()) { self::$bed = RestoreTestBed::build(); }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$bed !== null) { self::$bed->server->stop(); self::$bed = null; }
        Fixture::cleanup();
    }

    protected function setUp(): void
    {
        if (self::$bed === null) { self::markTestSkipped('Explicit isolated MySQL integration authorization is required.'); }
    }

    protected function tearDown(): void
    {
        foreach ($this->services as [$service, $name]) { try { $service->stopSite($name); $service->stop($name); } catch (\Throwable $ignored) { } }
    }

    private function service(array $connections = [], array $extra = []): RestoreService
    {
        return new RestoreService(self::$bed->environment(Fixture::temp(), $connections, $extra));
    }

    private function run_(RestoreService $service, array $input, array $secrets, string $actor = 'actor-a'): array
    {
        $review = $service->preflight($input, $secrets, $actor);
        self::assertTrue($review['ok'], json_encode($review['checks'], JSON_UNESCAPED_UNICODE));
        $status = $service->start($input, $secrets, $review['planHash'], $input['name'], $actor);
        $this->services[] = [$service, $input['name']];
        return $this->finish($service, $input['name'], $status);
    }

    private function finish(RestoreService $service, string $name, array $status = []): array
    {
        $deadline = microtime(true) + 240;
        while (in_array($service->status($name)['state'], ['queued', 'running'], true)) {
            if (microtime(true) > $deadline) { self::fail('The restore did not finish.'); }
            usleep(100000);
        }
        return $service->status($name);
    }

    private function untilSite(RestoreService $service, string $name, string $response = 'responding'): array
    {
        $deadline = microtime(true) + 15;
        do { $status = $service->status($name); if (($status['site']['response'] ?? '') === $response) { return $status; } usleep(100000); } while (microtime(true) < $deadline);
        self::fail('The running copy did not respond: ' . json_encode($status['site'], JSON_UNESCAPED_UNICODE));
    }

    private function assertPreviewClean(int $port): void
    {
        [$code, $headers, $body] = RestoreTestBed::http($port, 'GET', '/');
        self::assertSame(200, $code, $body);
        self::assertStringContainsString("default-src 'self'", $headers['content-security-policy']);
        self::assertStringContainsString("connect-src 'self'", $headers['content-security-policy']);
        self::assertDoesNotMatchRegularExpression('~(?:https?:)?(?://|\\\\/\\\\/|%2F%2F)new\.example\.com(?!munity)~i', $body, 'No spelling of the target stays a link');
        self::assertStringContainsString('http://127.0.0.1:' . $port . '/post', $body);
        self::assertStringContainsString('http:\/\/127.0.0.1:' . $port . '\/api', $body, 'script');
        self::assertStringContainsString('http%3A%2F%2F127.0.0.1%3A' . $port . '%2Fa', $body, 'encoded link');
        self::assertStringContainsString('https://new.example.community/keep', $body, 'a different host stays');
        self::assertStringContainsString('<span>http://127.0.0.1:' . $port . '</span>', $body, 'WP_HOME follows the preview');
        [, , $rest] = RestoreTestBed::http($port, 'GET', '/wp-json/wp/v2/posts');
        self::assertStringNotContainsString('new.example.com', $rest); self::assertStringContainsString('127.0.0.1', $rest);
        [, , $admin] = RestoreTestBed::http($port, 'GET', '/wp-admin/');
        self::assertDoesNotMatchRegularExpression('~//new\.example\.com(?!munity)~', $admin); self::assertStringContainsString('<title>admin</title>', $admin);
        [$redirect, $redirectHeaders] = RestoreTestBed::http($port, 'GET', '/redirect');
        self::assertSame(302, $redirect); self::assertSame('http://127.0.0.1:' . $port . '/elsewhere', $redirectHeaders['location']);
    }

    public function testSqliteFromFormToIsolatedPreviewKeepsTheOriginalAndSeparatesTheTwoUrls(): void
    {
        $service = $this->service(); $port = RestoreTestBed::freePort();
        $input = self::$bed->input('sqlite-flow', $port);
        $status = $this->run_($service, $input, self::$bed->secrets());
        self::assertSame('complete', $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE));
        foreach (['archive', 'database', 'files', 'media', 'startup'] as $result) { self::assertSame('verified', $status['results'][$result]['state'], $result); }
        self::assertSame(1, $status['results']['media']['files']); self::assertSame(0, $status['results']['media']['placed']);
        self::assertSame(RestoreTestBed::TARGET_URL, $status['startup']['storedUrl']);
        self::assertSame('http://127.0.0.1:' . $port, $status['startup']['previewUrl']);
        self::assertSame('not-started', $status['site']['state'], 'Nothing starts by itself');
        $job = $this->base($service) . '/jobs/sqlite-flow';
        self::assertDirectoryDoesNotExist($job . '/restore/roots/r0001/wp-content/uploads');
        self::assertSame('0', RestoreTestBed::sqliteValue($status['startup']['migrationDatabase'], "SELECT COUNT(*) FROM wp_users WHERE user_login='restore-admin'"));
        $running = $job . '/running-db/site.sqlite'; $original = $job . '/original-db/site.sqlite';
        self::assertSame(RestoreTestBed::TARGET_URL, RestoreTestBed::sqliteValue($running, "SELECT option_value FROM wp_options WHERE option_name='home'"));
        self::assertSame(RestoreTestBed::SOURCE_URL, RestoreTestBed::sqliteValue($original, "SELECT option_value FROM wp_options WHERE option_name='home'"), 'The original stays unchanged');
        $serialized = unserialize(RestoreTestBed::sqliteValue($running, "SELECT option_value FROM wp_options WHERE option_name='wrb_serialized'"));
        self::assertSame(['url' => RestoreTestBed::TARGET_URL . '/page', 'keep' => 'https://source.invalid.example/x'], $serialized);
        self::assertStringContainsString('https:\\/\\/new.example.com\\/j', RestoreTestBed::sqliteValue($running, "SELECT option_value FROM wp_options WHERE option_name='wrb_json'"));
        self::assertStringNotContainsString('source-site', (string) file_get_contents($job . '/site/wp-config.php'));
        self::assertSame('1', RestoreTestBed::sqliteValue($running, "SELECT COUNT(*) FROM wp_users WHERE user_login='restore-admin'"));

        $started = $service->startSite('sqlite-flow');
        self::assertContains($started['site']['state'], ['starting', 'running']);
        $ready = $this->untilSite($service, 'sqlite-flow');
        self::assertTrue($ready['site']['openable']);
        self::assertTrue($ready['site']['adminOpenable']);
        self::assertStringContainsString('"database":"sqlite"', RestoreTestBed::http($port, 'GET', '/__wrb_isolation_check')[2]);
        $this->assertPreviewClean($port);
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $listenerPort = (int) substr(strrchr((string) stream_socket_get_name($listener, false), ':'), 1);
        [, , $probe] = RestoreTestBed::http($port, 'GET', '/probe?port=' . $listenerPort . '&socket=' . rawurlencode(self::$bed->server->workspace() . '/mysql.sock'));
        fclose($listener);
        $results = json_decode($probe, true);
        self::assertFalse($results['tcp'], 'Outbound TCP stays denied'); self::assertFalse($results['otherSocket'], 'A SQLite copy reaches no socket at all'); self::assertFalse($results['neighbor'], 'The job directory stays unreadable');
        $stopped = $service->stopSite('sqlite-flow');
        self::assertSame('stopped', $stopped['site']['state']);
        $this->assertNoSecret($job, [self::$bed->identity, RestoreTestBed::ADMIN_PASSWORD]);
    }

    public function testBlankUrlAndAdministratorOptOutKeepTheBackedUpUserAndOriginalAddress(): void
    {
        $service = $this->service(); $port = RestoreTestBed::freePort();
        $input = self::$bed->input('keep-source', $port, ['targetUrl' => '', 'createPreviewAdmin' => '0']);
        $status = $this->run_($service, $input, self::$bed->secrets(['adminPassword' => '']));
        self::assertSame('complete', $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE));
        self::assertTrue($status['startup']['hasBackedUpUsers']);
        $job = $this->base($service) . '/jobs/keep-source';
        $sqliteTemp = $job . '/sqlite-tmp';
        self::assertDirectoryExists($sqliteTemp);
        self::assertSame(0700, fileperms($sqliteTemp) & 0777);
        self::assertSame(['.', '..'], scandir($sqliteTemp));
        $db = $job . '/running-db/site.sqlite';
        self::assertSame(RestoreTestBed::SOURCE_URL, RestoreTestBed::sqliteValue($db, "SELECT option_value FROM wp_options WHERE option_name='home'"));
        self::assertSame('1', RestoreTestBed::sqliteValue($db, "SELECT COUNT(*) FROM wp_users WHERE user_login='synthetic'"));
        self::assertSame('0', RestoreTestBed::sqliteValue($db, "SELECT COUNT(*) FROM wp_users WHERE user_login='restore-admin'"));
        $service->startSite('keep-source'); $this->untilSite($service, 'keep-source');
        [$code, , $body] = RestoreTestBed::http($port, 'GET', '/wp-admin/');
        self::assertSame(200, $code);
        self::assertStringNotContainsString(RestoreTestBed::SOURCE_URL, $body);
    }

    /** @dataProvider guidanceDatabases */
    public function testPublicationConfigGuidanceKeepsOriginalOptionsAndContent(string $database): void
    {
        if ($database === 'mysql' && !IsolatedMysql::available('mariadb')) { self::markTestSkipped('MariaDB is not configured.'); }
        $bed = $database === 'mysql' ? RestoreTestBed::build('mariadb') : self::$bed;
        try {
            $set = $database === 'mysql' ? RestoreTestBed::connectionSet($bed->server, 'target') : null;
            $service = new RestoreService($bed->environment(Fixture::temp(), $set === null ? [] : [$set]));
            $input = $bed->input('config-guidance', RestoreTestBed::freePort(), ['database' => $database, 'connection' => 'target',
                'urlMode' => 'config', 'targetSiteUrl' => RestoreTestBed::TARGET_URL . '/wordpress', 'createPreviewAdmin' => '0']);
            $secrets = $bed->secrets(['adminPassword' => '']);
            $review = $service->preflight($input, $secrets, 'actor-a');
            self::assertTrue($review['ok']);
            self::assertSame('config', $review['summary']['urlMode']);
            self::assertArrayNotHasKey('restoredUrls', $review['summary']);
            $status = $this->run_($service, $input, $secrets);
            self::assertSame('complete', $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE));
            self::assertSame($review['summary']['publicationUrls'], $status['startup']['publicationUrls']);
            self::assertSame(['home' => RestoreTestBed::SOURCE_URL, 'siteurl' => RestoreTestBed::SOURCE_URL], $status['restoredUrls']['database']);
            self::assertSame(0, $status['startup']['urlFieldsUpdated']);
            $job = $this->base($service) . '/jobs/config-guidance';
            self::assertFileDoesNotExist($job . '/migration-db');
            self::assertStringNotContainsString(RestoreTestBed::TARGET_URL, (string) file_get_contents($job . '/site/wp-config.php'));
            if ($set !== null) {
                self::assertSame($bed->server->client()->tableChecksums($set['_schema']), $bed->server->client()->tableChecksums($set['_schema'] . '_run'));
            } else {
                $contents = static function (string $file): array {
                    $db = new \SQLite3($file, SQLITE3_OPEN_READONLY);
                    $db->enableExceptions(true);
                    try {
                        $rows = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name GLOB 'wp_*' ORDER BY name");
                        $tables = [];
                        while (($table = $rows->fetchArray(SQLITE3_NUM)) !== false) {
                            $tables[$table[0]] = [];
                            $data = $db->query('SELECT * FROM "' . str_replace('"', '""', $table[0]) . '" ORDER BY rowid');
                            while (($row = $data->fetchArray(SQLITE3_NUM)) !== false) { $tables[$table[0]][] = $row; }
                            $data->finalize();
                        }
                        return $tables;
                    } finally { $db->close(); }
                };
                self::assertSame($contents($job . '/original-db/site.sqlite'), $contents($job . '/running-db/site.sqlite'));
            }
        } finally { if ($database === 'mysql') { $bed->server->stop(); } }
    }

    public function guidanceDatabases(): array { return [['sqlite'], ['mysql']]; }

    public function testDatabaseMigrationStopsBeforeCopyingWhenTheRecordedUrlDisagreesWithTheDatabase(): void
    {
        $bed = RestoreTestBed::build('mysql', null, 'https://recorded.example.net');
        try {
            $service = new RestoreService($bed->environment(Fixture::temp()));
            $status = $this->run_($service, $bed->input('mismatch', RestoreTestBed::freePort(), ['urlMode' => 'database']), $bed->secrets());
            self::assertSame('startup-failed', $status['state']);
            self::assertSame('verified', $status['results']['database']['state']);
            self::assertSame(RestoreTestBed::SOURCE_URL, $status['restoredUrls']['database']['home']);
            self::assertFalse($status['restoredUrls']['configuration']['home']);
            $job = $this->base($service) . '/jobs/mismatch';
            self::assertDirectoryDoesNotExist($job . '/migration-db');
            self::assertDirectoryDoesNotExist($job . '/site');
        } finally { $bed->server->stop(); }
    }

    /** @dataProvider engines */
    public function testMysqlFromFormToIsolatedPreviewProtectsTheOriginalFromTheRunningAccount(string $engine): void
    {
        $bed = self::$bed;
        if ($engine !== 'mysql') {
            if (!IsolatedMysql::available($engine)) { self::markTestSkipped('The requested MariaDB runtime is not configured.'); }
            $bed = RestoreTestBed::build($engine);
        }
        $server = $bed->server;
        try {
            $set = RestoreTestBed::connectionSet($server, 'target');
            $service = new RestoreService($bed->environment(Fixture::temp(), [$set])); $port = RestoreTestBed::freePort();
            $input = $bed->input('mysql-flow', $port, ['database' => 'mysql', 'connection' => 'target']);
            $status = $this->run_($service, $input, $bed->secrets());
            self::assertSame('complete', $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE));
            $schema = $set['_schema']; $run = $schema . '_run';
            self::assertSame([[RestoreTestBed::SOURCE_URL]], $server->rows($schema, "SELECT option_value FROM wp_options WHERE option_name='home'"), 'The original keeps its value');
            self::assertSame([[RestoreTestBed::TARGET_URL]], $server->rows($run, "SELECT option_value FROM wp_options WHERE option_name='home'"));
            $serialized = unserialize($server->rows($run, "SELECT option_value FROM wp_options WHERE option_name='wrb_serialized'")[0][0]);
            self::assertSame(RestoreTestBed::TARGET_URL . '/page', $serialized['url']); self::assertSame('https://source.invalid.example/x', $serialized['keep']);
            self::assertSame([['1']], $server->rows($run, "SELECT COUNT(*) FROM wp_users WHERE user_login='restore-admin'"));
            $migration = $server->newSchema();
            $server->client()->importIntoEmpty($migration, $status['startup']['migrationDatabase'], []);
            self::assertSame([[RestoreTestBed::TARGET_URL]], $server->rows($migration, "SELECT option_value FROM wp_options WHERE option_name='home'"));
            self::assertSame([['0']], $server->rows($migration, "SELECT COUNT(*) FROM wp_users WHERE user_login='restore-admin'"));
            self::assertFileDoesNotExist($server->workspace() . '/importer-' . $schema . '.cnf', 'Client settings end with the job');
            self::assertSame([], glob($server->workspace() . '/.wrb-restore-inspect-*') ?: []);
            $service->startSite('mysql-flow');
            $this->untilSite($service, 'mysql-flow');
            self::assertStringContainsString('"database":"mysql"', RestoreTestBed::http($port, 'GET', '/__wrb_isolation_check')[2]);
            $this->assertPreviewClean($port);
            $other = stream_socket_server('unix://' . dirname($server->workspace()) . '/wrb-other-' . bin2hex(random_bytes(4)) . '.sock');
            $otherPath = (string) stream_socket_get_name($other, false);
            $listener = stream_socket_server('tcp://127.0.0.1:0');
            $listenerPort = (int) substr(strrchr((string) stream_socket_get_name($listener, false), ':'), 1);
            [, , $probe] = RestoreTestBed::http($port, 'GET', '/probe?port=' . $listenerPort . '&socket=' . rawurlencode($otherPath) . '&original=' . $schema);
            fclose($listener); fclose($other); @unlink($otherPath);
            $results = json_decode($probe, true);
            self::assertSame('http://127.0.0.1:' . $port, $results['database'], 'The one registered socket works; the stored target reads back through the preview filter');
            self::assertFalse($results['tcp'], 'TCP stays denied'); self::assertFalse($results['otherSocket'], 'Any other socket stays denied');
            self::assertFalse($results['original'], 'The running account cannot read the original database');
            self::assertFalse($results['neighbor']);
            self::assertSame('stopped', $service->stopSite('mysql-flow')['site']['state']);
            $repeat = new RestoreService($bed->environment(Fixture::temp(), [$set]));
            $again = $repeat->preflight($bed->input('mysql-again', RestoreTestBed::freePort(), ['database' => 'mysql', 'connection' => 'target']), $bed->secrets(), 'actor-a');
            self::assertFalse($again['ok'], 'A used database is refused, never imported into again');
        } finally { if ($engine !== 'mysql') { foreach ($this->services as [$running, $job]) { try { $running->stopSite($job); } catch (\Throwable $ignored) { } } $server->stop(); } }
    }

    public function engines(): array { return ['MySQL 8.0' => ['mysql'], 'MariaDB 10.5' => ['mariadb105'], 'MariaDB 10.11' => ['mariadb']]; }

    public function testChangesAfterTheCheckExpireTheConfirmation(): void
    {
        $service = $this->service(); $port = RestoreTestBed::freePort();
        $input = self::$bed->input('bound', $port); $secrets = self::$bed->secrets();
        $hash = $service->preflight($input, $secrets, 'actor-a')['planHash'];
        foreach (['targetUrl' => 'https://other.example.com', 'purpose' => 'full', 'tablePrefix' => 'x_', 'adminLogin' => 'someone'] as $field => $value) {
            $this->assertRefused(fn () => $service->start([$field => $value] + $input, $secrets, $hash, 'bound', 'actor-a'), $field);
        }
        $this->assertRefused(fn () => $service->start($input, $secrets, $hash, 'bound', 'other-session'), 'another session');
        $this->assertRefused(fn () => $service->start($input, $secrets, $hash, 'wrong-name', 'actor-a'), 'wrong confirmation');
        $this->assertRefused(fn () => $service->start($input, ['identity' => "AGE-SECRET-KEY-1INVALID\n"] + $secrets, $hash, 'bound', 'actor-a'), 'a key that cannot decrypt');
        $root = $this->base($service);
        $plan = $root . '/plans/' . $hash . '.json';
        $record = json_decode((string) file_get_contents($plan), true); $record['expiresAt'] = time() - 1; file_put_contents($plan, json_encode($record));
        $this->assertRefused(fn () => $service->start($input, $secrets, $hash, 'bound', 'actor-a'), 'expired');
        self::assertDirectoryDoesNotExist($root . '/jobs/bound');
        $fresh = $service->preflight($input, $secrets, 'actor-a')['planHash'];
        $status = $service->start($input, $secrets, $fresh, 'bound', 'actor-a'); $this->services[] = [$service, 'bound'];
        self::assertContains($status['state'], ['queued', 'running', 'complete']);
        $this->assertRefused(fn () => $service->start($input, $secrets, $fresh, 'bound', 'actor-a'), 'single use');
        $this->finish($service, 'bound');
    }

    public function testExistingDestinationsAndUnsafeRequestsAreRefusedWithoutSideEffects(): void
    {
        $service = $this->service(); $port = RestoreTestBed::freePort(); $secrets = self::$bed->secrets();
        $root = $this->base($service);
        $before = scandir($root . '/jobs');
        foreach (['../../escape', 'A', 'a/b', '-x', 'x-', str_repeat('a', 41)] as $name) {
            $review = $service->preflight(self::$bed->input($name, $port), $secrets, 'a');
            self::assertFalse($review['ok'], $name); self::assertSame('name', $review['checks'][0]['field']);
        }
        foreach (['ftp://x.example', 'https://user:p@x.example', 'https://x.example/?q=1', 'javascript:alert(1)'] as $url) {
            $review = $service->preflight(self::$bed->input('valid', $port, ['targetUrl' => $url]), $secrets, 'a');
            self::assertFalse($review['ok'], $url); self::assertSame('targetUrl', $review['checks'][0]['field']);
        }
        self::assertFalse($service->preflight(self::$bed->input('valid', 80), $secrets, 'a')['ok'], 'privileged port');
        self::assertFalse($service->preflight(self::$bed->input('valid', $port, ['targetUrl' => RestoreTestBed::SOURCE_URL]), $secrets, 'a')['ok'], 'target equal to the source');
        self::assertFalse($service->preflight(self::$bed->input('valid', $port), self::$bed->secrets(['adminPassword' => 'short']), 'a')['ok']);
        $taken = stream_socket_server('tcp://127.0.0.1:' . $port);
        self::assertFalse($service->preflight(self::$bed->input('valid', $port), $secrets, 'a')['ok'], 'occupied port'); fclose($taken);
        self::assertSame($before, scandir($root . '/jobs'));
        mkdir($root . '/jobs/existing', 0700);
        $review = $service->preflight(self::$bed->input('existing', $port), $secrets, 'a');
        self::assertFalse($review['ok']);
        self::assertSame(['.', '..'], scandir($root . '/jobs/existing'), 'An existing destination is not touched');
        symlink(sys_get_temp_dir(), $root . '/jobs/linked');
        self::assertFalse($service->preflight(self::$bed->input('linked', $port), $secrets, 'a')['ok'], 'A link in place of the job directory');

        $outside = Fixture::temp();
        foreach ([$outside . '/inside', $outside] as $protected) {
            @mkdir($outside . '/inside', 0700);
            $rejected = false;
            try { new RestoreService(self::$bed->environment($outside . '/inside', [], ['protectedPaths' => [$protected]])); } catch (RestoreError $error) { $rejected = true; }
            self::assertTrue($rejected, 'A state directory overlapping a protected path');
        }
        $link = $outside . '/link'; symlink($outside, $link);
        $rejected = false;
        try { new RestoreService(self::$bed->environment($link)); } catch (RestoreError $error) { $rejected = true; }
        self::assertTrue($rejected, 'A linked state directory');
    }

    public function testUnregisteredConnectionsAndTheActiveDatabaseAreRefused(): void
    {
        $set = RestoreTestBed::connectionSet(self::$bed->server, 'target');
        $service = $this->service([$set], ['activeDatabase' => $set['_schema']]);
        $port = RestoreTestBed::freePort(); $secrets = self::$bed->secrets();
        $review = $service->preflight(self::$bed->input('x', $port, ['database' => 'mysql', 'connection' => 'not-registered']), $secrets, 'a');
        self::assertFalse($review['ok']); self::assertSame('connection', $review['checks'][0]['field']);
        self::assertFalse($service->preflight(self::$bed->input('x', $port, ['database' => 'mysql', 'connection' => 'target']), $secrets, 'a')['ok'], 'The active site database');
        $plain = $this->service([$set]);
        self::$bed->server->query($set['_schema'], 'CREATE TABLE existing_data(id INT)');
        $review = $plain->preflight(self::$bed->input('x', $port, ['database' => 'mysql', 'connection' => 'target']), $secrets, 'a');
        self::assertFalse($review['ok'], 'A database with existing tables');
        self::assertSame([], glob($set['workspace'] . '/importer-' . $set['_schema'] . '*') ?: [], 'A check leaves no client settings');
        $encoded = json_encode($review, JSON_UNESCAPED_UNICODE);
        foreach ([$set['_importer']['password'], $set['_runner']['password']] as $secret) { self::assertStringNotContainsString($secret, $encoded); }

        $wide = RestoreTestBed::connectionSet(self::$bed->server, 'wide');
        self::$bed->server->query('', "GRANT SELECT ON `" . str_replace('_', '\\_', $wide['_schema']) . "`.* TO '" . $wide['_runner']['user'] . "'@'localhost'");
        $review = $this->service([$wide])->preflight(self::$bed->input('x', $port, ['database' => 'mysql', 'connection' => 'wide']), $secrets, 'a');
        self::assertFalse($review['ok'], 'A running account that can read the original is refused');
        $same = RestoreTestBed::connectionSet(self::$bed->server, 'same');
        $same['runner'] = $same['importer'];
        $review = $this->service([$same])->preflight(self::$bed->input('x', $port, ['database' => 'mysql', 'connection' => 'same']), $secrets, 'a');
        self::assertFalse($review['ok'], 'The original and running databases need separate accounts');

        $manual = RestoreTestBed::connectionSet(self::$bed->server, 'manual', false);
        $bad = self::$bed->secrets(['inspectorUser' => 'root', 'inspectorPassword' => '', 'importerUser' => $manual['_importer']['user'], 'importerPassword' => "wrong-MARK9\"\n[client]", 'runnerUser' => $manual['_runner']['user'], 'runnerPassword' => $manual['_runner']['password']]);
        $review = $this->service([$manual])->preflight(self::$bed->input('x', $port, ['database' => 'mysql', 'connection' => 'manual', 'mysqlDatabase' => $manual['_schema']]), $bad, 'a');
        self::assertFalse($review['ok'], 'A setting that could inject a client option');
        self::assertStringNotContainsString('MARK9', json_encode($review, JSON_UNESCAPED_UNICODE));
        $wrong = array_merge($bad, ['importerPassword' => 'wrong-MARK9']);
        $review = $this->service([$manual])->preflight(self::$bed->input('x', $port, ['database' => 'mysql', 'connection' => 'manual', 'mysqlDatabase' => $manual['_schema']]), $wrong, 'a');
        self::assertFalse($review['ok'], 'A wrong password');
        self::assertStringNotContainsString('MARK9', json_encode($review, JSON_UNESCAPED_UNICODE));
        $good = array_merge($bad, ['importerPassword' => $manual['_importer']['password']]);
        $review = $this->service([$manual])->preflight(self::$bed->input('x', $port, ['database' => 'mysql', 'connection' => 'manual', 'mysqlDatabase' => $manual['_schema']]), $good, 'a');
        self::assertTrue($review['ok'], json_encode($review['checks'] ?? [], JSON_UNESCAPED_UNICODE));
    }

    public function testASlowWorkerHoldsNoRequestAndSurvivesReloadStopAndInterruption(): void
    {
        $slow = Fixture::temp() . '/slow-age';
        file_put_contents($slow, "#!/bin/sh\nsleep 1\nexec " . escapeshellarg(Fixture::runtime()['age']) . " \"\$@\"\n"); chmod($slow, 0700);
        $set = RestoreTestBed::connectionSet(self::$bed->server, 'target');
        $service = $this->service([$set], ['age' => $slow]); $port = RestoreTestBed::freePort();
        $input = self::$bed->input('slow', $port, ['database' => 'mysql', 'connection' => 'target']); $secrets = self::$bed->secrets();
        $hash = $service->preflight($input, $secrets, 'a')['planHash'];
        $began = microtime(true);
        $status = $service->start($input, $secrets, $hash, 'slow', 'a'); $this->services[] = [$service, 'slow'];
        self::assertLessThan(5.0, microtime(true) - $began, 'The request returns after handing over, not at the end of the work');
        self::assertContains($status['state'], ['queued', 'running'], json_encode($status, JSON_UNESCAPED_UNICODE));
        self::assertSame(RestoreTestBed::TARGET_URL, $status['site']['publication']['home']);
        self::assertSame('http://127.0.0.1:' . $port, $status['site']['previewUrl']);
        self::assertFalse($status['site']['openable']);
        $review = $service->preflight(self::$bed->input('second', $port), $secrets, 'a');
        self::assertFalse($review['ok'], 'A second restore waits for the first');
        self::assertSame('exclusive', array_column($review['checks'], 'id')[1]);
        $reload = new RestoreService(self::$bed->environment($this->base($service), [$set], ['age' => $slow]));
        self::assertContains($reload->context()['latest']['state'], ['queued', 'running'], 'A new request sees the same job');
        $lines = shell_exec('ps -axww -o command');
        foreach ([self::$bed->identity, $set['_importer']['password'], RestoreTestBed::ADMIN_PASSWORD] as $secret) { self::assertStringNotContainsString(trim($secret), (string) $lines, 'Secrets stay out of process arguments'); }
        $this->waitForFile($set['workspace'] . '/importer-' . $set['_schema'] . '.cnf');
        self::assertSame(0600, fileperms($set['workspace'] . '/importer-' . $set['_schema'] . '.cnf') & 0777);
        $stopped = $reload->stop('slow');
        self::assertSame('stopped', $stopped['state'], json_encode($stopped, JSON_UNESCAPED_UNICODE));
        self::assertFileDoesNotExist($set['workspace'] . '/importer-' . $set['_schema'] . '.cnf');

        $set2 = RestoreTestBed::connectionSet(self::$bed->server, 'target2');
        $service2 = $this->service([$set2], ['age' => $slow]);
        $input2 = self::$bed->input('killed', RestoreTestBed::freePort(), ['database' => 'mysql', 'connection' => 'target2']);
        $hash2 = $service2->preflight($input2, $secrets, 'a')['planHash'];
        $service2->start($input2, $secrets, $hash2, 'killed', 'a');
        $cnf = $set2['workspace'] . '/importer-' . $set2['_schema'] . '.cnf';
        $this->waitForFile($cnf);
        $pid = (int) file_get_contents($this->base($service2) . '/jobs/killed/worker.lock');
        self::assertGreaterThan(1, $pid); posix_kill($pid, 9);
        $deadline = microtime(true) + 20;
        do { $state = $service2->status('killed'); usleep(100000); } while ($state['state'] === 'running' && microtime(true) < $deadline);
        self::assertSame('interrupted', $state['state'], json_encode($state, JSON_UNESCAPED_UNICODE));
        self::assertFileDoesNotExist($cnf, 'A killed worker cannot leave client settings behind once its status is read');
        self::assertStringContainsString('再取込しません', $state['message']);
    }

    public function testAStartupFailureKeepsTheOriginalAndRetriesOnlyThatStage(): void
    {
        $service = $this->service(); $port = RestoreTestBed::freePort();
        $status = $this->run_($service, self::$bed->input('retry', $port, ['tablePrefix' => 'absent_']), self::$bed->secrets());
        self::assertSame('startup-failed', $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE));
        self::assertSame('verified', $status['results']['database']['state']); self::assertSame('failed', $status['results']['startup']['state']);
        $job = $this->base($service) . '/jobs/retry'; $original = hash_file('sha256', $job . '/original-db/site.sqlite');
        self::assertDirectoryDoesNotExist($job . '/site', 'An unverified URL source stops before the startup copy is made');
        $this->assertRefused(fn () => $service->retryStartup('retry', ['tablePrefix' => '../bad'], self::$bed->secrets()), 'a bad prefix');
        $service->retryStartup('retry', ['tablePrefix' => 'wp_'], self::$bed->secrets());
        $status = $this->finish($service, 'retry');
        self::assertSame('complete', $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE));
        self::assertSame($original, hash_file('sha256', $job . '/original-db/site.sqlite'), 'The original database is not imported again');
        self::assertDirectoryExists($job . '/site');
        self::assertSame(RestoreTestBed::TARGET_URL, RestoreTestBed::sqliteValue($job . '/running-db/site.sqlite', "SELECT option_value FROM wp_options WHERE option_name='home'"));
    }

    public function testDatabaseImportFailureIsNotRepeated(): void
    {
        $set = RestoreTestBed::connectionSet(self::$bed->server, 'target');
        $service = $this->service([$set]);
        $input = self::$bed->input('importfail', RestoreTestBed::freePort(), ['database' => 'mysql', 'connection' => 'target']); $secrets = self::$bed->secrets();
        $review = $service->preflight($input, $secrets, 'a');
        self::assertTrue($review['ok']);
        // The next statement after the check removes a right the import needs, as a concurrent administrator might.
        self::$bed->server->query('', 'REVOKE CREATE ON `' . str_replace('_', '\\_', $set['_schema']) . "`.* FROM '" . $set['_importer']['user'] . "'@'localhost'");
        $status = $service->start($input, $secrets, $review['planHash'], 'importfail', 'a');
        $this->services[] = [$service, 'importfail'];
        $status = $this->finish($service, 'importfail');
        self::assertSame('failed', $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE));
        self::assertSame('failed', $status['results']['database']['state']);
        self::assertSame('pending', $status['results']['startup']['state']);
        self::assertFileExists($set['workspace'] . '/.wrb-import-' . $set['_schema'], 'The attempt stays recorded');
        $again = $this->service([$set])->preflight(self::$bed->input('importfail2', RestoreTestBed::freePort(), ['database' => 'mysql', 'connection' => 'target']), $secrets, 'a');
        self::assertFalse($again['ok'], 'The attempted database is refused for any new job');
        $encoded = json_encode($status, JSON_UNESCAPED_UNICODE);
        foreach ([$set['_importer']['password'], trim(self::$bed->identity), RestoreTestBed::ADMIN_PASSWORD] as $secret) { self::assertStringNotContainsString($secret, $encoded); }
    }

    public function testARemoteLinuxServerNeverPresentsALoopbackLinkAsSuccess(): void
    {
        $linux = $this->service([], ['isolationSupported' => false]);
        $port = RestoreTestBed::freePort();
        $status = $this->run_($linux, self::$bed->input('linux', $port), self::$bed->secrets());
        self::assertSame('complete', $status['state']);
        self::assertSame('unavailable', $status['site']['state']); self::assertFalse($status['site']['openable']);
        self::assertStringContainsString('起動しません', $status['site']['reason']); self::assertStringContainsString('macOS', $status['site']['guidance']);
        $refused = false;
        try { $linux->startSite('linux'); } catch (RestoreError $error) { $refused = true; }
        self::assertTrue($refused, 'No unisolated start exists');

        $base = $this->base($linux);
        $remote = new RestoreService(self::$bed->environment($base, [], ['viewerLocal' => false]));
        $remote->startSite('linux'); $this->services[] = [$remote, 'linux'];
        $running = $this->untilSite($remote, 'linux');
        self::assertFalse($running['site']['openable'], 'A loopback link is not offered to another computer');
        self::assertStringContainsString('ssh -L ' . $port . ':127.0.0.1:' . $port, $running['site']['guidance']);
        $remote->stopSite('linux');
    }

    public function testEverySecretIsBoundToTheConfirmationWithoutAppearingInIt(): void
    {
        $service = $this->service(); $port = RestoreTestBed::freePort();
        $input = self::$bed->input('secret-bound', $port); $secrets = self::$bed->secrets();
        $review = $service->preflight($input, $secrets, 'a');
        $this->assertRefused(fn () => $service->start($input, self::$bed->secrets(['adminPassword' => 'another-valid-password']), $review['planHash'], 'secret-bound', 'a'), 'a different administrator password');
        $this->assertRefused(fn () => $service->start($input, ['identity' => self::$bed->identity . "\n"] + $secrets, $review['planHash'], 'secret-bound', 'a'), 'a different key text');
        self::assertDirectoryDoesNotExist($this->base($service) . '/jobs/secret-bound', 'A refused start creates nothing');
        $record = (string) file_get_contents($this->base($service) . '/plans/' . $review['planHash'] . '.json');
        self::assertSame(0600, fileperms($this->base($service) . '/plans/' . $review['planHash'] . '.json') & 0777);
        foreach ([RestoreTestBed::ADMIN_PASSWORD, trim(self::$bed->identity)] as $secret) { self::assertStringNotContainsString($secret, $record); self::assertStringNotContainsString($secret, json_encode($review)); }
        self::assertNotSame(hash('sha256', RestoreTestBed::ADMIN_PASSWORD), $review['planHash']);
        $other = $this->service();
        self::assertSame($review['planHash'], RestorePlan::hash(RestorePlan::normalize($input, []), ['base' => $this->base($service), 'receipt' => $this->receipt(), 'root' => 'r0001', 'sourceUrl' => RestoreTestBed::SOURCE_URL]), 'The public value depends on the request and facts only');
        $held = fopen($this->base($service) . '/run.lock', 'c+b'); flock($held, LOCK_EX);
        $this->assertRefused(fn () => $service->start($input, $secrets, $review['planHash'], 'secret-bound', 'a'), 'another operation holding the lock');
        flock($held, LOCK_UN); fclose($held);
        $status = $service->start($input, $secrets, $review['planHash'], 'secret-bound', 'a'); $this->services[] = [$service, 'secret-bound'];
        self::assertContains($status['state'], ['queued', 'running', 'complete']);
        $this->finish($service, 'secret-bound');

        $set = RestoreTestBed::connectionSet(self::$bed->server, 'target', false);
        $mysql = $this->service([$set]); $form = self::$bed->input('manual', RestoreTestBed::freePort(), ['database' => 'mysql', 'connection' => 'target', 'mysqlDatabase' => $set['_schema']]);
        $accounts = self::$bed->secrets(['inspectorUser' => 'root', 'inspectorPassword' => '', 'importerUser' => $set['_importer']['user'], 'importerPassword' => $set['_importer']['password'], 'runnerUser' => $set['_runner']['user'], 'runnerPassword' => $set['_runner']['password']]);
        $plan = $mysql->preflight($form, $accounts, 'a');
        self::assertTrue($plan['ok'], json_encode($plan['checks'], JSON_UNESCAPED_UNICODE));
        self::$bed->server->query('', "ALTER USER '" . $set['_runner']['user'] . "'@'localhost' IDENTIFIED BY 'changed-password-MARK4'");
        $this->assertRefused(fn () => $mysql->start($form, ['runnerPassword' => 'changed-password-MARK4'] + $accounts, $plan['planHash'], 'manual', 'a'), 'a different database account password');
        self::assertDirectoryDoesNotExist($this->base($mysql) . '/jobs/manual');
    }

    public function testPortAndDatabaseNameLimitsMatchWhatTheServerAccepts(): void
    {
        foreach ([['999', false], ['1000', false], ['1023', false], ['1024', true], ['65535', true], ['65536', false], ['99999', false]] as [$port, $valid]) {
            $accepted = true;
            try { RestorePlan::normalize(self::$bed->input('a', 8181, ['previewPort' => $port]), []); } catch (RestoreError $error) { $accepted = false; self::assertSame('previewPort', $error->field()); }
            self::assertSame($valid, $accepted, $port);
        }
        $connections = ['s' => ['id' => 's']];
        $long = 'wrb_test_' . str_repeat('a', 51);
        self::assertSame(64, strlen(RestorePlan::normalize(self::$bed->input('a', 8181, ['database' => 'mysql', 'connection' => 's', 'mysqlDatabase' => $long]), $connections)['mysql']['runningDatabase']));
        $rejected = false;
        try { RestorePlan::normalize(self::$bed->input('a', 8181, ['database' => 'mysql', 'connection' => 's', 'mysqlDatabase' => $long . 'a']), $connections); } catch (RestoreError $error) { $rejected = true; self::assertSame('mysqlDatabase', $error->field()); }
        self::assertTrue($rejected, 'The running database name would pass 64 characters');
    }

    /** @dataProvider unsupportedShapes */
    public function testAShapeTheOneUrlCopyCannotRepresentStopsWithItsReason(array $tables, array $options, string $config, string $expected): void
    {
        $root = Fixture::temp();
        if ($config !== '') { file_put_contents($root . '/wp-config.php', $config); }
        $refused = null;
        try { \WordPressR2Backup\RestoredSiteCheck::assertSupported($tables, $options, 'wp_', $root); } catch (RestoreError $error) { $refused = $error->getMessage(); }
        self::assertNotNull($refused); self::assertStringContainsString($expected, $refused); self::assertStringContainsString('原本は保持', $refused);
    }

    public function unsupportedShapes(): array
    {
        $same = ['home' => 'https://a.example', 'siteurl' => 'https://a.example'];
        return ['subdirectory' => [['wp_options'], ['home' => 'https://a.example', 'siteurl' => 'https://a.example/wp'], '', 'siteurl'],
            'config subdirectory' => [['wp_options'], $same, "<?php\ndefine('WP_SITEURL', 'https://a.example/wp');\n", 'siteurl'],
            'multisite tables' => [['wp_options', 'wp_blogs', 'wp_site'], $same, '', 'マルチサイト'],
            'multisite config' => [['wp_options'], $same, "<?php\ndefine( 'MULTISITE', true );\n", 'マルチサイト']];
    }

    public function testSingleSiteShapesPassAndOldLayoutConstantsAreReported(): void
    {
        $root = Fixture::temp();
        file_put_contents($root . '/wp-config.php', "<?php\n// define('MULTISITE', true);\n/* define('WP_SITEURL', 'https://x.example/wp'); */\ndefine('WP_CONTENT_URL', 'https://cdn.example/c');\ndefine('UPLOADS', 'files');\ndefine('WP_ALLOW_MULTISITE', false);\n");
        self::assertSame(['WP_CONTENT_URL', 'UPLOADS'], \WordPressR2Backup\RestoredSiteCheck::assertSupported(['wp_options'], ['home' => 'https://a.example/', 'siteurl' => 'https://a.example'], 'wp_', $root));
    }

    public function testArchivedConfigurationIsNotExecutedAndNonstandardIncludesAreNotConfirmed(): void
    {
        $root = Fixture::temp(); $config = $root . '/wp-config.php';
        $inspect = static function () use ($root): array { return \WordPressR2Backup\RestoredSiteCheck::inspect(['wp_options'], ['home' => 'https://a.example', 'siteurl' => 'https://a.example'], 'wp_', $root); };
        self::assertFalse($inspect()['configurationPresent']);
        file_put_contents($config, "<?php\n// include 'not-real.php';\nrequire_once ABSPATH . 'wp-settings.php';\n");
        self::assertFalse($inspect()['configurationIncomplete']);
        file_put_contents($config, "<?php\nrequire __DIR__ . '/unavailable.php';\n");
        $original = $inspect();
        self::assertTrue($original['configurationPresent']);
        self::assertTrue($original['configurationIncomplete']);
        self::assertFalse($original['configuration']['home']['mentioned']);
    }

    public function testASubdirectorySiteStopsOnlyTheStartupStageInBothDatabaseModes(): void
    {
        $bed = RestoreTestBed::build('mysql', RestoreTestBed::SOURCE_URL . '/wp');
        try {
            $service = new RestoreService($bed->environment(Fixture::temp()));
            $review = $service->preflight($bed->input('shape-sqlite', RestoreTestBed::freePort()), $bed->secrets(), 'a');
            self::assertTrue($review['ok'], 'The shape is known only after the database is restored');
            $service->start($bed->input('shape-sqlite', (int) parse_url($review['summary']['previewUrl'], PHP_URL_PORT)), $bed->secrets(), $review['planHash'], 'shape-sqlite', 'a');
            $status = $this->finish($service, 'shape-sqlite');
            self::assertSame('startup-failed', $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE));
            self::assertSame('verified', $status['results']['database']['state']); self::assertSame('verified', $status['results']['files']['state']); self::assertSame('failed', $status['results']['startup']['state']);
            self::assertStringContainsString('siteurl', $status['message']);
            $job = $this->base($service) . '/jobs/shape-sqlite';
            self::assertDirectoryDoesNotExist($job . '/site', 'No running copy flattens the two URLs');
            self::assertSame(RestoreTestBed::SOURCE_URL . '/wp', RestoreTestBed::sqliteValue($job . '/original-db/site.sqlite', "SELECT option_value FROM wp_options WHERE option_name='siteurl'"));

            $set = RestoreTestBed::connectionSet($bed->server, 'shape');
            $mysql = new RestoreService($bed->environment(Fixture::temp(), [$set]));
            $input = $bed->input('shape-mysql', RestoreTestBed::freePort(), ['database' => 'mysql', 'connection' => 'shape']);
            $review = $mysql->preflight($input, $bed->secrets(), 'a');
            $mysql->start($input, $bed->secrets(), $review['planHash'], 'shape-mysql', 'a');
            $status = $this->finish($mysql, 'shape-mysql');
            self::assertSame('startup-failed', $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE));
            self::assertSame([['0']], $bed->server->rows($set['_schema'] . '_run', 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'), 'The running database stays unused');
            self::assertSame([[RestoreTestBed::SOURCE_URL . '/wp']], $bed->server->rows($set['_schema'], "SELECT option_value FROM wp_options WHERE option_name='siteurl'"));
        } finally { $bed->server->stop(); }
    }

    public function testPlanHashAndPortableHash(): void
    {
        $plan = RestorePlan::normalize(self::$bed->input('a', 8181), []);
        self::assertSame('http://127.0.0.1:8181', $plan['previewUrl']); self::assertSame(RestoreTestBed::TARGET_URL, $plan['targetUrl']);
        self::assertNotSame(RestorePlan::hash($plan, ['x' => 1]), RestorePlan::hash($plan, ['x' => 2]));
        self::assertSame(RestorePlan::hash(['a' => 1, 'b' => 2], []), RestorePlan::hash(['b' => 2, 'a' => 1], []));
        self::assertSame('$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0', self::portable('test12345', '$P$9IQRaTwmf'), 'The reference verifier matches the published phpass vector');
        $hash = RestorePlan::portableHash('correct horse');
        self::assertMatchesRegularExpression('/\A\$P\$B[.\/0-9A-Za-z]{30}\z/D', $hash);
        self::assertSame($hash, self::portable('correct horse', $hash));
        self::assertNotSame($hash, self::portable('wrong', $hash));
    }

    private static function portable(string $password, string $setting): string
    {
        $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $count = 1 << strpos($alphabet, $setting[3]); $salt = substr($setting, 4, 8);
        $hash = md5($salt . $password, true);
        do { $hash = md5($hash . $password, true); } while (--$count);
        $output = substr($setting, 0, 12); $i = 0;
        do {
            $value = ord($hash[$i++]); $output .= $alphabet[$value & 63];
            if ($i < 16) { $value |= ord($hash[$i]) << 8; } $output .= $alphabet[($value >> 6) & 63];
            if ($i++ >= 16) { break; }
            if ($i < 16) { $value |= ord($hash[$i]) << 16; } $output .= $alphabet[($value >> 12) & 63];
            if ($i++ >= 16) { break; }
            $output .= $alphabet[($value >> 18) & 63];
        } while ($i < 16);
        return $output;
    }

    private function receipt(): string
    {
        $service = new \WordPressR2Backup\Restorer(new \WordPressR2Backup\AgeProcess(Fixture::runtime()['age']), \WordPressR2Backup\LocalRepository::forRecovery(self::$bed->repository, self::$bed->unit));
        $stream = fopen('php://memory', 'w+b'); fwrite($stream, self::$bed->identity); rewind($stream);
        try { return $service->describe(self::$bed->snapshot, $stream)['receiptSha256']; } finally { fclose($stream); }
    }

    private function base(RestoreService $service): string
    {
        $property = new \ReflectionProperty($service, 'base'); $property->setAccessible(true);
        return $property->getValue($service);
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (!is_file($path)) { if (microtime(true) > $deadline) { self::fail('Timed out waiting for the worker.'); } usleep(50000); clearstatcache(true, $path); }
    }

    private function assertRefused(callable $call, string $why): void
    {
        $refused = false;
        try { $call(); } catch (RestoreError $error) { $refused = true; }
        self::assertTrue($refused, 'Must refuse: ' . $why);
    }

    private function assertNoSecret(string $directory, array $secrets): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->isLink() || $file->getSize() > 20000000) { continue; }
            $bytes = (string) file_get_contents($file->getPathname());
            foreach ($secrets as $secret) { self::assertStringNotContainsString(trim($secret), $bytes, $file->getPathname()); }
        }
    }
}
