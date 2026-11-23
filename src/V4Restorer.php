<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

final class V4Restorer
{
    /** @var callable|null */
    private $phaseListener = null;
    private AgeProcess $age;
    private ObjectReader $reader;

    public function __construct(AgeProcess $age, ObjectReader $reader)
    {
        $this->age = $age;
        $this->reader = $reader;
    }

    public function recover(array $manifest, array $receipt, $input, string $destination, ?RecoveryDatabase $database, ?string $schema, bool $resume, ?callable $summary = null, ?string $localMediaDryRunPrefix = null, ?callable $phaseListener = null, ?callable $progressListener = null): array
    {
        if ($resume && $database instanceof SqliteDatabaseClient) { throw new RuntimeException('SQLite recovery cannot be resumed; use a fresh destination.'); }
        $this->phaseListener = $phaseListener;
        $this->manifest($manifest);
        V4Format::validateReceipt($receipt);
        if ($receipt['key'] !== 'manifests/' . $manifest['snapshotId'] . '.age') { throw new RuntimeException('V4 root receipt belongs to another snapshot.'); }
        if (($database === null) !== ($schema === null)) { throw new RuntimeException('Supply the database client and destination schema together.'); }
        if ($localMediaDryRunPrefix !== null) {
            if (preg_match('/\Aroots\/(r[0-9]{4})\/wp-content\/uploads\z/D', $localMediaDryRunPrefix, $match) !== 1) { throw new RuntimeException('Local media dry run requires the exact standard uploads prefix.'); }
            $selected = array_values(array_filter($manifest['roots'], static function (array $root) use ($match): bool { return $root['name'] === $match[1] && $root['type'] === 'directory'; }));
            if (count($selected) !== 1) { throw new RuntimeException('The local media root does not match a saved directory root.'); }
        }
        $workspace = $this->workspace($manifest['snapshotId'], $destination);
        $owner = $this->owner($manifest['snapshotId'], $destination);
        if ($resume) { $this->assertOwner($workspace, $owner); }
        else {
            if (@lstat($destination) !== false || @lstat($workspace) !== false) { throw new RuntimeException('V4 recovery requires a new destination; use resume for an owned job.'); }
            if (!mkdir($workspace, 0700)) { throw new RuntimeException('Cannot create v4 recovery workspace.'); }
            $this->writeNew($workspace . '/owner', $owner);
        }
        $lock = $this->lock($workspace);
        $identity = null;
        $scan = null;
        $scanIdentity = null;
        $plan = null;
        try {
            $binding = ['receipt' => ['key' => $receipt['key'], 'bytes' => $receipt['bytes'], 'sha256' => $receipt['sha256']], 'rootSha256' => hash('sha256', V4Format::json($manifest)), 'schema' => $schema];
            if ($database instanceof SqliteDatabaseClient) { $binding['sqliteFile'] = $database->path(); }
            if ($localMediaDryRunPrefix !== null) { $binding['publication'] = ['mode' => 'local-system-media-dry-run', 'mediaPrefix' => $localMediaDryRunPrefix]; }
            if ($resume) {
                $state = $this->readJson($workspace . '/state.json');
                if (($state['binding'] ?? null) !== $binding) { throw new RuntimeException('V4 resume requires the same root receipt, snapshot and database destination.'); }
                $ledger = MetadataMap::open($workspace . '/publication');
            } else {
                $state = ['binding' => $binding, 'destination' => null, 'database' => 'pending'];
                $ledger = $database instanceof SqliteDatabaseClient ? MetadataMap::createProcessLocal($workspace . '/publication') : MetadataMap::create($workspace . '/publication');
                $this->saveState($workspace, $state);
            }
            if ($state['destination'] !== null) {
                $this->publicationOwner($workspace, $destination, $state['destination']);
                $this->verifyTree($destination, '', $ledger, $state['destination']['marker']);
            } elseif (@lstat($destination) !== false) { throw new RuntimeException('Existing destination has no v4 ownership proof.'); }
            $identity = $this->identity($input);
            $scan = $workspace . '/scan-' . bin2hex(random_bytes(12));
            if (!mkdir($scan, 0700)) { throw new RuntimeException('Cannot create v4 validation workspace.'); }
            $scanIdentity = $this->statIdentity($scan, 'directory');
            $backend = getenv('WRB_RECOVERY_METADATA_BACKEND');
            $plan = RecoveryMetadataMap::create($scan . '/metadata', $backend === false || $backend === '' ? 'auto' : $backend);
            $state['metadataBackend'] = $plan->backend(); $state['phase'] = 'prepare'; $this->saveState($workspace, $state);
            $this->prepare($manifest, $identity, $scan, true, $plan, $progressListener);
            $mediaDryRun = $localMediaDryRunPrefix === null ? null : $this->mediaDryRun($localMediaDryRunPrefix, $plan);
            if ($summary !== null) {
                $preview = $this->summary($manifest, true);
                if ($mediaDryRun !== null) { $preview['restoreMode'] = 'local-system-media-dry-run'; $preview['mediaDryRun'] = $mediaDryRun; }
                $summary($preview); $plan->seal();
            }
            if ($database !== null) {
                if (!method_exists($database, 'verifyV4Database')) { throw new RuntimeException('V4 database value, definition and foreign-key verification is unavailable.'); }
                if ($state['database'] === 'attempted') { throw new RuntimeException('Interrupted database import requires a fresh empty schema and a new recovery destination.'); }
                if ($state['database'] === 'pending') {
                    FileSafety::assertRegularFile($scan . '/database.sql');
                    if (filesize($scan . '/database.sql') !== $manifest['database']['size'] || !hash_equals($manifest['database']['sha256'], hash_file('sha256', $scan . '/database.sql'))) { throw new RuntimeException('The verified database input changed before import.'); }
                    $state['database'] = 'attempted'; $state['phase'] = 'database-import'; $this->saveState($workspace, $state);
                    $database->importIntoEmpty($schema, $scan . '/database.sql', $manifest['database']['events']);
                    $state['database'] = 'imported'; $state['phase'] = 'database-verify'; $this->saveState($workspace, $state);
                }
                $database->verifyV4Database($schema, $this->tables($plan), $manifest['database']['environment']);
                $state['database'] = 'verified'; $this->saveState($workspace, $state);
                $plan->seal();
            }
            $state['phase'] = 'files-publish'; $this->saveState($workspace, $state);
            if ($state['destination'] === null) {
                if (!mkdir($destination, 0700)) { throw new RuntimeException('Cannot create a new v4 recovery destination.'); }
                $marker = '.wrb-publication-' . bin2hex(random_bytes(16));
                if (!link($workspace . '/owner', $destination . '/' . $marker)) { throw new RuntimeException('Cannot publish v4 ownership marker.'); }
                $state['destination'] = $this->statIdentity($destination, 'directory') + ['marker' => $marker];
                $ledger->put('path:', $state['destination']);
                $this->saveState($workspace, $state);
            }
            $this->directory($destination, 'roots', $ledger);
            foreach ($manifest['roots'] as $root) { $this->directory($destination, 'roots/' . $root['name'], $ledger); }
            $this->publishFiles($manifest, $identity, $scan, $destination, $plan, $ledger, $localMediaDryRunPrefix);
            $this->publishLinks($destination, $plan, $ledger, $localMediaDryRunPrefix);
            $state['phase'] = 'files-verify'; $this->saveState($workspace, $state);
            $this->verifyTree($destination, '', $ledger, $state['destination']['marker']);
            $state['phase'] = 'permissions-verify'; $this->saveState($workspace, $state);
            $this->finalizeModes($destination, '', $plan, $ledger, $state['destination']['marker']);
            $plan->seal(); $plan->close(); $plan = null;
            FileSafety::privateDirectory($scan); $this->assertIdentity($scan, $scanIdentity, 'directory');
            if (!unlink($destination . '/' . $state['destination']['marker'])) { throw new RuntimeException('Cannot retire v4 publication marker.'); }
            $result = ['snapshotId' => $manifest['snapshotId'], 'files' => $manifest['counts']['files'], 'directories' => $manifest['counts']['directories'], 'links' => $manifest['counts']['links'], 'bytes' => $manifest['counts']['fileBytes'], 'databaseImported' => $database !== null];
            if ($mediaDryRun !== null) {
                foreach (['files', 'directories', 'links', 'bytes'] as $count) { $result[$count] -= $mediaDryRun[$count]; }
                $result['restoreMode'] = 'local-system-media-dry-run'; $result['mediaDryRun'] = $mediaDryRun;
            }
            $state['phase'] = 'cleanup'; $this->saveState($workspace, $state);
            $this->removeTree($workspace);
            $scan = null;
            return $result;
        } finally {
            try {
                if ($plan !== null) { $plan->close(); }
                if ($scan !== null && @lstat($scan) !== false) { FileSafety::privateDirectory($scan); $this->assertIdentity($scan, $scanIdentity, 'directory'); $this->removeTree($scan); }
            } finally {
                if (is_resource($identity)) { fclose($identity); }
                flock($lock, LOCK_UN); fclose($lock);
            }
        }
    }

