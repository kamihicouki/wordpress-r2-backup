<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\BackupHistory;
use WordPressR2Backup\R2TransportException;
use WordPressR2Backup\SourcePathCollision;
use WordPressR2Backup\SourcePathReviewRequired;

final class BackupHistoryTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    private function state(string $id, string $status): array
    {
        return ['id' => str_repeat($id, 64), 'status' => $status, 'startedAt' => '2026-11-17T01:00:00Z',
            'completed' => 0, 'total' => 8, 'snapshotId' => str_repeat('f', 64), 'message' => '/PRIVATE_DATA/runtime-secret'];
    }

    public function testFrozenSettingsAndSafeLifecycleSurviveAcrossReaders(): void
    {
        $directory = Fixture::temp(); $first = new BackupHistory($directory);
        $settings = ['bucket' => 'safe-backup', 'retentionDays' => 14, 'sharing' => ['enabled' => true, 'directory' => '/PRIVATE_DATA/sharing'],
            'secretAccessKey' => 'UNIQUE_KEY_VALUE', 'recipients' => ['UNIQUE_RECIPIENT'],
            'policy' => ['version' => 2, 'preset' => 'custom', 'paths' => ['PRIVATE_DATA' => 'exclude'],
                'rows' => ['private_table' => ['customer_email' => ['PRIVATE_CUSTOMER' => 'exclude']]]]];
        $state = $this->state('a', 'queued');
        $first->start($state, $settings, 'online');
        $settings['retentionDays'] = 30;
        $second = new BackupHistory($directory);
        $second->record($this->state('a', 'capturing'), 'capture_started');
        $second->record($this->state('a', 'uploading'), 'upload_started');
        $second->record($this->state('a', 'uploading'), BackupHistory::transferError(new R2TransportException(7)));
        self::assertSame('interrupted', $first->find($state['id'])['status']);
        $second->record($this->state('a', 'uploading'), 'resumed');
        $second->record($this->state('a', 'uploading'), 'final_verification');
        $second->record(array_replace($this->state('a', 'complete'), ['completed' => 8]), 'complete', 1024);
        $result = $first->latest();
        self::assertSame($state['id'], $result['id']);
        self::assertSame('complete', $result['status']);
        self::assertSame(8, $result['completed']);
        self::assertSame(1024, $result['bytes']);
        self::assertNotEmpty($result['endedAt']);
        self::assertSame('manual', $result['source']);
        self::assertSame(14, $result['settings']['retentionDays']);
        self::assertSame(1, $result['settings']['exclusions']['paths']);
        self::assertSame(1, $result['settings']['exclusions']['rows']);
        self::assertSame(['queued', 'capture_started', 'upload_started', 'transfer_connection', 'resumed', 'final_verification', 'complete'], array_column($result['events'], 'code'));
        $saved = file_get_contents($directory . '/backup-history.json');
        foreach (['UNIQUE_KEY_VALUE', 'UNIQUE_RECIPIENT', 'PRIVATE_DATA', 'PRIVATE_CUSTOMER', 'private_table', 'customer_email'] as $secret) {
            self::assertStringNotContainsString($secret, $saved);
        }
        self::assertSame(0600, fileperms($directory . '/backup-history.json') & 0777);
    }

    public function testRetryDetailsUseJournalCodesReadableByPreviousReleases(): void
    {
        $directory = Fixture::temp();
        $history = new BackupHistory($directory);
        $state = $this->state('d', 'uploading');
        foreach (['auto_resume_waiting','auto_resume_queued','auto_resume_exhausted'] as $code) { $history->record($state,$code); }
        $stored = json_decode(file_get_contents($directory.'/backup-history.json'),true,16,JSON_THROW_ON_ERROR);
        self::assertSame(['transfer_connection','resume_queued','transfer_connection'],array_column($stored['runs'][0]['events'],'code'));
        self::assertSame(['auto_resume_waiting','auto_resume_queued','auto_resume_exhausted'],array_column($history->latest()['events'],'code'));
    }

    public function testSchedulerOutcomeDoesNotOverwriteAnActualRunAndHasStableVersion(): void
    {
        $history = new BackupHistory(Fixture::temp());
        $schedule = ['slot' => '2026-11-17T01:00:00Z', 'revision' => str_repeat('c', 64), 'enabled' => true, 'time' => '10:00',
            'frequency' => 'weekly', 'weekday' => 7, 'mode' => 'online', 'effectiveSince' => '2026-11-16T01:00:00Z'];
        $settings = ['bucket' => 'safe-backup', 'retentionDays' => 30];
        $history->start($this->state('a', 'queued'), $settings, 'online', $schedule);
        $history->scheduleOutcome($schedule['slot'], $schedule, $settings, 'interrupted');
        self::assertCount(1, $history->recent());
        self::assertSame('queued', $history->latest()['status']);
        self::assertSame(7, $history->latest()['schedule']['weekday']);
        $missing = '2026-11-18T01:00:00Z';
        $history->scheduleOutcome($missing, $schedule, $settings, 'missed');
        $history->scheduleOutcome($missing, $schedule, $settings, 'failed');
        self::assertCount(2, $history->recent());
        self::assertSame('skipped', $history->latest()['status']);
        self::assertSame($missing, $history->latest()['scheduledAt']);
        self::assertSame($history->find(str_repeat('a', 64))['settings']['revision'], $history->latest()['settings']['revision']);
        $history->scheduleOutcome('2026-11-19T01:00:00Z', $schedule, null, 'missed');
        self::assertNull($history->latest()['settings']);
        self::assertSame('2026-11-19T01:00:00Z', $history->latest()['schedule']['slot']);
    }

    public function testCaptureErrorsUseOnlyFixedJapaneseMessages(): void
    {
        $workspace = Fixture::temp(); $history = new BackupHistory($workspace);
        foreach ([
            'a' => [new SourcePathCollision('/PRIVATE_DATA/sensitive-name'), 'capture_path_collision'],
            'b' => [new SourcePathReviewRequired('A source path requires an explicit inclusion decision.'), 'capture_path_review'],
            'c' => [new \RuntimeException('/PRIVATE_DATA/unclassified'), 'capture_failed'],
        ] as $label => [$error, $code]) {
            $state = $this->state($label, 'failed');
            $history->record($state, BackupHistory::captureError($error));
            self::assertSame($code, $history->find($state['id'])['events'][0]['code']);
        }
        self::assertStringNotContainsString('PRIVATE_DATA', file_get_contents($workspace . '/backup-history.json'));
    }

    public function testLegacyPrivacyCountsDoNotIncludeRuleValues(): void
    {
        $snapshot = BackupHistory::settingsSnapshot(['policy' => ['version' => 2, 'preset' => 'custom',
            'paths' => ['new-private' => 'exclude'], 'legacy' => ['scanPublic' => true, 'publicContentOnly' => false,
                'paths' => ['legacy-private' => 'exclude'], 'excludedRows' => ['table' => ['field' => ['private-match']]]]]], 'maintenance');
        self::assertSame(2, $snapshot['exclusions']['paths']);
        self::assertSame(1, $snapshot['exclusions']['rows']);
        self::assertSame(['scanPublic' => true, 'publicContentOnly' => false], $snapshot['legacy']);
        self::assertStringNotContainsString('private', json_encode($snapshot));
    }

    public function testQueuedFailureRequiresACompleteMarkerForTheCurrentLaunch(): void
    {
        $workspace = Fixture::temp(); $history = new BackupHistory($workspace);
        $first = str_repeat('b', 64); $second = str_repeat('c', 64);
        $state = $this->state('a', 'queued') + ['launchId' => $first];
        $history->start($state, [], 'online');
        $marker = 'runner-failure-' . $first . '.json';
        foreach (['', '{"launchId":', json_encode(['launchId' => $second, 'status' => 'failed']), json_encode(['launchId' => $first])] as $bad) {
            Fixture::write($workspace, $marker, $bad);
            self::assertSame('queued', $history->latest()['status']);
        }
        $resumed = array_replace($state, ['launchId' => $second, 'resumeStatus' => 'uploading']);
        $history->record($resumed, 'resume_queued');
        $history->launchFailure($state);
        self::assertSame('queued', $history->latest()['status']);
        Fixture::write($workspace, 'runner-failure-' . $second . '.json', json_encode(['launchId' => $second, 'status' => 'failed']));
        self::assertSame('interrupted', $history->latest()['status']);
    }

    public function testFailedRunRemainsFailedAfterCleanup(): void
    {
        $history = new BackupHistory(Fixture::temp());
        $failed = $this->state('a', 'failed');
        $history->record($failed, 'capture_path_review');
        $ended = $history->latest()['endedAt'];
        $history->record(array_replace($failed, ['status' => 'cancelled']), 'cancelled');
        self::assertSame('failed', $history->latest()['status']);
        self::assertSame($ended, $history->latest()['endedAt']);
        self::assertSame(['capture_path_review', 'failed_cleanup'], array_column($history->latest()['events'], 'code'));
    }

    public function testLegacyResumeHasNoInventedHistoricalSettings(): void
    {
        $history = new BackupHistory(Fixture::temp());
        $history->record($this->state('a', 'uploading'), 'resumed');
        self::assertSame('unknown', $history->latest()['source']);
        self::assertNull($history->latest()['settings']);
        self::assertSame('resumed', $history->latest()['events'][0]['code']);
    }
}
