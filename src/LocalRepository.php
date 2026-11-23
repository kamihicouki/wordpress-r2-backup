<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use InvalidArgumentException;
use RuntimeException;

final class LocalRepository implements ObjectWriter, ObjectReader
{
    private string $root;
    private string $unit;
    private bool $writing;
    private ?\Closure $publicationGuard;

    private function __construct(string $root, string $unit, bool $writing, ?\Closure $publicationGuard = null)
    {
        $this->root = $root;
        $this->unit = $unit;
        $this->writing = $writing;
        $this->publicationGuard = $publicationGuard;
    }

    public static function forWriting(string $root, string $unitId, ?\Closure $publicationGuard = null): self
    {
        self::identifier($unitId);
        FileSafety::privateDirectory($root);
        FileSafety::createPrivateDirectory($root . '/' . $unitId);
        return new self($root, $unitId, true, $publicationGuard);
    }

    public static function forRecovery(string $root, string $unitId): self
    {
        self::identifier($unitId);
        FileSafety::privateDirectory($root);
        FileSafety::privateDirectory($root . '/' . $unitId);
        return new self($root, $unitId, false);
    }

    public function unitId(): string
    {
        return $this->unit;
    }

    public function putNew(string $objectKey, string $cipherFile): array
    {
        $this->requireRole(true);
        self::key($objectKey);
        if (str_starts_with($objectKey, 'commits/')) {
            throw new InvalidArgumentException('Completion records must be published after verification.');
        }
        FileSafety::assertRegularFile($cipherFile);
        $input = @fopen($cipherFile, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot read ciphertext input.');
        }
        try {
            $stat = fstat($input);
            Manifest::validateReceipt(['key' => $objectKey, 'bytes' => $stat['size'], 'sha256' => str_repeat('0', 64)]);
            return $this->store($objectKey, $input);
        } finally {
            fclose($input);
        }
    }

    public function verify(array $receipt): void
    {
        $this->verifyStored($receipt);
    }

    public function publish(string $snapshotId, array $manifestReceipt): void
    {
        $this->publishRecord($snapshotId, $manifestReceipt, null);
    }

    private function publishRecord(string $snapshotId, array $manifestReceipt, ?array $objects): void
    {
        $this->requireRole(true);
        self::identifier($snapshotId);
        Manifest::validateReceipt($manifestReceipt);
        if ($manifestReceipt['key'] !== 'manifests/' . $snapshotId . '.age') {
            throw new InvalidArgumentException('Manifest does not belong to this snapshot.');
        }
        $this->verifyStored($manifestReceipt);
        $record = ['snapshotId' => $snapshotId, 'manifest' => $manifestReceipt];
        if ($objects !== null) { $record['objects'] = $objects; }
        $data = json_encode($record, JSON_THROW_ON_ERROR);
        if (strlen($data) > 33554432) { throw new RuntimeException('Completion record exceeds its size limit.'); }
        $input = fopen('php://memory', 'w+b');
        try {
            if (fwrite($input, $data) !== strlen($data) || !rewind($input)) {
                throw new RuntimeException('Cannot stage completion record.');
            }
            $this->store('commits/' . $snapshotId . '.json', $input);
        } finally {
            fclose($input);
        }
    }

    public function hasObjects(): bool
    {
        $this->requireRole(true);
        return $this->hasEntry($this->base());
    }

    public function publishWithObjects(string $snapshotId, array $manifestReceipt, array $objects): void
    {
        $this->requireRole(true);
        if (count($objects) > 100000 || !array_is_list($objects)) {
            throw new InvalidArgumentException('Invalid completion object list.');
        }
        $seen = [];
        foreach ($objects as $receipt) {
            if (!is_array($receipt)) { throw new InvalidArgumentException('Invalid completion object receipt.'); }
            Manifest::validateReceipt($receipt);
            if (str_starts_with($receipt['key'], 'manifests/') || isset($seen[$receipt['key']])) {
                throw new InvalidArgumentException('Invalid or duplicate completion object receipt.');
            }
            $seen[$receipt['key']] = true;
        }
        foreach ($objects as $receipt) { $this->verifyStored($receipt); }
        $this->publishRecord($snapshotId, $manifestReceipt, $objects);
    }

    public function objectPath(string $key): string
    {
        $this->requireRole(false);
        return $this->existingPath($key);
    }