    public function inspect(array $manifest, $input, string $privateWorkspace): array
    {
        $this->manifest($manifest); FileSafety::privateDirectory($privateWorkspace);
        $scan = $privateWorkspace . '/v4-inspect-' . bin2hex(random_bytes(12));
        if (!mkdir($scan, 0700)) { throw new RuntimeException('Cannot create v4 inspection workspace.'); }
        $scanIdentity = $this->statIdentity($scan, 'directory'); $plan = null;
        $identity = $this->identity($input);
        try { $plan = $this->prepare($manifest, $identity, $scan, false); return $this->summary($manifest, false); }
        finally { try { if ($plan !== null) { $plan->close(); } $this->cleanupScan($scan, $scanIdentity); } finally { fclose($identity); } }
    }

    public function verifiedPacks(array $manifest, $input, string $privateWorkspace, ?MetadataMap $verifiedCache = null): iterable
    {
        $this->manifest($manifest); FileSafety::privateDirectory($privateWorkspace);
        $scan = $privateWorkspace . '/v4-index-' . bin2hex(random_bytes(12));
        if (!mkdir($scan, 0700)) { throw new RuntimeException('Cannot create v4 index verification workspace.'); }
        $scanIdentity = $this->statIdentity($scan, 'directory'); $plan = null;
        $identity = $this->identity($input);
        try {
            $plan = $this->prepare($manifest, $identity, $scan, false);
            foreach ($plan->entries() as $key => $descriptor) {
                if (strpos($key, 'pack:') !== 0) { continue; }
                $cached = $verifiedCache === null ? null : $verifiedCache->get($descriptor['object']['key']);
                if ($cached !== null && $cached !== $descriptor) { throw new RuntimeException('Retained snapshots disagree on a pack descriptor.'); }
                if ($cached === null) {
                    rewind($identity);
                    $this->verifyPack($descriptor['object'], $identity, $scan, $descriptor);
                    if ($verifiedCache !== null) { $verifiedCache->put($descriptor['object']['key'], $descriptor); }
                }
                yield $descriptor;
            }
        } finally { try { if ($plan !== null) { $plan->close(); } $this->cleanupScan($scan, $scanIdentity); } finally { fclose($identity); } }
    }

