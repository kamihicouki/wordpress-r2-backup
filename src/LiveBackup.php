<?php
declare(strict_types=1);
namespace WordPressR2Backup;

final class LiveBackup
{
    /** Consecutive shared packs one transfer step of a legacy job (no capture guard) reads in full together. */
    private const LEGACY_REUSED_PACK_BATCH = 4;
    private const NEW_PAGE_BATCH = 16;
    private const SCHEDULED_RETRY_DELAYS = [60, 300, 900];
    private array $settings;
    private string $workspace;
    private DirectR2Repository $repository;
    private BackupHistory $history;
    private ?string $historyUploadRun = null;

    public function __construct(array $settings, ?DirectR2Repository $repository = null)
    {
        $this->settings = $settings;
        $this->workspace = LiveSettings::workspace();
        $this->history = new BackupHistory($this->workspace);
        $this->repository = $repository ?? new DirectR2Repository(array_intersect_key($settings, array_flip(['accountId', 'bucket', 'accessKeyId', 'secretAccessKey', 'unitId'])));
    }

    public function source(string $directory): WordPressSource
    {
        global $wpdb;
        return new WordPressSource(rtrim(ABSPATH, '/'), $wpdb, new PrivacyPolicy($this->settings['policy'], $wpdb->prefix), $directory);
    }

    public function sourceV4(string $directory, ?array $maintenanceOwner = null): V4Capture
    {
        global $wpdb;
        $rules = LiveSettings::privacyPolicy($this->settings);
        $roots = $this->sourceRoots($directory);
        return new V4Capture($roots, $wpdb, new PrivacyPolicy($rules, $wpdb->prefix), $directory, $maintenanceOwner, home_url('/'));
    }

    private function sourceRoots(string $directory): SourceRoots
    {
        $rules = LiveSettings::privacyPolicy($this->settings);
        $private = [$this->workspace, $directory];
        if (!empty($this->settings['sharing']['enabled'])) { $private[] = $this->settings['sharing']['directory']; }
        return isset($rules['legacy'])
            ? new SourceRoots(rtrim(ABSPATH, '/'), [], $private)
            : SourceRoots::fromWordPress(rtrim(ABSPATH, '/'), $private);
    }

    private function contentIndex(string $directory): ?V4ContentIndex
    {
        if (empty($this->settings['sharing']['enabled'])) { return null; }
        $base = $this->settings['sharing']['directory'] ?? '';
        FileSafety::assertNoLinks($base);
        $temporary = realpath(sys_get_temp_dir());
        if ($temporary === false || $base === $temporary || str_starts_with($base, $temporary . '/')) {
            throw new \RuntimeException('重複索引にはOSの一時領域以外の永続ディレクトリを指定してください。');
        }
        $this->sourceRoots($directory)->verifyPrivateLocations([$base]);
        if (@lstat($base) === false && !@mkdir($base, 0700)) { throw new \RuntimeException('重複索引の専用領域を作成できません。親ディレクトリを確認してください。'); }
        FileSafety::privateDirectory($base);
        $path = FileSafety::createPrivateDirectory($base . '/' . V4Format::scopeId($this->v4Scope()));
        return V4ContentIndex::openOrCreate($path, $this->v4Scope(), $this->repository);
    }

    private function sharingDigest(): string
    {
        return hash('sha256', V4Format::json($this->settings['sharing'] ?? ['enabled' => false, 'directory' => '']));
    }

    public function scan(): array
    {
        $directory = FileSafety::createPrivateDirectory($this->workspace . '/scan-' . bin2hex(random_bytes(32)));
        try { return $this->sourceV4($directory)->scan() + ['unresolved' => 0]; }
        finally { $this->removePrivateDirectory($directory); }
    }

    public function connectionTest(): array { return $this->repository->testConnection(); }

    public function listing(): array { return $this->repository->listStatus(); }

    public function state(): ?array
    {
        $state = $this->operationState();
        if (!is_array($state)) { return null; }
        if ($state['status'] === 'queued' && is_string($state['launchId'] ?? null) && $this->history->hasLaunchFailure($state['launchId'])) {
            $this->history->launchFailure($state);
            $state['status'] = 'failed';
            $state['message'] = 'バックグラウンド処理を開始できませんでした。未完了の実行を取り消してからPHP CLIの設定を確認してください。';
        }
        $path = $this->workspace . '/progress.json';
        if (is_file($path) && !is_link($path) && filesize($path) < 16384) {
            $progress = json_decode((string) file_get_contents($path), true);
            if (is_array($progress) && ($progress['id'] ?? '') === ($state['id'] ?? '') && in_array($state['status'], ['queued', 'capturing'], true)) {
                $state['progress'] = $progress;
            }
        }
        return $state;
    }

    public function workspace(): string { return $this->workspace; }

    public static function scheduledRetryDue(?array $state, array $schedule, int $now): bool
    {
        $attempts = $state['autoResumeAttempts'] ?? null;
        $revision = $schedule['revision'] ?? null;
        return ($schedule['enabled'] ?? false) === true && ($schedule['mode'] ?? null) === 'online'
            && is_string($revision) && preg_match('/\A[a-f0-9]{64}\z/D', $revision) && ($state['scheduleRevision'] ?? null) === $revision
            && empty($state['manualResume']) && ($state['status'] ?? null) === 'uploading' && ($state['consistencyMode'] ?? null) === 'online'
            && is_int($attempts) && isset(self::SCHEDULED_RETRY_DELAYS[$attempts])
            && is_int($state['autoResumeAt'] ?? null) && $state['autoResumeAt'] > 0 && $state['autoResumeAt'] <= $now;
    }

