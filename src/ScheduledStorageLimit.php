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
        return new self('retained_limit', '未完了の定期バックアップが保管上限の2世代に達したため、新規取得を見送りました。実行履歴で中断原因を確認してください。転送可能な現在の世代は、原因を解消して「バックアップ設定」の「暗号文の転送を再開」を実行し、最終照合が完了すると枠が空きます。');
    }

    public static function lowSpace(): self
    {
        return new self('low_space', 'バックアップ作業領域の空き容量を確保できないため、定期取得を停止しました。');
    }

    public static function storageWait(?int $deadline = null): self
    {
        if ($deadline === null || $deadline > 253402300799) {
            return new self('storage_wait', 'R2保存先の待機期限を確認できないため、通信を見送りました。');
        }
        return new self('storage_wait', 'R2保存先への再試行可能時刻は ' . gmdate('Y-m-d H:i:s', $deadline) . ' UTC です。期限まで通信を見送ります。');
    }

    public function outcome(): string { return $this->outcome; }
}
