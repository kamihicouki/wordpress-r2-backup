<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

final class LiveBackupCacheTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testStateReadsOtherProcessesCancellationInsteadOfLocalOptionCache(): void
    {
        $root = Fixture::temp();
        $script = <<<'PHP'
<?php
namespace {
function wp_cache_delete($key,$group) { unset($GLOBALS['cache'][$key]); return true; }
}
namespace WordPressR2Backup {
function get_option($key,$default=null) {
  if(array_key_exists($key,$GLOBALS['cache']??[])) return $GLOBALS['cache'][$key];
  return $GLOBALS['cache'][$key]=$GLOBALS['store'][$key]??$default;
}
function update_option($key,$value,$autoload=false) { $GLOBALS['cache'][$key]=$GLOBALS['store'][$key]=$value; return true; }
function home_url($path='/') { return 'https://fixture.invalid/'.hash('sha256',ABSPATH).$path; }
function wp_salt($scheme='auth') { return 'fixture-auth-salt'; }
require AUTOLOAD;
define('ABSPATH', SITE . '/');
$settings=['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'unitId'=>str_repeat('d',64),'connectionVerified'=>true,'proofs'=>[['confirmed'=>true],['confirmed'=>true]],'policy'=>[],'recipients'=>[]];
$GLOBALS['store']['wrb_live_workspace_token'] = str_repeat('e', 64);
$GLOBALS['wpdb'] = new class {
  public $options = 'wp_options';
  public $prefix = 'wp_';
  public $last_error = '';
  public function suppress_errors($value = true) { return false; }
  public function prepare($sql, ...$parameters) { return $sql; }
  public function get_var($sql) { return $GLOBALS['store']['wrb_live_workspace_token']; }
};
LiveSettings::save($settings);
$backup=new LiveBackup($settings); $workspace=$backup->workspace(); $launchId=str_repeat('a',64);
try {
  $queued = $backup->queue($launchId);
  $ticket = ['version' => 2, 'action' => 'start', 'mode' => $queued['consistencyMode'],
    'settingsDigest' => $queued['settingsDigest'], 'sharingDigest' => $queued['sharingDigest']];
  $first = $backup->state();
  // A second process writes the database without invalidating this worker's in-memory cache.
  $GLOBALS['store']['wrb_live_operation']['status'] = 'cancelled';
  $fresh = $backup->state();
  // The worker may still have its own queued copy when it starts.
  $GLOBALS['cache']['wrb_live_operation'] = $first;
  $rejection = null;
  try { $backup->runQueued($launchId, $ticket); }
  catch (\RuntimeException $error) { $rejection = $error->getMessage(); }
  echo json_encode(['initial' => $first['status'], 'fresh' => $fresh['status'],
    'rejection' => $rejection, 'final' => $GLOBALS['store']['wrb_live_operation']['status'],
    'jobs' => count(glob($workspace . '/job-*')), 'maintenance' => file_exists(ABSPATH . '.maintenance')]);
} finally {
  foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspace,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $entry) { $entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname()); }
  rmdir($workspace);
}
}
PHP;
        $script = str_replace(['AUTOLOAD', 'SITE'], [var_export(dirname(__DIR__) . '/vendor/autoload.php', true), var_export($root, true)], $script);
        Fixture::write($root, 'cache-test.php', $script);
        $process = proc_open([PHP_BINARY, $root . '/cache-test.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('queued', $result['initial']);
        self::assertSame('cancelled', $result['fresh']);
        self::assertSame('起動待ちの操作を検証できません。', $result['rejection']);
        self::assertSame('cancelled', $result['final']);
        self::assertSame(0, $result['jobs']);
        self::assertFalse($result['maintenance']);
    }
}
