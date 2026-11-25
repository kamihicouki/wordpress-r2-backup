<?php
declare(strict_types=1);
namespace WordPressR2Backup;

final class LiveAdminView
{
    private const VIEWS = ['backup' => ['バックアップ設定', 'backup'], 'history' => ['バックアップ実行履歴', 'list-view'], 'restore' => ['復元', 'undo'],
        'storage' => ['ストレージ設定', 'cloud'], 'privacy' => ['保存範囲と除外設定', 'privacy'], 'settings' => ['保存期間と容量', 'admin-generic']];
    private const CATEGORIES = ['forms' => ['フォーム送信情報', '確認できた問い合わせ・申込の送信データ、添付、送信記録。フォーム定義は含みません。', 'format-chat'],
        'wordpress_users' => ['WordPressユーザー', '管理者・編集者などのユーザーとユーザーメタ情報。フォーム送信者とは別の分類です。', 'admin-users'],
        'authentication' => ['認証設定', 'wp-config.phpなどの設定ファイル、認証・接続に使うサイト設定。含める場合も暗号化します。', 'lock'],
        'content' => ['コンテンツ', '公開・非公開の記事、固定ページ、コメントなど。', 'media-document'],
        'media' => ['メディア', 'アップロードした画像・資料などのファイル。', 'media-default'],
        'logs' => ['ログ', 'ログとして判別できたファイル。任意に除外できます。', 'media-text'],
        'backups' => ['既存バックアップ', '対象範囲内の既存バックアップファイル。今回の作業領域は取得対象外に配置します。', 'backup']];

    public static function render(?array $settings, ?array $state, bool $busy): void
    {
        $view = LiveAdmin::view();
        echo '<div class="wrap wrb-synthetic wrb-live"><header class="wrb-header"><p class="wrb-brand">R2 Backup <small>' . esc_html(WordPressAdmin::version()) . '</small></p><h1>' . esc_html(self::VIEWS[$view][0]) . '</h1>';
        echo '<nav class="wrb-nav" aria-label="R2 Backup">';
        foreach (self::VIEWS as $name => $item) {
            echo '<a href="' . esc_url(LiveAdmin::url($name)) . '"' . ($view === $name ? ' aria-current="page"' : '') . '><span class="dashicons dashicons-' . esc_attr($item[1]) . '" aria-hidden="true"></span> ' . esc_html($name === 'history' ? '実行履歴' : $item[0]) . '</a>';
        }
        echo '</nav></header>';
        $history = null;
        $historyAvailable = false;
        $historyNotice = null;
        try {
            $history = LiveAdmin::history();
            $historyNotice = LiveAdmin::historyNotice($history, $state);
            $historyAvailable = true;
        } catch (\Throwable $error) {
            // Keep all backup controls usable if the history storage cannot be read.
        }
        LiveHistoryView::renderNotice($historyNotice, $historyAvailable);
        $notice = LiveAdmin::takeOperationNotice();
        if ($notice !== '') {
            $isError = strpos($notice, 'エラー: ') === 0;
            echo '<div class="notice ' . ($isError ? 'notice-error' : 'notice-info') . ' inline"' . ($isError ? ' role="alert"' : '') . '><p>' . esc_html($notice) . '</p></div>';
        }
        if ($busy) { echo '<div class="wrb-alert"><p>未完了のバックアップがあります。完了または取り消しまで設定を変更できません。</p></div>'; }
        if (in_array($view, ['backup', 'storage'], true)) {
            try { AgeBinary::path(); }
            catch (\Throwable $error) { self::error($error->getMessage()); }
        }
        try {
            if ($view === 'history') { LiveHistoryView::render($history, $historyAvailable, $settings['policy'] ?? null); }
            elseif ($view === 'privacy') { self::privacy($settings, $busy); }
            elseif ($view === 'storage') { self::storage($settings, $busy); }
            elseif ($view === 'settings') { self::settings($settings, $busy); }
            elseif ($view === 'restore') { self::restore($settings); }
            else { self::backup($settings, $state, $busy); }
        } catch (\Throwable $error) { self::error($error->getMessage()); }
        echo '</div>';
    }

