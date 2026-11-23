<?php
declare(strict_types=1);
namespace WordPressR2Backup;

final class LiveHistoryView
{
    private const SOURCES = ['scheduled' => '定期バックアップ', 'manual' => '手動バックアップ', 'unknown' => '実行方法の記録なし'];
    private const OUTCOMES = ['running' => '実行中', 'complete' => '完了', 'failed' => '失敗', 'interrupted' => '中断', 'skipped' => '見送り', 'cancelled' => '取り消し'];
    private const ACTIVE = ['queued', 'capturing', 'uploading'];

    public static function validId($id): bool
    {
        return is_string($id) && preg_match('/\A[a-f0-9]{64}\z/D', $id) === 1;
    }

    /** A compact public state for the shared admin notification. */
    public static function notice(?array $run): ?array
    {
        if ($run === null || !self::validId($run['id'] ?? null)) { return null; }
        $status = self::outcome($run);
        if (!isset(self::OUTCOMES[$status])) { return null; }
        $source = self::source($run);
        $name = $source === 'unknown' ? 'バックアップ' : self::SOURCES[$source];
        $titles = ['queued' => 'の開始を受け付けました', 'capturing' => 'を実行中', 'uploading' => 'を実行中',
            'complete' => 'が完了しました', 'failed' => 'に失敗しました', 'interrupted' => 'が中断しました',
            'skipped' => 'を見送りました', 'cancelled' => 'を取り消しました'];
        $when = self::date($run['updatedAt'] ?? null);
        return ['id' => $run['id'], 'status' => $status, 'title' => $name . $titles[$run['status']],
            'message' => ($when === null ? '' : $when->format('Y/m/d H:i') . ' · ') . self::result($run)[1]];
    }

    public static function renderNotice(?array $notice, bool $available): void
    {
        $url = LiveAdmin::url('history');
        echo '<div class="wrb-history-overview" data-wrb-history-notice data-history-url="' . esc_url($url)
            . '" data-run-id="' . esc_attr($notice['id'] ?? '') . '" data-outcome="' . esc_attr($notice['status'] ?? '') . '"'
            . ($notice === null ? ' hidden' : '') . '><div><strong data-wrb-history-title>' . esc_html($notice['title'] ?? '')
            . '</strong><p data-wrb-history-message>' . esc_html($notice['message'] ?? '') . '</p></div>';
        echo '<a data-wrb-history-link href="' . esc_url($notice === null ? $url : self::url(['wrb_run' => $notice['id']])) . '">実行の詳細を表示</a></div>';
        echo '<p data-wrb-history-unavailable class="wrb-history-unavailable"' . ($available ? ' hidden' : '')
            . '>実行履歴の最新状態を確認できません。表示中の場合は最後に確認した記録です。</p>';
        echo '<p class="screen-reader-text" role="status" aria-atomic="true" data-wrb-history-announce></p>';
    }

    public static function render(?BackupHistory $history, bool $available, ?array $policy = null): void
    {
        try {
            $rows = $history === null ? [] : $history->recent();
            $id = self::query('wrb_run');
            $requested = self::validId($id) && $history !== null ? $history->find($id) : null;
        } catch (\Throwable $error) {
            $available = false;
        }
        if (!$available) {
            echo '<p class="wrb-alert">実行履歴を読み込めませんでした。画面を開き直し、解消しない場合はバックアップの作業領域を確認してください。</p>';
            return;
        }

        $filters = self::filters();
        $revisionOptions = [];
        foreach ($rows as $row) {
            $revision = $row['settings']['revision'] ?? null;
            if (self::validId($revision)) { $revisionOptions[$revision] = substr($revision, 0, 12); }
        }
        if (isset($filters['wrb_revision'])) { $revisionOptions[$filters['wrb_revision']] = substr($filters['wrb_revision'], 0, 12); }
        $matching = self::matching($rows, $filters);
        $run = $id !== '' ? $requested : ($matching[0] ?? null);
        $requestedPane = self::query('wrb_detail');
        $pane = $requestedPane === 'settings' ? 'settings' : 'log';
        $focus = $requested === null ? '' : (in_array($requestedPane, ['log', 'settings'], true) ? 'tab' : 'heading');

        echo '<p class="wrb-lead">バックアップの結果、実行ログ、当時の設定を確認できます。</p><div class="wrb-history-grid">';
        echo '<section class="wrb-panel wrb-history-list" aria-labelledby="wrb-history-title"><header class="wrb-history-list-head"><h2 id="wrb-history-title">実行一覧</h2>'
            . '<span class="wrb-history-zone">' . esc_html(wp_timezone_string()) . '</span></header>';
        self::renderFilters($filters, $revisionOptions);
        if ($matching === []) {
            echo '<p class="wrb-empty">' . esc_html($rows === [] ? '記録された実行はありません。' : '条件に合う履歴がありません。') . '</p>';
        } else {
            self::renderTable($matching, $filters, $run['id'] ?? '');
        }
        echo '<footer class="wrb-history-count">' . esc_html(number_format_i18n(count($matching))) . '件の記録</footer></section>';

        if ($run !== null) {
            self::renderDetails($run, $filters, $pane, $focus, $policy);
        } else {
            echo '<section class="wrb-panel wrb-empty" aria-label="実行の詳細"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><p>'
                . esc_html($id === '' ? '履歴を選ぶと詳細が表示されます。' : '指定された実行の記録が見つかりません。') . '</p></section>';
        }
        echo '</div>';
    }