    public function verifyPack(array $receipt, $input, string $privateWorkspace, ?array $descriptor = null): array
    {
        V4Format::validateReceipt($receipt); FileSafety::privateDirectory($privateWorkspace);
        if (!str_starts_with($receipt['key'], 'packs/')) { throw new RuntimeException('Expected a v4 pack receipt.'); }
        $cipher = $privateWorkspace . '/cipher-' . bin2hex(random_bytes(12));
        $identity = $this->identity($input); $plain = null;
        try {
            $this->reader->download($receipt, $cipher);
            FileSafety::assertRegularFile($cipher);
            if (filesize($cipher) !== $receipt['bytes'] || !hash_equals($receipt['sha256'], hash_file('sha256', $cipher))) { throw new RuntimeException('V4 downloaded pack differs from its receipt.'); }
            $plain = $this->age->decryptToMemory($cipher, $identity, V4Format::MAX_CIPHER_BYTES);
            return V4Pack::verifyStream($plain, $receipt, $descriptor);
        } finally {
            if (is_resource($plain)) { fclose($plain); }
            fclose($identity);
            if (@lstat($cipher) !== false) { unlink($cipher); }
        }
    }

    public function exportDatabase(array $manifest, $input, string $newSqlFile): array
    {
        $this->manifest($manifest); FileSafety::assertNoLinks($newSqlFile); FileSafety::privateDirectory(dirname($newSqlFile));
        if (@lstat($newSqlFile) !== false) { throw new RuntimeException('V4 SQL export requires a new output file.'); }
        $scan = dirname($newSqlFile) . '/.wrb-v4-export-' . bin2hex(random_bytes(12));
        if (!mkdir($scan, 0700)) { throw new RuntimeException('Cannot create v4 export workspace.'); }
        $scanIdentity = $this->statIdentity($scan, 'directory'); $plan = null;
        $identity = $this->identity($input);
        try {
            $plan = $this->prepare($manifest, $identity, $scan);
            if (!link($scan . '/database.sql', $newSqlFile)) { throw new RuntimeException('Cannot publish SQL without replacement.'); }
            return ['snapshotId' => $manifest['snapshotId'], 'sqlFile' => $newSqlFile, 'bytes' => $manifest['database']['size'], 'sha256' => $manifest['database']['sha256'], 'databaseImported' => false];
        } finally { try { if ($plan !== null) { $plan->close(); } $this->cleanupScan($scan, $scanIdentity); } finally { fclose($identity); } }
    }

    public function pending(string $snapshotId, string $destination): bool
    {
        return @lstat($this->workspace($snapshotId, $destination)) !== false;
    }

