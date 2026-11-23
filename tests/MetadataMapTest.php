<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\CaptureMetadataBatch;
use WordPressR2Backup\MetadataMap;

final class MetadataMapTest extends TestCase
{
    public function testSmallValuesSplitBeforeAReadMustValidateMoreThanSixtyFourRecords(): void
    {
        $directory = Fixture::temp() . '/metadata';
        $map = MetadataMap::create($directory, 1048576, 64);
        $ordinaryDirectory = Fixture::temp() . '/ordinary-metadata';
        $ordinary = MetadataMap::create($ordinaryDirectory);
        $expected = [];
        for ($number = 0; count($expected) < 65; $number++) {
            $key = 'bounded-' . $number;
            if (substr(hash('sha256', $key), 0, 3) !== 'abc') { continue; }
            $expected[$key] = ['id' => $number];
            $map->put($key, $expected[$key]);
            $ordinary->put($key, $expected[$key]);
        }
        self::assertFileExists($directory . '/branch-abc');
        self::assertFileDoesNotExist($ordinaryDirectory . '/branch-abc');
        $map = MetadataMap::open($directory);
        foreach ($expected as $key => $value) { self::assertSame($value, $map->get($key)); }
        $actual = iterator_to_array($map->entries());
        ksort($actual); ksort($expected);
        self::assertSame($expected, $actual);
        foreach (glob($directory . '/leaf-*.json') as $file) {
            $leaf = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
            self::assertLessThanOrEqual(64, count($leaf['records']));
        }
    }

    public function testEarlySplittingKeepsTheExistingSingleValueSizeLimit(): void
    {
        $directory = Fixture::temp() . '/metadata';
        $map = MetadataMap::create($directory, 1048576, 64);
        $value = ['descriptor' => str_repeat('x', 60000)];
        $map->put('large-single-value', $value);
        self::assertSame($value, MetadataMap::open($directory)->get('large-single-value'));
    }

    public function testARecordLimitMustBePositive(): void
    {
        $this->expectException(\RuntimeException::class);
        MetadataMap::create(Fixture::temp() . '/metadata', 1048576, 0);
    }

    public function testChangedOrMissingSplitPartitionsAreRejectedByTheSameMap(): void
    {
        foreach (['tampered', 'missing', 'permissions'] as $variant) {
            $directory = Fixture::temp() . '/metadata';
            $map = MetadataMap::create($directory, 1048576, 1);
            $key = 'first'; $hash = hash('sha256', $key);
            for ($number = 0; ; $number++) {
                $second = 'second-' . $number;
                if (substr(hash('sha256', $second), 0, 3) === substr($hash, 0, 3)) { break; }
            }
            $map->put($key, ['id' => 'saved']); $map->put($second, ['id' => 'other']);
            self::assertSame(['id' => 'saved'], $map->get($key));
            $target = null;
            foreach (glob($directory . '/leaf-*.json') as $file) {
                $leaf = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
                if (isset($leaf['records'][$hash])) { $target = $file; break; }
            }
            self::assertNotNull($target);
            if ($variant === 'missing') { unlink($target); }
            elseif ($variant === 'permissions') { chmod($target, 0644); }
            else { $leaf['records'][$hash]['value']['id'] = 'changed'; file_put_contents($target, json_encode($leaf, JSON_THROW_ON_ERROR)); }
            $rejected = false;
            $rejected = false;
            try { $map->get($key); self::fail('Changed temporary metadata must be rejected.'); }
            catch (\RuntimeException $expected) { self::assertNotSame('', $expected->getMessage()); }
        }
    }

