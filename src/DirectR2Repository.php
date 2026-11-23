<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

/** Direct, scoped R2 storage. The injected transport must stream the request body and call consume for response chunks. */
final class DirectR2Repository implements ObjectReader, ObjectWriter
{
    private const MAX_BYTES = 5368709120;
    private const MAX_METADATA_BYTES = 33554432;
    private const MAX_OBJECTS = 100000;
    /** Seconds before each further GET or HEAD attempt. */
    private const RETRY_DELAYS = [2, 5, 15];
    /** A GET or HEAD aborts after this long with almost no data. A transfer that keeps moving is limited only by CURLOPT_TIMEOUT, so large objects still complete. */
    private const STALL_SECONDS = 120;
    /**
     * A further attempt starts only if this many seconds or fewer have passed since the request began, and the clock has not gone backwards.
     * This limits when retries may begin. It is not a limit on the request's total time: the attempt it starts may stall or keep moving as above.
     */
    private const RETRY_BUDGET_SECONDS = 180;
    /** Whole-object reads that verifyMany() runs at once. Each still reads and hashes every byte of its object. */
    private const PARALLEL_READS = 4;
    private const BULK_PARALLEL_READS = 8;
    private const BULK_READ_THRESHOLD = 16;
    private const PARALLEL_WRITES = 4;
    private array $connection;
    private $transport;
    private $clock;
    private $wait;
    /** Monotonic nanoseconds. Parallel reads decide that a retry delay has passed only from this time. */
    private $ticks;
    /** Reuse the connection within one repository instance, while resetting all request options between uses. */
    private $curlHandle = null;

