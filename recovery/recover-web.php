<?php
declare(strict_types=1);

// Independent restore screen. Owner's terminal: start|issue|stop-job|stop-site. PHP's built-in server: the guarded web entry.
use WordPressR2Backup\{FileSafety, RestoreApi, RestoreError, RestorePage, RestoreService, RestoreWebAuth};

$wrbAutoload = is_file(__DIR__ . '/library/vendor/autoload.php') ? __DIR__ . '/library/vendor/autoload.php' : dirname(__DIR__) . '/vendor/autoload.php';
if (PHP_SAPI === 'cli') {
    umask(0077);
    require $wrbAutoload;
    exit(wrbWebCli($argv, $wrbAutoload));
}
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
umask(0077);
ini_set('display_errors', '0'); ini_set('log_errors', '0');
require $wrbAutoload;
wrbWebServe($wrbAutoload);

function wrbWebCli(array $argv, string $autoload): int
{
    $usage = "php recover-web.php start --state-dir <0700> --port <n> --age <binary> [--repository <dir> --unit <64-hex> | --direct-r2-connection-file <0600>] [--mysql-connections <0600>]\n"
        . "php recover-web.php issue|stop-job|stop-site --state-dir <0700> [--job <name>]\n";
    array_shift($argv);
    $command = array_shift($argv);
    $options = [];
    while ($argv !== []) {
        $flag = array_shift($argv); $value = array_shift($argv);
        if (!is_string($flag) || !in_array($flag, ['--state-dir', '--port', '--age', '--repository', '--unit', '--direct-r2-connection-file', '--mysql-connections', '--job'], true) || !is_string($value) || $value === '' || isset($options[$flag])) { fwrite(STDERR, $usage); return 2; }
        $options[$flag] = $value;
    }
    if (!in_array($command, ['start', 'issue', 'stop-job', 'stop-site'], true) || !isset($options['--state-dir'])) { fwrite(STDERR, $usage); return 2; }
    try {
        $state = FileSafety::privateDirectory($options['--state-dir']);
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0 || fileowner($state) !== posix_geteuid() || fileowner(__FILE__) !== posix_geteuid()) { throw new RestoreError('このコマンドは、状態フォルダーとキットの所有者が通常アカウントで実行してください。'); }
        $auth = new RestoreWebAuth($state);
        if ($command === 'issue') { fwrite(STDOUT, '有効化コード（10分間・一回限り）: ' . $auth->issue() . "\n"); return 0; }
        if ($command !== 'start') {
            $service = new RestoreService(wrbWebEnvironment($state, false));
            $name = $options['--job'] ?? (string) ($service->status()['name'] ?? '');
            if ($name === '') { throw new RestoreError('対象の復元がありません。'); }
            $result = $command === 'stop-job' ? $service->stop($name) : $service->stopSite($name);
            fwrite(STDOUT, json_encode(['state' => $result['state'], 'site' => $result['site']['state'] ?? null], JSON_UNESCAPED_UNICODE) . "\n");
            return 0;
        }
        foreach (['--port', '--age'] as $required) { if (!isset($options[$required])) { fwrite(STDERR, $usage); return 2; } }
        if (!preg_match('/\A[1-9][0-9]{3,4}\z/D', $options['--port']) || (int) $options['--port'] < 1024 || (int) $options['--port'] > 65535) { throw new RestoreError('ポートは1024〜65535で指定してください。'); }
        if (isset($options['--repository'], $options['--direct-r2-connection-file'])) { throw new RestoreError('保存先は一つだけ指定してください。'); }
        $source = isset($options['--repository']) ? ['type' => 'repository', 'path' => $options['--repository'], 'unit' => $options['--unit'] ?? ''] : (isset($options['--direct-r2-connection-file']) ? ['type' => 'direct-r2-file', 'path' => $options['--direct-r2-connection-file']] : ['type' => 'unconfigured']);
        if ($source['type'] !== 'unconfigured') { RestoreService::openRepository($source); }
        RestoreService::loadConnections($options['--mysql-connections'] ?? null);
        $age = realpath($options['--age']);
        if ($age === false || !is_executable($age)) { throw new RestoreError('ageの実行ファイルを確認できません。'); }
        $config = json_encode(['port' => (int) $options['--port'], 'age' => $age, 'source' => $source, 'connections' => $options['--mysql-connections'] ?? null, 'php' => realpath(PHP_BINARY)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $configFile = $state . '/web/config.json';
        file_put_contents($configFile, $config); chmod($configFile, 0600);
        $token = $auth->issue();
        fwrite(STDOUT, "独立復元画面: http://127.0.0.1:" . $options['--port'] . "/\n有効化コード（10分間・一回限り）: " . $token . "\n別のPCからはSSHトンネル（同じポート番号）で接続してください。Ctrl-Cで画面を閉じると権限は失効します。実行中の復元は続きます。\n");
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $options['--port'], __FILE__], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, __DIR__, ['WRB_RECOVERY_WEB_STATE' => $state, 'HOME' => (string) getenv('HOME'), 'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin', 'TMPDIR' => sys_get_temp_dir()], ['bypass_shell' => true]);
        if (!is_resource($process)) { $auth->revoke(); throw new RestoreError('Webサーバーを起動できません。'); }
        $stop = false;
        if (function_exists('pcntl_async_signals')) { pcntl_async_signals(true); foreach ([SIGINT, SIGTERM] as $signal) { pcntl_signal($signal, static function () use (&$stop): void { $stop = true; }); } }
        do { $running = proc_get_status($process)['running']; if ($stop) { proc_terminate($process, 15); } usleep(100000); } while ($running);
        proc_close($process);
        $auth->revoke();
        return 0;
    } catch (RestoreError $error) { fwrite(STDERR, $error->getMessage() . "\n"); return 1; }
    catch (Throwable $error) { fwrite(STDERR, "処理を完了できませんでした。状態フォルダーと指定を確認してください。\n"); return 1; }
}

