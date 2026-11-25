<?php
declare(strict_types=1);
namespace WordPressR2Backup;

/** A bounded local journal. Only the immutable backup state can authorize capture, transfer or publication. */
final class BackupHistory
{
    private const MAX_RUNS = 720;
    private const MAX_EVENTS = 24;
    private const MAX_BYTES = 3145728;
    // Preserve the journal format understood by earlier releases. Retry details refine existing events.
    private const RETRY_EVENTS = [
        'schedule_retained_limit' => 'schedule_skipped',
        'schedule_low_space' => 'schedule_skipped',
        'capture_low_space' => 'capture_failed',
        'schedule_storage_wait' => 'schedule_skipped',
        'legacy_http_retry' => 'transfer_connection',
        'worker_lost_waiting' => 'transfer_connection',
        'scheduled_released' => 'transfer_failed',
        'auto_resume_config_changed' => 'transfer_failed',
        'auto_resume_waiting' => 'transfer_connection',
        'auto_resume_exhausted' => 'transfer_connection',
        'auto_resume_queued' => 'resume_queued',
    ];
    private const EVENTS = [
        'legacy_http_retry' => ['interrupted', '旧版で中断した暗号文転送の保存位置を検証しました。同じ世代を一度だけ自動再開します。'],
        'worker_lost_waiting' => ['interrupted', '実行ワーカーの停止を検出しました。検証済みの保存位置から同じ転送を自動再開します。'],
        'scheduled_released' => ['interrupted', '前回の定期実行を未完了として保管し、次の予定枠を解放しました。作業ファイルは元の場所に保持しています。'],
        'queued' => ['queued', 'バックアップの起動を待っています。'],
        'resume_queued' => ['queued', '転送の再開を待っています。'],
        'auto_resume_config_changed' => ['interrupted', '取得時の設定または接続・復号の確認状態が変わったため、自動再開を停止しました。取得時の設定を確認してください。'],
        'auto_resume_waiting' => ['interrupted', 'R2の一時エラーにより転送が中断しました。保存済みの位置からの自動再開を待っています。'],
        'auto_resume_queued' => ['queued', 'R2の一時エラーのため、保存済みの位置から転送を自動再開します。'],
        'auto_resume_exhausted' => ['interrupted', 'R2の一時エラーが続き、自動再開が上限の3回に達しました。接続を確認して「転送を再開」を実行してください。'],
        'capture_started' => ['capturing', 'ファイルとデータベースの取得を開始しました。'],
        'upload_started' => ['uploading', '取得と暗号化が完了し、R2への転送を開始しました。'],
        'resumed' => ['uploading', '保存済みの位置から転送を再開しました。'],
        'final_verification' => ['uploading', 'R2の保存物と完了記録を照合しています。'],
        'complete' => ['complete', 'R2への転送と完了記録の照合が完了しました。'],
        'retention_failed' => ['complete', '保存期限の処理を完了できませんでした。'],
        'cancelled' => ['cancelled', '未完了の実行を取り消しました。'],
        'failed_cleanup' => ['failed', '失敗した実行の未完了データを片付けました。'],
        'launch_failed' => ['failed', 'バックグラウンド処理を開始できませんでした。'],
        'resume_failed' => ['interrupted', '転送を再開できませんでした。バックグラウンド実行の設定を確認してください。'],
        'capture_failed' => ['failed', 'ファイルとデータベースの取得を完了できませんでした。'],
        'capture_path_collision' => ['failed', '同じ名前として扱われるファイルまたはフォルダーがあり、取得を中止しました。'],
        'capture_path_review' => ['failed', '取得対象に含めるか未確認のファイルがあります。除外設定を確認してください。'],
        'capture_unsupported_engine' => ['failed', '保守なしで取得できない形式のデータベーステーブルがあり、取得を中止しました。'],
        'transfer_connection' => ['interrupted', 'R2への接続が途切れました。保存済みの位置から転送を再開できます。'],
        'transfer_failed' => ['interrupted', '転送または照合を完了できませんでした。保存済みの位置から転送を再開できます。'],
        'schedule_retained_limit' => ['skipped', '未完了の定期バックアップが保管上限の2世代に達したため、新規取得を見送りました。'],
        'schedule_low_space' => ['skipped', '作業領域の空き容量を確保できないため、新規取得を見送りました。'],
        'capture_low_space' => ['failed', '作業領域の空き容量を確保できないため、取得を停止しました。'],
        'schedule_storage_wait' => ['skipped', '保存先への再試行時刻に達していないため、接続先への新しい定期実行も見送りました。'],
        'schedule_skipped' => ['skipped', '他のバックアップ処理があるため、定期実行を見送りました。'],
        'schedule_failed' => ['failed', '定期バックアップを起動できませんでした。'],
        'schedule_unavailable' => ['skipped', '実行に必要な設定または実行環境を確認できませんでした。'],
        'schedule_config_changed' => ['skipped', '定期実行の設定が変わったため、起動を見送りました。'],
        'schedule_missed' => ['skipped', '予定時刻に起動が確認できませんでした。さかのぼっての実行は行いません。'],
        'schedule_interrupted' => ['interrupted', '定期実行の起動確認が途中で止まりました。'],
    ];

