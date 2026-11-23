<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** The independent entry over real HTTP, through PHP's built-in server exactly as the owner starts it. @group integration */
final class RestoreWebTest extends TestCase
{
    private static ?RestoreTestBed $bed = null;
    private $process = null;
    private array $pipes = [];
    private string $state = '';
    private int $port = 0;
    private string $cookie = '';
    private string $csrf = '';
    private string $code = '';
    private ?string $kitScript = null;

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
        if ($this->state !== '') {
            foreach (['stop-site', 'stop-job'] as $command) { $this->cli([$command]); }
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process, 15);
            foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            proc_close($this->process); $this->process = null;
        }
    }

    private function script(): string { return $this->kitScript ?? dirname(__DIR__) . '/recovery/recover-web.php'; }

    private function cli(array $arguments): array
    {
        $process = proc_open(array_merge([PHP_BINARY, $this->script()], [$arguments[0], '--state-dir', $this->state], array_slice($arguments, 1)), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $out, $error];
    }

    private function boot(?string $age = null, bool $withoutSource = false): void
    {
        $this->state = Fixture::temp(); $this->port = RestoreTestBed::freePort();
        $command = [PHP_BINARY, $this->script(), 'start', '--state-dir', $this->state, '--port', (string) $this->port, '--age', $age ?? Fixture::runtime()['age']];
        if (!$withoutSource) { array_push($command, '--repository', self::$bed->repository, '--unit', self::$bed->unit); }
        $this->process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        stream_set_blocking($this->pipes[1], true);
        $deadline = microtime(true) + 10; $output = '';
        while (microtime(true) < $deadline && strpos($output, 'Ctrl-C') === false) { $output .= (string) fread($this->pipes[1], 4096); }
        self::assertSame(1, preg_match('/([a-f0-9]{64})/', $output, $match), 'The code appears on the owner\'s terminal');
        $this->code = $match[1];
        do {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $c, $m, 0.2);
            if ($socket !== false) { fclose($socket); return; }
            usleep(50000);
        } while (microtime(true) < $deadline);
        self::fail('The independent server did not start.');
    }

    public function testUnconfiguredScreenAcceptsNoUntrustedEndpointOrOversizedSecret(): void
    {
        $this->boot(null, true); $this->signIn();
        [$code, $result] = $this->api('snapshots', ['r2Connection' => '{"endpoint":"http://127.0.0.1"}']);
        self::assertSame(400, $code); self::assertArrayHasKey('error', $result);
        [$code, $result] = $this->api('snapshots', ['r2Connection' => str_repeat('x', 8193)]);
        self::assertSame(400, $code); self::assertSame('r2Connection', $result['field']);
        self::assertStringNotContainsString('127.0.0.1', (string) file_get_contents($this->state . '/web/config.json'));
    }

    private function origin(): array { return ['Origin' => 'http://127.0.0.1:' . $this->port]; }

    private function activate(?string $code = null, array $extra = []): array
    {
        return RestoreTestBed::http($this->port, 'POST', '/activate', $this->origin() + $extra, http_build_query(['code' => $code ?? $this->code]));
    }

    private function signIn(): void
    {
        [$code, $headers] = $this->activate();
        self::assertSame(303, $code);
        self::assertSame(1, preg_match('/wrb_recovery_session=([a-f0-9]{64})/', $headers['set-cookie'], $match));
        $this->cookie = $match[1];
        [, , $page] = RestoreTestBed::http($this->port, 'GET', '/', ['Cookie' => 'wrb_recovery_session=' . $this->cookie]);
        self::assertSame(1, preg_match('/data-token="([a-f0-9]{64})"/', $page, $token));
        $this->csrf = $token[1];
    }

    private function api(string $command, array $fields = []): array
    {
        [$code, , $body] = RestoreTestBed::http($this->port, 'POST', '/api', $this->origin() + ['Cookie' => 'wrb_recovery_session=' . $this->cookie], http_build_query($fields + ['command' => $command, 'csrf' => $this->csrf]));
        return [$code, json_decode($body, true) ?? ['raw' => $body]];
    }

    private function editJson(string $name, array $changes): void
    {
        $path = $this->state . '/web/' . $name;
        file_put_contents($path, json_encode($changes + json_decode((string) file_get_contents($path), true)));
    }

    public function testTheScreenStartsDisabledAndOnlyTheTerminalCodeActivatesIt(): void
    {
        $this->boot();
        [$code, , $page] = RestoreTestBed::http($this->port, 'GET', '/');
        self::assertSame(200, $code); self::assertStringContainsString('有効化コード', $page); self::assertStringNotContainsString('wrb-r-form', $page);
        self::assertStringContainsString('php recover-web.php issue --state-dir &lt;起動時に指定した状態フォルダー&gt;', $page);
        self::assertStringNotContainsString($this->state, $page);
        self::assertStringNotContainsString($this->code, $page);
        [$code] = RestoreTestBed::http($this->port, 'GET', '/web/token.json');
        self::assertSame(404, $code, 'Private activation records are never served by HTTP');
        [$code] = $this->api('status');
        self::assertSame(401, $code, 'The API is closed before activation');
        foreach (range(1, 5) as $ignored) { [$code] = $this->activate(str_repeat('0', 64)); self::assertSame(401, $code); }
        [$code] = $this->activate();
        self::assertSame(401, $code, 'Five failures burn the code');
        [$exit, $out] = $this->cli(['issue']);
        self::assertSame(0, $exit);
        self::assertSame(1, preg_match('/([a-f0-9]{64})/', $out, $match)); $this->code = $match[1];
        $this->editJson('token.json', ['expiresAt' => time() - 1]);
        [$code] = $this->activate();
        self::assertSame(401, $code, 'An expired code');
        [, $out] = $this->cli(['issue']); preg_match('/([a-f0-9]{64})/', $out, $match); $this->code = $match[1];
        $tokenFile = (string) file_get_contents($this->state . '/web/token.json');
        self::assertStringNotContainsString($this->code, $tokenFile, 'Only the hash is stored');
        self::assertSame(0600, fileperms($this->state . '/web/token.json') & 0777);
        $planted = str_repeat('a', 64);
        [$code, $headers] = $this->activate(null, ['Cookie' => 'wrb_recovery_session=' . $planted]);
        self::assertSame(303, $code);
        self::assertStringContainsString('HttpOnly', $headers['set-cookie']); self::assertStringContainsString('SameSite=Strict', $headers['set-cookie']);
        self::assertStringNotContainsString($planted, $headers['set-cookie'], 'A session id proposed by the browser is never adopted');
        [$reused] = $this->activate();
        self::assertSame(401, $reused, 'The code works once');
        $this->cookie = $planted; $this->csrf = str_repeat('b', 64);
        [$code] = $this->api('status');
        self::assertSame(401, $code, 'The planted cookie has no session');
    }

    public function testRequestsFromAnywhereElseAreRefused(): void
    {
        $this->boot(); $this->signIn();
        $cookie = ['Cookie' => 'wrb_recovery_session=' . $this->cookie];
        $post = http_build_query(['command' => 'status', 'csrf' => $this->csrf]);
        foreach ([['Host' => 'localhost:' . $this->port], ['Host' => 'evil.example'], ['Host' => '127.0.0.1:' . ($this->port + 1)], ['X-Forwarded-For' => '127.0.0.1'], ['Forwarded' => 'for=127.0.0.1'], ['X-Real-IP' => '127.0.0.1'], ['X-Forwarded-Host' => '127.0.0.1:' . $this->port]] as $headers) {
            [$code] = RestoreTestBed::http($this->port, 'GET', '/', $headers + $cookie);
            self::assertSame(403, $code, json_encode($headers));
        }
        foreach ([[], ['Origin' => 'http://evil.example'], ['Origin' => 'http://localhost:' . $this->port], ['Origin' => 'null'], ['Origin' => 'http://127.0.0.1:' . $this->port, 'Sec-Fetch-Site' => 'cross-site']] as $headers) {
            [$code] = RestoreTestBed::http($this->port, 'POST', '/api', $headers + $cookie, $post);
            self::assertSame(403, $code, json_encode($headers));
        }
        [$code] = RestoreTestBed::http($this->port, 'POST', '/api', $this->origin() + $cookie, http_build_query(['command' => 'status']));
        self::assertSame(403, $code, 'No CSRF value');
        [$code] = RestoreTestBed::http($this->port, 'POST', '/api', $this->origin() + $cookie, http_build_query(['command' => 'status', 'csrf' => str_repeat('0', 64)]));
        self::assertSame(403, $code, 'A wrong CSRF value');
        [$code, $headers] = RestoreTestBed::http($this->port, 'GET', '/', $cookie);
        self::assertSame(200, $code);
        self::assertStringContainsString("frame-ancestors 'none'", $headers['content-security-policy']); self::assertSame('no-store', $headers['cache-control']);
        foreach (['/recover.php', '/../recover.php', '/web/config.json', '/assets/../../recover.php'] as $path) {
            [$code] = RestoreTestBed::http($this->port, 'GET', $path, $cookie);
            self::assertSame(404, $code, $path);
        }
    }

    public function testAFullRestoreOverHttpHoldsNoConnectionAndKeepsTheBoundary(): void
    {
        $this->boot(); $this->signIn();
        [$code, $list] = $this->api('snapshots');
        self::assertSame(200, $code); self::assertSame(self::$bed->snapshot, $list['snapshots'][0]['snapshotId']);
        $preview = RestoreTestBed::freePort();
        $form = self::$bed->input('web-flow', $preview) + self::$bed->secrets();
        [$code, $review] = $this->api('preflight', $form);
        self::assertSame(200, $code); self::assertTrue($review['ok'], json_encode($review, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('AGE-SECRET-KEY', json_encode($review));
        $form += ['planHash' => $review['planHash'], 'confirmName' => 'web-flow'];
        [$code, $status] = $this->api('start', $form);
        self::assertSame(200, $code, json_encode($status, JSON_UNESCAPED_UNICODE));
        [$code, $denied] = $this->api('preflight', $form);
        self::assertSame(403, $code, 'Starting consumes the right to start: ' . json_encode($denied, JSON_UNESCAPED_UNICODE));
        $deadline = microtime(true) + 120;
        do { [, $status] = $this->api('status'); usleep(200000); } while (in_array($status['state'], ['queued', 'running'], true) && microtime(true) < $deadline);
        self::assertSame('complete', $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE));
        [$code, $status] = $this->api('site_start', ['job' => 'web-flow']);
        self::assertSame(200, $code, json_encode($status, JSON_UNESCAPED_UNICODE));
        $deadline = microtime(true) + 15;
        do { [, $status] = $this->api('status'); usleep(200000); } while (($status['site']['response'] ?? '') !== 'responding' && microtime(true) < $deadline);
        self::assertTrue($status['site']['openable'], json_encode($status['site'], JSON_UNESCAPED_UNICODE));
        [$code, , $body] = RestoreTestBed::http($preview, 'GET', '/');
        self::assertSame(200, $code); self::assertStringContainsString('http://127.0.0.1:' . $preview . '/post', $body);
        [$code, $status] = $this->api('site_stop', ['job' => 'web-flow']);
        self::assertSame('stopped', $status['site']['state']);
        $this->editJson('session.json', ['startConsumedAt' => time() - 40000]);
        $path = $this->state . '/jobs/web-flow/status.json'; $data = json_decode((string) file_get_contents($path), true); $data['updatedAt'] = time() - 4000; file_put_contents($path, json_encode($data));
        [$code] = $this->api('status');
        self::assertSame(401, $code, 'The session ends some time after the work ends');
        self::assertFileDoesNotExist($this->state . '/web/session.json');
    }

    public function testStartRequestReturnsPromptlyAndExpiryLeavesWatchingAndStopping(): void
    {
        $slow = Fixture::temp() . '/slow-age';
        file_put_contents($slow, "#!/bin/sh\nsleep 1\nexec " . escapeshellarg(Fixture::runtime()['age']) . " \"\$@\"\n"); chmod($slow, 0700);
        $this->boot($slow); $this->signIn();
        $form = self::$bed->input('slow-web', RestoreTestBed::freePort()) + self::$bed->secrets();
        [, $review] = $this->api('preflight', $form);
        self::assertTrue($review['ok'], json_encode($review, JSON_UNESCAPED_UNICODE));
        $this->editJson('session.json', ['lastActiveAt' => time() - 3600]);
        [$code] = $this->api('start', $form + ['planHash' => $review['planHash'], 'confirmName' => 'slow-web']);
        self::assertSame(403, $code, 'An idle session cannot start');
        $this->editJson('session.json', ['lastActiveAt' => time()]);
        $began = microtime(true);
        [$code, $status] = $this->api('start', $form + ['planHash' => $review['planHash'], 'confirmName' => 'slow-web']);
        // RestoreTestBed::http reads to end of stream: a descriptor inherited by the worker would hold this for the whole job.
        self::assertLessThan(6.0, microtime(true) - $began, 'The browser connection ends when the request does');
        self::assertSame(200, $code, json_encode($status, JSON_UNESCAPED_UNICODE));
        self::assertContains($status['state'], ['queued', 'running']);
        $this->editJson('session.json', ['createdAt' => time() - 90000, 'lastActiveAt' => time() - 90000]);
        [$code] = $this->api('preflight', $form);
        self::assertSame(403, $code, 'Past its lifetime the session starts nothing');
        [$code, $status] = $this->api('status');
        self::assertSame(200, $code, 'Progress stays readable'); self::assertContains($status['state'], ['queued', 'running']);
        [$code, $status] = $this->api('stop', ['job' => 'slow-web']);
        self::assertSame(200, $code, 'The running job can still be stopped'); self::assertSame('stopped', $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE));
    }

    /** A new kit carries everything the screen needs, away from the plugin tree. Set WRB_TEST_AGE_ARCHIVE to the verified official archive. */
    public function testANewKitServesTheScreenAndCompletesARestore(): void
    {
        $archive = getenv('WRB_TEST_AGE_ARCHIVE');
        if (!is_string($archive) || !is_file($archive)) { self::markTestSkipped('WRB_TEST_AGE_ARCHIVE names the official age archive.'); }
        $kit = Fixture::temp() . '/kit';
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/recovery/build-kit.php', '--output', $kit, '--age-archive', $archive], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        foreach (['recover-web.php', 'local-router.php', 'library/restore-worker.php', 'library/local-router.php', 'library/assets/restore.js', 'library/assets/restore.css', 'library/src/RestoreService.php'] as $file) { self::assertFileExists($kit . '/' . $file); }
        self::assertArrayHasKey('recover-web.php', json_decode((string) file_get_contents($kit . '/SHA256SUMS.json'), true));
        $this->kitScript = $kit . '/recover-web.php';
        $this->boot($kit . '/dependencies/age/age'); $this->signIn();
        [$code, , $asset] = RestoreTestBed::http($this->port, 'GET', '/assets/restore.js');
        self::assertSame(200, $code); self::assertStringContainsString('wrb-restore', $asset);
        $preview = RestoreTestBed::freePort();
        $form = self::$bed->input('kit-flow', $preview) + self::$bed->secrets();
        [, $review] = $this->api('preflight', $form);
        self::assertTrue($review['ok'], json_encode($review, JSON_UNESCAPED_UNICODE));
        [$code, $status] = $this->api('start', $form + ['planHash' => $review['planHash'], 'confirmName' => 'kit-flow']);
        self::assertSame(200, $code, json_encode($status, JSON_UNESCAPED_UNICODE));
        $deadline = microtime(true) + 120;
        do { [, $status] = $this->api('status'); usleep(200000); } while (in_array($status['state'], ['queued', 'running'], true) && microtime(true) < $deadline);
        self::assertSame('complete', $status['state'], json_encode($status, JSON_UNESCAPED_UNICODE));
        [$code] = $this->api('site_start', ['job' => 'kit-flow']); self::assertSame(200, $code);
        $deadline = microtime(true) + 15;
        do { [, $status] = $this->api('status'); usleep(200000); } while (($status['site']['response'] ?? '') !== 'responding' && microtime(true) < $deadline);
        self::assertTrue($status['site']['openable'], json_encode($status['site'], JSON_UNESCAPED_UNICODE));
        $this->api('site_stop', ['job' => 'kit-flow']);
    }

    public function testTheListenerRefusesAPortBelow1024(): void
    {
        $this->state = Fixture::temp();
        $process = proc_open([PHP_BINARY, $this->script(), 'start', '--state-dir', $this->state, '--port', '1023', '--age', Fixture::runtime()['age'], '--repository', self::$bed->repository, '--unit', self::$bed->unit], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(1, proc_close($process)); self::assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $out);
    }

    public function testClosingTheScreenRevokesAndAWideStateDirectoryIsRefused(): void
    {
        $this->boot(); $this->signIn();
        proc_terminate($this->process, 15);
        $deadline = microtime(true) + 10;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) { usleep(50000); }
        foreach ($this->pipes as $pipe) { fclose($pipe); } proc_close($this->process); $this->process = null;
        self::assertFileDoesNotExist($this->state . '/web/session.json');
        $wide = Fixture::temp(); chmod($wide, 0755);
        $state = $this->state; $this->state = $wide;
        [$exit, $out, $error] = $this->cli(['issue']);
        self::assertSame(1, $exit); self::assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $out . $error);
        $this->state = '';
        chmod($wide, 0700);
    }
}