function wrbWebEnvironment(string $state, bool $viewerLocal): array
{
    $config = json_decode((string) file_get_contents($state . '/web/config.json'), true, 8, JSON_THROW_ON_ERROR);
    return ['base' => $state, 'age' => $config['age'], 'source' => $config['source'], 'connections' => RestoreService::loadConnections($config['connections']), 'viewerLocal' => $viewerLocal, 'php' => $config['php'], 'protectedPaths' => []];
}

function wrbWebRespond(int $code, string $type, string $body, array $headers = []): void
{
    http_response_code($code);
    header('Content-Type: ' . $type); header('Content-Length: ' . strlen($body)); header('Connection: close');
    header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); // no-referrer would turn a form's Origin into null, which the exact match refuses
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    foreach ($headers as $header) { header($header); }
    echo $body;
}

function wrbWebServe(string $autoload): void
{
    $state = getenv('WRB_RECOVERY_WEB_STATE');
    try {
        if (!is_string($state) || $state === '') { throw new RuntimeException('Missing state.'); }
        FileSafety::privateDirectory($state);
        $config = json_decode((string) file_get_contents($state . '/web/config.json'), true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) { wrbWebRespond(503, 'text/plain; charset=utf-8', '利用できません。'); return; }
    $origin = '127.0.0.1:' . $config['port'];
    // A fixed listener: the peer, the requested name and the origin must all be this machine. Forwarding headers mean a proxy is in the path, and none is trusted.
    $forwarded = false;
    foreach (['HTTP_FORWARDED', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_REAL_IP'] as $header) { if (isset($_SERVER[$header])) { $forwarded = true; } }
    if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || ($_SERVER['HTTP_HOST'] ?? '') !== $origin || $forwarded) { wrbWebRespond(403, 'text/plain; charset=utf-8', '接続元を確認できません。'); return; }
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    if ($fetchSite !== null && !in_array($fetchSite, ['same-origin', 'none'], true)) { wrbWebRespond(403, 'text/plain; charset=utf-8', '別のサイトからの要求は受け付けません。'); return; }
    if ($method === 'POST' && ($_SERVER['HTTP_ORIGIN'] ?? '') !== 'http://' . $origin) { wrbWebRespond(403, 'text/plain; charset=utf-8', '別のサイトからの要求は受け付けません。'); return; }
    if ($method === 'GET' && in_array($path, ['/assets/restore.js', '/assets/restore.css'], true)) {
        $file = dirname($autoload, 2) . $path;
        wrbWebRespond(200, substr($path, -2) === 'js' ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8', (string) file_get_contents($file));
        return;
    }
    try { $auth = new RestoreWebAuth($state); }
    catch (Throwable $error) { wrbWebRespond(503, 'text/plain; charset=utf-8', '利用できません。'); return; }
    $cookie = is_string($_COOKIE['wrb_recovery_session'] ?? null) ? $_COOKIE['wrb_recovery_session'] : '';
    if ($method === 'POST' && $path === '/activate') {
        $session = $auth->activate(is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '');
        if ($session === null) { wrbWebPage(401, wrbWebActivation('有効化できませんでした。入力を確認してください。コードの期限が切れた場合や使用済みの場合は、下の手順で再発行してください。')); return; }
        wrbWebRespond(303, 'text/plain; charset=utf-8', '', ['Location: /', 'Set-Cookie: wrb_recovery_session=' . $session . '; Path=/; HttpOnly; SameSite=Strict']);
        return;
    }
    $session = $auth->session($cookie);
    if ($method === 'GET' && $path === '/') {
        if ($session === null) { wrbWebPage(200, wrbWebActivation()); return; }
        wrbWebPage(200, RestorePage::render(['endpoint' => '/api', 'tokenName' => 'csrf', 'token' => $session['csrf'], 'reauth' => false, 'prefix' => 'wp_']));
        return;
    }
    if ($method === 'POST' && $path === '/api') {
        $json = static function (int $code, array $body): void { wrbWebRespond($code, 'application/json; charset=utf-8', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); };
        if ($session === null) { $json(401, ['error' => '権限がありません。端末で issue を実行して有効化してください。']); return; }
        if (!hash_equals($session['csrf'], is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '')) { $json(403, ['error' => '操作の確認に失敗しました。画面を開き直してください。']); return; }
        $command = is_string($_POST['command'] ?? null) ? $_POST['command'] : '';
        try {
            $service = new RestoreService(wrbWebEnvironment($state, true));
            if (in_array($command, RestoreApi::START_COMMANDS, true)) {
                if (!$session['canStart']) { $json(403, ['error' => '開始の権限が期限切れか使用済みです。端末で issue を実行して有効化してください。進捗の確認と停止は続けられます。']); return; }
            } else {
                $status = $service->status();
                $finished = in_array($status['state'] ?? '', ['queued', 'running', 'none'], true) ? null : ($status['updatedAt'] ?? null);
                if (!$auth->canObserve($session, $service->isActive($status), $finished)) { $json(401, ['error' => '権限が失効しました。端末で issue を実行して有効化してください。']); return; }
            }
            [$code, $body] = RestoreApi::handle($service, $command, $_POST, $session['actor']);
            if (in_array($command, RestoreApi::START_COMMANDS, true) || !in_array($command, ['status', 'context', 'snapshots'], true)) { $auth->touch(); }
            if ($command === 'start' && $code === 200) { $auth->consumeStart(); }
            $json($code, $body);
        } catch (RestoreError $error) { $json(400, ['error' => $error->getMessage(), 'field' => $error->field()]); }
        catch (Throwable $error) { $json(500, ['error' => '処理を完了できませんでした。']); }
        return;
    }
    wrbWebRespond(404, 'text/plain; charset=utf-8', '見つかりません。');
}

function wrbWebActivation(string $error = ''): string
{
    $escape = static function (string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $command = 'php recover-web.php issue --state-dir <起動時に指定した状態フォルダー>';
    $minutes = (int) (RestoreWebAuth::TOKEN_LIFETIME / 60);
    return '<div class="wrb-restore"><h1>独立復元画面の有効化</h1>'
        . ($error !== '' ? '<p class="wrb-r-note wrb-r-bad" role="alert">' . $escape($error) . '</p>' : '')
        . '<form method="post" action="/activate"><p class="wrb-r-field"><label for="wrb-code">有効化コード</label>'
        . '<span id="wrb-code-help" class="wrb-r-help">サーバーの起動時または再発行時に表示されたコードを入力してください。発行から' . $minutes . '分間、1回限り使えます。</span>'
        . '<input id="wrb-code" name="code" type="password" autocomplete="off" aria-describedby="wrb-code-help" required></p><button class="wrb-r-button wrb-r-primary" type="submit">有効化</button></form>'
        . '<section aria-labelledby="wrb-code-location"><h2 id="wrb-code-location">コードの確認・再発行（サーバー管理者向け）</h2>'
        . '<p>復元サービスを起動した端末の「有効化コード」を確認します。起動出力をファイルに保存する構成では、管理者がサーバー上で指定した非公開ログを開いてください。</p>'
        . '<p>状態フォルダーの <code>web/token.json</code> には認証用の照合値だけがあり、入力できるコードは取り出せません。</p>'
        . '<p>コードが見つからない場合は、復元サービスを動かしているサーバーまたはコンテナで、状態フォルダーの所有者として復旧キットのあるフォルダーから次を実行してください。</p>'
        . '<pre><code>' . $escape($command) . '</code></pre><p>端末に表示された新しいコードを入力してください。</p></section></div>';
}

function wrbWebPage(int $code, string $content): void
{
    wrbWebRespond($code, 'text/html; charset=utf-8', '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>独立復元画面</title><link rel="stylesheet" href="/assets/restore.css"></head><body><main>' . $content . '</main><script src="/assets/restore.js"></script></body></html>');
}