    private string $workspace;

    public function __construct(string $workspace) { $this->workspace = $workspace; }

    public function recent(): array
    {
        $runs = $this->runsWithLaunchFailures();
        usort($runs, static function (array $a, array $b): int {
            return strcmp($b['scheduledAt'] ?? $b['startedAt'], $a['scheduledAt'] ?? $a['startedAt'])
                ?: strcmp($b['updatedAt'], $a['updatedAt']) ?: strcmp($b['id'], $a['id']);
        });
        return array_map([self::class, 'displayRun'], $runs);
    }

    public function latest(): ?array
    {
        $run = $this->runsWithLaunchFailures()[0] ?? null;
        return $run === null ? null : self::displayRun($run);
    }

    public function find(string $id): ?array
    {
        if (!self::isId($id)) { return null; }
        foreach ($this->runsWithLaunchFailures() as $run) { if ($run['id'] === $id) { return self::displayRun($run); } }
        return null;
    }

    private static function displayRun(array $run): array
    {
        unset($run['_launchId'], $run['_resume']);
        foreach ($run['events'] as &$event) {
            $detail = $event['retry'] ?? null;
            if (is_string($detail) && (self::RETRY_EVENTS[$detail] ?? null) === $event['code']) { $event['code'] = $detail; }
            $event['message'] = self::EVENTS[$event['code']][1];
            $status = $event['httpStatus'] ?? null;
            if (is_int($status) && $status >= 100 && $status <= 599) { $event['message'] .= '（HTTP ' . $status . '）'; }
        }
        unset($event);
        return $run;
    }

    private function runsWithLaunchFailures(): array
    {
        // Atomic replacement guarantees a reader sees either the old or the complete new journal.
        FileSafety::privateDirectory($this->workspace);
        $runs = $this->read();
        foreach ($runs as $run) {
            $launchId = $run['_launchId'] ?? null;
            if ($run['status'] === 'queued' && self::isId($launchId) && $this->hasLaunchFailure($launchId)) {
                $this->launchFailure(['id' => $run['id'], 'launchId' => $launchId,
                    'resumeStatus' => ($run['_resume'] ?? false) ? 'uploading' : null]);
                return $this->read();
            }
        }
        return $runs;
    }

    public function start(array $state, array $settings, string $mode, ?array $schedule = null): void
    {
        $this->change(function (array &$runs) use ($state, $settings, $mode, $schedule): bool {
            $id = $state['id'] ?? null;
            if (!self::isId($id) || self::index($runs, $id) !== null) { return false; }
            $snapshot = self::settingsSnapshot($settings, $mode, $schedule);
            $run = self::newRun($id, $state, $schedule === null ? 'manual' : 'scheduled', $snapshot);
            if ($snapshot['schedule'] !== null) {
                $run['schedule'] = $snapshot['schedule'];
                $run['scheduledAt'] = $snapshot['schedule']['slot'] ?? null;
            }
            self::apply($run, $state, ($state['status'] ?? null) === 'queued' ? 'queued' : 'capture_started', null);
            array_unshift($runs, $run);
            return true;
        });
    }

