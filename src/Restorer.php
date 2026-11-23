<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

final class Restorer
{
    private AgeProcess $age;
    private ObjectReader $reader;

    public function __construct(AgeProcess $age, ObjectReader $reader)
    {
        $this->age = $age;
        $this->reader = $reader;
    }

    public function restore(string $snapshotId, $identityStream, string $newDestination, ?RecoveryDatabase $database = null, ?string $newDatabase = null, ?callable $captureSummary = null, ?string $localMediaDryRunPrefix = null, ?callable $phaseListener = null, ?callable $progressListener = null): array
    {
        return $this->routeRecovery($snapshotId, $identityStream, $newDestination, $database, $newDatabase, false, $captureSummary, $localMediaDryRunPrefix, $phaseListener, $progressListener);
    }

    public function resume(string $snapshotId, $identityStream, string $newDestination, ?RecoveryDatabase $database = null, ?string $newDatabase = null, ?callable $captureSummary = null, ?string $localMediaDryRunPrefix = null): array
    {
        if ($database instanceof SqliteDatabaseClient) { throw new RuntimeException('SQLite recovery cannot be resumed; use a fresh destination.'); }
        return $this->routeRecovery($snapshotId, $identityStream, $newDestination, $database, $newDatabase, true, $captureSummary, $localMediaDryRunPrefix);
    }

    public function inspect(string $snapshotId, $identityStream): array
    {
        $receipt = $this->completed($snapshotId);
        $identity = $this->identity($identityStream);
        $workspace = realpath(sys_get_temp_dir()) . '/wrb-inspect-' . bin2hex(random_bytes(16));
        if (!mkdir($workspace, 0700)) {
            fclose($identity);
            throw new RuntimeException('Cannot create an inspection workspace.');
        }
        try {
            $manifest = $this->manifest($snapshotId, $receipt, $identity, $workspace);
            if ($manifest['version'] === 4) {
                rewind($identity);
                return (new V4Restorer($this->age, $this->reader))->inspect($manifest, $identity, $workspace);
            }
            $this->verifyReferences($manifest);
            return $this->captureSummary($manifest);
        } finally {
            fclose($identity);
            $this->removeOwnedTree($workspace);
        }
    }

    /** Decrypts the root manifest only: what a screen shows and binds before any recovery work. Referenced objects are not read. */
    public function describe(string $snapshotId, $identityStream): array
    {
        $receipt = ($this->reader instanceof DirectR2Repository || $this->reader instanceof LocalRepository)
            ? $this->reader->completionMetadata($snapshotId)['manifest'] : $this->completed($snapshotId);
        $identity = $this->identity($identityStream);
        $workspace = realpath(sys_get_temp_dir()) . '/wrb-describe-' . bin2hex(random_bytes(16));
        if (!mkdir($workspace, 0700)) { fclose($identity); throw new RuntimeException('Cannot create an inspection workspace.'); }
        try {
            $manifest = $this->manifest($snapshotId, $receipt, $identity, $workspace);
            return self::describeManifest($manifest, $receipt);
        } finally { fclose($identity); $this->removeOwnedTree($workspace); }
    }

    /** Publish only bounded, validated evidence from the decrypted root manifest. */
    private static function describeManifest(array $manifest, array $receipt): array
    {
        $summary = ['manifestVersion' => $manifest['version'], 'receiptSha256' => $receipt['sha256'], 'siteUrl' => $manifest['wordpress']['siteUrl'] ?? null];
        if ($manifest['version'] === 4) {
            V4Format::validateRoot($manifest);
            $summary += ['roots' => $manifest['roots'], 'counts' => $manifest['counts'], 'database' => ['size' => $manifest['database']['size'], 'tableCount' => $manifest['database']['tableCount']], 'capturedAt' => $manifest['wordpress']['evidence']['finishedAt'] ?? null];
            if (($manifest['wordpress']['consistencyMode'] ?? null) === 'online') {
                $evidence = $manifest['wordpress']['evidence'];
                $summary['consistency'] = ['mode' => 'online', 'snapshotAt' => $evidence['snapshotAt'], 'filesVerifiedAt' => $evidence['filesVerifiedAt']];
            }
        }
        return $summary;
    }