    public function discard(string $snapshotId, string $destination): void
    {
        $workspace = $this->workspace($snapshotId, $destination);
        $this->assertOwner($workspace, $this->owner($snapshotId, $destination));
        $lock = $this->lock($workspace);
        try { $this->removeTree($workspace); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function prepare(array $manifest, $identity, string $scan, bool $payload = true, ?RecoveryMetadataMap $map = null, ?callable $progress = null): RecoveryMetadataMap
    {
        $map = $map ?? RecoveryMetadataMap::create($scan . '/metadata', 'legacy');
        if (!mkdir($scan . '/packs', 0700)) { throw new RuntimeException('Cannot create v4 pack workspace.'); }
        $indexedPacks = $payload && $map->backend() !== 'legacy';
        $scope = V4Format::scopeId($manifest['scope']); $packs = 0; $packTotal = $manifest['counts']['packs'];
        if ($progress !== null && $packTotal > 0) { $progress(['unit' => 'packs', 'done' => 0, 'total' => $packTotal]); }
        foreach ($this->records($manifest, 'packs', $identity, $scan) as $pack) {
            $key = $pack['object']['key'];
            if (strpos($key, 'packs/' . $scope . '/') !== 0 || $map->get('pack:' . $key) !== null) { throw new RuntimeException('Repeated v4 pack or storage scope mismatch.'); }
            $map->put('pack:' . $key, $pack); ++$packs;
            foreach ($pack['members'] as $member) {
                $member = ['id' => $member['id'], 'size' => $member['size'], 'sha256' => $member['sha256']];
                $map->put('member:' . $key . ':' . $member['id'], $member);
                $map->put('content:' . $member['id'], $member);
            }
            $this->reader->verify($pack['object']);
            if (!$payload) { continue; }
            $tar = $indexedPacks ? $scan . '/packs/' . hash('sha256', $key) . '.tar' : $scan . '/pack-' . bin2hex(random_bytes(12)) . '.tar';
            $retained = false;
            try {
                $this->decrypt($pack['object'], $tar, $identity, $scan);
                if ($indexedPacks) {
                    $index = V4Pack::index($tar, $pack);
                    $map->put('packed-archive:' . $key, $index['identity']);
                    foreach ($index['members'] as $member) { $map->put('packed-member:' . $key . ':' . $member['id'], ['offset' => $member['offset']]); }
                    $retained = true;
                } else { V4Pack::extract($tar, $scan . '/packs/' . hash('sha256', $key), $pack); }
            } finally { if (!$retained && @lstat($tar) !== false) { unlink($tar); } }
            if ($progress !== null && $packs <= $packTotal) { $progress(['unit' => 'packs', 'done' => $packs, 'total' => $packTotal]); }
        }
        if ($packs !== $manifest['counts']['packs']) { throw new RuntimeException('V4 pack or content count differs from its root.'); }
        $roots = [];
        foreach ($manifest['roots'] as $root) {
            $roots[$root['id']] = $root;
            $map->put('entry:roots/' . $root['name'], ['type' => 'directory', 'mode' => $root['type'] === 'directory' ? $root['mode'] : 0700]);
        }
        $counts = ['files' => 0, 'directories' => 0, 'links' => 0, 'fileBytes' => 0]; $file = null; $fileTotal = $manifest['counts']['files'];
        if ($progress !== null) { $progress($fileTotal > 0 ? ['unit' => 'files', 'done' => 0, 'total' => $fileTotal] : null); }
        foreach ($this->records($manifest, 'filesystem', $identity, $scan) as $record) {
            if ($record['type'] === 'part') {
                if ($file === null) { throw new RuntimeException('V4 content part has no open file.'); }
                $member = $this->reference($record['content'], $map);
                $content = $payload ? $this->content($record['content'], $map, $scan, $member) : '';
                $file['bytes'] += $record['content']['size'];
                if ($file['bytes'] > $file['record']['size']) { throw new RuntimeException('V4 file exceeds its declared size.'); }
                hash_update($file['hash'], $content); continue;
            }
            if ($record['type'] === 'end') {
                if ($file === null || $file['bytes'] !== $file['record']['size'] || ($payload && !hash_equals($record['sha256'], hash_final($file['hash'])))) { throw new RuntimeException('V4 file size or digest differs.'); }
                $map->put('entry:' . $file['path'], $file['record'] + ['sha256' => $record['sha256']], true);
                $verified = $counts['files'];
                if ($progress !== null && $verified <= $fileTotal && ($verified % 128 === 0 || $verified === $fileTotal)) {
                    $progress(['unit' => 'files', 'done' => $verified, 'total' => $fileTotal]);
                }
                $file = null; continue;
            }
            if ($file !== null) { throw new RuntimeException('V4 file is missing its end record.'); }
            $root = $roots[$record['rootId']] ?? null;
            if ($root === null || ($root['type'] === 'file' && ($record['type'] !== 'file' || $record['path'] !== basename($root['sourcePath'])))) { throw new RuntimeException('V4 filesystem record belongs to an invalid source root.'); }
            $path = 'roots/' . $root['name'] . '/' . $record['path'];
            $this->register($path, $record, $map);
            if ($record['type'] === 'file') { ++$counts['files']; $counts['fileBytes'] += $record['size']; $file = ['record' => $record, 'path' => $path, 'bytes' => 0, 'hash' => hash_init('sha256')]; }
            elseif ($record['type'] === 'directory') { ++$counts['directories']; }
            else { ++$counts['links']; }
        }
        if ($file !== null) { throw new RuntimeException('V4 filesystem ends inside a file.'); }
        foreach ($manifest['roots'] as $root) {
            if ($root['type'] === 'file' && ($map->get('entry:roots/' . $root['name'] . '/' . basename($root['sourcePath']))['type'] ?? null) !== 'file') { throw new RuntimeException('V4 single-file source root has no saved file.'); }
        }
        foreach ($counts as $name => $value) { if ($manifest['counts'][$name] !== $value) { throw new RuntimeException('V4 filesystem count or byte total differs.'); } }
        if ($progress !== null) { $progress(null); }
        foreach ($map->entries() as $key => $entry) {
            if (strpos($key, 'entry:') !== 0) { continue; }
            $path = substr($key, 6); $parent = dirname($path);
            if ($parent !== 'roots' && ($map->get('entry:' . $parent)['type'] ?? null) !== 'directory') { throw new RuntimeException('V4 entry has a missing or non-directory parent.'); }
            if ($entry['type'] === 'symlink') { $this->linkDestination($path, $entry['target'], $map); }
        }
        $sql = $payload ? $this->newStream($scan . '/database.sql') : null; $sqlHash = hash_init('sha256'); $sqlBytes = 0; $tables = 0;
        try {
            foreach ($this->records($manifest, 'database', $identity, $scan) as $record) {
                if ($record['type'] === 'table') {
                    $tableKey = 'table:' . strtolower($record['name']);
                    if ($map->get($tableKey) !== null) { throw new RuntimeException('Repeated v4 database table.'); }
                    $map->put($tableKey, $record); ++$tables;
                } else {
                    $member = $this->reference($record['content'], $map);
                    $bytes = $payload ? $this->content($record['content'], $map, $scan, $member) : ''; $sqlBytes += $record['content']['size'];
                    if ($sqlBytes > $manifest['database']['size']) { throw new RuntimeException('V4 SQL exceeds its declared size.'); }
                    if ($payload) { $this->write($sql, $bytes); hash_update($sqlHash, $bytes); }
                }
            }
            if ($payload && !FileSafety::sync($sql)) { throw new RuntimeException('Cannot persist recovered v4 SQL.'); }
        } finally { if (is_resource($sql)) { fclose($sql); } }
        if ($tables !== $manifest['database']['tableCount'] || $sqlBytes !== $manifest['database']['size'] || ($payload && !hash_equals($manifest['database']['sha256'], hash_final($sqlHash)))) { throw new RuntimeException('V4 database size, digest or table count differs.'); }
        $references = 0; $unreferenced = false;
        $rejectUnreferenced = !(new PrivacyPolicy($manifest['wordpress']['policy']))->isComplete();
        foreach ($map->entries() as $key => $value) {
            if (strpos($key, 'reference:') === 0) { ++$references; }
            if ($rejectUnreferenced && strpos($key, 'content:') === 0 && $map->get('reference:' . $value['id']) === null) { $unreferenced = true; }
        }
        if ($references !== $manifest['counts']['contents']) { throw new RuntimeException('V4 referenced content count differs.'); }
        if ($unreferenced) { throw new RuntimeException('Excluded v4 snapshot contains unreferenced pack content.'); }
        $map->seal();
        return $map;
    }

    private function records(array $manifest, string $kind, $identity, string $workspace): \Generator
    {
        $sequence = 0;
        foreach ($manifest['pages'] as $descriptor) {
            if ($descriptor['kind'] !== $kind) { continue; }
            if ($descriptor['sequence'] !== $sequence++) { throw new RuntimeException('V4 page sequence differs.'); }
            $plain = $workspace . '/page-' . bin2hex(random_bytes(12));
            try {
                $this->decrypt($descriptor['object'], $plain, $identity, $workspace);
                $this->verifyContent($plain, $descriptor);
                if (filesize($plain) > V4Format::MAX_PAGE_BYTES) { throw new RuntimeException('V4 plaintext page exceeds its limit.'); }
                $page = json_decode(file_get_contents($plain), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($page)) { throw new RuntimeException('Invalid v4 page.'); }
                V4Format::validatePage($page, $descriptor);
                if ($page['snapshotId'] !== $manifest['snapshotId']) { throw new RuntimeException('V4 page belongs to another snapshot.'); }
                foreach ($page['records'] as $record) { yield $record; }
            } finally { if (@lstat($plain) !== false) { unlink($plain); } }
        }
    }

    private function reference(array $reference, RecoveryMetadataMap $map): array
    {
        $member = $map->get('member:' . $reference['pack'] . ':' . $reference['id']);
        if ($member !== ['id' => $reference['id'], 'size' => $reference['size'], 'sha256' => $reference['sha256']]) { throw new RuntimeException('V4 content reference differs from its pack member.'); }
        $map->put('reference:' . $reference['id'], ['id' => $reference['id']]);
        return $member;
    }

    /** An authenticated member may only come from the immediately preceding reference() call. */
    private function content(array $reference, RecoveryMetadataMap $map, string $scan, ?array $authenticatedMember = null): string
    {
        $member = $authenticatedMember ?? $map->get('member:' . $reference['pack'] . ':' . $reference['id']);
        if ($member !== ['id' => $reference['id'], 'size' => $reference['size'], 'sha256' => $reference['sha256']]) { throw new RuntimeException('V4 content reference differs from its pack member.'); }
        if ($map->backend() !== 'legacy') {
            $identity = $map->get('packed-archive:' . $reference['pack']);
            $range = $map->get('packed-member:' . $reference['pack'] . ':' . $reference['id']);
            if ($identity === null || $range === null || array_keys($range) !== ['offset']) { throw new RuntimeException('Indexed v4 content has no validated archive range.'); }
            return V4Pack::readIndexed($scan . '/packs/' . hash('sha256', $reference['pack']) . '.tar', $identity, $member + $range);
        }
        $path = $scan . '/packs/' . hash('sha256', $reference['pack']) . '/' . $reference['id'];
        $this->verifyContent($path, $reference);
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) !== $reference['size']) { throw new RuntimeException('Cannot read verified v4 content.'); }
        return $bytes;
    }

