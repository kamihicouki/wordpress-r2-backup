<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

final class V4ContentIndex
{
    private string $directory;
    private array $scope;
    private string $scopeId;
    private array $header = [];
    private $repository;
    private bool $locked = false;
    private MetadataMap $packs;
    private MetadataMap $contents;
    private ?string $lastPackKey = null;
    private ?array $lastPackRecord = null;
    private array $lastPackMembers = [];

    private function __construct(string $directory, array $scope, $repository)
    {
        $this->directory = FileSafety::privateDirectory($directory);
        $this->scopeId = V4Format::scopeId($scope);
        $this->scope = [];
        foreach (['accountId', 'bucket', 'unitId', 'keySetId', 'encoding'] as $field) { $this->scope[$field] = $scope[$field]; }
        if (!is_object($repository) || !method_exists($repository, 'verify') || !method_exists($repository, 'listPackObjects')) {
            throw new RuntimeException('V4 index requires a pack repository.');
        }
        $this->repository = $repository;
    }

    public static function openOrCreate(string $privateDirectory, array $scope, $repository): self
    {
        $index = new self($privateDirectory, $scope, $repository);
        $empty = scandir($privateDirectory) === ['.', '..'];
        if (@lstat($privateDirectory . '/scope.json') !== false) { $index->load('ready'); }
        elseif (!$empty) { throw new RuntimeException('Index missing or directory is not empty. Rebuild the index or explicitly disable sharing.'); }
        $index->lock(function () use ($index, $empty): void {
            if (@lstat($index->directory . '/scope.json') === false) {
                if (!$empty || scandir($index->directory) !== ['.', '..', 'unit.lock']) {
                    throw new RuntimeException('Index missing or directory is not empty. Rebuild the index or explicitly disable sharing.');
                }
                foreach ($index->repository->listPackObjects($index->scopeId) as $receipt) {
                    V4Format::validateReceipt($receipt);
                    if (!str_starts_with($receipt['key'], 'packs/' . $index->scopeId . '/')) { throw new RuntimeException('Repository returned a pack outside the index scope.'); }
                    throw new RuntimeException('Index missing for stored packs. Rebuild the index or explicitly disable sharing.');
                }
                $index->initialize('ready');
            } else { $index->load('ready'); }
        });
        return $index;
    }

    public function identity(): array
    {
        return ['scopeId' => $this->scopeId, 'indexId' => $this->header['indexId']];
    }

    public function withLock(callable $operation)
    {
        return $this->lock(function () use ($operation) {
            $this->load('ready');
            return $operation();
        });
    }

    public function lookup(string $contentId): ?array
    {
        $this->assertLocked(); self::digest($contentId);
        $locator = $this->contents->get($contentId);
        if ($locator === null) { return null; }
        self::fields($locator, ['pack']);
        if (!is_string($locator['pack'])) { throw new RuntimeException('Invalid content locator.'); }
        $pack = $this->pack($locator['pack']);
        if ($pack === null) { throw new RuntimeException('Content locator has lost its pack.'); }
        if ($pack['status'] !== 'ready') { return null; }
        if (isset($this->lastPackMembers[$contentId])) { return ['descriptor' => $pack['descriptor'], 'rootScopeSha256' => $pack['rootScopeSha256']]; }
        throw new RuntimeException('Content locator differs from pack membership.');
    }

    public function verifyReadyPack(string $packKey): array
    {
        $this->assertLocked();
        $pack = $this->pack($packKey);
        if ($pack === null || $pack['status'] !== 'ready') { throw new RuntimeException('Pack is not ready for reuse.'); }
        $this->repository->verify($pack['descriptor']['object']);
        return $pack['descriptor'];
    }

    /**
     * verifyReadyPack() for several packs, in their order: every one must be ready before any is read, and each is still
     * read and hashed in full. A repository with verifyMany() reads them together; any failure fails them all.
     */
    public function verifyReadyPacks(array $packKeys): array
    {
        $descriptors = $this->readyDescriptors($packKeys);
        $objects = array_column($descriptors, 'object');
        if (method_exists($this->repository, 'verifyMany')) { $this->repository->verifyMany($objects); }
        else { foreach ($objects as $object) { $this->repository->verify($object); } }
        return $descriptors;
    }