    public function exportDatabase(string $snapshotId, $identityStream, string $newSqlFile): array
    {
        FileSafety::assertNoLinks($newSqlFile);
        FileSafety::privateDirectory(dirname($newSqlFile));
        if (@lstat($newSqlFile) !== false) { throw new RuntimeException('The SQL export destination already exists.'); }
        $receipt = $this->completed($snapshotId);
        $identity = $this->identity($identityStream);
        $workspace = realpath(sys_get_temp_dir()) . '/wrb-sql-export-' . bin2hex(random_bytes(16));
        if (!mkdir($workspace, 0700)) { fclose($identity); throw new RuntimeException('Cannot create SQL export workspace.'); }
        try {
            $manifest = $this->manifest($snapshotId, $receipt, $identity, $workspace);
            if ($manifest['version'] === 4) {
                rewind($identity);
                return (new V4Restorer($this->age, $this->reader))->exportDatabase($manifest, $identity, $newSqlFile);
            }
            $this->reader->verify($manifest['database']['object']);
            $plain = $workspace . '/database.sql';
            $this->decrypt($manifest['database']['object'], $plain, $identity, $workspace);
            $this->verifyContent($plain, $manifest['database']);
            if (!link($plain, $newSqlFile)) { throw new RuntimeException('Cannot publish SQL export without replacement.'); }
            return ['snapshotId' => $snapshotId, 'sqlFile' => $newSqlFile, 'bytes' => $manifest['database']['size'], 'sha256' => $manifest['database']['sha256'], 'databaseImported' => false];
        } finally { fclose($identity); $this->removeOwnedTree($workspace); }
    }

    public function discard(string $snapshotId, string $newDestination): void
    {
        $v4 = new V4Restorer($this->age, $this->reader);
        if ($v4->pending($snapshotId, $newDestination)) { $v4->discard($snapshotId, $newDestination); return; }
        $archive = new ArchiveRestorer($this->age, $this->reader);
        if ($archive->pending($snapshotId, $newDestination)) { $archive->discard($snapshotId, $newDestination); return; }
        $workspace = $this->workspace($snapshotId, $newDestination);
        FileSafety::privateDirectory($workspace);
        $this->assertJobOwner($workspace, $snapshotId, $newDestination);
        $lock = $this->lock($workspace);
        try {
            $this->removeOwnedTree($workspace);
        } finally {
            fclose($lock);
        }
    }

    public function rebuildIndex($identityStream, array $currentRecipients, string $newPrivateIndexDirectory): void
    {
        $keySetId = Manifest::keySetId($currentRecipients);
        $directory = FileSafety::privateDirectory($newPrivateIndexDirectory);
        if (array_diff(scandir($directory), ['.', '..']) !== []) {
            throw new RuntimeException('Index rebuild requires an empty private directory.');
        }
        $identity = $this->identity($identityStream);
        try {
            if ((new V4IndexRebuilder($this->age, $this->reader))->rebuild($identity, $currentRecipients, $directory)) { fclose($identity); return; }
            rewind($identity);
        } catch (\Throwable $error) { fclose($identity); throw $error; }
        $workspace = $directory . '/.rebuild-' . bin2hex(random_bytes(16));
        if (!mkdir($workspace, 0700)) {
            fclose($identity);
            throw new RuntimeException('Cannot create index verification workspace.');
        }
        try {
            $verified = [];
            foreach ($this->reader->listCompleted() as $record) {
                $manifest = $this->manifest($record['snapshotId'], $record['manifest'], $identity, $workspace);
                if ($manifest['version'] === 4) { throw new RuntimeException('Repository generations changed during index rebuild.'); }
                foreach ($manifest['files'] as $entry) {
                    if ($entry['type'] !== 'file') {
                        continue;
                    }
                    $this->reader->verify($entry['object']);
                    if ($manifest['keySetId'] === $keySetId) {
                        $this->verifyPlainObject($entry['object'], $entry, $identity, $workspace . '/object');
                        $verified[$entry['object']['key']] = $this->indexEntry($keySetId, $entry, $entry['object']);
                    }
                }
                $this->reader->verify($manifest['database']['object']);
            }
            foreach ($this->reader->listFileObjects($keySetId) as $receipt) {
                if (isset($verified[$receipt['key']])) {
                    continue;
                }
                $this->decrypt($receipt, $workspace . '/object', $identity, $workspace);
                $content = $this->content($workspace . '/object');
                unlink($workspace . '/object');
                $verified[$receipt['key']] = $this->indexEntry($keySetId, $content, $receipt);
            }
            $this->removeOwnedTree($workspace);
            DedupIndex::rebuild($directory, $this->reader, array_values($verified));
        } finally {
            fclose($identity);
            if (@lstat($workspace) !== false) {
                $this->removeOwnedTree($workspace);
            }
        }
    }