    private function register(string $path, array $record, RecoveryMetadataMap $map): void
    {
        if ($map->get('entry:' . $path) !== null) { throw new RuntimeException('Repeated v4 filesystem path.'); }
        $prefix = ''; $parts = explode('/', $path); $last = count($parts) - 1;
        foreach ($parts as $index => $part) {
            $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
            $key = 'fold:' . mb_convert_case(\Normalizer::normalize($prefix, \Normalizer::FORM_C), MB_CASE_FOLD, 'UTF-8');
            $prior = $map->get($key);
            if ($prior !== null && $prior !== ['path' => $prefix]) { throw new RuntimeException('V4 paths collide after Unicode normalization and case folding.'); }
            if ($prior === null) { $map->put($key, ['path' => $prefix]); }
            if ($index < $last && ($map->get('entry:' . $prefix)['type'] ?? 'directory') !== 'directory') { throw new RuntimeException('V4 file or link is an ancestor of another entry.'); }
        }
        $parent = dirname($path);
        if (($map->get('entry:' . $parent)['type'] ?? null) !== 'directory') { throw new RuntimeException('V4 entry appears before its saved parent directory.'); }
        $map->put('entry:' . $path, $record);
    }

    private function linkDestination(string $path, string $target, RecoveryMetadataMap $map, ?string $unavailablePrefix = null): string
    {
        $parts = explode('/', dirname($path)); $root = implode('/', array_slice($parts, 0, 2));
        $components = explode('/', $target);
        foreach ($components as $index => $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { if (count($parts) <= 2) { throw new RuntimeException('V4 link escapes its source root.'); } array_pop($parts); }
            else { $parts[] = $part; }
            if ($unavailablePrefix !== null && $this->withinMedia(implode('/', $parts), $unavailablePrefix)) { throw new RuntimeException('A retained system link traverses dry-run media.'); }
            $entry = $map->get('entry:' . implode('/', $parts));
            if ($entry === null || !in_array($entry['type'], ['file', 'directory'], true) || ($index < count($components) - 1 && $entry['type'] !== 'directory')) { throw new RuntimeException('V4 link target is absent, excluded or traverses a link.'); }
        }
        $resolved = implode('/', $parts); $entry = $map->get('entry:' . $resolved);
        if (strpos($resolved . '/', $root . '/') !== 0 || $entry === null || !in_array($entry['type'], ['file', 'directory'], true) || (substr($target, -1) === '/' && $entry['type'] !== 'directory')) { throw new RuntimeException('V4 link target is unavailable or has an invalid trailing slash.'); }
        return $resolved;
    }

    private function mediaDryRun(string $prefix, RecoveryMetadataMap $plan): array
    {
        if (($plan->get('entry:' . $prefix)['type'] ?? null) !== 'directory') { throw new RuntimeException('The standard uploads directory is absent or not a directory; custom media roots are not inferred.'); }
        $counts = ['files' => 0, 'directories' => 0, 'links' => 0, 'bytes' => 0, 'contentsVerified' => true];
        foreach ($plan->entries() as $key => $entry) {
            if (strpos($key, 'entry:') !== 0) { continue; }
            $path = substr($key, 6);
            if ($this->withinMedia($path, $prefix)) {
                if ($entry['type'] === 'file') { ++$counts['files']; $counts['bytes'] += $entry['size']; }
                elseif ($entry['type'] === 'directory') { ++$counts['directories']; }
                else { ++$counts['links']; }
            } elseif ($entry['type'] === 'symlink') { $this->linkDestination($path, $entry['target'], $plan, $prefix); }
        }
        return $counts;
    }