    /**
     * The indexed descriptors of packs that are all still ready, in their order, without reading R2. Only the transfer of a
     * capture whose packs were read in full under the capture's lock uses this; publication reads every pack in full
     * again before and after the completion record is written.
     */
    public function readyDescriptors(array $packKeys): array
    {
        $this->assertLocked();
        if ($packKeys === [] || !array_is_list($packKeys) || count(array_unique($packKeys, SORT_REGULAR)) !== count($packKeys)) {
            throw new RuntimeException('Invalid ready pack list.');
        }
        $descriptors = [];
        foreach ($packKeys as $packKey) {
            if (!is_string($packKey)) { throw new RuntimeException('Invalid ready pack list.'); }
            $pack = $this->pack($packKey);
            if ($pack === null || $pack['status'] !== 'ready') { throw new RuntimeException('Pack is not ready for reuse.'); }
            $descriptors[] = $pack['descriptor'];
        }
        return $descriptors;
    }

    public function recordCandidate(array $descriptor, ?string $rootScopeSha256): void
    {
        $this->assertLocked(); $this->validateDescriptor($descriptor); self::optionalDigest($rootScopeSha256);
        $key = $descriptor['object']['key'];
        $existing = $this->pack($key);
        if ($existing !== null) {
            if ($existing['descriptor'] !== $descriptor || $existing['rootScopeSha256'] !== $rootScopeSha256 || $existing['status'] === 'missing') {
                throw new RuntimeException('A pack identity cannot be reassigned.');
            }
            return;
        }
        $this->packs->put($key, ['descriptor' => $descriptor, 'status' => 'candidate', 'rootScopeSha256' => $rootScopeSha256]);
    }

    public function markReady(array $receipt): void
    {
        $this->assertLocked(); V4Format::validateReceipt($receipt);
        $pack = $this->pack($receipt['key']);
        if ($pack === null || $pack['status'] === 'missing' || $pack['descriptor']['object'] !== $receipt) {
            throw new RuntimeException('Unrecorded or different ciphertext cannot become ready.');
        }
        $this->repository->verify($receipt);
        $pack['status'] = 'ready';
        $this->packs->put($receipt['key'], $pack, true);
        foreach ($pack['descriptor']['members'] as $member) { $this->contents->put($member['id'], ['pack' => $receipt['key']], true); }
    }

    public function markMissing(iterable $keys): void
    {
        $this->assertLocked();
        foreach ($keys as $key) {
            if (!is_string($key)) { throw new RuntimeException('Invalid deleted pack key.'); }
            if (!str_starts_with($key, 'packs/' . $this->scopeId . '/')) { continue; }
            $pack = $this->pack($key);
            if ($pack === null) { continue; }
            $pack['status'] = 'missing';
            $this->packs->put($key, $pack, true);
        }
    }

    /** A filtered selection needs proof for any ready pack, independently of the source roots. */
    public function requiresMembershipProof(bool $full, ?string $rootScopeSha256 = null): bool
    {
        $this->assertLocked();
        if ($full || $rootScopeSha256 !== null) { self::digest($rootScopeSha256); }
        foreach ($this->packs->entries() as $key => $pack) {
            $this->validatePackRecord($key, $pack);
            if ($pack['status'] === 'ready' && (!$full || $pack['rootScopeSha256'] !== $rootScopeSha256)) { return true; }
        }
        return false;
    }

