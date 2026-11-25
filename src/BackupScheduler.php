<?php
declare(strict_types=1);
namespace WordPressR2Backup;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Schedule calculation only. Starting or claiming work requires a separate exclusive lock. */
final class BackupScheduler
{
    /** Structural gate only; successful validation does not itself authorize or start a capture. */
    public static function assertOnlineRelease(string $release): void
    {
        $root = realpath($release);
        if ($root === false || defined('WRB_SYNTHETIC_RUNTIME_PROFILE') || !function_exists('has_action')
            || has_action('wp_ajax_wrb_live', [LiveAdmin::class, 'ajax']) === false) {
            throw new \RuntimeException('有効なバックアッププラグインを確認できません。');
        }
        $main = realpath($root . '/wordpress-r2-backup.php');
        $included = array_map('realpath', get_included_files());
        if ($main === false || !in_array($main, $included, true)) { throw new \RuntimeException('有効なリリースを確認できません。'); }
        foreach ([self::class => 'BackupScheduler.php', LiveRunner::class => 'LiveRunner.php', LiveBackup::class => 'LiveBackup.php', LiveAdmin::class => 'LiveAdmin.php'] as $class => $file) {
            $expected = realpath($root . '/src/' . $file); $actual = (new \ReflectionClass($class))->getFileName();
            if ($expected === false || !is_string($actual) || realpath($actual) !== $expected) { throw new \RuntimeException('読み込まれたリリースが一致しません。'); }
        }
        if (!method_exists(LiveRunner::class, 'startOnline')) { throw new \RuntimeException('保守なし取得は未対応です。'); }
        $method = new \ReflectionMethod(LiveRunner::class, 'startOnline');
        if (!$method->isPublic() || $method->getNumberOfRequiredParameters() !== 0) { throw new \RuntimeException('保守なし取得の入口が不正です。'); }
    }

    /** Save only schedule settings; credentials and backup state are not modified. */
    public static function save(array $input): array
    {
        if (!isset($input['enabled']) || !is_bool($input['enabled']) || !is_string($input['frequency'] ?? null)
            || !is_string($input['time'] ?? null) || !array_key_exists('weekday', $input)
            || (!is_int($input['weekday']) && $input['weekday'] !== null)
            || !is_string($input['mode'] ?? null) || !in_array($input['mode'], ['maintenance', 'online'], true)) {
            throw new InvalidArgumentException('定期実行の設定が不正です。');
        }
        BackupSchedule::next($input['frequency'], $input['time'], $input['weekday'], wp_timezone(), new DateTimeImmutable('now', new DateTimeZone('UTC')));
        if (array_key_exists('rules', $input)) { $input['rules'] = ScheduleRules::validate($input['rules']); }
        $workspace = LiveSettings::workspace();
        return self::withScheduleLock($workspace, static function () use ($workspace, $input): array {
            return self::withLock($workspace, 'operation.lock', static function () use ($input): array {
                wp_cache_delete('wrb_live_operation', 'options'); wp_cache_delete('wrb_live_config', 'options'); wp_cache_delete('notoptions', 'options');
                $state = get_option('wrb_live_operation', null);
                if ($state !== null && (!is_array($state) || !in_array($state['status'] ?? '', ['complete', 'cancelled'], true))) {
                    throw new \RuntimeException('未完了のバックアップがあるため設定を変更できません。');
                }
                if (LiveSettings::load() === null) { throw new \RuntimeException('先にストレージ設定を保存してください。'); }
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $new = ['enabled' => $input['enabled'], 'frequency' => $input['frequency'], 'time' => $input['time'],
                    'weekday' => $input['weekday'], 'mode' => $input['mode'], 'revision' => bin2hex(random_bytes(32)),
                    'effectiveSince' => $now->format('Y-m-d\TH:i:s\Z')];
                wp_cache_delete('wrb_live_schedule', 'options');
                $current = get_option('wrb_live_schedule', []);
                if (isset($input['rules'])) { $new['rules'] = $input['rules']; }
                elseif (is_array($current) && array_key_exists('rules', $current)) { $new['rules'] = ScheduleRules::validate($current['rules']); }
                self::due($new, null, wp_timezone(), $now);
                update_option('wrb_live_schedule', $new, false);
                wp_cache_delete('wrb_live_schedule', 'options'); wp_cache_delete('notoptions', 'options');
                if (get_option('wrb_live_schedule', null) !== $new) { throw new \RuntimeException('定期実行の設定を保存できません。'); }
                return $new;
            });
        });
    }

    /** Shared by the admin save and CLI; acquire this before operation.lock. */
    public static function withScheduleLock(string $workspace, callable $operation)
    {
        return self::withLock($workspace, 'schedule.lock', $operation);
    }