    public function record(array $state, string $code, ?int $bytes = null, ?int $httpStatus = null): void
    {
        $id = $state['id'] ?? null;
        if (!self::isId($id) || !isset(self::EVENTS[$code]) || str_starts_with($code, 'schedule_')) { return; }
        $this->change(static function (array &$runs) use ($state, $code, $bytes, $id, $httpStatus): bool {
            $index = self::index($runs, $id);
            $run = $index === null ? self::newRun($id, $state, 'unknown', null) : $runs[$index];
            if ($code === 'scheduled_released' && $run['source'] === 'unknown'
                && self::isId($state['scheduleRevision'] ?? null) && ($run['snapshotId'] ?? $state['snapshotId']) === $state['snapshotId']) {
                $run['source'] = 'scheduled';
                $run['schedule'] = ['revision' => $state['scheduleRevision']];
                if (self::isTime($state['scheduledSlot'] ?? null)) {
                    $run['scheduledAt'] = $state['scheduledSlot'];
                    $run['schedule']['slot'] = $state['scheduledSlot'];
                }
            }
            if ($run['status'] === 'cancelled' || ($run['status'] === 'complete' && $code !== 'retention_failed')) { return false; }
            if (in_array($code, ['launch_failed', 'resume_failed'], true) && $index !== null
                && ($run['status'] !== 'queued' || ($run['_launchId'] ?? null) !== ($state['launchId'] ?? null))) { return false; }
            if ($code === 'resumed' && $run['status'] !== 'queued' && $run['status'] !== 'interrupted' && $index !== null) { return false; }
            $event = $code === 'cancelled' && $run['status'] === 'failed' ? 'failed_cleanup' : $code;
            if (!self::apply($run, $state, $event, $bytes, $httpStatus)) { return false; }
            if ($index !== null) { array_splice($runs, $index, 1); }
            array_unshift($runs, $run);
            return true;
        });
    }

    /** Accept only the authenticated, complete worker marker for this exact launch. */
    public function hasLaunchFailure(string $launchId): bool
    {
        if (!self::isId($launchId)) { return false; }
        $path = $this->workspace . '/runner-failure-' . $launchId . '.json';
        try { $stat = self::fileStat($path); }
        catch (\RuntimeException $error) { return false; }
        if ($stat === null || $stat['size'] <= 0 || $stat['size'] >= 4096) { return false; }
        $body = @file_get_contents($path, false, null, 0, 4096);
        if (!is_string($body) || strlen($body) >= 4096) { return false; }
        // The marker contains only constrained diagnostic metadata; only these two identifying fields are consumed.
        $marker = json_decode($body, true, 8);
        return is_array($marker) && ($marker['status'] ?? null) === 'failed' && ($marker['launchId'] ?? null) === $launchId;
    }

    public function launchFailure(array $state): void
    {
        $this->record($state, ($state['resumeStatus'] ?? null) === 'uploading' ? 'resume_failed' : 'launch_failed');
    }

    public function scheduleOutcome(string $slot, array $schedule, ?array $settings, string $outcome): void
    {
        $revision = $schedule['revision'] ?? null;
        $code = 'schedule_' . $outcome;
        if (!self::isId($revision) || !self::isTime($slot) || !isset(self::EVENTS[$code])) { return; }
        $schedule['slot'] = $slot;
        $this->change(static function (array &$runs) use ($slot, $revision, $schedule, $settings, $code): bool {
            foreach ($runs as $other) {
                if (($other['scheduledAt'] ?? null) === $slot && ($other['schedule']['revision'] ?? null) === $revision) { return false; }
            }
            $id = hash('sha256', "wrb-schedule-history-v1\n" . $slot . "\n" . $revision);
            $snapshot = $settings === null ? null : self::settingsSnapshot($settings, (string) ($schedule['mode'] ?? ''), $schedule);
            $run = self::newRun($id, [], 'scheduled', $snapshot);
            $run['scheduledAt'] = $slot; $run['schedule'] = self::safeSchedule($schedule);
            self::apply($run, [], $code, null);
            array_unshift($runs, $run);
            return true;
        });
    }