    public function testPartitionsSurviveReopeningAndKeepEachValue(): void
    {
        $directory = Fixture::temp() . '/metadata';
        $map = MetadataMap::create($directory, 1024);
        $expected = [];
        for ($number = 0; $number < 180; $number++) {
            $key = '内容/' . $number;
            $expected[$key] = ['id' => hash('sha256', $key)];
            $map->put($key, $expected[$key]);
        }
        $map = MetadataMap::open($directory);
        foreach ($expected as $key => $value) { self::assertSame($value, $map->get($key)); }
        $actual = iterator_to_array($map->entries());
        ksort($actual); ksort($expected);
        self::assertSame($expected, $actual);
        foreach (glob($directory . '/leaf-*.json') as $file) { self::assertLessThanOrEqual(1024, filesize($file)); }
        self::assertNull($map->get('absent'));
        $map->put('内容/0', ['id' => 'updated'], true);
        self::assertSame(['id' => 'updated'], MetadataMap::open($directory)->get('内容/0'));
    }

    public function testConflictingOwnershipCannotReplaceAnEntry(): void
    {
        $map = MetadataMap::create(Fixture::temp() . '/metadata', 512);
        $map->put('path', ['inode' => 123]);
        $map->put('path', ['inode' => 123]);
        $this->expectException(\RuntimeException::class);
        $map->put('path', ['inode' => 456]);
    }

    public function testLostPartitionFailsInsteadOfReturningAnEmptyMap(): void
    {
        $directory = Fixture::temp() . '/metadata';
        $map = MetadataMap::create($directory);
        $map->put('path', ['id' => 'saved']);
        unlink(glob($directory . '/leaf-*.json')[0]);
        $this->expectException(\RuntimeException::class);
        $map->get('path');
    }

    public function testAChangedMetadataIdentityIsRejected(): void
    {
        $directory = Fixture::temp() . '/metadata';
        MetadataMap::create($directory);
        file_put_contents($directory . '/owner', 'another owner');
        $this->expectException(\RuntimeException::class);
        MetadataMap::open($directory);
    }

    public function testAChangedValidJsonPartitionIsRejected(): void
    {
        $directory = Fixture::temp() . '/metadata';
        $map = MetadataMap::create($directory);
        $map->put('path', ['id' => 'saved']);
        $file = glob($directory . '/leaf-*.json')[0];
        $data = json_decode(file_get_contents($file), true);
        $data['records'][hash('sha256', 'path')]['value']['id'] = 'changed';
        file_put_contents($file, json_encode($data));
        $this->expectException(\RuntimeException::class);
        $map->get('path');
    }

    public function testDensePartitionSplitsWithoutLosingExistingKeys(): void
    {
        $directory = Fixture::temp() . '/metadata';
        $map = MetadataMap::create($directory, 1024);
        $keys = [];
        for ($number = 0; count($keys) < 24; $number++) {
            $key = 'dense-' . $number;
            if (substr(hash('sha256', $key), 0, 3) !== 'abc') { continue; }
            $keys[] = $key;
            $map->put($key, ['value' => $number]);
        }
        $map = MetadataMap::open($directory);
        self::assertCount(24, iterator_to_array($map->entries()));
        foreach ($keys as $key) { self::assertNotNull($map->get($key)); }
        foreach (glob($directory . '/leaf-*.json') as $file) { self::assertLessThanOrEqual(1024, filesize($file)); }
    }

    public function testFullyPopulatedPartitionRegistryCanBeReopened(): void
    {
        $directory = Fixture::temp() . '/metadata';
        $map = MetadataMap::create($directory);
        $keys = [];
        for ($number = 0; count($keys) < 4096; $number++) {
            $key = 'registry-' . $number;
            $keys[substr(hash('sha256', $key), 0, 3)] = $key;
        }
        foreach ($keys as $key) { $map->put($key, ['id' => 'saved']); }
        $map = MetadataMap::open($directory);
        foreach ($keys as $key) { self::assertSame(['id' => 'saved'], $map->get($key)); }
        self::assertCount(4096, iterator_to_array($map->entries()));
    }

