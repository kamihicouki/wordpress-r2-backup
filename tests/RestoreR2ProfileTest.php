<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\DirectR2Repository;
use WordPressR2Backup\RestoreService;

final class RestoreR2ProfileTest extends TestCase
{
    public function testConnectionFileCanBeValidatedInMemory(): void
    {
        $body = json_encode(['format' => 'wrb-direct-r2-v1', 'version' => 1, 'accountId' => str_repeat('a', 32), 'bucket' => 'backup-test', 'accessKeyId' => str_repeat('B', 32), 'secretAccessKey' => str_repeat('c', 64), 'unitId' => str_repeat('d', 64)]);
        self::assertSame(str_repeat('d', 64), DirectR2Repository::fromContents($body)->unitId());
    }

    public function testProfileIsAcceptedAsABoundedSecret(): void
    {
        self::assertSame(8192, \WordPressR2Backup\RestorePlan::SECRET_FIELDS['r2Connection']);
    }

    public function testOversizedConnectionIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        DirectR2Repository::fromContents(str_repeat('x', 8193));
    }

    public function testConnectionCannotBeChangedAfterPreflight(): void
    {
        $reflection = new \ReflectionClass(RestoreService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('secretMac'); $method->setAccessible(true);
        $plan = ['database' => 'sqlite', 'adminLogin' => null];
        $before = $method->invoke($service, $plan, ['identity' => 'key', 'r2Connection' => 'first', 'adminPassword' => 'password'], 'binding-key');
        $after = $method->invoke($service, $plan, ['identity' => 'key', 'r2Connection' => 'second', 'adminPassword' => 'password'], 'binding-key');
        self::assertNotSame($before, $after);
    }

    public function testArbitraryEndpointIsRejected(): void
    {
        $body = json_encode(['format' => 'wrb-direct-r2-v1', 'version' => 1, 'accountId' => str_repeat('a', 32), 'bucket' => 'backup-test', 'accessKeyId' => str_repeat('B', 32), 'secretAccessKey' => str_repeat('c', 64), 'unitId' => str_repeat('d', 64), 'endpoint' => 'http://127.0.0.1/']);
        $this->expectException(\RuntimeException::class);
        DirectR2Repository::fromContents($body);
    }
}