    public static function captureError(\Throwable $error): string
    {
        if ($error instanceof ScheduledStorageLimit) { return 'capture_low_space'; }
        if ($error instanceof SourcePathCollision) { return 'capture_path_collision'; }
        if ($error instanceof SourcePathReviewRequired) { return 'capture_path_review'; }
        return $error instanceof UnsupportedOnlineDatabaseEngine ? 'capture_unsupported_engine' : 'capture_failed';
    }

    public static function transferError(\Throwable $error): string
    {
        return $error instanceof R2TransportException && $error->kind() === R2TransportException::CONNECTION ? 'transfer_connection' : 'transfer_failed';
    }

    /** A display snapshot, independent of the operational settings digest and never used to authorize anything. */
    public static function settingsSnapshot(array $settings, string $mode, ?array $schedule = null): array
    {
        $mode = in_array($mode, ['maintenance', 'online'], true) ? $mode : null;
        $rawPolicy = $settings['policy'] ?? null;
        $policy = is_array($rawPolicy) && $rawPolicy !== [] ? $rawPolicy : ['version' => 2, 'preset' => 'full'];
        $legacy = is_array($policy['legacy'] ?? null) ? $policy['legacy'] : (!isset($policy['version']) ? $policy : null);
        $exclusions = self::exclusionCounts($policy);
        if ($legacy !== null && $legacy !== $policy) {
            foreach (self::exclusionCounts($legacy) as $key => $count) { $exclusions[$key] += $count; }
        }
        $bucket = $settings['bucket'] ?? null; $days = $settings['retentionDays'] ?? null;
        $preset = $policy['preset'] ?? ($legacy === null ? null : 'custom');
        $summary = [
            'mode' => $mode,
            'bucket' => is_string($bucket) && preg_match('/\A[a-z0-9][a-z0-9-]{1,61}[a-z0-9]\z/D', $bucket) ? $bucket : null,
            'retentionDays' => is_int($days) && $days > 0 && $days <= 3650 ? $days : null,
            'sharing' => ($settings['sharing']['enabled'] ?? false) === true,
            'policyPreset' => in_array($preset, ['full', 'forms', 'custom'], true) ? $preset : null,
            'policySha256' => hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR)),
            'exclusions' => $exclusions,
            'legacy' => $legacy === null ? null : [
                'scanPublic' => is_bool($legacy['scanPublic'] ?? null) ? $legacy['scanPublic'] : null,
                'publicContentOnly' => is_bool($legacy['publicContentOnly'] ?? null) ? $legacy['publicContentOnly'] : null,
            ],
            'schedule' => $schedule === null ? null : self::safeSchedule($schedule),
        ];
        $stable = $summary;
        if ($stable['schedule'] !== null) { unset($stable['schedule']['slot'], $stable['schedule']['revision'], $stable['schedule']['effectiveSince']); }
        $revision = hash('sha256', json_encode($stable, JSON_THROW_ON_ERROR));
        return ['version' => substr($revision, 0, 12), 'revision' => $revision] + $summary;
    }

    private static function exclusionCounts(array $policy): array
    {
        $count = static fn ($rules): int => is_array($rules) ? count(array_keys($rules, 'exclude', true)) : 0;
        $counts = [];
        foreach (['tables', 'fields', 'paths', 'categories', 'options'] as $kind) { $counts[$kind] = $count($policy[$kind] ?? null); }
        // In the old format, field rules were grouped by table and excluded option and row values were lists.
        foreach ((array) ($policy['fields'] ?? []) as $actions) { $counts['fields'] += $count($actions); }
        $counts['options'] += count((array) ($policy['excludedOptions'] ?? []));
        $counts['rows'] = 0;
        foreach ((array) ($policy['rows'] ?? []) as $fields) {
            if (is_array($fields)) { foreach ($fields as $actions) { $counts['rows'] += $count($actions); } }
        }
        foreach ((array) ($policy['excludedRows'] ?? []) as $fields) {
            if (is_array($fields)) { foreach ($fields as $values) { $counts['rows'] += is_array($values) ? count($values) : 0; } }
        }
        return $counts;
    }

    private static function safeSchedule(array $schedule): array
    {
        $value = [];
        foreach (['slot', 'effectiveSince'] as $field) {
            if (self::isTime($schedule[$field] ?? null)) { $value[$field] = $schedule[$field]; }
        }
        if (self::isId($schedule['revision'] ?? null)) { $value['revision'] = $schedule['revision']; }
        if (is_bool($schedule['enabled'] ?? null)) { $value['enabled'] = $schedule['enabled']; }
        if (in_array($schedule['mode'] ?? null, ['maintenance', 'online'], true)) { $value['mode'] = $schedule['mode']; }
        if (in_array($schedule['frequency'] ?? null, ['hourly', 'daily', 'weekly'], true)) { $value['frequency'] = $schedule['frequency']; }
        if (is_string($schedule['time'] ?? null) && preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $schedule['time'])) { $value['time'] = $schedule['time']; }
        $weekday = $schedule['weekday'] ?? null;
        if ($weekday === null || (is_int($weekday) && $weekday >= 1 && $weekday <= 7)) { $value['weekday'] = $weekday; }
        $zone = $schedule['timezone'] ?? null;
        if (is_string($zone) && (in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)
                || preg_match('/\A[+-](?:0[0-9]|1[0-4]):[0-5][0-9]\z/D', $zone))) { $value['timezone'] = $zone; }
        return $value;
    }

    private static function newRun(string $id, array $state, string $source, ?array $settings): array
    {
        return ['id' => $id, 'source' => $source, 'status' => '', 'startedAt' => self::isTime($state['startedAt'] ?? null) ? $state['startedAt'] : self::now(),
            'settings' => $settings, 'events' => []];
    }

    private static function apply(array &$run, array $state, string $code, ?int $bytes, ?int $httpStatus = null): bool
    {
        $at = self::now();
        $status = self::EVENTS[$code][0];
        $last = end($run['events']);
        $storedCode = self::RETRY_EVENTS[$code] ?? $code;
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) { $httpStatus = null; }
        if ($last !== false && ($last['httpStatus'] ?? null) === $httpStatus && $last['code'] === $storedCode && ($last['retry'] ?? $storedCode) === $code
            && (!self::isId($state['launchId'] ?? null) || ($run['_launchId'] ?? null) === $state['launchId'])) { return false; }
        $event = ['at' => $at, 'code' => $storedCode];
        if ($storedCode !== $code) { $event['retry'] = $code; }
        if ($httpStatus !== null) { $event['httpStatus'] = $httpStatus; }
        $run['events'][] = $event;
        if (count($run['events']) > self::MAX_EVENTS) { array_splice($run['events'], 1, 1); }
        $run['status'] = $status; $run['updatedAt'] = $at;
        if (in_array($status, ['complete', 'failed', 'interrupted', 'cancelled', 'skipped'], true)) {
            if ($code === 'failed_cleanup') { $run['endedAt'] = $run['endedAt'] ?? $at; }
            elseif ($status === 'complete') { $run['endedAt'] = self::isTime($state['completedAt'] ?? null) ? $state['completedAt'] : ($run['endedAt'] ?? $at); }
            else { $run['endedAt'] = $at; }
        } else { unset($run['endedAt']); }
        foreach (['completed', 'total'] as $field) {
            if (is_int($state[$field] ?? null) && $state[$field] >= 0) { $run[$field] = $state[$field]; }
        }
        if (self::isId($state['snapshotId'] ?? null)) { $run['snapshotId'] = $state['snapshotId']; }
        if (self::isId($state['launchId'] ?? null)) { $run['_launchId'] = $state['launchId']; }
        if ($code === 'resume_queued' || $code === 'auto_resume_queued') { $run['_resume'] = true; }
        elseif ($status !== 'queued') { unset($run['_resume']); }
        if ($bytes !== null && $bytes >= 0 && $status === 'complete') { $run['bytes'] = $bytes; }
        return true;
    }

    private static function isId($value): bool { return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1; }
    private static function isTime($value): bool
    {
        if (!is_string($value) || strlen($value) !== 20) { return false; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }
    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

    private static function index(array $runs, string $id): ?int
    {
        foreach ($runs as $i => $run) { if ($run['id'] === $id) { return $i; } }
        return null;
    }

    private static function fileStat(string $path): ?array
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        if ($stat === false) { return null; }
        if (($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            throw new \RuntimeException('バックアップ履歴の保存場所を検証できません。');
        }
        return $stat;
    }

    private function read(): array
    {
        $path = $this->workspace . '/backup-history.json';
        if (self::fileStat($path) === null) { return []; }
        $body = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($body) || strlen($body) > self::MAX_BYTES) { throw new \RuntimeException('バックアップ履歴を読み込めません。'); }
        $data = json_decode($body, true, 16);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['runs'] ?? null) || count($data['runs']) > self::MAX_RUNS
            || array_values($data['runs']) !== $data['runs']) { throw new \RuntimeException('バックアップ履歴を検証できません。'); }
        foreach ($data['runs'] as $run) {
            if (!is_array($run) || !self::isId($run['id'] ?? null) || !is_array($run['events'] ?? null) || count($run['events']) > self::MAX_EVENTS
                || !is_string($run['status'] ?? null) || !is_string($run['startedAt'] ?? null) || !is_string($run['updatedAt'] ?? null)) {
                throw new \RuntimeException('バックアップ履歴を検証できません。');
            }
            foreach ($run['events'] as $event) {
                if (!is_array($event) || !is_string($event['code'] ?? null) || !isset(self::EVENTS[$event['code']]) || !is_string($event['at'] ?? null) || strlen($event['at']) !== 20) {
                    throw new \RuntimeException('バックアップ履歴を検証できません。');
                }
            }
        }
        return $data['runs'];
    }

    /** History failure must not change capture, transfer, publication, or schedule admission. */
    private function change(callable $operation): void
    {
        try {
            FileSafety::privateDirectory($this->workspace);
            $path = $this->workspace . '/backup-history.lock';
            $existing = self::fileStat($path);
            // Create with owner-only permissions, even if the process is interrupted immediately.
            $mask = umask(0077);
            try { $lock = @fopen($path, 'c+b'); }
            finally { umask($mask); }
            if (!is_resource($lock)) { throw new \RuntimeException('バックアップ履歴のロックを取得できません。'); }
            try {
                $current = self::fileStat($path); $open = fstat($lock);
                if ($current === null || $open === false || $current['ino'] !== $open['ino'] || $current['dev'] !== $open['dev']
                    || ($existing !== null && ($existing['ino'] !== $current['ino'] || $existing['dev'] !== $current['dev']))
                    || !self::lockForWrite($lock)) { throw new \RuntimeException('バックアップ履歴のロックを確認できません。'); }
                $runs = $this->read();
                if ($operation($runs)) { $this->write(array_slice($runs, 0, self::MAX_RUNS)); }
            } finally { fclose($lock); }
        } catch (\Throwable $error) {
            error_log('WordPress R2 Backup: バックアップ履歴を更新できませんでした。');
        }
    }

    private static function lockForWrite($lock): bool
    {
        $limit = hrtime(true) + 250000000;
        do {
            if (@flock($lock, LOCK_EX | LOCK_NB)) { return true; }
            usleep(5000);
        } while (hrtime(true) < $limit);
        return false;
    }

    private function write(array $runs): void
    {
        $temp = $this->workspace . '/backup-history.tmp';
        $target = $this->workspace . '/backup-history.json';
        $body = json_encode(['version' => 1, 'runs' => $runs], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($body) > self::MAX_BYTES) { throw new \RuntimeException('バックアップ履歴が上限を超えました。'); }
        if (self::fileStat($temp) !== null && !@unlink($temp)) { throw new \RuntimeException('バックアップ履歴を保存できません。'); }
        $mask = umask(0077);
        try { $stream = @fopen($temp, 'xb'); }
        finally { umask($mask); }
        if (!is_resource($stream)) { throw new \RuntimeException('バックアップ履歴を保存できません。'); }
        try {
            if (@fwrite($stream, $body) !== strlen($body) || !@FileSafety::sync($stream)) { throw new \RuntimeException('バックアップ履歴を保存できません。'); }
            self::fileStat($target);
            if (!@rename($temp, $target)) { throw new \RuntimeException('バックアップ履歴を確定できません。'); }
        } finally {
            fclose($stream);
            if (@lstat($temp) !== false) { @unlink($temp); }
        }
    }
}