    public function testPartitionBytesMatchTheDocumentedFormat(): void
    {
        $directory = Fixture::temp() . '/metadata';
        $map = MetadataMap::create($directory, 1024);
        for ($number = 0; $number < 60; $number++) { $map->put('format/' . $number, ['n' => $number, 'path' => '内容/a b/' . $number]); }
        $leaves = glob($directory . '/leaf-*.json');
        self::assertNotEmpty($leaves);
        foreach ($leaves as $file) {
            $data = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
            $records = json_encode($data['records'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $expected = json_encode(['version' => 1, 'records' => $data['records'], 'checksum' => hash('sha256', $records)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            self::assertSame($expected, file_get_contents($file));
        }
    }

    /** @dataProvider changedAncestorOperations */
    public function testAReplacedAncestorIsRejectedByTheNextCallOfTheSameMap(string $operation): void
    {
        $outer = Fixture::temp() . '/outer'; mkdir($outer, 0700);
        $map = MetadataMap::create($outer . '/metadata');
        $map->put('first', ['id' => 1]);
        $moved = Fixture::temp() . '/moved';
        rename($outer, $moved); symlink($moved, $outer);
        $this->expectException(\RuntimeException::class);
        if ($operation === 'get') { $map->get('first'); }
        elseif ($operation === 'put') { $map->put('second', ['id' => 2]); }
        else { iterator_to_array($map->entries()); }
    }

    public function changedAncestorOperations(): array { return [['get'], ['put'], ['entries']]; }

    public function testAReplacedLeafIsNeverFollowedOrOverwrittenThroughALink(): void
    {
        $directory = Fixture::temp() . '/metadata';
        $map = MetadataMap::create($directory);
        $map->put('path', ['id' => 'saved']);
        $leaf = glob($directory . '/leaf-*.json')[0];
        $outside = Fixture::temp() . '/outside.json'; file_put_contents($outside, 'synthetic outside marker'); chmod($outside, 0600);
        unlink($leaf); symlink($outside, $leaf);
        foreach ([static function () use ($map) { $map->get('path'); }, static function () use ($map) { $map->put('path', ['id' => 'other'], true); }] as $operation) {
            try { $operation(); }
            catch (\RuntimeException $expected) { $rejected = true; }
            self::assertTrue($rejected, 'A linked partition must be rejected.');
        }
        self::assertSame('synthetic outside marker', file_get_contents($outside));
        self::assertTrue(is_link($leaf));
    }

    public function testAChangedPartitionRegistryIsRejectedBeforeEveryOperation(): void
    {
        $directory = Fixture::temp() . '/metadata';
        $map = MetadataMap::create($directory);
        $map->put('path', ['id' => 'saved']);
        $registry = json_decode(file_get_contents($directory . '/partitions.json'), true);
        $registry['checksum'] = str_repeat('0', 64);
        file_put_contents($directory . '/partitions.json', json_encode($registry));
        foreach ([static function () use ($map) { $map->get('path'); }, static function () use ($map) { $map->put('other', ['id' => 1]); }, static function () use ($map) { iterator_to_array($map->entries()); }] as $operation) {
            try { $operation(); }
            catch (\RuntimeException $expected) { $rejected = true; }
            self::assertTrue($rejected, 'A changed registry must be rejected.');
        }
    }

    public function testAnotherProcessChangingAPartitionModeIsSeenByTheNextCall(): void
    {
        $directory = Fixture::temp() . '/metadata';
        $map = MetadataMap::create($directory);
        $map->put('path', ['id' => 'saved']);
        self::assertSame(['id' => 'saved'], $map->get('path'));
        $leaf = glob($directory . '/leaf-*.json')[0];
        $process = proc_open([PHP_BINARY, '-r', 'exit(chmod($argv[1], 0644) ? 0 : 3);', $leaf], [], $pipes);
        self::assertSame(0, proc_close($process));
        $this->expectException(\RuntimeException::class);
        $map->get('path');
    }

    public function testWarmPartitionStillReadsSameLengthChangesAndValidUpdates(): void
    {
        $directory = Fixture::temp() . '/metadata';
        $map = MetadataMap::create($directory);
        $map->put('path', ['id' => 'saved']);
        self::assertSame(['id' => 'saved'], $map->get('path'));
        $leaf = glob($directory . '/leaf-*.json')[0];
        $original = file_get_contents($leaf);
        $changed = str_replace('saved', 'other', $original);
        self::assertSame(strlen($original), strlen($changed));
        file_put_contents($leaf, $changed);
        try { $map->get('path'); self::fail('Same-length corruption must be rejected after a successful read.'); }
        catch (\RuntimeException $expected) { self::assertNotSame('', $expected->getMessage()); }
        file_put_contents($leaf, $original);
        self::assertSame(['id' => 'saved'], $map->get('path'));
        MetadataMap::open($directory)->put('path', ['id' => 'valid update'], true);
        self::assertSame(['id' => 'valid update'], $map->get('path'));
    }

    public function testReturnedNestedValuesCannotChangeTheNextRead(): void
    {
        $map = MetadataMap::create(Fixture::temp() . '/metadata');
        $map->put('path', ['nested' => ['id' => 'saved']]);
        $value = $map->get('path');
        $value['nested']['id'] = 'changed';
        self::assertSame(['nested' => ['id' => 'saved']], $map->get('path'));
    }

    public function testChangedProcessLocalLeafStillValidatesOtherRecordsAfterReadingAnotherLeaf(): void
    {
        $directory = Fixture::temp() . '/local';
        $map = MetadataMap::createProcessLocal($directory);
        $firstHash = hash('sha256', 'path');
        $prefix = substr($firstHash, 0, 3);
        for ($number = 0; ; ++$number) {
            $neighbor = 'neighbor-' . $number;
            $neighborHash = hash('sha256', $neighbor);
            if (substr($neighborHash, 0, 3) === $prefix) { break; }
        }
        $map->put('path', ['id' => 1]);
        $map->put($neighbor, ['id' => 2]);
        $map->put('other', ['id' => 3]);
        self::assertSame(['id' => 1], $map->get('path'));
        self::assertSame(['id' => 3], $map->get('other'));

        $leaf = $directory . '/leaf-' . $prefix . '.json';
        $original = file_get_contents($leaf);
        $data = json_decode($original, true, 16, JSON_THROW_ON_ERROR);
        $data['records'][$neighborHash]['key'] = str_repeat('x', strlen($neighbor));
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $data['checksum'] = hash('sha256', json_encode($data['records'], $flags));
        $changed = json_encode($data, $flags);
        self::assertSame(strlen($original), strlen($changed));
        file_put_contents($leaf, $changed);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid metadata record.');
        $map->get('path');
    }

    /** @dataProvider noncanonicalJsonValues */
    public function testGeneratedJsonMustStillPassTheExistingChecksumRules(array $value): void
    {
        $map = MetadataMap::create(Fixture::temp() . '/metadata');
        $map->put('path', $value);
        $this->expectException(\RuntimeException::class);
        $map->get('path');
    }

    public function noncanonicalJsonValues(): array
    {
        return [[['value' => new \stdClass()]], [['value' => -0.0]], [['value' => (object) ['0' => 'a', '1' => 'b']]]];
    }

    public function testProcessLocalMapKeepsTheSameValuesAndPartitionsAsADurableMap(): void
    {
        $base = Fixture::temp();
        $local = MetadataMap::createProcessLocal($base . '/local', 1024);
        $durableDirectory = $base . '/durable'; $durable = MetadataMap::create($durableDirectory, 1024);
        $expected = [];
        for ($number = 0; $number < 300; $number++) {
            $expected['内容/' . $number] = ['id' => $number, 'sha256' => hash('sha256', (string) $number)];
            $local->put('内容/' . $number, $expected['内容/' . $number]); $durable->put('内容/' . $number, $expected['内容/' . $number]);
        }
        self::assertSame($expected['内容/1'], $local->get('内容/1'));
        self::assertSame($expected['内容/2'], $local->get('内容/2'));
        $local->put('内容/0', ['id' => 0, 'sha256' => hash('sha256', '0')]);
        $local->put('内容/1', ['id' => 'updated'], true); $durable->put('内容/1', ['id' => 'updated'], true); $expected['内容/1'] = ['id' => 'updated'];
        foreach ($expected as $key => $value) { self::assertSame($value, $local->get($key)); }
        $actual = iterator_to_array($local->entries()); ksort($actual); ksort($expected);
        self::assertSame($expected, $actual);
        self::assertNull($local->get('absent'));
        self::assertSame(self::names($durableDirectory), self::names(dirname($durableDirectory) . '/local'));
        $this->expectException(\RuntimeException::class);
        $local->put('内容/2', ['id' => 'conflict']);
    }

    public function testNewRecordsCanBeWrittenByPartitionAndLaterBatchesValidateTheExistingLeaves(): void
    {
        $directory = Fixture::temp() . '/local';
        $map = MetadataMap::createProcessLocal($directory, 1024);
        $keys = [];
        for ($number = 0; count($keys) < 12; ++$number) {
            $key = 'batch-' . $number;
            if (substr(hash('sha256', $key), 0, 3) === 'abc') { $keys[] = $key; }
        }
        $values = array_fill_keys($keys, '{"value":"unchanged"}');
        $map->putProcessLocalBatch($values);
        $map->putProcessLocalBatch($values);
        foreach ($keys as $key) { self::assertSame(['value' => 'unchanged'], $map->get($key)); }
        self::assertFileExists($directory . '/branch-abc');
        $leaf = $directory . '/leaf-' . substr(hash('sha256', $keys[0]), 0, 4) . '.json';
        file_put_contents($leaf, 'x', FILE_APPEND);
        $this->expectException(\JsonException::class);
        $map->putProcessLocalBatch([$keys[0] => $values[$keys[0]]]);
    }

    public function testCaptureBufferFlushesAtItsEncodedBytesLimit(): void
    {
        $map = MetadataMap::createProcessLocal(Fixture::temp() . '/local');
        $batch = new CaptureMetadataBatch($map, null);
        $value = ['metadata' => str_repeat('m', 250000)];
        for ($number = 0; $number < 18; ++$number) { $batch->put('capture-' . $number, $value); }
        self::assertSame($value, $map->get('capture-0'));
        self::assertNull($map->get('capture-17'));
        $batch->flush();
        self::assertSame($value, $map->get('capture-17'));
    }

    /** @dataProvider powerLossStates */
    public function testProcessLocalMapCannotBeOpenedInAnyStateACrashOrPowerLossCanLeave(string $state): void
    {
        $directory = Fixture::temp() . '/local';
        $map = MetadataMap::createProcessLocal($directory, 1024);
        $earlier = self::snapshot($directory);
        for ($number = 0; $number < 120; $number++) { $map->put('path/' . $number, ['id' => $number]); }
        $owner = file_get_contents($directory . '/owner');
        self::assertNotSame("wordpress-r2-backup metadata map v1\n", $owner);
        if ($state === 'owner missing') { unlink($directory . '/owner'); }
        elseif ($state === 'owner empty') { file_put_contents($directory . '/owner', ''); }
        elseif ($state === 'owner torn') { file_put_contents($directory . '/owner', substr($owner, 0, 20)); }
        elseif ($state === 'reverted to the newly created map') { self::revert($directory, $earlier); }
        elseif ($state === 'partitions torn or lost') {
            foreach (glob($directory . '/leaf-*.json') as $number => $leaf) { $number % 2 === 0 ? file_put_contents($leaf, substr((string) file_get_contents($leaf), 0, 9)) : unlink($leaf); }
            unlink($directory . '/partitions.json');
        } elseif ($state === 'pending update left behind') { file_put_contents($directory . '/pending-' . str_repeat('a', 32), 'torn'); chmod($directory . '/pending-' . str_repeat('a', 32), 0600); }
        try { MetadataMap::open($directory); self::fail('A process-local map was opened again.'); }
        catch (\RuntimeException $expected) { self::assertNotSame('', $expected->getMessage()); }
    }

    public function powerLossStates(): array
    {
        return ['complete' => ['complete'], 'owner missing' => ['owner missing'], 'owner empty' => ['owner empty'], 'owner torn' => ['owner torn'],
            'reverted to the newly created map' => ['reverted to the newly created map'], 'partitions torn or lost' => ['partitions torn or lost'],
            'pending update left behind' => ['pending update left behind']];
    }

    public function testTheProcessLocalOwnerIsAnotherKindThatADurableMapCannotAdopt(): void
    {
        $local = Fixture::temp() . '/local'; MetadataMap::createProcessLocal($local);
        $durable = Fixture::temp() . '/durable'; MetadataMap::create($durable)->put('path', ['id' => 'saved']);
        self::assertNotSame(file_get_contents($durable . '/owner'), file_get_contents($local . '/owner'));
        self::assertSame(['id' => 'saved'], MetadataMap::open($durable)->get('path'));
        file_put_contents($durable . '/owner', file_get_contents($local . '/owner'));
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('Invalid metadata map identity.');
        MetadataMap::open($durable);
    }

    /** @dataProvider changedProcessLocalPartitions */
    public function testProcessLocalMapRejectsChangedPartitionsWithinItsProcess(string $change): void
    {
        $directory = Fixture::temp() . '/local';
        $map = MetadataMap::createProcessLocal($directory);
        $map->put('path', ['id' => 'saved']); $map->put('other', ['id' => 'other']);
        self::assertSame(['id' => 'saved'], $map->get('path'));
        self::assertSame(['id' => 'other'], $map->get('other'));
        $leaf = $directory . '/leaf-' . substr(hash('sha256', 'path'), 0, 3) . '.json';
        $original = (string) file_get_contents($leaf);
        if ($change === 'valid JSON edited') {
            $data = json_decode($original, true); $data['records'][hash('sha256', 'path')]['value']['id'] = 'changed'; file_put_contents($leaf, json_encode($data));
        } elseif ($change === 'torn') { file_put_contents($leaf, substr($original, 0, intdiv(strlen($original), 2))); }
        elseif ($change === 'empty') { file_put_contents($leaf, ''); }
        elseif ($change === 'lost') { unlink($leaf); }
        elseif ($change === 'linked') { $outside = Fixture::temp() . '/outside.json'; file_put_contents($outside, $original); chmod($outside, 0600); unlink($leaf); symlink($outside, $leaf); }
        elseif ($change === 'readable by others') { chmod($leaf, 0644); }
        else { file_put_contents($directory . '/partitions.json', ''); }
        foreach ([static function () use ($map): void { $map->get('path'); }, static function () use ($map): void { $map->put('path', ['id' => 'replacement'], true); }] as $operation) {
            $rejected = false;
            // Invalid JSON surfaces as JsonException, as it already does for durable maps; both stop the caller.
            try { $operation(); } catch (\RuntimeException | \JsonException $expected) { $rejected = true; }
            self::assertTrue($rejected, $change);
        }
    }

    public function changedProcessLocalPartitions(): array
    {
        return ['valid JSON edited' => ['valid JSON edited'], 'torn' => ['torn'], 'empty' => ['empty'], 'lost' => ['lost'],
            'linked' => ['linked'], 'readable by others' => ['readable by others'], 'registry emptied' => ['registry emptied']];
    }

    private static function names(string $directory): array
    {
        $names = array_values(array_filter(scandir($directory), static fn (string $name): bool => !in_array($name, ['.', '..', 'owner'], true)));
        sort($names); return $names;
    }

    private static function snapshot(string $directory): array
    {
        $files = []; foreach (self::names($directory) as $name) { $files[$name] = file_get_contents($directory . '/' . $name); }
        $files['owner'] = file_get_contents($directory . '/owner'); return $files;
    }

    private static function revert(string $directory, array $files): void
    {
        foreach (scandir($directory) as $name) { if (!in_array($name, ['.', '..'], true)) { unlink($directory . '/' . $name); } }
        foreach ($files as $name => $bytes) { file_put_contents($directory . '/' . $name, $bytes); chmod($directory . '/' . $name, 0600); }
    }
}
