<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\LiveBackup;

final class LiveBackupStateTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testAutomaticResumeRequiresAReadyCurrentScheduledOperation(): void
    {
        $revision = str_repeat('a',64);
        $schedule = ['enabled'=>true,'mode'=>'online','revision'=>$revision];
        $state = ['status'=>'uploading','consistencyMode'=>'online','scheduleRevision'=>$revision,'autoResumeAt'=>100,'autoResumeAttempts'=>0];
        self::assertTrue(LiveBackup::scheduledRetryDue($state,$schedule,100));
        self::assertFalse(LiveBackup::scheduledRetryDue($state,$schedule,99));
        foreach ([['status'=>'queued'], ['status'=>'cancelled'], ['scheduleRevision'=>null], ['scheduleRevision'=>str_repeat('b',64)],
            ['autoResumeAttempts'=>3], ['autoResumeAttempts'=>-1], ['autoResumeAt'=>null], ['consistencyMode'=>'maintenance']] as $override) {
            self::assertFalse(LiveBackup::scheduledRetryDue(array_replace($state,$override),$schedule,100));
        }
        self::assertFalse(LiveBackup::scheduledRetryDue($state,array_replace($schedule,['enabled'=>false]),100));
        self::assertFalse(LiveBackup::scheduledRetryDue(['status'=>'uploading','consistencyMode'=>'online'],$schedule,100));
    }

    public function testMaintenanceRefreshPreservesIdentityAndOwnershipButRejectsAnotherOwner(): void
    {
        $directory = Fixture::temp(); $path = $directory . '/.maintenance'; $token = str_repeat('a', 32);
        $marker = '<?php $upgrading = ' . time() . '; /* wrb-' . $token . ' */'; Fixture::write($directory, '.maintenance', $marker);
        $stat = lstat($path); $owner = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'token' => $token, 'sha256' => hash('sha256', $marker)];
        $class = new \ReflectionClass(LiveBackup::class); $backup = $class->newInstanceWithoutConstructor();
        $refresh = $class->getMethod('refreshMaintenance'); $refresh->setAccessible(true);
        $owns = $class->getMethod('ownsMaintenance'); $owns->setAccessible(true);
        $arguments = [$path, $owner, &$marker]; $refresh->invokeArgs($backup, $arguments);
        clearstatcache(true, $path);
        self::assertSame($stat['ino'], lstat($path)['ino']);
        self::assertSame($marker, file_get_contents($path)); self::assertStringContainsString((string) time(), $marker);
        self::assertTrue($owns->invoke($backup, $path, $owner));
        file_put_contents($path, '<?php $upgrading = ' . time() . '; /* wrb-' . str_repeat('b', 32) . ' */');
        self::assertFalse($owns->invoke($backup, $path, $owner));
        $this->expectException(\RuntimeException::class); $refresh->invokeArgs($backup, $arguments);
    }

    public function testExpiredMaintenanceCannotBeRefreshedOrAcceptedForPublication(): void
    {
        $directory = Fixture::temp(); $path = $directory . '/.maintenance'; $token = str_repeat('a', 32);
        $marker = '<?php $upgrading = ' . (time() - 600) . '; /* wrb-' . $token . ' */'; Fixture::write($directory, '.maintenance', $marker);
        $stat = lstat($path); $owner = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'token' => $token];
        $class = new \ReflectionClass(LiveBackup::class); $backup = $class->newInstanceWithoutConstructor();
        foreach (['refreshMaintenance', 'assertFreshMaintenance'] as $method) {
            $function = $class->getMethod($method); $function->setAccessible(true);
            $arguments = [$path, $owner, &$marker];
            $failure = null;
            try { $function->invokeArgs($backup, $arguments); } catch (\RuntimeException $expected) { $failure = $expected; }
            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertStringContainsString('期限', $failure->getMessage());
            self::assertSame($marker, file_get_contents($path));
        }
    }

    public function testChangedMarkerWithSameOwnerIsNotRefreshed(): void
    {
        $directory = Fixture::temp(); $path = $directory . '/.maintenance'; $token = str_repeat('a', 32);
        $marker = '<?php $upgrading = ' . time() . '; /* wrb-' . $token . ' */'; Fixture::write($directory, '.maintenance', $marker);
        $stat = lstat($path); $owner = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'token' => $token];
        file_put_contents($path, '<?php $upgrading = ' . (time() - 1) . '; /* wrb-' . $token . ' */');
        $class = new \ReflectionClass(LiveBackup::class); $backup = $class->newInstanceWithoutConstructor();
        $refresh = $class->getMethod('refreshMaintenance'); $refresh->setAccessible(true); $arguments = [$path, $owner, &$marker];
        $this->expectException(\RuntimeException::class); $refresh->invokeArgs($backup, $arguments);
    }

    public function testCaptureGuardRejectsIncompleteOrChangedJobsButAllowsLegacyJobs(): void
    {
        $directory = Fixture::temp(); $class = new \ReflectionClass(LiveBackup::class); $backup = $class->newInstanceWithoutConstructor();
        $write = $class->getMethod('writeCaptureGuard'); $write->setAccessible(true);
        $check = $class->getMethod('assertCaptureGuard'); $check->setAccessible(true);
        $legacy = ['id'=>str_repeat('1',64),'snapshotId'=>str_repeat('2',64)];
        $check->invoke($backup, $directory, $legacy, $legacy); // Existing in-flight jobs remain resumable.
        $state = $legacy + ['captureGuard'=>str_repeat('3',32)];
        foreach ([[$state,$state], [$state,$legacy], [$legacy,$state]] as $pair) {
            $rejected = false; try { $check->invoke($backup, $directory, $pair[0], $pair[1]); } catch (\RuntimeException $error) { $rejected = true; }
            self::assertTrue($rejected);
        }
        $write->invoke($backup, $directory, $state); $check->invoke($backup, $directory, $state, $state);
        self::assertSame(0600, fileperms($directory . '/capture-ready') & 0777);
        file_put_contents($directory . '/capture-ready', 'changed');
        $this->expectException(\RuntimeException::class); $check->invoke($backup, $directory, $state, $state);
    }

    public function testLegacyMaintenanceOwnershipStillRequiresExactContents(): void
    {
        $directory = Fixture::temp(); $path = $directory . '/.maintenance'; $marker = '<?php $upgrading = 1;';
        Fixture::write($directory, '.maintenance', $marker); $stat = lstat($path);
        $class = new \ReflectionClass(LiveBackup::class); $backup = $class->newInstanceWithoutConstructor();
        $owns = $class->getMethod('ownsMaintenance'); $owns->setAccessible(true);
        $owner = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'sha256' => hash('sha256', $marker)];
        self::assertTrue($owns->invoke($backup, $path, $owner));
        file_put_contents($path, '<?php $upgrading = 2;');
        self::assertFalse($owns->invoke($backup, $path, $owner));
    }

    public function testOnlineCaptureGuardCannotBeDowngradedToLegacyOrMaintenance(): void
    {
        $directory = Fixture::temp(); $class = new \ReflectionClass(LiveBackup::class); $backup = $class->newInstanceWithoutConstructor();
        $write = $class->getMethod('writeCaptureGuard'); $write->setAccessible(true);
        $check = $class->getMethod('assertCaptureGuard'); $check->setAccessible(true);
        $online = ['id'=>str_repeat('1',64), 'snapshotId'=>str_repeat('2',64), 'captureGuard'=>str_repeat('3',32), 'consistencyMode'=>'online', 'settingsDigest'=>str_repeat('4',64), 'sharingDigest'=>str_repeat('5',64)];
        $write->invoke($backup, $directory, $online); $check->invoke($backup, $directory, $online, $online);
        foreach ([[$online, array_diff_key($online, ['consistencyMode'=>true])], [array_diff_key($online, ['consistencyMode'=>true]), $online],
            [$online, array_merge($online, ['settingsDigest'=>str_repeat('6',64)])], [$online, array_merge($online, ['sharingDigest'=>str_repeat('7',64)])],
            [['id'=>$online['id'], 'snapshotId'=>$online['snapshotId'], 'consistencyMode'=>'online'], ['id'=>$online['id'], 'snapshotId'=>$online['snapshotId'], 'consistencyMode'=>'online']]] as $pair) {
            $rejected = false; try { $check->invoke($backup, $directory, $pair[0], $pair[1]); } catch (\RuntimeException $error) { $rejected = true; }
            self::assertTrue($rejected, 'online capture must retain its guard and its mode');
        }
    }

    public function testNewMaintenanceGuardBindsSettingsWhileLegacyGuardRemainsReadable(): void
    {
        $directory=Fixture::temp(); $class=new \ReflectionClass(LiveBackup::class); $backup=$class->newInstanceWithoutConstructor();
        $write=$class->getMethod('writeCaptureGuard'); $write->setAccessible(true);
        $check=$class->getMethod('assertCaptureGuard'); $check->setAccessible(true);
        $state=['id'=>str_repeat('1',64),'snapshotId'=>str_repeat('2',64),'captureGuard'=>str_repeat('3',32),
            'consistencyMode'=>'maintenance','settingsDigest'=>str_repeat('4',64),'sharingDigest'=>str_repeat('5',64)];
        $write->invoke($backup,$directory,$state); $check->invoke($backup,$directory,$state,$state);
        foreach ([array_merge($state,['settingsDigest'=>str_repeat('6',64)]),array_diff_key($state,['settingsDigest'=>true]),array_diff_key($state,['consistencyMode'=>true,'settingsDigest'=>true,'sharingDigest'=>true])] as $altered) {
            $error=null; try { $check->invoke($backup,$directory,$state,$altered); } catch(\RuntimeException $caught) { $error=$caught; }
            self::assertInstanceOf(\RuntimeException::class,$error);
        }
    }
}