    public static function matching(array $rows, array $filters): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            return (!isset($filters['wrb_source']) || self::source($row) === $filters['wrb_source'])
                && (!isset($filters['wrb_outcome']) || self::outcome($row) === $filters['wrb_outcome'])
                && (!isset($filters['wrb_revision']) || ($row['settings']['revision'] ?? null) === $filters['wrb_revision'])
                && (!isset($filters['wrb_schedule_revision']) || (self::schedule($row)['revision'] ?? null) === $filters['wrb_schedule_revision']);
        }));
    }

    private static function filters(): array
    {
        $filters = [];
        $source = self::query('wrb_source');
        $outcome = self::query('wrb_outcome');
        if (isset(self::SOURCES[$source])) { $filters['wrb_source'] = $source; }
        if (isset(self::OUTCOMES[$outcome])) { $filters['wrb_outcome'] = $outcome; }
        foreach (['wrb_revision', 'wrb_schedule_revision'] as $name) {
            $value = self::query($name);
            if (self::validId($value)) { $filters[$name] = $value; }
        }
        return $filters;
    }

    private static function renderFilters(array $filters, array $revisions): void
    {
        echo '<form class="wrb-history-filters" method="get" action="' . esc_url(strtok(LiveAdmin::url('history'), '?')) . '">'
            . '<input type="hidden" name="page" value="' . esc_attr(WordPressAdmin::PAGE) . '"><input type="hidden" name="wrb_view" value="history">';
        if (isset($filters['wrb_schedule_revision'])) {
            echo '<input type="hidden" name="wrb_schedule_revision" value="' . esc_attr($filters['wrb_schedule_revision']) . '">';
        }
        self::select('wrb_source', '設定の種類', ['' => 'すべての種類'] + self::SOURCES, $filters);
        self::select('wrb_revision', '設定の版', ['' => 'すべての版'] + $revisions, $filters);
        self::select('wrb_outcome', '結果', ['' => 'すべての結果'] + self::OUTCOMES, $filters);
        echo '<button class="button" type="submit">絞り込む</button></form>';
        if ($filters !== []) {
            echo '<p class="wrb-history-filter-note">' . (isset($filters['wrb_schedule_revision']) ? '設定画面から選んだ定期設定の記録を表示しています。 ' : '')
                . '<a href="' . esc_url(self::url([])) . '">絞り込みを解除</a></p>';
        }
    }

    private static function select(string $name, string $label, array $choices, array $filters): void
    {
        echo '<label>' . esc_html($label) . '<select name="' . esc_attr($name) . '">';
        foreach ($choices as $value => $description) {
            echo '<option value="' . esc_attr($value) . '"' . (($filters[$name] ?? '') === $value ? ' selected' : '') . '>' . esc_html($description) . '</option>';
        }
        echo '</select></label>';
    }

    private static function renderTable(array $rows, array $filters, string $selected): void
    {
        echo '<div class="wrb-history-table-wrap" tabindex="0" role="region" aria-labelledby="wrb-history-caption"><table class="widefat wrb-history-table">'
            . '<caption class="screen-reader-text" id="wrb-history-caption">バックアップ実行一覧</caption><thead><tr>'
            . '<th scope="col">開始・予定時刻</th><th scope="col">設定</th><th scope="col">結果</th><th scope="col">所要時間</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $selectedRow = ($row['id'] ?? '') === $selected;
            $outcome = self::outcome($row);
            $source = self::SOURCES[self::source($row)];
            $date = self::date($row['scheduledAt'] ?? $row['startedAt'] ?? null);
            $label = ($date === null ? '' : $date->format('Y/m/d H:i') . ' ') . $source . ' ' . (self::OUTCOMES[$outcome] ?? '不明') . 'の詳細';
            $revision = self::validId($row['settings']['revision'] ?? null) ? substr($row['settings']['revision'], 0, 12) : '';
            echo '<tr data-wrb-history-row' . ($selectedRow ? ' class="wrb-history-selected"' : '') . '><td>'
                . self::timeMarkup($date, 'Y/m/d H:i') . '</td><th scope="row"><a data-wrb-run-link'
                . ' href="' . esc_url(self::url($filters + ['wrb_run' => $row['id']])) . '" aria-label="' . esc_attr($label) . '"'
                . ($selectedRow ? ' aria-current="true"' : '') . '>' . esc_html($source) . '</a>'
                . ($revision === '' ? '' : '<small>版 <code>' . esc_html($revision) . '</code></small>')
                . '</th><td>' . self::badge($outcome) . '</td><td>' . esc_html(self::duration($row)) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function renderDetails(array $run, array $filters, string $pane, string $focus, ?array $policy): void
    {
        $outcome = self::outcome($run);
        [$heading, $description, $next, $action] = self::result($run);
        echo '<section class="wrb-panel wrb-history-detail" aria-labelledby="wrb-history-detail-title"><header class="wrb-history-detail-head"><div>'
            . '<p class="wrb-muted">実行の詳細</p><h2 id="wrb-history-detail-title" tabindex="-1"' . ($focus === 'heading' ? ' data-wrb-history-focus' : '') . '>'
            . esc_html(self::SOURCES[self::source($run)]) . '</h2></div>' . self::badge($outcome) . '</header>';
        echo '<dl class="wrb-history-meta">';
        if (isset($run['scheduledAt'])) { self::definition('予定', self::timeMarkup(self::date($run['scheduledAt']), 'Y/m/d H:i:s')); }
        if (isset($run['startedAt']) && $outcome !== 'skipped') { self::definition('開始', self::timeMarkup(self::date($run['startedAt']), 'Y/m/d H:i:s')); }
        if (isset($run['endedAt']) && $outcome !== 'skipped') { self::definition('終了', self::timeMarkup(self::date($run['endedAt']), 'Y/m/d H:i:s')); }
        $elapsed = self::duration($run);
        if ($elapsed !== '—') { self::definition('所要時間', esc_html($elapsed)); }
        echo '</dl><div class="wrb-history-result" data-outcome="' . esc_attr($outcome) . '"><h3>' . esc_html($heading) . '</h3><p>' . esc_html($description) . '</p>';
        if ($next !== '') {
            echo '<p><strong>次にすること</strong> ' . esc_html($next);
            if ($action !== '') {
                echo ' <a href="' . esc_url(LiveAdmin::url($action)) . '">' . ($action === 'privacy' ? '保存範囲と除外設定を開く' : 'バックアップ設定を開く') . '</a>';
            }
            echo '</p>';
        }
        $total = $run['total'] ?? 0;
        $completed = $run['completed'] ?? 0;
        if (($run['status'] ?? '') === 'uploading' && is_int($total) && $total > 0 && is_int($completed)) {
            echo '<div class="wrb-history-progress"><progress aria-label="暗号文の転送・照合状況" max="' . $total . '" value="' . min($total, max(0, $completed))
                . '"></progress><span>' . esc_html(number_format_i18n($completed) . ' / ' . number_format_i18n($total) . ' 件') . '</span></div>';
        }
        echo '</div>';
        if ($outcome === 'complete') { self::renderReceipt($run); }
        $detailQuery = $filters + ['wrb_run' => $run['id']];
        echo '<nav class="wrb-history-detail-nav" aria-label="詳細の表示切替">';
        foreach (['log' => '実行ログ', 'settings' => '実行時の設定'] as $view => $label) {
            echo '<a href="' . esc_url(self::url($detailQuery + ['wrb_detail' => $view])) . '"' . ($view === $pane ? ' aria-current="page"' . ($focus === 'tab' ? ' data-wrb-history-focus' : '') : '') . '>'
                . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if ($pane === 'settings') { self::renderSettings($run, $outcome, $policy); }
        else { self::renderEvents($run['events'] ?? []); }
        echo '</section>';
    }

    private static function renderReceipt(array $run): void
    {
        echo '<dl class="wrb-history-receipt">';
        if (is_int($run['total'] ?? null) && $run['total'] > 0 && ($run['completed'] ?? null) === $run['total']) {
            self::definition('照合済み', esc_html(number_format_i18n($run['total']) . ' 件'));
        }
        if (is_int($run['bytes'] ?? null) && $run['bytes'] > 0) {
            self::definition('R2保存物の総容量', esc_html(size_format($run['bytes'], 1)));
        }
        if (self::validId($run['snapshotId'] ?? null)) {
            self::definition('世代ID', '<code>' . esc_html($run['snapshotId']) . '</code>');
        }
        echo '</dl>';
    }

    private static function renderEvents(array $events): void
    {
        if ($events === []) {
            echo '<p class="wrb-muted">この実行の工程は記録されていません。</p>';
            return;
        }
        echo '<ol class="wrb-history-timeline" aria-label="工程ごとの記録">';
        foreach ($events as $event) {
            echo '<li>' . self::timeMarkup(self::date($event['at'] ?? null), 'm/d H:i:s')
                . '<span>' . esc_html($event['message'] ?? '') . '</span></li>';
        }
        echo '</ol>';
    }

    private static function renderSettings(array $run, string $outcome, ?array $policy): void
    {
        $settings = $run['settings'] ?? null;
        $schedule = self::schedule($run);
        if ($settings === null && $schedule === null) {
            echo '<p class="wrb-muted">この実行では当時の設定が記録されていません。</p>';
            return;
        }
        echo '<p class="wrb-muted">' . ($outcome === 'skipped' ? '実行予定を確認した時点の設定です。' : 'この実行で記録された設定です。') . '</p>';
        if ($settings !== null) {
            echo '<h3>取得と保存</h3><dl class="wrb-history-settings">';
            self::renderStorage($settings);
            echo '</dl><h3>保存範囲と除外ルール</h3><dl class="wrb-history-settings">';
            self::renderScope($settings, $policy);
            echo '</dl>';
        }
        if ($schedule !== null) {
            echo '<h3>定期実行</h3><dl class="wrb-history-settings">';
            self::renderSchedule($schedule);
            echo '</dl>';
        }
        echo '<details class="wrb-history-disclosure"><summary>設定の版と識別子</summary><dl class="wrb-history-settings">';
        self::renderIdentifier('設定の版', $settings['revision'] ?? null);
        self::renderIdentifier('保存範囲の識別子', $settings['policySha256'] ?? null);
        self::renderIdentifier('定期設定の版', $schedule['revision'] ?? null);
        echo '</dl></details>';
    }

    private static function renderIdentifier(string $label, $identifier): void
    {
        if (self::validId($identifier)) {
            self::definition($label, '<code>' . esc_html(substr($identifier, 0, 12)) . '</code>');
        }
    }

    private static function renderStorage(array $settings): void
    {
        self::definition('取得方式', esc_html(self::mode($settings['mode'] ?? '')));
        if (!empty($settings['bucket'])) { self::definition('R2保存先', '<code>' . esc_html($settings['bucket']) . '</code>'); }
        if (is_int($settings['retentionDays'] ?? null)) { self::definition('保存期間', esc_html(number_format_i18n($settings['retentionDays']) . ' 日')); }
        if (is_bool($settings['sharing'] ?? null)) { self::definition('保存容量の節約', $settings['sharing'] ? '有効' : '無効'); }
    }

    private static function renderScope(array $settings, ?array $policy): void
    {
        $preset = ['full' => '完全保存', 'forms' => 'フォーム送信情報を除外', 'custom' => 'カスタム'];
        if (isset($preset[$settings['policyPreset'] ?? ''])) { self::definition('保存範囲', esc_html($preset[$settings['policyPreset']])); }

        // Only use live rule names if the complete policy matches the recorded digest.
        // Keep row matching values and legacy rule targets out of the history view.
        $digest = $settings['policySha256'] ?? null;
        $encoded = $policy === null ? false : json_encode($policy);
        $matching = self::validId($digest) && is_string($encoded) && hash_equals($digest, hash('sha256', $encoded));
        $entries = '';
        $recorded = false;
        foreach (['tables' => 'テーブル', 'fields' => '列', 'paths' => 'ファイル・ディレクトリ', 'categories' => '分類', 'options' => 'サイト設定項目', 'rows' => '行'] as $key => $label) {
            $count = $settings['exclusions'][$key] ?? null;
            if (!is_int($count)) { continue; }
            $recorded = true;
            if ($count <= 0) { continue; }
            $title = esc_html($label . ' ' . number_format_i18n($count) . '件');
            $targets = $matching && $key !== 'rows' && is_array($policy[$key] ?? null)
                ? array_keys($policy[$key], 'exclude', true) : [];
            $targets = array_filter($targets, 'is_string');
            if ($targets === []) {
                $entries .= '<p>' . $title . '</p>';
                continue;
            }
            $entries .= '<details class="wrb-history-disclosure"><summary>' . $title . '</summary><ul>';
            foreach ($targets as $target) { $entries .= '<li><code>' . esc_html($target) . '</code></li>'; }
            $entries .= '</ul>';
            if (count($targets) < $count) { $entries .= '<p class="wrb-muted">一部の対象名は表示できません。</p>'; }
            $entries .= '</details>';
        }
        if ($entries !== '' && !$matching) { $entries .= '<p class="wrb-muted">当時の除外対象の名前は履歴に記録されていません。</p>'; }
        self::definition('除外ルール', $entries !== '' ? $entries : ($recorded ? '0件' : '件数の記録なし'));
        if (($settings['legacy']['scanPublic'] ?? false) === true) {
            self::definition('以前の公開範囲設定', ($settings['legacy']['publicContentOnly'] ?? false) === true ? '公開コンテンツのみ' : '公開範囲の検査あり');
        }
    }

    private static function renderSchedule(array $schedule): void
    {
        $frequency = ['hourly' => '毎時', 'daily' => '毎日', 'weekly' => '毎週'][$schedule['frequency'] ?? ''] ?? '';
        $time = is_string($schedule['time'] ?? null) ? $schedule['time'] : '';
        if ($frequency !== '' && $time !== '') {
            $day = $schedule['frequency'] === 'weekly' ? ([1 => '月', '火', '水', '木', '金', '土', '日'][$schedule['weekday'] ?? 0] ?? '') : '';
            $label = $schedule['frequency'] === 'hourly' ? $frequency . substr($time, -2) . '分' : trim($frequency . ($day === '' ? ' ' : ' ' . $day . '曜日 ') . $time);
            self::definition('実行予定の設定', esc_html($label));
        }
        if (!empty($schedule['timezone'])) { self::definition('タイムゾーン', esc_html($schedule['timezone'])); }
    }

    private static function schedule(array $run): ?array
    {
        return $run['settings']['schedule'] ?? $run['schedule'] ?? null;
    }

    private static function definition(string $label, string $valueHtml): void
    {
        echo '<div><dt>' . esc_html($label) . '</dt><dd>' . $valueHtml . '</dd></div>';
    }

    private static function result(array $run): array
    {
        $status = $run['status'] ?? '';
        $last = [];
        foreach (array_reverse($run['events'] ?? []) as $event) {
            if (($event['code'] ?? '') !== 'failed_cleanup') { $last = $event; break; }
        }
        $results = [
            'queued' => ['開始待ち', 'バックアップの開始を受け付けました。', '', ''],
            'capturing' => ['ファイルとデータベースを取得中', '取得対象の総数を確認中です。', '', ''],
            'uploading' => ['R2に転送・照合中', '暗号化した保存物を転送し、R2上の内容を照合しています。', '', ''],
            'complete' => ['R2の照合まで完了', 'R2上の保存物の照合が完了しました。', '', ''],
            'failed' => ['バックアップが停止しました', '保存物の照合が完了する前に処理が止まりました。', '実行ログで停止した工程を確認してください。', 'backup'],
            'interrupted' => ['バックアップが中断しました', 'この実行の完了を確認できませんでした。', '実行ログと現在の状態を確認してから、転送の再開を試してください。', 'backup'],
            'skipped' => ['予定されていた実行を見送りました', 'この予定では新しいバックアップを開始しませんでした。', '', ''],
            'cancelled' => ['バックアップを取り消しました', 'この実行は取り消されました。', '', ''],
        ];
        $result = $results[$status] ?? ['実行状況を確認できません', '記録された状態を読み取れません。', '', ''];
        if (in_array($status, ['failed', 'interrupted', 'skipped'], true) && isset($last['message'])) { $result[1] = $last['message']; }
        $next = [
            'capture_path_collision' => ['取得対象のファイル名やフォルダー名と参照元を確認し、名前の衝突を解消してから再実行してください。', 'backup'],
            'capture_path_review' => ['対象に含めるファイルと除外するファイルを確認してください。', 'privacy'],
            'capture_unsupported_engine' => ['保守を伴う取得が可能か確認してください。', 'backup'],
            'launch_failed' => ['バックグラウンド実行用のPHPの設定を確認してください。', 'backup'],
            'auto_resume_waiting' => ['', ''],
            'auto_resume_exhausted' => ['R2の接続を確認してください。', 'backup'],
            'transfer_connection' => ['R2への接続を確認し、暗号文の転送を再開してください。', 'backup'],
            'schedule_unavailable' => ['接続設定とサーバー側の定期実行設定を確認してください。', 'backup'],
            'schedule_missed' => ['サーバー側の起動状況と次回の予定を確認してください。', 'backup'],
        ][$last['code'] ?? ''] ?? null;
        if ($next !== null) { [$result[2], $result[3]] = $next; }
        return $result;
    }

    private static function badge(string $outcome): string
    {
        $icons = ['running' => 'update', 'complete' => 'yes-alt', 'failed' => 'warning', 'interrupted' => 'warning', 'cancelled' => 'minus', 'skipped' => 'minus'];
        return '<span class="wrb-history-badge" data-outcome="' . esc_attr($outcome) . '"><span aria-hidden="true" class="dashicons dashicons-'
            . esc_attr($icons[$outcome] ?? 'info-outline') . '"></span>' . esc_html(self::OUTCOMES[$outcome] ?? '不明') . '</span>';
    }

    private static function outcome(array $run): string
    {
        $status = $run['status'] ?? '';
        return in_array($status, self::ACTIVE, true) ? 'running' : $status;
    }

    private static function source(array $run): string
    {
        $source = $run['source'] ?? 'unknown';
        return isset(self::SOURCES[$source]) ? $source : 'unknown';
    }

    private static function mode(string $mode): string
    {
        return ['online' => '保守なし', 'maintenance' => '保守あり'][$mode] ?? '記録なし';
    }

    private static function query(string $key): string
    {
        $value = $_GET[$key] ?? '';
        return is_string($value) && strlen($value) <= 64 ? $value : '';
    }

    private static function url(array $query): string
    {
        $url = LiveAdmin::url('history');
        return $query === [] ? $url : add_query_arg($query, $url);
    }

    private static function date($value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value)) { return null; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value ? $date->setTimezone(wp_timezone()) : null;
    }

    private static function timeMarkup(?\DateTimeImmutable $date, string $format): string
    {
        if ($date === null) { return '—'; }
        return '<time datetime="' . esc_attr($date->format(DATE_ATOM)) . '">' . esc_html($date->format($format)) . '</time>';
    }

    private static function duration(array $run): string
    {
        if (($run['status'] ?? '') === 'skipped') { return '—'; }
        $start = self::date($run['startedAt'] ?? null);
        $end = self::date($run['endedAt'] ?? null);
        if ($start === null || $end === null || $end < $start) { return '—'; }
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        return ($hours > 0 ? $hours . '時間' : '') . ($hours > 0 || $minutes > 0 ? $minutes . '分' : '') . ($seconds % 60) . '秒';
    }
}