    private function routeRecovery(string $snapshotId, $input, string $destination, ?RecoveryDatabase $database, ?string $schema, bool $resume, ?callable $summary, ?string $localMediaDryRunPrefix, ?callable $phaseListener = null, ?callable $progressListener = null): array
    {
        $identity = $this->identity($input);
        $workspace = dirname($this->workspace($snapshotId, $destination)) . '/.wrb-route-' . bin2hex(random_bytes(16));
        if (!mkdir($workspace, 0700)) { fclose($identity); throw new RuntimeException('Cannot create recovery routing workspace.'); }
        try {
            $receipt = $this->completed($snapshotId);
            $manifest = $this->manifest($snapshotId, $receipt, $identity, $workspace);
            rewind($identity);
            if ($manifest['version'] === 4) {
                return (new V4Restorer($this->age, $this->reader))->recover($manifest, $receipt, $identity, $destination, $database, $schema, $resume, $summary, $localMediaDryRunPrefix, $phaseListener, $progressListener);
            }
            if ($localMediaDryRunPrefix !== null) { throw new RuntimeException('Local media dry run requires a version 4 snapshot.'); }
            if ($database !== null && !($database instanceof MysqlClient)) { throw new RuntimeException('SQLite recovery requires a version 4 snapshot.'); }
            if (isset($manifest['wordpress']['archives'])) {
                $restorer = new ArchiveRestorer($this->age, $this->reader);
                return $resume ? $restorer->resume($snapshotId, $identity, $destination, $database, $schema, $summary)
                    : $restorer->restore($snapshotId, $identity, $destination, $database, $schema, $summary);
            }
            return $this->recover($snapshotId, $identity, $destination, $database, $schema, $resume, $summary, $manifest, $receipt);
        } finally { fclose($identity); $this->removeOwnedTree($workspace); }
    }

