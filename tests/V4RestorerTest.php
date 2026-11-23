<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\AgeProcess;
use WordPressR2Backup\Manifest;
use WordPressR2Backup\MetadataMap;
use WordPressR2Backup\Restorer;
use WordPressR2Backup\SqliteDatabaseClient;
use WordPressR2Backup\WordPressDatabase;
use WordPressR2Backup\ObjectReader;
use WordPressR2Backup\V4Format;
use WordPressR2Backup\V4Pack;
use WordPressR2Backup\V4Restorer;

final class V4RestorerTest extends TestCase
{
    private string $directory;
    private array $keys;
    private AgeProcess $age;
    private V4RecoveryReader $reader;
    private array $manifest;
    private array $receipt;
    private string $secondPage;
    private array $densePaths = [];

    protected function setUp(): void
    {
        $this->directory = Fixture::temp(); $this->keys = Fixture::keys(2);
        $this->age = new AgeProcess(Fixture::runtime()['age']);
        $this->reader = new V4RecoveryReader(str_repeat('a', 64));
        $this->fixture();
    }

    protected function tearDown(): void
    {
        $this->makeReadable($this->directory);
        Fixture::cleanup();
    }

    public function testSnapshotAloneRestoresSplitFilesRootsLinksModesAndIndependentInodes(): void
    {
        $events = [];
        $result = $this->recover(false, null, static function (?array $event) use (&$events): void { $events[] = $event; });
        $packs = $this->manifest['counts']['packs'];
        self::assertSame(['unit' => 'packs', 'done' => 0, 'total' => $packs], $events[0]);
        self::assertContains(['unit' => 'packs', 'done' => $packs, 'total' => $packs], $events);
        self::assertContains(['unit' => 'files', 'done' => 2, 'total' => 2], $events);
        self::assertNull(end($events));
        $root = $this->directory . '/restored/roots/r0001';
        self::assertSame(2, $result['files']); self::assertSame(1, $result['links']); self::assertFalse($result['databaseImported']);
        self::assertSame('hello world', file_get_contents($root . '/a.txt'));
        self::assertSame('hello world', file_get_contents($root . '/nested/b.txt'));
        self::assertNotSame(lstat($root . '/a.txt')['ino'], lstat($root . '/nested/b.txt')['ino']);
        self::assertSame(0400, fileperms($root . '/a.txt') & 0777);
        self::assertSame(0640, fileperms($root . '/nested/b.txt') & 0777);
        self::assertSame('nested//', readlink($root . '/shortcut'));
        self::assertFalse($this->restorer()->pending($this->manifest['snapshotId'], $this->directory . '/restored'));
        self::assertSame(0, $this->reader->completedCalls);
    }

    /** @dataProvider recoveryMetadataBackends */
    public function testRecoveryPreservesFilesWhenTemporaryMetadataNeedsEarlySplitting(string $backend): void
    {
        if ($backend === 'sqlite' && !extension_loaded('sqlite3')) { self::markTestSkipped('SQLite is unavailable.'); }
        $previous = getenv('WRB_RECOVERY_METADATA_BACKEND'); putenv('WRB_RECOVERY_METADATA_BACKEND=' . $backend);
        try {
            $this->fixture('dense');
            $result = $this->recover();
            self::assertSame(67, $result['files']);
            self::assertSame(1, $result['links']);
            $root = $this->directory . '/restored/roots/r0001';
            foreach ($this->densePaths as $path) {
                self::assertSame('hello world', file_get_contents($root . '/' . $path));
                self::assertSame(0640, fileperms($root . '/' . $path) & 0777);
            }
            self::assertSame('nested//', readlink($root . '/shortcut'));
            self::assertSame(0400, fileperms($root . '/a.txt') & 0777);
        } finally {
            putenv($previous === false ? 'WRB_RECOVERY_METADATA_BACKEND' : 'WRB_RECOVERY_METADATA_BACKEND=' . $previous);
        }
    }

    public static function recoveryMetadataBackends(): array { return [['files'], ['sqlite']]; }

    /** @dataProvider recoveryMetadataBackends */
    public function testNormalRecoveryRetainsOnlyTheVerifiedPackArchive(string $backend): void
    {
        $previous = getenv('WRB_RECOVERY_METADATA_BACKEND'); putenv('WRB_RECOVERY_METADATA_BACKEND=' . $backend); $identity = $this->identity(); $called = false;
        try {
            $result = $this->restorer()->recover($this->manifest, $this->receipt, $identity, $this->directory . '/restored', null, null, false, function () use (&$called): void {
                $called = true; $entries = glob($this->directory . '/.wrb-v4-*/scan-*/packs/*');
                self::assertCount(1, $entries); self::assertFileExists($entries[0]); self::assertStringEndsWith('.tar', $entries[0]);
            });
            self::assertTrue($called); self::assertSame(2, $result['files']); self::assertSame('hello world', file_get_contents($this->directory . '/restored/roots/r0001/a.txt'));
        } finally { fclose($identity); putenv($previous === false ? 'WRB_RECOVERY_METADATA_BACKEND' : 'WRB_RECOVERY_METADATA_BACKEND=' . $previous); }
    }

    public function testAnIndexedMemberChangedAfterPreparationCannotBePublished(): void
    {
        $previous = getenv('WRB_RECOVERY_METADATA_BACKEND'); putenv('WRB_RECOVERY_METADATA_BACKEND=files'); $identity = $this->identity();
        try {
            $this->restorer()->recover($this->manifest, $this->receipt, $identity, $this->directory . '/restored', null, null, false, function (): void {
                $entries = glob($this->directory . '/.wrb-v4-*/scan-*/packs/*.tar'); self::assertCount(1, $entries);
                $stream = fopen($entries[0], 'r+b'); fseek($stream, 512); fwrite($stream, 'X'); fclose($stream);
            });
            self::fail('Changed indexed content must fail.');
        } catch (RuntimeException $error) { self::assertStringContainsString('Indexed v4 content changed', $error->getMessage()); }
        finally { fclose($identity); putenv($previous === false ? 'WRB_RECOVERY_METADATA_BACKEND' : 'WRB_RECOVERY_METADATA_BACKEND=' . $previous); }
    }

