<?php
declare(strict_types=1);

namespace WordPressR2Backup;

/**
 * Whether a verified original fits the one-URL running copy this screen makes. A shape it cannot represent stops the startup
 * stage with its reason, rather than being silently flattened. The restored wp-config.php is read as text and never executed.
 */
final class RestoredSiteCheck
{
    private const LAYOUT_CONSTANTS = ['WP_CONTENT_URL', 'WP_CONTENT_DIR', 'WP_PLUGIN_URL', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_URL', 'WPMU_PLUGIN_DIR', 'UPLOADS', 'COOKIE_DOMAIN', 'COOKIEPATH', 'SITECOOKIEPATH'];

    /**
     * @param list<string> $tables table names of the original database
     * @param array{home:?string,siteurl:?string} $options
     * @return list<string> constants of the old configuration a running copy does not carry over
     */
    public static function assertSupported(array $tables, array $options, string $tablePrefix, string $restoredRoot): array
    {
        return self::inspect($tables, $options, $tablePrefix, $restoredRoot)['layout'];
    }

    public static function inspect(array $tables, array $options, string $tablePrefix, string $restoredRoot): array
    {
        [$config, $configurationIncomplete, $configurationPresent] = self::configuration($restoredRoot);
        if (array_intersect([$tablePrefix . 'blogs', $tablePrefix . 'site'], $tables) !== [] || preg_match('/define\s*\(\s*[\'"](?:MULTISITE|WP_ALLOW_MULTISITE)[\'"]\s*,\s*(?:true|1)\s*\)/i', $config) === 1) {
            throw new RestoreError('マルチサイト構成には対応していないため、起動準備を停止しました。原本の復元と照合は完了し、原本は保持しています。');
        }
        $defined = static function (string $name) use ($config): ?string { return preg_match('/define\s*\(\s*[\'"]' . $name . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/', $config, $match) === 1 ? rtrim($match[1], '/') : null; };
        $database = [];
        $configuration = [];
        foreach (['home' => 'WP_HOME', 'siteurl' => 'WP_SITEURL'] as $key => $constant) {
            $database[$key] = self::validAddress($options[$key] ?? null);
            $configuration[$key] = [
                'mentioned' => preg_match('/\b' . $constant . '\b/', $config) === 1,
                'literal' => self::validAddress($defined($constant)),
            ];
        }
        $home = $configuration['home']['literal'] ?? $database['home'];
        $siteUrl = $configuration['siteurl']['literal'] ?? $database['siteurl'];
        if ($home !== null && $siteUrl !== null && $home !== $siteUrl) {
            throw new RestoreError('公開URL（home）とWordPress本体のURL（siteurl）が異なる構成には対応していないため、起動準備を停止しました。1つのURLへ統合すると動作が変わるためです。原本の復元と照合は完了し、原本は保持しています。');
        }
        $custom = [];
        foreach (self::LAYOUT_CONSTANTS as $name) { if (preg_match('/define\s*\(\s*[\'"]' . $name . '[\'"]\s*,/', $config) === 1) { $custom[] = $name; } }
        return ['layout' => $custom, 'database' => $database, 'configuration' => $configuration, 'configurationPresent' => $configurationPresent,
            'configurationIncomplete' => $configurationIncomplete || self::hasCustomIncludes($config)];
    }

    private static function validAddress(?string $url): ?string
    {
        if ($url === null) { return null; }
        try { SiteUrlRewriter::assertUrl($url, false); return rtrim($url, '/'); }
        catch (\RuntimeException $ignored) { return null; }
    }

    private static function hasCustomIncludes(string $config): bool
    {
        // Do not follow includes or execute the archived PHP. Allow only WordPress's standard bootstrap include.
        $tokens = array_values(array_filter(token_get_all($config), static function ($token): bool {
            return !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
        }));
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) { continue; }
            $expression = '';
            foreach (array_slice($tokens, $index + 1, 12) as $part) {
                if ($part === ';') { break; }
                $expression .= is_array($part) ? $part[1] : $part;
            }
            if (preg_match('~\A(?:ABSPATH\.[\'\"]wp-settings\.php[\'\"]|\(ABSPATH\.[\'\"]wp-settings\.php[\'\"]\))\z~D', $expression) !== 1) { return true; }
        }
        return false;
    }

    private static function configuration(string $root): array
    {
        foreach ([$root . '/wp-config.php', dirname($root) . '/wp-config.php'] as $path) {
            if (!@is_file($path) && !is_link($path)) { continue; }
            if (is_link($path)) { return ['', true, true]; }
            $bytes = @file_get_contents($path, false, null, 0, 1048577);
            if (!is_string($bytes) || strlen($bytes) > 1048576) { return ['', true, true]; }
            $source = '';
            foreach (token_get_all($bytes) as $token) {
                $source .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1]) : $token;
            }
            return [$source, false, true];
        }
        return ['', false, false];
    }
}