    private function recover(string $snapshotId, $identityStream, string $destination, ?MysqlClient $database, ?string $schema, bool $resume, ?callable $captureSummary, ?array $loadedManifest = null, ?array $loadedReceipt = null): array
    {
        if (($database === null) !== ($schema === null)) {
            throw new RuntimeException('The database client and new database must be supplied together.');
        }
        $workspace = $this->workspace($snapshotId, $destination);
        if (!$resume && @lstat($destination) !== false) {
            throw new RuntimeException('Recovery requires a new destination.');
        }
        $receipt = $loadedReceipt ?? $this->completed($snapshotId);
        $identity = $this->identity($identityStream);
        $lock = null;
        $owned = false;
        $preserveWorkspace = $resume;
        try {
            if ($resume) {
                FileSafety::privateDirectory($workspace);
                $this->assertJobOwner($workspace, $snapshotId, $destination);
            } elseif (!@mkdir($workspace, 0700)) {
                throw new RuntimeException('Recovery workspace exists; use resume or explicit discard.');
            } else {
                $owned = true;
                $marker = $workspace . '/owner';
                $contents = $this->jobOwner($snapshotId, $destination);
                if (file_put_contents($marker, $contents) !== strlen($contents) || !chmod($marker, 0600)) {
                    throw new RuntimeException('Cannot persist recovery job ownership.');
                }
            }
            $lock = $this->lock($workspace);
            $owned = true;
            if ($resume) {
                $state = $this->readState($workspace, $snapshotId, $destination);
                if ($state['manifestSha256'] !== $receipt['sha256']) {
                    throw new RuntimeException('The recovery manifest changed.');
                }
            } else {
                $state = ['snapshotId' => $snapshotId, 'destination' => $destination, 'manifestSha256' => $receipt['sha256'], 'completed' => [], 'publication' => null, 'pending' => null];
                $this->saveState($workspace, $state);
                if (!mkdir($workspace . '/files', 0700)) {
                    throw new RuntimeException('Cannot create recovery file workspace.');
                }
            }
            if ($state['publication'] !== null) {
                $preserveWorkspace = true;
                $this->assertPublicationOwner($workspace, $destination, $state['publication']);
            } elseif (@lstat($destination) !== false) {
                throw new RuntimeException('The existing recovery destination has no publication ownership record; use a new destination.');
            }
            $manifest = $loadedManifest ?? $this->manifest($snapshotId, $receipt, $identity, $workspace);
            $this->verifyReferences($manifest);
            if ($captureSummary !== null) {
                $captureSummary($this->captureSummary($manifest));
            }
            $units = [];
            foreach ($manifest['files'] as $entry) {
                if ($entry['type'] === 'file') {
                    $units['file:' . $entry['path']] = $entry;
                }
            }
            $units['database'] = $manifest['database'];
            $this->verifyProgress($workspace, $state, $units, $manifest['files']);
            if ($state['pending'] !== null) {
                $this->recoverUnit($workspace, $state, $state['pending'], $units[$state['pending']], $identity);
            }
            foreach ($manifest['files'] as $entry) {
                $this->directories($workspace . '/files', $entry['type'] === 'directory' ? $entry['path'] : dirname($entry['path']));
                if ($entry['type'] === 'file' && !isset($state['completed']['file:' . $entry['path']])) {
                    $this->recoverUnit($workspace, $state, 'file:' . $entry['path'], $entry, $identity);
                }
            }
            if (!isset($state['completed']['database'])) {
                $this->recoverUnit($workspace, $state, 'database', $manifest['database'], $identity);
            }
            if ($state['publication'] !== null) {
                $this->verifyPublication($workspace, $destination, $state['publication'], $manifest['files']);
            }
            if ($database !== null) {
                try {
                    $expectedEvents = $manifest['version'] === 2
                        ? array_column($manifest['captureReport']['database']['definitions']['events'], 'name')
                        : ($manifest['version'] === 3 ? $manifest['wordpress']['evidence']['events'] : null);
                    $database->importIntoEmpty($schema, $workspace . '/database.sql', $expectedEvents);
                } catch (\Throwable $error) {
                    $preserveWorkspace = true;
                    throw $error;
                }
            }
            if ($state['publication'] === null) {
                FileSafety::privateDirectory(dirname($destination));
                if (!@mkdir($destination, 0700)) {
                    throw new RuntimeException('The recovery destination was created by another operation.');
                }
                $preserveWorkspace = true;
                $stat = lstat($destination);
                do {
                    $marker = '.wrb-publication-' . bin2hex(random_bytes(16));
                } while (in_array($marker, array_column($manifest['files'], 'path'), true));
                if (!link($workspace . '/owner', $destination . '/' . $marker)) {
                    throw new RuntimeException('Cannot create publication ownership proof; use a new destination.');
                }
                $state['publication'] = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'marker' => $marker];
                $this->saveState($workspace, $state);
            }
            foreach ($manifest['files'] as $entry) {
                $this->assertPublicationOwner($workspace, $destination, $state['publication']);
                $path = $destination . '/' . $entry['path'];
                $this->directories($destination, $entry['type'] === 'directory' ? $entry['path'] : dirname($entry['path']));
                if ($entry['type'] === 'file') {
                    if (@lstat($path) === false && !@link($workspace . '/files/' . $entry['path'], $path)) {
                        throw new RuntimeException('Cannot publish the verified recovery without replacement.');
                    }
                    $this->verifyContent($path, $entry);
                }
            }
            $this->verifyPublication($workspace, $destination, $state['publication'], $manifest['files']);
            if (!unlink($destination . '/' . $state['publication']['marker'])) {
                throw new RuntimeException('Cannot finish the recovery publication.');
            }
            $preserveWorkspace = false;
            $files = 0;
            $bytes = 0;
            foreach ($manifest['files'] as $entry) {
                if ($entry['type'] === 'file') {
                    $files++;
                    $bytes += $entry['size'];
                }
            }
            return ['files' => $files, 'bytes' => $bytes, 'databaseImported' => $database !== null];
        } finally {
            fclose($identity);
            if ($owned && !$preserveWorkspace && @lstat($workspace) !== false) {
                $this->removeOwnedTree($workspace);
            }
            if (is_resource($lock)) {
                fclose($lock);
            }
        }
    }

    private function verifyReferences(array $manifest): void
    {
        foreach ($manifest['wordpress']['archives'] ?? [] as $archive) { $this->reader->verify($archive['object']); }
        foreach ($manifest['files'] as $entry) {
            if ($entry['type'] === 'file') {
                $this->reader->verify($entry['object']);
            }
        }
        $this->reader->verify($manifest['database']['object']);
    }

    private function captureSummary(array $manifest): array
    {
        $capture = null;
        if ($manifest['version'] === 2) {
            $report = $manifest['captureReport'];
            $engines = array_values(array_unique(array_filter(array_column($report['database']['tables'], 'engine'), static fn ($engine): bool => $engine !== null)));
            sort($engines, SORT_STRING);
            $capture = [
                'method' => $report['method'], 'startedAt' => $report['startedAt'],
                'fixedAt' => $report['fixedAt'], 'sealedAt' => $report['sealedAt'],
                'file' => ['pausedAt' => $report['file']['pausedAt'], 'releasedAt' => $report['file']['releasedAt'],
                    'fileCount' => count(array_filter($manifest['files'], static fn (array $entry): bool => $entry['type'] === 'file')),
                    'directoryCount' => count(array_filter($manifest['files'], static fn (array $entry): bool => $entry['type'] === 'directory'))],
                'database' => ['lockedAt' => $report['database']['lockedAt'], 'releasedAt' => $report['database']['releasedAt'],
                    'serverVersion' => $report['database']['serverVersion'], 'storageEngines' => $engines,
                    'tableCount' => count($report['database']['tables']),
                    'definitionCount' => array_sum(array_map('count', $report['database']['definitions']))],
            ];
        }
        if ($manifest['version'] === 3) {
            $capture = ['method' => 'wordpress-live-filtered', 'siteUrl' => $manifest['wordpress']['siteUrl'],
                'policySha256' => $manifest['wordpress']['policySha256'], 'evidence' => $manifest['wordpress']['evidence'],
                'fileCount' => isset($manifest['wordpress']['archives']) ? array_sum(array_column($manifest['wordpress']['archives'], 'fileCount'))
                    : count(array_filter($manifest['files'], static fn (array $entry): bool => $entry['type'] === 'file'))];
        }
        return ['manifestVersion' => $manifest['version'], 'captureEvidence' => $capture === null ? 'unknown' : 'reported',
            'capture' => $capture,
            'verification' => ['manifest' => true, 'referencedCiphertexts' => true, 'payloadDecrypted' => false, 'databaseImported' => false]];
    }

    private function completed(string $snapshotId): array
    {
        return $this->reader->completed($snapshotId)['manifest'];
    }

    private function manifest(string $snapshotId, array $receipt, $identity, string $workspace): array
    {
        $path = $workspace . '/manifest-' . bin2hex(random_bytes(8)) . '.json';
        try {
            $this->decrypt($receipt, $path, $identity, $workspace);
            if (filesize($path) > Manifest::MAX_BYTES) {
                throw new RuntimeException('The recovery manifest exceeds its size limit.');
            }
            $manifest = Manifest::fromArray(json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR))->toArray();
            if ($manifest['unitId'] !== $this->reader->unitId() || $manifest['snapshotId'] !== $snapshotId) {
                throw new RuntimeException('The manifest belongs to another snapshot or backup unit.');
            }
            return $manifest;
        } finally {
            if (@lstat($path) !== false) {
                unlink($path);
            }
        }
    }

    private function identity($input)
    {
        if (!is_resource($input) || get_resource_type($input) !== 'stream') {
            throw new RuntimeException('A readable identity stream is required.');
        }
        $memory = fopen('php://memory', 'w+b');
        try {
            $bytes = stream_copy_to_stream($input, $memory, 65537);
            if ($bytes === false || $bytes === 0 || $bytes > 65536) {
                throw new RuntimeException('The identity stream must contain at most 64 KiB.');
            }
            rewind($memory);
            return $memory;
        } catch (\Throwable $error) {
            fclose($memory);
            throw $error;
        }
    }

    private function decrypt(array $receipt, string $destination, $identity, string $workspace): void
    {
        if (!rewind($identity)) {
            throw new RuntimeException('Cannot rewind the in-memory identity stream.');
        }
        $cipher = $workspace . '/.cipher-' . bin2hex(random_bytes(16));
        try {
            $this->reader->download($receipt, $cipher);
            $this->age->decrypt($cipher, $destination, $identity);
        } finally {
            if (@lstat($cipher) !== false) { unlink($cipher); }
        }
    }

    private function verifyPlainObject(array $receipt, array $expected, $identity, string $destination): void
    {
        try {
            $this->decrypt($receipt, $destination, $identity, dirname($destination));
            $this->verifyContent($destination, $expected);
        } finally {
            if (@lstat($destination) !== false) {
                unlink($destination);
            }
        }
    }

    private function content(string $path): array
    {
        FileSafety::assertRegularFile($path);
        clearstatcache(true, $path);
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        if ($size === false || $size > Manifest::MAX_RESTORED_BYTES || $hash === false) {
            throw new RuntimeException('Cannot verify restored content.');
        }
        return ['size' => $size, 'sha256' => $hash];
    }

    private function verifyContent(string $path, array $expected): void
    {
        $actual = $this->content($path);
        if ($actual['size'] !== $expected['size'] || !hash_equals($expected['sha256'], $actual['sha256'])) {
            throw new RuntimeException('Restored content does not match the manifest.');
        }
    }

    private function workspace(string $snapshotId, string $destination): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $snapshotId)) {
            throw new RuntimeException('Invalid snapshot identifier.');
        }
        FileSafety::assertNoLinks($destination);
        FileSafety::privateDirectory(dirname($destination));
        return dirname($destination) . '/.wrb-restore-' . hash('sha256', $snapshotId . "\0" . $destination);
    }

    private function lock(string $workspace)
    {
        FileSafety::privateDirectory($workspace);
        $path = $workspace . '/job.lock';
        FileSafety::assertNoLinks($path);
        if (@lstat($path) !== false) {
            FileSafety::assertRegularFile($path);
        }
        $lock = fopen($path, 'c+b');
        if ($lock === false || !chmod($path, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('The recovery job is already running or cannot be locked.');
        }
        return $lock;
    }

    private function jobOwner(string $snapshotId, string $destination): string
    {
        return 'wordpress-r2-backup recovery job v1 ' . hash('sha256', $this->reader->unitId() . "\0" . $snapshotId . "\0" . $destination) . "\n";
    }

    private function assertJobOwner(string $workspace, string $snapshotId, string $destination): void
    {
        $marker = $workspace . '/owner';
        FileSafety::assertRegularFile($marker);
        if (filesize($marker) > 256 || file_get_contents($marker) !== $this->jobOwner($snapshotId, $destination)) {
            throw new RuntimeException('The recovery workspace does not belong to this job.');
        }
    }

    private function saveState(string $workspace, array $state): void
    {
        $path = $workspace . '/progress.json';
        $temporary = $workspace . '/.progress-' . bin2hex(random_bytes(8));
        $stream = fopen($temporary, 'xb');
        try {
            $json = json_encode(['data' => $state, 'checksum' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
            if (!chmod($temporary, 0600) || fwrite($stream, $json) !== strlen($json) || !fflush($stream) || !\WordPressR2Backup\FileSafety::sync($stream)
                || !rename($temporary, $path)) {
                throw new RuntimeException('Cannot persist recovery progress.');
            }
        } finally {
            fclose($stream);
            if (@lstat($temporary) !== false) {
                unlink($temporary);
            }
        }
    }

    private function readState(string $workspace, string $snapshotId, string $destination): array
    {
        $path = $workspace . '/progress.json';
        FileSafety::assertRegularFile($path);
        if (filesize($path) > Manifest::MAX_BYTES) {
            throw new RuntimeException('Recovery progress exceeds its size limit.');
        }
        $record = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $state = $record['data'] ?? null;
        if (!is_array($record) || count($record) !== 2 || !is_array($state)
            || !is_string($record['checksum'] ?? null)
            || !hash_equals(hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)), $record['checksum'])) {
            throw new RuntimeException('Recovery progress checksum does not match.');
        }
        if (!is_array($state) || !in_array(count($state), [5, 6], true) || ($state['snapshotId'] ?? null) !== $snapshotId
            || ($state['destination'] ?? null) !== $destination || !preg_match('/^[a-f0-9]{64}$/D', $state['manifestSha256'] ?? '')
            || !is_array($state['completed'] ?? null) || !array_key_exists('publication', $state)
            || (count($state) === 6 && (!array_key_exists('pending', $state) || ($state['pending'] !== null && !is_string($state['pending']))))
            || (count($state) === 5 && array_key_exists('pending', $state))
            || ($state['publication'] !== null && (!is_array($state['publication']) || count($state['publication']) !== 3
                || !is_int($state['publication']['dev'] ?? null) || !is_int($state['publication']['ino'] ?? null)
                || !is_string($state['publication']['marker'] ?? null)
                || !preg_match('/^\\.wrb-publication-[a-f0-9]{32}$/D', $state['publication']['marker'])))) {
            throw new RuntimeException('Recovery progress does not match this job.');
        }
        $state['pending'] ??= null;
        return $state;
    }

    private function verifyProgress(string $workspace, array $state, array $units, array $entries): void
    {
        FileSafety::privateDirectory($workspace . '/files');
        $this->verifyPendingStage($workspace, $state, $units);
        $allowed = [];
        foreach ($entries as $entry) {
            $path = $entry['path'];
            $allowed[$path] = $entry['type'];
            while (($path = dirname($path)) !== '.') {
                $allowed[$path] = 'directory';
            }
        }
        foreach ($state['completed'] as $key => $content) {
            if (!isset($units[$key]) || !is_array($content) || $content !== ['size' => $units[$key]['size'], 'sha256' => $units[$key]['sha256']]) {
                throw new RuntimeException('Recovery progress contains changed or unknown units.');
            }
            $path = $key === 'database' ? $workspace . '/database.sql' : $workspace . '/files/' . substr($key, 5);
            $this->verifyContent($path, $content);
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspace . '/files', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($workspace . '/files/'));
            if (!isset($allowed[$path]) || $file->isLink() || ($allowed[$path] === 'directory' ? !$file->isDir() : !$file->isFile())
                || ($file->isFile() && !isset($state['completed']['file:' . $path]) && $state['pending'] !== 'file:' . $path)) {
                throw new RuntimeException('The recovery workspace contains changed or unrecorded content.');
            }
            if ($file->isFile() && !isset($state['completed']['file:' . $path])) {
                $this->verifyPendingLink($workspace, $file->getPathname());
            }
        }
        if (@lstat($workspace . '/database.sql') !== false && !isset($state['completed']['database'])) {
            if ($state['pending'] !== 'database') {
                throw new RuntimeException('The recovery workspace contains an unrecorded database.');
            }
            $this->verifyPendingLink($workspace, $workspace . '/database.sql');
        }
    }

    private function verifyPendingStage(string $workspace, array $state, array $units): void
    {
        $stage = $workspace . '/.pending';
        if ($state['pending'] !== null && (!isset($units[$state['pending']]) || $state['publication'] !== null)) {
            throw new RuntimeException('Recovery progress contains an unknown pending unit.');
        }
        if (@lstat($stage) === false) {
            return;
        }
        if ($state['pending'] === null) {
            throw new RuntimeException('The recovery workspace contains an unowned pending stage.');
        }
        FileSafety::privateDirectory($stage);
        foreach (array_diff(scandir($stage), ['.', '..']) as $name) {
            if ($name !== 'payload' && !preg_match('/^\.(?:cipher|wrb-age|download)-[a-f0-9]{32}$/D', $name)) {
                throw new RuntimeException('The pending recovery stage contains unknown content.');
            }
            $path = $stage . '/' . $name;
            FileSafety::assertRegularFile($path);
            $stat = lstat($path);
            if (($stat['mode'] & 0777) !== 0600 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
                throw new RuntimeException('The pending recovery stage contains unowned content.');
            }
            if ($name === 'payload') {
                $this->verifyContent($path, $units[$state['pending']]);
                $target = $state['pending'] === 'database' ? $workspace . '/database.sql' : $workspace . '/files/' . substr($state['pending'], 5);
                if (@lstat($target) !== false) {
                    $this->verifyPendingLink($workspace, $target);
                }
            }
        }
    }

    private function verifyPendingLink(string $workspace, string $target): void
    {
        $payload = $workspace . '/.pending/payload';
        FileSafety::assertRegularFile($payload);
        FileSafety::assertRegularFile($target);
        $source = lstat($payload);
        $linked = lstat($target);
        if ($source['dev'] !== $linked['dev'] || $source['ino'] !== $linked['ino']) {
            throw new RuntimeException('The unrecorded recovery file is not owned by the pending unit.');
        }
    }

    private function recoverUnit(string $workspace, array &$state, string $key, array $entry, $identity): void
    {
        $stage = $workspace . '/.pending';
        if ($state['pending'] === null) {
            if (@lstat($stage) !== false) {
                throw new RuntimeException('The recovery workspace contains an unowned pending stage.');
            }
            $state['pending'] = $key;
            $this->saveState($workspace, $state);
        }
        if (!isset($state['completed'][$key])) {
            $payload = $stage . '/payload';
            if (@lstat($payload) === false) {
                $this->removeOwnedTree($stage);
                if (!mkdir($stage, 0700)) {
                    throw new RuntimeException('Cannot create the pending recovery stage.');
                }
                $this->decrypt($entry['object'], $payload, $identity, $stage);
            }
            $this->verifyContent($payload, $entry);
            $target = $key === 'database' ? $workspace . '/database.sql' : $workspace . '/files/' . substr($key, 5);
            if (@lstat($target) === false && !@link($payload, $target)) {
                throw new RuntimeException('Cannot confirm the recovered unit without replacement.');
            }
            $this->verifyPendingLink($workspace, $target);
            $state['completed'][$key] = ['size' => $entry['size'], 'sha256' => $entry['sha256']];
            $this->saveState($workspace, $state);
        }
        $this->removeOwnedTree($stage);
        $state['pending'] = null;
        $this->saveState($workspace, $state);
    }

    private function assertPublicationOwner(string $workspace, string $destination, array $publication): void
    {
        FileSafety::privateDirectory($destination);
        $stat = lstat($destination);
        $marker = $destination . '/' . $publication['marker'];
        FileSafety::assertRegularFile($marker);
        $proof = lstat($marker);
        $owner = lstat($workspace . '/owner');
        if ($stat['dev'] !== $publication['dev'] || $stat['ino'] !== $publication['ino']
            || $proof['dev'] !== $owner['dev'] || $proof['ino'] !== $owner['ino']) {
            throw new RuntimeException('The recovery destination no longer belongs to this publication.');
        }
    }

    private function verifyPublication(string $workspace, string $destination, array $publication, array $entries): void
    {
        $this->assertPublicationOwner($workspace, $destination, $publication);
        $allowed = [];
        foreach ($entries as $entry) {
            $path = $entry['path'];
            $allowed[$path] = $entry;
            while (($path = dirname($path)) !== '.') {
                $allowed[$path] ??= ['type' => 'directory'];
            }
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($destination, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($destination . '/'));
            if ($path === $publication['marker']) {
                continue;
            }
            if (!isset($allowed[$path]) || $file->isLink()
                || ($allowed[$path]['type'] === 'directory' ? !$file->isDir() : !$file->isFile())) {
                throw new RuntimeException('The recovery destination contains changed or unknown content.');
            }
            if ($allowed[$path]['type'] === 'file') {
                $this->verifyContent($file->getPathname(), $allowed[$path]);
            }
        }
    }

    private function directories(string $root, string $relative): void
    {
        if ($relative === '.') {
            return;
        }
        foreach (explode('/', $relative) as $part) {
            $root .= '/' . $part;
            FileSafety::createPrivateDirectory($root);
        }
    }

    private function removeOwnedTree(string $path): void
    {
        $stat = @lstat($path);
        if ($stat === false) {
            return;
        }
        if (($stat['mode'] & 0170000) === 0040000) {
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                $this->removeOwnedTree($path . '/' . $entry);
            }
            if (!rmdir($path)) {
                throw new RuntimeException('Cannot remove an owned recovery directory.');
            }
        } elseif (!unlink($path)) {
            throw new RuntimeException('Cannot remove an owned recovery file.');
        }
    }

    private function indexEntry(string $keySetId, array $content, array $receipt): array
    {
        return ['keySetId' => $keySetId, 'plainBytes' => $content['size'], 'plainSha256' => $content['sha256'], 'receipt' => $receipt];
    }

}
