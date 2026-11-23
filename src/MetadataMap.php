<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

final class MetadataMap
{
    private const MARKER = "wordpress-r2-backup metadata map v1\n";
    /** Owner of a map that is never reopened. It differs from MARKER, which is the only owner open() accepts. */
    private const PROCESS_LOCAL_MARKER = "wordpress-r2-backup process-local metadata map v1\n";
    private const BRANCH = "wordpress-r2-backup metadata branch v1\n";
    private const PREFIX_LENGTH = 3;
    private string $directory;
    private int $maximumBytes;
    private int $maximumRecords;
    private bool $durable;
    // Only one decoded leaf and one bounded registry. Every use still reads all current bytes.
    private const CACHE_RAW_BYTES = 262144;
    private int $cachedLeafMaximumBytes = 0;
    private string $cachedLeafPrefix = '';
    private string $cachedLeafBytes = '';
    private array $cachedLeafRecords = [];
    /** Fully validated byte digests for at most the 4096 root leaves of a process-local map. */
    private array $verifiedLeafDigests = [];
    private string $cachedBitmapBytes = '';
    private string $cachedBitmapBits = '';

    private function __construct(string $directory, int $maximumBytes, int $maximumRecords = PHP_INT_MAX, bool $durable = true)
    {
        $this->durable = $durable;
        $this->directory = FileSafety::privateDirectory($directory);
        if ($maximumBytes < 512 || $maximumBytes > 4194304) { throw new RuntimeException('Invalid metadata partition limit.'); }
        if ($maximumRecords < 1) { throw new RuntimeException('Invalid metadata partition record limit.'); }
        $this->maximumBytes = $maximumBytes;
        $this->maximumRecords = $maximumRecords;
    }

    public static function create(string $newDirectory, int $maximumBytes = 4194304, int $maximumRecords = PHP_INT_MAX): self
    {
        $map = self::createDirectory($newDirectory, $maximumBytes, $maximumRecords, true);
        $map->write('limit', (string) $maximumBytes);
        $map->saveBitmap(str_repeat("\0", 512));
        $map->write('owner', self::MARKER);
        return $map;
    }

    /**
     * A map used only by the process that creates it. Its writes are not flushed to stable storage, so after a crash or
     * power loss its files may be missing, torn, or older than what was written. Its owner is a different marker that
     * open() never accepts, and that marker is the first file written, so no state of the directory can be opened again.
     */
    public static function createProcessLocal(string $newDirectory, int $maximumBytes = 4194304, int $maximumRecords = PHP_INT_MAX): self
    {
        $map = self::createDirectory($newDirectory, $maximumBytes, $maximumRecords, false);
        $map->write('owner', self::PROCESS_LOCAL_MARKER);
        $map->write('limit', (string) $maximumBytes);
        $map->saveBitmap(str_repeat("\0", 512));
        return $map;
    }

    private static function createDirectory(string $newDirectory, int $maximumBytes, int $maximumRecords, bool $durable): self
    {
        FileSafety::assertNoLinks($newDirectory);
        FileSafety::privateDirectory(dirname($newDirectory));
        if (@lstat($newDirectory) !== false || !mkdir($newDirectory, 0700)) { throw new RuntimeException('Metadata map requires a new private directory.'); }
        return new self($newDirectory, $maximumBytes, $maximumRecords, $durable);
    }

    public static function open(string $directory): self
    {
        FileSafety::privateDirectory($directory);
        $marker = self::readIn($directory, 'owner', 128);
        $limit = self::readIn($directory, 'limit', 16);
        if ($marker !== self::MARKER || !preg_match('/\A[1-9][0-9]*\z/D', $limit)) { throw new RuntimeException('Invalid metadata map identity.'); }
        $map = new self($directory, (int) $limit);
        $map->bitmap();
        return $map;
    }

    private function invalidateLeafCache(): void
    {
        $this->cachedLeafMaximumBytes = 0; $this->cachedLeafPrefix = ''; $this->cachedLeafBytes = ''; $this->cachedLeafRecords = [];
    }

    private function invalidateCache(): void
    {
        $this->invalidateLeafCache();
        $this->verifiedLeafDigests = [];
        $this->cachedBitmapBytes = ''; $this->cachedBitmapBits = '';
    }

    /** Reports whether the on-disk partition exists even when the key has no stored record. */
    public function get(string $key, ?bool &$partitionInitialized = null): ?array
    {
        try { return $this->verifiedGet($key, $partitionInitialized); }
        catch (\Throwable $error) { $this->invalidateCache(); throw $error; }
    }

    public function put(string $key, array $value, bool $replace = false): void
    {
        try { $this->verifiedPut($key, $value, $replace); }
        catch (\Throwable $error) { $this->invalidateCache(); throw $error; }
    }

