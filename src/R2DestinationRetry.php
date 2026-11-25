<?php
declare(strict_types=1);

namespace WordPressR2Backup;

/** Private, monotonic retry deadline for one R2 bucket in this WordPress workspace. */
final class R2DestinationRetry
{
    private string $path;

    public function __construct(string $workspace, string $account, string $bucket)
    {
        $this->path = FileSafety::privateDirectory($workspace) . '/r2-retry-' . hash('sha256', $account . '/' . $bucket);
    }

    public function assertAvailable(int $now): void
    {
        $deadline = $this->read();
        if ($deadline === null) {
            $lockPath = $this->path . '.lock';
            clearstatcache(true, $lockPath);
            if (@lstat($lockPath) !== false) {
                // A partial first write can leave an idle lock. Recheck after acquiring the same inode.
                $lock = $this->openLock($lockPath, false);
                try { $deadline = $this->read(); }
                finally { fclose($lock); }
            }
        }
        if ($deadline !== null && $deadline > $now) { throw ScheduledStorageLimit::storageWait($deadline); }
    }

    public function record(int $deadline): void
    {
        $lock = $this->openLock($this->path . '.lock', true);
        try {
            if (($this->read() ?? 0) >= $deadline) { return; }
            $pending = $this->path . '-' . bin2hex(random_bytes(12)) . '.tmp';
            $mask = umask(0077);
            try { $stream = @fopen($pending, 'xbe'); }
            finally { umask($mask); }
            if (!is_resource($stream)) { throw ScheduledStorageLimit::storageWait(); }
            try {
                $body = (string) $deadline . "\n";
                try {
                    if (fwrite($stream, $body) !== strlen($body) || !FileSafety::sync($stream)) { throw ScheduledStorageLimit::storageWait(); }
                } finally { fclose($stream); }
                if (!rename($pending, $this->path)) { throw ScheduledStorageLimit::storageWait(); }
            } finally { if (is_file($pending)) { @unlink($pending); } }
        } finally { fclose($lock); }
    }

    private function openLock(string $path, bool $create)
    {
        clearstatcache(true, $path);
        $previous = @lstat($path);
        if ($previous !== false && !self::ownerFile($previous)) { throw ScheduledStorageLimit::storageWait(); }
        if ($create) {
            $mask = umask(0077);
            try { $lock = @fopen($path, 'c+be'); }
            finally { umask($mask); }
        } else { $lock = @fopen($path, 'r+be'); }
        if (!is_resource($lock)) { throw ScheduledStorageLimit::storageWait(); }
        try {
            if (!flock($lock, LOCK_EX | ($create ? 0 : LOCK_NB))) { throw ScheduledStorageLimit::storageWait(); }
            clearstatcache(true, $path);
            $current = @lstat($path); $open = fstat($lock);
            if (!self::ownerFile($current) || !self::ownerFile($open) || $current['ino'] !== $open['ino']
                || $current['dev'] !== $open['dev']) { throw ScheduledStorageLimit::storageWait(); }
            return $lock;
        } catch (\Throwable $error) { fclose($lock); throw $error; }
    }

    private function read(): ?int
    {
        clearstatcache(true, $this->path);
        $stat = @lstat($this->path);
        if ($stat === false) { return null; }
        if (!self::ownerFile($stat) || $stat['size'] > strlen((string) PHP_INT_MAX) + 1) { throw ScheduledStorageLimit::storageWait(); }
        // A writer replaces the complete owner-only file atomically. The old handle is also a complete record.
        $stream = @fopen($this->path, 'rbe');
        if (!is_resource($stream)) { throw ScheduledStorageLimit::storageWait(); }
        try {
            if (!self::ownerFile(fstat($stream))) { throw ScheduledStorageLimit::storageWait(); }
            $body = stream_get_contents($stream, strlen((string) PHP_INT_MAX) + 2);
        } finally { fclose($stream); }
        $value = is_string($body) && preg_match('/\A[1-9][0-9]*\n\z/D', $body) ? filter_var(substr($body, 0, -1), FILTER_VALIDATE_INT) : false;
        if (!is_int($value)) { throw ScheduledStorageLimit::storageWait(); }
        return $value;
    }

    private static function ownerFile($stat): bool
    {
        return is_array($stat) && ($stat['mode'] & 0177777) === 0100600 && $stat['nlink'] === 1
            && (!function_exists('posix_geteuid') || $stat['uid'] === posix_geteuid());
    }
}
