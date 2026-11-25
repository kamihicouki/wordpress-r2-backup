<?php
declare(strict_types=1);

// This entry point intentionally loads no plugin code before the WordPress release is selected.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

function wrbWorkerTicket(string $ticket, string $token): array
{
    $workspace = dirname($ticket); clearstatcache(true, $ticket);
    $file = @lstat($ticket); $dir = @lstat($workspace);
    if (!function_exists('posix_geteuid') || realpath($ticket) !== $ticket || realpath($workspace) !== $workspace
        || !is_array($file) || ($file['mode'] & 0170000) !== 0100000 || ($file['mode'] & 0777) !== 0600 || $file['nlink'] !== 1 || $file['size'] > 4096 || $file['uid'] !== posix_geteuid()
        || !is_array($dir) || ($dir['mode'] & 0170000) !== 0040000 || ($dir['mode'] & 0777) !== 0700 || $dir['uid'] !== posix_geteuid()) { throw new RuntimeException('Invalid ticket.'); }
    $data = json_decode((string) file_get_contents($ticket), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data)) { throw new RuntimeException('Invalid ticket.'); }
    $keys = array_keys($data); sort($keys);
    $legacy = $keys === ['launchId','root','tokenHash','version','workspace'] && ($data['version'] ?? null) === 1;
    if ((!$legacy && ($keys !== ['action','launchId','mode','root','settingsDigest','sharingDigest','tokenHash','version','workspace'] || ($data['version'] ?? null) !== 2))
        || !is_string($data['launchId']) || !preg_match('/\A[a-f0-9]{64}\z/D', $data['launchId']) || $data['workspace'] !== $workspace || $ticket !== $workspace . '/launch-' . $data['launchId'] . '.json'
        || !is_string($data['root']) || realpath($data['root']) !== $data['root'] || !is_file($data['root'].'/wp-load.php')
        || !is_string($data['tokenHash']) || !hash_equals(hash('sha256', $token), $data['tokenHash'])) { throw new RuntimeException('Invalid ticket.'); }
    if ($legacy) { return $data; }
    if (!in_array($data['action'], ['start','resume'], true) || !in_array($data['mode'], ['maintenance','online'], true)) { throw new RuntimeException('Invalid ticket.'); }
    $bound = is_string($data['settingsDigest']) && preg_match('/\A[a-f0-9]{64}\z/D', $data['settingsDigest']) && is_string($data['sharingDigest']) && preg_match('/\A[a-f0-9]{64}\z/D', $data['sharingDigest']);
    $legacyResume = $data['action'] === 'resume' && $data['mode'] === 'maintenance' && $data['settingsDigest'] === null && $data['sharingDigest'] === null;
    if (!$bound && !$legacyResume) { throw new RuntimeException('Invalid ticket.'); }
    return $data;
}

// The authorization token is deliberately absent from the included file's scope.
function wrbWorkerLoad(string $root): void { require $root . '/wp-load.php'; }

function wrbWorkerFailure(string $workspace, string $id, Throwable $error): void
{
    $target = $workspace.'/runner-failure-'.$id.'.json';
    $temp = $workspace.'/.runner-failure-'.bin2hex(random_bytes(16)).'.tmp';
    $file = @fopen($temp, 'xb');
    if (!is_resource($file)) { return; }
    try {
        // Messages and full paths may contain credentials or personal data. Keep only constrained metadata.
        $class = get_class($error); $fileName = basename($error->getFile()); $line = $error->getLine();
        $class = preg_match('~\A[A-Za-z_][A-Za-z0-9_\\\\]{0,159}\z~D', $class) ? $class : 'Throwable';
        $where = preg_match('/\A[A-Za-z0-9_.-]{1,180}\.php\z/D', $fileName) && $line > 0 ? $fileName . ':' . $line : 'unknown';
        $body = json_encode(['launchId'=>$id,'status'=>'failed','updatedAt'=>gmdate('Y-m-d\TH:i:s\Z'),'exception'=>$class,'where'=>$where], JSON_THROW_ON_ERROR);
        if (chmod($temp, 0600) && fwrite($file, $body) === strlen($body) && fflush($file)) { @link($temp, $target); }
    } finally { fclose($file); @unlink($temp); }
}