    /** Inserts new records by leaf in a process-local map. Values are encoded by the bounded capture buffer. */
    public function putProcessLocalBatch(array $values, ?callable $heartbeat = null): void
    {
        if ($this->durable) { throw new RuntimeException('Metadata batches require a process-local map.'); }
        try {
            $buckets = [];
            foreach ($values as $key => $ignored) {
                $hash = self::keyHash($key);
                $prefix = substr($hash, 0, self::PREFIX_LENGTH);
                if (isset($buckets[$prefix][$hash])) { throw new RuntimeException('Metadata identity collision.'); }
                $buckets[$prefix][$hash] = $key;
            }
            $limit = min(262144, $this->maximumBytes - 256);
            $nextHeartbeat = hrtime(true) + 1000000000;
            foreach ($buckets as $prefix => $keys) {
                $prefix = (string) $prefix;
                $this->guard();
                $bits = $this->bitmap();
                $initialized = self::marked($bits, $prefix);
                $leaves = [$prefix => $keys];
                if ($initialized && $this->isBranch($prefix)) {
                    $leaves = [];
                    foreach ($keys as $hash => $key) { $leaves[$this->partition($hash)][$hash] = $key; }
                }
                foreach ($leaves as $leaf => $leafKeys) {
                    $leaf = (string) $leaf;
                    $records = $initialized ? $this->leaf($leaf) : [];
                    $changed = false;
                    foreach ($leafKeys as $hash => $key) {
                        $json = $values[$key];
                        if (!is_string($json) || strlen($json) > $limit) { throw new RuntimeException('Metadata value exceeds its limit.'); }
                        $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
                        if (!is_array($value)) { throw new RuntimeException('Invalid metadata value.'); }
                        if (isset($records[$hash])) {
                            if ($records[$hash]['key'] !== $key) { throw new RuntimeException('Metadata identity collision.'); }
                            if ($records[$hash]['value'] !== $value) { throw new RuntimeException('Metadata key is already assigned.'); }
                            continue;
                        }
                        $records[$hash] = ['key' => $key, 'value' => $value];
                        $changed = true;
                    }
                    if ($changed) { $this->persist($leaf, $records); }
                }
                if (!$initialized) { $this->markPartition($bits, $prefix); }
                if ($heartbeat !== null && hrtime(true) >= $nextHeartbeat) {
                    $heartbeat();
                    $nextHeartbeat = hrtime(true) + 1000000000;
                }
            }
        } catch (\Throwable $error) { $this->invalidateCache(); throw $error; }
    }

    public function entries(): \Generator
    {
        try { yield from $this->verifiedEntries(); }
        catch (\Throwable $error) { $this->invalidateCache(); throw $error; }
    }
    private function verifiedGet(string $key, ?bool &$partitionInitialized): ?array
    {
        $hash = self::keyHash($key);
        $this->guard();
        $partitionInitialized = self::marked($this->bitmap(), $hash);
        if (!$partitionInitialized) { return null; }
        $records = $this->leaf($this->partition($hash), !$this->durable);
        if (!isset($records[$hash])) { return null; }
        if ($records[$hash]['key'] !== $key) { throw new RuntimeException('Metadata identity collision.'); }
        return $records[$hash]['value'];
    }