    /** Called while the scheduler owns runner.lock. A live worker owns that lock for its entire lifetime. */
    public function reconcileScheduled(array $schedule, ?string $slot, int $now): void
    {
        $lock = $this->lock();
        try {
            $state = $this->operationState();
            $revision = $schedule['revision'] ?? null;
            if (($schedule['enabled'] ?? false) !== true || ($schedule['mode'] ?? '') !== 'online'
                || !is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D', $revision)
                || !is_string($state['scheduleRevision'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $state['scheduleRevision'])
                || ($state['consistencyMode'] ?? '') !== 'online'
                || !in_array($state['status'] ?? '', ['queued', 'capturing', 'uploading'], true)
                || isset($state['maintenanceOwner'])
                || !is_int($state['autoResumeAttempts'] ?? null)
                || !preg_match('/\A[a-f0-9]{64}\z/D', (string) ($state['id'] ?? ''))
                || !preg_match('/\A[a-f0-9]{64}\z/D', (string) ($state['snapshotId'] ?? ''))) { return; }
            if (@lstat(rtrim(ABSPATH, '/') . '/.maintenance') !== false) { return; }
            // A future server deadline also applies when the scheduled configuration has changed.
            if (is_int($state['autoResumeAt'] ?? null) && $state['autoResumeAt'] > $now) { return; }
            $run = $this->history->find($state['id']);
            if ($run !== null && (($run['source'] ?? '') !== 'scheduled' || ($run['snapshotId'] ?? null) !== $state['snapshotId']
                || ($run['schedule']['revision'] ?? null) !== $state['scheduleRevision'])) { return; }
            // A long-lived interruption can outlast the bounded journal. New jobs also carry their original slot.
            $previousSlot = $run['scheduledAt'] ?? $state['scheduledSlot'] ?? null;
            if (!is_string($previousSlot) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $previousSlot)) { return; }
            $events = array_column($run['events'] ?? [], 'code');
            // Old releases did not persist manual takeover in the operation option.
            $manual = !empty($state['manualResume']) || in_array('resume_queued', $events, true);
            $recent = strtotime($run['updatedAt'] ?? $state['startedAt'] ?? '');
            if ($recent === false || $now - $recent < 120) { return; }
            try { $this->assertReady(); } catch (\RuntimeException $unready) { return; }
            $sameSettings = $revision === $state['scheduleRevision'];
            try { $this->assertBoundSettingsUnchanged($state); }
            catch (\RuntimeException $changed) { $sameSettings = false; }
            if (!$manual && $sameSettings && self::scheduledRetryDue($state, $schedule, $now)) { return; }
            $attempts = $state['autoResumeAttempts'];
            $directory = $this->workspace . '/job-' . $state['id'];
            $upload = $state['status'] === 'uploading' || ($state['status'] === 'queued' && ($state['resumeStatus'] ?? '') === 'uploading');
            $lost = !isset($state['transferStopped']) && in_array(end($events),
                ['upload_started', 'resumed', 'final_verification', 'auto_resume_queued', 'worker_lost_waiting'], true);
            $last = $run === null ? null : end($run['events']);
            // A pre-marker HTTP 500 has no method. Only an immutable payload checkpoint permits a single migration.
            $legacy = !isset($state['scheduledSlot']) && !isset($state['transferStopped']) && $attempts === 0
                && ($last['code'] ?? null) === 'transfer_failed' && ($last['httpStatus'] ?? null) === 500;
            if (!$manual && $sameSettings && $upload && ($lost || $legacy) && isset(self::SCHEDULED_RETRY_DELAYS[$attempts])
                && $this->validOrphanCheckpoint($directory, $state, $legacy)) {
                $state['status'] = 'uploading';
                unset($state['resumeStatus']);
                $state['autoResumeAt'] = max($now, $recent + ($legacy ? 900 : self::SCHEDULED_RETRY_DELAYS[$attempts]));
                $this->setState($state);
                $this->history->record($state, $legacy ? 'legacy_http_retry' : 'worker_lost_waiting', null, $legacy ? 500 : null);
                return;
            }
            if ($slot === null || strcmp($previousSlot, $slot) >= 0) { return; }
            // Keep every byte at its original absolute path. Job receipts and the capture guard bind these paths.
            if (@lstat($directory) !== false) {
                FileSafety::privateDirectory($directory);
                $this->saveJob($directory, $state, 'interrupted-operation.json');
            }
            $state['status'] = 'failed';
            $state['message'] = '前回の定期バックアップを未完了として保管しました。実行履歴を確認してください。';
            unset($state['autoResumeAt']);
            // If persisting the operation fails after journaling, the next slot repeats the same handoff.
            $this->history->record($state, 'scheduled_released');
            $saved = $this->history->find($state['id']);
            if (($saved['status'] ?? '') !== 'interrupted' || end($saved['events'])['code'] !== 'scheduled_released') { return; }
            $this->setState($state);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** At most one expired detached workspace per idle tick. Remote objects and pre-policy jobs are never removed here. */
    public function pruneExpiredScheduledWork(int $now): void
    {
        $lock = $this->lock();
        try {
            $activeId = $this->operationState()['id'] ?? null;
            foreach (glob($this->workspace . '/job-*/interrupted-operation.json', GLOB_NOSORT) ?: [] as $marker) {
                $directory = dirname($marker);
                if (!preg_match('/\Ajob-([a-f0-9]{64})\z/D', basename($directory), $match) || $match[1] === $activeId) { continue; }
                FileSafety::privateDirectory($directory);
                FileSafety::assertRegularFile($marker);
                $stat = lstat($marker);
                if (($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1 || $stat['size'] > 65536
                    || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) { continue; }
                $state = json_decode((string) file_get_contents($marker), true, 32);
                $days = $state['localRetentionDays'] ?? null;
                if (($state['id'] ?? null) !== $match[1] || ($state['consistencyMode'] ?? '') !== 'online'
                    || !is_string($state['scheduledSlot'] ?? null)
                    || !is_int($days) || $days < 1 || $days > 3650 || $stat['mtime'] + $days * 86400 > $now) { continue; }
                $this->removePrivateDirectory($directory, $marker);
                return;
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function validOrphanCheckpoint(string $directory, array $state, bool $legacy): bool
    {
        try {
            FileSafety::privateDirectory($directory);
            $job = $this->readJob($directory);
            if (($job['version'] ?? null) !== 4 || ($job['settingsDigest'] ?? null) !== $this->settingsDigest()
                || ($job['sharingDigest'] ?? null) !== $this->sharingDigest()) { return false; }
            $this->assertCaptureGuard($directory, $state, $job['state'] ?? []);
            $this->validateV4Job($job, $directory);
            $position = $job['position'];
            if (($job['state']['completed'] ?? null) !== $position || ($job['state']['total'] ?? null) !== count($job['objects']) + 1
                || !in_array($job['state']['status'] ?? '', ['uploading','complete'], true)
                || !is_int($state['completed'] ?? null) || $state['completed'] > $position) { return false; }
            if (!$legacy) { return true; }
            $entry = $job['objects'][$position] ?? null;
            if (!is_string($entry['cipherFile'] ?? null) || $position !== $state['completed']) { return false; }
            $cipher = $this->cipher($directory, $entry['cipherFile']);
            return filesize($cipher) === $entry['object']['bytes'] && hash_file('sha256', $cipher) === $entry['object']['sha256'];
        } catch (\RuntimeException | \InvalidArgumentException | \JsonException $error) { return false; }
    }

    public function queue(string $launchId, bool $resume = false, string $mode = 'maintenance', ?array $schedule = null): ?array
    {
        $automatic = $resume && $schedule !== null;
        if (!$automatic) { $this->assertReady(); }
        if (!in_array($mode, ['maintenance', 'online'], true) || ($resume && $mode !== 'maintenance')) { throw new \RuntimeException('取得方式が不正です。'); }
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $launchId)) { throw new \RuntimeException('起動識別子が不正です。'); }
        $lock = $this->lock();
        try {
            $state = $this->operationState();
            if ($resume) {
                if ($automatic) {
                    // Recheck under operation.lock after acquiring runner.lock. Cancellation and manual resume can win.
                    if (!self::scheduledRetryDue($state, $schedule, time()) || $state['id'] !== ($schedule['operationId'] ?? null)) { return null; }
                    try {
                        $this->assertReady();
                        $this->assertBoundSettingsUnchanged($state);
                    } catch (\RuntimeException $error) {
                        unset($state['autoResumeAt']);
                        $this->setState($state);
                        $this->history->record($state, 'auto_resume_config_changed');
                        throw $error;
                    }
                    ++$state['autoResumeAttempts'];
                }
                if (!is_array($state) || $state['status'] !== 'uploading') { throw new \RuntimeException('転送を再開する操作がありません。'); }
                unset($state['autoResumeAt'], $state['transferStopped']);
                if (!$automatic && isset($state['scheduleRevision'])) { $state['manualResume'] = true; }
                $state['resumeStatus'] = 'uploading';
            } else {
                if (is_array($state) && !in_array($state['status'], ['complete', 'failed', 'cancelled'], true)) { throw new \RuntimeException('未完了のバックアップがあります。転送を再開してください。'); }
                if (@lstat(rtrim(ABSPATH, '/') . '/.maintenance') !== false) { throw new \RuntimeException('保守状態が残っています。未完了の実行を取り消してから取得してください。'); }
                $state = ['id' => bin2hex(random_bytes(32)), 'snapshotId' => bin2hex(random_bytes(32)), 'completed' => 0, 'total' => 0, 'startedAt' => gmdate('Y-m-d\TH:i:s\Z')];
                if ($mode === 'online' && isset($schedule['revision'])) {
                    $state['scheduleRevision'] = $schedule['revision'];
                    if (isset($schedule['slot'])) {
                        $state['scheduledSlot'] = $schedule['slot'];
                        $state['localRetentionDays'] = $this->settings['retentionDays'];
                    }
                    $state['autoResumeAttempts'] = 0;
                }
                $state['consistencyMode'] = $mode; $state['settingsDigest'] = $this->fullSettingsDigest($this->settings); $state['sharingDigest'] = $this->sharingDigest();
                $this->assertBoundSettingsUnchanged($state);
            }
            $state['status'] = 'queued'; $state['launchId'] = $launchId; $state['message'] = 'バックグラウンド処理の開始を待っています。';
            $this->setState($state);
            if ($resume) { $this->history->record($state, $automatic ? 'auto_resume_queued' : 'resume_queued'); }
            else { $this->history->start($state, $this->settings, $mode, $schedule); }
            return $state;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function runQueued(string $launchId, ?array $ticket = null): array
    {
        $lock = $this->lock();
        try {
            $state = $this->operationState();
            if (!is_array($state) || $state['status'] !== 'queued' || !hash_equals((string) ($state['launchId'] ?? ''), $launchId)) { throw new \RuntimeException('起動待ちの操作を検証できません。'); }
            $this->assertQueuedTicket($state, $ticket);
            $resume = ($state['resumeStatus'] ?? '') === 'uploading';
            if ($resume) { $state['status'] = 'uploading'; unset($state['resumeStatus']); $this->setState($state); $this->history->record($state, 'resumed'); }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        if (!$resume) { $state = $this->begin($launchId); }
        while ($state['status'] === 'uploading') { $state = $this->step(); }
        return $state;
    }

    /** Legacy tickets may resume an already captured legacy job, but cannot start a new unbound capture. */
    private function assertQueuedTicket(array $state, ?array $ticket): void
    {
        $resume = ($state['resumeStatus'] ?? '') === 'uploading';
        if ($ticket === null && $resume) { return; } // Trusted internal resume still revalidates the job and guard in step().
        if (!is_array($ticket)) { throw new \RuntimeException('起動情報の取得方式を検証できません。'); }
        if (($ticket['version'] ?? null) === 1) {
            if ($resume && !array_key_exists('consistencyMode', $state) && !array_key_exists('settingsDigest', $state) && !array_key_exists('sharingDigest', $state)) { return; }
            throw new \RuntimeException('以前の起動情報では新しい取得を開始できません。');
        }
        $mode = $state['consistencyMode'] ?? 'maintenance';
        $settings = $state['settingsDigest'] ?? null; $sharing = $state['sharingDigest'] ?? null;
        if (!in_array($mode, ['maintenance', 'online'], true) || ($ticket['version'] ?? null) !== 2 || ($ticket['action'] ?? '') !== ($resume ? 'resume' : 'start')
            || ($ticket['mode'] ?? '') !== $mode || ($ticket['settingsDigest'] ?? null) !== $settings || ($ticket['sharingDigest'] ?? null) !== $sharing) {
            throw new \RuntimeException('起動情報と取得設定が一致しません。');
        }
        $bound = is_string($settings) && preg_match('/\A[a-f0-9]{64}\z/D', $settings) && is_string($sharing) && preg_match('/\A[a-f0-9]{64}\z/D', $sharing);
        $legacyResume = $resume && $mode === 'maintenance' && !array_key_exists('consistencyMode', $state) && !array_key_exists('settingsDigest', $state) && !array_key_exists('sharingDigest', $state);
        if (!$bound && !$legacyResume) { throw new \RuntimeException('起動情報の取得設定を検証できません。'); }
    }

    public function launchFailed(string $launchId): void
    {
        $lock = self::lockWorkspace($this->workspace, true);
        try {
            $state = $this->operationState();
            if (is_array($state) && $state['status'] === 'queued' && ($state['launchId'] ?? '') === $launchId) {
                $state['status'] = ($state['resumeStatus'] ?? '') === 'uploading' ? 'uploading' : 'failed';
                $state['message'] = 'バックグラウンド処理を開始できませんでした。PHP CLIの設定を確認してください。';
                $this->setState($state);
                $this->history->launchFailure($state);
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function assertReady(): void
    {
        if (empty($this->settings['connectionVerified'])) { throw new \RuntimeException('先にR2接続を確認してください。'); }
        foreach ([0, 1] as $slot) { if (empty($this->settings['proofs'][$slot]['confirmed'])) { throw new \RuntimeException('公開鍵2本の復号確認を完了してください。'); } }
    }

    private function setState(array $state): void
    {
        update_option('wrb_live_operation', $state, false);
        if ($this->operationState() !== $state) { throw new \RuntimeException('実行状態を保存できません。'); }
    }

    private function operationState(): ?array
    {
        // This option is always written with autoload=false, so it has its own cache key.
        // A detached worker must not retain another process's pre-cancellation state.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('wrb_live_operation', 'options');
            wp_cache_delete('notoptions', 'options');
        }
        $state = get_option('wrb_live_operation', null);
        return is_array($state) ? $state : null;
    }

    public function begin(?string $launchId = null): array
    {
        $this->assertReady();
        $lock = $this->lock();
        $maintenance = rtrim(ABSPATH, '/') . '/.maintenance';
        $owned = false;
        try {
            $existing = $this->state();
            $queued = $launchId !== null && $existing !== null && $existing['status'] === 'queued' && hash_equals((string) ($existing['launchId'] ?? ''), $launchId) && empty($existing['resumeStatus']);
            if ($launchId !== null && !$queued) { throw new \RuntimeException('起動待ちの操作が取り消されたか変更されています。'); }
            if ($existing !== null && !$queued && !in_array($existing['status'], ['complete', 'failed', 'cancelled'], true)) { throw new \RuntimeException('未完了のバックアップがあります。転送を再開してください。'); }
            $binding = $queued ? $existing : ['consistencyMode' => 'maintenance', 'settingsDigest' => $this->fullSettingsDigest($this->settings), 'sharingDigest' => $this->sharingDigest()];
            $mode = $binding['consistencyMode'] ?? null;
            if (!in_array($mode, ['maintenance', 'online'], true)) { throw new \RuntimeException('取得方式が不正です。'); }
            $this->assertBoundSettingsUnchanged($binding);
            if (@lstat($maintenance) !== false) { throw new \RuntimeException('既に保守状態です。現在の保守処理が終わってから取得してください。'); }
            $marker = ''; $owner = null;
            if ($mode === 'maintenance') {
                $file = @fopen($maintenance, 'xb');
                if ($file === false) { throw new \RuntimeException('WordPressを保守状態にできません。'); }
                $maintenanceStat = fstat($file);
                $owned = true;
                $maintenanceToken = bin2hex(random_bytes(16));
                $marker = '<?php $upgrading = ' . time() . '; /* wrb-' . $maintenanceToken . ' */';
                try {
                    if (!chmod($maintenance, 0644) || fwrite($file, $marker) !== strlen($marker) || !fflush($file)) { throw new \RuntimeException('保守状態を保存できません。'); }
                    $maintenanceStat = fstat($file);
                } finally { fclose($file); }
                $owner = ['dev' => $maintenanceStat['dev'], 'ino' => $maintenanceStat['ino'], 'token' => $maintenanceToken, 'sha256' => hash('sha256', $marker)];
            }
            $id = $queued ? $existing['id'] : bin2hex(random_bytes(32));
            $directory = FileSafety::createPrivateDirectory($this->workspace . '/job-' . $id);
            $state = ['id' => $id, 'snapshotId' => $queued ? $existing['snapshotId'] : bin2hex(random_bytes(32)), 'status' => 'capturing', 'completed' => 0, 'total' => 0, 'startedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'captureGuard' => bin2hex(random_bytes(16)),
                'message' => 'ファイルとデータベースを取得しています。'];
            if (isset($binding['scheduleRevision'])) {
                $state['scheduleRevision'] = $binding['scheduleRevision'];
                if (isset($binding['scheduledSlot'])) { $state['scheduledSlot'] = $binding['scheduledSlot']; }
                if (isset($binding['localRetentionDays'])) { $state['localRetentionDays'] = $binding['localRetentionDays']; }
                $state['autoResumeAttempts'] = $binding['autoResumeAttempts'];
            }
            if ($mode === 'maintenance') { $state['maintenanceOwner'] = $owner; }
            $state['consistencyMode'] = $mode; $state['settingsDigest'] = $binding['settingsDigest']; $state['sharingDigest'] = $binding['sharingDigest'];
            $this->setState($state);
            if ($queued) { $this->history->record($state, 'capture_started'); }
            else { $this->history->start($state, $this->settings, $mode); }
            if (function_exists('set_time_limit')) { @set_time_limit(0); }
            $lastRefresh = 0;
            $progressSnapshot = [];
            $index = $this->contentIndex($directory);
            $operation = function () use ($directory, $state, $maintenance, $owner, $mode, &$marker, &$lastRefresh, &$progressSnapshot, $index): array {
                return $this->sourceV4($directory, $owner)->capture(new AgeProcess(AgeBinary::path()), $this->settings['recipients'], $state['snapshotId'], $this->v4Scope(), function (array $progress) use ($state, $maintenance, $owner, $mode, &$marker, &$lastRefresh, &$progressSnapshot): void {
                    if ($mode === 'online') { $this->recordOnlineProgress($progressSnapshot, $progress, $state['id'], $lastRefresh); }
                    else { $this->recordCaptureProgress($progressSnapshot,$progress,$state['id'],$maintenance,$owner,$marker,$lastRefresh); }
                }, $index, $mode);
            };
            $capture = $index === null ? $operation() : $index->withLock($operation);
            $job = ['version' => 4, 'state' => $state, 'manifest' => $capture['manifest'], 'objects' => $capture['objects'], 'position' => 0,
                'settingsDigest' => $this->settingsDigest()];
            $job['sharingDigest'] = $this->sharingDigest();
            if ($index !== null) { $job['indexIdentity'] = $index->identity(); }
            if (isset($capture['sharing'])) { $job['state']['sharing'] = $capture['sharing']; }
            // Measurement only: fixed integer fields, no paths, names or content, saved with the state that is written anyway.
            if (is_array($capture['measurements'] ?? null)) { $job['state']['measurements'] = ['version' => 1, 'capture' => self::measurementValues($capture['measurements'])]; }
            $this->validateV4Job($job, $directory);
            $job['state']['status'] = 'uploading';
            $job['state']['total'] = count($capture['objects']) + 1;
            $job['state']['message'] = '暗号化済みデータをR2へ転送できます。';
            // WordPress stops respecting .maintenance after ten minutes. Never publish a capture if it lapsed, even if
            // capture returned without another progress callback, or the job write itself took too long.
            $this->assertBoundSettingsUnchanged($state);
            if ($mode === 'maintenance') { $this->assertFreshMaintenance($maintenance, $owner, $marker); }
            $this->saveJob($directory, $job);
            $this->assertBoundSettingsUnchanged($state);
            if ($mode === 'maintenance') { $this->assertFreshMaintenance($maintenance, $owner, $marker); }
            $this->setState($job['state']);
            // The operation lock is still held: if saving the state stalled past expiry, fail before transfer can begin.
            $this->assertBoundSettingsUnchanged($state);
            if ($mode === 'maintenance') { $this->assertFreshMaintenance($maintenance, $owner, $marker); }
            // If state persistence succeeded but a later check failed (or the worker died), step() must still refuse the job.
            // Write this only after the complete capture and all freshness checks; legacy jobs have no captureGuard field.
            $this->writeCaptureGuard($directory, $job['state']);
            $this->history->record($job['state'], 'upload_started');
            return $job['state'];
        } catch (\Throwable $error) {
            if (isset($state)) {
                $state['status'] = 'failed'; $state['message'] = $error instanceof UnsupportedOnlineDatabaseEngine
                    ? '保守なしでは、対象データベースにInnoDB以外のテーブルがあるため実行できません。'
                    : '取得を完了できませんでした。安全な完了世代は公開していません。';
                $this->setState($state);
                $this->history->record($state, BackupHistory::captureError($error));
            }
            throw $error;
        } finally {
            if ($owned) {
                clearstatcache(true, $maintenance);
                $now = @lstat($maintenance);
                if ($now !== false && $now['dev'] === $maintenanceStat['dev'] && $now['ino'] === $maintenanceStat['ino'] && file_get_contents($maintenance) === $marker) { unlink($maintenance); }
            }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function step(): array
    {
        $lock = $this->lock();
        try {
            $state = $this->state();
            if ($state === null || $state['status'] !== 'uploading' || !preg_match('/\A[a-f0-9]{64}\z/D', $state['id'])) { throw new \RuntimeException('転送を再開する操作がありません。'); }
            $directory = FileSafety::privateDirectory($this->workspace . '/job-' . $state['id']);
            $job = $this->readJob($directory);
            $this->assertCaptureGuard($directory, $state, $job['state'] ?? []);
            if (array_key_exists('consistencyMode', $state)) { $this->assertBoundSettingsUnchanged($state); }
            if (!hash_equals($job['settingsDigest'], $this->settingsDigest())) { throw new \RuntimeException('取得後に設定が変わりました。同じ設定に戻してから再開してください。'); }
            if (function_exists('set_time_limit')) { @set_time_limit(0); }
            if ($this->historyUploadRun !== $state['id']) {
                $this->history->record($state, 'resumed');
                $this->historyUploadRun = $state['id'];
            }
            if (($job['version'] ?? null) === 4) {
                if (isset($job['sharingDigest']) && !hash_equals($job['sharingDigest'], $this->sharingDigest())) { throw new \RuntimeException('取得後に重複保存の設定が変わりました。同じ設定に戻してから再開してください。'); }
                $index = isset($job['indexIdentity']) ? $this->contentIndex($directory) : null;
                if (isset($job['indexIdentity']) && ($index === null || $index->identity() !== $job['indexIdentity'])) { throw new \RuntimeException('取得時の重複索引が失われたか変更されています。索引を復旧してから再開してください。'); }
                $operation = function () use ($directory, $job, $state, $index): array { return $this->stepV4($directory, $job, $state, $index); };
                return $index === null ? $operation() : $index->withLock($operation);
            }
            $archives = isset($job['capture']['archives']);
            $files = $archives ? $job['capture']['archives'] : array_values(array_filter($job['capture']['files'], static fn (array $entry): bool => $entry['type'] === 'file'));
            if ($job['position'] < count($files)) {
                $entry = $files[$job['position']];
                $reference = $archives ? 'archive-' . $job['position'] : $entry['path'];
                $key = 'files/' . Manifest::keySetId($this->settings['recipients']) . '/' . hash('sha256', $state['snapshotId'] . ':' . $reference) . '.age';
                $receipt = $this->repository->putNew($key, $this->cipher($directory, $entry['cipherFile']));
                $this->repository->verify($receipt);
                $job['receipts'][$reference] = $receipt;
                $job['position']++;
            } elseif ($job['position'] === count($files)) {
                $receipt = $this->repository->putNew('databases/' . $state['snapshotId'] . '.age', $this->cipher($directory, $job['capture']['database']['cipherFile']));
                $this->repository->verify($receipt);
                $job['databaseReceipt'] = $receipt;
                $job['position']++;
            } else {
                $this->history->record($state, 'final_verification');
                $data = $this->manifest($job);
                $cipher = $directory . '/manifest.age';
                if (!is_file($cipher)) {
                    (new AgeProcess(AgeBinary::path()))->encryptStream([json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], $cipher, $this->settings['recipients']);
                }
                $receipt = $this->repository->putNew('manifests/' . $state['snapshotId'] . '.age', $cipher);
                $this->repository->verify($receipt);
                $objects = array_values($job['receipts']);
                $objects[] = $job['databaseReceipt'];
                $this->repository->publishWithObjects($state['snapshotId'], $receipt, $objects);
                $this->repository->completed($state['snapshotId']);
                $job['position']++;
                $job['state']['status'] = 'complete';
                $job['state']['completedAt'] = gmdate('Y-m-d\TH:i:s\Z');
                $job['state']['message'] = 'R2への転送と保存物の照合が完了しました。独立復旧キットで復号を確認してください。';
            }
            $job['state']['completed'] = $job['position'];
            $this->saveJob($directory, $job);
            $this->setState($job['state']);
            if ($job['state']['status'] === 'complete') {
                $this->history->record($job['state'], 'complete', array_sum(array_column($objects, 'bytes')) + $receipt['bytes']);
                $this->clearCiphertexts($directory);
                try { $this->repository->retention($this->settings['retentionDays']); }
                catch (\Throwable $error) {
                    $job['state']['message'] .= ' 保存期限の処理を完了できませんでした。保存済み世代は削除せず保持しています。';
                    $this->saveJob($directory, $job); $this->setState($job['state']);
                    $this->history->record($job['state'], 'retention_failed');
                }
            }
            return $job['state'];
        } catch (\Throwable $error) {
            $this->historyUploadRun = null;
            if (($state['status'] ?? null) === 'uploading') {
                $this->recordTransferFailure($state, $error);
            }
            throw $error;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function recordTransferFailure(array $state, \Throwable $error): void
    {
        $code = BackupHistory::transferError($error);
        // A completed job or a newer checkpoint must never be rolled back by the failure handler.
        $current = isset($state['scheduleRevision']) ? $this->operationState() : null;
        if (($current['status'] ?? null) === 'uploading' && $current['id'] === $state['id']) {
            $before = $current;
            $current['transferStopped'] = true;
            unset($current['autoResumeAt']);
            $notBefore = $error instanceof R2HttpException ? $error->retryNotBefore() : null;
            if (empty($current['manualResume']) && (($error instanceof R2TransportException && $error->isTransient()) || $notBefore !== null)) {
                $attempts = $current['autoResumeAttempts'] ?? null;
                if (is_int($attempts) && isset(self::SCHEDULED_RETRY_DELAYS[$attempts])) {
                    $current['autoResumeAt'] = max($notBefore ?? 0, time() + self::SCHEDULED_RETRY_DELAYS[$attempts]);
                    $code = 'auto_resume_waiting';
                } elseif ($attempts === count(self::SCHEDULED_RETRY_DELAYS)) { $code = 'auto_resume_exhausted'; }
            }
            if ($current !== $before) { $this->setState($current); }
        }
        $this->history->record($state, $code, null, $error instanceof R2HttpException ? $error->getCode() : null);
    }

    private function v4Scope(): array
    {
        $storage = $this->repository->storageScope();
        foreach (['accountId', 'bucket', 'unitId'] as $field) {
            if ($storage[$field] !== $this->settings[$field]) { throw new \RuntimeException('実際のR2接続先とバックアップの保存範囲が一致しません。'); }
        }
        return $storage + ['keySetId' => Manifest::keySetId($this->settings['recipients']), 'encoding' => V4Format::ENCODING];
    }

    private function validateV4Job(array $job, string $directory): void
    {
        if (!isset($job['manifest'], $job['objects'], $job['state'], $job['position']) || !is_array($job['manifest'])
            || !is_array($job['objects']) || !array_is_list($job['objects']) || count($job['objects']) > V4Format::MAX_OBJECTS
            || !is_array($job['state']) || !is_int($job['position']) || $job['position'] < 0 || $job['position'] > count($job['objects']) + 1) {
            throw new \RuntimeException('v4バックアップ状態が不正です。');
        }
        $root = $job['manifest']; V4Format::validateRoot($root);
        $stateMode = $job['state']['consistencyMode'] ?? 'maintenance';
        $rootMode = $root['wordpress']['consistencyMode'] ?? 'maintenance';
        if (!in_array($stateMode, ['maintenance', 'online'], true) || $stateMode !== $rootMode) { throw new \RuntimeException('v4バックアップの取得方式が一致しません。'); }
        if ($root['scope'] !== $this->v4Scope() || $root['snapshotId'] !== ($job['state']['snapshotId'] ?? null)
            || $root['recipients'] !== $this->settings['recipients'] || count($job['objects']) !== $root['counts']['packs'] + count($root['pages'])) {
            throw new \RuntimeException('v4バックアップの保存先または世代が一致しません。');
        }
        $pages = []; foreach ($root['pages'] as $page) { $pages[$page['object']['key']] = $page['object']; }
        $seen = []; $packs = 0; $scope = V4Format::scopeId($root['scope']);
        if (isset($job['indexIdentity'])) {
            $identity = $job['indexIdentity'];
            if (!is_array($identity) || array_keys($identity) !== ['scopeId', 'indexId'] || $identity['scopeId'] !== $scope
                || !is_string($identity['indexId']) || !preg_match('/^[a-f0-9]{64}$/D', $identity['indexId'])) { throw new \RuntimeException('重複索引の識別情報が不正です。'); }
        }
        foreach ($job['objects'] as $entry) {
            if (!is_array($entry) || !isset($entry['object']) || !array_key_exists('cipherFile', $entry) || !is_array($entry['object']) || ($entry['cipherFile'] !== null && !is_string($entry['cipherFile']))) { throw new \RuntimeException('v4暗号文の記録が不正です。'); }
            $receipt = $entry['object']; V4Format::validateReceipt($receipt); $key = $receipt['key'];
            if (isset($seen[$key])) { throw new \RuntimeException('v4暗号文が重複しています。'); }
            $seen[$key] = true;
            if (str_starts_with($key, 'packs/' . $scope . '/')) { ++$packs; }
            elseif (isset($pages[$key]) && $pages[$key] === $receipt) { unset($pages[$key]); }
            else { throw new \RuntimeException('v4暗号文の保存範囲が一致しません。'); }
            if ($entry['cipherFile'] === null) {
                if (!isset($job['indexIdentity']) || !str_starts_with($key, 'packs/' . $scope . '/')) { throw new \RuntimeException('共有パックの索引情報がありません。'); }
            } else { $this->cipher($directory, $entry['cipherFile']); }
        }
        if ($pages !== [] || $packs !== $root['counts']['packs']) { throw new \RuntimeException('v4暗号文の一覧が一致しません。'); }
    }

    private function stepV4(string $directory, array $job, array $state, ?V4ContentIndex $index = null): array
    {
        $this->validateV4Job($job, $directory);
        if (($job['state']['id'] ?? null) !== $state['id'] || ($job['state']['snapshotId'] ?? null) !== $state['snapshotId']) {
            throw new \RuntimeException('v4バックアップの実行状態が一致しません。');
        }
        // Progress checkpoints replace the operation option. Carry its retry budget across successful steps.
        if (isset($state['scheduleRevision'])) {
            $job['state']['autoResumeAttempts'] = $state['autoResumeAttempts'];
            if (!empty($state['manualResume'])) { $job['state']['manualResume'] = true; }
        }
        if (($job['state']['status'] ?? '') === 'complete' && $job['position'] === count($job['objects']) + 1) {
            $this->history->record($state, 'final_verification');
            $completed = $this->repository->completed($state['snapshotId']);
            if (($completed['manifest'] ?? null) !== ($job['rootReceipt'] ?? null) || ($completed['objects'] ?? null) !== array_column($job['objects'], 'object')) {
                throw new \RuntimeException('保存済みv4完了記録を照合できません。');
            }
            $this->setState($job['state']);
            $this->history->record($job['state'], 'complete', $completed['manifest']['bytes'] + array_sum(array_column($completed['objects'], 'bytes')));
            return $this->finishV4Job($directory, $job, $index);
        }
        if ($job['position'] > count($job['objects'])) { throw new \RuntimeException('v4バックアップの転送位置が不正です。'); }
        if ($job['position'] < count($job['objects'])) {
            $stepStarted = hrtime(true); $firstPosition = $job['position'];
            $entry = $job['objects'][$job['position']]; $expected = $entry['object'];
            $kind = $entry['cipherFile'] === null ? 'sharedPacks' : (str_starts_with($expected['key'], 'pages/') ? 'pages' : (str_starts_with($expected['key'], 'packs/') ? 'newPacks' : 'otherObjects'));
            if ($entry['cipherFile'] === null) {
                // A capture proven complete by its guard read every shared pack in full under its lock, and publication
                // reads every object in full before and after the completion record. For such a job the whole run of
                // consecutive shared packs is checked against the locked index only: each must still be ready, with
                // exactly the receipt the capture recorded. A legacy job without the guard cannot prove its capture, so
                // its shared packs are still read in full, LEGACY_REUSED_PACK_BATCH at a time. Either way the position
                // moves past the checked packs only after every one has passed; any failure leaves it unchanged.
                if ($index === null) { throw new \RuntimeException('共有パックを照合できません。'); }
                $proven = $this->captureProven($directory, $state, $job['state']);
                $batch = [];
                for ($next = $job['position']; $next < count($job['objects']) && ($proven || count($batch) < self::LEGACY_REUSED_PACK_BATCH) && $job['objects'][$next]['cipherFile'] === null; ++$next) {
                    $batch[] = $job['objects'][$next]['object'];
                }
                $keys = array_column($batch, 'key');
                $descriptors = $proven ? $index->readyDescriptors($keys) : $index->verifyReadyPacks($keys);
                foreach ($batch as $offset => $object) {
                    if (($descriptors[$offset]['object'] ?? null) !== $object) { throw new \RuntimeException('共有パックを照合できません。'); }
                }
                $job['position'] += count($batch) - 1; // The increment after this branch counts the last one.
            } elseif (str_starts_with($expected['key'], 'pages/')) {
                // Each PUT is conditional, and the batch API verifies every object before returning. Keep the second
                // whole-object verification and advance the persisted position only after every receipt matches.
                $job['position'] += $this->uploadPageBatch($directory, $job['objects'], $job['position']) - 1;
            } else {
                $cipher = $this->cipher($directory, $entry['cipherFile']);
                if (filesize($cipher) !== $expected['bytes'] || !hash_equals($expected['sha256'], hash_file('sha256', $cipher))) { throw new \RuntimeException('v4暗号文の内容が一致しません。'); }
                $receipt = $this->repository->putNew($expected['key'], $cipher);
                if ($receipt !== $expected) { throw new \RuntimeException('v4暗号文の照合情報が一致しません。'); }
                if ($index !== null && str_starts_with($expected['key'], 'packs/')) { $index->markReady($receipt); }
                else { $this->repository->verify($receipt); }
            }
            ++$job['position'];
            $bytes = 0; for ($done = $firstPosition; $done < $job['position']; ++$done) { $bytes += $job['objects'][$done]['object']['bytes']; }
            $job['state'] = self::withTransferMeasurement($job['state'], $kind, ['ms' => self::elapsedMs($stepStarted), 'steps' => 1, 'objects' => $job['position'] - $firstPosition, 'bytes' => $bytes]);
        } else {
            $this->history->record($state, 'final_verification');
            $rootStarted = hrtime(true);
            $cipher = $directory . '/manifest.age';
            if (!is_file($cipher)) { (new AgeProcess(AgeBinary::path()))->encryptStream([V4Format::json($job['manifest'])], $cipher, $this->settings['recipients']); }
            $cipher = $this->cipher($directory, $cipher);
            $expected = ['key' => 'manifests/' . $state['snapshotId'] . '.age', 'bytes' => filesize($cipher), 'sha256' => hash_file('sha256', $cipher)];
            V4Format::validateReceipt($expected);
            if (isset($job['rootReceipt']) && $job['rootReceipt'] !== $expected) { throw new \RuntimeException('v4ルートの照合情報が一致しません。'); }
            $receipt = $this->repository->putNew($expected['key'], $cipher);
            if ($receipt !== $expected) { throw new \RuntimeException('v4ルートの照合情報が一致しません。'); }
            $this->repository->verify($receipt);
            $job['rootReceipt'] = $receipt; $this->saveJob($directory, $job);
            $objects = array_column($job['objects'], 'object');
            $publishStarted = hrtime(true);
            $this->repository->publishWithObjects($state['snapshotId'], $receipt, $objects);
            $checkStarted = hrtime(true);
            $completed = $this->repository->completed($state['snapshotId']);
            if (($completed['manifest'] ?? null) !== $receipt || ($completed['objects'] ?? null) !== $objects) { throw new \RuntimeException('v4完了記録が一致しません。'); }
            $job['state'] = self::withTransferMeasurement($job['state'], 'publication', ['rootMs' => intdiv(max(0, $publishStarted - $rootStarted), 1000000),
                'publishMs' => intdiv(max(0, $checkStarted - $publishStarted), 1000000), 'completedCheckMs' => self::elapsedMs($checkStarted),
                'objects' => count($objects) + 1, 'bytes' => array_sum(array_column($objects, 'bytes')) + $receipt['bytes']]);
            ++$job['position']; $job['state']['status'] = 'complete';
            $job['state']['completedAt'] = gmdate('Y-m-d\TH:i:s\Z');
            $job['state']['message'] = 'R2への転送と保存物の照合が完了しました。独立復旧キットで復号を確認してください。';
        }
        $job['state']['completed'] = $job['position'];
        $this->saveJob($directory, $job); $this->setState($job['state']);
        if ($job['state']['status'] === 'complete') {
            $this->history->record($job['state'], 'complete', $job['state']['measurements']['transfer']['publication']['bytes']);
            return $this->finishV4Job($directory, $job, $index);
        }
        return $job['state'];
    }

    private function uploadPageBatch(string $directory, array $objects, int $position): int
    {
        $pages = []; $expectedReceipts = [];
        for ($next = $position; $next < count($objects) && count($pages) < self::NEW_PAGE_BATCH; ++$next) {
            $page = $objects[$next];
            if ($page['cipherFile'] === null || !str_starts_with($page['object']['key'], 'pages/')) { break; }
            $expected = $page['object']; $cipher = $this->cipher($directory, $page['cipherFile']);
            $pages[] = ['object' => $expected, 'cipherFile' => $cipher]; $expectedReceipts[] = $expected;
        }
        if ($pages === []) { throw new \RuntimeException('v4ページの転送対象がありません。'); }
        $receipts = $this->repository->putNewPages($pages);
        if ($receipts !== $expectedReceipts) { throw new \RuntimeException('v4暗号文の照合情報が一致しません。'); }
        $this->repository->verifyMany($receipts);
        return count($receipts);
    }

    private function finishV4Job(string $directory, array $job, ?V4ContentIndex $index = null): array
    {
        try {
            $deleted = $this->repository->retention($this->settings['retentionDays']);
            if ($index !== null) { foreach ($deleted as $result) { $index->markMissing($result['deleted']); } }
        }
        catch (\Throwable $error) {
            $job['state']['message'] .= ' 保存期限の処理を完了できませんでした。保存済み世代は削除せず保持しています。';
            $this->setState($job['state']);
            $this->history->record($job['state'], 'retention_failed');
        }
        $this->removePrivateDirectory($directory);
        return $job['state'];
    }

    private function removePrivateDirectory(string $directory, ?string $keepUntilEnd = null): void
    {
        FileSafety::privateDirectory($directory);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->getPathname() === $keepUntilEnd) { continue; }
            if ($file->isLink()) { throw new \RuntimeException('私有ディレクトリにリンクがあります。'); }
            if ($file->isDir()) { if (!rmdir($file->getPathname())) { throw new \RuntimeException('私有ディレクトリを削除できません。'); } }
            elseif (!$file->isFile() || !unlink($file->getPathname())) { throw new \RuntimeException('私有ファイルを削除できません。'); }
        }
        if ($keepUntilEnd !== null && !unlink($keepUntilEnd)) { throw new \RuntimeException('保管期限の記録を削除できません。'); }
        if (!rmdir($directory)) { throw new \RuntimeException('私有ディレクトリを削除できません。'); }
    }

    public function delete(string $snapshotId): array
    {
        $lock = $this->lock();
        try {
            $state = $this->state();
            if ($state !== null && in_array($state['status'], ['queued', 'capturing', 'uploading'], true)) { throw new \RuntimeException('未完了のバックアップがある間は世代を削除できません。'); }
            $index = $this->contentIndex($this->workspace);
            $operation = function () use ($snapshotId, $index): array {
                $result = $this->repository->deleteSnapshot($snapshotId);
                if ($index !== null) { $index->markMissing($result['deleted']); }
                return $result;
            };
            return $index === null ? $operation() : $index->withLock($operation);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function cancel(): void
    {
        $lock = $this->lock();
        try {
            $state = $this->state();
            if ($state === null || !in_array($state['status'], ['queued', 'capturing', 'uploading', 'failed'], true)) { throw new \RuntimeException('取り消す未完了の実行がありません。'); }
            $maintenance = rtrim(ABSPATH, '/') . '/.maintenance';
            $stat = @lstat($maintenance);
            $owner = $state['maintenanceOwner'] ?? null;
            if ($stat !== false) {
                if (!is_array($owner) || !$this->ownsMaintenance($maintenance, $owner)) {
                    throw new \RuntimeException('他の処理の保守状態があるため解除できません。');
                }
                unlink($maintenance);
            }
            if (preg_match('/\A[a-f0-9]{64}\z/D', $state['id'])) {
                $directory = $this->workspace . '/job-' . $state['id'];
                if (is_dir($directory)) {
                    FileSafety::privateDirectory($directory);
                    $job = is_file($directory . '/job.json') ? $this->readJob($directory) : null;
                    if (($job['version'] ?? null) === 4 || $job === null) { $this->removePrivateDirectory($directory); }
                    else { $this->clearCiphertexts($directory); }
                }
            }
            $state['status'] = 'cancelled';
            $state['message'] = '未完了の実行を取り消しました。未完了の暗号文は完了世代として公開していません。';
            $this->setState($state);
            $this->history->record($state, 'cancelled');
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function manifest(array $job): array
    {
        $files = [];
        foreach ($job['capture']['files'] as $entry) {
            if ($entry['type'] === 'directory') { $files[] = ['path' => $entry['path'], 'type' => 'directory']; }
            else { $files[] = ['path' => $entry['path'], 'type' => 'file', 'size' => $entry['size'], 'sha256' => $entry['sha256'], 'object' => $job['receipts'][$entry['path']]]; }
        }
        $archives = [];
        foreach ($job['capture']['archives'] ?? [] as $index => $entry) { $archives[] = ['object' => $job['receipts']['archive-' . $index], 'size' => $entry['size'], 'sha256' => $entry['sha256'], 'fileCount' => $entry['fileCount'], 'directoryCount' => $entry['directoryCount']]; }
        $database = $job['capture']['database'];
        return Manifest::fromArray(['format' => 'wordpress-r2-backup', 'version' => 3, 'dataClass' => 'wordpress',
            'unitId' => $this->settings['unitId'], 'snapshotId' => $job['state']['snapshotId'], 'keySetId' => Manifest::keySetId($this->settings['recipients']),
            'recipients' => $this->settings['recipients'], 'capturedAt' => $job['state']['startedAt'], 'files' => $files,
            'database' => ['size' => $database['size'], 'sha256' => $database['sha256'], 'object' => $job['databaseReceipt']],
            'wordpress' => ['siteUrl' => home_url('/'), 'policySha256' => hash('sha256', json_encode($this->settings['policy'], JSON_THROW_ON_ERROR)), 'evidence' => $job['capture']['evidence']] + (isset($job['capture']['archives']) ? ['archives' => $archives] : [])])->toArray();
    }

    private function ownsMaintenance(string $path, array $owner): bool
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['dev'] !== ($owner['dev'] ?? null) || $stat['ino'] !== ($owner['ino'] ?? null) || $stat['size'] > 512) { return false; }
        $body = (string) file_get_contents($path);
        if (isset($owner['token'])) { return preg_match('~\A<\?php \$upgrading = [0-9]+; /\* wrb-([a-f0-9]{32}) \*/\z~D', $body, $match) === 1 && hash_equals($owner['token'], $match[1]); }
        return isset($owner['sha256']) && hash_equals($owner['sha256'], hash('sha256', $body));
    }

    private function refreshMaintenance(string $path, array $owner, string &$marker): void
    {
        $stream = @fopen($path, 'r+b');
        if ($stream === false) { throw new \RuntimeException('保守状態を更新できません。'); }
        try {
            $previous = $this->checkMaintenanceStream($stream, $path, $owner, $marker);
            $next = '<?php $upgrading = ' . time() . '; /* wrb-' . $owner['token'] . ' */';
            rewind($stream);
            if (fwrite($stream, $next) !== strlen($next) || !ftruncate($stream, strlen($next)) || !fflush($stream)) { throw new \RuntimeException('保守状態を更新できません。'); }
            $marker = $next;
            if (time() - $previous >= 600) { throw new \RuntimeException('保守状態の期限が切れました。取得を中止します。'); }
        } finally { fclose($stream); }
    }

    private function assertFreshMaintenance(string $path, array $owner, string $marker): void
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) { throw new \RuntimeException('保守状態の所有者を検証できません。'); }
        try { $this->checkMaintenanceStream($stream, $path, $owner, $marker); }
        finally { fclose($stream); }
    }

    /** Validate both the open file and current path, including the exact marker and WordPress's ten-minute expiry. */
    private function checkMaintenanceStream($stream, string $path, array $owner, string $marker): int
    {
        $stat = fstat($stream); clearstatcache(true, $path); $current = @lstat($path);
        if ($stat === false || $current === false || ($stat['mode'] & 0170000) !== 0100000 || ($current['mode'] & 0170000) !== 0100000
            || $stat['dev'] !== ($owner['dev'] ?? null) || $stat['ino'] !== ($owner['ino'] ?? null)
            || $current['dev'] !== $stat['dev'] || $current['ino'] !== $stat['ino'] || $stat['size'] > 512) {
            throw new \RuntimeException('保守状態の所有者を検証できません。');
        }
        $body = stream_get_contents($stream, 513);
        if (!is_string($body) || !hash_equals($marker, $body) || !is_string($owner['token'] ?? null)
            || preg_match('~\A<\?php \$upgrading = ([0-9]{1,12}); /\* wrb-([a-f0-9]{32}) \*/\z~D', $body, $match) !== 1
            || !hash_equals($owner['token'], $match[2])) { throw new \RuntimeException('保守状態の所有者を検証できません。'); }
        $timestamp = (int) $match[1]; $now = time();
        if ($timestamp > $now || $now - $timestamp >= 600) { throw new \RuntimeException('保守状態の期限が切れました。取得を中止します。'); }
        return $timestamp;
    }

    private function writeProgress(array $progress): void
    {
        $temp = $this->workspace . '/.progress-' . bin2hex(random_bytes(8));
        $body = json_encode($progress, JSON_THROW_ON_ERROR);
        if (file_put_contents($temp, $body, LOCK_EX) !== strlen($body) || !chmod($temp, 0600) || !rename($temp, $this->workspace . '/progress.json')) { @unlink($temp); throw new \RuntimeException('進捗を記録できません。'); }
    }

    private function recordCaptureProgress(array &$current, array $update, string $id, string $maintenance, array $owner, string &$marker, int &$lastRefresh): void
    {
        $current=$this->mergeProgress($current,$update);
        if (time()-$lastRefresh<15) { return; }
        $this->refreshMaintenance($maintenance,$owner,$marker);
        $this->writeProgress($current+['id'=>$id,'updatedAt'=>gmdate('Y-m-d\TH:i:s\Z')]);
        $lastRefresh=time();
    }

    /** Snapshot callbacks must never issue writes on the capture's database connection. */
    private function recordOnlineProgress(array &$current, array $update, string $id, int &$lastRefresh): void
    {
        $current = $this->mergeProgress($current, $update);
        if (time() - $lastRefresh < 15) { return; }
        $this->writeProgress($current + ['id' => $id, 'updatedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
        $lastRefresh = time();
    }

    /** Merge counters even when disk output is throttled; heartbeat-only events do not change the active phase. */
    private function mergeProgress(array $current, array $update): array
    {
        $phase=$update['phase']??null; $heartbeat=($update['heartbeat']??false)===true;
        if (!$heartbeat && is_string($phase) && in_array($phase,['prepare','scan','sharing-preflight','capture'],true)) {
            if (($current['phase']??null)!==$phase) { $current=['phase'=>$phase]; }
        } elseif (!isset($current['phase']) && is_string($phase) && in_array($phase,['prepare','scan','sharing-preflight','capture'],true)) { $current['phase']=$phase; }
        foreach (['entries','selected','tableCount','files','directories','links','databaseBytes'] as $key) {
            if (array_key_exists($key,$update) && is_int($update[$key]) && $update[$key]>=0) { $current[$key]=$update[$key]; }
        }
        // V4Capture's existing callback uses fileBytes alone for one file, and with files for the cumulative total.
        if (array_key_exists('fileBytes',$update) && is_int($update['fileBytes']) && $update['fileBytes']>=0) {
            if (array_key_exists('files',$update)) { $current['fileBytes']=$update['fileBytes']; $current['currentFileBytes']=0; }
            else { $current['currentFileBytes']=$update['fileBytes']; }
        }
        return $current;
    }

    /** A guard is local to one capture and makes a partially persisted uploading state ineligible for transfer. */
    private function captureGuardBody(array $state): string
    {
        foreach (['id' => 64, 'snapshotId' => 64, 'captureGuard' => 32] as $key => $length) {
            if (!is_string($state[$key] ?? null) || !preg_match('/\A[a-f0-9]{' . $length . '}\z/D', $state[$key])) {
                throw new \RuntimeException('取得の完了記録を検証できません。');
            }
        }
        $mode = $state['consistencyMode'] ?? 'maintenance';
        if (!in_array($mode, ['maintenance', 'online'], true)) { throw new \RuntimeException('取得方式を検証できません。'); }
        $bound = array_key_exists('consistencyMode', $state);
        $prefix = $bound ? "wordpress-r2-backup capture guard v2 " . $mode . "\n" : "wordpress-r2-backup capture guard v1\n";
        $body = $prefix . $state['id'] . "\n" . $state['snapshotId'] . "\n" . $state['captureGuard'] . "\n";
        if ($bound) {
            foreach (['settingsDigest', 'sharingDigest'] as $field) {
                if (!is_string($state[$field] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $state[$field])) { throw new \RuntimeException('取得設定を検証できません。'); }
                $body .= $state[$field] . "\n";
            }
        }
        return $body;
    }

    private function writeCaptureGuard(string $directory, array $state): void
    {
        $path = $directory . '/capture-ready'; $temp = $directory . '/.capture-ready-' . bin2hex(random_bytes(8)); $body = $this->captureGuardBody($state);
        FileSafety::assertNoLinks($path);
        if (@lstat($path) !== false) { throw new \RuntimeException('取得の完了記録が既に存在します。'); }
        $stream = @fopen($temp, 'xb');
        if ($stream === false) { throw new \RuntimeException('取得の完了記録を保存できません。'); }
        try {
            if (!chmod($temp, 0600) || fwrite($stream, $body) !== strlen($body) || !FileSafety::sync($stream)) {
                throw new \RuntimeException('取得の完了記録を保存できません。');
            }
        } finally { fclose($stream); }
        if (!rename($temp, $path)) { throw new \RuntimeException('取得の完了記録を確定できません。'); }
    }

    private static function elapsedMs(int $started): int { return intdiv(max(0, hrtime(true) - $started), 1000000); }

    /** Keeps only non-negative integer fields with plain names; measurement data never carries anything else. */
    private static function measurementValues($values): array
    {
        $result = [];
        if (!is_array($values)) { return $result; }
        foreach ($values as $name => $value) {
            if (is_string($name) && preg_match('/\A[A-Za-z]{1,40}\z/D', $name) && is_int($value) && $value >= 0) { $result[$name] = $value; }
        }
        return $result;
    }

    /** Adds one transfer step to the state's measurements. A missing or malformed record (an older job) starts again from zero. */
    private static function withTransferMeasurement(array $state, string $kind, array $add): array
    {
        $measurements = is_array($state['measurements'] ?? null) ? $state['measurements'] : ['version' => 1];
        $transfer = is_array($measurements['transfer'] ?? null) ? $measurements['transfer'] : [];
        $entry = self::measurementValues($transfer[$kind] ?? []);
        foreach ($add as $name => $value) { $entry[$name] = ($entry[$name] ?? 0) + $value; }
        $transfer[$kind] = $entry; $measurements['transfer'] = $transfer; $state['measurements'] = $measurements;
        return $state;
    }

    /**
     * True only when the state, the job and the capture-ready file all carry the same guard, which is written after the
     * complete capture and its checks. A legacy job with no guard on either side is false; any other mismatch fails.
     */
    private function captureProven(string $directory, array $state, array $jobState): bool
    {
        if (!array_key_exists('captureGuard', $state) && !array_key_exists('captureGuard', $jobState)) { return false; }
        $this->assertCaptureGuard($directory, $state, $jobState);
        return true;
    }

    private function assertCaptureGuard(string $directory, array $state, array $jobState): void
    {
        $stateHas = array_key_exists('captureGuard', $state); $jobHas = array_key_exists('captureGuard', $jobState);
        $mode = $state['consistencyMode'] ?? 'maintenance'; $jobMode = $jobState['consistencyMode'] ?? 'maintenance';
        if (!in_array($mode, ['maintenance', 'online'], true) || !in_array($jobMode, ['maintenance', 'online'], true) || $mode !== $jobMode) { throw new \RuntimeException('取得方式が一致しません。'); }
        if (!$stateHas && !$jobHas && $mode === 'maintenance' && !array_key_exists('consistencyMode', $state) && !array_key_exists('consistencyMode', $jobState)) { return; } // Existing in-flight maintenance jobs predate guards.
        if (!$stateHas || !$jobHas || $this->captureGuardBody($state) !== $this->captureGuardBody($jobState)) {
            throw new \RuntimeException('取得の完了記録が一致しません。');
        }
        $path = $directory . '/capture-ready'; FileSafety::assertRegularFile($path);
        $stat = lstat($path);
        if ($stat === false || ($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1 || $stat['size'] > 512
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
            || !hash_equals($this->captureGuardBody($state), (string) file_get_contents($path))) {
            throw new \RuntimeException('取得の完了記録を検証できません。');
        }
    }

    private function settingsDigest(): string
    {
        return hash('sha256', json_encode([$this->settings['connectionDigest'], $this->settings['unitId'], $this->settings['recipients'], $this->settings['policy'] ?? null], JSON_THROW_ON_ERROR));
    }

    private function fullSettingsDigest(array $settings): string
    {
        return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
    }

    /** Compare both the worker's frozen settings and the authoritative currently stored settings. */
    private function assertBoundSettingsUnchanged(array $state): void
    {
        if (!in_array($state['consistencyMode'] ?? null, ['maintenance', 'online'], true)) { throw new \RuntimeException('取得方式を検証できません。'); }
        $digest = $state['settingsDigest'] ?? null; $sharing = $state['sharingDigest'] ?? null;
        if (!is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/D', $digest) || !is_string($sharing) || !preg_match('/\A[a-f0-9]{64}\z/D', $sharing)
            || !hash_equals($digest, $this->fullSettingsDigest($this->settings)) || !hash_equals($sharing, $this->sharingDigest())) {
            throw new \RuntimeException('取得開始後に設定が変わりました。');
        }
        if (function_exists('wp_cache_delete')) { wp_cache_delete(LiveSettings::OPTION, 'options'); wp_cache_delete('notoptions', 'options'); }
        $current = LiveSettings::load();
        if (!is_array($current) || !hash_equals($digest, $this->fullSettingsDigest($current))) { throw new \RuntimeException('取得開始後に設定が変わりました。'); }
    }

    private function cipher(string $directory, string $path): string
    {
        if (realpath($path) !== $path || !str_starts_with($path, $directory . '/')) { throw new \RuntimeException('暗号文の保存場所を検証できません。'); }
        FileSafety::assertRegularFile($path);
        return $path;
    }

    private function lock()
    {
        return self::lockWorkspace($this->workspace);
    }

    /** Settings updates share the capture/transfer lock so a stale admin request cannot race publication. */
    public static function withSettingsLock(callable $operation)
    {
        $lock = self::lockWorkspace(LiveSettings::workspace());
        try { return $operation(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function lockWorkspace(string $workspace, bool $wait = false)
    {
        $path = $workspace . '/operation.lock';
        FileSafety::assertNoLinks($path);
        $lock = fopen($path, 'c+b');
        if (!$lock || !chmod($path, 0600) || !flock($lock, LOCK_EX | ($wait ? 0 : LOCK_NB))) { if (is_resource($lock)) { fclose($lock); } throw new \RuntimeException('別のバックアップ処理が実行中です。'); }
        return $lock;
    }

    private function saveJob(string $directory, array $job, string $name = 'job.json'): void
    {
        $path = $directory . '/' . $name; $temp = $directory . '/.state-' . bin2hex(random_bytes(8));
        $body = json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($body) > 33554432) { throw new \RuntimeException('バックアップ状態が上限を超えています。'); }
        $stream = fopen($temp, 'xb');
        if ($stream === false) { throw new \RuntimeException('バックアップ状態を記録できません。'); }
        try { if (!chmod($temp, 0600) || fwrite($stream, $body) !== strlen($body) || !FileSafety::sync($stream)) { throw new \RuntimeException('バックアップ状態を記録できません。'); } }
        finally { fclose($stream); }
        FileSafety::assertNoLinks($path);
        if (!rename($temp, $path)) { throw new \RuntimeException('バックアップ状態を確定できません。'); }
    }

    private function readJob(string $directory): array
    {
        $path = $directory . '/job.json'; FileSafety::assertRegularFile($path);
        if ((fileperms($path) & 0777) !== 0600 || filesize($path) > 33554432) { throw new \RuntimeException('バックアップ状態を検証できません。'); }
        return json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }

    private function clearCiphertexts(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink() && str_ends_with($file->getFilename(), '.age')) { unlink($file->getPathname()); }
        }
    }
}
