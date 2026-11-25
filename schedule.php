<?php
declare(strict_types=1);

// The maintenance mode is deliberately unavailable for scheduling until an external-writer
// verifier can prove that writes remain stopped throughout capture.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (count($argv) !== 2 || !is_string($argv[1])) { fwrite(STDERR, "WordPressのルートを指定してください。\n"); exit(1); }
$root = realpath($argv[1]);
if ($root === false || !is_file($root . '/wp-load.php')) { fwrite(STDERR, "WordPressを確認できません。\n"); exit(1); }
define('DISABLE_WP_CRON', true);
try {
    require $root . '/wp-load.php';
    require_once __DIR__ . '/vendor/autoload.php';
    $class = new ReflectionClass(\WordPressR2Backup\BackupScheduler::class);
    if (realpath((string) $class->getFileName()) !== realpath(__DIR__ . '/src/BackupScheduler.php')) { throw new RuntimeException('Release mismatch.'); }
    $workspace = \WordPressR2Backup\LiveSettings::workspace();
    \WordPressR2Backup\BackupScheduler::withScheduleLock($workspace, static function () use ($workspace): void {
        wp_cache_delete('wrb_live_schedule', 'options'); wp_cache_delete('wrb_live_schedule_runner', 'options'); wp_cache_delete('notoptions', 'options');
        $config = get_option('wrb_live_schedule', null);
        if ($config !== null && !is_array($config)) { throw new RuntimeException('Schedule configuration is invalid.'); }
        $state = get_option('wrb_live_schedule_runner', []);
        if (!is_array($state)) { throw new RuntimeException('Schedule state is invalid.'); }
        $lastAttempt = $state['lastAttempt'] ?? null;
        if ($lastAttempt !== null && !is_string($lastAttempt)) { throw new RuntimeException('Schedule state is invalid.'); }
        $zone = wp_timezone();
        $record = static function (string $slot, array $schedule, ?array $settings, string $outcome) use ($workspace, $zone): void {
            try {
                $schedule['timezone'] = $zone->getName();
                (new \WordPressR2Backup\BackupHistory($workspace))->scheduleOutcome($slot, $schedule, $settings, $outcome);
            } catch (Throwable $error) {
                fwrite(STDERR, "定期バックアップの履歴を記録できませんでした。\n");
            }
        };
        if (($state['lastResult'] ?? '') === 'claimed') {
            if ($lastAttempt === null || !is_string($state['claimRevision'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $state['claimRevision'])) { throw new RuntimeException('Schedule claim is invalid.'); }
            $state['lastResult'] = 'interrupted';
            $state['message'] = '前回の定期実行の起動結果を確認できません。重複を避けて再実行しないため、バックアップの状態を確認してください。';
            $claim = ($config['revision'] ?? null) === $state['claimRevision'] ? $config : ['revision' => $state['claimRevision']];
            $record($lastAttempt, $claim, null, 'interrupted');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $missed = is_array($config) ? \WordPressR2Backup\BackupScheduler::latestMissed($config, $state, $zone, $now) : null;
        if ($missed !== null) {
            $state['lastMissedSlot'] = $missed; $state['lastMissedRecordedAt'] = $now->format('Y-m-d\TH:i:s\Z'); $state['lastMissedRevision'] = $config['revision'];
            $record($missed, $config, null, 'missed');
        }
        if (is_array($config)) { $state['observedRevision'] = $config['revision']; }
        $slot = is_array($config) ? \WordPressR2Backup\BackupScheduler::due($config, $lastAttempt, $zone, $now) : null;
        $state['lastSeen'] = $now->format('Y-m-d\TH:i:s\Z');
        $persist = static function (array $value): void {
            update_option('wrb_live_schedule_runner', $value, false);
            wp_cache_delete('wrb_live_schedule_runner', 'options'); wp_cache_delete('notoptions', 'options');
            if (get_option('wrb_live_schedule_runner', null) !== $value) { throw new RuntimeException('Schedule state could not be recorded.'); }
        };
        $storageLimit = null;
        // Only detached scheduled operations without a retry deadline need reconciliation.
        if (($config['enabled'] ?? false) === true && ($config['mode'] ?? '') === 'online') {
            wp_cache_delete('wrb_live_operation', 'options');
            $active = get_option('wrb_live_operation', null);
            $unfinished = isset($active['scheduleRevision']) && (!isset($active['autoResumeAt']) || $slot !== null)
                && in_array($active['status'] ?? '', ['queued', 'capturing', 'uploading'], true);
            // Look for a retained workspace only on idle ticks, outside a due slot and pending retry.
            $cleanup = $slot === null && !in_array($active['status'] ?? '', ['queued', 'capturing', 'uploading'], true)
                && (glob($workspace . '/job-*/interrupted-operation.json', GLOB_NOSORT) || glob($workspace . '/job-*/capture-owner.json', GLOB_NOSORT));
            if ($unfinished || $cleanup) {
                try {
                    \WordPressR2Backup\BackupScheduler::assertOnlineRelease(__DIR__);
                    $currentSettings = \WordPressR2Backup\LiveSettings::load();
                    if (is_array($currentSettings)) {
                        (new \WordPressR2Backup\LiveRunner($currentSettings))->reconcileScheduled($config, $slot, $now->getTimestamp());
                    }
                } catch (\WordPressR2Backup\ScheduledStorageLimit $limit) {
                    $storageLimit = $limit;
                } catch (Throwable $error) {
                    // Leave the existing operation in place. The regular slot path records busy/unavailable.
                    fwrite(STDERR, "中断した定期実行を確認できませんでした。\n");
                }
            }
        }
        if ($slot !== null) {
            $settings = null;
            // Claim before any future launch. A crash may miss one slot, but cannot replay it.
            $state['lastAttempt'] = $slot; $state['claimRevision'] = $config['revision'];
            $state['status'] = 'claimed'; $state['lastResult'] = 'claimed';
            $persist($state);
            wp_cache_delete('wrb_live_schedule', 'options'); wp_cache_delete('notoptions', 'options');
            if (get_option('wrb_live_schedule', null) !== $config) {
                $state['status'] = 'config_changed'; $state['lastResult'] = 'config_changed';
                $state['message'] = '設定が変更されたため定期実行を開始しませんでした。';
            } elseif (($config['mode'] ?? '') === 'online') {
                try {
                    \WordPressR2Backup\BackupScheduler::assertOnlineRelease(__DIR__);
                    $ready = true;
                } catch (Throwable $error) { $ready = false; }
                if (!$ready) {
                    $state['status'] = 'unavailable'; $state['lastResult'] = 'unavailable';
                    $state['message'] = '保守なし取得に対応する有効なリリースを確認できませんでした。';
                } else {
                    try {
                        wp_cache_delete(\WordPressR2Backup\LiveSettings::OPTION, 'options'); wp_cache_delete('notoptions', 'options');
                        $settings = \WordPressR2Backup\LiveSettings::load();
                        if (!is_array($settings)) { throw new RuntimeException('Backup settings are missing.'); }
                        if ($storageLimit !== null) { throw $storageLimit; }
                        $queued = (new \WordPressR2Backup\LiveRunner($settings))->startOnline($config + ['slot' => $slot, 'timezone' => $zone->getName()]);
                        $digest = hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
                        if (($queued['status'] ?? '') !== 'queued' || ($queued['consistencyMode'] ?? '') !== 'online'
                            || !is_string($queued['settingsDigest'] ?? null) || !hash_equals($digest, $queued['settingsDigest'])
                            || !is_string($queued['sharingDigest'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $queued['sharingDigest'])) {
                            throw new RuntimeException('Online queue could not be verified.');
                        }
                        $state['status'] = 'started'; $state['lastResult'] = 'started';
                        $state['message'] = '保守なしバックアップの起動を受け付けました。実行状況を確認してください。';
                    } catch (\WordPressR2Backup\ScheduledStorageLimit $limit) {
                        $storageLimit = $limit;
                        $state['status'] = $state['lastResult'] = 'skipped';
                        $state['message'] = $limit->getMessage();
                    } catch (Throwable $error) {
                        // The worker may have started before an acknowledgement was lost; never retry this slot.
                        wp_cache_delete('wrb_live_operation', 'options'); wp_cache_delete('notoptions', 'options');
                        $active = get_option('wrb_live_operation', null);
                        $busy = is_array($active) && in_array($active['status'] ?? '', ['queued', 'capturing', 'uploading'], true);
                        $waiting = $busy && is_int($active['autoResumeAt'] ?? null) && $active['autoResumeAt'] > $now->getTimestamp();
                        $state['status'] = $state['lastResult'] = $waiting ? 'storage_wait' : ($busy ? 'skipped' : 'failed');
                        $state['message'] = !$busy ? '起動依頼を確認できません。自動再試行せず、バックアップの状態を確認してください。'
                            : ($waiting ? '保存先への再試行時刻に達していないため、定期実行を見送りました。'
                                : '未完了のバックアップがあるため、この予定の実行を見送りました。');
                    }
                }
            } else {
                $state['status'] = 'unavailable'; $state['lastResult'] = 'unavailable';
                $state['message'] = '安全な自動取得の準備が未完了のため開始しませんでした。';
            }
            if ($state['lastResult'] !== 'started') {
                $outcome = $state['lastResult'] === 'skipped' && $storageLimit !== null ? $storageLimit->outcome() : $state['lastResult'];
                $record($slot, $config, $settings, $outcome);
            }
        } else { $state['status'] = 'not_due'; }
        $persist($state);
        // Retry only a known interrupted scheduled operation, independently of the next new capture slot.
        if (($config['enabled'] ?? false) === true && ($config['mode'] ?? null) === 'online') {
            wp_cache_delete('wrb_live_operation', 'options'); wp_cache_delete('notoptions', 'options');
            $active = get_option('wrb_live_operation', null);
            if (is_array($active) && \WordPressR2Backup\LiveBackup::scheduledRetryDue($active, $config, $now->getTimestamp())) {
                \WordPressR2Backup\BackupScheduler::assertOnlineRelease(__DIR__);
                $settings = \WordPressR2Backup\LiveSettings::load();
                if (!is_array($settings)) { throw new RuntimeException('Backup settings are missing.'); }
                (new \WordPressR2Backup\LiveRunner($settings))->resumeScheduled($config, $active['id']);
            }
        }
        fwrite(STDOUT, json_encode(['status' => $state['status']], JSON_THROW_ON_ERROR) . "\n");
    });
} catch (Throwable $error) {
    fwrite(STDERR, "定期実行の状態を確認できませんでした。\n"); exit(1);
}