    private function verifiedPut(string $key, array $value, bool $replace = false): void
    {
        $hash = self::keyHash($key);
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > min(262144, $this->maximumBytes - 256)) { throw new RuntimeException('Metadata value exceeds its limit.'); }
        $this->guard();
        $bits = $this->bitmap();
        $initialized = self::marked($bits, $hash);
        $prefix = $initialized ? $this->partition($hash) : substr($hash, 0, self::PREFIX_LENGTH);
        $records = $initialized ? $this->leaf($prefix) : [];
        if (isset($records[$hash])) {
            if ($records[$hash]['key'] !== $key) { throw new RuntimeException('Metadata identity collision.'); }
            if (!$replace && $records[$hash]['value'] !== $value) { throw new RuntimeException('Metadata key is already assigned.'); }
            if ($records[$hash]['value'] === $value) { return; }
        }
        $records[$hash] = ['key' => $key, 'value' => $value];
        $this->persist($prefix, $records);
        if (!$initialized) { $this->markPartition($bits, $hash); }
    }

    private function verifiedEntries(): \Generator
    {
        $this->guard();
        $bits = $this->bitmap();
        for ($index = 0; $index < 4096; $index++) {
            if ((ord($bits[intdiv($index, 8)]) & (1 << ($index % 8))) !== 0) { yield from $this->walk(str_pad(dechex($index), self::PREFIX_LENGTH, '0', STR_PAD_LEFT)); }
        }
    }

    private function walk(string $prefix): \Generator
    {
        $this->guard();
        if ($this->isBranch($prefix)) {
            foreach (str_split('0123456789abcdef') as $digit) { yield from $this->walk($prefix . $digit); }
            return;
        }
        foreach ($this->leaf($prefix) as $record) { yield $record['key'] => $record['value']; }
    }

    private function partition(string $hash): string
    {
        $prefix = substr($hash, 0, self::PREFIX_LENGTH);
        while ($this->isBranch($prefix)) {
            if (strlen($prefix) >= 64) { throw new RuntimeException('Metadata partition depth exceeded.'); }
            $prefix .= $hash[strlen($prefix)];
        }
        return $prefix;
    }

    private function isBranch(string $prefix): bool
    {
        $path = $this->directory . '/branch-' . $prefix;
        if (@lstat($path) === false) { return false; }
        if (self::readIn($this->directory, 'branch-' . $prefix, 128) !== self::BRANCH) { throw new RuntimeException('Invalid metadata branch.'); }
        return true;
    }

    private function leaf(string $prefix, bool $reuseVerifiedDigest = false): array
    {
        $contents = self::readIn($this->directory, 'leaf-' . $prefix . '.json', $this->maximumBytes);
        if ($this->cachedLeafPrefix === $prefix && $this->cachedLeafMaximumBytes === $this->maximumBytes && $this->cachedLeafBytes === $contents) { return $this->cachedLeafRecords; }
        $data = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        $digest = null;
        if ($reuseVerifiedDigest && strlen($prefix) === self::PREFIX_LENGTH && strlen($contents) <= self::CACHE_RAW_BYTES) {
            // OpenSSL can use the host's SHA-256 instructions on these larger buffers.
            $digest = function_exists('openssl_digest') ? openssl_digest($contents, 'sha256', true) : false;
            if ($digest === false) { $digest = hash('sha256', $contents, true); }
            // Only a digest recorded after full validation can authorize reuse of these freshly read bytes.
            if (($this->verifiedLeafDigests[$prefix] ?? null) === $digest) {
                $this->cacheLeaf($prefix, $contents, $data['records']);
                return $data['records'];
            }
        }
        if (!is_array($data) || array_keys($data) !== ['version', 'records', 'checksum'] || $data['version'] !== 1 || !is_array($data['records'])
            || !is_string($data['checksum']) || !hash_equals($data['checksum'], hash('sha256', self::encodeRecords($data['records'])))) { throw new RuntimeException('Invalid metadata partition.'); }
        foreach ($data['records'] as $hash => $record) {
            if (!is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/D', $hash) || strpos($hash, $prefix) !== 0
                || !is_array($record) || array_keys($record) !== ['key', 'value'] || !is_string($record['key']) || !is_array($record['value'])
                || self::keyHash($record['key']) !== $hash) { throw new RuntimeException('Invalid metadata record.'); }
        }
        $this->cacheLeaf($prefix, $contents, $data['records']);
        if ($digest !== null) { $this->verifiedLeafDigests[$prefix] = $digest; }
        return $data['records'];
    }

    private function cacheLeaf(string $prefix, string $bytes, array $records): void
    {
        if (strlen($bytes) > min($this->maximumBytes, self::CACHE_RAW_BYTES)) { $this->invalidateCache(); return; }
        $this->cachedLeafMaximumBytes = $this->maximumBytes;
        $this->cachedLeafPrefix = $prefix; $this->cachedLeafBytes = $bytes; $this->cachedLeafRecords = $records;
    }

    private function persist(string $prefix, array $records): void
    {
        $encoded = self::encodeRecords($records); // same bytes as encoding the whole document, without encoding the records twice
        $json = '{"version":1,"records":' . $encoded . ',"checksum":"' . hash('sha256', $encoded) . '"}';
        if (strlen($json) <= $this->maximumBytes && count($records) <= $this->maximumRecords && !$this->isBranch($prefix)) { $this->write('leaf-' . $prefix . '.json', $json); $this->invalidateLeafCache(); return; }
        if (strlen($prefix) >= 64) { throw new RuntimeException('Metadata partition cannot be split.'); }
        $this->invalidateCache();
        $groups = array_fill_keys(str_split('0123456789abcdef'), []);
        foreach ($records as $hash => $record) { $groups[$hash[strlen($prefix)]][$hash] = $record; }
        foreach ($groups as $digit => $group) { $this->persist($prefix . $digit, $group); }
        $this->write('branch-' . $prefix, self::BRANCH);
        $path = $this->directory . '/leaf-' . $prefix . '.json';
        clearstatcache(true, $path); if (@lstat($path) !== false) { self::assertExisting($path, $this->maximumBytes); if (!unlink($path)) { throw new RuntimeException('Cannot retire metadata partition.'); } }
        $this->invalidateCache();
    }

    private function write(string $name, string $contents): void
    {
        $path = $this->directory . '/' . $name;
        clearstatcache(true, $path); if (@lstat($path) !== false) { self::assertExisting($path, max($this->maximumBytes, 1024)); }
        $pending = $this->directory . '/pending-' . bin2hex(random_bytes(16));
        $stream = fopen($pending, 'xb');
        if ($stream === false) { throw new RuntimeException('Cannot create metadata update.'); }
        try {
            // A process-local map is never opened again, so it skips only the durability flush; every later read is still checked.
            if (!chmod($pending, 0600) || fwrite($stream, $contents) !== strlen($contents) || !fflush($stream) || ($this->durable && !FileSafety::sync($stream))) { throw new RuntimeException('Cannot persist metadata update.'); }
        } finally { $closed = fclose($stream); }
        if (!$closed) { @unlink($pending); throw new RuntimeException('Cannot persist metadata update.'); }
        if (!rename($pending, $path)) { @unlink($pending); throw new RuntimeException('Cannot publish metadata update.'); }
    }

    /** Same checks as before for one file; the directory ancestors are checked once per public call by guard(). */
    private static function assertExisting(string $path, int $limit): array
    {
        clearstatcache(true, $path); // another process may have changed this exact file since a cached lstat
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || !is_readable($path)) { throw new RuntimeException('Expected a readable regular file.'); }
        if (($stat['mode'] & 0777) !== 0600 || $stat['size'] > $limit) { throw new RuntimeException('Unsafe metadata file.'); }
        return $stat;
    }

    /** Opens the file named by lstat and reads that same inode, so a swap after the checks cannot change what is read. */
    private static function readIn(string $directory, string $name, int $limit): string
    {
        $path = $directory . '/' . $name;
        $stat = self::assertExisting($path, $limit);
        $stream = @fopen($path, 'rb');
        if ($stream === false) { throw new RuntimeException('Cannot read metadata file.'); }
        try {
            $opened = fstat($stream);
            if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino'] || $opened['size'] > $limit) { throw new RuntimeException('Unsafe metadata file.'); }
            $contents = stream_get_contents($stream, $limit + 1);
        } finally { fclose($stream); }
        if (!is_string($contents) || strlen($contents) > $limit) { throw new RuntimeException('Cannot read metadata file.'); }
        return $contents;
    }

    private function guard(): void { FileSafety::assertNoLinks($this->directory); }

    private static function keyHash(string $key): string
    {
        if ($key === '' || strlen($key) > 8192 || strpos($key, "\0") !== false || !mb_check_encoding($key, 'UTF-8')) { throw new RuntimeException('Invalid metadata key.'); }
        return hash('sha256', $key);
    }

    private static function encodeRecords(array $records): string
    {
        return json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function marked(string $bits, string $hash): bool
    {
        $index = hexdec(substr($hash, 0, self::PREFIX_LENGTH));
        return (ord($bits[intdiv($index, 8)]) & (1 << ($index % 8))) !== 0;
    }

    private function markPartition(string $bits, string $hash): void
    {
        $index = hexdec(substr($hash, 0, self::PREFIX_LENGTH));
        $offset = intdiv($index, 8);
        $bits[$offset] = chr(ord($bits[$offset]) | (1 << ($index % 8)));
        $this->saveBitmap($bits);
    }

    private function bitmap(): string
    {
        $contents = self::readIn($this->directory, 'partitions.json', 1024);
        if ($contents === $this->cachedBitmapBytes && $this->cachedBitmapBits !== '') { return $this->cachedBitmapBits; }
        $data = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_keys($data) !== ['bits', 'checksum'] || !is_string($data['bits']) || !is_string($data['checksum'])) { throw new RuntimeException('Invalid metadata partition registry.'); }
        $bits = base64_decode($data['bits'], true);
        if (!is_string($bits) || strlen($bits) !== 512 || !hash_equals($data['checksum'], hash('sha256', $bits))) { throw new RuntimeException('Corrupt metadata partition registry.'); }
        $this->cachedBitmapBytes = $contents; $this->cachedBitmapBits = $bits;
        return $bits;
    }

    private function saveBitmap(string $bits): void
    {
        $contents = json_encode(['bits' => base64_encode($bits), 'checksum' => hash('sha256', $bits)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->write('partitions.json', $contents);
        $this->cachedBitmapBytes = $contents; $this->cachedBitmapBits = $bits;
    }
}