    public function testMetadataChangedByTheSummaryCallbackFailsBeforePublication(): void
    {
        if (!extension_loaded('sqlite3')) { self::markTestSkipped('SQLite is unavailable.'); }
        $previous = getenv('WRB_RECOVERY_METADATA_BACKEND'); putenv('WRB_RECOVERY_METADATA_BACKEND=sqlite');
        $identity = $this->identity(); $called = false;
        try {
            $this->restorer()->recover($this->manifest, $this->receipt, $identity, $this->directory . '/restored', null, null, false, function () use (&$called): void {
                $called = true;
                $files = glob($this->directory . '/.wrb-v4-*/scan-*/metadata/index.sqlite');
                self::assertCount(1, $files);
                $script = '$db=new SQLite3($argv[1],SQLITE3_OPEN_READWRITE);$db->enableExceptions(true);$db->exec("UPDATE records SET v=x\'7b7d\' WHERE id=(SELECT id FROM records LIMIT 1)");$db->close();';
                $process = proc_open([PHP_BINARY, '-r', $script, $files[0]], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process));
            });
            self::fail('A changed temporary plan must fail before any files are published.');
        } catch (RuntimeException $expected) {
            self::assertTrue($called); self::assertDirectoryDoesNotExist($this->directory . '/restored');
        } finally {
            fclose($identity); putenv($previous === false ? 'WRB_RECOVERY_METADATA_BACKEND' : 'WRB_RECOVERY_METADATA_BACKEND=' . $previous);
        }
    }

    public function testAnExchangedScanDirectoryIsNotRemovedDuringFailureCleanup(): void
    {
        $identity = $this->identity(); $replacement = null;
        try {
            $this->restorer()->recover($this->manifest, $this->receipt, $identity, $this->directory . '/restored', null, null, false, function () use (&$replacement): void {
                $scans = glob($this->directory . '/.wrb-v4-*/scan-*'); self::assertCount(1, $scans);
                $replacement = $scans[0]; rename($replacement, $replacement . '-retained'); mkdir($replacement, 0700); file_put_contents($replacement . '/unowned', 'keep');
            });
            self::fail('An exchanged validation directory must fail.');
        } catch (RuntimeException $expected) {
            self::assertNotNull($replacement); self::assertSame('keep', file_get_contents($replacement . '/unowned'));
            self::assertDirectoryDoesNotExist($this->directory . '/restored');
        } finally { fclose($identity); }
    }

    public function testInitialSqliteRecoveryCompletesWithANonReopenablePublicationLedger(): void
    {
        $this->fixture('sqlite'); $client = $this->sqliteClient(); $identity = $this->identity(); $called = false;
        try {
            $result = $this->restorer()->recover($this->manifest, $this->receipt, $identity, $this->directory . '/restored', $client, 'sqlite', false, function () use (&$called): void {
                $called = true; $ledgers = glob($this->directory . '/.wrb-v4-*/publication'); self::assertCount(1, $ledgers);
                $rejected = false;
                try { MetadataMap::open($ledgers[0]); } catch (RuntimeException $error) { $rejected = true; self::assertSame('Invalid metadata map identity.', $error->getMessage()); }
                self::assertTrue($rejected, 'A SQLite publication ledger must not be reopenable.');
            });
            self::assertTrue($called); self::assertTrue($result['databaseImported']); self::assertSame(2, $result['files']);
            self::assertSame('hello world', file_get_contents($this->directory . '/restored/roots/r0001/a.txt'));
            self::assertSame(0400, fileperms($this->directory . '/restored/roots/r0001/a.txt') & 0777);
            self::assertSame([], glob($this->directory . '/.wrb-v4-*'));
        } finally { fclose($identity); $client->close(); }
    }

    public function testBothSqliteResumeEntrypointsRejectTheSameLiveClientWithoutChangingFailedPublication(): void
    {
        $this->fixture('sqlite'); $client = $this->sqliteClient(); $identity = $this->identity();
        $destination = $this->directory . '/restored'; $key = $this->secondPage;
        $this->reader->failure = static function (string $downloaded, int $count) use ($key): void {
            if ($downloaded === $key && $count === 2) { throw new RuntimeException('Synthetic SQLite publication interruption.'); }
        };
        try {
            $interrupted = false;
            try { $this->restorer()->recover($this->manifest, $this->receipt, $identity, $destination, $client, 'sqlite', false); }
            catch (RuntimeException $error) { $interrupted = true; self::assertSame('Synthetic SQLite publication interruption.', $error->getMessage()); }
            self::assertTrue($interrupted);
            $stateFile = glob($this->directory . '/.wrb-v4-*/state.json')[0]; $state = file_get_contents($stateFile);
            $sentinel = $destination . '/roots/r0001/a.txt'; $inode = fileinode($sentinel); self::assertSame('hello world', file_get_contents($sentinel));
            $downloads = $this->reader->downloads; $completed = $this->reader->completedCalls; $this->reader->failure = null;
            foreach (['public-api', 'direct-v4'] as $entrypoint) {
                rewind($identity); $rejected = false;
                try {
                    if ($entrypoint === 'public-api') { (new Restorer($this->age, $this->reader))->resume($this->manifest['snapshotId'], $identity, $destination, $client, 'sqlite'); }
                    else { $this->restorer()->recover($this->manifest, $this->receipt, $identity, $destination, $client, 'sqlite', true); }
                } catch (RuntimeException $error) { $rejected = true; self::assertSame('SQLite recovery cannot be resumed; use a fresh destination.', $error->getMessage()); }
                self::assertTrue($rejected); self::assertSame(0, ftell($identity));
                self::assertSame($state, file_get_contents($stateFile)); self::assertSame($inode, fileinode($sentinel)); self::assertSame('hello world', file_get_contents($sentinel));
                self::assertSame($downloads, $this->reader->downloads); self::assertSame($completed, $this->reader->completedCalls);
            }
            rewind($identity); $rejected = false;
            try { $this->restorer()->recover($this->manifest, $this->receipt, $identity, $destination, $client, 'sqlite', false); }
            catch (RuntimeException $error) { $rejected = true; self::assertStringContainsString('V4 recovery requires a new destination', $error->getMessage()); }
            self::assertTrue($rejected); self::assertSame($state, file_get_contents($stateFile)); self::assertSame('hello world', file_get_contents($sentinel));
            $this->expectException(RuntimeException::class); $this->expectExceptionMessage('Invalid metadata map identity.');
            MetadataMap::open(dirname($stateFile) . '/publication');
        } finally { fclose($identity); $client->close(); }
    }

    public static function durableDatabaseModes(): array { return [['none'], ['mysql']]; }

    /** @dataProvider durableDatabaseModes */
    public function testNonSqlitePublicationLedgersRemainDurable(string $mode): void
    {
        // Never connect to a MySQL server: stop at the summary callback before any database method is reached.
        $database = $mode === 'mysql' ? (new \ReflectionClass(\WordPressR2Backup\MysqlClient::class))->newInstanceWithoutConstructor() : null;
        $identity = $this->identity(); $called = false;
        try {
            $this->expectException(RuntimeException::class); $this->expectExceptionMessage('Synthetic durable ledger probe completed.');
            $this->restorer()->recover($this->manifest, $this->receipt, $identity, $this->directory . '/restored', $database, $database === null ? null : 'wrb_test_synthetic', false, function () use (&$called): void {
                $called = true; $ledgers = glob($this->directory . '/.wrb-v4-*/publication'); self::assertCount(1, $ledgers);
                self::assertNull(MetadataMap::open($ledgers[0])->get('absent'));
                throw new RuntimeException('Synthetic durable ledger probe completed.');
            });
        } finally { fclose($identity); self::assertTrue($called); self::assertDirectoryDoesNotExist($this->directory . '/restored'); }
    }

    private function sqliteClient(): SqliteDatabaseClient
    {
        require_once dirname(__DIR__) . '/dependencies/sqlite-database-integration/wp-includes/database/load.php';
        return new SqliteDatabaseClient($this->directory . '/synthetic.sqlite');
    }

    public function testInspectionDecryptsPagesAndChecksPackReceiptsWithoutReadingPackBodies(): void
    {
        $result = $this->restorer()->inspect($this->manifest, $this->identity(), $this->directory);
        self::assertSame(4, $result['manifestVersion']);
        self::assertFalse($result['verification']['payloadDecrypted']);
        foreach ($this->reader->downloads as $key => $count) { self::assertStringStartsWith('pages/', $key); }
    }

    public function testSqlExportIsPrivateNewAndNeverImports(): void
    {
        $output = $this->directory . '/export.sql';
        $result = $this->restorer()->exportDatabase($this->manifest, $this->identity(), $output);
        self::assertSame("SELECT 1;\n", file_get_contents($output));
        self::assertSame(0600, fileperms($output) & 0777); self::assertFalse($result['databaseImported']);
        $this->expectException(RuntimeException::class);
        $this->restorer()->exportDatabase($this->manifest, $this->identity(), $output);
    }

    public function testResumeRetainsOwnedFileInodesAndHandlesZeroModes(): void
    {
        $this->interruptPublication();
        $path = $this->directory . '/restored/roots/r0001/a.txt'; $inode = lstat($path)['ino'];
        chmod($path, 0000); chmod(dirname($path), 0000);
        $this->reader->failure = null;
        $this->recover(true);
        self::assertSame($inode, lstat($path)['ino']); self::assertSame('hello world', file_get_contents($path));
    }

    public function testResumeRejectsAReplacementFileEvenWhenItsBytesAreEqual(): void
    {
        $this->interruptPublication();
        $path = $this->directory . '/restored/roots/r0001/a.txt'; rename($path, $this->directory . '/original'); file_put_contents($path, 'hello world');
        $this->reader->failure = null; $this->expectException(RuntimeException::class); $this->recover(true);
    }

    public function testResumeRejectsUnknownFiles(): void
    {
        $this->interruptPublication(); file_put_contents($this->directory . '/restored/unowned', 'keep');
        $this->reader->failure = null;
        try { $this->recover(true); self::fail('Unknown destination entries must be rejected.'); }
        catch (RuntimeException $expected) { self::assertSame('keep', file_get_contents($this->directory . '/restored/unowned')); }
    }

    public function testResumeRequiresTheIdenticalAuthenticatedRootReceipt(): void
    {
        $this->interruptPublication(); $this->receipt['sha256'] = str_repeat('f', 64);
        $this->reader->failure = null; $this->expectException(RuntimeException::class); $this->recover(true);
    }

    public function testExistingDestinationIsNeverReplaced(): void
    {
        mkdir($this->directory . '/restored', 0700); file_put_contents($this->directory . '/restored/keep', 'unchanged');
        try { $this->recover(); self::fail('Existing destination must be rejected.'); }
        catch (RuntimeException $expected) { self::assertSame('unchanged', file_get_contents($this->directory . '/restored/keep')); }
    }

    public function testPageCiphertextTamperingFailsBeforePublication(): void
    {
        file_put_contents($this->reader->objects[$this->secondPage], 'tampered');
        try { $this->recover(); self::fail('Tampered ciphertext must fail.'); }
        catch (RuntimeException $expected) { self::assertDirectoryDoesNotExist($this->directory . '/restored'); }
    }

    public function testFoldedPathCollisionsAndMissingLinkTargetsFailBeforePublication(): void
    {
        foreach (['collision', 'missing'] as $variant) {
            $this->fixture($variant);
            try { $this->restorer()->inspect($this->manifest, $this->identity(), $this->directory); self::fail('Invalid filesystem plan must fail.'); }
            catch (RuntimeException $expected) { self::assertDirectoryDoesNotExist($this->directory . '/restored'); }
        }
    }

    /** @dataProvider recoveryMetadataBackends */
    public function testRecoveryStillRejectsCaseFoldedPathsBeforePublication(string $backend): void
    {
        $previous = getenv('WRB_RECOVERY_METADATA_BACKEND'); putenv('WRB_RECOVERY_METADATA_BACKEND=' . $backend);
        $this->fixture('collision');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('V4 paths collide after Unicode normalization and case folding.');
            $this->recover();
        } finally {
            putenv($previous === false ? 'WRB_RECOVERY_METADATA_BACKEND' : 'WRB_RECOVERY_METADATA_BACKEND=' . $previous);
            self::assertDirectoryDoesNotExist($this->directory . '/restored');
        }
    }

    /** @dataProvider recoveryMetadataBackends */
    public function testAnExistingFoldRecordMustMatchTheEntireExpectedValue(string $backend): void
    {
        $map = \WordPressR2Backup\RecoveryMetadataMap::create($this->directory . '/fold-map', $backend);
        $map->put('fold:roots', ['path' => 'roots', 'unexpected' => true]);
        try {
            $register = new \ReflectionMethod(V4Restorer::class, 'register'); $register->setAccessible(true);
            $this->expectException(RuntimeException::class);
            $register->invoke($this->restorer(), 'roots/r0001/a.txt', ['type' => 'file'], $map);
        } finally { $map->close(); }
    }

    public function testAllPackMembersAreAuthenticatedEvenWhenNotReferenced(): void
    {
        $this->fixture('extra');
        self::assertSame(2, $this->manifest['counts']['contents']);
        $this->recover(); self::assertFileExists($this->directory . '/restored/roots/r0001/a.txt');
    }

    public function testAuthenticatedPackMemberAndWholeFileDigestMismatchesFailBeforePublication(): void
    {
        foreach (['pack-mismatch', 'file-mismatch', 'reference-mismatch'] as $variant) {
            $this->fixture($variant);
            try { $this->recover(); self::fail('Mismatched authenticated payload must fail.'); }
            catch (RuntimeException $expected) { self::assertDirectoryDoesNotExist($this->directory . '/restored'); }
            if ($this->restorer()->pending($this->manifest['snapshotId'], $this->directory . '/restored')) { $this->restorer()->discard($this->manifest['snapshotId'], $this->directory . '/restored'); }
        }
    }

    /** @dataProvider recoveryMetadataBackends */
    public function testReferenceReturnsTheAuthenticatedMemberForItsImmediateRead(string $backend): void
    {
        $map = \WordPressR2Backup\RecoveryMetadataMap::create($this->directory . '/member-map', $backend);
        $member = ['id' => hash('sha256', 'synthetic-id'), 'size' => 11, 'sha256' => hash('sha256', 'synthetic-bytes')];
        $reference = $member + ['pack' => 'synthetic-pack'];
        $map->put('member:synthetic-pack:' . $member['id'], $member);
        try {
            $method = new \ReflectionMethod(V4Restorer::class, 'reference'); $method->setAccessible(true);
            self::assertSame($member, $method->invoke($this->restorer(), $reference, $map));
            self::assertSame(['id' => $member['id']], $map->get('reference:' . $member['id']));
        } finally { $map->close(); }
    }

    /** @dataProvider recoveryMetadataBackends */
    public function testContentWithoutAnImmediateAuthenticatedMemberStillRejectsAMismatch(string $backend): void
    {
        $map = \WordPressR2Backup\RecoveryMetadataMap::create($this->directory . '/mismatched-member-map', $backend);
        $member = ['id' => hash('sha256', 'synthetic-id'), 'size' => 11, 'sha256' => hash('sha256', 'synthetic-bytes')];
        $reference = $member + ['pack' => 'synthetic-pack'];
        $map->put('member:synthetic-pack:' . $member['id'], array_replace($member, ['size' => 12]));
        try {
            $method = new \ReflectionMethod(V4Restorer::class, 'content'); $method->setAccessible(true);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('V4 content reference differs from its pack member.');
            $method->invoke($this->restorer(), $reference, $map, $this->directory);
        } finally { $map->close(); }
    }

    public function testExclusionPolicyRejectsUnreferencedPackMembers(): void
    {
        $this->fixture('extra'); $this->manifest['wordpress']['policy']['preset'] = 'custom';
        $this->manifest['wordpress']['policy']['paths']['private'] = 'exclude';
        $this->manifest['wordpress']['policySha256'] = hash('sha256', V4Format::json($this->manifest['wordpress']['policy']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Excluded v4 snapshot contains unreferenced pack content.');
        $this->restorer()->inspect($this->manifest, $this->identity(), $this->directory);
    }

    public static function referenceCountCases(): array { return [['files', false], ['sqlite', false], ['files', true], ['sqlite', true]]; }

    /** @dataProvider referenceCountCases */
    public function testRecoveryRejectsAReferenceCountMismatchBeforePublication(string $backend, bool $excluded): void
    {
        $previous = getenv('WRB_RECOVERY_METADATA_BACKEND'); putenv('WRB_RECOVERY_METADATA_BACKEND=' . $backend);
        if ($excluded) {
            $this->fixture('extra'); $this->manifest['wordpress']['policy']['preset'] = 'custom';
            $this->manifest['wordpress']['policy']['paths']['private'] = 'exclude';
            $this->manifest['wordpress']['policySha256'] = hash('sha256', V4Format::json($this->manifest['wordpress']['policy']));
        }
        ++$this->manifest['counts']['contents'];
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('V4 referenced content count differs.');
            $this->recover();
        } finally {
            self::assertDirectoryDoesNotExist($this->directory . '/restored');
            putenv($previous === false ? 'WRB_RECOVERY_METADATA_BACKEND' : 'WRB_RECOVERY_METADATA_BACKEND=' . $previous);
        }
    }

    /** @dataProvider recoveryMetadataBackends */
    public function testRecoveryWithExclusionsRejectsHiddenUnreferencedContentBeforePublication(string $backend): void
    {
        $previous = getenv('WRB_RECOVERY_METADATA_BACKEND'); putenv('WRB_RECOVERY_METADATA_BACKEND=' . $backend);
        $this->fixture('extra'); $this->manifest['wordpress']['policy']['preset'] = 'custom';
        $this->manifest['wordpress']['policy']['paths']['private'] = 'exclude';
        $this->manifest['wordpress']['policySha256'] = hash('sha256', V4Format::json($this->manifest['wordpress']['policy']));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Excluded v4 snapshot contains unreferenced pack content.');
            $this->recover();
        } finally {
            self::assertDirectoryDoesNotExist($this->directory . '/restored');
            putenv($previous === false ? 'WRB_RECOVERY_METADATA_BACKEND' : 'WRB_RECOVERY_METADATA_BACKEND=' . $previous);
        }
    }

    public function testResumeRejectsModifiedCompletedFileAndAddedHardLinks(): void
    {
        $this->interruptPublication(); $path = $this->directory . '/restored/roots/r0001/a.txt';
        file_put_contents($path, 'bad content'); $this->reader->failure = null;
        try { $this->recover(true); self::fail('Modified complete file must fail.'); }
        catch (RuntimeException $expected) { self::assertSame('bad content', file_get_contents($path)); }
        file_put_contents($path, 'hello world'); link($path, $this->directory . '/external-alias');
        $this->expectException(RuntimeException::class); $this->recover(true);
    }

    public function testSingleFileSourceRootUsesItsContainerAndOriginalFileMode(): void
    {
        $this->fixture('single'); $this->recover();
        $path = $this->directory . '/restored/roots/r0001/a.txt';
        self::assertSame('hello world', file_get_contents($path));
        self::assertSame(0400, fileperms($path) & 0777);
    }

    public function testOriginalZeroFileAndDirectoryModesAreAppliedAfterVerification(): void
    {
        $this->fixture('zero'); $this->recover();
        $path = $this->directory . '/restored/roots/r0001';
        self::assertSame(0000, fileperms($path) & 0777); chmod($path, 0700);
        self::assertSame(0000, fileperms($path . '/a.txt') & 0777);
    }

    public function testOperationLockRejectsConcurrentRecovery(): void
    {
        $this->interruptPublication(); $destination = $this->directory . '/restored';
        $workspace = $this->directory . '/.wrb-v4-' . hash('sha256', $this->reader->unitId() . "\0" . $this->manifest['snapshotId'] . "\0" . $destination);
        $lock = fopen($workspace . '/job.lock', 'r+b'); self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try { $this->reader->failure = null; $this->recover(true); self::fail('Concurrent recovery must fail.'); }
        catch (RuntimeException $expected) { self::assertStringContainsString('already running', $expected->getMessage()); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function testLocalMediaDryRunVerifiesAllMediaButPublishesOnlySystemFilesAndExactCounts(): void
    {
        $this->fixture('media'); $preview = null; $identity = $this->identity();
        try {
            $result = $this->restorer()->recover($this->manifest, $this->receipt, $identity, $this->directory . '/restored', null, null, false,
                static function (array $summary) use (&$preview): void { $preview = $summary; }, 'roots/r0001/wp-content/uploads');
        } finally { fclose($identity); }
        $root = $this->directory . '/restored/roots/r0001';
        self::assertDirectoryDoesNotExist($root . '/wp-content/uploads');
        self::assertSame('hello world', file_get_contents($root . '/wp-content/themes/icon.svg'));
        self::assertSame('hello world', file_get_contents($root . '/wp-content/uploads-not-media/keep.txt'));
        self::assertSame('nested//', readlink($root . '/shortcut'));
        self::assertSame(['files' => 2, 'directories' => 2, 'links' => 1, 'bytes' => 33, 'contentsVerified' => true], $result['mediaDryRun']);
        self::assertSame([4, 4, 1, 44], [$result['files'], $result['directories'], $result['links'], $result['bytes']]);
        self::assertSame('local-system-media-dry-run', $result['restoreMode']);
        self::assertSame($result['mediaDryRun'], $preview['mediaDryRun']);
        self::assertFalse($result['databaseImported']);
        self::assertSame(0640, fileperms($root . '/wp-content/themes/icon.svg') & 0777);
    }

    public function testLocalMediaPrefixMatchesTheSavedRootNameRatherThanItsId(): void
    {
        $this->fixture('media'); $this->manifest['roots'][0]['name'] = 'r0042';
        $this->receipt['sha256'] = hash('sha256', V4Format::json($this->manifest));
        $result = $this->recover(false, 'roots/r0042/wp-content/uploads');
        self::assertSame(2, $result['mediaDryRun']['files']);
        self::assertDirectoryDoesNotExist($this->directory . '/restored/roots/r0042/wp-content/uploads');
        self::assertFileExists($this->directory . '/restored/roots/r0042/wp-content/themes/icon.svg');
    }

    public function testAnInvalidAuthenticatedMediaFileDigestStillFailsBeforeAnyPublication(): void
    {
        $this->fixture('media-file-mismatch');
        try { $this->recover(false, 'roots/r0001/wp-content/uploads'); self::fail('Dry-run media content must still be verified.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('file size or digest differs', $error->getMessage()); self::assertDirectoryDoesNotExist($this->directory . '/restored'); }
    }

    public function testLocalMediaModeFailsClosedWhenTheStandardUploadsDirectoryIsAbsent(): void
    {
        try { $this->recover(false, 'roots/r0001/wp-content/uploads'); self::fail('Custom media locations must not be inferred.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('standard uploads directory', $error->getMessage()); self::assertDirectoryDoesNotExist($this->directory . '/restored'); }
    }

    public function testLocalMediaModeRejectsRetainedLinksToAndThroughTheOmittedDirectory(): void
    {
        foreach (['media-link-target', 'media-link-traversal'] as $variant) {
            $this->fixture($variant);
            try { $this->recover(false, 'roots/r0001/wp-content/uploads'); self::fail('A system link must not depend on omitted media.'); }
            catch (RuntimeException $error) { self::assertStringContainsString('system link traverses dry-run media', $error->getMessage()); self::assertDirectoryDoesNotExist($this->directory . '/restored'); }
        }
    }

    public function testPartialRecoveryBindsTheMediaPrefixAndRejectsFullOrDifferentPrefixResume(): void
    {
        $this->fixture('media'); $prefix = 'roots/r0001/wp-content/uploads'; $this->interruptPublication($prefix); $this->reader->failure = null;
        foreach ([null, 'roots/r0002/wp-content/uploads'] as $changed) {
            try { $this->recover(true, $changed); self::fail('The media publication scope must remain bound on resume.'); }
            catch (RuntimeException $error) { self::assertStringContainsString('V4 resume requires', $error->getMessage()); }
        }
        $result = $this->recover(true, $prefix); self::assertSame(2, $result['mediaDryRun']['files']);
        self::assertDirectoryDoesNotExist($this->directory . '/restored/roots/r0001/wp-content/uploads');
    }

    public function testAFullRecoveryRetainsTheOldBindingAndCannotResumeAsAPartialRecovery(): void
    {
        $this->fixture('media'); $this->interruptPublication(); $this->reader->failure = null;
        $state = json_decode(file_get_contents(glob($this->directory . '/.wrb-v4-*/state.json')[0]), true, 32, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('publication', $state['binding']);
        try { $this->recover(true, 'roots/r0001/wp-content/uploads'); self::fail('Full recovery cannot be changed to a media dry run.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('V4 resume requires', $error->getMessage()); }
        $result = $this->recover(true); self::assertArrayNotHasKey('mediaDryRun', $result); self::assertSame(6, $result['files']);
        self::assertSame('hello worldhello world', file_get_contents($this->directory . '/restored/roots/r0001/wp-content/uploads/2026/photo.jpg'));
    }

    public function testUnknownFilesUnderTheOmittedMediaPrefixArePreservedAndRejectedOnResume(): void
    {
        $this->fixture('media'); $prefix = 'roots/r0001/wp-content/uploads'; $this->interruptPublication($prefix); $this->reader->failure = null;
        $uploads = $this->directory . '/restored/roots/r0001/wp-content/uploads'; mkdir($uploads, 0700, true); file_put_contents($uploads . '/unowned', 'keep');
        try { $this->recover(true, $prefix); self::fail('Unknown media entries must not be adopted or removed.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('Unknown file', $error->getMessage()); self::assertSame('keep', file_get_contents($uploads . '/unowned')); }
    }

    private function interruptPublication(?string $localMediaDryRunPrefix = null): void
    {
        $key = $this->secondPage;
        $this->reader->failure = static function (string $downloaded, int $count) use ($key): void {
            if ($downloaded === $key && $count === 2) { throw new RuntimeException('Synthetic interruption during publication.'); }
        };
        try { $this->recover(false, $localMediaDryRunPrefix); self::fail('Expected synthetic interruption.'); }
        catch (RuntimeException $error) { self::assertSame('Synthetic interruption during publication.', $error->getMessage()); }
        self::assertFileExists($this->directory . '/restored/roots/r0001/a.txt');
    }

    private function fixture(string $variant = ''): void
    {
        $snapshot = hash('sha256', 'synthetic-' . $variant); $recipients = array_column($this->keys, 'public');
        $scope = ['accountId' => str_repeat('b', 32), 'bucket' => 'fixture-bucket', 'unitId' => $this->reader->unitId(), 'keySetId' => Manifest::keySetId($recipients), 'encoding' => V4Format::ENCODING];
        $create = 'CREATE TABLE `wp_probe` (`id` bigint unsigned NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB';
        $sqlBytes = $variant === 'sqlite' ? $create . ";\nINSERT INTO `wp_probe` (`id`) VALUES (1);\n" : "SELECT 1;\n";
        $text = $this->content('hello world'); $sql = $this->content($sqlBytes); $members = [$text, $sql];
        if ($variant === 'extra') { $members[] = $this->content('old unreferenced content'); }
        $pack = V4Pack::write($this->age, $recipients, $this->directory, V4Format::scopeId($scope), 'code', $members);
        if ($variant === 'pack-mismatch') { $other = $this->content('different declared content'); unset($other['bytes']); $pack['descriptor']['members'][0] = $other; }
        $this->reader->objects[$pack['descriptor']['object']['key']] = $pack['cipherFile'];
        $textRef = $text; unset($textRef['bytes']); $textRef['pack'] = $pack['descriptor']['object']['key'];
        if ($variant === 'reference-mismatch') { $textRef = $this->content('unknown content'); unset($textRef['bytes']); $textRef['pack'] = $pack['descriptor']['object']['key']; }
        $sqlRef = $sql; unset($sqlRef['bytes']); $sqlRef['pack'] = $pack['descriptor']['object']['key'];
        $first = [['type' => 'file', 'rootId' => 'r0001', 'path' => 'a.txt', 'size' => 11, 'mode' => 0400], ['type' => 'part', 'content' => $textRef]];
        $second = [['type' => 'end', 'sha256' => $text['sha256']], ['type' => 'directory', 'rootId' => 'r0001', 'path' => 'nested', 'mode' => 0750], ['type' => 'file', 'rootId' => 'r0001', 'path' => $variant === 'collision' ? 'A.txt' : 'nested/b.txt', 'size' => 11, 'mode' => 0640], ['type' => 'part', 'content' => $textRef], ['type' => 'end', 'sha256' => $text['sha256']], ['type' => 'symlink', 'rootId' => 'r0001', 'path' => 'shortcut', 'target' => $variant === 'missing' ? 'absent' : 'nested//']];
        if ($variant === 'dense') {
            for ($number = 0; count($this->densePaths) < 65; $number++) {
                $path = 'dense-' . $number . '.txt';
                if (substr(hash('sha256', 'entry:roots/r0001/' . $path), 0, 3) !== 'abc') { continue; }
                $this->densePaths[] = $path;
                $second[] = ['type' => 'file', 'rootId' => 'r0001', 'path' => $path, 'size' => 11, 'mode' => 0640];
                $second[] = ['type' => 'part', 'content' => $textRef];
                $second[] = ['type' => 'end', 'sha256' => $text['sha256']];
            }
        }
        if (strpos($variant, 'media') === 0) {
            $second = array_merge($second, [
                ['type' => 'directory', 'rootId' => 'r0001', 'path' => 'wp-content', 'mode' => 0750],
                ['type' => 'directory', 'rootId' => 'r0001', 'path' => 'wp-content/themes', 'mode' => 0750],
                ['type' => 'file', 'rootId' => 'r0001', 'path' => 'wp-content/themes/icon.svg', 'size' => 11, 'mode' => 0640], ['type' => 'part', 'content' => $textRef], ['type' => 'end', 'sha256' => $text['sha256']],
                ['type' => 'directory', 'rootId' => 'r0001', 'path' => 'wp-content/uploads', 'mode' => 0750],
                ['type' => 'directory', 'rootId' => 'r0001', 'path' => 'wp-content/uploads/2026', 'mode' => 0750],
                ['type' => 'file', 'rootId' => 'r0001', 'path' => 'wp-content/uploads/2026/photo.jpg', 'size' => 22, 'mode' => 0640], ['type' => 'part', 'content' => $textRef], ['type' => 'part', 'content' => $textRef], ['type' => 'end', 'sha256' => $variant === 'media-file-mismatch' ? str_repeat('f', 64) : hash('sha256', 'hello worldhello world')],
                ['type' => 'file', 'rootId' => 'r0001', 'path' => 'wp-content/uploads/second.jpg', 'size' => 11, 'mode' => 0640], ['type' => 'part', 'content' => $textRef], ['type' => 'end', 'sha256' => $text['sha256']],
                ['type' => 'symlink', 'rootId' => 'r0001', 'path' => 'wp-content/uploads/shortcut', 'target' => '../themes/icon.svg'],
                ['type' => 'directory', 'rootId' => 'r0001', 'path' => 'wp-content/uploads-not-media', 'mode' => 0750],
                ['type' => 'file', 'rootId' => 'r0001', 'path' => 'wp-content/uploads-not-media/keep.txt', 'size' => 11, 'mode' => 0640], ['type' => 'part', 'content' => $textRef], ['type' => 'end', 'sha256' => $text['sha256']],
            ]);
            if (in_array($variant, ['media-link-target', 'media-link-traversal'], true)) { $second[] = ['type' => 'symlink', 'rootId' => 'r0001', 'path' => 'media-shortcut', 'target' => $variant === 'media-link-target' ? 'wp-content/uploads/2026/photo.jpg' : 'wp-content/uploads/../themes/icon.svg']; }
        }
        if ($variant === 'file-mismatch') { $second[0]['sha256'] = str_repeat('f', 64); }
        if ($variant === 'single') { $second = [$second[0]]; }
        if ($variant === 'zero') { $first[0]['mode'] = 0; }
        $pages = [$this->page($snapshot, 'packs', 0, [$pack['descriptor']]), $this->page($snapshot, 'filesystem', 0, $first), $this->page($snapshot, 'filesystem', 1, $variant === 'single' ? $second : array_slice($second, 0, 2))];
        if ($variant !== 'single') { $pages[] = $this->page($snapshot, 'filesystem', 2, array_slice($second, 2)); }
        $databaseRecords = [['type' => 'sql', 'stage' => 'preamble', 'name' => 'database', 'content' => $sqlRef]];
        if ($variant === 'sqlite') {
            $databaseRecords[] = ['type' => 'table', 'name' => 'wp_probe', 'rows' => 1, 'sourceRows' => 1, 'excludedRows' => 0,
                'definitionSha256' => WordPressDatabase::definitionSha256($create, ''), 'rowSha256' => hash('sha256', hash('sha256', 'V1:1;') . "\n"),
                'columns' => ['id'], 'order' => ['id'], 'foreignKeys' => []];
        }
        $pages[] = $this->page($snapshot, 'database', 0, $databaseRecords);
        $this->secondPage = $pages[$variant === 'single' ? 2 : 3]['object']['key'];
        $policy = ['version' => 2, 'preset' => 'full'];
        $this->manifest = ['format' => 'wordpress-r2-backup', 'version' => 4, 'dataClass' => 'wordpress', 'unitId' => $scope['unitId'], 'snapshotId' => $snapshot, 'keySetId' => $scope['keySetId'], 'recipients' => $recipients, 'capturedAt' => '2026-10-30T00:00:00Z', 'encoding' => V4Format::ENCODING, 'scope' => $scope, 'roots' => [['id' => 'r0001', 'name' => 'r0001', 'sourcePath' => '/does-not-exist/source', 'type' => 'directory', 'mode' => 0750]], 'pages' => $pages, 'counts' => ['files' => 2, 'directories' => 1, 'links' => 1, 'contents' => 2, 'packs' => 1, 'databaseBytes' => strlen($sql['bytes']), 'fileBytes' => 22], 'database' => ['size' => strlen($sql['bytes']), 'sha256' => $sql['sha256'], 'tableCount' => 0, 'events' => [], 'environment' => ['serverVersion' => '8.0.40', 'sqlMode' => '', 'timeZone' => '+00:00', 'characterSet' => 'utf8mb4', 'collation' => 'utf8mb4_bin']], 'wordpress' => ['siteUrl' => 'https://synthetic.invalid', 'policySha256' => hash('sha256', V4Format::json($policy)), 'policy' => $policy, 'evidence' => []]];
        if ($variant === 'single') {
            $this->manifest['roots'][0]['type'] = 'file'; $this->manifest['roots'][0]['sourcePath'] = '/missing/a.txt';
            $this->manifest['counts']['files'] = 1; $this->manifest['counts']['fileBytes'] = 11;
            $this->manifest['counts']['directories'] = 0; $this->manifest['counts']['links'] = 0;
        }
        if ($variant === 'zero') { $this->manifest['roots'][0]['mode'] = 0; }
        if ($variant === 'dense') { $this->manifest['counts']['files'] = 67; $this->manifest['counts']['fileBytes'] = 67 * 11; }
        if (strpos($variant, 'media') === 0) {
            $this->manifest['counts']['files'] = 6; $this->manifest['counts']['fileBytes'] = 77; $this->manifest['counts']['directories'] = 6;
            $this->manifest['counts']['links'] = in_array($variant, ['media-link-target', 'media-link-traversal'], true) ? 3 : 2;
            $this->manifest['roots'][] = ['id' => 'r0002', 'name' => 'r0002', 'sourcePath' => '/does-not-exist/other-source', 'type' => 'directory', 'mode' => 0700];
        }
        if ($variant === 'sqlite') { $this->manifest['database']['tableCount'] = 1; }
        $this->receipt = ['key' => 'manifests/' . $snapshot . '.age', 'bytes' => 1, 'sha256' => hash('sha256', V4Format::json($this->manifest))];
    }

    private function page(string $snapshot, string $kind, int $sequence, array $records): array
    {
        $bytes = V4Format::json(['version' => 4, 'snapshotId' => $snapshot, 'kind' => $kind, 'sequence' => $sequence, 'records' => $records]);
        $key = V4Format::pageKey($snapshot); $path = $this->directory . '/fixture-' . basename($key);
        $this->age->encryptStream([$bytes], $path, array_column($this->keys, 'public'));
        $this->reader->objects[$key] = $path;
        return ['kind' => $kind, 'sequence' => $sequence, 'count' => count($records), 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'object' => ['key' => $key, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)]];
    }

    private function content(string $bytes): array
    {
        $hash = hash('sha256', $bytes);
        return ['id' => V4Format::contentId(strlen($bytes), $hash), 'size' => strlen($bytes), 'sha256' => $hash, 'bytes' => $bytes];
    }

    private function identity()
    {
        $stream = fopen('php://memory', 'w+b'); fwrite($stream, file_get_contents($this->keys[0]['identity'])); rewind($stream); return $stream;
    }

    private function recover(bool $resume = false, ?string $localMediaDryRunPrefix = null, ?callable $progress = null): array
    {
        $stream = $this->identity();
        try { return $this->restorer()->recover($this->manifest, $this->receipt, $stream, $this->directory . '/restored', null, null, $resume, null, $localMediaDryRunPrefix, null, $progress); }
        finally { fclose($stream); }
    }

    private function restorer(): V4Restorer { return new V4Restorer($this->age, $this->reader); }

    private function makeReadable(string $path): void
    {
        if (!is_dir($path) || is_link($path)) { return; }
        chmod($path, 0700);
        foreach (new \FilesystemIterator($path) as $entry) { if (!$entry->isLink() && $entry->isDir()) { $this->makeReadable($entry->getPathname()); } elseif (!$entry->isLink()) { chmod($entry->getPathname(), 0600); } }
    }
}

final class V4RecoveryReader implements ObjectReader
{
    private string $unit;
    public array $objects = [];
    public array $downloads = [];
    public int $completedCalls = 0;
    public $failure = null;
    public function __construct(string $unit) { $this->unit = $unit; }
    public function unitId(): string { return $this->unit; }
    public function verify(array $receipt): void
    {
        $path = $this->objects[$receipt['key']] ?? '';
        if (!is_file($path) || filesize($path) !== $receipt['bytes'] || hash_file('sha256', $path) !== $receipt['sha256']) { throw new RuntimeException('Synthetic ciphertext receipt mismatch.'); }
    }
    public function download(array $receipt, string $newCipherFile): void
    {
        $count = ($this->downloads[$receipt['key']] ?? 0) + 1; $this->downloads[$receipt['key']] = $count;
        if ($this->failure !== null) { ($this->failure)($receipt['key'], $count); }
        $this->verify($receipt); $stream = fopen($newCipherFile, 'x+b'); chmod($newCipherFile, 0600);
        $source = fopen($this->objects[$receipt['key']], 'rb'); stream_copy_to_stream($source, $stream); fclose($source); fclose($stream);
    }
    public function completed(string $snapshotId): array { ++$this->completedCalls; throw new RuntimeException('Completed index must not be read.'); }
    public function listCompleted(): array { throw new RuntimeException('No index is available.'); }
    public function listStatus(): array { return []; }
    public function listFileObjects(string $keySetId): array { throw new RuntimeException('No prior objects are available.'); }
}