    public function __construct(array $connection, ?callable $transport = null, ?callable $clock = null, ?callable $wait = null, ?callable $ticks = null)
    {
        self::fields($connection, ['accountId', 'bucket', 'accessKeyId', 'secretAccessKey', 'unitId']);
        foreach ($connection as $value) { if (!is_string($value)) { throw new RuntimeException('Invalid R2 connection.'); } }
        if (!preg_match('/^[a-f0-9]{32}$/D', $connection['accountId'])
            || !preg_match('/^[a-f0-9]{64}$/D', $connection['unitId'])
            || !preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $connection['bucket'])
            || !preg_match('/^[A-Za-z0-9]{16,128}$/D', $connection['accessKeyId'])
            || !preg_match('~^[A-Za-z0-9/+=]{32,256}$~D', $connection['secretAccessKey'])) {
            throw new RuntimeException('Invalid R2 connection.');
        }
        $this->connection = $connection;
        $this->transport = $transport;
        $this->clock = $clock ?? static fn (): int => time();
        $this->wait = $wait ?? static function (int $seconds): void { sleep($seconds); };
        $this->ticks = $ticks ?? static fn (): int => hrtime(true);
    }

    public static function fromFile(string $privatepath): self
    {
        FileSafety::assertRegularFile($privatepath);
        $stat = lstat($privatepath);
        if (realpath($privatepath) !== $privatepath || ($stat['mode'] & 0777) !== 0600
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) || $stat['size'] > 8192) {
            throw new RuntimeException('Expected an owner-only bounded R2 connection file.');
        }
        $body = @file_get_contents($privatepath, false, null, 0, 8193);
        if (!is_string($body) || strlen($body) > 8192) { throw new RuntimeException('Cannot read R2 connection file.'); }
        return self::fromContents($body);
    }

    /** Parse a bounded connection file in memory, without persisting credentials. */
    public static function fromContents(string $body): self
    {
        return new self(self::connectionFromContents($body));
    }

    public static function connectionFromContents(string $body): array
    {
        if (strlen($body) > 8192) { throw new RuntimeException('R2 connection is too large.'); }
        $profile = self::decode($body);
        if (($profile['format'] ?? null) !== 'wrb-direct-r2-v1' || ($profile['version'] ?? null) !== 1) {
            throw new RuntimeException('Invalid R2 connection format.');
        }
        unset($profile['format'], $profile['version']);
        new self($profile); // Apply exactly the same field and endpoint validation.
        return $profile;
    }

    public function unitId(): string { return $this->connection['unitId']; }

    public function storageScope(): array
    {
        return ['accountId' => $this->connection['accountId'], 'bucket' => $this->connection['bucket'], 'unitId' => $this->unitId()];
    }

    public function putNew(string $objectKey, string $cipherFile): array
    {
        Manifest::validateReceipt(['key' => $objectKey, 'bytes' => 1, 'sha256' => str_repeat('0', 64)]);
        FileSafety::assertRegularFile($cipherFile);
        $input = @fopen($cipherFile, 'rb');
        if ($input === false) { throw new RuntimeException('Cannot open ciphertext.'); }
        try {
            $stat = fstat($input);
            $bytes = $stat['size'];
            if ($bytes < 1 || $bytes > self::MAX_BYTES) { throw new RuntimeException('Ciphertext exceeds the single upload limit or is empty.'); }
            Manifest::validateReceipt(['key' => $objectKey, 'bytes' => $bytes, 'sha256' => str_repeat('0', 64)]);
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $input) !== $bytes || !rewind($input)) { throw new RuntimeException('Cannot hash ciphertext.'); }
            $receipt = ['key' => $objectKey, 'bytes' => $bytes, 'sha256' => hash_final($hash)];
            $this->put($objectKey, $input, $bytes, $receipt['sha256']);
            $this->verify($receipt);
            return $receipt;
        } finally { fclose($input); }
    }

    /** Upload at most sixteen new pages, retaining conditional PUT and full post-upload verification for every receipt. */
    public function putNewPages(array $pages): array
    {
        if ($pages === [] || !array_is_list($pages) || count($pages) > 16) { throw new RuntimeException('Invalid page upload batch.'); }
        $streams = []; $receipts = []; $seen = [];
        try {
            // Validate and hash every input before sending any request.
            foreach ($pages as $page) {
                if (!is_array($page)) { throw new RuntimeException('Invalid page upload record.'); }
                self::fields($page, ['object', 'cipherFile']);
                if (!is_array($page['object']) || !is_string($page['cipherFile'])) { throw new RuntimeException('Invalid page upload record.'); }
                $receipt = $page['object']; Manifest::validateReceipt($receipt);
                if (!preg_match('~^pages/[a-f0-9]{64}/[a-f0-9]{64}\.age$~D', $receipt['key']) || $receipt['bytes'] < 1 || $receipt['bytes'] > self::MAX_BYTES || isset($seen[$receipt['key']])) { throw new RuntimeException('Invalid page upload receipt.'); }
                $seen[$receipt['key']] = true; FileSafety::assertRegularFile($page['cipherFile']);
                $stream = @fopen($page['cipherFile'], 'rb'); if ($stream === false) { throw new RuntimeException('Cannot open ciphertext.'); }
                $streams[] = $stream; $stat = fstat($stream); clearstatcache(true, $page['cipherFile']); $pathStat = @lstat($page['cipherFile']);
                if ($stat === false || $pathStat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['dev'] !== $pathStat['dev'] || $stat['ino'] !== $pathStat['ino'] || $stat['size'] !== $receipt['bytes']) { throw new RuntimeException('Page ciphertext changed.'); }
                $hash = hash_init('sha256');
                if (hash_update_stream($hash, $stream) !== $receipt['bytes'] || !hash_equals($receipt['sha256'], hash_final($hash)) || !rewind($stream)) { throw new RuntimeException('Page ciphertext differs.'); }
                $after = fstat($stream);
                if ($after === false || $after['dev'] !== $stat['dev'] || $after['ino'] !== $stat['ino'] || $after['size'] !== $stat['size'] || $after['mtime'] !== $stat['mtime'] || $after['ctime'] !== $stat['ctime']) { throw new RuntimeException('Page ciphertext changed.'); }
                $receipts[] = $receipt;
            }
            if ($this->transport !== null || count($receipts) < 2 || !function_exists('curl_multi_init')) {
                foreach ($receipts as $index => $receipt) {
                    $this->put($receipt['key'], $streams[$index], $receipt['bytes'], $receipt['sha256']);
                    $this->verify($receipt);
                }
            } else {
                $specs = [];
                foreach ($receipts as $index => $receipt) {
                    $specs[] = ['method'=>'PUT','key'=>$receipt['key'],'body'=>$streams[$index],'bodyBytes'=>$receipt['bytes'],'payloadHash'=>$receipt['sha256'],
                        'headers'=>['if-none-match'=>'*'],'consume'=>static fn(string $chunk): int => strlen($chunk),'limit'=>65536,'allowed'=>[200,201,204,409,412]];
                }
                $this->curlMany($specs);
                // Includes existing objects after 409/412; read and hash every full object.
                $this->verifyMany($receipts);
            }
            return $receipts;
        } finally { foreach ($streams as $stream) { fclose($stream); } }
    }

    public function verify(array $receipt): void
    {
        Manifest::validateReceipt($receipt);
        if ($receipt['bytes'] > self::MAX_BYTES) { throw new RuntimeException('Ciphertext exceeds the single upload limit.'); }
        $this->readObject($receipt['key'], $receipt);
    }

    /**
     * The checks of verify() for every receipt: a HEAD whose length must equal the receipt, then a GET whose every byte is
     * counted and hashed against it. With cURL, batches below BULK_READ_THRESHOLD use up to PARALLEL_READS connections,
     * larger batches use up to BULK_PARALLEL_READS; an injected transport reads one at a time. Any failure fails the call.
     */
    public function verifyMany(array $receipts): void
    {
        foreach ($receipts as $receipt) {
            Manifest::validateReceipt($receipt);
            if ($receipt['bytes'] > self::MAX_BYTES) { throw new RuntimeException('Ciphertext exceeds the single upload limit.'); }
        }
        $receipts = array_values($receipts);
        if ($this->transport !== null || count($receipts) < 2 || !function_exists('curl_multi_init')) {
            foreach ($receipts as $receipt) { $this->readObject($receipt['key'], $receipt); }
            return;
        }
        $heads = [];
        foreach ($receipts as $index => $receipt) {
            $heads[$index] = ['method' => 'HEAD', 'key' => $receipt['key'], 'consume' => static fn (string $chunk): int => strlen($chunk), 'limit' => 0, 'allowed' => [200], 'reset' => static function (): void {}];
        }
        $parallel = count($receipts) >= self::BULK_READ_THRESHOLD ? self::BULK_PARALLEL_READS : self::PARALLEL_READS;
        foreach ($this->curlMany($heads, $parallel) as $index => $head) { self::storedLength($head, $receipts[$index]['key'], $receipts[$index]); }
        $reads = []; $states = [];
        foreach ($receipts as $index => $receipt) {
            $state = (object) ['hash' => hash_init('sha256'), 'bytes' => 0]; $states[$index] = $state;
            $reads[$index] = ['method' => 'GET', 'key' => $receipt['key'], 'limit' => $receipt['bytes'], 'allowed' => [200],
                'consume' => static function (string $chunk) use ($state): int { hash_update($state->hash, $chunk); $state->bytes += strlen($chunk); return strlen($chunk); },
                'reset' => static function () use ($state): void { $state->hash = hash_init('sha256'); $state->bytes = 0; }];
        }
        $this->curlMany($reads, $parallel);
        foreach ($receipts as $index => $receipt) {
            if ($states[$index]->bytes !== $receipt['bytes'] || !hash_equals($receipt['sha256'], hash_final($states[$index]->hash))) { throw new RuntimeException('Ciphertext verification failed.'); }
        }
    }

    public function publish(string $snapshotId, array $manifestReceipt): void
    {
        self::identifier($snapshotId);
        Manifest::validateReceipt($manifestReceipt);
        if ($manifestReceipt['key'] !== 'manifests/' . $snapshotId . '.age') { throw new RuntimeException('Manifest belongs to another snapshot.'); }
        $this->verify($manifestReceipt);
        $this->putText('commits/' . $snapshotId . '.json', json_encode(['snapshotId' => $snapshotId, 'manifest' => $manifestReceipt], JSON_THROW_ON_ERROR));
    }

    /** Only ciphertext receipts and opaque identifiers are published in public metadata. */
    public function publishWithObjects(string $snapshotId, array $manifestReceipt, array $objects): void
    {
        self::identifier($snapshotId);
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) { throw new RuntimeException('Invalid R2 signing clock.'); }
        $record = ['snapshotId' => $snapshotId, 'manifest' => $manifestReceipt,
            'objects' => $objects, 'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $now)];
        $this->validateCompletion($snapshotId, $record);
        $this->verifyMany(array_merge([$manifestReceipt], $objects));
        if ($this->optionalText('deletions/' . $snapshotId . '.json') !== null) { throw new RuntimeException('Cannot republish a deleted snapshot.'); }
        // Retries retain the immutable original publication time.
        $existing = $this->optionalText('commits/' . $snapshotId . '.json');
        if ($existing !== null) {
            $published = self::decode($existing);
            $this->validateCompletion($snapshotId, $published);
            if ($published['manifest'] !== $manifestReceipt || ($published['objects'] ?? null) !== $objects) {
                throw new RuntimeException('R2 completion already exists with different references.');
            }
            return;
        }
        $body = json_encode($record, JSON_THROW_ON_ERROR);
        if (strlen($body) > self::MAX_METADATA_BYTES) { throw new RuntimeException('Completion record exceeds metadata limit.'); }
        $this->putText('commits/' . $snapshotId . '.json', $body);
    }

    /** Caller must hold its operation lock and exclude unfinished backups. */
    public function deleteSnapshot(string $id): array
    {
        self::identifier($id);
        $ledgerKey = 'deletions/' . $id . '.json';
        $body = $this->optionalText($ledgerKey);
        if ($body === null) {
            $record = $this->completionRecord($id);
            $ledger = ['snapshotId' => $id, 'commit' => $record];
            $body = json_encode($ledger, JSON_THROW_ON_ERROR);
            if (strlen($body) > self::MAX_METADATA_BYTES) { throw new RuntimeException('Deletion record exceeds metadata limit.'); }
            $this->putText($ledgerKey, $body);
        } else {
            $ledger = self::decode($body);
            self::fields($ledger, ['snapshotId', 'commit']);
            if ($ledger['snapshotId'] !== $id || !is_array($ledger['commit'])) { throw new RuntimeException('Invalid deletion record.'); }
            $record = $ledger['commit'];
            $this->validateCompletion($id, $record);
            $current = $this->optionalText('commits/' . $id . '.json');
            if ($current !== null) {
                $currentRecord = self::decode($current);
                $this->validateCompletion($id, $currentRecord);
                if ($currentRecord !== $record) { throw new RuntimeException('Deletion record differs from completion record.'); }
            }
        }
        $shared = []; $unknownReferences = false;
        foreach ($this->snapshotIds() as $otherId) {
            if ($otherId === $id) { continue; }
            $other = $this->completionRecord($otherId);
            if (!isset($other['objects'])) { $unknownReferences = true; }
            foreach ($other['objects'] ?? [] as $receipt) { $shared[$receipt['key']] = true; }
        }
        $deleted = []; $retained = [];
        // Hide completion before removing any referenced ciphertext. A retry uses the durable ledger.
        $this->deleteKey('commits/' . $id . '.json');
        $deleted[] = 'commits/' . $id . '.json';
        $this->deleteKey($record['manifest']['key']);
        $deleted[] = $record['manifest']['key'];
        foreach ($record['objects'] ?? [] as $receipt) {
            if ($unknownReferences || isset($shared[$receipt['key']])) { $retained[] = $receipt['key']; continue; }
            $this->deleteKey($receipt['key']);
            $deleted[] = $receipt['key'];
        }
        return ['snapshotId' => $id, 'deleted' => $deleted, 'retainedShared' => $retained];
    }

    public function retention(int $days): array
    {
        if ($days < 1 || $days > 3650) { throw new RuntimeException('Retention must be between 1 and 3650 days.'); }
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) { throw new RuntimeException('Invalid R2 signing clock.'); }
        $expired = [];
        // A failed deletion can already have removed its commit. Resume its durable intent first.
        foreach ($this->listKeys('deletions/') as $key) {
            if (!preg_match('~^deletions/([a-f0-9]{64})\.json$~D', $key, $match)) { throw new RuntimeException('Invalid deletion record name.'); }
            $expired[$match[1]] = true;
        }
        foreach ($this->snapshotIds() as $id) {
            $record = $this->completionRecord($id);
            if (isset($record['createdAt']) && strtotime($record['createdAt']) <= $now - $days * 86400) { $expired[$id] = true; }
        }
        $results = [];
        foreach (array_keys($expired) as $id) { $results[] = $this->deleteSnapshot($id); }
        return $results;
    }

    private function completionRecord(string $id): array
    {
        $record = self::decode($this->text('commits/' . $id . '.json', self::MAX_METADATA_BYTES));
        $this->validateCompletion($id, $record);
        return $record;
    }

    private function validateCompletion(string $id, array $record): void
    {
        $extended = array_key_exists('objects', $record) || array_key_exists('createdAt', $record);
        self::fields($record, $extended ? ['snapshotId', 'manifest', 'objects', 'createdAt'] : ['snapshotId', 'manifest']);
        if ($record['snapshotId'] !== $id || !is_array($record['manifest'])) { throw new RuntimeException('Invalid completion record.'); }
        Manifest::validateReceipt($record['manifest']);
        if ($record['manifest']['key'] !== 'manifests/' . $id . '.age' || $record['manifest']['bytes'] > self::MAX_BYTES) { throw new RuntimeException('Invalid completion manifest.'); }
        if (!$extended) { return; }
        if (!is_string($record['createdAt']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $record['createdAt'])
            || strtotime($record['createdAt']) === false || gmdate('Y-m-d\TH:i:s\Z', strtotime($record['createdAt'])) !== $record['createdAt']
            || !is_array($record['objects']) || count($record['objects']) > self::MAX_OBJECTS
            || ($record['objects'] !== [] && array_keys($record['objects']) !== range(0, count($record['objects']) - 1))) {
            throw new RuntimeException('Invalid completion metadata.');
        }
        $seen = [];
        foreach ($record['objects'] as $receipt) {
            if (!is_array($receipt)) { throw new RuntimeException('Invalid completion object receipt.'); }
            Manifest::validateReceipt($receipt);
            if ($receipt['bytes'] > self::MAX_BYTES || strpos($receipt['key'], 'manifests/') === 0 || isset($seen[$receipt['key']])) {
                throw new RuntimeException('Invalid or duplicate completion object receipt.');
            }
            $seen[$receipt['key']] = true;
        }
    }

    private function optionalText(string $key): ?string
    {
        $body = '';
        $response = $this->request('GET', $key, [], null, 0, hash('sha256', ''), [], static function (string $chunk) use (&$body): int {
            $body .= $chunk; return strlen($chunk);
        }, self::MAX_METADATA_BYTES, [200, 404], static function () use (&$body): void { $body = ''; });
        return $response['status'] === 404 ? null : $body;
    }

    private function deleteKey(string $key): void
    {
        // This is deliberately stricter than request(): deletion accepts only validated backup keys.
        if (!preg_match('~^(?:commits/[a-f0-9]{64}\.json|(?:manifests|databases)/[a-f0-9]{64}\.age|(?:files|packs|pages)/[a-f0-9]{64}/[a-f0-9]{64}\.age)$~D', $key)) {
            throw new RuntimeException('Invalid deletion key.');
        }
        $this->request('DELETE', $key, [], null, 0, hash('sha256', ''), [], static fn (string $chunk): int => strlen($chunk), 65536, [200, 204, 404]);
    }

    public function download(array $receipt, string $newCipherFile): void
    {
        Manifest::validateReceipt($receipt);
        if ($receipt['bytes'] > self::MAX_BYTES) { throw new RuntimeException('Ciphertext exceeds the single upload limit.'); }
        FileSafety::assertNoLinks($newCipherFile);
        $parent = FileSafety::privateDirectory(dirname($newCipherFile));
        if (@lstat($newCipherFile) !== false) { throw new RuntimeException('Ciphertext destination already exists.'); }
        $temporary = $parent . '/.download-' . bin2hex(random_bytes(16));
        $output = @fopen($temporary, 'x+b');
        if ($output === false) { throw new RuntimeException('Cannot create ciphertext staging file.'); }
        try {
            if (!chmod($temporary, 0600)) { throw new RuntimeException('Cannot protect ciphertext staging file.'); }
            $this->readObject($receipt['key'], $receipt, $output);
            if (!fflush($output) || !FileSafety::sync($output) || !@link($temporary, $newCipherFile)) { throw new RuntimeException('Cannot publish verified ciphertext.'); }
        } finally { fclose($output); @unlink($temporary); }
    }

    public function completed(string $snapshotId): array
    {
        self::identifier($snapshotId);
        $record = $this->completionRecord($snapshotId);
        $this->verifyMany(array_merge([$record['manifest']], $record['objects'] ?? []));
        return $record;
    }

    /** Only checks the completion record; referenced objects remain unverified until recovery. */
    public function completionMetadata(string $snapshotId): array
    {
        self::identifier($snapshotId);
        return $this->completionRecord($snapshotId);
    }

    public function listCandidates(): array
    {
        $result = [];
        foreach ($this->snapshotIds() as $id) {
            try {
                $record = $this->completionMetadata($id);
                $result[] = ['snapshotId' => $id, 'valid' => true, 'verified' => false, 'savedAt' => $record['createdAt'] ?? null];
            }
            catch (\Throwable $ignored) { $result[] = ['snapshotId' => $id, 'valid' => false, 'verified' => false]; }
        }
        return $result;
    }

    public function listCompleted(): array
    {
        return array_map(fn (string $id): array => $this->completed($id), $this->snapshotIds());
    }

    public function listStatus(): array
    {
        $result = [];
        foreach ($this->snapshotIds() as $id) {
            try { $result[] = $this->completed($id) + ['valid' => true]; }
            catch (\Throwable $ignored) { $result[] = ['snapshotId' => $id, 'valid' => false, 'error' => 'Snapshot verification failed.']; }
        }
        return $result;
    }

    public function listFileObjects(string $keySetId): array
    {
        self::identifier($keySetId);
        $result = [];
        foreach ($this->listKeys('files/' . $keySetId . '/') as $key) {
            if (!preg_match('~^files/' . $keySetId . '/[a-f0-9]{64}\.age$~D', $key)) { throw new RuntimeException('Invalid file object name.'); }
            $result[] = $this->readObject($key);
        }
        return $result;
    }

    public function listPackObjects(string $scopeId): iterable
    {
        self::identifier($scopeId);
        foreach ($this->iterateKeys('packs/' . $scopeId . '/') as $key) {
            if (!preg_match('~^packs/' . $scopeId . '/[a-f0-9]{64}\.age$~D', $key)) { throw new RuntimeException('Invalid pack object name.'); }
            yield $this->readObject($key);
        }
    }

    public function hasObjects(): bool { return $this->listKeys('') !== []; }

    public function testConnection(): array
    {
        $key = 'probes/' . bin2hex(random_bytes(32));
        $body = random_bytes(32);
        $created = false;
        try {
            // Never delete a key that was not created by this invocation.
            $created = $this->putText($key, $body, false);
            if (!$created || !hash_equals($body, $this->text($key, 32))) { throw new RuntimeException('R2 connection probe verification failed.'); }
        } finally {
            if ($created) { $this->request('DELETE', $key, [], null, 0, hash('sha256', ''), [], static fn (string $chunk): int => strlen($chunk), 65536, [204, 200]); }
        }
        return ['ok' => true, 'unitId' => $this->unitId()];
    }

    private function snapshotIds(): array
    {
        $ids = [];
        foreach ($this->listKeys('commits/') as $key) {
            if (!preg_match('~^commits/([a-f0-9]{64})\.json$~D', $key, $match)) { throw new RuntimeException('Invalid completion record name.'); }
            $ids[] = $match[1];
        }
        sort($ids, SORT_STRING);
        return $ids;
    }

    private function readObject(string $key, ?array $expected = null, $output = null): array
    {
        $head = $this->request('HEAD', $key, [], null, 0, hash('sha256', ''), [], static fn (string $chunk): int => strlen($chunk), 0, [200], static function (): void {});
        $length = (string) self::storedLength($head, $key, $expected);
        $hash = hash_init('sha256'); $bytes = 0;
        $this->request('GET', $key, [], null, 0, hash('sha256', ''), [], static function (string $chunk) use (&$hash, &$bytes, $output): int {
            $size = strlen($chunk);
            if (is_resource($output)) {
                $offset = 0;
                while ($offset < $size) { $n = fwrite($output, substr($chunk, $offset)); if ($n === false || $n === 0) { return 0; } $offset += $n; }
            }
            hash_update($hash, $chunk); $bytes += $size; return $size;
        }, (int) $length, [200], static function () use (&$hash, &$bytes, $output): void {
            $hash = hash_init('sha256'); $bytes = 0;
            if (is_resource($output) && (!ftruncate($output, 0) || !rewind($output))) { throw new RuntimeException('Cannot reset ciphertext staging file.'); }
        });
        $actual = ['key' => $key, 'bytes' => $bytes, 'sha256' => hash_final($hash)];
        if ($bytes !== (int) $length || ($expected !== null && !hash_equals($expected['sha256'], $actual['sha256']))) { throw new RuntimeException('Ciphertext verification failed.'); }
        return $actual;
    }

    private static function storedLength(array $head, string $key, ?array $expected): int
    {
        $length = $head['headers']['content-length'] ?? null;
        if (!is_string($length) || !preg_match('/^[1-9][0-9]*$/D', $length) || strlen($length) > 10
            || (int) $length > self::MAX_BYTES || ($expected !== null && (int) $length !== $expected['bytes'])) {
            throw new RuntimeException('Stored ciphertext length does not match.');
        }
        Manifest::validateReceipt(['key' => $key, 'bytes' => (int) $length, 'sha256' => str_repeat('0', 64)]);
        return (int) $length;
    }

    private function put(string $key, $stream, int $bytes, string $hash): int
    {
        $response = $this->request('PUT', $key, [], $stream, $bytes, $hash, ['if-none-match' => '*'], static fn (string $chunk): int => strlen($chunk), 65536, [200, 201, 204, 409, 412]);
        return $response['status'];
    }

    private function putText(string $key, string $body, bool $verifyExisting = true): bool
    {
        $stream = fopen('php://temp', 'w+b');
        try {
            if (fwrite($stream, $body) !== strlen($body) || !rewind($stream)) { throw new RuntimeException('Cannot stage R2 metadata.'); }
            $status = $this->put($key, $stream, strlen($body), hash('sha256', $body));
            if (in_array($status, [409, 412], true)) {
                if (!$verifyExisting || !hash_equals($body, $this->text($key, strlen($body)))) { throw new RuntimeException('R2 object already exists with different content.'); }
                return false;
            }
            return true;
        } finally { fclose($stream); }
    }

    private function text(string $key, int $limit): string
    {
        $body = '';
        $this->request('GET', $key, [], null, 0, hash('sha256', ''), [], static function (string $chunk) use (&$body): int { $body .= $chunk; return strlen($chunk); }, $limit, [200], static function () use (&$body): void { $body = ''; });
        return $body;
    }

    private function listKeys(string $prefix): array
    {
        $values = iterator_to_array($this->iterateKeys($prefix), false);
        sort($values, SORT_STRING);
        return $values;
    }

    private function iterateKeys(string $prefix): \Generator
    {
        $physicalPrefix = $this->prefix() . $prefix;
        $cursor = null; $seen = []; $count = 0; $last = null;
        for ($page = 0; $page < 100; $page++) {
            $query = ['list-type' => '2', 'max-keys' => '1000', 'prefix' => $physicalPrefix];
            if ($cursor !== null) { $query['continuation-token'] = $cursor; }
            $body = '';
            $this->request('GET', null, $query, null, 0, hash('sha256', ''), [], static function (string $chunk) use (&$body): int { $body .= $chunk; return strlen($chunk); }, 1048576, [200], static function () use (&$body): void { $body = ''; });
            [$keys, $cursor] = self::listPage($body, $physicalPrefix);
            sort($keys, SORT_STRING);
            foreach ($keys as $key) {
                if (($last !== null && strcmp($last, $key) >= 0) || ++$count > self::MAX_OBJECTS) { throw new RuntimeException('Unordered, duplicate or excessive R2 object listing.'); }
                $last = $key;
                yield substr($key, strlen($this->prefix()));
            }
            if ($cursor === null) { return; }
            if (isset($seen[$cursor])) { throw new RuntimeException('R2 listing cursor repeated.'); }
            $seen[$cursor] = true;
        }
        throw new RuntimeException('R2 listing exceeds its page limit.');
    }
    private static function listPage(string $body, string $prefix): array
    {
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $body)) { throw new RuntimeException('XML document types and entities are forbidden.'); }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($body, LIBXML_NONET) || $document->doctype !== null
                || $document->documentElement === null || $document->documentElement->localName !== 'ListBucketResult'
                || !in_array($document->documentElement->namespaceURI, [null, '', 'http://s3.amazonaws.com/doc/2006-03-01/'], true)) {
                throw new RuntimeException('Invalid R2 list XML.');
            }
            $xpath = new \DOMXPath($document);
            foreach ($document->documentElement->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->namespaceURI !== $document->documentElement->namespaceURI) {
                    throw new RuntimeException('Unexpected R2 list XML namespace.');
                }
            }
            $truncated = $xpath->query('/*/*[local-name()="IsTruncated"]');
            $tokens = $xpath->query('/*/*[local-name()="NextContinuationToken"]');
            $prefixes = $xpath->query('/*/*[local-name()="Prefix"]');
            if ($truncated->length !== 1 || $xpath->query('./*', $truncated->item(0))->length !== 0
                || !in_array($truncated->item(0)->textContent, ['true', 'false'], true)
                || $tokens->length > 1 || $prefixes->length > 1 || ($prefixes->length === 1 && $prefixes->item(0)->textContent !== $prefix)) {
                throw new RuntimeException('Invalid R2 list pagination.');
            }
            $contents = $xpath->query('/*/*[local-name()="Contents"]');
            if ($contents->length > 1000) { throw new RuntimeException('R2 list page has too many objects.'); }
            $keys = [];
            foreach ($contents as $entry) {
                $names = $xpath->query('./*[local-name()="Key"]', $entry);
                if ($names->length !== 1 || $xpath->query('./*', $names->item(0))->length !== 0
                    || $names->item(0)->namespaceURI !== $document->documentElement->namespaceURI) { throw new RuntimeException('Invalid R2 list key.'); }
                $key = $names->item(0)->textContent;
                if (!str_starts_with($key, $prefix) || strlen($key) > 1024 || preg_match('/[\x00-\x20\x7f]/', $key)) {
                    throw new RuntimeException('R2 list key escaped its requested prefix.');
                }
                $keys[] = $key;
            }
            $isTruncated = $truncated->item(0)->textContent === 'true';
            $cursor = $tokens->length === 1 ? $tokens->item(0)->textContent : null;
            if (($isTruncated && ($cursor === null || $cursor === '' || strlen($cursor) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $cursor)))
                || (!$isTruncated && $cursor !== null)) { throw new RuntimeException('Invalid R2 list continuation token.'); }
            return [$keys, $cursor];
        } finally {
            libxml_clear_errors(); libxml_use_internal_errors($previous);
        }
    }

    /**
     * A GET or HEAD whose caller supplied $reset may be repeated after a connection failure; everything else gets one attempt.
     * $reset must discard all state its consume callback built, so a later attempt starts as if it were the first. Each attempt is signed anew.
     */
    private function request(string $method, ?string $key, array $query, $body, int $bodyBytes, string $payloadHash, array $extraHeaders, callable $consume, int $limit, array $allowed, ?callable $reset = null): array
    {
        $retryable = $reset !== null && $body === null && in_array($method, ['GET', 'HEAD'], true);
        $attempts = $retryable ? count(self::RETRY_DELAYS) + 1 : 1;
        $started = ($this->clock)();
        for ($attempt = 1; ; ++$attempt) {
            try { return $this->send($method, $key, $query, $body, $bodyBytes, $payloadHash, $extraHeaders, $consume, $limit, $allowed); }
            catch (R2TransportException $failure) {
                $now = ($this->clock)();
                $again = $failure->isTransient() && $attempt < $attempts && is_int($now) && is_int($started) && $now >= $started && $now - $started <= self::RETRY_BUDGET_SECONDS;
                error_log(sprintf('wrb-r2-transport method=%s kind=%s errno=%d attempt=%d/%d retry=%s', $method, $failure->kind(), $failure->errno(), $attempt, $attempts, $again ? 'yes' : 'no'));
                if (!$again) { throw new R2TransportException($failure->errno(), $attempt, $attempts, $failure->kind()); }
            }
            $reset();
            ($this->wait)(self::RETRY_DELAYS[$attempt - 1]);
        }
    }

    /** Transport: callable(array $request, callable $consume): array{status:int,headers:array}. It reports a connection failure with R2TransportException. */
    private function send(string $method, ?string $key, array $query, $body, int $bodyBytes, string $payloadHash, array $extraHeaders, callable $consume, int $limit, array $allowed): array
    {
        $prepared = $this->prepare($method, $key, $query, $body, $bodyBytes, $payloadHash, $extraHeaders, $consume, $limit);
        try { $response = $this->transport !== null ? ($this->transport)($prepared['request'], $prepared['consume']) : $this->curl($prepared['request'], $prepared['consume']); }
        catch (R2TransportException $failure) { throw self::stoppedHere($prepared['flags'], $failure); }
        catch (\Throwable $failure) {
            error_log(sprintf('wrb-r2-transport method=%s errno=none local=yes', $method));
            throw new RuntimeException('R2 transport failed.');
        }
        return self::accepted($method, $prepared['flags'], $response, $allowed);
    }

    /** Signs one attempt now and bounds what its response may deliver. */
    private function prepare(string $method, ?string $key, array $query, $body, int $bodyBytes, string $payloadHash, array $extraHeaders, callable $consume, int $limit): array
    {
        $host = $this->connection['accountId'] . '.r2.cloudflarestorage.com';
        $path = '/' . rawurlencode($this->connection['bucket']);
        if ($key !== null) { $path .= '/' . implode('/', array_map('rawurlencode', explode('/', $this->prefix() . $key))); }
        ksort($query, SORT_STRING);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $timestamp = ($this->clock)();
        if (!is_int($timestamp) || $timestamp < 0) { throw new RuntimeException('Invalid R2 signing clock.'); }
        $date = gmdate('Ymd\THis\Z', $timestamp); $day = substr($date, 0, 8);
        $headers = $extraHeaders + ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $date];
        if ($body !== null) { $headers['content-length'] = (string) $bodyBytes; }
        ksort($headers, SORT_STRING);
        $canonical = '';
        foreach ($headers as $name => $value) { $canonical .= $name . ':' . trim($value) . "\n"; }
        $signed = implode(';', array_keys($headers));
        $requestHash = hash('sha256', $method . "\n" . $path . "\n" . $queryString . "\n" . $canonical . "\n" . $signed . "\n" . $payloadHash);
        $scope = $day . '/auto/s3/aws4_request';
        $signing = hash_hmac('sha256', $day, 'AWS4' . $this->connection['secretAccessKey'], true);
        foreach (['auto', 's3', 'aws4_request'] as $part) { $signing = hash_hmac('sha256', $part, $signing, true); }
        $signature = hash_hmac('sha256', "AWS4-HMAC-SHA256\n" . $date . "\n" . $scope . "\n" . $requestHash, $signing);
        $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->connection['accessKeyId'] . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $signature;
        // putNew()/putNewPages() validate each .age receipt, use create-only writes, and re-read the
        // complete existing ciphertext after a conflict. Metadata writes have a different recovery contract.
        $conditionalCipherPut = $method === 'PUT' && ($headers['if-none-match'] ?? null) === '*'
            && is_resource($body) && $key !== null && str_ends_with($key, '.age');
        $flags = (object) ['received' => 0, 'overflow' => false, 'refused' => false, 'conditionalCipherPut' => $conditionalCipherPut];
        $bounded = static function (string $chunk) use ($consume, $limit, $flags): int {
            $length = strlen($chunk);
            if ($length > $limit - $flags->received) { $flags->overflow = true; return 0; }
            $flags->received += $length;
            // Whatever consume raises is this side's failure, even an exception that looks like a connection failure.
            try { $accepted = $consume($chunk); }
            catch (\Throwable $failure) { $flags->refused = true; throw $failure; }
            if ($accepted !== $length) { $flags->refused = true; }
            return $accepted;
        };
        $request = ['method' => $method, 'url' => 'https://' . $host . $path . ($queryString === '' ? '' : '?' . $queryString), 'headers' => $headers, 'body' => $body, 'bodyBytes' => $bodyBytes];
        return ['request' => $request, 'consume' => $bounded, 'flags' => $flags];
    }

    /** This side stopped the transfer: cURL reports that as a write error, and no number alongside it may make it look temporary. */
    private static function stoppedHere(\stdClass $flags, R2TransportException $failure): R2TransportException
    {
        if ($flags->overflow) { return new R2TransportException($failure->errno(), 1, 1, R2TransportException::OVERFLOW); }
        if ($flags->refused) { return new R2TransportException($failure->errno(), 1, 1, R2TransportException::LOCAL_WRITE); }
        return $failure;
    }

    private static function accepted(string $method, \stdClass $flags, $response, array $allowed): array
    {
        $status = is_array($response) ? ($response['status'] ?? null) : null;
        $validStatus = is_int($status) && $status >= 100 && $status <= 599;
        $reason = null;
        if ($flags->overflow) { $reason = 'response_limit'; }
        elseif ($flags->refused) { $reason = 'local_refusal'; }
        elseif (!$validStatus || !is_array($response['headers'] ?? null)) { $reason = 'invalid_response'; }
        if ($reason !== null) {
            error_log(sprintf('wrb-r2-response method=%s reason=%s status=%d', $method, $reason, $validStatus ? $status : 0));
            throw new RuntimeException('R2 request failed or exceeded its response limit.');
        }
        $normalized = [];
        foreach ($response['headers'] as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                error_log(sprintf('wrb-r2-response method=%s reason=invalid_headers status=%d', $method, $status));
                throw new RuntimeException('Invalid R2 response headers.');
            }
            $name = strtolower($name);
            if ($name === 'retry-after' && isset($normalized[$name])) { $normalized[$name] = ''; }
            else { $normalized[$name] = trim($value); }
        }
        if (!in_array($status, $allowed, true)) {
            error_log(sprintf('wrb-r2-response method=%s reason=http_status status=%d', $method, $status));
            throw new R2HttpException($status, $normalized['retry-after'] ?? null, $flags->conditionalCipherPut);
        }
        return ['status' => $status, 'headers' => $normalized];
    }

    private function curl(array $request, callable $consume): array
    {
        if (!function_exists('curl_init')) { throw new RuntimeException('R2 requires the PHP cURL extension.'); }
        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
            if ($this->curlHandle === false) { $this->curlHandle = null; throw new RuntimeException('Cannot initialize R2 transport.'); }
        }
        $curl = $this->curlHandle;
        curl_reset($curl);
        $received = (object) ['headers' => [], 'bytes' => 0];
        try {
            if (!curl_setopt_array($curl, self::curlOptions($request, $consume, $received))) { throw new RuntimeException('Cannot configure R2 transport.'); }
            if (curl_exec($curl) === false) { throw new R2TransportException(curl_errno($curl)); }
            return ['status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'headers' => $received->headers];
        } finally { self::releaseHandle($curl); }
    }

    /**
     * GET/HEAD use the specified read limit; PUT uses PARALLEL_WRITES. Each attempt is signed when it starts, and reads are
     * retried as request() retries them: a connection failure only, at most count(RETRY_DELAYS) + 1 attempts, a further
     * attempt only when its first attempt began RETRY_BUDGET_SECONDS or less ago, its reset first, and its delay before it.
     * Any other failure stops every read. Returns the responses in the order of $specs.
     */
    private function curlMany(array $specs, ?int $readLimit = null): array
    {
        if (!function_exists('curl_multi_init')) { throw new RuntimeException('R2 requires the PHP cURL extension.'); }
        $method = $specs[array_key_first($specs)]['method'];
        foreach ($specs as $spec) { if ($spec['method'] !== $method || !in_array($method, ['GET', 'HEAD', 'PUT'], true)) { throw new RuntimeException('Parallel R2 requests must share one method.'); } }
        $multi = curl_multi_init();
        $handles = []; $free = []; $active = []; $results = []; $attempts = []; $started = []; $due = [];
        $ready = array_keys($specs); $allowedAttempts = $method === 'PUT' ? 1 : count(self::RETRY_DELAYS) + 1;
        $parallelLimit = $method === 'PUT' ? self::PARALLEL_WRITES : ($readLimit ?? self::PARALLEL_READS);
        if (!in_array($parallelLimit,[self::PARALLEL_READS,self::BULK_PARALLEL_READS],true)) { throw new RuntimeException('Invalid parallel request limit.'); }
        try {
            while ($ready !== [] || $due !== [] || $active !== []) {
                $tick = ($this->ticks)();
                foreach ($due as $index => $at) { if ($tick >= $at) { $ready[] = $index; unset($due[$index]); } }
                while (count($active) < $parallelLimit && $ready !== []) {
                    $index = array_shift($ready); $spec = $specs[$index];
                    $attempts[$index] = ($attempts[$index] ?? 0) + 1;
                    if ($attempts[$index] === 1) { $started[$index] = ($this->clock)(); }
                    $prepared = $this->prepare($method, $spec['key'], [], $spec['body'] ?? null, $spec['bodyBytes'] ?? 0, $spec['payloadHash'] ?? hash('sha256', ''), $spec['headers'] ?? [], $spec['consume'], $spec['limit']);
                    if ($free === []) {
                        $handle = curl_init();
                        if ($handle === false) { throw new RuntimeException('Cannot initialize R2 transport.'); }
                        $handles[] = $handle;
                    } else { $handle = array_pop($free); }
                    $received = (object) ['headers' => [], 'bytes' => 0];
                    curl_reset($handle);
                    if (!curl_setopt_array($handle, self::curlOptions($prepared['request'], $prepared['consume'], $received)) || curl_multi_add_handle($multi, $handle) !== CURLM_OK) {
                        self::releaseHandle($handle); throw new RuntimeException('Cannot configure R2 transport.');
                    }
                    $active[] = ['index' => $index, 'handle' => $handle, 'flags' => $prepared['flags'], 'received' => $received];
                }
                if ($active === []) {
                    // Nothing is transferring, so wait for the earliest retry. Only the monotonic time read at the top of the
                    // loop makes a retry ready, so a short or interrupted wait is followed by another one.
                    if ($due !== []) {
                        $remaining = (int) ceil((min($due) - ($this->ticks)()) / 1000000000);
                        if ($remaining > 0) { ($this->wait)($remaining); }
                    }
                    continue;
                }
                try {
                    do { $code = curl_multi_exec($multi, $running); } while ($code === CURLM_CALL_MULTI_PERFORM);
                } catch (\Throwable $failure) {
                    error_log(sprintf('wrb-r2-transport method=%s errno=none local=yes', $method));
                    throw new RuntimeException('R2 transport failed.');
                }
                if ($code !== CURLM_OK) { throw new RuntimeException('R2 transport failed.'); }
                while (is_array($done = curl_multi_info_read($multi))) {
                    if (($done['msg'] ?? null) !== CURLMSG_DONE) { continue; }
                    $position = null;
                    foreach ($active as $key => $entry) { if ($entry['handle'] === $done['handle']) { $position = $key; break; } }
                    if ($position === null) { throw new RuntimeException('R2 transport failed.'); }
                    $entry = $active[$position]; unset($active[$position]);
                    curl_multi_remove_handle($multi, $entry['handle']);
                    $status = (int) curl_getinfo($entry['handle'], CURLINFO_HTTP_CODE);
                    self::releaseHandle($entry['handle']); $free[] = $entry['handle'];
                    $index = $entry['index'];
                    if ((int) $done['result'] === 0) {
                        $results[$index] = self::accepted($method, $entry['flags'], ['status' => $status, 'headers' => $entry['received']->headers], $specs[$index]['allowed']);
                        continue;
                    }
                    $failure = self::stoppedHere($entry['flags'], new R2TransportException((int) $done['result']));
                    $now = ($this->clock)();
                    $again = $failure->isTransient() && $attempts[$index] < $allowedAttempts && is_int($now) && is_int($started[$index]) && $now >= $started[$index] && $now - $started[$index] <= self::RETRY_BUDGET_SECONDS;
                    error_log(sprintf('wrb-r2-transport method=%s kind=%s errno=%d attempt=%d/%d retry=%s', $method, $failure->kind(), $failure->errno(), $attempts[$index], $allowedAttempts, $again ? 'yes' : 'no'));
                    if (!$again) { throw new R2TransportException($failure->errno(), $attempts[$index], $allowedAttempts, $failure->kind()); }
                    ($specs[$index]['reset'])();
                    $due[$index] = ($this->ticks)() + self::RETRY_DELAYS[$attempts[$index] - 1] * 1000000000;
                }
                if ($active !== [] && $running > 0 && curl_multi_select($multi, 1.0) === -1) { usleep(1000); }
            }
        } finally {
            foreach ($active as $entry) { curl_multi_remove_handle($multi, $entry['handle']); self::releaseHandle($entry['handle']); }
            foreach ($handles as $handle) { curl_close($handle); }
            curl_multi_close($multi);
        }
        if (count($results) !== count($specs)) { throw new RuntimeException('R2 transport failed.'); }
        $ordered = [];
        foreach (array_keys($specs) as $index) { $ordered[$index] = $results[$index]; }
        return $ordered;
    }

    private static function curlOptions(array $request, callable $consume, \stdClass $received): array
    {
        $httpHeaders = [];
        foreach ($request['headers'] as $name => $value) { $httpHeaders[] = $name . ': ' . $value; }
        $httpHeaders[] = 'Expect:';
        $options = [CURLOPT_URL => $request['url'], CURLOPT_CUSTOMREQUEST => $request['method'], CURLOPT_NOBODY => $request['method'] === 'HEAD',
            CURLOPT_HTTPHEADER => $httpHeaders, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 3600,
            CURLOPT_WRITEFUNCTION => static fn ($handle, string $chunk): int => $consume($chunk),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use ($received): int {
                $length = strlen($line); $received->bytes += $length;
                if ($received->bytes > 65536) { return 0; }
                if (preg_match('~^HTTP/~i', $line)) { $received->headers = []; }
                elseif (strpos($line, ':') !== false) {
                    [$name, $value] = explode(':', $line, 2); $name = strtolower(trim($name));
                    if (isset($received->headers[$name]) && $name === 'content-length') { return 0; }
                    if ($name === 'retry-after' && isset($received->headers[$name])) { $received->headers[$name] = ''; }
                    else { $received->headers[$name] = trim($value); }
                }
                return $length;
            }];
        if (in_array($request['method'], ['GET', 'HEAD'], true)) {
            // A stalled read otherwise holds the capture's lock and maintenance mode until the hour-long limit.
            $options[CURLOPT_LOW_SPEED_LIMIT] = 1024;
            $options[CURLOPT_LOW_SPEED_TIME] = self::STALL_SECONDS;
        }
        if (is_resource($request['body'])) {
            // A non-retried upload must not reuse a connection that the server may have closed while idle.
            $options[CURLOPT_FRESH_CONNECT] = true;
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $request['body'];
            $options[CURLOPT_INFILESIZE] = $request['bodyBytes'];
        }
        return $options;
    }

    /** PHP may retain callbacks across curl_reset; release their captured response state first. */
    private static function releaseHandle($curl): void
    {
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, static fn ($handle, string $chunk): int => strlen($chunk));
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, static fn ($handle, string $line): int => strlen($line));
        curl_reset($curl);
    }

    private function prefix(): string { return 'wordpress-r2/' . $this->unitId() . '/'; }

    private static function identifier(string $identifier): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $identifier)) { throw new RuntimeException('Invalid lowercase 256-bit identifier.'); }
    }

    private static function fields(array $value, array $expected): void
    {
        $actual = array_keys($value); sort($actual); sort($expected);
        if ($actual !== $expected) { throw new RuntimeException('Unexpected R2 record fields.'); }
    }

    private static function decode(string $body): array
    {
        try { $value = json_decode($body, true, 16, JSON_THROW_ON_ERROR); }
        catch (\Throwable $ignored) { throw new RuntimeException('Invalid R2 JSON record.'); }
        if (!is_array($value)) { throw new RuntimeException('Invalid R2 JSON object.'); }
        return $value;
    }
}
