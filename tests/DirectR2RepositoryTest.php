<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\DirectR2Repository;

final class DirectR2RepositoryTest extends TestCase
{
    private array $connection;
    private array $objects = [];
    private array $requests = [];
    private string $directory;
    private bool $corrupt = false;

    protected function setUp(): void
    {
        $this->connection = ['accountId' => str_repeat('a', 32), 'bucket' => 'backup-test', 'accessKeyId' => str_repeat('B', 32), 'secretAccessKey' => str_repeat('c', 64), 'unitId' => str_repeat('d', 64)];
        $this->directory = realpath(sys_get_temp_dir()) . '/wrb-direct-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) { unlink($file); }
        rmdir($this->directory);
    }

    private function repository(): DirectR2Repository
    {
        return new DirectR2Repository($this->connection, function (array $request, callable $consume): array {
            $this->assertSignature($request);
            $this->requests[] = $request;
            $path = parse_url($request['url'], PHP_URL_PATH);
            $headers = []; $status = 200; $response = '';
            switch ($request['method']) {
                case 'PUT':
                    self::assertIsResource($request['body']);
                    self::assertSame('*', $request['headers']['if-none-match']);
                    $content = stream_get_contents($request['body']);
                    self::assertSame(strlen($content), $request['bodyBytes']);
                    self::assertSame(hash('sha256', $content), $request['headers']['x-amz-content-sha256']);
                    if (isset($this->objects[$path])) { $status = 412; }
                    else { $this->objects[$path] = $content; }
                    break;
                case 'HEAD':
                    if (!isset($this->objects[$path])) { $status = 404; }
                    else { $headers['content-length'] = (string) strlen($this->objects[$path]); }
                    break;
                case 'DELETE': unset($this->objects[$path]); $status = 204; break;
                case 'GET':
                    if (strpos($request['url'], '?') !== false) {
                        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
                        $response = '<ListBucketResult><Prefix>' . htmlspecialchars($query['prefix'], ENT_XML1) . '</Prefix><IsTruncated>false</IsTruncated>';
                        foreach (array_keys($this->objects) as $name) {
                            $key = substr($name, strlen('/backup-test/'));
                            if (strpos($key, $query['prefix']) === 0) { $response .= '<Contents><Key>' . htmlspecialchars($key, ENT_XML1) . '</Key></Contents>'; }
                        }
                        $response .= '</ListBucketResult>';
                    } elseif (!isset($this->objects[$path])) { $status = 404; }
                    else { $response = $this->corrupt ? str_repeat('x', strlen($this->objects[$path])) : $this->objects[$path]; }
                    break;
            }
            foreach (str_split($response, 7) as $chunk) { if ($consume($chunk) !== strlen($chunk)) { break; } }
            return ['status' => $status, 'headers' => $headers];
        }, static fn (): int => 1700000000);
    }

    /** Independently reconstruct canonical fields from the actual wire URL and Authorization. */
    private function assertSignature(array $request): void
    {
        self::assertSame('https', parse_url($request['url'], PHP_URL_SCHEME));
        self::assertSame(str_repeat('a', 32) . '.r2.cloudflarestorage.com', parse_url($request['url'], PHP_URL_HOST));
        self::assertStringStartsWith(strpos($request['url'], '?') !== false ? '/backup-test' : '/backup-test/wordpress-r2/' . str_repeat('d', 64) . '/', parse_url($request['url'], PHP_URL_PATH));
        self::assertMatchesRegularExpression('/Credential=([A-Za-z0-9]+)\/([^,]+), SignedHeaders=([^,]+), Signature=([a-f0-9]{64})$/', $request['headers']['authorization']);
        preg_match('/Credential=([A-Za-z0-9]+)\/([^,]+), SignedHeaders=([^,]+), Signature=([a-f0-9]{64})$/', $request['headers']['authorization'], $parts);
        self::assertSame($this->connection['accessKeyId'], $parts[1]);
        self::assertSame('20231114/auto/s3/aws4_request', $parts[2]);
        $canonicalHeaders = '';
        foreach (explode(';', $parts[3]) as $name) { $canonicalHeaders .= $name . ':' . trim($request['headers'][$name]) . "\n"; }
        $canonical = implode("\n", [$request['method'], parse_url($request['url'], PHP_URL_PATH), parse_url($request['url'], PHP_URL_QUERY) ?? '', $canonicalHeaders, $parts[3], $request['headers']['x-amz-content-sha256']]);
        $key = 'AWS4' . $this->connection['secretAccessKey'];
        foreach (explode('/', $parts[2]) as $part) { $key = hash_hmac('sha256', $part, $key, true); }
        self::assertSame(hash_hmac('sha256', "AWS4-HMAC-SHA256\n20231114T221320Z\n" . $parts[2] . "\n" . hash('sha256', $canonical), $key), $parts[4]);
    }