    private static function withLock(string $workspace, string $name, callable $operation)
    {
        FileSafety::privateDirectory($workspace);
        $path = $workspace . '/' . $name; FileSafety::assertNoLinks($path);
        // A detached worker may outlive this scheduler, including after SIGKILL.
        $lock = @fopen($path, 'c+be');
        if ($lock === false || !chmod($path, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            throw new \RuntimeException('別のバックアップ処理が実行中です。');
        }
        try { return $operation(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** The cron should run each minute; jobs more than two minutes late are not replayed. */
    public static function due(array $config, ?string $lastAttempt, DateTimeZone $zone, DateTimeImmutable $now): ?string
    {
        if (!isset($config['enabled']) || !is_bool($config['enabled']) || !isset($config['mode'])
            || !in_array($config['mode'], ['maintenance', 'online'], true)
            || !is_string($config['revision'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $config['revision'])
            || !is_string($config['effectiveSince'] ?? null) || !is_string($config['frequency'] ?? null)
            || !is_string($config['time'] ?? null) || !array_key_exists('weekday', $config)
            || (!is_int($config['weekday']) && $config['weekday'] !== null)) {
            throw new InvalidArgumentException('定期実行の設定が不正です。');
        }
        $effective = self::timestamp($config['effectiveSince']);
        $last = $lastAttempt === null ? null : self::timestamp($lastAttempt);
        if (array_key_exists('rules', $config)) {
            ScheduleRules::validate($config['rules']);
            if (!$config['enabled']) { return null; }
            $lower = max($effective, $last ?? $effective, $now->modify('-121 seconds'));
            $slot = ScheduleRules::next($config, $zone, $lower, $now);
            return $slot === null ? null : $slot->format('Y-m-d\TH:i:s\Z');
        }
        // Validate the recurrence even if currently disabled.
        $slot = BackupSchedule::next($config['frequency'], $config['time'], $config['weekday'], $zone, $now->modify('-121 seconds'));
        if (!$config['enabled'] || $slot > $now || $slot <= $effective || ($last !== null && $slot <= $last)) { return null; }
        return $slot->format('Y-m-d\TH:i:s\Z');
    }

    /** Most recent unobserved occurrence outside the launch window; never authorizes a launch. */
    public static function latestMissed(array $config, array $state, DateTimeZone $zone, DateTimeImmutable $now): ?string
    {
        // Validate the complete config through the same path used by the launch decision.
        self::due($config, null, $zone, $now);
        if (!$config['enabled']) { return null; }
        $lower = self::timestamp($config['effectiveSince']);
        foreach (['lastAttempt', 'lastMissedSlot'] as $key) {
            if (array_key_exists($key, $state)) {
                if (!is_string($state[$key])) { throw new InvalidArgumentException('定期実行の状態が不正です。'); }
                $boundary = self::timestamp($state[$key]); if ($boundary > $lower) { $lower = $boundary; }
            }
        }
        if (($state['observedRevision'] ?? null) === $config['revision'] && array_key_exists('lastSeen', $state)) {
            if (!is_string($state['lastSeen'])) { throw new InvalidArgumentException('定期実行の状態が不正です。'); }
            $seen = self::timestamp($state['lastSeen']); if ($seen > $lower) { $lower = $seen; }
        }
        $cutoff = $now->modify('-121 seconds');
        if ($lower >= $cutoff) { return null; }
        if (array_key_exists('rules', $config)) {
            // Search newest days first, bounding work for minute-level rules.
            for ($day = $cutoff, $i = 0; $day > $lower && $i < 370; ++$i, $day = $start) {
                $start = max($lower, $day->modify('-1 day'));
                $latest = null;
                while (($next = ScheduleRules::next($config, $zone, $latest ?? $start, $day)) !== null) { $latest = $next; }
                if ($latest !== null) { return $latest->format('Y-m-d\TH:i:s\Z'); }
            }
            return null;
        }
        // An hourly schedule has a recent occurrence within three days even across DST changes.
        $start = $cutoff->modify($config['frequency'] === 'hourly' ? '-3 days' : '-370 days'); if ($lower > $start) { $start = $lower; }
        $latest = null;
        for ($i = 0; $i < 371; ++$i) {
            $next = BackupSchedule::next($config['frequency'], $config['time'], $config['weekday'], $zone, $start);
            if ($next > $cutoff) { break; }
            $latest = $next; $start = $next;
        }
        return $latest === null ? null : $latest->format('Y-m-d\TH:i:s\Z');
    }

    private static function timestamp(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($time === false || $time->format('Y-m-d\TH:i:s\Z') !== $value) { throw new InvalidArgumentException('定期実行の日時が不正です。'); }
        return $time;
    }
}
