<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

/** Isolated WordPress: an inert CLI shim prevents any worker from capturing or contacting R2. */
final class BackupSchedulerLaunchIntegrationTest extends TestCase
{
    private ?IsolatedMysql $db=null;
    private ?IsolatedWordPress $wp=null;
    private string $scratch='';
    private string $shim='';
    private bool $failStarted=false;
    protected function tearDown(): void
    {
        if ($this->wp!==null) { $this->wp->stop(); }
        if ($this->db!==null) { $this->db->stop(); }
        Fixture::cleanup();
    }
    private function process(array $command): array
    {
        $env=getenv(); $env['WRB_PHP_CLI']=$this->shim; $env['WRB_TEST_FAIL_STARTED']=$this->failStarted ? '1' : '0';
        $p=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        self::assertIsResource($p); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        return ['exit'=>proc_close($p),'out'=>$out,'err'=>$err];
    }
    private function evalWp(string $code): array
    {
        $path=$this->scratch.'/wp-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($path,'<?php require '.var_export($this->wp->root().'/wp-load.php',true).';'.$code);
        $r=$this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$path]);
        self::assertSame(0,$r['exit'],$r['err']); return json_decode($r['out'],true,32,JSON_THROW_ON_ERROR);
    }
    private function prepare(): array
    {
        if(getenv('WRB_RUN_V4_MYSQL_INTEGRATION')!=='1') { self::markTestSkipped('Isolated WordPress integration is not enabled.'); }
        $this->db=IsolatedMysql::start(null,'DISABLED'); $this->wp=IsolatedWordPress::start($this->db); $this->scratch=Fixture::temp();
        $this->shim=$this->scratch.'/inert-php';
        file_put_contents($this->shim,"#!/bin/sh\nif [ \"\$1\" = -r ]; then exec ".escapeshellarg(PHP_BINARY)." \"\$@\"; fi\nexit 0\n"); chmod($this->shim,0700);
        $plugin=$this->wp->root().'/wp-content/plugins/wordpress-r2-backup';
        self::assertTrue(copy(dirname(__DIR__).'/schedule.php',$plugin.'/schedule.php'));
        $keys=Fixture::keys(); $settings=['accountId'=>str_repeat('a',32),'bucket'=>'backup-test','accessKeyId'=>str_repeat('B',32),'secretAccessKey'=>str_repeat('c',64),'recipients'=>array_column($keys,'public'),'retentionDays'=>30];
        $config=['enabled'=>true,'frequency'=>'daily','time'=>gmdate('H:i'),'weekday'=>null,'mode'=>'online','revision'=>str_repeat('a',64),'effectiveSince'=>gmdate('Y-m-d\TH:i:s\Z',time()-86400)];
        $seed=$this->evalWp('$s=WordPressR2Backup\\LiveSettings::validate('.var_export($settings,true).'); $s["connectionVerified"]=true; $s["proofs"]=[["confirmed"=>true],["confirmed"=>true]]; WordPressR2Backup\\LiveSettings::save($s);'
            .'update_option("timezone_string","UTC"); update_option("wrb_live_schedule",'.var_export($config,true).',false);'
            .'echo json_encode(["digest"=>hash("sha256",json_encode($s,JSON_THROW_ON_ERROR))]);');
        return [$plugin, $config, $seed];
    }

    public function testOnlineClaimsAndQueuesOnceWithFrozenSettingsAndRejectsBusy(): void
    {
        [$plugin, $config, $seed] = $this->prepare();
        $run=$this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]);
        self::assertSame(0,$run['exit'],$run['err']);
        $status=$this->evalWp('echo json_encode(["schedule"=>get_option("wrb_live_schedule_runner"),"operation"=>get_option("wrb_live_operation")]);');
        self::assertSame('started',$status['schedule']['lastResult']); self::assertSame('queued',$status['operation']['status']);
        self::assertSame('online',$status['operation']['consistencyMode']); self::assertSame($seed['digest'],$status['operation']['settingsDigest']);
        self::assertNotEmpty($status['operation']['sharingDigest']); $original=$status['operation'];
        $history=$this->evalWp('echo json_encode((new WordPressR2Backup\\BackupHistory(WordPressR2Backup\\LiveSettings::workspace()))->recent());');
        self::assertCount(1,$history);
        self::assertSame($original['id'],$history[0]['id']);
        self::assertSame('scheduled',$history[0]['source']);
        self::assertSame($status['schedule']['lastAttempt'],$history[0]['settings']['schedule']['slot']);
        $run=$this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]); self::assertSame(0,$run['exit'],$run['err']);
        $again=$this->evalWp('echo json_encode(["schedule"=>get_option("wrb_live_schedule_runner"),"operation"=>get_option("wrb_live_operation")]);');
        self::assertSame($original,$again['operation']); self::assertSame('not_due',$again['schedule']['status']); self::assertSame('started',$again['schedule']['lastResult']);
        $this->evalWp('delete_option("wrb_live_schedule_runner"); echo "{}";');
        $run=$this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]); self::assertSame(0,$run['exit'],$run['err']);
        $busy=$this->evalWp('echo json_encode(["schedule"=>get_option("wrb_live_schedule_runner"),"operation"=>get_option("wrb_live_operation")]);');
        self::assertSame('skipped',$busy['schedule']['lastResult']); self::assertSame($original,$busy['operation']);
        $run=$this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]); self::assertSame(0,$run['exit'],$run['err']);
        $busyAgain=$this->evalWp('echo json_encode(["schedule"=>get_option("wrb_live_schedule_runner"),"operation"=>get_option("wrb_live_operation")]);');
        self::assertSame('not_due',$busyAgain['schedule']['status']); self::assertSame('skipped',$busyAgain['schedule']['lastResult']); self::assertSame($original,$busyAgain['operation']);
        $deduplicated=$this->evalWp('echo json_encode((new WordPressR2Backup\\BackupHistory(WordPressR2Backup\\LiveSettings::workspace()))->recent());');
        self::assertSame($history,$deduplicated);
        $workspace=$this->evalWp('delete_option("wrb_live_schedule_runner"); update_option("wrb_live_operation",["status"=>"complete"],false); echo json_encode(["path"=>WordPressR2Backup\\LiveSettings::workspace()]);')['path'];
        $held=fopen($workspace.'/runner.lock','c+b'); self::assertIsResource($held); self::assertTrue(flock($held,LOCK_EX|LOCK_NB));
        try {
            $run=$this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]); self::assertSame(0,$run['exit'],$run['err']);
            $locked=$this->evalWp('echo json_encode(["schedule"=>get_option("wrb_live_schedule_runner"),"operation"=>get_option("wrb_live_operation")]);');
            self::assertSame('failed',$locked['schedule']['lastResult']); self::assertSame(['status'=>'complete'],$locked['operation']);
        } finally { flock($held,LOCK_UN); fclose($held); }
        $maintenance=$config; $maintenance['mode']='maintenance'; $maintenance['revision']=str_repeat('b',64);
        $this->evalWp('delete_option("wrb_live_schedule_runner"); update_option("wrb_live_schedule",'.var_export($maintenance,true).',false); echo "{}";');
        $run=$this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]); self::assertSame(0,$run['exit'],$run['err']);
        $blocked=$this->evalWp('echo json_encode(["schedule"=>get_option("wrb_live_schedule_runner"),"operation"=>get_option("wrb_live_operation")]);');
        self::assertSame('unavailable',$blocked['schedule']['lastResult']); self::assertSame(['status'=>'complete'],$blocked['operation']);
        // Simulate losing the scheduler after the worker was queued, before started was persisted.
        $mu=$this->wp->root().'/wp-content/mu-plugins'; self::assertTrue(mkdir($mu,0700));
        file_put_contents($mu.'/schedule-failure.php', '<?php if(getenv("WRB_TEST_FAIL_STARTED")==="1") add_filter("pre_update_option_wrb_live_schedule_runner",static function($value){ if(($value["lastResult"]??"")==="started") throw new RuntimeException("synthetic"); return $value; });');
        $this->evalWp('delete_option("wrb_live_schedule_runner"); delete_option("wrb_live_operation"); update_option("wrb_live_schedule",'.var_export($config,true).',false); echo "{}";');
        $this->failStarted=true;
        $run=$this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]); self::assertSame(1,$run['exit']);
        $this->failStarted=false;
        $claimed=$this->evalWp('echo json_encode(["schedule"=>get_option("wrb_live_schedule_runner"),"operation"=>get_option("wrb_live_operation")]);');
        self::assertSame('claimed',$claimed['schedule']['lastResult']); self::assertSame('queued',$claimed['operation']['status']);
        $run=$this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]); self::assertSame(0,$run['exit'],$run['err']);
        $recovered=$this->evalWp('echo json_encode(["schedule"=>get_option("wrb_live_schedule_runner"),"operation"=>get_option("wrb_live_operation")]);');
        self::assertSame('interrupted',$recovered['schedule']['lastResult']); self::assertSame($claimed['operation'],$recovered['operation']);
        $this->evalWp('update_option("wrb_live_operation",["status"=>"complete"],false); echo "{}";');
        $this->evalWp('delete_option("wrb_live_schedule_runner"); update_option("wrb_live_schedule",'.var_export($config,true).',false); update_option("active_plugins",[],false); echo "{}";');
        $run=$this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]); self::assertSame(0,$run['exit'],$run['err']);
        $inactive=$this->evalWp('echo json_encode(["schedule"=>get_option("wrb_live_schedule_runner"),"operation"=>get_option("wrb_live_operation")]);');
        self::assertSame('unavailable',$inactive['schedule']['lastResult']); self::assertSame(['status'=>'complete'],$inactive['operation']);
    }

    public function testScheduledConnectionFailuresResumeTheSavedJobAcrossCliProcesses(): void
    {
        [$plugin, $config] = $this->prepare();
        $tick = function () use ($plugin): void {
            $result = $this->process([PHP_BINARY, '-d', 'sys_temp_dir='.$this->scratch, $plugin.'/schedule.php', $this->wp->root()]);
            self::assertSame(0, $result['exit'], $result['err']);
        };
        $read = fn (): array => $this->evalWp('echo json_encode(get_option("wrb_live_operation"));');
        $this->evalWp('$s=WordPressR2Backup\LiveSettings::load(); $s["policy"]["preset"]="custom";'
            .'$s["policy"]["paths"]=["wp-admin"=>"exclude","wp-includes"=>"exclude","wp-content"=>"exclude"];'
            .'WordPressR2Backup\LiveSettings::save($s); echo "{}";');
        $tick();
        $initial = $read();
        $objects = $this->scratch.'/r2';
        self::assertTrue(mkdir($objects, 0700));
        $worker = 'require '.var_export(__DIR__.'/MemoryR2Transport.php', true).';'
            .'$fault=0; $s=WordPressR2Backup\LiveSettings::load(); $memory=new WordPressR2Backup\Tests\MemoryR2Transport('.var_export($objects,true).');'
            .'$transport=static function(array $request, callable $consume) use ($memory,&$fault){'
            .'if($fault===503 && $request["method"]==="PUT") return ["status"=>503,"headers"=>["Retry-After"=>"600"]];'
            .'if($fault && $request["method"]==="PUT") throw new WordPressR2Backup\R2TransportException($fault);'
            .'return $memory($request,$consume);};'
            .'$b=new WordPressR2Backup\LiveBackup($s,new WordPressR2Backup\DirectR2Repository(array_intersect_key($s,array_flip(["accountId","bucket","accessKeyId","secretAccessKey","unitId"])),$transport));'
            .'$a=$b->state();';
        $started = $this->evalWp($worker.'$b->begin($a["launchId"]); echo json_encode($b->step());');
        self::assertGreaterThan(0, $started['completed']);
        $fault = '$fault=7; try { $b->step(); } catch (WordPressR2Backup\R2TransportException $error) {} echo json_encode($b->state());';
        $paused = $this->evalWp($worker.$fault);
        self::assertSame($started['completed'], $paused['completed']);
        $tls = $this->evalWp($worker.str_replace('$fault=7;', '$fault=60;', $fault));
        self::assertArrayNotHasKey('autoResumeAt', $tls, 'A TLS error must clear a pending connection retry');
        $tick();
        self::assertSame($tls, $read());
        $failureStarted = time();
        $paused = $this->evalWp($worker.$fault);
        $failureEnded = time();
        $dueNow = function (): void {
            // Move only the due time in the isolated database, then start a fresh real scheduler process.
            $this->evalWp('$a=get_option("wrb_live_operation"); $a["autoResumeAt"]=time()-1; update_option("wrb_live_operation",$a,false); echo "{}";');
        };
        // A binding mismatch stops recovery before ticket creation. Further ticks leave diagnosis visible.
        $dueNow();
        $this->evalWp('$a=get_option("wrb_live_operation"); $a["settingsDigest"]=str_repeat("0",64); update_option("wrb_live_operation",$a,false); echo "{}";');
        $invalid = $this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]);
        self::assertSame(1,$invalid['exit']);
        $blocked=$read();
        self::assertArrayNotHasKey('autoResumeAt',$blocked);
        self::assertSame(0,$blocked['autoResumeAttempts']);
        $tick();
        self::assertSame($blocked,$read());
        $this->evalWp('update_option("wrb_live_operation",'.var_export($paused,true).',false); echo "{}";');
        $resumeWorker = '$ticket=json_decode(file_get_contents($b->workspace()."/launch-".$a["launchId"].".json"),true);'
            .'try {$b->runQueued($a["launchId"],$ticket);} catch (WordPressR2Backup\R2TransportException $error) {} echo json_encode($b->state());';
        foreach ([60, 300, 900] as $attempt => $delay) {
            // Compare the deadline to the failed worker, before running independent scheduler checks.
            self::assertGreaterThanOrEqual($failureStarted + $delay, $paused['autoResumeAt']);
            self::assertLessThanOrEqual($failureEnded + $delay, $paused['autoResumeAt']);
            $tick();
            self::assertSame($paused, $read(), 'Early ticks must preserve the interruption');
            $dueNow();
            if ($attempt === 0) {
                $path=$this->evalWp('echo json_encode(["path"=>WordPressR2Backup\LiveSettings::workspace()]);')['path'];
                $lock=fopen($path.'/runner.lock','c+b'); self::assertTrue(flock($lock,LOCK_EX|LOCK_NB));
                try { $tick(); self::assertSame('uploading', $read()['status']); }
                finally { fclose($lock); }
            }
            $tick();
            $queued = $read();
            self::assertSame('queued', $queued['status']);
            self::assertSame($initial['id'], $queued['id']);
            self::assertSame($initial['snapshotId'], $queued['snapshotId']);
            self::assertSame($started['completed'], $queued['completed']);
            self::assertSame($attempt+1, $queued['autoResumeAttempts']);
            $tick();
            self::assertSame($queued, $read(), 'A second tick must not launch another worker');
            $failureStarted = time();
            $paused=$this->evalWp($worker.'$fault=7;'.$resumeWorker);
            $failureEnded = time();
        }
        self::assertArrayNotHasKey('autoResumeAt', $paused);
        $tick();
        self::assertSame($paused, $read());
        $history=$this->evalWp('echo json_encode((new WordPressR2Backup\BackupHistory(WordPressR2Backup\LiveSettings::workspace()))->latest());');
        $codes=array_column($history['events'],'code');
        self::assertSame(3, count(array_keys($codes,'auto_resume_queued',true)));
        self::assertSame('auto_resume_exhausted', end($codes));

        $request = var_export($config + ['operationId'=>$initial['id']],true);
        // A manual resume wins after eligibility was observed and before the scheduler acquires the collection locks.
        $due = $paused;
        $due['autoResumeAttempts']=1; $due['autoResumeAt']=time()-1;
        $this->evalWp('update_option("wrb_live_operation",'.var_export($due,true).',false);'.$worker.'$b->queue(str_repeat("e",64),true); echo "{}";');
        self::assertNull($this->evalWp($worker.'echo json_encode(["result"=>$b->queue(str_repeat("f",64),true,"maintenance",'.$request.')]);')['result']);
        $complete=$this->evalWp($worker.'$fault=0; $b->runQueued($a["launchId"]); echo json_encode($b->state());');
        self::assertSame('complete', $complete['status']);
        self::assertSame(1, $complete['autoResumeAttempts'], 'Verified progress must preserve the durable budget');
        $tick();
        self::assertSame($complete, $read());

        // A cancellation between the advisory due check and the queue lock must win.
        $this->evalWp('update_option("wrb_live_operation",'.var_export($due,true).',false);'.$worker.'$b->cancel(); echo "{}";');
        self::assertNull($this->evalWp($worker.'echo json_encode(["result"=>$b->queue(str_repeat("f",64),true,"maintenance",'.$request.')]);')['result']);
        $tick();
        self::assertSame('cancelled',$read()['status']);

        // Start another scheduled capture, interrupt after its first checkpoint, then let one automatic retry finish.
        $config['time']=gmdate('H:i'); $config['revision']=str_repeat('c',64);
        $this->evalWp('delete_option("wrb_live_schedule_runner"); update_option("wrb_live_schedule",'.var_export($config,true).',false); echo "{}";');
        $tick();
        $second=$read();
        self::assertSame('queued',$second['status']);
        self::assertNotSame($initial['id'],$second['id']);
        $this->evalWp($worker.'$b->begin($a["launchId"]); echo json_encode($b->step());');
        $temporary=$this->evalWp($worker.str_replace(['$fault=7;', 'R2TransportException $error'], ['$fault=503;', 'R2HttpException $error'], $fault));
        self::assertEqualsWithDelta(time()+600, $temporary['autoResumeAt'], 5);
        $tick();
        self::assertSame($temporary,$read(), 'Retry-After must survive a new scheduler process');
        $dueNow();
        $tick();
        self::assertSame('uploading', $read()['status'], 'An eligible operation must still respect the destination deadline');
        self::assertSame(0, $read()['autoResumeAttempts']);
        $this->evalWp('$s=WordPressR2Backup\LiveSettings::load();'
            .'$marker=WordPressR2Backup\LiveSettings::workspace()."/r2-retry-".hash("sha256",$s["accountId"]."/".$s["bucket"]);'
            .'if(!is_file($marker)) throw new RuntimeException("Expected retry marker"); file_put_contents($marker,(time()-1)."\n"); echo "{}";');
        $tick();
        $recovered=$this->evalWp($worker.'$fault=0;'.$resumeWorker);
        self::assertSame('complete',$recovered['status']);
        self::assertSame($second['snapshotId'],$recovered['snapshotId']);
        self::assertSame(1,$recovered['autoResumeAttempts']);
        $history=$this->evalWp('echo json_encode((new WordPressR2Backup\BackupHistory(WordPressR2Backup\LiveSettings::workspace()))->find('.var_export($second['id'],true).'));');
        $codes=array_column($history['events'],'code');
        $waiting = $history['events'][array_search('auto_resume_waiting',$codes,true)];
        self::assertSame(503,$waiting['httpStatus']);
        self::assertStringContainsString('HTTP 503',$waiting['message']);
        self::assertContains('final_verification',$codes);
        self::assertSame('complete',end($codes));
    }

    public function testDestinationThrottleBlocksTheNextSlotWithoutAnOperationRetryBudget(): void
    {
        [$plugin, $config] = $this->prepare();
        $throttled = $this->evalWp('$settings=WordPressR2Backup\LiveSettings::load();'
            .'$connection=array_intersect_key($settings,array_flip(["accountId","bucket","accessKeyId","secretAccessKey","unitId"]));'
            .'$repository=new WordPressR2Backup\DirectR2Repository($connection,static fn()=>["status"=>429,"headers"=>["Retry-After"=>"3600"]]);'
            .'$backup=new WordPressR2Backup\LiveBackup($settings,$repository);'
            .'try {iterator_to_array($repository->listPackObjects(str_repeat("e",64)));} catch(WordPressR2Backup\R2HttpException $error) {$deadline=$error->retryNotBefore();}'
            .'update_option("wrb_live_operation",["status"=>"failed"],false); echo json_encode(["deadline"=>$deadline??null]);');
        self::assertNotNull($throttled['deadline']);
        $run = $this->process([PHP_BINARY, '-d', 'sys_temp_dir='.$this->scratch, $plugin.'/schedule.php', $this->wp->root()]);
        self::assertSame(0, $run['exit'], $run['err']);
        $result = $this->evalWp('$workspace=WordPressR2Backup\LiveSettings::workspace();'
            .'echo json_encode(["operation"=>get_option("wrb_live_operation"),"runner"=>get_option("wrb_live_schedule_runner"),'
            .'"history"=>(new WordPressR2Backup\BackupHistory($workspace))->latest(),"tickets"=>glob($workspace."/launch-*.json")]);');
        self::assertSame(['status' => 'failed'], $result['operation']);
        self::assertSame('storage_wait', $result['runner']['lastResult']);
        self::assertStringContainsString(gmdate('Y-m-d H:i:s', $throttled['deadline']), $result['runner']['message']);
        self::assertSame('schedule_storage_wait', $result['history']['events'][0]['code']);
        self::assertSame([], $result['tickets']);
    }
    public function testAnIndependentStorageWaitResumesTheSameScheduledTransfer(): void
    {
        [$plugin] = $this->prepare();
        $this->evalWp('$s=WordPressR2Backup\LiveSettings::load(); $s["policy"]["preset"]="custom";'
            .'$s["policy"]["paths"]=["wp-admin"=>"exclude","wp-includes"=>"exclude","wp-content"=>"exclude"];'
            .'WordPressR2Backup\LiveSettings::save($s); echo "{}";');
        $store = $this->scratch.'/rate-limited';
        self::assertTrue(mkdir($store, 0700));
        $tick = function () use ($plugin): void {
            $result = $this->process([PHP_BINARY, '-d', 'sys_temp_dir='.$this->scratch, $plugin.'/schedule.php', $this->wp->root()]);
            self::assertSame(0, $result['exit'], $result['err']);
        };
        $worker = function (string $action) use ($store): array {
            return $this->evalWp('$directory='.var_export($store, true).'; $action='.var_export($action, true).'; $fault="healthy";'
                .'require '.var_export(__DIR__.'/fixtures/scheduled-cipher-put.php', true).';');
        };
        $tick();
        $first = $worker('capture')['state'];
        self::assertSame('uploading', $first['status']);
        self::assertGreaterThan(0, $first['completed']);
        $window = $this->evalWp('$settings=WordPressR2Backup\LiveSettings::load(); $workspace=WordPressR2Backup\LiveSettings::workspace();'
            .'$retry=new WordPressR2Backup\R2DestinationRetry($workspace,$settings["accountId"],$settings["bucket"]);'
            .'$deadline=time()+3600; $retry->record($deadline);'
            .'echo json_encode(["deadline"=>$deadline,"path"=>$workspace."/r2-retry-".hash("sha256",$settings["accountId"]."/".$settings["bucket"])]);');
        $wait = $worker('step');
        self::assertSame($first['id'], $wait['state']['id']);
        self::assertSame($first['completed'], $wait['state']['completed']);
        self::assertSame($window['deadline'], $wait['state']['autoResumeAt']);
        self::assertSame(0, $wait['state']['autoResumeAttempts']);
        self::assertSame('auto_resume_waiting', end($wait['events'])['code']);
        $tick();
        $waitingState = $this->evalWp('echo json_encode(get_option("wrb_live_operation"));');
        self::assertSame($wait['state'], $waitingState);
        file_put_contents($window['path'], (time()-1)."\n");
        $this->evalWp('$s=get_option("wrb_live_operation"); $s["autoResumeAt"]=time()-1;'
            .'update_option("wrb_live_operation",$s,false); echo "{}";');
        $tick();
        $done = $worker('resume');
        self::assertNull($done['error']);
        self::assertSame($first['id'], $done['state']['id']);
        self::assertSame($first['snapshotId'], $done['state']['snapshotId']);
        self::assertSame('complete', $done['state']['status']);
        self::assertContains('final_verification', array_column($done['events'], 'code'));
    }

    public function testHttp500ConditionalPutResumesOnlyWithVerifiedCiphertextAndWithinBudget(): void
    {
        [$plugin,$config] = $this->prepare();
        $this->evalWp('$s=WordPressR2Backup\LiveSettings::load(); $s["policy"]["preset"]="custom";'
            .'$s["policy"]["paths"]=["wp-admin"=>"exclude","wp-includes"=>"exclude","wp-content"=>"exclude"];'
            .'WordPressR2Backup\LiveSettings::save($s); echo "{}";');
        $tick = function () use ($plugin): void {
            $result = $this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]);
            self::assertSame(0,$result['exit'],$result['err']);
        };
        foreach (['stored','corrupt','persistent'] as $fault) {
            $config['revision']=bin2hex(random_bytes(32)); $config['time']=gmdate('H:i');
            $this->evalWp('delete_option("wrb_live_schedule_runner"); update_option("wrb_live_schedule",'.var_export($config,true).',false); echo "{}";');
            $directory=$this->scratch.'/'.$fault; self::assertTrue(mkdir($directory,0700));
            $worker=function (string $action,string $fault) use ($directory): array {
                return $this->evalWp('$directory='.var_export($directory,true).'; $action='.var_export($action,true).'; $fault='.var_export($fault,true).';'
                    .'require '.var_export(__DIR__.'/fixtures/scheduled-cipher-put.php',true).';');
            };
            $tick();
            $start=$worker('capture','healthy');
            self::assertNull($start['error']); self::assertGreaterThan(0,$start['state']['completed']);
            $paused=$worker('step',$fault);
            self::assertSame('R2 returned HTTP 500.',$paused['error']);
            $budget=$fault==='persistent' ? [60,300,900] : [600];
            foreach ($budget as $attempt=>$delay) {
                $state=$paused['state'];
                self::assertSame($start['state']['completed'],$state['completed']);
                self::assertEqualsWithDelta(time()+$delay,$state['autoResumeAt'],5);
                $tick();
                self::assertSame($state,$this->evalWp('echo json_encode(get_option("wrb_live_operation"));'));
                $this->evalWp('$a=get_option("wrb_live_operation"); $a["autoResumeAt"]=time()-1; update_option("wrb_live_operation",$a,false); echo "{}";');
                $tick();
                $paused=$worker('resume',$fault==='persistent' ? 'persistent' : 'healthy');
                self::assertSame($start['state']['snapshotId'],$paused['state']['snapshotId']);
                self::assertSame($attempt+1,$paused['state']['autoResumeAttempts']);
            }
            $codes=array_column($paused['events'],'code');
            self::assertArrayNotHasKey('autoResumeAt',$paused['state']);
            if ($fault !== 'persistent') {
                self::assertContains(['PUT',412],$paused['trace']);
                self::assertContains(['HEAD',200],$paused['trace']);
                self::assertContains(['GET',200],$paused['trace']);
            }
            if ($fault === 'stored') {
                self::assertNull($paused['error']); self::assertSame('complete',$paused['state']['status']);
                self::assertContains('final_verification',$codes); self::assertSame('complete',end($codes));
            } else {
                self::assertSame($start['state']['completed'],$paused['state']['completed']);
                self::assertSame($fault === 'persistent' ? 'auto_resume_exhausted' : 'transfer_failed',end($codes));
                self::assertSame($fault === 'persistent' ? 'R2 returned HTTP 500.' : 'Ciphertext verification failed.',$paused['error']);
                $tick();
                self::assertSame($paused['state'],$this->evalWp('echo json_encode(get_option("wrb_live_operation"));'));
                $this->evalWp('(new WordPressR2Backup\LiveBackup(WordPressR2Backup\LiveSettings::load()))->cancel(); echo "{}";');
            }
        }
    }

    /** @dataProvider interruptedSlots */
    public function testSchedulerRecoversADeadWorkerOrReleasesTheNextSlot(string $fault): void
    {
        [$plugin, $config] = $this->prepare();
        $this->evalWp('$s=WordPressR2Backup\LiveSettings::load(); $s["policy"]["preset"]="custom";'
            .'$s["policy"]["paths"]=["wp-admin"=>"exclude","wp-includes"=>"exclude","wp-content"=>"exclude"];'
            .'WordPressR2Backup\LiveSettings::save($s); echo "{}";');
        $tick = function () use ($plugin): void {
            $r = $this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]);
            self::assertSame(0, $r['exit'], $r['err']);
        };
        $read = fn (): array => $this->evalWp('echo json_encode(get_option("wrb_live_operation"));');
        $workspace = $this->evalWp('echo json_encode(["path"=>WordPressR2Backup\LiveSettings::workspace()]);')['path'];
        $store = $this->scratch.'/store';
        self::assertTrue(mkdir($store,0700));
        $worker = function (string $action, string $failure) use ($store): array {
            $fixture = '$directory='.var_export($store,true).'; $action='.var_export($action,true).'; $fault='.var_export($failure,true).';'
                .'require '.var_export(__DIR__.'/fixtures/scheduled-cipher-put.php',true).';';
            if ($failure !== 'terminated') { return $this->evalWp($fixture); }
            $script = $this->scratch.'/terminate.php';
            file_put_contents($script,'<?php require '.var_export($this->wp->root().'/wp-load.php',true).';'.$fixture);
            $r=$this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$script]);
            self::assertSame(77,$r['exit'],$r['err']);
            return [];
        };
        $tick();
        $first=$worker('capture','healthy')['state'];
        $id=$first['id'];
        $job=$workspace.'/job-'.$id;
        self::assertGreaterThan(0,$first['completed']);
        if ($fault === 'exhausted') {
            $this->evalWp('$s=get_option("wrb_live_operation"); $s["autoResumeAttempts"]=3; update_option("wrb_live_operation",$s,false); echo "{}";');
        }
        $failure = ['revision'=>'terminated', 'settings'=>'terminated', 'manual'=>'terminated', 'legacy403'=>'forbidden',
            'missingHistory'=>'forbidden', 'legacyMissingHistory'=>'forbidden', 'corruptHistory'=>'forbidden',
            'longRetry'=>'persistent', 'recreatedHistory'=>'forbidden', 'nullCheckpoint'=>'terminated', 'damaged'=>'terminated', 'legacy'=>'persistent', 'exhausted'=>'persistent'][$fault] ?? $fault;
        if ($fault === 'recreatedHistory') { unlink($workspace.'/backup-history.json'); }
        $worker('step', $failure);
        if ($fault === 'revision') {
            $this->evalWp('$c=get_option("wrb_live_schedule"); $c["revision"]=str_repeat("d",64); update_option("wrb_live_schedule",$c,false); echo "{}";');
        } elseif ($fault === 'settings') {
            $this->evalWp('$s=WordPressR2Backup\LiveSettings::load(); $s["retentionDays"]=31; WordPressR2Backup\LiveSettings::save($s); echo "{}";');
        }
        // Advance the previous slot and event times in the isolated fixture; the real CLI clock is untouched.
        $journal=$workspace.'/backup-history.json';
        $data=json_decode(file_get_contents($journal),true,16,JSON_THROW_ON_ERROR);
        $run=&$data['runs'][0];
        $previous=gmdate('Y-m-d\TH:i:s\Z',time()-3600);
        $run['updatedAt'] = $previous;
        if ($fault === 'recreatedHistory') {
            self::assertSame('unknown', $run['source']);
            self::assertArrayNotHasKey('schedule', $run);
        } else {
            $run['scheduledAt'] = $run['schedule']['slot'] = $previous;
        }
        $last=count($run['events'])-1;
        $run['events'][$last]['at']=$previous;
        if (in_array($fault, ['legacy', 'legacy403', 'legacyMissingHistory'], true)) {
            $run['events'][$last]=['at'=>$previous,'code'=>'transfer_failed','httpStatus'=>$fault==='legacy'?500:403];
            $this->evalWp('$s=get_option("wrb_live_operation"); unset($s["autoResumeAt"],$s["scheduledSlot"],$s["transferStopped"],$s["transferStoppedAt"],$s["localRetentionDays"]);'
                .'update_option("wrb_live_operation",$s,false); echo "{}";');
        }
        if (in_array($fault, ['legacyMissingHistory', 'missingHistory', 'corruptHistory', 'recreatedHistory'], true)) {
            self::assertTrue(touch($job.'/job.json', time()-3600));
            if ($fault === 'legacyMissingHistory') { $data['runs'] = []; }
        }
        if (in_array($fault, ['missingHistory', 'corruptHistory', 'recreatedHistory'], true)) {
            if ($fault === 'missingHistory') { $data['runs'] = []; }
            $this->evalWp('$s=get_option("wrb_live_operation"); $s["scheduledSlot"]=$s["startedAt"]='.var_export($previous,true).';'
                .'update_option("wrb_live_operation",$s,false); echo "{}";');
        }
        $this->evalWp('$s=get_option("wrb_live_operation"); $s["startedAt"]='.var_export($previous,true).';'
            .'if(isset($s["transferStoppedAt"])) $s["transferStoppedAt"]=time()-3600;'
            .'update_option("wrb_live_operation",$s,false); echo "{}";');
        if ($fault === 'longRetry') {
            $this->evalWp('$s=get_option("wrb_live_operation"); $s["autoResumeAt"]=time()+86400; $s["transferStoppedAt"]=time()-180;'
                .'update_option("wrb_live_operation",$s,false); delete_option("wrb_live_schedule_runner"); echo "{}";');
            $tick();
            self::assertSame($id, $read()['id']);
            self::assertSame(0, $read()['autoResumeAttempts']);
            $this->evalWp('$s=get_option("wrb_live_operation"); $s["transferStoppedAt"]=time()-3600;'
                .'update_option("wrb_live_operation",$s,false); delete_option("wrb_live_schedule_runner"); echo "{}";');
            $beforeDeadline = $read();
            $tick();
            self::assertSame($beforeDeadline, $read());
            self::assertFileDoesNotExist($job.'/interrupted-operation.json');
            $wait = $this->evalWp('echo json_encode(get_option("wrb_live_schedule_runner"));');
            self::assertSame('storage_wait', $wait['lastResult']);
            $this->evalWp('$s=get_option("wrb_live_operation"); $s["autoResumeAt"]=time()-1;'
                .'update_option("wrb_live_operation",$s,false); echo "{}";');
        }
        if ($fault === 'nullCheckpoint') { file_put_contents($job.'/job.json', 'null'); }
        if ($fault === 'damaged') { unlink($job.'/capture-ready'); }
        if ($fault === 'manual') {
            $this->evalWp('(new WordPressR2Backup\LiveRunner(WordPressR2Backup\LiveSettings::load()))->resume(); echo "{}";');
            $run['events'][]=['at'=>$previous,'code'=>'resume_queued'];
        }
        file_put_contents($journal,json_encode($data,JSON_THROW_ON_ERROR));
        if ($fault === 'corruptHistory') { file_put_contents($journal, '{"corrupt":'); }
        $before=$read();
        if ($fault === 'manual') {
            // A newly queued manual resume is protected even before its worker takes the lock.
            $run['updatedAt']=gmdate('Y-m-d\TH:i:s\Z');
            file_put_contents($journal,json_encode($data,JSON_THROW_ON_ERROR));
            $this->evalWp('delete_option("wrb_live_schedule_runner"); echo "{}";');
            $tick(); self::assertSame($before,$read()); self::assertFileDoesNotExist($job.'/interrupted-operation.json');
            $run['updatedAt']=$previous;
            file_put_contents($journal,json_encode($data,JSON_THROW_ON_ERROR));
            $this->evalWp('$s=get_option("wrb_live_operation"); $s["queuedAt"]=time()-3600;'
                .'update_option("wrb_live_operation",$s,false); echo "{}";');
            $before=$read();
        }
        $checkpoint=hash_file('sha256',$job.'/job.json');
        $mustResume=in_array($fault,['legacy','terminated','longRetry'],true);
        if (!$mustResume) {
            $this->evalWp('delete_option("wrb_live_schedule_runner"); echo "{}";');
        }
        $held=fopen($workspace.'/runner.lock','c+b'); self::assertTrue(flock($held,LOCK_EX|LOCK_NB));
        try { $tick(); self::assertSame($before,$read()); } finally { fclose($held); }
        if (!$mustResume) { $this->evalWp('delete_option("wrb_live_schedule_runner"); echo "{}";'); }
        $tick();
        $queued=$read();
        self::assertSame('queued',$queued['status']);
        if ($mustResume) {
            self::assertSame($id,$queued['id']); self::assertSame($first['snapshotId'],$queued['snapshotId']);
            self::assertSame($first['completed'],$queued['completed']); self::assertSame(1,$queued['autoResumeAttempts']);
        } else {
            self::assertNotSame($id,$queued['id']); self::assertNotSame($first['snapshotId'],$queued['snapshotId']);
            self::assertSame($checkpoint,hash_file('sha256',$job.'/job.json'));
            self::assertSame($before,json_decode(file_get_contents($job.'/interrupted-operation.json'),true));
            if ($fault === 'corruptHistory') {
                self::assertSame('{"corrupt":', file_get_contents($journal));
                // The journal stays intact. Restore the fixture journal to inspect completion below.
                file_put_contents($journal, json_encode($data, JSON_THROW_ON_ERROR));
            } else {
                $old=$this->evalWp('echo json_encode((new WordPressR2Backup\BackupHistory(WordPressR2Backup\LiveSettings::workspace()))->find('.var_export($id,true).'));');
                self::assertSame('interrupted',$old['status']);
                self::assertSame('scheduled_released',end($old['events'])['code']);
            }
        }
        $done=$worker('resume','healthy');
        self::assertNull($done['error']); self::assertSame('complete',$done['state']['status']);
        self::assertContains('final_verification',array_column($done['events'],'code'));
        if (!$mustResume) {
            $marker=$job.'/interrupted-operation.json';
            $tick(); self::assertFileExists($marker);
            self::assertTrue(touch($marker,time()-32*86400));
            $tick();
            if (in_array($fault, ['legacy403', 'legacyMissingHistory'], true)) { self::assertFileExists($job.'/job.json'); }
            else { self::assertDirectoryDoesNotExist($job); }
        }
        if ($fault === 'manual') {
            $manual=$this->evalWp('echo json_encode((new WordPressR2Backup\LiveRunner(WordPressR2Backup\LiveSettings::load()))->startOnline());');
            $this->evalWp('delete_option("wrb_live_schedule_runner"); echo "{}";');
            $tick(); self::assertSame($manual,$read());
        }
        if ($mustResume) {
            $this->evalWp('delete_option("wrb_live_schedule_runner"); echo "{}";');
            $tick(); $next=$read(); self::assertSame('queued',$next['status']); self::assertNotSame($id,$next['id']);
        }
    }

    public function interruptedSlots(): array
    {
        return [['terminated'], ['forbidden'], ['exhausted'], ['legacy'], ['settings'], ['revision'], ['legacy403'], ['missingHistory'], ['legacyMissingHistory'], ['corruptHistory'], ['longRetry'], ['nullCheckpoint'], ['damaged'], ['manual'], ['recreatedHistory']];
    }

    public function testPersistentFailuresCanOnlyOccupyTwoLocalWorkspaces(): void
    {
        [$plugin, $config] = $this->prepare();
        $workspace = $this->evalWp('$s=WordPressR2Backup\LiveSettings::load(); $s["policy"]["preset"]="custom";'
            .'$s["policy"]["paths"]=["wp-admin"=>"exclude","wp-includes"=>"exclude","wp-content"=>"exclude"];'
            .'WordPressR2Backup\LiveSettings::save($s); echo json_encode(["path"=>WordPressR2Backup\LiveSettings::workspace()]);')['path'];
        $pressure=$this->scratch.'/pressured';
        $mu=$this->wp->root().'/wp-content/mu-plugins'; self::assertTrue(mkdir($mu,0700));
        file_put_contents($mu.'/capacity.php','<?php namespace WordPressR2Backup; function disk_free_space($path) {'
            .'return is_file('.var_export($pressure,true).') ? 4096 : \\disk_free_space($path); }');
        $store = $this->scratch.'/cipher-store'; self::assertTrue(mkdir($store,0700));
        $legacy = $workspace.'/job-'.str_repeat('f',64); self::assertTrue(mkdir($legacy,0700));
        file_put_contents($legacy.'/existing.age','protected');
        $read = fn (): array => $this->evalWp('echo json_encode(get_option("wrb_live_operation"));');
        $tick = function () use ($plugin): void {
            $result = $this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]);
            self::assertSame(0,$result['exit'],$result['err']);
        };
        $worker = function (string $action, string $fault) use ($store): array {
            return $this->evalWp('$directory='.var_export($store,true).'; $action='.var_export($action,true).'; $fault='.var_export($fault,true).';'
                .'require '.var_export(__DIR__.'/fixtures/scheduled-cipher-put.php',true).';');
        };
        $priorTime=time()-3600;
        $nextSlot = function () use ($workspace,$tick,$config,$priorTime): void {
            $this->evalWp('$s=get_option("wrb_live_operation"); if(is_array($s)) {'
                .'foreach (["queuedAt","transferStoppedAt"] as $key) if(isset($s[$key])) $s[$key]='.(string)$priorTime.';'
                .'update_option("wrb_live_operation",$s,false); } echo "{}";');

            $path = $workspace.'/backup-history.json';
            if (file_exists($path)) {
                $journal=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
                foreach ($journal['runs'] as &$run) {
                    $run['updatedAt']=$run['scheduledAt']=$run['schedule']['slot']=gmdate('Y-m-d\TH:i:s\Z',time()-3600);
                }
                unset($run);
                file_put_contents($path,json_encode($journal,JSON_THROW_ON_ERROR));
            }
            // Use a distinct revision for each simulated slot so its skip retains its own history ID.
            $config['revision']=bin2hex(random_bytes(32)); $config['time']=gmdate('H:i');
            $this->evalWp('delete_option("wrb_live_schedule_runner"); update_option("wrb_live_schedule",'.var_export($config,true).',false); echo "{}";');
            $tick();
        };
        $nextSlot();
        $first = $worker('capture','healthy')['state'];
        $firstFailure = $worker('step','forbidden'); self::assertSame('R2 returned HTTP 403.', $firstFailure['error']);
        $nextSlot();
        $second = $worker('capture','healthy')['state'];
        self::assertNotSame($first['id'],$second['id']);
        $secondFailure = $worker('step','forbidden'); self::assertSame('R2 returned HTTP 403.', $secondFailure['error']);
        foreach (['queuedAt','transferStoppedAt'] as $key) {
            if (isset($secondFailure['state'][$key])) { $secondFailure['state'][$key]=$priorTime; }
        }
        $firstDir = $workspace.'/job-'.$first['id']; $secondDir = $workspace.'/job-'.$second['id'];
        $firstSum=hash_file('sha256',$firstDir.'/job.json'); $secondSum=hash_file('sha256',$secondDir.'/job.json');
        $remoteBefore=glob($store.'/*');
        for ($i=0;$i<3;++$i) {
            $nextSlot();
            self::assertSame($secondFailure['state'],$read());
            $status=$this->evalWp('echo json_encode(get_option("wrb_live_schedule_runner"));');
            self::assertSame('skipped',$status['lastResult']); self::assertStringContainsString('2世代',$status['message']);
            $history=$this->evalWp('echo json_encode((new WordPressR2Backup\BackupHistory(WordPressR2Backup\LiveSettings::workspace()))->latest());');
            self::assertSame('schedule_retained_limit',end($history['events'])['code']);
            self::assertFileDoesNotExist($secondDir.'/interrupted-operation.json');
            self::assertCount(3,glob($workspace.'/job-*')); // Two interrupted jobs and the pre-existing directory.
        }
        self::assertSame($firstSum,hash_file('sha256',$firstDir.'/job.json')); self::assertSame($secondSum,hash_file('sha256',$secondDir.'/job.json'));
        self::assertSame($remoteBefore,glob($store.'/*'));
        // Both admission limits are active. Resuming the current transfer still makes no new capture or workspace.
        touch($pressure);
        $resumed=$this->evalWp('echo json_encode((new WordPressR2Backup\LiveRunner(WordPressR2Backup\LiveSettings::load()))->resume());');
        self::assertSame($second['snapshotId'],$resumed['snapshotId']); self::assertSame($second['completed'],$resumed['completed']);
        $thirdFailure=$worker('resume','forbidden');
        self::assertSame('R2 returned HTTP 403.',$thirdFailure['error']);
        self::assertSame($second['id'],$thirdFailure['state']['id']);
        self::assertSame($secondSum,hash_file('sha256',$secondDir.'/job.json'));
        unlink($pressure);
        touch($firstDir.'/interrupted-operation.json',time()-31*86400);
        $tick(); self::assertDirectoryDoesNotExist($firstDir); self::assertFileExists($secondDir.'/job.json');
        $nextSlot(); self::assertNotSame($second['id'],$read()['id']);
        $done=$worker('resume','healthy'); self::assertSame('complete',$done['state']['status']);
        self::assertContains('final_verification',array_column($done['events'],'code'));
        self::assertSame('protected',file_get_contents($legacy.'/existing.age'));
    }

    public function testLowFreeSpaceStopsNewCaptureAndKeepsTheCurrentGeneration(): void
    {
        [$plugin,$config]=$this->prepare();
        $mu=$this->wp->root().'/wp-content/mu-plugins'; self::assertTrue(mkdir($mu,0700));
        $space=$this->scratch.'/free-space';
        file_put_contents($mu.'/disk-space.php','<?php namespace WordPressR2Backup; function disk_free_space($path) {'
            .'$n=file_get_contents('.var_export($space,true).'); static $calls=0; ++$calls; if ($n==="boundary") return in_array("encryptStream",array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),"function"),true) ? 1073741824+65536 : 2147483648.0; if ($n==="during") return $calls===1 ? 2147483648.0 : 4096.0; if ($n==="nan") return NAN; if ($n==="infinite") return INF; return $n==="unknown" ? false : (float)$n; }');
        $previous=['status'=>'complete','id'=>str_repeat('d',64),'snapshotId'=>str_repeat('e',64),'localCipherBytes'=>64*1024*1024];
        $this->evalWp('update_option("wrb_live_operation",'.var_export($previous,true).',false); echo "{}";');
        $tick = function (bool $disabled = false) use ($plugin,$config): array {
            $config['revision']=bin2hex(random_bytes(32)); $config['time']=gmdate('H:i');
            $this->evalWp('delete_option("wrb_live_schedule_runner"); update_option("wrb_live_schedule",'.var_export($config,true).',false); echo "{}";');
            $command=[PHP_BINARY,'-d','sys_temp_dir='.$this->scratch];
            if ($disabled) { $command[]='-d'; $command[]='disable_functions=disk_free_space'; }
            $r=$this->process(array_merge($command,[$plugin.'/schedule.php',$this->wp->root()]));
            self::assertSame(0,$r['exit'],$r['err']);
            return $this->evalWp('echo json_encode(["runner"=>get_option("wrb_live_schedule_runner"),"active"=>get_option("wrb_live_operation"),'
                .'"history"=>(new WordPressR2Backup\BackupHistory(WordPressR2Backup\LiveSettings::workspace()))->latest()]);');
        };
        foreach (['unknown','nan','infinite',-1,64*1024*1024,1073741824+1024] as $free) {
            file_put_contents($space,(string)$free); $result=$tick();
            self::assertSame('skipped',$result['runner']['lastResult']); self::assertSame($previous,$result['active']);
            self::assertSame('schedule_low_space',end($result['history']['events'])['code']);
        }
        file_put_contents($space,(string)(2*1073741824));
        $disabled=$tick(true); self::assertSame('skipped',$disabled['runner']['lastResult']);
        self::assertSame($previous,$disabled['active']); self::assertSame('schedule_low_space',end($disabled['history']['events'])['code']);
        $result=$tick();
        self::assertSame('started',$result['runner']['lastResult']); self::assertSame('queued',$result['active']['status']);
        foreach (['during','boundary'] as $phase) {
            if ($phase==='boundary') { $result=$tick(); self::assertSame('started',$result['runner']['lastResult']); }
            file_put_contents($space,$phase);
            $paused=$this->evalWp('$b=new WordPressR2Backup\LiveBackup(WordPressR2Backup\LiveSettings::load()); $a=$b->state();'
                .'try { $b->begin($a["launchId"]); } catch (WordPressR2Backup\ScheduledStorageLimit $e) {}'
                .'echo json_encode(["state"=>$b->state(),"history"=>(new WordPressR2Backup\BackupHistory($b->workspace()))->find($a["id"]),'
                .'"marker"=>is_file($b->workspace()."/job-".$a["id"]."/interrupted-operation.json")]);');
            self::assertSame('failed',$paused['state']['status']); self::assertFalse($paused['marker']);
            self::assertSame('capture_low_space',end($paused['history']['events'])['code']);
            file_put_contents($space,(string)(2*1073741824));
        }
    }

    public function testFailedScheduledCapturesCleanTheirOwnPartialCiphertextAndRecoverAfterRestart(): void
    {
        [$plugin,$config]=$this->prepare();
        $scope=$this->evalWp('$s=WordPressR2Backup\LiveSettings::load(); $s["policy"]["preset"]="custom";'
            .'$s["policy"]["paths"]=["wp-admin"=>"exclude","wp-includes"=>"exclude","wp-content"=>"exclude"];'
            .'WordPressR2Backup\LiveSettings::save($s); echo json_encode(["path"=>WordPressR2Backup\LiveSettings::workspace()]);')['path'];
        $mu=$this->wp->root().'/wp-content/mu-plugins'; self::assertTrue(mkdir($mu,0700));
        // age publishes ciphertext atomically with link(). Fail the next encryption after one real file is durable.
        $fault=$this->scratch.'/fail-capture'; touch($fault);
        $evidence=$this->scratch.'/published-cipher';
        $obstruct=$this->scratch.'/block-cleanup';
        $kill=$this->scratch.'/crash-before-checkpoint';
        file_put_contents($mu.'/fail-capture.php','<?php namespace WordPressR2Backup; function link($source,$destination) { static $n=0;'
            .'if (is_file('.var_export($fault,true).') && str_ends_with($destination,".age") && ++$n===2) {if(is_file('.var_export($kill,true).')) exit(77); return false;}'
            .'$ok=\\link($source,$destination); if ($ok && str_ends_with($destination,".age")) file_put_contents('.var_export($evidence,true).',filesize($destination)); return $ok; }'
            .'function unlink($path) { if (is_file('.var_export($obstruct,true).') && str_ends_with($path,".age")) return false; return \\unlink($path); }'
            .'function rename($source,$dest) { if (is_file('.var_export($obstruct,true).') && basename($dest)==="interrupted-operation.json") return false; return \\rename($source,$dest); }');
        $tick=function () use ($plugin): array {
            $r=$this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$plugin.'/schedule.php',$this->wp->root()]);
            self::assertSame(0,$r['exit'],$r['err']);
            return $this->evalWp('echo json_encode(["runner"=>get_option("wrb_live_schedule_runner"),"active"=>get_option("wrb_live_operation")]);');
        };
        $slot=function () use ($tick,$config): array {
            $config['revision']=bin2hex(random_bytes(32)); $config['time']=gmdate('H:i');
            $this->evalWp('delete_option("wrb_live_schedule_runner"); update_option("wrb_live_schedule",'.var_export($config,true).',false); echo "{}";');
            return $tick();
        };
        $store=$this->scratch.'/healthy-r2'; self::assertTrue(mkdir($store,0700));
        $healthy=function () use ($slot,$store): void {
            $queued=$slot(); self::assertSame('started',$queued['runner']['lastResult']);
            $done=$this->evalWp('$directory='.var_export($store,true).'; $action="resume"; $fault="healthy";'
                .'require '.var_export(__DIR__.'/fixtures/scheduled-cipher-put.php',true).';');
            self::assertSame('complete',$done['state']['status']); self::assertContains('final_verification',array_column($done['events'],'code'));
        };
        foreach (['fail','healthy','fail','healthy','fail','fail','obstructed','healthy'] as $scenario) {
            if ($scenario==='healthy') { if (is_file($fault)) { unlink($fault); } $healthy(); continue; }
            touch($fault); if (is_file($evidence)) { unlink($evidence); }
            if ($scenario==='obstructed') { touch($obstruct); }
            $start=$slot(); self::assertSame('started',$start['runner']['lastResult']);
            $id=$start['active']['id']; $directory=$scope.'/job-'.$id;
            $finish=$this->evalWp('$b=new WordPressR2Backup\LiveBackup(WordPressR2Backup\LiveSettings::load());'
                .'$error=null; try {$b->begin($b->state()["launchId"]);} catch (RuntimeException $e) {$error=$e->getMessage();}'
                .'echo json_encode(["error"=>$error,"state"=>$b->state(),"history"=>(new WordPressR2Backup\BackupHistory($b->workspace()))->find('.var_export($id,true).')]);');
            self::assertGreaterThan(0,(int)file_get_contents($evidence));
            self::assertSame('Cannot publish age output without replacement.',$finish['error']);
            self::assertSame('failed',$finish['state']['status']); self::assertSame('capture_failed',end($finish['history']['events'])['code']);
            if ($scenario==='obstructed') {
                self::assertFileExists($directory.'/capture-owner.json'); self::assertFileDoesNotExist($directory.'/interrupted-operation.json');
                unlink($obstruct);
                // An active manual takeover, a checkpoint, a ready guard or a directory identity mismatch must veto cleanup.
                $setManual = function (bool $manual): void {
                    $this->evalWp('$a=get_option("wrb_live_operation"); $a["manualResume"]='.var_export($manual,true).';'
                        .'update_option("wrb_live_operation",$a,false); echo "{}";');
                };
                $setManual(true); $tick(); self::assertDirectoryExists($directory); $setManual(false);
                foreach (['job.json','capture-ready'] as $name) {
                    touch($directory.'/'.$name); $tick(); self::assertDirectoryExists($directory); unlink($directory.'/'.$name);
                }
                $path=$directory.'/capture-owner.json'; $saved=file_get_contents($path);
                $invalid=json_decode($saved,true); ++$invalid['ino']; file_put_contents($path,json_encode($invalid));
                $tick(); self::assertDirectoryExists($directory); file_put_contents($path,$saved);
                $lock=fopen($scope.'/runner.lock','c+b'); self::assertTrue(flock($lock,LOCK_EX|LOCK_NB));
                try { $tick(); self::assertDirectoryExists($directory); } finally { fclose($lock); }
                $tick(); // Fresh scheduler process completes the previously obstructed cleanup.
            }
            self::assertDirectoryDoesNotExist($directory);
        }

        touch($fault); touch($kill);
        $queued=$slot(); $id=$queued['active']['id']; $abandoned=$scope.'/job-'.$id;
        $script=$this->scratch.'/crash-capture.php';
        file_put_contents($script,'<?php require '.var_export($this->wp->root().'/wp-load.php',true).';'
            .'$b=new WordPressR2Backup\LiveBackup(WordPressR2Backup\LiveSettings::load()); $b->begin($b->state()["launchId"]);');
        $dead=$this->process([PHP_BINARY,'-d','sys_temp_dir='.$this->scratch,$script]); self::assertSame(77,$dead['exit']);
        self::assertFileExists($abandoned.'/capture-owner.json'); self::assertFileDoesNotExist($abandoned.'/capture-ready');
        $journalPath=$scope.'/backup-history.json';
        $data=json_decode(file_get_contents($journalPath),true);
        $time=gmdate('Y-m-d\TH:i:s\Z',time()-3600);
        foreach ($data['runs'] as &$run) {
            if ($run['id']===$id) { $run['updatedAt']=$run['scheduledAt']=$run['schedule']['slot']=$time; }
        }
        unset($run); file_put_contents($journalPath,json_encode($data));
        $this->evalWp('$s=get_option("wrb_live_operation"); $s["startedAt"]='.var_export($time,true).'; $s["queuedAt"]=time()-3600;'
            .'update_option("wrb_live_operation",$s,false); echo "{}";');
        unlink($kill); unlink($fault);
        $healthy(); self::assertDirectoryDoesNotExist($abandoned);

        // A manual online capture has no scheduled owner marker. Even its unsealed partial output remains untouched.
        touch($fault);
        $manual=$this->evalWp('$b=new WordPressR2Backup\LiveBackup(WordPressR2Backup\LiveSettings::load());'
            .'$r=new WordPressR2Backup\LiveRunner(WordPressR2Backup\LiveSettings::load()); $s=$r->startOnline();'
            .'try {$b->begin($s["launchId"]);} catch (RuntimeException $e) {} echo json_encode($b->state());');
        self::assertSame('failed',$manual['status']); $manualPath=$scope.'/job-'.$manual['id'];
        $tick(); self::assertDirectoryExists($manualPath); self::assertFileDoesNotExist($manualPath.'/capture-owner.json');
    }

}