    public static function rootScopeSha256(array $roots): string
    {
        if (!array_is_list($roots) || $roots === [] || count($roots) > V4Format::MAX_ROOTS) { throw new RuntimeException('Invalid source roots.'); }
        $scope = [];
        foreach ($roots as $root) {
            if (!is_array($root) || !isset($root['sourcePath'], $root['type']) || !is_string($root['sourcePath'])
                || !in_array($root['type'], ['directory', 'file'], true) || strlen($root['sourcePath']) > 4096
                || !mb_check_encoding($root['sourcePath'], 'UTF-8') || preg_match('/[\p{Cc}\\\\]/u', $root['sourcePath'])
                || $root['sourcePath'] === '' || $root['sourcePath'][0] !== '/') { throw new RuntimeException('Invalid source root identity.'); }
            if ($root['sourcePath'] !== '/') {
                foreach (explode('/', substr($root['sourcePath'], 1)) as $part) {
                    if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 255) { throw new RuntimeException('Noncanonical source root.'); }
                }
            }
            $scope[] = ['sourcePath' => $root['sourcePath'], 'type' => $root['type']];
        }
        usort($scope, static function (array $left, array $right): int {
            return strcmp($left['sourcePath'], $right['sourcePath']) ?: strcmp($left['type'], $right['type']);
        });
        return hash('sha256', V4Format::json($scope));
    }

    public static function rebuild(string $emptyPrivateDir, array $scope, $repository, iterable $verifiedPacks): self
    {
        $index = new self($emptyPrivateDir, $scope, $repository);
        if (scandir($emptyPrivateDir) !== ['.', '..']) { throw new RuntimeException('Rebuild requires a new empty private directory.'); }
        $index->lock(function () use ($index, $verifiedPacks): void {
            if (scandir($index->directory) !== ['.', '..', 'unit.lock']) { throw new RuntimeException('Rebuild directory is no longer empty.'); }
            $index->initialize('rebuilding');
            foreach ($verifiedPacks as $verified) {
                if (!is_array($verified)) { throw new RuntimeException('Invalid verified pack.'); }
                self::fields($verified, ['descriptor', 'rootScopeSha256']);
                if (!is_array($verified['descriptor'])) { throw new RuntimeException('Invalid verified descriptor.'); }
                $index->validateDescriptor($verified['descriptor']); self::optionalDigest($verified['rootScopeSha256']);
                $existing = $index->pack($verified['descriptor']['object']['key']);
                if ($existing !== null) {
                    if ($existing['descriptor'] !== $verified['descriptor'] || $existing['status'] !== 'ready') { throw new RuntimeException('Conflicting rebuilt pack identity.'); }
                    if ($existing['rootScopeSha256'] === null && $verified['rootScopeSha256'] !== null) {
                        $existing['rootScopeSha256'] = $verified['rootScopeSha256'];
                        $index->packs->put($verified['descriptor']['object']['key'], $existing, true);
                    }
                    continue;
                }
                $index->recordCandidate($verified['descriptor'], $verified['rootScopeSha256']);
                $index->markReady($verified['descriptor']['object']);
            }
            $index->header['state'] = 'ready';
            $index->saveHeader();
        });
        return $index;
    }

    private function pack(string $key): ?array
    {
        if (!preg_match('~^packs/' . $this->scopeId . '/[a-f0-9]{64}\.age$~D', $key)) { throw new RuntimeException('Pack is outside the index scope.'); }
        $pack = $this->packs->get($key);
        if ($pack === null) { $this->lastPackKey = null; $this->lastPackRecord = null; $this->lastPackMembers = []; return null; }
        // Always re-read and compare the entire record; only skip repeated semantic validation for an exact match.
        if ($this->lastPackKey === $key && $this->lastPackRecord === $pack) { return $pack; }
        $this->validatePackRecord($key, $pack);
        $members = [];
        foreach ($pack['descriptor']['members'] as $member) { $members[$member['id']] = true; }
        $this->lastPackKey = $key; $this->lastPackRecord = $pack; $this->lastPackMembers = $members;
        return $pack;
    }

    private function validatePackRecord(string $key, array $pack): void
    {
        self::fields($pack, ['descriptor', 'status', 'rootScopeSha256']);
        if (!is_array($pack['descriptor']) || !in_array($pack['status'], ['candidate', 'ready', 'missing'], true)) { throw new RuntimeException('Invalid indexed pack state.'); }
        $this->validateDescriptor($pack['descriptor']); self::optionalDigest($pack['rootScopeSha256']);
        if ($key !== $pack['descriptor']['object']['key']) { throw new RuntimeException('Indexed pack identity differs.'); }
    }

    private function validateDescriptor(array $descriptor): void
    {
        V4Format::validatePack($descriptor);
        if (!str_starts_with($descriptor['object']['key'], 'packs/' . $this->scopeId . '/')) { throw new RuntimeException('Pack is outside the index scope.'); }
    }

    private function initialize(string $state): void
    {
        $this->packs = MetadataMap::create($this->directory . '/packs');
        $this->contents = MetadataMap::create($this->directory . '/contents');
        $this->header = ['version' => 4, 'scope' => $this->scope, 'scopeId' => $this->scopeId, 'indexId' => bin2hex(random_bytes(32)), 'state' => $state];
        $this->saveHeader();
    }

    private function load(string $state): void
    {
        FileSafety::privateDirectory($this->directory);
        $path = $this->directory . '/scope.json';
        FileSafety::assertRegularFile($path);
        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== 0600 || filesize($path) > 4096) { throw new RuntimeException('Unsafe index header.'); }
        $json = file_get_contents($path);
        if (!is_string($json) || strlen($json) > 4096) { throw new RuntimeException('Cannot read index header.'); }
        $header = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($header)) { throw new RuntimeException('Invalid index header.'); }
        self::fields($header, ['version', 'scope', 'scopeId', 'indexId', 'state', 'checksum']);
        $checksum = $header['checksum']; unset($header['checksum']);
        if (!is_string($checksum) || !hash_equals(hash('sha256', V4Format::json($header)), $checksum)
            || $header['version'] !== 4 || !is_array($header['scope']) || V4Format::scopeId($header['scope']) !== $this->scopeId
            || $header['scope'] !== $this->scope || $header['scopeId'] !== $this->scopeId || $header['state'] !== $state) {
            throw new RuntimeException('Index is corrupt, incomplete, or belongs to a different scope.');
        }
        self::digest($header['indexId']);
        if ($this->header !== [] && $this->header !== $header) { throw new RuntimeException('Index identity changed.'); }
        $this->packs = MetadataMap::open($this->directory . '/packs');
        $this->contents = MetadataMap::open($this->directory . '/contents');
        $this->header = $header;
    }

    private function saveHeader(): void
    {
        $path = $this->directory . '/scope.json';
        if (@lstat($path) !== false) { FileSafety::assertRegularFile($path); }
        $data = $this->header;
        $data['checksum'] = hash('sha256', V4Format::json($this->header));
        $json = V4Format::json($data);
        $pending = $this->directory . '/pending-' . bin2hex(random_bytes(16));
        $stream = fopen($pending, 'xb');
        if ($stream === false) { throw new RuntimeException('Cannot create index header.'); }
        try {
            if (!chmod($pending, 0600) || fwrite($stream, $json) !== strlen($json) || !FileSafety::sync($stream)) { throw new RuntimeException('Cannot persist index header.'); }
        } finally { fclose($stream); }
        if (!rename($pending, $path)) { throw new RuntimeException('Cannot publish index header.'); }
    }

    private function lock(callable $operation)
    {
        if ($this->locked) { throw new \LogicException('Nested index lock.'); }
        FileSafety::privateDirectory($this->directory);
        $path = $this->directory . '/unit.lock';
        if (@lstat($path) !== false) {
            FileSafety::assertRegularFile($path);
            if ((fileperms($path) & 0777) !== 0600) { throw new RuntimeException('Unsafe index lock.'); }
        }
        // Capture and publication run subprocesses while holding this lock.
        $stream = fopen($path, 'c+be');
        if ($stream === false) { throw new RuntimeException('Cannot open index lock.'); }
        try {
            if (!chmod($path, 0600) || !flock($stream, LOCK_EX)) { throw new RuntimeException('Cannot lock the v4 index.'); }
            $this->locked = true;
            return $operation();
        } finally {
            $this->lastPackKey = null; $this->lastPackRecord = null; $this->lastPackMembers = [];
            $this->locked = false;
            flock($stream, LOCK_UN); fclose($stream);
        }
    }

    private function assertLocked(): void
    {
        if (!$this->locked) { throw new \LogicException('Index operation requires the unit lock.'); }
    }

    private static function fields(array $value, array $fields): void
    {
        $actual = array_keys($value); sort($actual); sort($fields);
        if ($actual !== $fields) { throw new RuntimeException('Unexpected or missing index fields.'); }
    }

    private static function digest($value): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) { throw new RuntimeException('Invalid index identifier.'); }
    }

    private static function optionalDigest($value): void
    {
        if ($value !== null) { self::digest($value); }
    }
}
