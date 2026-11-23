<?php
declare(strict_types=1);
namespace WordPressR2Backup;

/** Launch a detached CLI worker without credentials or launch secrets in argv. */
final class LiveRunner
{
    private LiveBackup $backup;
    private $launcher;

    public function __construct(array $settings, ?callable $launcher = null)
    {
        $this->backup = new LiveBackup($settings);
        $this->launcher = $launcher;
    }

    public function start(): array { return $this->launch(false); }
    public function startOnline(?array $schedule = null): array { return $this->launch(false, 'online', $schedule); }
    public function resume(): array { return $this->launch(true); }
    public function resumeScheduled(array $schedule, string $operationId): ?array
    {
        $schedule['operationId'] = $operationId;
        return $this->launch(true, 'maintenance', $schedule);
    }

    public function state(): ?array { return $this->backup->state(); }

    private function launch(bool $resume, string $mode = 'maintenance', ?array $schedule = null): ?array
    {
        $workspace = $this->backup->workspace(); $lock = self::runnerLock($workspace, true, $resume && $schedule !== null);
        if ($lock === null) { return null; }
        $launchId = bin2hex(random_bytes(32)); $token = bin2hex(random_bytes(32)); $ticket = null; $queued = false;
        try {
            $automatic = $resume && $schedule !== null;
            $php = $automatic ? null : self::phpBinary();
            $state = $this->backup->queue($launchId, $resume, $mode, $schedule);
            if ($state === null) { return null; }
            $queued = true;
            // After reserving an automatic retry, a missing CLI must also retire that attempt through launchFailed().
            if ($php === null) { $php = self::phpBinary(); }
            $root = realpath(rtrim(ABSPATH, '/'));
            if ($root === false) { throw new \RuntimeException('WordPressの保存場所を検証できません。'); }
            FileSafety::assertRegularFile($root . '/wp-load.php');
            $ticket = $workspace . '/launch-' . $launchId . '.json';
            $body = json_encode(['version' => 2, 'launchId' => $launchId, 'workspace' => $workspace, 'root' => $root, 'tokenHash' => hash('sha256', $token),
                'action' => $resume ? 'resume' : 'start', 'mode' => $state['consistencyMode'] ?? 'maintenance',
                'settingsDigest' => $state['settingsDigest'] ?? null, 'sharingDigest' => $state['sharingDigest'] ?? null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $file = @fopen($ticket, 'xb');
            if ($file === false) { throw new \RuntimeException('起動情報を保存できません。'); }
            try { if (!chmod($ticket, 0600) || fwrite($file, $body) !== strlen($body) || !FileSafety::sync($file)) { throw new \RuntimeException('起動情報を保存できません。'); } }
            finally { fclose($file); }
            $environment = getenv(); if (!is_array($environment)) { $environment = []; }
            $environment['WRB_LAUNCH_TOKEN'] = $token;
            $log = $workspace . '/worker.log'; FileSafety::assertNoLinks($log);
            $stream = fopen($log, 'ab'); if ($stream === false || !chmod($log, 0600)) { if (is_resource($stream)) { fclose($stream); } throw new \RuntimeException('実行記録を作成できません。'); } fclose($stream);
            if ($this->launcher !== null) { ($this->launcher)($php, $ticket, $environment, $log); }
            else { self::detach($php, $ticket, $environment, $log); }
            return $state;
        } catch (\Throwable $error) {
            if ($ticket !== null) { @unlink($ticket); }
            if ($queued) { $this->backup->launchFailed($launchId); }
            throw $error;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function phpBinary(): string
    {
        $configured = defined('WRB_PHP_CLI') ? constant('WRB_PHP_CLI') : getenv('WRB_PHP_CLI');
        $candidates = is_string($configured) && $configured !== '' ? [$configured] : [PHP_BINARY, dirname(PHP_BINARY) . '/php', PHP_BINDIR . '/php'];
        foreach (array_unique($candidates) as $candidate) {
            $real = realpath($candidate);
            if ($real === false || !is_file($real) || !is_executable($real)) { continue; }
            $process = @proc_open([$real, '-r', 'exit(PHP_SAPI === "cli" && PHP_VERSION_ID >= 70400 ? 0 : 1);'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (is_resource($process) && proc_close($process) === 0) { return $real; }
        }
        throw new \RuntimeException('PHP CLIを利用できません。WRB_PHP_CLIへPHP 7.4以降のCLI実行ファイルを設定してください。');
    }

    private static function detach(string $php, string $ticket, array $environment, string $log): void
    {
        if (DIRECTORY_SEPARATOR !== '/') { throw new \RuntimeException('この環境ではバックグラウンド起動を利用できません。'); }
        $command = escapeshellarg($php) . ' ' . escapeshellarg(dirname(__DIR__) . '/worker.php') . ' ' . escapeshellarg($ticket)
            . ' >' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
        $process = @proc_open(['/bin/sh', '-c', $command], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        if (!is_resource($process)) { throw new \RuntimeException('バックグラウンド処理を起動できません。'); }
        $pid = trim((string) stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || !preg_match('/\A[1-9][0-9]*\z/D', $pid)) { throw new \RuntimeException('バックグラウンド処理を起動できません。'); }
    }

    /** Called before wp-load.php: validates CLI, private ticket, and out-of-band token. */
    public static function validateLaunch(string $ticket, string $token): array
    {
        if (PHP_SAPI !== 'cli' || !preg_match('/\A[a-f0-9]{64}\z/D', $token)) { throw new \RuntimeException('起動の認証情報を検証できません。'); }
        FileSafety::assertRegularFile($ticket);
        if (realpath($ticket) !== $ticket || (fileperms($ticket) & 0777) !== 0600 || filesize($ticket) > 4096) { throw new \RuntimeException('起動情報を検証できません。'); }
        $data = json_decode((string) file_get_contents($ticket), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !in_array($data['version'] ?? null, [1, 2], true) || !is_string($data['launchId'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $data['launchId'])
            || !is_string($data['tokenHash'] ?? null) || !hash_equals($data['tokenHash'], hash('sha256', $token))
            || !is_string($data['workspace'] ?? null) || $ticket !== $data['workspace'] . '/launch-' . $data['launchId'] . '.json'
            || !is_string($data['root'] ?? null) || realpath($data['root']) !== $data['root']) { throw new \RuntimeException('起動情報を検証できません。'); }
        if ($data['version'] === 2) {
            $keys = array_keys($data); sort($keys);
            $expected = ['action', 'launchId', 'mode', 'root', 'settingsDigest', 'sharingDigest', 'tokenHash', 'version', 'workspace'];
            $settings = $data['settingsDigest'] ?? null; $sharing = $data['sharingDigest'] ?? null;
            $bound = is_string($settings) && preg_match('/\A[a-f0-9]{64}\z/D', $settings) && is_string($sharing) && preg_match('/\A[a-f0-9]{64}\z/D', $sharing);
            $legacyResume = ($data['action'] ?? null) === 'resume' && ($data['mode'] ?? null) === 'maintenance' && $settings === null && $sharing === null;
            if ($keys !== $expected || !in_array($data['action'] ?? null, ['start', 'resume'], true) || !in_array($data['mode'] ?? null, ['maintenance', 'online'], true)
                || (!$bound && !$legacyResume)) { throw new \RuntimeException('起動情報の取得設定を検証できません。'); }
        }
        FileSafety::privateDirectory($data['workspace']); FileSafety::assertRegularFile($data['root'] . '/wp-load.php');
        return $data;
    }

    public static function work(string $ticket, string $token): void
    {
        $data = self::validateLaunch($ticket, $token);
        $lock = self::runnerLock($data['workspace'], false);
        try {
            // Re-read after acquiring the lock; each authenticated ticket is one-use.
            $data = self::validateLaunch($ticket, $token);
            if (!unlink($ticket)) { throw new \RuntimeException('起動情報を消費できません。'); }
            unset($_ENV['WRB_LAUNCH_TOKEN'], $_SERVER['WRB_LAUNCH_TOKEN']); putenv('WRB_LAUNCH_TOKEN');
            require $data['root'] . '/wp-load.php';
            if (LiveSettings::workspace() !== $data['workspace']) { throw new \RuntimeException('作業領域の所有者が一致しません。'); }
            $settings = LiveSettings::load();
            if ($settings === null) { throw new \RuntimeException('バックアップ設定がありません。'); }
            $backup = new LiveBackup($settings);
            try { $backup->runQueued($data['launchId'], $data); }
            catch (\Throwable $error) { $backup->launchFailed($data['launchId']); throw $error; }
        } catch (\Throwable $error) {
            $path = $data['workspace'] . '/runner-failure-' . $data['launchId'] . '.json';
            FileSafety::assertNoLinks($path);
            $file = @fopen($path, 'xb');
            if ($file !== false) {
                try { if (chmod($path, 0600)) { fwrite($file, json_encode(['launchId' => $data['launchId'], 'status' => 'failed', 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'exception' => get_class($error), 'where' => basename($error->getFile()) . ':' . $error->getLine()], JSON_THROW_ON_ERROR)); fflush($file); } }
                finally { fclose($file); }
            }
            throw $error;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Called only by the CLI dispatcher after WordPress has loaded under the same runner lock. */
    public static function workWithHeldLock(string $ticket, string $token, $lock): void
    {
        $data = self::validateLaunch($ticket, $token);
        if ($data['version'] !== 2 || !is_resource($lock)) { throw new \RuntimeException('起動情報を検証できません。'); }
        $path = $data['workspace'] . '/runner.lock'; clearstatcache(true, $path);
        $handle = fstat($lock); $file = @lstat($path);
        if (!is_array($handle) || !is_array($file) || ($file['mode'] & 0170000) !== 0100000 || $file['nlink'] !== 1
            || $handle['dev'] !== $file['dev'] || $handle['ino'] !== $file['ino'] || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('実行ロックを検証できません。');
        }
        $data = self::validateLaunch($ticket, $token);
        if ($data['version'] !== 2 || LiveSettings::workspace() !== $data['workspace']) { throw new \RuntimeException('作業領域を検証できません。'); }
        if (!unlink($ticket)) { throw new \RuntimeException('起動情報を消費できません。'); }
        $settings = LiveSettings::load();
        if ($settings === null) { throw new \RuntimeException('バックアップ設定がありません。'); }
        $backup = new LiveBackup($settings);
        try { $backup->runQueued($data['launchId'], $data); }
        catch (\Throwable $error) { $backup->launchFailed($data['launchId']); throw $error; }
    }

    private static function runnerLock(string $workspace, bool $nonblocking, bool $skipBusy = false)
    {
        FileSafety::privateDirectory($workspace); $path = $workspace . '/runner.lock'; FileSafety::assertNoLinks($path);
        $lock = @fopen($path, 'c+b');
        $wouldBlock = 0;
        if ($lock === false || !chmod($path, 0600) || !flock($lock, LOCK_EX | ($nonblocking ? LOCK_NB : 0), $wouldBlock)) {
            if (is_resource($lock)) { fclose($lock); }
            if ($skipBusy && $wouldBlock) { return null; }
            throw new \RuntimeException('バックグラウンド処理が実行中です。');
        }
        return $lock;
    }
}
