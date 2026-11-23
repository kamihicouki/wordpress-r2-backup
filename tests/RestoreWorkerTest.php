<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\RestoreWorker;

final class RestoreWorkerTest extends TestCase
{
    public function testDiagnosticUsesOnlyTheLocationAndNestedPdoCodes(): void
    {
        $pdo = new \PDOException('SQLite report containing a private path');
        $pdo->errorInfo = ['HY000', 13, 'synthetic-db-password'];
        $shell = new \RuntimeException('Shell output including a private command line', 0, $pdo);
        $failure = new RestoreWorkerDiagnosticWrapper('Restore report containing private details', 0, $shell);

        self::assertSame('RestoreWorkerDiagnosticWrapper RestoreWorkerTest.php:' . $failure->getLine()
            . ' (PDO HY000/13)', self::diagnostic($failure));
    }

    public function testDiagnosticFallsBackToTheLocationForMalformedPdoCodes(): void
    {
        foreach ([['HY000 extra', 13], ['HY000', '13']] as [$state, $code]) {
            $pdo = new \PDOException('Private database report');
            $pdo->errorInfo = [$state, $code, 'synthetic-db-password'];
            $failure = new RestoreWorkerDiagnosticWrapper('Private restore report', 0, $pdo);

            self::assertSame('RestoreWorkerDiagnosticWrapper RestoreWorkerTest.php:' . $failure->getLine(), self::diagnostic($failure));
        }
    }

    private static function diagnostic(\Throwable $failure): string
    {
        $method = new \ReflectionMethod(RestoreWorker::class, 'diagnostic');
        $method->setAccessible(true);
        return $method->invoke(null, $failure);
    }
}

final class RestoreWorkerDiagnosticWrapper extends \RuntimeException {}
