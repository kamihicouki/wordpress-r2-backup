<?php
declare(strict_types=1);

namespace WordPressR2Backup;

/** Validates the restore form into one canonical request. Paths are never accepted from a browser; the service derives them from the name. */
final class RestorePlan
{
    public const TEXT_FIELDS = ['snapshot' => 64, 'name' => 40, 'purpose' => 16, 'database' => 8, 'connection' => 32, 'mysqlDatabase' => 64, 'urlMode' => 8, 'targetUrl' => 2048, 'targetSiteUrl' => 2048, 'aliases' => 16384, 'previewPort' => 5, 'tablePrefix' => 64, 'createPreviewAdmin' => 1, 'adminLogin' => 60, 'root' => 5];
    public const SECRET_FIELDS = ['identity' => 65536, 'r2Connection' => 8192, 'adminPassword' => 4096, 'inspectorUser' => 80, 'inspectorPassword' => 512, 'importerUser' => 80, 'importerPassword' => 512, 'runnerUser' => 80, 'runnerPassword' => 512];

    /** @param array<string,array> $connections registered connection sets by id */
    public static function normalize(array $input, array $connections): array
    {
        $text = static function (string $field) use ($input): string { $value = $input[$field] ?? ''; return is_string($value) ? trim($value) : ''; };
        $snapshot = $text('snapshot');
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $snapshot)) { throw new RestoreError('復元するバックアップを選んでください。', 'snapshot'); }
        $name = $text('name');
        if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?\z/D', $name)) { throw new RestoreError('復元の名前は英小文字・数字・ハイフンで、40文字以内にしてください。', 'name'); }
        $purpose = $text('purpose');
        if (!in_array($purpose, ['startup-check', 'full'], true)) { throw new RestoreError('メディアの扱いを選んでください。', 'purpose'); }
        $database = $text('database');
        if (!in_array($database, ['sqlite', 'mysql'], true)) { throw new RestoreError('データベースの種類を選んでください。', 'database'); }
        $plan = ['snapshot' => $snapshot, 'name' => $name, 'purpose' => $purpose, 'database' => $database];
        if ($database === 'mysql') {
            $id = $text('connection');
            if (!isset($connections[$id])) { throw new RestoreError('登録済みの接続先を選んでください。', 'connection'); }
            $schema = $connections[$id]['database'] ?? $text('mysqlDatabase');
            if (!preg_match('/\Awrb_test_[a-z0-9_]{1,51}\z/D', $schema)) { throw new RestoreError('データベース名は wrb_test_ で始まる英小文字・数字・下線にしてください。', 'mysqlDatabase'); }
            $plan['mysql'] = ['connection' => $id, 'database' => $schema, 'runningDatabase' => $schema . '_run'];
        }
        $port = $text('previewPort');
        if (!preg_match('/\A[1-9][0-9]{3,4}\z/D', $port) || (int) $port < 1024 || (int) $port > 65535) { throw new RestoreError('確認用のポート番号は1024〜65535で指定してください。', 'previewPort'); }
        $plan['previewUrl'] = 'http://127.0.0.1:' . $port;
        $mode = $text('urlMode');
        if ($mode === '') { $mode = $text('targetUrl') === '' ? 'keep' : 'database'; }
        if (!in_array($mode, ['keep', 'database', 'config'], true)) { throw new RestoreError('運用URLの扱いを選んでください。', 'urlMode'); }
        $plan['urlMode'] = $mode;
        // targetUrl is the legacy worker's permission to rewrite a DB. Never populate it for config guidance.
        $plan['targetUrl'] = $plan['configHome'] = $plan['targetSiteUrl'] = null;
        if ($mode !== 'keep') {
            $home = self::targetUrl($text('targetUrl'), 'targetUrl');
            $site = $text('targetSiteUrl') === '' ? $home : self::targetUrl($text('targetSiteUrl'), 'targetSiteUrl');
            if (self::origin($home) !== self::origin($site)) { throw new RestoreError('2つのURLの方式（HTTP／HTTPS）、ホスト名、ポートをそろえてください。', 'targetSiteUrl'); }
            $homePath = (string) parse_url($home, PHP_URL_PATH); $sitePath = (string) parse_url($site, PHP_URL_PATH);
            if ($sitePath !== $homePath) {
                if ($mode === 'database') { throw new RestoreError('データベースへの反映ではWordPress本体の配置を変えられません。別の場所に配置する場合は「wp-config.php に記入する値を確認」を選んでください。', 'targetSiteUrl'); }
                if (strpos($sitePath, $homePath . '/') !== 0) { throw new RestoreError('WordPress アドレス (URL) はサイトアドレス (URL) と同じか、その配下にしてください。', 'targetSiteUrl'); }
            }
            $plan[$mode === 'database' ? 'targetUrl' : 'configHome'] = $home;
            $plan['targetSiteUrl'] = $site;
        }
        $aliases = [];
        foreach ($mode === 'database' ? (preg_split('/\R/', $text('aliases')) ?: []) : [] as $alias) {
            $alias = rtrim(trim($alias), '/');
            if ($alias === '') { continue; }
            try { SiteUrlRewriter::assertUrl($alias, false); } catch (\RuntimeException $ignored) { throw new RestoreError('別のアドレスは1行に1つ、https://example.com の形で入力してください。', 'aliases'); }
            $aliases[] = $alias;
        }
        if (count($aliases) > 8) { throw new RestoreError('別のアドレスは8個までです。', 'aliases'); }
        $plan['aliases'] = $aliases;
        $prefix = $text('tablePrefix') === '' ? 'wp_' : $text('tablePrefix');
        if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $prefix)) { throw new RestoreError('表の接頭辞は英数字と下線で指定してください。', 'tablePrefix'); }
        $plan['tablePrefix'] = $prefix;
        $createAdmin = $text('createPreviewAdmin');
        if (!in_array($createAdmin, ['', '0', '1'], true)) { throw new RestoreError('確認用管理者を作成するか確認してください。', 'createPreviewAdmin'); }
        $login = $createAdmin === '1' ? $text('adminLogin') : null;
        if ($login !== null && !preg_match('/\A[A-Za-z0-9_.-]{1,60}\z/D', $login)) { throw new RestoreError('確認用ログインの名前は英数字と . _ - で指定してください。', 'adminLogin'); }
        $plan['adminLogin'] = $login;
        $root = $text('root');
        if ($root !== '' && !preg_match('/\Ar[0-9]{4}\z/D', $root)) { throw new RestoreError('WordPressの保存ルートは r0001 の形式で指定してください。', 'root'); }
        $plan['root'] = $root === '' ? null : $root;
        return $plan;
    }

    public static function urlMode(array $plan): string
    {
        return $plan['urlMode'] ?? (($plan['targetUrl'] ?? null) === null ? 'keep' : 'database');
    }

    public static function publicationUrls(array $plan): array
    {
        $home = $plan['targetUrl'] ?? $plan['configHome'] ?? null;
        return ['home' => $home, 'siteurl' => $plan['targetSiteUrl'] ?? $home];
    }

    private static function targetUrl(string $value, string $field): string
    {
        $url = rtrim($value, '/');
        try { SiteUrlRewriter::assertUrl($url, false); }
        catch (\RuntimeException $ignored) { throw new RestoreError('アドレスは https://example.com の形で入力してください。', $field); }
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        $host = strtolower(rtrim($parts['host'], '.'));
        $ip = @inet_pton(trim($host, '[]'));
        $mappedPrefix = str_repeat("\0", 10) . "\xff\xff";
        if (preg_match('/(?:^|\.)localhost\z/D', $host) || $ip === str_repeat("\0", 15) . "\1" || $ip === str_repeat("\0", 16)
            || (is_string($ip) && strlen($ip) === 4 && (ord($ip[0]) === 127 || $ip === str_repeat("\0", 4)))
            || (is_string($ip) && strncmp($ip, $mappedPrefix, 12) === 0 && ord($ip[12]) === 127)
            || ($ip === false && preg_match('/(?:^|\.)(?:[0-9]+|0x[0-9a-f]+)\z/D', $host))) {
            throw new RestoreError('運用URLには確認用のローカルアドレスではなく、移行先のアドレスを指定してください。', $field);
        }
        if (strlen($url) > 2048 || strpbrk($path, '%\\') !== false || strpos($path, '//') !== false || preg_match('~(?:^|/)\.{1,2}(?:/|$)~D', $path)) {
            throw new RestoreError('アドレスのパスに、%で始まる表記、連続したスラッシュ、または . や .. を含めないでください。', $field);
        }
        return self::origin($url) . $path;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? null;
        return $parts['scheme'] . '://' . strtolower(rtrim($parts['host'], '.')) . ($port === null || $port === ($parts['scheme'] === 'https' ? 443 : 80) ? '' : ':' . $port);
    }

    /** The bound value for a canonical request and the facts checked for it. */
    public static function hash(array $plan, array $facts): string
    {
        $canonical = static function ($value) use (&$canonical) {
            if (!is_array($value)) { return $value; }
            if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) { ksort($value, SORT_STRING); }
            return array_map($canonical, $value);
        };
        return hash('sha256', json_encode($canonical(['plan' => $plan, 'facts' => $facts]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** WordPress portable hash for the running copy's administrator; the restored site's own code is never loaded to compute it. */
    public static function portableHash(string $password): string
    {
        $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $encode = static function (string $bytes) use ($alphabet): string {
            $output = ''; $length = strlen($bytes);
            for ($i = 0; $i < $length;) {
                $value = ord($bytes[$i++]); $output .= $alphabet[$value & 63];
                if ($i < $length) { $value |= ord($bytes[$i]) << 8; } $output .= $alphabet[($value >> 6) & 63];
                if ($i++ >= $length) { break; }
                if ($i < $length) { $value |= ord($bytes[$i]) << 16; } $output .= $alphabet[($value >> 12) & 63];
                if ($i++ >= $length) { break; }
                $output .= $alphabet[($value >> 18) & 63];
            }
            return $output;
        };
        $salt = substr($encode(random_bytes(6)), 0, 8);
        $hash = md5($salt . $password, true);
        for ($round = 0; $round < (1 << 13); ++$round) { $hash = md5($hash . $password, true); }
        return '$P$' . $alphabet[13] . $salt . $encode($hash);
    }
}