    public function testUploadVerifyPublishListDownloadAndIdempotentRetry(): void
    {
        $repo = $this->repository(); $id = str_repeat('1', 64); $set = str_repeat('2', 64);
        file_put_contents($this->directory . '/input', 'encrypted-test-body');
        self::assertFalse($repo->hasObjects());
        $file = $repo->putNew('files/' . $set . '/' . $id . '.age', $this->directory . '/input');
        self::assertSame($file, $repo->putNew($file['key'], $this->directory . '/input'));
        $manifest = $repo->putNew('manifests/' . $id . '.age', $this->directory . '/input');
        $repo->publish($id, $manifest); $repo->publish($id, $manifest);
        self::assertTrue($repo->hasObjects());
        self::assertSame([$file], $repo->listFileObjects($set));
        self::assertSame([['snapshotId' => $id, 'manifest' => $manifest]], $repo->listCompleted());
        self::assertTrue($repo->listStatus()[0]['valid']);
        $repo->download($file, $this->directory . '/download');
        self::assertSame('encrypted-test-body', file_get_contents($this->directory . '/download'));
        self::assertSame(0600, fileperms($this->directory . '/download') & 0777);
    }

    public function testConflictWithDifferentContentFailsWithoutOverwrite(): void
    {
        $repo = $this->repository(); $key = 'databases/' . str_repeat('3', 64) . '.age';
        file_put_contents($this->directory . '/input', 'first'); $repo->putNew($key, $this->directory . '/input');
        file_put_contents($this->directory . '/input', 'other');
        $this->expectException(\RuntimeException::class);
        $repo->putNew($key, $this->directory . '/input');
    }

    public function testProbeRemovesOnlyItsOwnTemporaryObject(): void
    {
        $repo = $this->repository();
        self::assertTrue($repo->testConnection()['ok']);
        self::assertSame([], $this->objects);
        self::assertSame(['PUT', 'GET', 'DELETE'], array_column($this->requests, 'method'));
    }

    public function testCorruptionDoesNotPublishDownload(): void
    {
        $repo = $this->repository(); file_put_contents($this->directory . '/input', 'cipher');
        $receipt = $repo->putNew('databases/' . str_repeat('4', 64) . '.age', $this->directory . '/input');
        $this->corrupt = true;
        try { $repo->download($receipt, $this->directory . '/output'); self::fail('Expected corruption failure.'); }
        catch (\RuntimeException $error) { self::assertFileDoesNotExist($this->directory . '/output'); }
    }

    public function testOversizeFailsBeforeTransport(): void
    {
        $file = fopen($this->directory . '/sparse', 'wb'); ftruncate($file, 5368709121); fclose($file);
        try { $this->repository()->putNew('databases/' . str_repeat('5', 64) . '.age', $this->directory . '/sparse'); self::fail('Expected upload limit.'); }
        catch (\RuntimeException $error) { self::assertSame([], $this->requests); }
    }