    private static function privacy(?array $settings, bool $busy): void
    {
        $policy = LiveSettings::loadPolicy($settings);
        $metadata = isset($_GET['wrb_scan']) ? self::databaseMetadata() : null;
        $detail = !isset($_GET['wrb_mode']) || $_GET['wrb_mode'] !== 'simple';
        echo '<p class="wrb-lead">保存する範囲と、任意に除外する対象を選びます。除外した内容は復旧できません。保存容量の節約や保存期間は「保存期間と容量」画面で設定し、ここでの除外とは独立しています。対象サイトのデータは変更しません。</p>';
        echo '<section class="wrb-panel"><h2><span class="dashicons dashicons-shield" aria-hidden="true"></span> 保存範囲と除外設定</h2><p>対象WordPressのファイルルートと構成上必要な追加ルート、接続先の1データベースを保存します。秘密値と行の実値は画面へ表示しません。保存前に暗号化します。</p>';
        LiveAdmin::form('privacy');
        echo '<fieldset' . ($busy ? ' disabled' : '') . '><fieldset class="wrb-public-options" data-wrb-presets aria-describedby="wrb-preset-help"><legend>保存のプリセット（設定のひな型）</legend>';
        foreach (['full' => ['完全保存', '除外や値の加工をせず、wp-config.phpなどの元の認証設定、WordPressユーザー、フォーム送信情報も暗号化して保存します。'],
            'forms' => ['フォーム送信情報を除外', '確認できた問い合わせ・申込の送信データと添付・送信記録を除外します。フォーム定義、WordPressユーザー、システム設定は保持します。個別の「含める」指定も適用します。'],
            'custom' => [isset($policy['legacy']) ? 'カスタム（以前の設定を継承）' : 'カスタム', '分類と個別の含める・除外を指定します。以前の設定は、明示的に解除するまで引き継ぎます。']] as $value => $label) {
            $id = 'wrb-preset-' . $value;
            echo '<label for="' . $id . '"><input id="' . $id . '" type="radio" name="preset" value="' . $value . '"' . checked($policy['preset'], $value, false) . ' aria-describedby="' . $id . '-help"> <strong>' . esc_html($label[0]) . '</strong><span id="' . $id . '-help" class="description">' . esc_html($label[1]) . '</span></label>';
        }
        echo '<p id="wrb-preset-help" class="description">「完全保存」を選んで保存すると、下の任意除外・個別指定・以前の互換ルールをすべて解除します。過去の世代の欠落データは復活しません。復号用秘密鍵と作業領域は取得対象の外で管理するなど、実行上の安全条件は別に確認します。</p></fieldset>';
        echo '<fieldset data-wrb-optional-rules><legend class="screen-reader-text">任意の除外ルール</legend><nav class="wrb-tabs" aria-label="除外設定の表示">';
        foreach (['simple' => 'かんたん設定', 'detail' => '詳細設定'] as $mode => $label) {
            echo '<a href="' . esc_url(add_query_arg('wrb_mode', $mode, LiveAdmin::url('privacy'))) . '"' . (($mode === 'detail') === $detail ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav><p>個別パスは長い指定を優先します。行・option_name（サイト設定の行名）の指定はテーブルより、テーブルは分類より優先します。「含める」は下位の除外を解除しますが、より具体的なフィールド除外は残ります。フィールド除外で制約を保てない場合は、取得前に停止します。</p><div class="wrb-category-grid">';
        foreach (self::CATEGORIES as $category => $item) {
            echo '<div class="wrb-category"><label for="wrb-category-' . $category . '"><span class="dashicons dashicons-' . esc_attr($item[2]) . '" aria-hidden="true"></span><strong>' . esc_html($item[0]) . '</strong></label>';
            self::select('category_' . $category, 'wrb-category-' . $category, $policy['categories'][$category] ?? '', '継承（プリセット・以前の設定）', 'wrb-category-' . $category . '-help');
            echo '<p id="wrb-category-' . $category . '-help" class="description">' . esc_html($item[1]) . '</p></div>';
        }
        echo '</div>';
        if (isset($policy['legacy'])) { self::legacyRules($policy); }
        self::policyTable($policy, $metadata);
        if ($detail) {
            echo '<h3>対象を手動で追加</h3><p>対象の種類に必要な欄だけ入力してください。「含める」は明示ルールとして保存します。継承へ戻すだけでは以前の除外を無効化しません。</p><div class="wrb-rule-add">';
            echo '<label for="wrb-rule-type">対象の種類<select id="wrb-rule-type" name="ruleType" data-wrb-rule-type><option value="field">フィールド</option><option value="table">テーブル</option><option value="path">ファイル・フォルダー</option><option value="option">サイト設定の行</option><option value="row">行（値の一致）</option></select></label>';
            echo '<label for="wrb-rule-table" data-wrb-rule-kind="table field row">テーブル名<input id="wrb-rule-table" name="tableName" type="text" maxlength="64" placeholder="wp_example_forms" autocomplete="off" spellcheck="false"></label>';
            echo '<label for="wrb-rule-field" data-wrb-rule-kind="field row">フィールド名<input id="wrb-rule-field" name="fieldName" type="text" maxlength="64" placeholder="contact_email" autocomplete="off" spellcheck="false"></label>';
            echo '<label for="wrb-rule-path" data-wrb-rule-kind="path">相対パス<input id="wrb-rule-path" name="path" type="text" maxlength="1024" placeholder="r0001/wp-content/uploads/private" autocomplete="off" spellcheck="false" aria-describedby="wrb-rule-path-help"><span id="wrb-rule-path-help" class="description">全ルート共通の相対パス、またはr0001/配下の相対パスでルートを限定できます。ルートIDはバックアップ開始時に確定します。</span></label>';
            echo '<label for="wrb-rule-option" data-wrb-rule-kind="option">サイト設定の行名<input id="wrb-rule-option" name="optionName" type="text" maxlength="191" placeholder="plugin_configuration" autocomplete="off" spellcheck="false" aria-describedby="wrb-rule-option-help"><span id="wrb-rule-option-help" class="description">option_nameの完全一致で指定します。設定値やSQL文（データベースへの操作指示）は入力しないでください。</span></label>';
            echo '<label for="wrb-rule-row-value" data-wrb-rule-kind="row">一致させる識別子<input id="wrb-rule-row-value" name="rowValue" type="text" maxlength="191" placeholder="123" autocomplete="off" spellcheck="false" aria-describedby="wrb-rule-row-value-help"><span id="wrb-rule-row-value-help" class="description">レコードIDや設定名などの識別子を完全一致で指定します。SQL文（データベースへの操作指示）、個人情報、秘密値は入力しないでください。</span></label>';
            echo '<label for="wrb-rule-action">扱い'; self::select('ruleAction', 'wrb-rule-action', 'exclude', '継承に戻す'); echo '</label><button type="submit" class="button button-primary" name="ruleIntent" value="add">対象を追加・変更</button></div>';
        } else { echo '<p><a href="' . esc_url(add_query_arg('wrb_mode', 'detail', LiveAdmin::url('privacy'))) . '">詳細設定で対象を手動で追加</a></p>'; }
        echo '</fieldset><p class="description">この画面ではファイル本文や行データを走査しません。バックアップ開始時に対象全体と除外後の依存関係を照合し、安全に保存できなければ停止します。</p><div class="wrb-actions">';
        submit_button('保存範囲と除外設定を保存', 'primary', '', false);
        echo '</div></fieldset></form><p><a class="button" href="' . esc_url(add_query_arg('wrb_scan', '1', LiveAdmin::url('privacy'))) . '">テーブル名と項目の型を確認</a></p></section>';
    }

    private static function databaseMetadata(): array
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_col') || !method_exists($wpdb, 'get_results')) { throw new \RuntimeException('テーブルの構成を読み取れません。'); }
        $names = $wpdb->get_col('SHOW TABLES');
        if (!is_array($names) || !empty($wpdb->last_error)) { throw new \RuntimeException('テーブル名を読み取れません。'); }
        $tables = [];
        foreach (array_slice($names, 0, 250) as $name) {
            if (!is_string($name) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $name)) { continue; }
            $columns = $wpdb->get_results('SHOW COLUMNS FROM `' . $name . '`', ARRAY_A);
            if (!is_array($columns) || !empty($wpdb->last_error)) { throw new \RuntimeException('項目の構成を読み取れません。'); }
            $fields = [];
            foreach ($columns as $column) { $fields[] = ['name' => (string) $column['Field'], 'type' => (string) $column['Type']]; }
            $tables[] = ['name' => $name, 'fields' => $fields];
        }
        return $tables;
    }

    private static function legacyRules(array $policy): void
    {
        $descriptions = [
            'legacy.sensitive_tables' => '旧版で機密情報の候補としたテーブルを除外し、不明なテーブルは確認を求めます。テーブルまたは分類の明示指定で変更できます。',
            'legacy.personal_fields' => '旧版の個人情報らしい項目名・値の検知を継承します。フィールドまたはテーブルの明示includeで変更できます。',
            'legacy.secret_generated_paths' => '旧版の設定・認証・非公開・生成物・ログのパス除外を継承します。個別パスのincludeで変更できます。',
            'legacy.option_names' => '旧版の認証・一時設定・wrb設定名の除外を継承します。option_nameまたはテーブルのincludeで変更できます。',
            'legacy.form_post_types' => '旧版の送信内容に似た投稿種類の除外を継承します。行またはテーブルのincludeで変更できます。',
            'legacy.personal_meta_keys' => '旧版の個人情報・認証に似たメタ項目名の除外を継承します。行またはテーブルのincludeで変更できます。',
            'legacy.public_content' => '旧版の公開情報の検査範囲と、公開コンテンツだけを保存する設定を継承します。個別指定または互換ルール全体の解除で変更できます。',
            'legacy.saved_rules' => '以前に保存した分類・テーブル・フィールド・パス・サイト設定名・行の指定を継承します。下の一覧で明示指定を追加できます。'];
        echo '<details class="wrb-legacy" open><summary>以前の設定を継承中</summary><p>新しい「含める」指定は以前の除外を上書きします。「継承」に戻すと以前の設定が再び有効になります。個別指定を追加しただけでは、他の実効除外は解除しません。</p><ul>';
        foreach ((new PrivacyPolicy($policy))->legacyRuleSummary() as $rule) { echo '<li>' . esc_html($descriptions[$rule['id']] ?? $rule['description']) . '</li>'; }
        echo '</ul><p>公開情報の内容検査 ' . (!empty($policy['legacy']['scanPublic']) ? '有効' : '省略') . '。公開コンテンツだけを保存 ' . (!empty($policy['legacy']['publicContentOnly']) ? '有効' : '無効') . '。</p><label for="wrb-clear-legacy"><input id="wrb-clear-legacy" type="checkbox" name="clearLegacy" value="1" aria-describedby="wrb-clear-legacy-help"> 以前の互換ルールをすべて解除する</label><p id="wrb-clear-legacy-help" class="description">明示して保存した場合だけ解除します。新しい分類・個別ルールは保持します。</p></details>';
    }