    public function download(array $receipt, string $newCipherFile): void
    {
        $this->requireRole(false);
        Manifest::validateReceipt($receipt);
        $source = $this->existingPath($receipt['key']);
        clearstatcache(true, $source);
        if (filesize($source) !== $receipt['bytes']) {
            throw new RuntimeException('Stored ciphertext size does not match its receipt.');
        }
        FileSafety::assertNoLinks($newCipherFile);
        $parent = FileSafety::privateDirectory(dirname($newCipherFile));
        if (@lstat($newCipherFile) !== false) {
            throw new RuntimeException('The ciphertext output already exists.');
        }
        $temporary = $parent . '/.download-' . bin2hex(random_bytes(16));
        $input = fopen($source, 'rb');
        $output = @fopen($temporary, 'x+b');
        if ($input === false || $output === false) {
            if (is_resource($input)) { fclose($input); }
            if (is_resource($output)) { fclose($output); unlink($temporary); }
            throw new RuntimeException('Cannot open ciphertext download streams.');
        }
        try {
            if (!chmod($temporary, 0600) || stream_copy_to_stream($input, $output, $receipt['bytes'] + 1) !== $receipt['bytes']
                || !fflush($output) || !\WordPressR2Backup\FileSafety::sync($output)
                || !hash_equals($receipt['sha256'], hash_file('sha256', $temporary))) {
                throw new RuntimeException('Ciphertext download verification failed.');
            }
            if (!@link($temporary, $newCipherFile)) {
                throw new RuntimeException('Cannot publish ciphertext without replacement.');
            }
        } finally {
            fclose($input);
            fclose($output);
            unlink($temporary);
        }
    }

    public function listCompleted(): array
    {
        return array_map(fn (string $snapshot): array => $this->completed($snapshot), $this->snapshotIds());
    }

    public function listStatus(): array
    {
        $result = [];
        foreach ($this->snapshotIds() as $snapshot) {
            try {
                $record = $this->completed($snapshot);
                $result[] = $record + ['valid' => true];
            } catch (RuntimeException $ignoredError) {
                $result[] = ['snapshotId' => $snapshot, 'valid' => false, 'error' => 'Snapshot verification failed.'];
            }
        }
        return $result;
    }

    /** List completion records without reading or hashing every referenced object. */
    public function listCandidates(): array
    {
        $result = [];
        foreach ($this->snapshotIds() as $snapshotId) {
            try {
                $record = $this->completionMetadata($snapshotId);
                Manifest::validateReceipt($record['manifest']);
                $valid = true;
            } catch (RuntimeException | InvalidArgumentException $error) { $valid = false; }
            $result[] = ['snapshotId' => $snapshotId, 'valid' => $valid, 'verified' => false];
        }
        return $result;
    }

    public function completionMetadata(string $snapshotId): array
    {
        $this->requireRole(false);
        self::identifier($snapshotId);
        $path = $this->existingPath('commits/' . $snapshotId . '.json');
        $size = filesize($path);
        if ($size === false || $size > 33554432) {
            throw new RuntimeException('Completion record exceeds its size limit.');
        }
        try {
            $record = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Invalid completion record.', 0, $error);
        }
        if (!is_array($record) || (count($record) !== 2 && !(count($record) === 3 && array_key_exists('objects', $record))) || ($record['snapshotId'] ?? null) !== $snapshotId
            || !isset($record['manifest']) || !is_array($record['manifest'])
            || ($record['manifest']['key'] ?? null) !== 'manifests/' . $snapshotId . '.age') {
            throw new RuntimeException('Invalid completion record.');
        }
        return $record;
    }

    public function completed(string $snapshotId): array
    {
        $record = $this->completionMetadata($snapshotId);
        try {
            $this->verifyStored($record['manifest']);
            if (array_key_exists('objects', $record)) {
                if (!is_array($record['objects']) || !array_is_list($record['objects']) || count($record['objects']) > 100000) { throw new RuntimeException('Invalid completion object list.'); }
                $seen = [];
                foreach ($record['objects'] as $receipt) {
                    if (!is_array($receipt)) { throw new RuntimeException('Invalid completion object receipt.'); }
                    Manifest::validateReceipt($receipt);
                    if (str_starts_with($receipt['key'], 'manifests/') || isset($seen[$receipt['key']])) { throw new RuntimeException('Invalid or duplicate completion object receipt.'); }
                    $seen[$receipt['key']] = true;
                    $this->verifyStored($receipt);
                }
            }
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException('Invalid completion manifest receipt.', 0, $error);
        }
        return $record;
    }

    private function snapshotIds(): array
    {
        $this->requireRole(false);
        $directory = $this->base() . '/commits';
        if (@lstat($directory) === false) {
            return [];
        }
        FileSafety::privateDirectory($directory);
        $result = [];
        foreach ($this->entries($directory) as $entry) {
            if (str_starts_with($entry, '.stage-')) {
                continue;
            }
            if (!preg_match('/^([a-f0-9]{64})\.json$/D', $entry, $parts)) {
                throw new RuntimeException('Invalid completion record name.');
            }
            $result[] = $parts[1];
        }
        return $result;
    }

    public function listFileObjects(string $keySetId): array
    {
        $this->requireRole(false);
        self::identifier($keySetId);
        $directory = $this->base() . '/files/' . $keySetId;
        FileSafety::assertNoLinks($directory);
        if (@lstat($directory) === false) {
            return [];
        }
        FileSafety::privateDirectory(dirname($directory));
        FileSafety::privateDirectory($directory);
        $result = [];
        foreach ($this->entries($directory) as $entry) {
            if (str_starts_with($entry, '.stage-')) {
                continue;
            }
            if (!preg_match('/^[a-f0-9]{64}\.age$/D', $entry)) {
                throw new RuntimeException('Invalid file object name.');
            }
            $key = 'files/' . $keySetId . '/' . $entry;
            $result[] = $this->receipt($key, $this->existingPath($key));
        }
        return $result;
    }