    private function withinMedia(string $path, string $prefix): bool { return $path === $prefix || strpos($path, $prefix . '/') === 0; }

    private function publishFiles(array $manifest, $identity, string $scan, string $destination, RecoveryMetadataMap $plan, MetadataMap $ledger, ?string $localMediaDryRunPrefix): void
    {
        $roots = array_column($manifest['roots'], null, 'id'); $stream = null; $path = null; $hash = null; $skipFile = false;
        try {
            foreach ($this->records($manifest, 'filesystem', $identity, $scan) as $record) {
                if ($record['type'] === 'part') { if ($skipFile) { continue; } $bytes = $this->content($record['content'], $plan, $scan); $this->write($stream, $bytes); hash_update($hash, $bytes); continue; }
                if ($record['type'] === 'end') {
                    if ($skipFile) { $skipFile = false; $path = null; $hash = null; continue; }
                    if (!FileSafety::sync($stream) || !hash_equals($record['sha256'], hash_final($hash))) { throw new RuntimeException('Published v4 file differs from its content.'); }
                    fclose($stream); $stream = null;
                    $entry = $plan->get('entry:' . $path);
                    $this->verifyContent($destination . '/' . $path, $entry);
                    $ledger->put('path:' . $path, $this->statIdentity($destination . '/' . $path, 'file') + ['complete' => true, 'size' => $entry['size'], 'sha256' => $entry['sha256']], true);
                    continue;
                }
                $path = 'roots/' . $roots[$record['rootId']]['name'] . '/' . $record['path'];
                if ($localMediaDryRunPrefix !== null && $this->withinMedia($path, $localMediaDryRunPrefix)) { $skipFile = $record['type'] === 'file'; continue; }
                if ($record['type'] === 'directory') { $this->directory($destination, $path, $ledger); }
                elseif ($record['type'] === 'file') {
                    $target = $destination . '/' . $path; FileSafety::assertNoLinks($target);
                    if (@lstat($target) === false) {
                        if ($ledger->get('path:' . $path) !== null) { throw new RuntimeException('An owned v4 file disappeared; refusing replacement.'); }
                        $stream = $this->newStream($target);
                        $ledger->put('path:' . $path, $this->statIdentity($target, 'file'));
                    } else {
                        $this->assertIdentity($target, $ledger->get('path:' . $path), 'file');
                        if (!chmod($target, 0600)) { throw new RuntimeException('Cannot prepare owned v4 file for resume.'); }
                        $ledger->put('path:' . $path, $this->statIdentity($target, 'file'), true);
                        $stream = fopen($target, 'r+b');
                        $opened = is_resource($stream) ? fstat($stream) : false; $owned = $ledger->get('path:' . $path);
                        if ($opened === false || $opened['dev'] !== $owned['dev'] || $opened['ino'] !== $owned['ino'] || $opened['nlink'] !== 1 || !ftruncate($stream, 0)) { throw new RuntimeException('Cannot resume owned v4 file with the recorded identity.'); }
                    }
                    $hash = hash_init('sha256');
                }
            }
        } finally { if (is_resource($stream)) { fclose($stream); } }
    }

    private function publishLinks(string $destination, RecoveryMetadataMap $plan, MetadataMap $ledger, ?string $localMediaDryRunPrefix): void
    {
        foreach ($plan->entries() as $key => $entry) {
            if (strpos($key, 'entry:') !== 0 || $entry['type'] !== 'symlink') { continue; }
            $path = substr($key, 6); $target = $destination . '/' . $path;
            if ($localMediaDryRunPrefix !== null && $this->withinMedia($path, $localMediaDryRunPrefix)) { continue; }
            FileSafety::assertNoLinks(dirname($target));
            if (@lstat($target) === false) {
                if ($ledger->get('path:' . $path) !== null) { throw new RuntimeException('An owned v4 link disappeared; refusing replacement.'); }
                if (!symlink($entry['target'], $target)) { throw new RuntimeException('Cannot create recovered v4 symbolic link.'); }
                $ledger->put('path:' . $path, $this->statIdentity($target, 'symlink') + ['target' => $entry['target']]);
            } else { $this->assertIdentity($target, $ledger->get('path:' . $path), 'symlink'); }
            if (readlink($target) !== $entry['target']) { throw new RuntimeException('Recovered v4 link target changed.'); }
        }
    }

    private function directory(string $destination, string $path, MetadataMap $ledger): void
    {
        $target = $destination . '/' . $path; FileSafety::assertNoLinks($target);
        if (@lstat($target) === false) {
            if ($ledger->get('path:' . $path) !== null) { throw new RuntimeException('An owned v4 directory disappeared; refusing replacement.'); }
            if (!mkdir($target, 0700)) { throw new RuntimeException('Cannot create v4 recovery directory.'); }
            $ledger->put('path:' . $path, $this->statIdentity($target, 'directory'));
        } else { $this->assertIdentity($target, $ledger->get('path:' . $path), 'directory'); }
        if (!chmod($target, 0700)) { throw new RuntimeException('Cannot prepare owned v4 directory.'); }
    }

