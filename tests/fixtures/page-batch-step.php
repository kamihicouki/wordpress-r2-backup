<?php
declare(strict_types=1);
// Synthetic capture and step only; no restore or network.
function update_option($name,$value,$autoload=false) { $GLOBALS['fixtureState']=$value; return true; }
function get_option($name,$default=null) { return $GLOBALS['fixtureState']??$default; }
require $argv[1].'/vendor/autoload.php';
require $argv[1].'/tests/Fixture.php';
require $argv[1].'/tests/WordPressDatabaseTest.php';
require $argv[1].'/tests/V4PageBatchUploadTest.php';
use WordPressR2Backup\{AgeProcess,BackupHistory,DirectR2Repository,LiveBackup,Manifest,PrivacyPolicy,SourceRoots,V4Capture,V4Format};
use WordPressR2Backup\Tests\{Fixture,V4DatabaseWpdbFake,V4PageBatchTransport};
$root=Fixture::temp(); $workspace=Fixture::temp(); $id=str_repeat('f',64); $directory=$workspace.'/job-'.$id; mkdir($directory,0700); Fixture::write($root,'application.txt','synthetic'); $keys=Fixture::keys(); $recipients=array_column($keys,'public');
$connection=['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('b',32),'secretAccessKey'=>str_repeat('c',64),'unitId'=>str_repeat('d',64)];
$settings=$connection+['recipients'=>$recipients,'connectionDigest'=>hash('sha256','synthetic'),'policy'=>['version'=>2,'preset'=>'full']]; $transport=new V4PageBatchTransport();
$repo=new DirectR2Repository($connection,$transport,null,static function(int $seconds): void {});
$scope=$repo->storageScope()+['keySetId'=>Manifest::keySetId($recipients),'encoding'=>V4Format::ENCODING]; $snapshot=str_repeat('e',64);
$capture=(new V4Capture(new SourceRoots($root),new V4DatabaseWpdbFake(),new PrivacyPolicy(['version'=>2,'preset'=>'full']),$directory))->capture(new AgeProcess(Fixture::runtime()['age']),$recipients,$snapshot,$scope);
$objects=$capture['objects']; $start=null;
foreach($objects as $i=>$object) { if (str_starts_with($object['object']['key'],'pages/')) { $start=$i; break; } }
if ($start===null || count($objects)-$start<2) throw new RuntimeException('Fixture needs consecutive pages');
$state=['id'=>$id,'snapshotId'=>$snapshot,'status'=>'uploading','completed'=>$start,'total'=>count($objects)+1]; $GLOBALS['fixtureState']=$state;
$job=['version'=>4,'manifest'=>$capture['manifest'],'objects'=>$objects,'state'=>$state,'position'=>$start];
$class=new ReflectionClass(LiveBackup::class); $backup=$class->newInstanceWithoutConstructor();
foreach(['repository'=>$repo,'settings'=>$settings,'workspace'=>$workspace,'history'=>new BackupHistory($workspace)] as $name=>$value) { $p=$class->getProperty($name);$p->setAccessible(true);$p->setValue($backup,$value); }
$digest=$class->getMethod('settingsDigest');$digest->setAccessible(true);$job['settingsDigest']=$digest->invoke($backup);
$save=$class->getMethod('saveJob');$save->setAccessible(true);$save->invoke($backup,$directory,$job);$before=file_get_contents($directory.'/job.json');
if (($argv[2]??'')==='benchmark') {
    $started=hrtime(true); $steps=0; $position=$start;
    while ($position<count($objects)) { $result=$backup->step();$position=$result['completed'];++$steps; }
    echo json_encode(['pages'=>count($objects)-$start,'steps'=>$steps,'seconds'=>(hrtime(true)-$started)/1e9],JSON_THROW_ON_ERROR),"\n";
    Fixture::cleanup(); exit;
}
if (($argv[2]??'')==='receipt-mismatch') {
    $failed=false; try { $backup->step(); } catch (Throwable $error) { $failed=true; }
    if (!$failed || file_get_contents($directory.'/job.json')!==$before || $GLOBALS['fixtureState']!==$state || $transport->deletes!==0) throw new RuntimeException('Receipt mismatch did not fail closed');
    echo json_encode(['receiptMismatchRejected'=>true],JSON_THROW_ON_ERROR),"\n"; Fixture::cleanup(); exit;
}
$transport->failPut=2; $failed=false;
try { $backup->step(); } catch (Throwable $error) { $failed=true; }
if (!$failed || file_get_contents($directory.'/job.json')!==$before || $GLOBALS['fixtureState']!==$state || count($transport->objects)!==1) throw new RuntimeException('Failed step changed position');
$transport->failPut=0; $transport->getCounts=[]; $transport->corruptOuter=true; $failed=false;
try { $backup->step(); } catch (Throwable $error) { $failed=true; }
if (!$failed || file_get_contents($directory.'/job.json')!==$before || $GLOBALS['fixtureState']!==$state || count($transport->objects)!==count($objects)-$start) throw new RuntimeException('Failed whole batch check changed position');
$transport->corruptOuter=false; $result=$backup->step();$saved=json_decode(file_get_contents($directory.'/job.json'),true,512,JSON_THROW_ON_ERROR);
$expected=$start+min($class->getConstant('NEW_PAGE_BATCH'),count($objects)-$start);
if ($saved['position']!==$expected || $result['completed']!==$expected || $GLOBALS['fixtureState']['completed']!==$expected || $transport->deletes!==0) throw new RuntimeException('Completed batch was not saved exactly');
echo json_encode(['failedPositionUnchanged'=>true,'resumedPosition'=>$expected,'pages'=>$expected-$start],JSON_THROW_ON_ERROR),"\n";
Fixture::cleanup();
