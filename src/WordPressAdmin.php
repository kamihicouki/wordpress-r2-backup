<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use InvalidArgumentException;
use Throwable;

final class WordPressAdmin
{
    public const PAGE = 'wrb-synthetic-backup';

    private const FIELDS = [
        'register_keys' => ['recipient_1' => 128, 'recipient_2' => 128],
        'issue_challenge' => [],
        'download_challenge' => ['slot' => 1],
        'confirm_challenge' => ['slot' => 1, 'challenge_id' => 128, 'code' => 128],
        'start' => ['request_id' => 128],
        'resume' => ['operation_id' => 128],
    ];
    private const MESSAGES = [
        'registered' => '公開鍵2名分を登録しました。両方の鍵で復号確認を行ってください。',
        'issued' => '2名分の確認用暗号文を発行しました。両名の確認が解除されました。',
        'confirmed' => 'この公開鍵の復号能力を確認しました。',
        'completed' => '合成取得の処理を終えました。下の操作状態と世代一覧を確認してください。',
        'invalid_keys' => '異なる有効なage公開鍵を2本入力してください。',
        'invalid_request' => '入力を受け付けられませんでした。画面を開き直して操作してください。',
        'nonce_invalid' => '操作確認の期限が切れたか、一致しません。画面を開き直してください。',
        'keys_required' => '先に公開鍵2名分を登録してください。',
        'challenge_invalid' => '確認情報が一致しません。対象の暗号文と入力内容を確認してください。',
        'challenge_expired' => '確認用暗号文の期限が切れました。再発行してください。',
        'challenge_locked' => '確認の失敗上限に達しました。暗号文を再発行してください。',
        'challenge_used' => 'この確認用暗号文は使用済みです。',
        'keys_unconfirmed' => '公開鍵2名分の復号確認を完了してください。',
        'operation_active' => '未完了の操作があります。同じ操作を再開してください。',
        'operation_missing' => '再開する操作を確認できません。',
        'busy' => '別の操作が処理中です。しばらく待ってから画面を開き直してください。',
        'state_invalid' => '保存状態を検証できないため停止しました。設定担当者へ確認してください。',
        'capture_failed' => '合成取得を完了できませんでした。この画面の実行権は30秒で更新されます。中断直後は30秒以上待ってから同じ操作を再開してください。',
        'listing_failed' => '保存先の世代一覧を取得できませんでした。',
        'runtime_invalid' => '合成設定を検証できないため実行を停止しました。設定担当者へ確認してください。',
    ];

    public static function version(): string
    {
        return get_file_data(dirname(__DIR__) . '/wordpress-r2-backup.php', ['version' => 'Version'])['version'];
    }

    public static function register(string $pluginFile): void
    {
        LiveAdmin::register($pluginFile);
        WordPressRestoreAdmin::register($pluginFile);
        $admin = new self();
        add_action('admin_menu', static function () use ($admin): void {
            $title = defined('WRB_SYNTHETIC_RUNTIME_PROFILE') ? '合成バックアップ' : 'R2バックアップ';
            add_management_page($title, $title, 'manage_options', self::PAGE, [$admin, 'render']);
            if (!defined('WRB_SYNTHETIC_RUNTIME_PROFILE')) {
                add_menu_page('R2 Backup', 'R2 Backup', 'manage_options', self::PAGE, [$admin, 'render'], 'dashicons-database', 58);
                foreach (['backup' => 'バックアップ設定', 'history' => '実行履歴', 'restore' => '復元', 'storage' => 'ストレージ設定', 'privacy' => '保存範囲と除外設定', 'settings' => '保存期間と容量'] as $view => $label) {
                    $slug = self::PAGE . ($view === 'backup' ? '' : '&wrb_view=' . $view);
                    add_submenu_page(self::PAGE, $label, $label, 'manage_options', $slug, [$admin, 'render']);
                }
                // Preserve the installed tools.php URL without duplicating the navigation item.
                remove_submenu_page('tools.php', self::PAGE);
            }
        });
        // WordPress titles every submenu of this page after its first entry; the browser tab should name the screen in use.
        add_filter('admin_title', static function ($title) {
            if (defined('WRB_SYNTHETIC_RUNTIME_PROFILE') || ($_GET['page'] ?? '') !== self::PAGE || !is_string($title)) { return $title; }
            $labels = ['history' => '実行履歴', 'restore' => '復元', 'storage' => 'ストレージ設定', 'privacy' => '保存範囲と除外設定', 'settings' => '保存期間と容量'];
            $view = $_GET['wrb_view'] ?? 'backup';
            return is_string($view) && isset($labels[$view]) ? str_replace(['バックアップ設定', 'バックアップ'], $labels[$view], $title) : $title;
        });
        add_filter('submenu_file', static function ($submenu) {
            if (defined('WRB_SYNTHETIC_RUNTIME_PROFILE') || ($_GET['page'] ?? '') !== self::PAGE) { return $submenu; }
            $view = $_GET['wrb_view'] ?? 'backup';
            return is_string($view) && in_array($view, ['history', 'restore', 'storage', 'privacy', 'settings'], true) ? self::PAGE . '&wrb_view=' . $view : self::PAGE;
        });
        foreach (array_keys(self::FIELDS) as $command) {
            add_action('admin_post_wrb_' . $command, static function () use ($admin, $command): void {
                $admin->handle($command);
            });
        }
        add_action('admin_enqueue_scripts', static function (string $hook) use ($pluginFile): void {
            if ($hook === 'tools_page_' . self::PAGE || $hook === 'toplevel_page_' . self::PAGE) {
                wp_enqueue_style('wrb-synthetic-admin', plugins_url('assets/admin.css', $pluginFile), [], '0.6.4');
            }
        });
    }

