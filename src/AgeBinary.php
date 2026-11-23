<?php
declare(strict_types=1);
namespace WordPressR2Backup;

final class AgeBinary
{
    public static function path(): string
    {
        $machine = strtolower(php_uname('m'));
        $architecture = ['arm64'=>'arm64','aarch64'=>'arm64','amd64'=>'amd64','x86_64'=>'amd64'][$machine] ?? '';
        $platform = strtolower(PHP_OS_FAMILY) . '-' . $architecture;
        $hashes = ['linux-amd64' => 'eb7dd1b518f0a307c99cd97782623c5321da049154b04acd2d98d21aa7bc9b2c',
            'darwin-arm64' => '4012dfc2725883beafb710894af4f599b7a94f8c8e0f51f02cc96ab8df33915e'];
        if (!isset($hashes[$platform]) || !is_callable('proc_open')) {
            throw new \RuntimeException('このサーバーで暗号化ツールを実行できません。対応する公式age実行ファイルとproc_openを確認してください。');
        }
        $relative = 'bin/' . $platform . '/age';
        $file = dirname(__DIR__) . '/' . $relative;
        FileSafety::assertRegularFile($file);
        $stream = @fopen($file, 'rb');
        if ($stream === false) { throw new \RuntimeException('同梱ageを読み取れません。プラグインを再インストールしてください。'); }
        try {
            $stat = fstat($stream);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 07022) !== 0) {
                throw new \RuntimeException('同梱ageの種類または権限が不正です。通常ファイルにし、特殊権限と所有者以外の書き込み権限を外してください。');
            }
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $stream) !== $stat['size'] || !hash_equals($hashes[$platform], hash_final($hash))) {
                throw new \RuntimeException('同梱ageのSHA-256が一致しません。公式の配布ZIPから再インストールしてください。');
            }
            self::assertSameFile($file, $stat);
            if (!is_executable($file)) {
                // WordPress ZIP extraction can replace 0755 with 0644. chmod the verified open inode, never a
                // path that could be replaced with a symlink during an update. PHP has no native fchmod().
                $checked = fstat($stream);
                if ($checked === false || $checked['nlink'] !== 1) {
                    throw new \RuntimeException('同梱ageが別のファイルにリンクされています。公式の配布ZIPから再インストールしてください。');
                }
                $allowed = ($checked['mode'] & 0100) === 0;
                $mode = sprintf('%04o', ($checked['mode'] & 0777) | 0100);
                $process = $allowed ? @proc_open(['/bin/chmod', $mode, '/dev/fd/3'], [
                    0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'],
                    3 => $stream,
                ], $pipes) : false;
                $changed = is_resource($process) && proc_close($process) === 0;
                self::assertSameFile($file, $stat);
                if (!$changed || !is_executable($file)) {
                    throw new \RuntimeException('同梱ageを実行できません。サーバー管理者は ' . $relative . ' のSHA-256が '
                        . $hashes[$platform] . ' と一致することを確認し、このファイルにPHP実行ユーザーの実行権限を付与してください。');
                }
            }
            return $file;
        } finally { fclose($stream); }
    }

    private static function assertSameFile(string $file, array $expected): void
    {
        FileSafety::assertNoLinks($file);
        $current = @lstat($file);
        if ($current === false || $current['dev'] !== $expected['dev'] || $current['ino'] !== $expected['ino']
            || ($current['mode'] & 07022) !== 0 || $current['size'] !== $expected['size'] || $current['mtime'] !== $expected['mtime']) {
            throw new \RuntimeException('検証中に同梱ageが変わりました。プラグインの更新完了後に再実行してください。');
        }
    }
}
