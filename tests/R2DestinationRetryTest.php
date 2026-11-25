<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{DirectR2Repository, R2HttpException, ScheduledStorageLimit};

final class R2DestinationRetryTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    /** @dataProvider requests */
    public function testAStorageDeadlineSurvivesRequestAndRepositoryLifetimes(string $method, int $status): void
    {
        $workspace = Fixture::temp();
        $now = time(); $deadline = $now + 86400; $calls = 0;
        $throttle = static fn (): array => ['status' => $status, 'headers' => ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', $deadline)]];
        $successful = static function () use (&$calls): array { ++$calls; return ['status' => 200, 'headers' => []]; };
        try { $this->request($this->repository($workspace, $throttle, $now), $method); self::fail('HTTP error was expected'); }
        catch (R2HttpException $error) { self::assertSame($deadline, $error->retryNotBefore()); }

        // A new operation and rotated credentials targeting the same bucket still wait.
        $same = $this->repository($workspace, $successful, $now, ['accessKeyId' => str_repeat('R', 32), 'unitId' => str_repeat('e', 64)]);
        try { $this->request($same, 'GET'); self::fail('New request ignored the deadline'); }
        catch (ScheduledStorageLimit $wait) { self::assertSame('storage_wait', $wait->outcome()); }
        self::assertSame(0, $calls);

        $this->request($this->repository($workspace, $successful, $now, ['bucket' => 'other-backups']), 'GET');
        self::assertSame(1, $calls);
        $now = $deadline;
        $this->request($same, 'GET');
        self::assertSame(2, $calls);
    }

    public function requests(): array { return [['GET', 429], ['HEAD', 503], ['PUT', 429], ['DELETE', 503]]; }

    public function testParallelHeaderObserversKeepTheLatestDeadlineEvenBeforeTheBodyCompletes(): void
    {
        $workspace = Fixture::temp(); $now = time();
        $clock = static fn (): int => $now;
        $connection = ['accountId' => str_repeat('a', 32), 'bucket' => 'backup-test', 'accessKeyId' => str_repeat('b', 32),
            'secretAccessKey' => str_repeat('c', 64), 'unitId' => str_repeat('d', 64)];
        $repository = new DirectR2Repository($connection, null, $clock, null, null, $workspace);
        $options = new \ReflectionMethod($repository, 'curlOptions'); $options->setAccessible(true);
        $request = ['method' => 'GET', 'headers' => [], 'url' => 'https://fixture.invalid', 'body' => null];
        foreach ([7200, 60] as $delay) {
            $response = (object) ['headers' => [], 'bytes' => 0, 'status' => 0, 'retryObserved' => false];
            $read = $options->invoke($repository, $request, 'strlen', $response)[CURLOPT_HEADERFUNCTION];
            $read(null, "HTTP/2 503\r\n");
            $read(null, 'Retry-After: ' . gmdate('D, d M Y H:i:s \G\M\T', $now + $delay) . "\r\n");
            $read(null, "\r\n");
            self::assertTrue($response->retryObserved);
        }
        try { $repository->assertDestinationAvailable($now + 60); self::fail('Shorter sibling response replaced the deadline'); }
        catch (ScheduledStorageLimit $wait) { self::assertStringContainsString(gmdate('Y-m-d H:i:s', $now + 7200), $wait->getMessage()); }
        $repository->assertDestinationAvailable($now + 7200);
    }

    public function testCorruptRetryStateIsNotTreatedAsAnExpiredDeadline(): void
    {
        $workspace = Fixture::temp(); $now = time(); $calls = 0;
        $path = $workspace . '/r2-retry-' . hash('sha256', str_repeat('a', 32) . '/backup-test');
        file_put_contents($path, '12'); chmod($path, 0600);
        $success = static function () use (&$calls): array { ++$calls; return ['status' => 200, 'headers' => []]; };
        try { $this->request($this->repository($workspace, $success, $now), 'GET'); self::fail('Torn deadline must fail closed'); }
        catch (ScheduledStorageLimit $wait) { self::assertSame('storage_wait', $wait->outcome()); }
        self::assertSame(0, $calls);
    }

    private function repository(string $workspace, callable $transport, int &$now, array $overrides = []): DirectR2Repository
    {
        $clock = static function () use (&$now): int { return $now; };
        return new DirectR2Repository($overrides + ['accountId' => str_repeat('a', 32), 'bucket' => 'backup-test',
            'accessKeyId' => str_repeat('b', 32), 'secretAccessKey' => str_repeat('c', 64), 'unitId' => str_repeat('d', 64)],
            $transport, $clock, null, null, $workspace);
    }

    private function request(DirectR2Repository $repository, string $method): void
    {
        $request = new \ReflectionMethod($repository, 'request'); $request->setAccessible(true);
        $request->invoke($repository, $method, null, [], null, 0, hash('sha256', ''), [], 'strlen', 1024, [200]);
    }
}
