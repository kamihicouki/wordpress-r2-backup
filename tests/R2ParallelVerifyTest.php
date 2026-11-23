<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** verifyMany() through publishWithObjects() and completed(), with synthetic cURL functions in a separate process. */
final class R2ParallelVerifyTest extends TestCase
{
    /** @dataProvider scenarios */
    public function testParallelVerificationKeepsEveryCheckAndPublishesOnlyAfterAllReadsPass(string $scenario): void
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/r2-parallel-verify.php', $scenario], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $error);
        self::assertSame('ok ' . $scenario . "\n", $output);
    }

    public function scenarios(): array
    {
        $names = ['success', 'retry', 'tls', 'corrupt', 'length', 'overflow', 'status', 'throttled', 'server', 'retry_then_denied', 'missing', 'budget', 'exhausted', 'staggered', 'boundary15', 'boundary16'];
        return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
    }
}
