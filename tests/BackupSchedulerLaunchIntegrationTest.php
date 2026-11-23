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
        $paused = $this->evalWp($worker.$fault);
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
            self::assertEqualsWithDelta(time()+$delay, $paused['autoResumeAt'], 5);
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
            $paused=$this->evalWp($worker.'$fault=7;'.$resumeWorker);
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
}