function wrbWorkerMain(array $arguments): void
{
    $token = getenv('WRB_LAUNCH_TOKEN');
    if (count($arguments) !== 2 || !is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D', $token)) { throw new RuntimeException('Invalid invocation.'); }
    $ticket = $arguments[1]; $data = wrbWorkerTicket($ticket, $token); $workspace = $data['workspace']; $path = $workspace . '/runner.lock';
    $before = @lstat($path);
    if ($before !== false && (($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1 || $before['uid'] !== posix_geteuid())) { throw new RuntimeException('Invalid lock.'); }
    // Subprocesses used during capture must not keep this worker's lock alive.
    $lock = @fopen($path, 'c+be');
    if (!is_resource($lock)) { throw new RuntimeException('Invalid lock.'); }
    $authorized = false;
    try {
        if (!chmod($path, 0600)) { throw new RuntimeException('Invalid lock.'); }
        $held = fstat($lock); $file = lstat($path);
        if (!is_array($held) || !is_array($file) || $held['dev'] !== $file['dev'] || $held['ino'] !== $file['ino'] || $file['nlink'] !== 1 || !flock($lock, LOCK_EX)) { throw new RuntimeException('Invalid lock.'); }
        clearstatcache(true, $path); $file = lstat($path);
        if (!is_array($file) || $held['dev'] !== $file['dev'] || $held['ino'] !== $file['ino']) { throw new RuntimeException('Invalid lock.'); }
        $data = wrbWorkerTicket($ticket, $token); $authorized = true;
        if (@lstat($workspace.'/runner-failure-'.$data['launchId'].'.json') !== false) { throw new RuntimeException('Launch already failed.'); }
        if ($data['version'] === 1) { throw new RuntimeException('Legacy launch is no longer supported.'); }
        unset($_ENV['WRB_LAUNCH_TOKEN'], $_SERVER['WRB_LAUNCH_TOKEN']); putenv('WRB_LAUNCH_TOKEN');
        wrbWorkerLoad($data['root']);
        global $wpdb;
        $raw = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='active_plugins'");
        $active = is_string($raw) ? @unserialize($raw, ['allowed_classes'=>false]) : false;
        if (!is_array($active)) { throw new RuntimeException('Active plugin unavailable.'); }
        $matched = [];
        foreach ($active as $entry) {
            if (is_string($entry) && ($entry === 'wordpress-r2-backup/wordpress-r2-backup.php' || preg_match('~\Awordpress-r2-backup/release-[A-Za-z0-9-]+\.php\z~D', $entry))) { $matched[] = $entry; }
        }
        if (count($matched) !== 1 || !class_exists('WordPressR2Backup\\LiveRunner')) { throw new RuntimeException('Active plugin mismatch.'); }
        $base = $data['root'].'/wp-content/plugins/wordpress-r2-backup';
        if ($matched[0] !== 'wordpress-r2-backup/wordpress-r2-backup.php') {
            preg_match('~\Awordpress-r2-backup/release-([A-Za-z0-9-]+)\.php\z~D', $matched[0], $version);
            $base .= '/releases/'.$version[1];
        }
        $expected = realpath($base.'/src/LiveRunner.php');
        $actual = (new ReflectionClass('WordPressR2Backup\\LiveRunner'))->getFileName();
        if ($expected === false || realpath((string) $actual) !== $expected) { throw new RuntimeException('Loaded plugin mismatch.'); }
        \WordPressR2Backup\LiveRunner::workWithHeldLock($ticket, $token, $lock);
    } catch (Throwable $error) {
        if ($authorized) {
            wrbWorkerFailure($workspace, $data['launchId'], $error);
        }
        throw $error;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

try { wrbWorkerMain($argv); }
catch (Throwable $error) { fwrite(STDERR, "バックアップ処理を完了できませんでした。管理画面の実行状態を確認してください。\n"); exit(1); }