    private function verifyTree(string $destination, string $relative, MetadataMap $ledger, string $marker): void
    {
        $path = $relative === '' ? $destination : $destination . '/' . $relative;
        $this->assertIdentity($path, $ledger->get('path:' . $relative), 'directory');
        if (!chmod($path, 0700)) { throw new RuntimeException('Cannot inspect owned v4 directory.'); }
        $directory = opendir($path);
        if ($directory === false) { throw new RuntimeException('Cannot read owned v4 destination.'); }
        try {
            while (($name = readdir($directory)) !== false) {
                if ($name === '.' || $name === '..' || ($relative === '' && $name === $marker)) { continue; }
                $child = $relative === '' ? $name : $relative . '/' . $name;
                $record = $ledger->get('path:' . $child);
                if ($record === null) { throw new RuntimeException('Unknown file in v4 recovery destination; refusing replacement.'); }
                $this->assertIdentity($destination . '/' . $child, $record, $record['type']);
                if ($record['type'] === 'directory') { $this->verifyTree($destination, $child, $ledger, $marker); }
                elseif ($record['type'] === 'file') {
                    if (!chmod($destination . '/' . $child, 0600)) { throw new RuntimeException('Cannot inspect owned v4 file.'); }
                    if ($record['complete'] ?? false) { $this->verifyContent($destination . '/' . $child, $record); }
                }
                elseif (readlink($destination . '/' . $child) !== $record['target']) { throw new RuntimeException('Owned v4 link target changed.'); }
            }
        } finally { closedir($directory); }
    }

    private function finalizeModes(string $destination, string $relative, RecoveryMetadataMap $plan, MetadataMap $ledger, string $marker): void
    {
        $path = $relative === '' ? $destination : $destination . '/' . $relative;
        $directory = opendir($path);
        if ($directory === false) { throw new RuntimeException('Cannot finalize v4 directory modes.'); }
        try {
            while (($name = readdir($directory)) !== false) {
                if ($name === '.' || $name === '..' || ($relative === '' && $name === $marker)) { continue; }
                $child = $relative === '' ? $name : $relative . '/' . $name;
                $record = $ledger->get('path:' . $child); $entry = $plan->get('entry:' . $child);
                $this->assertIdentity($destination . '/' . $child, $record, $record['type']);
                if ($record['type'] === 'directory') { $this->finalizeModes($destination, $child, $plan, $ledger, $marker); }
                elseif ($record['type'] === 'file') {
                    $this->verifyContent($destination . '/' . $child, $entry);
                    if (!chmod($destination . '/' . $child, $entry['mode'])) { throw new RuntimeException('Cannot apply original v4 file mode.'); }
                }
            }
        } finally { closedir($directory); }
        $entry = $plan->get('entry:' . $relative);
        if (!chmod($path, $entry['mode'] ?? 0700)) { throw new RuntimeException('Cannot apply original v4 directory mode.'); }
    }

    private function tables(RecoveryMetadataMap $map): \Generator
    {
        foreach ($map->entries() as $key => $record) { if (strpos($key, 'table:') === 0) { yield $record; } }
    }

    private function manifest(array $manifest): void
    {
        V4Format::validateRoot($manifest);
        if ($manifest['unitId'] !== $this->reader->unitId()) { throw new RuntimeException('V4 root belongs to another repository unit.'); }
    }

    private function summary(array $manifest, bool $decrypted): array
    {
        $capture = ['method' => 'wordpress-v4', 'siteUrl' => $manifest['wordpress']['siteUrl'], 'policySha256' => $manifest['wordpress']['policySha256'], 'rootCount' => count($manifest['roots']), 'counts' => $manifest['counts']];
        $online = ($manifest['wordpress']['consistencyMode'] ?? null) === 'online';
        if ($online) {
            $capture['method'] = 'wordpress-v4-online';
            $capture['consistencyMode'] = 'online';
            $capture['samePointInTimeGuaranteed'] = false;
        }
        foreach ($online ? ['startedAt', 'snapshotAt', 'databaseVerifiedAt', 'filesVerifiedAt', 'finishedAt'] : ['startedAt', 'lockAcquiredAt', 'verifiedAt', 'finishedAt'] as $field) {
            if (is_string($manifest['wordpress']['evidence'][$field] ?? null)) { $capture[$field] = $manifest['wordpress']['evidence'][$field]; }
        }
        return ['manifestVersion' => 4, 'captureEvidence' => 'reported', 'capture' => $capture, 'verification' => ['manifest' => true, 'referencedCiphertexts' => true, 'pagesDecrypted' => true, 'payloadDecrypted' => $decrypted, 'databaseImported' => false]];
    }

    private function decrypt(array $receipt, string $plain, $identity, string $workspace): void
    {
        V4Format::validateReceipt($receipt); $cipher = $workspace . '/cipher-' . bin2hex(random_bytes(12));
        try {
            $this->reader->verify($receipt); $this->reader->download($receipt, $cipher);
            FileSafety::assertRegularFile($cipher);
            if (filesize($cipher) !== $receipt['bytes'] || !hash_equals($receipt['sha256'], hash_file('sha256', $cipher))) { throw new RuntimeException('V4 downloaded ciphertext differs from its receipt.'); }
            rewind($identity); $this->age->decrypt($cipher, $plain, $identity);
        } finally { if (@lstat($cipher) !== false) { unlink($cipher); } }
    }

    private function verifyContent(string $path, array $expected): void
    {
        FileSafety::assertRegularFile($path);
        if (filesize($path) !== $expected['size'] || !hash_equals($expected['sha256'], hash_file('sha256', $path))) { throw new RuntimeException('V4 plaintext differs from its size or digest.'); }
    }