    private static function policyTable(array $policy, ?array $metadata): void
    {
        $rows = [];
        foreach (['tables' => 'table', 'fields' => 'field', 'paths' => 'path', 'options' => 'option'] as $kind => $type) {
            foreach ($policy[$kind] as $name => $action) { $rows[$type . ' ' . $name] = ['type' => $type, 'name' => $name, 'action' => $action, 'value' => $action, 'reason' => '個別設定']; }
        }
        foreach ($policy['rows'] as $table => $fields) {
            foreach ($fields as $field => $values) { foreach ($values as $value => $action) {
                $rows['row ' . $table . '.' . $field . ' ' . $value] = ['type' => 'row', 'name' => $table . '.' . $field . '（識別子は非表示）', 'action' => $action, 'value' => $action, 'reason' => '個別設定'];
            } }
        }
        if (isset($policy['legacy'])) {
            $legacy = $policy['legacy'];
            foreach (['tables' => 'table', 'fields' => 'field', 'paths' => 'path'] as $kind => $type) {
                foreach ($legacy[$kind] as $name => $action) { $key = $type . ' ' . $name; if (!isset($rows[$key])) { $rows[$key] = ['type' => $type, 'name' => $name, 'action' => $action, 'value' => '', 'reason' => '以前の個別設定']; } }
            }
            foreach ($legacy['excludedOptions'] as $name) { $key = 'option ' . $name; if (!isset($rows[$key])) { $rows[$key] = ['type' => 'option', 'name' => $name, 'action' => 'exclude', 'value' => '', 'reason' => '以前の個別設定']; } }
            foreach ($legacy['excludedRows'] as $table => $fields) { foreach ($fields as $field => $values) { foreach ($values as $value) {
                $key = 'row ' . $table . '.' . $field . ' ' . $value;
                if (!isset($rows[$key])) { $rows[$key] = ['type' => 'row', 'name' => $table . '.' . $field . '（識別子は非表示）', 'action' => 'exclude', 'value' => '', 'reason' => '以前の個別設定']; }
            } } }
            foreach ($legacy['categories'] as $category => $action) { echo '<p class="description">以前の分類 ' . esc_html(['contacts' => '問い合わせ', 'members' => '会員情報', 'content' => 'コンテンツ', 'media' => 'ファイル'][$category] ?? $category) . ' は' . ($action === 'exclude' ? '除外' : '含める') . 'を継承しています。</p>'; }
        }
        foreach ($metadata ?? [] as $table) {
            $key = 'table ' . $table['name'];
            if (!isset($rows[$key])) { $rows[$key] = ['type' => 'table', 'name' => $table['name'], 'value' => '', 'action' => '', 'reason' => 'テーブル名']; }
            foreach ($table['fields'] as $field) {
                $name = $table['name'] . '.' . $field['name']; $key = 'field ' . $name;
                if (!isset($rows[$key])) { $rows[$key] = ['type' => 'field', 'name' => $name, 'value' => '', 'action' => '', 'reason' => $field['type']]; }
            }
        }
        echo '<div class="wrb-table-wrap"><table class="widefat wrb-policy-table"><caption class="screen-reader-text">保存対象の個別指定と継承設定</caption><thead><tr><th scope="col">対象</th><th scope="col">場所</th><th scope="col">種類</th><th scope="col">扱い</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, 250, true) as $key => $row) {
            $name = $row['name']; $type = $row['type'];
            $label = $type === 'path' ? basename($name) : ($type === 'field' ? substr($name, strpos($name, '.') + 1) : $name);
            echo '<tr><th scope="row">' . esc_html($label) . '<small>' . esc_html($row['reason']) . '</small></th><td><code>' . esc_html($name) . '</code></td><td>' . esc_html(['table' => 'テーブル', 'field' => 'フィールド', 'path' => 'ファイル・フォルダー', 'option' => 'サイト設定の行', 'row' => '行（値の一致）'][$type]) . '</td><td>';
            $id = 'wrb-decision-' . md5($key);
            echo '<label class="screen-reader-text" for="' . esc_attr($id) . '">' . esc_html($name) . 'の扱い</label>';
            self::select('decisions[' . base64_encode($key) . ']', $id, $row['value'], '継承' . ($row['value'] === '' && $row['action'] !== '' ? '（' . ($row['action'] === 'exclude' ? '除外' : '含める') . '）' : '（下位の設定を使用）'));
            echo '</td></tr>';
        }
        if ($rows === []) { echo '<tr><td colspan="4" class="wrb-empty">個別設定はまだありません。カスタムを選び、対象を手動で追加できます。</td></tr>'; }
        echo '</tbody></table></div>';
        if (count($rows) > 250) { echo '<p class="description">一覧は先頭250対象です。表示されていない保存済みルールも保持しています。対象名を手動で指定して変更できます。</p>'; }
    }

    private static function select(string $name, string $id, string $value, ?string $default = null, ?string $description = null): void
    {
        echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"' . ($description !== null ? ' aria-describedby="' . esc_attr($description) . '"' : '') . '>';
        $choices = $default === null ? [] : ['' => $default];
        foreach ($choices + ['exclude' => '除外', 'include' => '含める'] as $action => $label) { echo '<option value="' . esc_attr($action) . '"' . selected($value, $action, false) . '>' . esc_html($label) . '</option>'; }
        echo '</select>';
    }

    private static function storage(?array $settings, bool $busy): void
    {
        echo '<p class="wrb-lead">Cloudflare R2の保存先と、復旧する2名の公開鍵を設定します。</p><section class="wrb-panel"><h2>R2に接続</h2><p>現在は保存先バケット専用のアクセスキーを使用します。OAuth連携（Cloudflareにログインして許可する接続方法）は準備中です。</p><p>秘密アクセスキーは暗号化して保存し、画面へ再表示しません。</p>';
        LiveAdmin::form('save'); echo '<fieldset' . ($busy ? ' disabled' : '') . '>';
        LiveAdmin::input('accountId', 'CloudflareアカウントID', $settings['accountId'] ?? '', 32);
        LiveAdmin::input('bucket', 'R2バケット名', $settings['bucket'] ?? '', 63);
        LiveAdmin::input('accessKeyId', 'アクセスキーID', $settings['accessKeyId'] ?? '', 128);
        echo '<p><label for="wrb-secret">秘密アクセスキー' . ($settings ? '（変更する場合だけ入力）' : '（必須）') . '</label><br><input id="wrb-secret" name="secretAccessKey" type="password" class="large-text" autocomplete="new-password" maxlength="256" value=""' . ($settings ? '' : ' required') . '></p>';
        echo '<h2>復旧する2名の公開鍵</h2><p>異なるage公開鍵（バックアップの暗号化に使う鍵）を入力します。ここで入力するのは公開鍵だけです。秘密鍵はこの画面へ入力せず、別々の安全な場所へ保管してください。秘密鍵は復元画面でだけ一時的に使い、保存しません。各秘密鍵は単独で復旧に使えます。</p>';
        foreach ([0, 1] as $slot) { LiveAdmin::input('recipient_' . $slot, ($slot + 1) . '人目のage公開鍵', $settings['recipients'][$slot] ?? '', 128); }
        echo '<details><summary>公開鍵の準備方法</summary><p>手元の端末で公式のage-keygenを実行して2組の鍵を作成します。独立復旧キットにもageを同梱します。</p><pre>age-keygen -o administrator-1.key<br>age-keygen -o administrator-2.key</pre></details>';
        submit_button('接続設定を保存', 'primary', '', false); echo '</fieldset></form></section>';
        if ($settings === null) { return; }
        echo '<section class="wrb-panel"><h2>接続と復号能力の確認</h2><p>R2接続 ' . (!empty($settings['connectionVerified']) ? '確認済み' : '未確認') . '</p>';
        LiveAdmin::form('test'); submit_button('R2接続を確認', 'secondary', '', false); echo '</form><p>サイト専用領域に小さな確認データを保存し、読み戻して確認データだけを削除します。</p>';
        LiveAdmin::form('issue'); echo '<fieldset' . ($busy ? ' disabled' : '') . '>'; submit_button('2名分の復号確認ファイルを作成', 'secondary', '', false); echo '</fieldset></form>';
        foreach ([0, 1] as $slot) {
            $proof = $settings['proofs'][$slot] ?? null;
            echo '<div class="wrb-key-proof"><h3>' . ($slot + 1) . '人目の公開鍵 ' . (!empty($proof['confirmed']) ? '確認済み' : '未確認') . '</h3>';
            if ($proof !== null) {
                LiveAdmin::form('download', ['slot' => (string) $slot]); submit_button('確認用暗号文を取得', 'secondary', '', false); echo '</form>';
                echo '<p>手元で復号し、確認ファイル内のcode（復号確認コード）を入力します。期限15分、失敗上限5回です。秘密鍵は送信しません。</p>';
                LiveAdmin::form('confirm', ['slot' => (string) $slot]); LiveAdmin::input('code', '手元で復号したcode', '', 64); submit_button('復号を確認', 'secondary', '', false); echo '</form>';
            }
            echo '</div>';
        }
        echo '</section>';
    }

    private static function backup(?array $settings, ?array $state, bool $busy): void
    {
        $ready = $settings !== null && !empty($settings['connectionVerified']) && !empty($settings['proofs'][0]['confirmed']) && !empty($settings['proofs'][1]['confirmed']);
        echo '<p class="wrb-lead">このWordPressを公開鍵で暗号化し、Cloudflare R2へ保存します。</p><section class="wrb-panel"><h2>準備の状況</h2><ul class="wrb-checklist">';
        foreach ([[$settings !== null, 'storage', '保存先と公開鍵を設定'], [!empty($settings['connectionVerified']), 'storage', 'R2の接続を確認'],
            [!empty($settings['proofs'][0]['confirmed']) && !empty($settings['proofs'][1]['confirmed']), 'storage', '2名の復号能力を確認']] as $item) {
            echo '<li><span class="dashicons dashicons-' . ($item[0] ? 'yes-alt' : 'marker') . '" aria-hidden="true"></span> ' . ($item[0] ? '確認済み ' : '未確認 ') . '<a href="' . esc_url(LiveAdmin::url($item[1])) . '">' . esc_html($item[2]) . '</a></li>';
        }
        echo '<li><span class="dashicons dashicons-privacy" aria-hidden="true"></span> <a href="' . esc_url(LiveAdmin::url('privacy')) . '">保存範囲と除外設定を確認</a>。開始時に対象と依存関係を照合し、安全に保存できなければ停止します。</li></ul></section>';
        self::mediaAudit($busy);
        // The script redraws this panel from the same public fields and keeps polling while a run is active. Without the
        // script, the server-rendered summary stays accurate as of this page load. Only allow-listed fields reach the page.
        $public = self::publicState($state);
        echo '<section class="wrb-panel wrb-progress" id="wrb-progress" data-wrb-state="' . esc_attr((string) json_encode($public, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)) . '">';
        echo '<h2>現在の状態</h2><p id="wrb-live-announce" class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true"></p><div id="wrb-live-status"><p>' . esc_html($public['message'] ?? 'まだ実行していません。') . '</p>';
        if ($public !== null) {
            echo '<p>状態 ' . esc_html(self::statusLabel($public['status'])) . '　転送 ' . (int) ($public['completed'] ?? 0) . ' / ' . (int) ($public['total'] ?? 0) . ' 項目</p>';
            if (($public['consistencyMode'] ?? null) === 'online') { echo '<p>取得方法: 保守なし（DBとファイルの取得時点に差がある場合があります）</p>'; }
            if (isset($public['startedAt'])) { echo '<p class="wrb-muted">開始 ' . esc_html($public['startedAt']) . '（UTC）</p>'; }
        }
        if (isset($public['sharing'])) { echo '<p>' . esc_html(self::sharingSummary($public['sharing'])) . '</p>'; }
        echo '</div><p class="wrb-muted" id="wrb-live-checked">この表示は画面を開いた時点の記録です。</p><div id="wrb-live-detail"></div></section>';
        echo '<section class="wrb-panel"><h2>バックアップを実行</h2><p>取得方法を選びます。取得後は暗号化済みデータをR2へ転送します。</p>';
        echo '<p><a href="' . esc_url(add_query_arg('wrb_source', 'manual', LiveAdmin::url('history'))) . '">手動バックアップの実行履歴</a></p>';
        echo '<fieldset id="wrb-manual-mode"><legend>取得中のサイトの扱い</legend><p><label><input type="radio" name="wrb_manual_mode" value="maintenance" checked> <strong>保守あり — 整合性を優先</strong></label><br><span class="description">取得中はサイトを一時停止し、外部の書き込みも停止します。</span></p><p><label><input type="radio" name="wrb_manual_mode" value="online"> <strong>保守なし — サイトを公開したまま</strong></label><br><span class="description">DBとファイルの取得時点が異なる場合があります。検出できない変更は残る可能性があります。DB構造の変更は取得中に待機する場合があります。</span></p></fieldset>';
        echo '<div data-wrb-maintenance-confirm><p><label><input id="wrb-writers" type="checkbox"> 外部の定期処理・直接更新等の書き手を停止しました</label></p><p><label><input id="wrb-downtime" type="checkbox"> 取得中の一時的な保守状態を確認しました</label></p></div><div data-wrb-online-confirm hidden><p><label><input id="wrb-online-ack" type="checkbox"> DBとファイルの時点差が残る可能性を理解しました</label></p></div>';
        echo '<div class="wrb-actions"><button type="button" class="button button-primary" data-wrb-command="start" data-wrb-ready="' . ($ready ? '1' : '0') . '"' . ($busy || !$ready ? ' disabled' : '') . '>このサイトをバックアップ</button> <button type="button" class="button" data-wrb-command="step"' . (!$busy || ($state['status'] ?? '') !== 'uploading' ? ' disabled' : '') . '>暗号文の転送を再開</button></div>';
        if (!$ready) { echo '<p>ストレージ設定で接続と2名の復号能力を確認すると、バックアップを開始できます。</p>'; }
        echo '<p>除外した情報は復旧できません。バックアップの成功と復旧の確認は別の検証です。</p>';
        // Always rendered so the script can show it exactly when this server rule holds and the state is confirmed. The
        // server itself refuses to cancel a run that still holds the operation lock.
        $cancellable = self::cancellable($public);
        echo '<div class="wrb-cancel" data-wrb-cancel' . ($cancellable ? '' : ' hidden') . '>';
        LiveAdmin::form('cancel'); submit_button('停止した未完了の実行を取り消す', 'secondary', '', false, $cancellable ? [] : ['disabled' => 'disabled']); echo '</form><p>実行中は取り消せません。強制終了後に保守表示が残った場合は、保守表示の期限（最大10分）後に開き直してください。</p></div>';
        echo '</section>';
        self::schedulePending($settings, $state);
    }

    /** Saving a schedule is available, but a runner heartbeat alone does not prove automatic execution is configured. */
    private static function schedulePending(?array $settings, ?array $state): void
    {
        $schedule = get_option('wrb_live_schedule', null);
        $schedule = is_array($schedule) ? $schedule : [];
        $frequency = in_array($schedule['frequency'] ?? null, ['hourly', 'daily', 'weekly'], true) ? $schedule['frequency'] : 'daily';
        $time = is_string($schedule['time'] ?? null) && preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $schedule['time']) ? $schedule['time'] : '03:00';
        $weekday = is_int($schedule['weekday'] ?? null) && $schedule['weekday'] >= 1 && $schedule['weekday'] <= 7 ? (string) $schedule['weekday'] : '';
        $mode = in_array($schedule['mode'] ?? null, ['maintenance', 'online'], true) ? $schedule['mode'] : 'maintenance';
        $locked = $settings === null || ($state !== null && !in_array($state['status'] ?? '', ['complete', 'cancelled'], true));
        echo '<section class="wrb-panel" id="wrb-schedule"><h2>定期バックアップ</h2><p class="wrb-alert">定期実行は未準備の場合があります。設定保存だけではサーバーの定期実行は登録されません。サーバー側の設定と安全な取得条件を確認してください。保守ありの定期取得は現在準備中です。</p>';
        $historyFilter = ['wrb_source' => 'scheduled'];
        if (LiveHistoryView::validId($schedule['revision'] ?? null)) { $historyFilter['wrb_schedule_revision'] = $schedule['revision']; }
        echo '<p><a href="' . esc_url(add_query_arg($historyFilter, LiveAdmin::url('history'))) . '">'
            . (isset($historyFilter['wrb_schedule_revision']) ? 'この定期設定の実行履歴' : '定期バックアップの実行履歴') . '</a></p>';
        if ($locked) { echo '<p>接続設定を保存し、未完了のバックアップを終了または取り消すと、予定を編集できます。</p>'; }
        LiveAdmin::form('schedule');
        echo '<fieldset' . ($locked ? ' disabled' : '') . '><legend>定期実行の設定</legend><p><label><input type="checkbox" name="scheduleEnabled" value="1"' . (($schedule['enabled'] ?? false) === true ? ' checked' : '') . '> 定期バックアップの予定を有効にする</label></p>';
        try { $rules = ScheduleRules::validate($schedule['rules'] ?? [['type' => 'basic']]); }
        catch (\InvalidArgumentException $error) {
            echo '<p class="wrb-alert">保存済みのルールを読み取れません。基本ルールを確認して保存し直してください。</p>';
            $rules = ScheduleRules::validate([['type' => 'basic']]);
        }
        echo '<div data-wrb-schedule-rules data-limit="' . ScheduleRules::LIMIT . '">';
        echo '<p class="description">上から順に確認し、最初に一致したルールを適用します。除外を優先する場合は実行ルールより上に置いてください。時刻はサイトのタイムゾーンです。</p>';
        echo '<ol class="wrb-schedule-rules" data-wrb-rule-list>';
        foreach ($rules as $index => $rule) {
            self::scheduleRuleStart((string) $index, $rule['type']);
            if ($rule['type'] === 'basic') {
                echo '<p><label>基本ルール <select name="scheduleRules[' . $index . '][enabled]"><option value="1"' . ($rule['enabled'] === '1' ? ' selected' : '') . '>有効</option><option value="0"' . ($rule['enabled'] === '0' ? ' selected' : '') . '>無効</option></select></label></p>';
                self::basicScheduleFields($frequency, $time, $weekday);
            } else {
                self::scheduleRuleFields((string) $index, $rule);
            }
            echo '</li>';
        }
        echo '</ol><p class="hide-if-no-js"><button type="button" class="button" data-wrb-add="cron">cronルールを追加</button> <button type="button" class="button" data-wrb-add="exclude">除外時間帯を追加</button></p>';
        echo '<p class="screen-reader-text" role="status" data-wrb-rule-status></p>';
        foreach ([['type' => 'cron', 'cron' => '15 2 * * 3'], ['type' => 'exclude', 'day' => '0', 'from' => '02:00', 'to' => '03:00']] as $rule) {
            echo '<template data-wrb-rule-template="' . $rule['type'] . '">';
            self::scheduleRuleStart('new', $rule['type']);
            self::scheduleRuleFields('new', $rule);
            echo '</li></template>';
        }
        echo '<input type="hidden" name="scheduleRulesComplete" value="1"></div>';
        echo '<p><label><input type="radio" name="scheduleMode" value="maintenance"' . ($mode === 'maintenance' ? ' checked' : '') . '> 保守あり</label> — 外部の書き込み停止を確認できた場合のみ実行します。</p><p><label><input type="radio" name="scheduleMode" value="online"' . ($mode === 'online' ? ' checked' : '') . '> 保守なし</label> — DBとファイルの取得時点に差が出る可能性があります。</p>';
        submit_button('定期バックアップの設定を保存', 'secondary', '', false);
        echo '</fieldset></form>';
        $next = '未確定';
        if (($schedule['enabled'] ?? false) === true && function_exists('wp_timezone') && function_exists('wp_timezone_string')) {
            try {
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $nextDate = array_key_exists('rules', $schedule)
                    ? ScheduleRules::next($schedule, wp_timezone(), $now)
                    : BackupSchedule::next($frequency, $time, $weekday === '' ? null : (int) $weekday, wp_timezone(), $now);
                $next = $nextDate === null ? '370日以内に実行候補なし'
                    : $nextDate->setTimezone(wp_timezone())->format('Y-m-d H:i') . '（' . wp_timezone_string() . '）';
            } catch (\Throwable $error) { $next = '設定を確認してください'; }
        }
        echo '<p>次回の予定: ' . esc_html($next) . '。サーバーの定期実行が動作した場合の予定です。</p>';
        $runner = get_option('wrb_live_schedule_runner', []); $runner = is_array($runner) ? $runner : [];
        $timestamp = static function ($value): ?string {
            if (!is_string($value)) { return null; }
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
            return $parsed !== false && $parsed->format('Y-m-d\TH:i:s\Z') === $value ? $value : null;
        };
        $seen = $timestamp($runner['lastSeen'] ?? null); $attempt = $timestamp($runner['lastAttempt'] ?? null);
        $missed = $timestamp($runner['lastMissedSlot'] ?? null);
        if ($missed !== null && is_string($schedule['revision'] ?? null) && is_string($runner['lastMissedRevision'] ?? null)
            && hash_equals($schedule['revision'], $runner['lastMissedRevision'])) {
            echo '<p>未実行の予定: ' . esc_html($missed) . '（UTC）。予定時刻を過ぎましたが実行を確認できません。自動再実行はしていません。</p>';
        }
        if ($seen !== null) { echo '<p>最終の起動確認: ' . esc_html($seen) . '（UTC）。起動確認だけでは定期実行の登録は確認できません。</p>'; }
        if ($attempt !== null && ($runner['lastResult'] ?? null) === 'unavailable') { echo '<p>前回の予定: ' . esc_html($attempt) . '（UTC）。自動取得は実行されませんでした。</p>'; }
        $lastResult = $runner['lastResult'] ?? null;
        $message = is_string($runner['message'] ?? null) ? $runner['message'] : '';
        if ($attempt !== null && $lastResult === 'skipped') {
            $capacity = [ScheduledStorageLimit::countLimit()->getMessage(), ScheduledStorageLimit::lowSpace()->getMessage()];
            $reason = in_array($message, $capacity, true) ? $message : 'ほかのバックアップが進行中のため見送りました。';
            echo '<p>前回の予定: ' . esc_html($attempt) . '（UTC）。' . esc_html($reason) . '</p>';
        }
        if ($lastResult === 'storage_wait') {
            $prefix = $attempt === null ? '' : '前回の予定: ' . $attempt . '（UTC）。';
            echo '<p>' . esc_html($prefix . ($message !== '' ? $message : ScheduledStorageLimit::storageWait()->getMessage())) . '</p>';
        } elseif (str_starts_with($message, 'R2保存先')) {
            // A wait discovered while resuming between slots only updates the message, preserving the previous slot result.
            echo '<p>転送の再開確認: ' . esc_html($message) . '</p>';
        }
        if ($attempt !== null && ($runner['lastResult'] ?? null) === 'started') { echo '<p>前回の予定: ' . esc_html($attempt) . '（UTC）。起動依頼を受け付けました。バックアップの完了は別途確認してください。</p>'; }
        if ($attempt !== null && in_array($runner['lastResult'] ?? null, ['failed', 'interrupted', 'claimed'], true)) { echo '<p>前回の予定: ' . esc_html($attempt) . '（UTC）。起動状況を確認できません。自動再試行せず、バックアップの状態を確認してください。</p>'; }
        if ($attempt !== null && ($runner['lastResult'] ?? null) === 'config_changed') { echo '<p>前回の予定: ' . esc_html($attempt) . '（UTC）。設定が変更されたため開始しませんでした。</p>'; }
        echo '</section>';
    }

    private static function basicScheduleFields(string $frequency, string $time, string $weekday): void
    {
        echo '<p><label for="wrb-schedule-frequency">頻度</label> <select id="wrb-schedule-frequency" name="scheduleFrequency"><option value="hourly"' . ($frequency === 'hourly' ? ' selected' : '') . '>1時間ごと</option><option value="daily"' . ($frequency === 'daily' ? ' selected' : '') . '>毎日</option><option value="weekly"' . ($frequency === 'weekly' ? ' selected' : '') . '>毎週</option></select> <label for="wrb-schedule-weekday">曜日（毎週の場合）</label> <select id="wrb-schedule-weekday" name="scheduleWeekday"><option value="">選択してください</option>';
        foreach (['月', '火', '水', '木', '金', '土', '日'] as $index => $day) { $value = (string) ($index + 1); echo '<option value="' . $value . '"' . ($weekday === $value ? ' selected' : '') . '>' . $day . '曜日</option>'; }
        echo '</select> <label for="wrb-schedule-time">開始時刻</label> <input type="time" id="wrb-schedule-time" name="scheduleTime" value="' . esc_attr($time) . '" aria-describedby="wrb-schedule-time-help" required> <span class="description" id="wrb-schedule-time-help">サイトのタイムゾーン。毎時の場合は分だけを使用します（例: 03:15なら毎時15分）。</span></p>';
    }

    private static function scheduleRuleStart(string $index, string $type): void
    {
        $title = ['basic' => '基本の実行日時', 'cron' => 'cronで実行', 'exclude' => 'メンテナンス時間帯を除外'][$type];
        echo '<li><input type="hidden" name="scheduleRules[' . $index . '][type]" value="' . $type . '">';
        echo '<div class="wrb-rule-heading"><span class="dashicons dashicons-move hide-if-no-js wrb-rule-grip" draggable="true" title="ドラッグして優先順位を変更" aria-hidden="true"></span>';
        echo '<strong>' . $title . '</strong><div class="wrb-rule-actions hide-if-no-js">';
        echo '<button type="button" class="button button-small" data-wrb-move="-1">上へ</button> <button type="button" class="button button-small" data-wrb-move="1">下へ</button>';
        if ($type !== 'basic') { echo ' <button type="button" class="button button-small" data-wrb-remove-rule>削除</button>'; }
        echo '</div></div>';
    }

    private static function scheduleRuleFields(string $index, array $rule): void
    {
        $name = 'scheduleRules[' . $index . ']';
        if ($rule['type'] === 'cron') {
            echo '<p><label>cron式 <input type="text" class="regular-text code" name="' . $name . '[cron]" value="' . esc_attr($rule['cron']) . '" maxlength="128" required></label></p>';
            echo '<p class="description">分・時・日・月・曜日の5項目です。例 <code>15 2 * * 3</code>（毎週水曜 02:15）。</p>';
            return;
        }
        echo '<p><label>開始曜日 <select name="' . $name . '[day]">';
        foreach (['毎日', '月曜', '火曜', '水曜', '木曜', '金曜', '土曜', '日曜'] as $day => $label) {
            echo '<option value="' . $day . '"' . ($rule['day'] === (string) $day ? ' selected' : '') . '>' . $label . '</option>';
        }
        echo '</select></label> <label>開始 <input type="time" name="' . $name . '[from]" value="' . esc_attr($rule['from']) . '" required></label> ';
        echo '<label>終了 <input type="time" name="' . $name . '[to]" value="' . esc_attr($rule['to']) . '" required></label></p>';
        echo '<p class="description">終了が開始より早い場合は翌日まで除外します。終了時刻は除外に含みません。</p>';
    }

    private static function mediaAudit(bool $busy): void
    {
        $auditError = null;
        try { $audit = LiveMediaAudit::current(); }
        catch (\RuntimeException $error) { $audit = null; $auditError = '前回の確認記録を読み取れません。未確認として、もう一度画像ファイルを確認してください。'; }
        $done = $audit !== null && $audit['done'];
        $warning = $auditError !== null || ($audit !== null && $audit['warning']);
        echo '<section class="wrb-panel" data-wrb-media data-wrb-media-done="' . ($done ? '1' : '0') . '" data-wrb-media-warning="' . ($warning ? '1' : '0') . '" data-wrb-media-checked-at="' . (int) ($audit['checkedAt'] ?? 0) . '"><h2>開始前の画像ファイル確認</h2>';
        echo '<p>公開画像の登録と、現在のアップロード保存先にある元画像・登録済みサイズを照合します。保存範囲の除外設定を適用する前の参考検査です。外部画像の取得や、元データの変更は行いません。</p>';
        echo '<div id="wrb-media-status" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"' . ($warning ? ' class="wrb-alert"' : '') . '>';
        if ($auditError !== null) { echo '<p>' . esc_html($auditError) . '</p>'; }
        foreach (LiveMediaAudit::lines($audit) as $line) { echo '<p>' . esc_html($line) . '</p>'; }
        echo '</div><p><button type="button" class="button" aria-controls="wrb-media-status" data-wrb-media-check' . ($busy ? ' disabled' : '') . '>画像ファイルを確認</button></p>';
        echo '<p data-wrb-media-ack' . (!$warning ? ' hidden' : '') . '><label for="wrb-media-ack"><input type="checkbox" id="wrb-media-ack" name="wrb_media_ack" aria-describedby="wrb-media-status"> 画像の不足または未確認の注意を理解し、現在の保存対象で続行します</label></p>';
        echo '<p class="description">バックアップ開始前にも確認します。検査後にファイルが変わる場合があります。記事内だけで参照される画像や外部URL、個別の除外指定による不足は、この検査では判定しません。バックアップの照合成功と、画像を含むサイト全体の復元確認は別です。</p><noscript><p>画像確認にはJavaScriptが必要です。現在の検査結果だけを表示しています。</p></noscript></section>';
    }

    private static function restore(?array $settings): void
    {
        WordPressRestoreAdmin::render($settings);
        if ($settings === null) {
            if (!defined('WRB_RESTORE_LOCAL_REPOSITORY')) { echo '<p class="wrb-empty">保存先は未設定です。接続ファイルを選ぶか、<a href="' . esc_url(LiveAdmin::url('storage')) . '">ストレージ設定</a>から接続してください。</p>'; }
            return;
        }
        echo '<details class="wrb-panel wrb-r-admin-tools"' . (isset($_GET['wrb_list']) ? ' open' : '') . '><summary><strong>バックアップの管理と別環境への備え</strong></summary>';
        echo '<h2>R2に保存したバックアップ</h2>';
        if (isset($_GET['wrb_list'])) {
            try {
                $records = (new LiveBackup($settings))->listing();
                if ($records === []) { echo '<p class="wrb-empty">保存済みのバックアップはありません。</p>'; }
                echo '<ul class="wrb-snapshot-list">';
                foreach ($records as $record) {
                    echo '<li><code>' . esc_html($record['snapshotId']) . '</code><p>' . (!empty($record['valid']) ? '暗号化された目録を照合済み（中身は未復号）' : '検証に失敗しました') . '</p>';
                    LiveAdmin::form('delete', ['snapshot' => $record['snapshotId']]); echo '<label><input type="checkbox" name="confirmDelete" value="1" required> このバックアップから復元できなくなることを確認しました</label> '; submit_button('このバックアップを削除', 'delete', '', false); echo '</form></li>';
                }
                echo '</ul>';
            } catch (\Throwable $error) { self::error($error->getMessage()); }
        } else { echo '<p>ボタンを押すと、R2から一覧を読み込みます。</p>'; }
        echo '<p><a class="button" href="' . esc_url(add_query_arg('wrb_list', '1', LiveAdmin::url('restore'))) . '">一覧を読み込む</a></p><h2>別環境で復元するための準備</h2><p>独立復旧キットと秘密鍵をサイトの外へ保管してください。接続ファイルにはR2のアクセスキーが含まれます。安全に保管し、他人と共有しないでください。</p>';
        LiveAdmin::form('recovery'); submit_button('復旧用のR2接続ファイルを取得', 'secondary', '', false); echo '</form></details>';
    }

    private static function settings(?array $settings, bool $busy): void
    {
        echo '<p class="wrb-lead">バックアップをどれだけ長く残すか、保存容量をどう抑えるかを設定します。何を保存するか（除外）は「保存範囲と除外設定」で、誰が復旧できるか（暗号化の鍵）は「ストレージ設定」で決めます。</p>';
        echo '<section class="wrb-panel"><h2>保存期間</h2>';
        if ($settings === null) { echo '<p class="wrb-empty">ストレージ設定を保存すると、保存期間を変更できます。既定は30日です。</p></section>'; return; }
        LiveAdmin::form('retention'); echo '<fieldset' . ($busy ? ' disabled' : '') . '><p class="wrb-setting-row"><label for="wrb-days">保存期間（日）</label> <input id="wrb-days" name="retentionDays" type="number" min="1" max="3650" required value="' . esc_attr((string) $settings['retentionDays']) . '"></p><p>これより古い世代は自動で削除されます。他の世代と共有している内容は、その世代が残る間は保持します。接続情報・公開鍵・除外設定は変わりません。</p>';
        submit_button('保存期間を保存', 'primary', '', false); echo '</fieldset></form></section>';
        $sharing = $settings['sharing'] ?? ['enabled' => false, 'directory' => ''];
        echo '<section class="wrb-panel"><h2>保存容量を節約（世代間共有）</h2><p>2回目以降のバックアップで、前回までと同じ内容をR2へ送り直さず再利用します。更新がほとんどないサイトほど、追加のアップロード量とR2の保存容量を減らせます（所要時間の短縮は保証しません）。</p>';
        LiveAdmin::form('sharing'); echo '<fieldset' . ($busy ? ' disabled' : '') . '><legend class="screen-reader-text">保存容量の節約設定</legend><p><label for="wrb-sharing-enabled"><input id="wrb-sharing-enabled" name="sharingEnabled" type="checkbox" value="1"' . (!empty($sharing['enabled']) ? ' checked' : '') . ' aria-describedby="wrb-sharing-help"> 保存容量を節約する</label></p>';
        echo '<p id="wrb-sharing-help">チェックして保存するだけで使えます。保存先と公開鍵が同じバックアップの間だけで再利用し、保存する範囲は広がりません。内容の照合に使う記録（索引）は、このサーバーの非公開領域へ自動で作ります。安全な場所を自動で選べない場合は、理由を表示して有効にしません。</p>';
        echo '<details' . ($sharing['directory'] !== '' ? ' open' : '') . '><summary>索引の保存場所（通常は変更不要）</summary><p><label for="wrb-sharing-directory">索引の専用ディレクトリ（空欄なら安全な場所を自動選択）</label><br><input id="wrb-sharing-directory" name="sharingDirectory" type="text" class="large-text code" maxlength="4096" value="' . esc_attr($sharing['directory']) . '" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" aria-describedby="wrb-sharing-help"></p>';
        echo '<p class="description">自動選択できない場合や、保存場所を指定したい場合だけ、サイトと一時領域の外にある所有者専用の永続ディレクトリを絶対パスで入力します。索引が失われると、取得を止めて案内します。復旧自体に索引は不要です。</p></details>';
        echo '<p>無効にしても、保存済み世代は削除しません。保存範囲と除外設定も変わりません。</p>';
        submit_button('保存容量の節約設定を保存', 'primary', '', false); echo '</fieldset></form></section>';
        echo '<section class="wrb-panel"><h2>設定項目の違い</h2><table class="widefat wrb-glossary"><thead><tr><th scope="col">項目</th><th scope="col">役割</th><th scope="col">変わらないこと</th></tr></thead><tbody>';
        foreach ([['保存範囲と除外', '何を保存するか。除外したものは復旧できません。', '暗号化・節約設定とは独立'],
            ['保存容量の節約', '同じ内容の再送・再保存を避けるだけです。', '保存範囲を広げず、除外内容も戻しません'],
            ['保存期間', '古い世代をいつ削除するか。', '内容の暗号化や除外には影響しません'],
            ['暗号化と2名の鍵', '保存内容を暗号化し、復旧できる人を決めます。', '常に有効。容量節約を使っても弱まりません'],
            ['保存物の照合', 'R2へ保存した暗号文が壊れていないことの確認です。', '復旧できることの証明ではありません'],
            ['復旧の検証', '実際に復号・復元して初めて確認できます。', 'バックアップ成功とは別の検証です']] as $row) {
            echo '<tr><th scope="row">' . esc_html($row[0]) . '</th><td>' . esc_html($row[1]) . '</td><td>' . esc_html($row[2]) . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private const STATUS_LABELS = ['queued' => '開始待ち', 'capturing' => '取得中', 'uploading' => '転送・照合中', 'complete' => '完了', 'failed' => '失敗', 'cancelled' => '取り消し済み'];

    private static function statusLabel(string $status): string { return self::STATUS_LABELS[$status] ?? '不明'; }

    /**
     * The fields of the operation state that the page may show, and nothing else: no guard, token, launch id, owner,
     * path or name. Counts, sizes and durations are non-negative integers; times keep their fixed UTC format.
     */
    public static function publicState(?array $state): ?array
    {
        if ($state === null || !is_string($state['status'] ?? null) || !isset(self::STATUS_LABELS[$state['status']])) { return null; }
        $public = ['status' => $state['status'], 'message' => is_string($state['message'] ?? null) ? mb_substr($state['message'], 0, 600) : ''];
        foreach (['completed', 'total'] as $key) { $public[$key] = is_int($state[$key] ?? null) && $state[$key] >= 0 ? $state[$key] : 0; }
        if (($state['consistencyMode'] ?? null) === 'online') { $public['consistencyMode'] = 'online'; }
        foreach (['startedAt', 'completedAt'] as $key) {
            if (is_string($state[$key] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $state[$key])) { $public[$key] = $state[$key]; }
        }
        if (is_string($state['snapshotId'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $state['snapshotId'])) { $public['snapshotId'] = $state['snapshotId']; }
        if (is_array($state['sharing'] ?? null)) { $public['sharing'] = self::integers($state['sharing']); }
        if (is_array($state['progress'] ?? null)) {
            $progress = self::integers($state['progress']);
            if (in_array($state['progress']['phase'] ?? null, ['prepare', 'scan', 'sharing-preflight', 'capture'], true)) { $progress['phase'] = $state['progress']['phase']; }
            if (is_string($state['progress']['updatedAt'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $state['progress']['updatedAt'])) { $progress['updatedAt'] = $state['progress']['updatedAt']; }
            $public['progress'] = $progress;
        }
        $measurements = $state['measurements'] ?? null;
        if (is_array($measurements)) {
            $public['measurements'] = [];
            if (is_array($measurements['capture'] ?? null)) { $public['measurements']['capture'] = self::integers($measurements['capture']); }
            if (is_array($measurements['transfer'] ?? null)) {
                foreach (['sharedPacks', 'pages', 'newPacks', 'otherObjects', 'publication'] as $kind) {
                    if (is_array($measurements['transfer'][$kind] ?? null)) { $public['measurements']['transfer'][$kind] = self::integers($measurements['transfer'][$kind]); }
                }
            }
        }
        return $public;
    }

    /** The cancel form applies to a stopped, unfinished run: the same rule the script uses after each confirmed check. */
    public static function cancellable(?array $public): bool
    {
        return $public !== null && in_array($public['status'], ['capturing', 'uploading', 'failed'], true);
    }

    /** Non-negative integers under plain letter-only names; everything else is dropped. */
    private static function integers(array $values): array
    {
        $result = [];
        foreach ($values as $name => $value) {
            if (is_string($name) && preg_match('/\A[A-Za-z]{1,40}\z/D', $name) && is_int($value) && $value >= 0) { $result[$name] = $value; }
        }
        return $result;
    }

    private static function sharingSummary(array $sharing): string
    {
        $labels = [['newBytes', 'newPacks', '新規保存（詰め直しを含む）'], ['reusedBytes', 'reusedPacks', '再利用'], ['repackedBytes', 'repackedPacks', '除外境界の詰め直し']];
        $parts = [];
        foreach ($labels as [$bytes, $packs, $label]) {
            $parts[] = $label . ' ' . number_format(max(0, (int) ($sharing[$bytes] ?? 0))) . ' バイト / ' . max(0, (int) ($sharing[$packs] ?? 0)) . ' パック';
        }
        return implode('　', $parts);
    }

    private static function error(string $message): void
    {
        echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html($message) . '</p></div>';
    }
}