    /** @return array<string,string> */
    public static function parseCommand(string $command, array $post): array
    {
        if (!isset(self::FIELDS[$command])) {
            throw new InvalidArgumentException('invalid_request');
        }
        $limits = ['action' => 64, '_wrb_nonce' => 128] + self::FIELDS[$command];
        if (array_diff(array_keys($post), array_keys($limits)) !== [] || count($post) !== count($limits)) {
            throw new InvalidArgumentException('invalid_request');
        }
        $input = [];
        foreach ($limits as $field => $limit) {
            if (!isset($post[$field]) || !is_string($post[$field]) || strlen($post[$field]) > $limit * 2) {
                throw new InvalidArgumentException('invalid_request');
            }
            $value = wp_unslash($post[$field]);
            if ($value === '' || strlen($value) > $limit || str_contains($value, "\0")) {
                throw new InvalidArgumentException('invalid_request');
            }
            $input[$field] = $value;
        }
        if ($input['action'] !== 'wrb_' . $command || (isset($input['slot']) && !in_array($input['slot'], ['1', '2'], true))) {
            throw new InvalidArgumentException('invalid_request');
        }
        return $input;
    }

    public function handle(string $command): void
    {
        $this->authorize();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html('この操作はPOSTで送信してください。'), '', ['response' => 405]);
        }
        try {
            $input = self::parseCommand($command, $_POST);
        } catch (Throwable $ignoredError) {
            $this->redirect('invalid_request');
        }
        if (!wp_verify_nonce($input['_wrb_nonce'], $input['action'])) {
            $this->redirect('nonce_invalid');
        }
        try {
            $backend = $this->backend();
            switch ($command) {
                case 'register_keys':
                    $backend->registerKeys([$input['recipient_1'], $input['recipient_2']]);
                    $result = 'registered';
                    break;
                case 'issue_challenge':
                    $backend->issueChallenge();
                    $result = 'issued';
                    break;
                case 'download_challenge':
                    $cipher = $backend->challenge((int) $input['slot']);
                    nocache_headers();
                    header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="wrb-key-challenge.age"');
                    header('X-Content-Type-Options: nosniff');
                    header('Content-Length: ' . strlen($cipher));
                    echo $cipher;
                    exit;
                case 'confirm_challenge':
                    $backend->confirmChallenge((int) $input['slot'], $input['challenge_id'], $input['code']);
                    $result = 'confirmed';
                    break;
                case 'start':
                    $backend->start($input['request_id']);
                    $result = 'completed';
                    break;
                case 'resume':
                    $backend->resume($input['operation_id']);
                    $result = 'completed';
                    break;
            }
        } catch (Throwable $error) {
            $result = SyntheticAdmin::publicError($error);
        }
        $this->redirect($result);
    }

    public function render(): void
    {
        if (!defined('WRB_SYNTHETIC_RUNTIME_PROFILE')) { LiveAdmin::render(); return; }
        $this->authorize();
        echo '<div class="wrap wrb-synthetic"><h1>合成バックアップ <small>' . esc_html(self::version()) . ' 試験版</small></h1>';
        echo '<div class="notice notice-warning inline"><p>' . esc_html('合成データ専用です。実サイトのファイル・データベース、実Cloudflareは取得しません。小規模な合成取得を、この画面からの要求中に処理します。') . '</p></div>';
        $result = $_GET['wrb_result'] ?? null;
        if (is_string($result) && isset(self::MESSAGES[$result])) {
            $success = in_array($result, ['registered', 'issued', 'confirmed', 'completed'], true);
            echo '<div class="notice ' . ($success ? 'notice-success' : 'notice-error') . ' inline" role="status"><p>' . esc_html(self::MESSAGES[$result]) . '</p></div>';
        }
        if (!defined('WRB_SYNTHETIC_RUNTIME_PROFILE')) {
            echo '<h2>合成設定未配置</h2><p>' . esc_html('設定担当者が隔離環境へ合成設定を配置すると操作できます。ブラウザーからパスや接続情報を設定する機能はありません。') . '</p>';
            $this->recoveryGuide();
            echo '</div>';
            return;
        }
        try {
            $view = $this->backend()->view();
        } catch (Throwable $error) {
            $reason = SyntheticAdmin::publicError($error);
            echo '<div class="notice notice-error inline"><p>' . esc_html(self::MESSAGES[$reason] ?? self::MESSAGES['runtime_invalid']) . '</p></div>';
            $this->recoveryGuide();
            echo '</div>';
            return;
        }
        echo '<p>合成単位ID <code>' . esc_html($view['unitId']) . '</code></p>';
        $pending = ($view['operation']['status'] ?? null) === 'pending';
        echo '<section><h2>1. 公開鍵2名分の登録</h2><p>異なるage公開鍵を2本登録します。秘密鍵は手元に保管し、WordPressへ渡さないでください。再登録すると両名の復号確認が解除されます。</p>';
        if ($pending) {
            echo '<p>未完了の操作があるため、公開鍵を変更できません。</p>';
        } else {
            $this->formStart('register_keys');
            echo '<fieldset><legend class="screen-reader-text">公開鍵2名分</legend>';
            foreach ([1, 2] as $slot) {
                $recipient = $view['keys'][$slot - 1]['recipient'] ?? '';
                echo '<p><label for="wrb-recipient-' . $slot . '">' . $slot . '人目のage公開鍵（必須）</label><br><input class="large-text code" id="wrb-recipient-' . $slot . '" name="recipient_' . $slot . '" type="text" required maxlength="128" value="' . esc_attr($recipient) . '" autocomplete="off" autocapitalize="off" spellcheck="false"></p>';
            }
            echo '</fieldset>';
            submit_button('公開鍵2名分を登録する', 'secondary', '');
            echo '</form>';
        }
        echo '</section><section><h2>2. 手元で復号して確認</h2><p>各公開鍵向けの暗号文を取得し、手元の対応する秘密鍵で復号します。暗号文に記載されたcodeだけを入力してください。期限は15分、失敗上限は5回です。再発行すると両名の確認が解除されます。確認できるのは秘密鍵の復号能力です。別々の人による操作は証明しません。</p>';
        echo '<p>手元のキットのageで実行する例です。秘密鍵を入力する場所は手元の端末だけです。</p><pre><code>./dependencies/age/age --decrypt --identity /dev/stdin wrb-key-challenge.age &lt; private-key</code></pre>';
        if ($view['keys'] === []) {
            echo '<p>公開鍵を登録すると確認できます。</p>';
        }
        if ($view['keys'] !== [] && !$pending) {
            $this->formStart('issue_challenge');
            submit_button('2名分の確認ファイルを作り直す', 'secondary', '');
            echo '</form>';
        }
        foreach ($view['keys'] as $key) {
            $slot = $key['slot'];
            echo '<fieldset><legend>' . esc_html((string) $slot) . '人目の公開鍵</legend><p><code>' . esc_html($key['recipient']) . '</code></p><p>状態 ' . esc_html($key['confirmed'] ? '復号確認済み' : '復号未確認') . '</p>';
            if ($key['downloadable']) {
                $this->formStart('download_challenge', ['slot' => (string) $slot]);
                submit_button('確認用暗号文を取得する', 'secondary', '');
                echo '</form>';
            }
            if (!$key['confirmed'] && $key['challengeId'] !== null && !$pending) {
                echo '<p>残りの確認回数 ' . esc_html((string) $key['attemptsRemaining']) . '回。期限 ' . esc_html(wp_date('Y-m-d H:i:s T', $key['expiresAt'])) . '</p>';
                $this->formStart('confirm_challenge', ['slot' => (string) $slot, 'challenge_id' => $key['challengeId']]);
                echo '<p><label for="wrb-code-' . esc_attr((string) $slot) . '">手元で復号したcode（必須）</label><br><input class="large-text code" id="wrb-code-' . esc_attr((string) $slot) . '" name="code" type="password" required maxlength="128" autocomplete="off" autocapitalize="off" spellcheck="false"></p>';
                submit_button('復号した確認コードを送信する', 'secondary', '');
                echo '</form>';
            }
            echo '</fieldset>';
        }
        echo '</section><section><h2>3. 合成取得と再開</h2>';
        if ($view['operation'] !== null) {
            $operation = $view['operation'];
            echo '<p>操作ID <code>' . esc_html($operation['operationId']) . '</code></p><p>状態 ' . esc_html($operation['status'] === 'complete' ? '完了照合済み' : '未完了。同じ操作を再開できます。') . '</p>';
            if ($operation['jobId'] !== null) {
                echo '<p>job ID（取得処理の識別子） <code>' . esc_html($operation['jobId']) . '</code></p>';
            }
            if ($pending) {
                $this->formStart('resume', ['operation_id' => $operation['operationId']]);
                submit_button('同じ操作を再開する', 'primary', '');
                echo '</form>';
            }
        }
        if ($view['canStart']) {
            $this->formStart('start', ['request_id' => $view['requestId']]);
            submit_button('合成データを取得する', 'primary', '');
            echo '</form>';
        } elseif (!$pending) {
            echo '<p>公開鍵2名分の復号確認後に合成取得を開始できます。</p>';
        }
        echo '</section><section><h2>4. 保存先の世代一覧</h2><p>reader（読み取り専用の接続）で取得した一覧です。保存物の検証と目録本文の復号・検証は別です。この画面では目録本文は未検証です。</p>';
        if ($view['listingError'] !== null) {
            echo '<p>' . esc_html(self::MESSAGES['listing_failed']) . '</p>';
        } elseif ($view['generations'] === []) {
            echo '<p>保存済みの合成世代はありません。</p>';
        } else {
            echo '<ul>';
            foreach ($view['generations'] as $generation) {
                echo '<li><code>' . esc_html($generation['snapshotId']) . '</code><br>' . esc_html($generation['valid'] ? '保存物検証済み。目録本文は未検証。' : '保存物を検証できません。目録本文は未検証。') . '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
        $this->recoveryGuide();
        echo '</div>';
    }

    private function authorize(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('この操作を行う権限がありません。'), '', ['response' => 403]);
        }
    }

    private function backend(): SyntheticAdmin
    {
        if (!defined('WRB_SYNTHETIC_RUNTIME_PROFILE') || !is_string(WRB_SYNTHETIC_RUNTIME_PROFILE) || WRB_SYNTHETIC_RUNTIME_PROFILE === '') {
            throw new \DomainException('runtime_invalid');
        }
        try {
            $runtime = SyntheticAdminRuntime::open(WRB_SYNTHETIC_RUNTIME_PROFILE, ABSPATH);
        } catch (Throwable $ignoredError) {
            throw new \DomainException('runtime_invalid');
        }
        return new SyntheticAdmin($runtime);
    }

    private function redirect(string $result): void
    {
        if (!isset(self::MESSAGES[$result])) {
            $result = 'runtime_invalid';
        }
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'wrb_result' => $result], admin_url('tools.php')), 303);
        exit;
    }

    private function formStart(string $command, array $fields = []): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr('wrb_' . $command) . '">';
        wp_nonce_field('wrb_' . $command, '_wrb_nonce', false);
        foreach ($fields as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
    }

    private function recoveryGuide(): void
    {
        echo '<section><h2>5. 独立キットで確認する</h2><p>復旧用キットと秘密鍵は分離して保管してください。元サイトなしの環境でキットのlist（世代一覧）とinspect（復号した目録の確認）を実行し、この画面の世代IDと照合します。この画面からキットは作成しません。</p><p>設定担当者向けの手順は <code>recovery/README.md</code> にあります。</p></section>';
    }
}
