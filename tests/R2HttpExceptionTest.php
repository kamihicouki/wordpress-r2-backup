<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\R2HttpException;

final class R2HttpExceptionTest extends TestCase
{
    public function testLongRetryAfterIsNeverShortenedForTheSameRequest(): void
    {
        $seconds = 30 * 86400;
        $before = time();
        $deadline = (new R2HttpException(503, (string) $seconds))->retryNotBefore();
        self::assertNotNull($deadline);
        self::assertGreaterThanOrEqual($before + $seconds, $deadline);
        self::assertLessThanOrEqual(time() + $seconds, $deadline);

        $date = $before + $seconds;
        $header = gmdate('D, d M Y H:i:s \G\M\T', $date);
        self::assertSame($date, (new R2HttpException(429, $header))->retryNotBefore());
        self::assertSame($date, (new R2HttpException(500, $header, true))->retryNotBefore());
        self::assertNull((new R2HttpException(500, $header))->retryNotBefore());
        self::assertNull((new R2HttpException(503, (string) PHP_INT_MAX))->retryNotBefore());
    }
}
