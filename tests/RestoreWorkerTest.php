<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\RestoreWorker;

final class RestoreWorkerTest extends TestCase
{
    public function testStatusKeepsTheFinalStatePublishedBeforeTheWorkerReleasedItsLock(): void
    {
        $base = Fixture::temp();
        $script = $base . '/status.php';
        file_put_contents($script, str_replace('__AUTOLOAD__', var_export(dirname(__DIR__) . '/vendor/autoload.php', true), <<<'PHP'
<?php
namespace WordPressR2Backup {
    function flock($lock, $operation) {
        $acquired = \flock($lock, $operation);
        if ($acquired && $operation === (LOCK_SH | LOCK_NB) && isset($GLOBALS['completedStatus'])) {
            // Model publication of the terminal state between the first read and the lock check.
            $status = $GLOBALS['completedStatus'];
            unset($GLOBALS['completedStatus']);
            file_put_contents($status, json_encode(['state' => 'complete', 'phase' => 'done']));
        }
        return $acquired;
    }
}
namespace {
    require __AUTOLOAD__;
    $service = new \WordPressR2Backup\RestoreService(['base' => __DIR__]);
    $job = __DIR__ . '/jobs/completing';
    mkdir($job, 0700);
    file_put_contents($job . '/worker.lock', '');
    $GLOBALS['completedStatus'] = $job . '/status.json';
    file_put_contents($GLOBALS['completedStatus'], json_encode(['state' => 'running', 'phase' => 'site-copy']));
    echo json_encode($service->status('completing'));
}
PHP
        ));
        try {
            $process = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertSame('complete', json_decode($output, true, 32, JSON_THROW_ON_ERROR)['state']);
            self::assertSame('complete', json_decode(file_get_contents($base . '/jobs/completing/status.json'), true, 32, JSON_THROW_ON_ERROR)['state']);
        } finally { Fixture::cleanup(); }
    }

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