    private function workspace(string $snapshot, string $destination): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $snapshot) || substr($destination, -1) === '/') { throw new RuntimeException('Invalid v4 recovery snapshot or destination.'); }
        FileSafety::assertNoLinks($destination); FileSafety::privateDirectory(dirname($destination));
        return dirname($destination) . '/.wrb-v4-' . hash('sha256', $this->reader->unitId() . "\0" . $snapshot . "\0" . $destination);
    }

    private function owner(string $snapshot, string $destination): string
    {
        return 'wordpress-r2-backup v4 recovery ' . hash('sha256', $this->reader->unitId() . "\0" . $snapshot . "\0" . $destination) . "\n";
    }

    private function assertOwner(string $workspace, string $owner): void
    {
        FileSafety::privateDirectory($workspace); FileSafety::assertRegularFile($workspace . '/owner');
        if ((fileperms($workspace . '/owner') & 0777) !== 0600 || filesize($workspace . '/owner') > 256 || file_get_contents($workspace . '/owner') !== $owner) { throw new RuntimeException('V4 recovery workspace ownership differs.'); }
    }

    private function publicationOwner(string $workspace, string $destination, array $publication): void
    {
        $this->assertIdentity($destination, $publication, 'directory');
        if (!preg_match('/\A\.wrb-publication-[a-f0-9]{32}\z/D', $publication['marker'] ?? '')) { throw new RuntimeException('Invalid v4 publication marker.'); }
        $owner = $this->statIdentity($workspace . '/owner', 'file');
        $this->assertIdentity($destination . '/' . $publication['marker'], $owner, 'file');
    }

    private function statIdentity(string $path, string $type): array
    {
        clearstatcache(true, $path); $stat = @lstat($path); $types = ['directory' => 0040000, 'file' => 0100000, 'symlink' => 0120000];
        if ($stat === false || ($stat['mode'] & 0170000) !== $types[$type] || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) { throw new RuntimeException('V4 recovery entry has an unsafe type or owner.'); }
        $identity = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'type' => $type];
        if ($type === 'file') { $identity['nlink'] = $stat['nlink']; }
        return $identity;
    }

    private function assertIdentity(string $path, ?array $expected, string $type): void
    {
        $actual = $this->statIdentity($path, $type);
        if ($expected === null || ($expected['dev'] ?? null) !== $actual['dev'] || ($expected['ino'] ?? null) !== $actual['ino'] || ($expected['type'] ?? null) !== $type || ($type === 'file' && ($expected['nlink'] ?? null) !== $actual['nlink'])) { throw new RuntimeException('V4 recovery entry identity changed; refusing replacement.'); }
    }

    private function lock(string $workspace)
    {
        FileSafety::privateDirectory($workspace); $path = $workspace . '/job.lock'; FileSafety::assertNoLinks($path);
        if (@lstat($path) !== false) { FileSafety::assertRegularFile($path); }
        $stream = fopen($path, 'c+b');
        if ($stream === false || !chmod($path, 0600) || !flock($stream, LOCK_EX | LOCK_NB)) { if (is_resource($stream)) { fclose($stream); } throw new RuntimeException('V4 recovery is already running or cannot be locked.'); }
        return $stream;
    }

    private function identity($input)
    {
        if (!is_resource($input) || get_resource_type($input) !== 'stream') { throw new RuntimeException('A readable identity stream is required.'); }
        $identity = fopen('php://memory', 'w+b'); $size = stream_copy_to_stream($input, $identity, 65537);
        if ($size === false || $size === 0 || $size > 65536) { fclose($identity); throw new RuntimeException('Identity stream must contain at most 64 KiB.'); }
        rewind($identity); return $identity;
    }

    private function newStream(string $path)
    {
        $previous = umask(0077);
        try { $stream = fopen($path, 'x+b'); } finally { umask($previous); }
        if ($stream === false || !chmod($path, 0600)) { if (is_resource($stream)) { fclose($stream); } throw new RuntimeException('Cannot create private v4 file.'); }
        return $stream;
    }

    private function write($stream, string $bytes): void
    {
        if (!is_resource($stream)) { throw new RuntimeException('V4 content has no open output.'); }
        for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) { throw new RuntimeException('Cannot write recovered v4 content.'); }
            $offset += $written;
        }
    }

    private function writeNew(string $path, string $bytes): void
    {
        $stream = $this->newStream($path);
        try { $this->write($stream, $bytes); if (!FileSafety::sync($stream)) { throw new RuntimeException('Cannot persist v4 recovery metadata.'); } }
        finally { fclose($stream); }
    }

    private function saveState(string $workspace, array $state): void
    {
        if ($this->phaseListener !== null && isset($state['phase'])) { ($this->phaseListener)($state['phase']); }
        $temporary = $workspace . '/state-' . bin2hex(random_bytes(12));
        try { $this->writeNew($temporary, V4Format::json($state)); if (!rename($temporary, $workspace . '/state.json')) { throw new RuntimeException('Cannot publish v4 recovery state.'); } }
        finally { if (@lstat($temporary) !== false) { unlink($temporary); } }
    }

    private function readJson(string $path): array
    {
        FileSafety::assertRegularFile($path);
        if ((fileperms($path) & 0777) !== 0600 || filesize($path) > 65536) { throw new RuntimeException('Invalid v4 recovery state.'); }
        $state = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($state)) { throw new RuntimeException('Invalid v4 recovery state.'); }
        return $state;
    }

    private function cleanupScan(string $path, array $identity): void
    {
        FileSafety::privateDirectory($path);
        $this->assertIdentity($path, $identity, 'directory');
        $this->removeTree($path);
    }

    private function removeTree(string $path): void
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        if ($stat === false) { return; }
        if (($stat['mode'] & 0170000) !== 0040000) { if (!unlink($path)) { throw new RuntimeException('Cannot remove private v4 workspace file.'); } return; }
        FileSafety::privateDirectory($path); $directory = opendir($path);
        if ($directory === false) { throw new RuntimeException('Cannot read private v4 workspace.'); }
        try { while (($name = readdir($directory)) !== false) { if ($name !== '.' && $name !== '..') { $this->removeTree($path . '/' . $name); } } }
        finally { closedir($directory); }
        if (!rmdir($path)) { throw new RuntimeException('Cannot remove private v4 workspace.'); }
    }
}