    public function listPackObjects(string $scopeId): iterable
    {
        self::identifier($scopeId);
        $directory = $this->base() . '/packs/' . $scopeId;
        FileSafety::assertNoLinks($directory);
        if (@lstat($directory) === false) { return; }
        FileSafety::privateDirectory(dirname($directory));
        FileSafety::privateDirectory($directory);
        $entries = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        $count = 0;
        foreach ($entries as $entry) {
            $name = $entry->getFilename();
            if (str_starts_with($name, '.stage-')) { continue; }
            if (!preg_match('/^[a-f0-9]{64}\.age$/D', $name) || ++$count > 100000) { throw new RuntimeException('Invalid or excessive pack object listing.'); }
            $key = 'packs/' . $scopeId . '/' . $name;
            yield $this->receipt($key, $this->existingPath($key));
        }
    }

    public function delete(string $key): void
    {
        throw new RuntimeException('Deletion and expiry are forbidden by the local repository API.');
    }

    private function store(string $key, $input): array
    {
        $directory = $this->base();
        $parts = explode('/', $key);
        array_pop($parts);
        foreach ($parts as $part) {
            $directory = FileSafety::createPrivateDirectory($directory . '/' . $part);
        }
        $destination = $directory . '/' . basename($key);
        FileSafety::assertNoLinks($destination);
        $temporary = $directory . '/.stage-' . bin2hex(random_bytes(16));
        $output = @fopen($temporary, 'x+b');
        if ($output === false) {
            throw new RuntimeException('Cannot create ciphertext staging file.');
        }
        try {
            if (!chmod($temporary, 0600) || stream_copy_to_stream($input, $output) === false || !fflush($output) || !\WordPressR2Backup\FileSafety::sync($output)) {
                throw new RuntimeException('Cannot persist staged object.');
            }
            $receipt = $this->receipt($key, $temporary);
            FileSafety::privateDirectory($directory);
            if ($this->publicationGuard !== null) {
                ($this->publicationGuard)();
            }
            if (!@link($temporary, $destination)) {
                throw new RuntimeException('Object already exists or cannot be created.');
            }
            return $receipt;
        } finally {
            fclose($output);
            if (!@unlink($temporary)) {
                throw new RuntimeException('Cannot remove owned staging file.');
            }
        }
    }

    private function verifyStored(array $receipt): void
    {
        Manifest::validateReceipt($receipt);
        $actual = $this->receipt($receipt['key'], $this->existingPath($receipt['key']));
        if ($actual['bytes'] !== $receipt['bytes'] || !hash_equals($receipt['sha256'], $actual['sha256'])) {
            throw new RuntimeException('Ciphertext verification failed.');
        }
    }

    private function receipt(string $key, string $path): array
    {
        FileSafety::assertRegularFile($path);
        clearstatcache(true, $path);
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        if ($size === false || $size < 1 || $hash === false) {
            throw new RuntimeException('Cannot read a nonempty stored object.');
        }
        $receipt = ['key' => $key, 'bytes' => $size, 'sha256' => $hash];
        if (!str_starts_with($key, 'commits/')) { Manifest::validateReceipt($receipt); }
        return $receipt;
    }

    private function existingPath(string $key): string
    {
        self::key($key);
        $directory = $this->base();
        $parts = explode('/', $key);
        array_pop($parts);
        foreach ($parts as $part) {
            $directory .= '/' . $part;
            FileSafety::privateDirectory($directory);
        }
        $path = $this->base() . '/' . $key;
        FileSafety::assertRegularFile($path);
        return $path;
    }

    private function base(): string
    {
        FileSafety::privateDirectory($this->root);
        return FileSafety::privateDirectory($this->root . '/' . $this->unit);
    }

    private function hasEntry(string $path): bool
    {
        foreach ($this->entries($path) as $entry) {
            $child = $path . '/' . $entry;
            FileSafety::assertNoLinks($child);
            if (!is_dir($child)) {
                FileSafety::assertRegularFile($child);
                return true;
            }
            FileSafety::privateDirectory($child);
            if ($this->hasEntry($child)) {
                return true;
            }
        }
        return false;
    }

    private function entries(string $directory): array
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Cannot enumerate repository.');
        }
        return array_values(array_diff($entries, ['.', '..']));
    }

    private function requireRole(bool $writing): void
    {
        if ($this->writing !== $writing) {
            throw new RuntimeException('Repository operation is forbidden for this role.');
        }
    }

    private static function identifier(string $value): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new InvalidArgumentException('Expected a lowercase 256-bit identifier.');
        }
    }

    private static function key(string $key): void
    {
        if (!preg_match('~^(?:(?:files|packs|pages)/[a-f0-9]{64}/[a-f0-9]{64}\.age|(?:databases|manifests)/[a-f0-9]{64}\.age|commits/[a-f0-9]{64}\.json)$~D', $key)) {
            throw new InvalidArgumentException('Invalid object key.');
        }
    }
}
