<?php
declare(strict_types=1);
namespace WordPressR2Backup;

/** Admission failures contain only a fixed public reason, never filesystem paths. */
final class ScheduledStorageLimit extends \RuntimeException
{
    private string $outcome;

    private function __construct(string $outcome, string $message)
    {
        parent::__construct($message);
        $this->outcome = $outcome;
    }

    public static function countLimit(): self
    {
        return new self('retained_limit', '未完了の定期バックアップが保管上限の2世代に達したため、新規取得を見送りました。');
    }

    public static function lowSpace(): self
    {
        return new self('low_space', 'バックアップ作業領域の空き容量を確保できないため、定期取得を停止しました。');
    }

    public function outcome(): string { return $this->outcome; }
}