    public function testEndpointOverrideIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        new DirectR2Repository($this->connection + ['endpoint' => 'http://localhost']);
    }

    public function testResponseOverflowAndSecretErrorsAreSanitized(): void
    {
        $repo = new DirectR2Repository($this->connection, static function (array $request, callable $consume): array {
            $consume(str_repeat('x', 1048577)); return ['status' => 200, 'headers' => []];
        });
        try { $repo->hasObjects(); self::fail('Expected bounded failure.'); }
        catch (\RuntimeException $error) { self::assertStringNotContainsString($this->connection['secretAccessKey'], $error->getMessage()); }
        $repo = new DirectR2Repository($this->connection, function (): void { throw new \RuntimeException($this->connection['secretAccessKey']); });
        try { $repo->hasObjects(); self::fail('Expected transport failure.'); }
        catch (\RuntimeException $error) { self::assertSame('R2 transport failed.', $error->getMessage()); self::assertNull($error->getPrevious()); }
    }

    /** @dataProvider badListingBodies */
    public function testRejectsUnsafeOrUnboundedListings(string $body): void
    {
        $repo = new DirectR2Repository($this->connection, static function (array $request, callable $consume) use ($body): array {
            $consume($body); return ['status' => 200, 'headers' => []];
        });
        $this->expectException(\RuntimeException::class); $repo->hasObjects();
    }

    public function badListingBodies(): array
    {
        return [
            ['<!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><ListBucketResult/>'],
            ['<ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>other-unit/file</Key></Contents></ListBucketResult>'],
            ['<ListBucketResult><IsTruncated>true</IsTruncated><NextContinuationToken>repeat</NextContinuationToken></ListBucketResult>'],
        ];
    }

    public function testProbeCleansUpAfterReadVerificationFailure(): void
    {
        $repo = $this->repository(); $this->corrupt = true;
        try { $repo->testConnection(); self::fail('Expected probe verification failure.'); }
        catch (\RuntimeException $error) {
            self::assertSame([], $this->objects);
            self::assertSame('DELETE', $this->requests[count($this->requests) - 1]['method']);
        }
    }

    public function testFailedHttpStatusCannotLookLikeSuccessfulUpload(): void
    {
        file_put_contents($this->directory . '/input', 'cipher');
        $requests = 0;
        $repo = new DirectR2Repository($this->connection, static function () use (&$requests): array {
            $requests++; return ['status' => 403, 'headers' => []];
        });
        try { $repo->putNew('databases/' . str_repeat('6', 64) . '.age', $this->directory . '/input'); self::fail('Expected HTTP failure.'); }
        catch (\RuntimeException $error) { self::assertSame(1, $requests); }
    }

    public function testListingFollowsEncodedContinuationAndRejectsDuplicateKeys(): void
    {
        $physical = 'wordpress-r2/' . $this->connection['unitId'] . '/files/' . str_repeat('7', 64) . '/' . str_repeat('8', 64) . '.age';
        $calls = 0;
        $repo = new DirectR2Repository($this->connection, function (array $request, callable $consume) use ($physical, &$calls): array {
            $this->assertSignature($request); $calls++;
            if ($calls === 2) { self::assertStringContainsString('continuation-token=a%2Bb%2F%3D', $request['url']); }
            $consume('<ListBucketResult><IsTruncated>' . ($calls === 1 ? 'true' : 'false') . '</IsTruncated>'
                . ($calls === 1 ? '<NextContinuationToken>a+b/=</NextContinuationToken>' : '')
                . '<Contents><Key>' . $physical . '</Key></Contents></ListBucketResult>');
            return ['status' => 200, 'headers' => []];
        }, static fn (): int => 1700000000);
        try { $repo->hasObjects(); self::fail('Expected duplicate listing rejection.'); }
        catch (\RuntimeException $error) { self::assertSame(2, $calls); }
    }

    public function testFailedVerificationCannotPublishCompletionRecord(): void
    {
        $repo = $this->repository(); $id = str_repeat('9', 64);
        file_put_contents($this->directory . '/input', 'cipher');
        $receipt = $repo->putNew('manifests/' . $id . '.age', $this->directory . '/input');
        $this->corrupt = true;
        try { $repo->publish($id, $receipt); self::fail('Expected manifest verification failure.'); }
        catch (\RuntimeException $error) {
            self::assertCount(1, $this->objects);
            self::assertStringNotContainsString('/commits/', implode(',', array_keys($this->objects)));
        }
    }

    public function testPrivateProfileAndModeValidation(): void
    {
        $profile = $this->directory . '/profile';
        file_put_contents($profile, json_encode(['format' => 'wrb-direct-r2-v1', 'version' => 1] + $this->connection)); chmod($profile, 0600);
        self::assertSame($this->connection['unitId'], DirectR2Repository::fromFile($profile)->unitId());
        chmod($profile, 0644); clearstatcache(true, $profile);
        $this->expectException(\RuntimeException::class); DirectR2Repository::fromFile($profile);
    }

    public function testCandidateListDoesNotClaimOrPerformPayloadVerification(): void
    {
        $repo = $this->repository(); $id = str_repeat('1', 64);
        file_put_contents($this->directory . '/input', 'encrypted-test-body');
        $manifest = $repo->putNew('manifests/' . $id . '.age', $this->directory . '/input');
        $repo->publish($id, $manifest);
        unset($this->objects['/backup-test/wordpress-r2/' . $this->connection['unitId'] . '/' . $manifest['key']]);
        $items = $repo->listCandidates();
        self::assertSame($id, $items[0]['snapshotId']);
        self::assertFalse($items[0]['verified']);
        $this->expectException(\RuntimeException::class);
        $repo->completed($id);
    }

    private ?string $failOperation = null;

    private function retentionRepository(int $now = 1700000000): DirectR2Repository
    {
        return new DirectR2Repository($this->connection, function (array $request, callable $consume): array {
            $path = parse_url($request['url'], PHP_URL_PATH);
            $operation = $request['method'] . ' ' . basename($path);
            if ($this->failOperation === $operation) { return ['status' => 503, 'headers' => []]; }
            $status = 200; $headers = []; $response = '';
            if ($request['method'] === 'PUT') {
                if (isset($this->objects[$path])) { $status = 412; }
                else { $this->objects[$path] = stream_get_contents($request['body']); }
            } elseif ($request['method'] === 'DELETE') {
                unset($this->objects[$path]); $status = 204;
            } elseif (strpos($request['url'], '?') !== false) {
                parse_str(parse_url($request['url'], PHP_URL_QUERY), $query);
                $response = '<ListBucketResult><IsTruncated>false</IsTruncated>';
                foreach (array_keys($this->objects) as $key) {
                    $key = substr($key, strlen('/backup-test/'));
                    if (strpos($key, $query['prefix']) === 0) { $response .= '<Contents><Key>' . htmlspecialchars($key, ENT_XML1) . '</Key></Contents>'; }
                }
                $response .= '</ListBucketResult>';
            } elseif (!isset($this->objects[$path])) { $status = 404; }
            elseif ($request['method'] === 'HEAD') { $headers['content-length'] = (string) strlen($this->objects[$path]); }
            else { $response = $this->objects[$path]; }
            if ($response !== '') { $consume($response); }
            return ['status' => $status, 'headers' => $headers];
        }, static fn (): int => $now);
    }

    private function extendedSnapshot(DirectR2Repository $repo, string $digit, ?array $shared = null): array
    {
        $id = str_repeat($digit, 64);
        file_put_contents($this->directory . '/input', 'cipher-' . $digit);
        $receipt = $shared ?? $repo->putNew('databases/' . $id . '.age', $this->directory . '/input');
        $manifest = $repo->putNew('manifests/' . $id . '.age', $this->directory . '/input');
        $repo->publishWithObjects($id, $manifest, [$receipt]);
        return [$id, $receipt, $manifest];
    }

    public function testExtendedPublicationAndSharedDeletion(): void
    {
        $repo = $this->retentionRepository();
        [$id, $shared] = $this->extendedSnapshot($repo, '1');
        [$other] = $this->extendedSnapshot($repo, '2', $shared);
        $record = $repo->completed($id);
        self::assertSame('2023-11-14T22:13:20Z', $record['createdAt']);
        self::assertSame([$shared], $record['objects']);
        $result = $repo->deleteSnapshot($id);
        self::assertSame([$shared['key']], $result['retainedShared']);
        self::assertSame($other, $repo->completed($other)['snapshotId']);
        $repo->deleteSnapshot($other);
        self::assertArrayNotHasKey('/backup-test/wordpress-r2/' . $this->connection['unitId'] . '/' . $shared['key'], $this->objects);
    }

    public function testDeleteFailureResumesFromLedgerAfterCommitDisappears(): void
    {
        $repo = $this->retentionRepository();
        [$id, $receipt] = $this->extendedSnapshot($repo, '3');
        $this->failOperation = 'DELETE ' . basename($receipt['key']);
        try { $repo->deleteSnapshot($id); self::fail('Delete failure must be reported.'); }
        catch (\RuntimeException $expected) {}
        $prefix = '/backup-test/wordpress-r2/' . $this->connection['unitId'] . '/';
        self::assertArrayHasKey($prefix . 'deletions/' . $id . '.json', $this->objects);
        self::assertArrayNotHasKey($prefix . 'commits/' . $id . '.json', $this->objects);
        $this->failOperation = null;
        $repo->deleteSnapshot($id);
        $repo->deleteSnapshot($id);
        self::assertArrayNotHasKey($prefix . $receipt['key'], $this->objects);
    }

    public function testRetentionUsesCommitTimeAndKeepsLegacyRecords(): void
    {
        $repo = $this->retentionRepository();
        [$id] = $this->extendedSnapshot($repo, '4');
        $legacy = str_repeat('5', 64);
        file_put_contents($this->directory . '/input', 'legacy');
        $repo->publish($legacy, $repo->putNew('manifests/' . $legacy . '.age', $this->directory . '/input'));
        $later = $this->retentionRepository(1700000000 + 31 * 86400);
        $result = $later->retention(30);
        self::assertSame([$id], array_column($result, 'snapshotId'));
        self::assertSame($legacy, $later->completed($legacy)['snapshotId']);
    }

    public function testMalformedReferenceCannotAuthorizeDeletion(): void
    {
        $repo = $this->retentionRepository();
        [$id] = $this->extendedSnapshot($repo, '6');
        $prefix = '/backup-test/wordpress-r2/' . $this->connection['unitId'] . '/';
        $record = json_decode($this->objects[$prefix . 'commits/' . $id . '.json'], true);
        $record['objects'][0]['key'] = '../another-unit/private';
        $this->objects[$prefix . 'commits/' . $id . '.json'] = json_encode($record);
        $before = $this->objects;
        try { $repo->deleteSnapshot($id); self::fail('Invalid references must fail.'); }
        catch (\Throwable $expected) { self::assertSame($before, $this->objects); }
    }

    public function testPublicationDoesNotCommitUnverifiedObjects(): void
    {
        $repo = $this->retentionRepository(); $id = str_repeat('7', 64);
        file_put_contents($this->directory . '/input', 'cipher');
        $manifest = $repo->putNew('manifests/' . $id . '.age', $this->directory . '/input');
        try { $repo->publishWithObjects($id, $manifest, [['key' => 'databases/' . $id . '.age', 'bytes' => 6, 'sha256' => hash('sha256', 'cipher')]]); self::fail('Missing object must fail.'); }
        catch (\Throwable $expected) {}
        self::assertCount(1, $this->objects);
    }

    public function testRetentionResumesDeletionWithoutCommit(): void
    {
        $repo = $this->retentionRepository();
        [$id, $receipt] = $this->extendedSnapshot($repo, '8');
        $this->failOperation = 'DELETE ' . basename($receipt['key']);
        try { $repo->deleteSnapshot($id); self::fail('Expected DELETE failure.'); }
        catch (\RuntimeException $expected) {}
        $this->failOperation = null;
        self::assertSame([$id], array_column($repo->retention(30), 'snapshotId'));
        self::assertCount(1, $this->objects); // Only durable deletion intent remains.
    }

    public function testGetFailureNeverStartsDeletion(): void
    {
        $repo = $this->retentionRepository();
        [$id] = $this->extendedSnapshot($repo, '9');
        $before = $this->objects;
        $this->failOperation = 'GET ' . $id . '.json';
        try { $repo->deleteSnapshot($id); self::fail('GET failure must stop deletion.'); }
        catch (\RuntimeException $expected) { self::assertSame($before, $this->objects); }
    }

    public function testLegacyCommitConservativelyPreservesUnknownReferences(): void
    {
        $repo = $this->retentionRepository();
        [$id, $receipt] = $this->extendedSnapshot($repo, 'a');
        $legacy = str_repeat('b', 64);
        file_put_contents($this->directory . '/input', 'legacy');
        $repo->publish($legacy, $repo->putNew('manifests/' . $legacy . '.age', $this->directory . '/input'));
        self::assertSame([$receipt['key']], $repo->deleteSnapshot($id)['retainedShared']);
        $repo->verify($receipt);
    }

    public function testCompletedStrictlyRejectsInvalidExtendedMetadata(): void
    {
        $repo = $this->retentionRepository();
        [$id] = $this->extendedSnapshot($repo, 'c');
        $key = '/backup-test/wordpress-r2/' . $this->connection['unitId'] . '/commits/' . $id . '.json';
        $record = json_decode($this->objects[$key], true);
        foreach ([['createdAt' => '2023-02-30T00:00:00Z'], ['objects' => [$record['manifest']]], ['objects' => [$record['objects'][0], $record['objects'][0]]], ['plaintextPath' => '/private/value']] as $change) {
            $this->objects[$key] = json_encode(array_replace($record, $change));
            $failed = false;
            try { $repo->completed($id); } catch (\Throwable $expected) { $failed = true; }
            self::assertTrue($failed, 'Malformed completion must be rejected.');
        }
    }

    public function testRetentionRejectsOutOfRangeDays(): void
    {
        $repo = $this->retentionRepository();
        foreach ([0, 3651] as $days) {
            $failed = false;
            try { $repo->retention($days); } catch (\RuntimeException $expected) { $failed = true; }
            self::assertTrue($failed);
        }
    }

    public function testLargerMetadataSupportsManyReceipts(): void
    {
        $repo = $this->retentionRepository(); $id = str_repeat('e', 64);
        file_put_contents($this->directory . '/input', 'cipher');
        $manifest = $repo->putNew('manifests/' . $id . '.age', $this->directory . '/input');
        $objects = [];
        for ($n = 0; $n < 30; $n++) {
            $objects[] = $repo->putNew('files/' . str_repeat('f', 64) . '/' . hash('sha256', (string) $n) . '.age', $this->directory . '/input');
        }
        $repo->publishWithObjects($id, $manifest, $objects);
        self::assertSame($objects, $repo->completed($id)['objects']);
    }

    public function testExtendedPublicationRetryPreservesOriginalTimestamp(): void
    {
        $repo = $this->retentionRepository();
        [$id, $receipt, $manifest] = $this->extendedSnapshot($repo, 'd');
        $later = $this->retentionRepository(1700000000 + 86400);
        $later->publishWithObjects($id, $manifest, [$receipt]);
        self::assertSame('2023-11-14T22:13:20Z', $later->completed($id)['createdAt']);
    }

    public function testTombstoneBlocksRepublishingDeletedSnapshot(): void
    {
        $repo = $this->retentionRepository();
        [$id, $receipt, $manifest] = $this->extendedSnapshot($repo, 'f');
        $repo->deleteSnapshot($id);
        // Uploaded data alone must not resurrect a deliberate deletion.
        file_put_contents($this->directory . '/input', 'cipher-f');
        $repo->putNew($receipt['key'], $this->directory . '/input');
        $repo->putNew($manifest['key'], $this->directory . '/input');
        $this->expectException(\RuntimeException::class);
        $repo->publishWithObjects($id, $manifest, [$receipt]);
    }

    public function testLedgerPutFailurePreservesAllSnapshotData(): void
    {
        $repo = $this->retentionRepository();
        [$id] = $this->extendedSnapshot($repo, '1');
        $before = $this->objects;
        $this->failOperation = 'PUT ' . $id . '.json';
        try { $repo->deleteSnapshot($id); self::fail('Failed durable intent must stop deletion.'); }
        catch (\RuntimeException $expected) { self::assertSame($before, $this->objects); }
    }

    public function testUnreadableOtherCommitStopsBeforeDeletingSnapshot(): void
    {
        $repo = $this->retentionRepository();
        [$id] = $this->extendedSnapshot($repo, '2');
        [$other] = $this->extendedSnapshot($repo, '3');
        $this->failOperation = 'GET ' . $other . '.json';
        try { $repo->deleteSnapshot($id); self::fail('Unknown references must stop deletion.'); }
        catch (\RuntimeException $expected) {}
        $this->failOperation = null;
        self::assertSame($id, $repo->completed($id)['snapshotId']);
        self::assertSame($other, $repo->completed($other)['snapshotId']);
    }

    public function testRetentionExpiresAtExactDayBoundary(): void
    {
        $repo = $this->retentionRepository();
        [$id] = $this->extendedSnapshot($repo, '4');
        self::assertSame([], $this->retentionRepository(1700000000 + 30 * 86400 - 1)->retention(30));
        self::assertSame([$id], array_column($this->retentionRepository(1700000000 + 30 * 86400)->retention(30), 'snapshotId'));
    }
}
