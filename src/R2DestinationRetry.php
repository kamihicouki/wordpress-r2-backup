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
        // An interrupted first write may have published the lock but not the deadline.
        if ($deadline === null && @lstat($this->path . '.lock') !== false) {
            $deadline = $this->read();
            if ($deadline === null) { throw ScheduledStorageLimit::storageWait(); }
        }
        if ($deadline !== null && $deadline > $now) { throw ScheduledStorageLimit::storageWait($deadline); }
    }

    public function record(int $deadline): void
    {
        $lockPath = $this->path . '.lock';
        $previous = @lstat($lockPath);
        if ($previous !== false && !self::ownerFile($previous)) { throw ScheduledStorageLimit::storageWait(); }
        $mask = umask(0077);
        try { $lock = @fopen($lockPath, 'c+be'); }
        finally { umask($mask); }
        if (!is_resource($lock)) { throw ScheduledStorageLimit::storageWait(); }
        try {
            clearstatcache(true, $lockPath);
            $current = @lstat($lockPath); $open = fstat($lock);
            if (!self::ownerFile($current) || !self::ownerFile($open) || $current['ino'] !== $open['ino']
                || $current['dev'] !== $open['dev'] || !flock($lock, LOCK_EX)) { throw ScheduledStorageLimit::storageWait(); }
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
