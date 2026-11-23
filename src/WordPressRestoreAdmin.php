<?php
declare(strict_types=1);

namespace WordPressR2Backup;

/** The restore screen's WordPress entry: capability, nonce and a password re-check tied to this user and login session. */
final class WordPressRestoreAdmin
{
    private const REAUTH_ATTEMPTS = 5;

    public static function register(string $pluginFile): void
    {
        add_action('wp_ajax_wrb_restore', [self::class, 'ajax']);
        add_action('admin_enqueue_scripts', static function (string $hook) use ($pluginFile): void {
            if (!in_array($hook, ['tools_page_' . WordPressAdmin::PAGE, 'toplevel_page_' . WordPressAdmin::PAGE], true) || defined('WRB_SYNTHETIC_RUNTIME_PROFILE') || LiveAdmin::view() !== 'restore') { return; }
            wp_enqueue_style('wrb-restore', plugins_url('assets/restore.css', $pluginFile), [], '0.7.0');
            wp_enqueue_script('wrb-restore', plugins_url('assets/restore.js', $pluginFile), [], '0.7.0', true);
        });
    }

    public static function render(?array $settings): void
    {
        self::authorize();
        global $wpdb;
        echo RestorePage::render(['endpoint' => admin_url('admin-ajax.php'), 'tokenName' => '_wrb_restore_nonce', 'token' => wp_create_nonce('wrb_restore'), 'extra' => ['action' => 'wrb_restore'], 'reauth' => true,
            'secureTransport' => self::secureTransport(), 'prefix' => is_object($wpdb) && isset($wpdb->prefix) && is_string($wpdb->prefix) ? $wpdb->prefix : 'wp_']);
    }

    public static function ajax(): void
    {
        self::authorize();
        check_ajax_referer('wrb_restore', '_wrb_restore_nonce');
        nocache_headers(); header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
        $post = wp_unslash($_POST);
        $command = is_array($post) && is_string($post['command'] ?? null) ? $post['command'] : '';
        try {
            if (!self::secureTransport()) {
                $secrets = array_intersect_key((array) $post, RestorePlan::SECRET_FIELDS + ['reauthPassword' => 4096]);
                foreach ($secrets as $field => $value) {
                    if ($value !== '') { throw new RestoreError('秘密情報を送るにはHTTPSが必要です。', $field); }
                }
            }
            if ($command === 'start') { self::reauthenticate((string) ($post['reauthPassword'] ?? '')); }
            $settings = LiveSettings::load();
            [$code, $body] = RestoreApi::handle(new RestoreService(self::environment($settings)), $command, is_array($post) ? $post : [], self::actor());
        } catch (RestoreError $error) {
            [$code, $body] = [400, ['error' => $error->getMessage(), 'field' => $error->field()]];
        } catch (\Throwable $error) {
            [$code, $body] = [500, ['error' => '処理を完了できませんでした。設定を確認してください。']];
        }
        if ($code === 200) { wp_send_json_success($body); } else { wp_send_json_error($body, $code); }
    }

    /** Ties a plan to one user and one login session; another session of the same user cannot use it. */
    public static function actor(): string
    {
        return 'wp:' . hash('sha256', get_current_user_id() . '|' . wp_get_session_token());
    }

    private static function reauthenticate(string $password): void
    {
        $user = wp_get_current_user();
        $key = 'wrb_restore_reauth_' . (int) $user->ID;
        $failures = (int) get_transient($key);
        if ($failures >= self::REAUTH_ATTEMPTS) { throw new RestoreError('パスワードの失敗が続いたため、しばらく開始できません。', 'reauthPassword'); }
        if ($password === '' || strlen($password) > 4096 || !wp_check_password($password, $user->user_pass, $user->ID)) {
            set_transient($key, $failures + 1, 900);
            throw new RestoreError('WordPressのパスワードを確認できません。', 'reauthPassword');
        }
        delete_transient($key);
    }

    /** Ciphertexts already fetched can be named by the site owner in wp-config.php; a browser cannot choose a storage location. */
    private static function source(?array $settings): array
    {
        if (defined('WRB_RESTORE_LOCAL_REPOSITORY')) { return ['type' => 'repository', 'path' => (string) constant('WRB_RESTORE_LOCAL_REPOSITORY'), 'unit' => defined('WRB_RESTORE_LOCAL_UNIT') ? (string) constant('WRB_RESTORE_LOCAL_UNIT') : '']; }
        if ($settings === null) { return ['type' => 'unconfigured']; }
        return ['type' => 'direct-r2', 'profile' => array_intersect_key((array) $settings, array_flip(['accountId', 'bucket', 'accessKeyId', 'secretAccessKey', 'unitId']))];
    }

    private static function environment(?array $settings): array
    {
        $base = defined('WRB_RESTORE_STATE_DIR') ? (string) constant('WRB_RESTORE_STATE_DIR') : LiveSettings::automaticRestoreDirectory();
        $protected = [rtrim((string) ABSPATH, '/'), realpath(sys_get_temp_dir()) ?: ''];
        if (!empty($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])) { $protected[] = $_SERVER['DOCUMENT_ROOT']; }
        return ['base' => $base, 'age' => AgeBinary::path(), 'source' => self::source($settings),
            'connections' => RestoreService::loadConnections(defined('WRB_RESTORE_MYSQL_CONNECTIONS') ? (string) constant('WRB_RESTORE_MYSQL_CONNECTIONS') : null),
            'protectedPaths' => array_values(array_filter($protected)), 'activeDatabase' => defined('DB_NAME') ? (string) constant('DB_NAME') : null, 'viewerLocal' => self::viewerLocal($_SERVER)];
    }

    /** The peer address and the requested host only. Forwarding headers can be set by anyone and are not read. */
    public static function viewerLocal(array $server): bool
    {
        $address = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';
        $host = is_string($server['HTTP_HOST'] ?? null) ? strtolower(preg_replace('/:[0-9]+\z/D', '', $server['HTTP_HOST'])) : '';
        return in_array($address, ['127.0.0.1', '::1'], true) && (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || preg_match('/\.(?:localhost|local|test)\z/D', $host) === 1);
    }

    private static function secureTransport(): bool
    {
        return is_ssl() || self::viewerLocal($_SERVER);
    }

    private static function authorize(): void
    {
        if (!current_user_can('manage_options') || (function_exists('is_multisite') && is_multisite() && !is_super_admin())) { wp_die('この操作を行う権限がありません。', '', ['response' => 403]); }
    }
}
