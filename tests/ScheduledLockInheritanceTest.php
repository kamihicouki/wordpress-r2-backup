<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

final class ScheduledLockInheritanceTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testDetachedChildDoesNotInheritTheScheduleAndRunnerLocks(): void
    {
        $workspace = $this->workspace();
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        $child = var_export($workspace . '/launch-child', true);
        $script = <<<'PHP'
<?php
require AUTOLOAD;
WordPressR2Backup\BackupScheduler::withScheduleLock(__DIR__, static function (): void {
    $open = new ReflectionMethod(WordPressR2Backup\LiveRunner::class, 'runnerLock');
    $open->setAccessible(true);
    $lock = $open->invoke(null, __DIR__, false);
    $detach = new ReflectionMethod(WordPressR2Backup\LiveRunner::class, 'detach');
    $detach->setAccessible(true);
    $detach->invoke(null, CHILD, __DIR__ . '/ticket', getenv(), __DIR__ . '/child.log');
    $deadline = microtime(true) + 10;
    while (!is_file(__DIR__ . '/stop') && microtime(true) < $deadline) { usleep(10000); }
    fclose($lock);
});
PHP;
        Fixture::write($workspace, 'owner.php', str_replace(['AUTOLOAD', 'CHILD'], [$autoload, $child], $script));
        Fixture::write($workspace, 'launch-child', "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workspace . '/survivor.php') . "\n");
        chmod($workspace . '/launch-child', 0700);
        $this->assertLocksReleasedAfterKill([PHP_BINARY, $workspace . '/owner.php'], $workspace, ['schedule.lock', 'runner.lock']);
    }

    public function testWorkerDoesNotPassTheRunnerLockToItsSubprocess(): void
    {
        $workspace = $this->workspace();
        $id = str_repeat('a', 64);
        $token = str_repeat('b', 64);
        $ticket = $workspace . '/launch-' . $id . '.json';
        Fixture::write($workspace, basename($ticket), json_encode([
            'version' => 2, 'launchId' => $id, 'workspace' => $workspace, 'root' => $workspace,
            'tokenHash' => hash('sha256', $token), 'action' => 'start', 'mode' => 'online',
            'settingsDigest' => str_repeat('c', 64), 'sharingDigest' => str_repeat('d', 64),
        ], JSON_THROW_ON_ERROR));
        chmod($ticket, 0600);
        Fixture::write($workspace, 'wp-load.php', '<?php require __DIR__ . "/start-survivor.php";');
        $environment = getenv();
        $environment['WRB_LAUNCH_TOKEN'] = $token;
        $this->assertLocksReleasedAfterKill([PHP_BINARY, dirname(__DIR__) . '/worker.php', $ticket], $workspace, ['runner.lock'], $environment);
    }

    public function testCaptureSubprocessDoesNotInheritTheSharedContentIndexLock(): void
    {
        $workspace = $this->workspace();
        $script = <<<'PHP'
<?php
require AUTOLOAD;
use WordPressR2Backup\V4ContentIndex;
use WordPressR2Backup\V4Format;
$directory = __DIR__ . '/index';
mkdir($directory, 0700);
$repository = new class {
    public function verify(array $receipt): void {}
    public function listPackObjects(string $scope): array { return []; }
};
$scope = ['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-bucket', 'unitId' => str_repeat('1', 64),
    'keySetId' => str_repeat('2', 64), 'encoding' => V4Format::ENCODING];
$index = V4ContentIndex::openOrCreate($directory, $scope, $repository);
$index->withLock(static function (): void { require __DIR__ . '/start-survivor.php'; });
PHP;
        Fixture::write($workspace, 'index-owner.php', str_replace('AUTOLOAD', var_export(dirname(__DIR__) . '/vendor/autoload.php', true), $script));
        $this->assertLocksReleasedAfterKill([PHP_BINARY, $workspace . '/index-owner.php'], $workspace, ['index/unit.lock']);
    }

    private function workspace(): string
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('proc_open') || !function_exists('posix_geteuid')) {
            self::markTestSkipped('POSIX CLI subprocesses are required.');
        }
        $workspace = Fixture::temp();
        Fixture::write($workspace, 'start-survivor.php', <<<'PHP'
<?php
$child = proc_open([PHP_BINARY, __DIR__ . '/survivor.php'], [0 => ['file', '/dev/null', 'r'],
    1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
if (is_resource($child)) { proc_close($child); }
PHP
        );
        Fixture::write($workspace, 'survivor.php', <<<'PHP'
<?php
file_put_contents(__DIR__ . '/survivor-ready', (string) getmypid());
$deadline = microtime(true) + 10;
while (!is_file(__DIR__ . '/stop') && microtime(true) < $deadline) { usleep(10000); }
touch(__DIR__ . '/survivor-done');
PHP
        );
        return $workspace;
    }

    private function assertLocksReleasedAfterKill(array $command, string $workspace, array $locks, ?array $environment = null): void
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'],
            2 => ['file', $workspace . '/owner.log', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);
        try {
            $deadline = microtime(true) + 5;
            while (!is_file($workspace . '/survivor-ready') && microtime(true) < $deadline) { usleep(10000); }
            self::assertFileExists($workspace . '/survivor-ready', (string) file_get_contents($workspace . '/owner.log'));
            foreach ($locks as $name) { self::assertFalse($this->available($workspace . '/' . $name), $name . ' must start locked'); }
            self::assertTrue(proc_terminate($process, 9));
            proc_close($process);
            $inherited = [];
            foreach ($locks as $name) {
                if (!$this->available($workspace . '/' . $name)) { $inherited[] = $name; }
            }
            self::assertSame([], $inherited, 'Locks inherited after SIGKILL');
        } finally {
            touch($workspace . '/stop');
            if (is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
            $deadline = microtime(true) + 1;
            while (is_file($workspace . '/survivor-ready') && !is_file($workspace . '/survivor-done') && microtime(true) < $deadline) { usleep(10000); }
        }
    }

    private function available(string $path): bool
    {
        $handle = fopen($path, 'r+b');
        self::assertIsResource($handle);
        try { return flock($handle, LOCK_EX | LOCK_NB); }
        finally { fclose($handle); }
    }
}
