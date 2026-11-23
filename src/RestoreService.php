<?php
declare(strict_types=1);

namespace WordPressR2Backup;

/**
 * The one restore flow behind both web entries: checks, binding, a background worker and its status. It adds no conversion or
 * verification of its own; Restorer, the database clients and the site setups do that work. It knows nothing about WordPress,
 * and the caller supplies the environment and an actor string identifying the authenticated user and session.
 */
final class RestoreService
{
    private const PLAN_LIFETIME = 900;
    public const SCOPE = '新しい場所へ復元するだけです。今のサイトとデータベースは変更せず、公開サイトの切り替えも行いません。既存のデータベース、別のサーバーのデータベース、マルチサイトへの復元には対応していません。';

    private array $env;
    private string $base;
    private string $library;
    private ?array $probe = null;
    private ?bool $workerSignals = null;

    /**
     * @param array $env base, age, source (type repository|direct-r2-file|direct-r2), connections, protectedPaths, activeDatabase, viewerLocal, php
     */
    public function __construct(array $env)
    {
        $this->env = $env + ['connections' => [], 'protectedPaths' => [], 'activeDatabase' => null, 'viewerLocal' => false, 'php' => null];
        $this->library = dirname(__DIR__);
        try { $this->base = FileSafety::privateDirectory($env['base']); }
        catch (\RuntimeException $error) { throw new RestoreError('復元の専用領域を所有者専用のフォルダーとして確認できません。'); }
        foreach ($this->env['protectedPaths'] as $path) {
            $real = realpath($path);
            if ($real !== false && ($this->inside($real, $this->base) || $this->inside($this->base, $real))) { throw new RestoreError('復元の専用領域が公開領域または稼働中のサイトと重なっています。'); }
        }
        foreach (['jobs', 'plans'] as $directory) {
            $path = $this->base . '/' . $directory;
            if (@lstat($path) === false && !@mkdir($path, 0700)) { throw new RestoreError('復元の専用領域に書き込めません。'); }
            FileSafety::privateDirectory($path);
        }
    }

    /** @return array<string,array> */
    public static function loadConnections(?string $file): array
    {
        if ($file === null || $file === '') { return []; }
        $invalid = new RestoreError('接続セットの登録ファイルを検証できません。所有者専用（0600）のJSONを確認してください。');
        try {
            FileSafety::assertRegularFile($file); clearstatcache(true, $file); $stat = lstat($file);
            if (realpath($file) !== $file || ($stat['mode'] & 0777) !== 0600 || $stat['size'] > 65536 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) { throw $invalid; }
            $data = json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        } catch (RestoreError $error) { throw $error; } catch (\Throwable $error) { throw $invalid; }
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['connections'] ?? null)) { throw $invalid; }
        $connections = [];
        foreach ($data['connections'] as $set) {
            if (!is_array($set) || array_diff(array_keys($set), ['id', 'label', 'workspace', 'socket', 'mysql', 'mysqldump', 'database', 'inspector', 'importer', 'runner']) !== []) { throw $invalid; }
            foreach (['id', 'label', 'workspace', 'socket', 'mysql', 'mysqldump'] as $field) { if (!is_string($set[$field] ?? null) || $set[$field] === '') { throw $invalid; } }
            if (!preg_match('/\A[a-z0-9-]{1,32}\z/D', $set['id']) || isset($connections[$set['id']]) || realpath($set['workspace']) !== $set['workspace'] || strpos($set['socket'], $set['workspace'] . '/') !== 0) { throw $invalid; }
            $connections[$set['id']] = $set;
        }
        return $connections;
    }

    /** What the screen may show before anything is requested; never an account password. */
    public function context(): array
    {
        $connections = [];
        foreach ($this->env['connections'] as $id => $set) {
            $connections[] = ['id' => $id, 'label' => $set['label'], 'socket' => $set['socket'], 'database' => $set['database'] ?? null, 'accountsRegistered' => isset($set['inspector'], $set['importer'], $set['runner'])];
        }
        return ['scope' => self::SCOPE, 'connections' => $connections, 'sourceConfigured' => ($this->env['source']['type'] ?? 'unconfigured') !== 'unconfigured', 'startup' => $this->startupCapability(), 'latest' => $this->latestStatus(), 'suggestedName' => $this->suggestedName(), 'suggestedPort' => $this->suggestedPort()];
    }

    /** Short enough to type when confirming, and unused so the common case needs no thought. */
    private function suggestedName(): string
    {
        $name = 'restore-' . date('md');
        for ($number = 2, $candidate = $name; @lstat($this->jobDirectory($candidate)) !== false && $number < 100; ++$number) { $candidate = $name . '-' . $number; }
        return $candidate;
    }

    private function suggestedPort(): ?int
    {
        for ($port = 8181; $port < 8200; ++$port) {
            $server = @stream_socket_server('tcp://127.0.0.1:' . $port, $code, $message);
            if ($server !== false) { fclose($server); return $port; }
        }
        return null;
    }

    public function snapshots(array $secrets = []): array
    {
        try {
            $repository = $this->repository($secrets);
            $records = $repository->listCandidates();
        } catch (RestoreError $error) { throw $error; }
        catch (\Throwable $error) { throw new RestoreError('バックアップ一覧を取得できません。復元情報ファイルと、サーバーから保存先へ接続できるかを確認してください。', 'r2Connection'); }
        $list = [];
        foreach ($records as $record) {
            $list[] = ['snapshotId' => $record['snapshotId'], 'valid' => !empty($record['valid']), 'verified' => $record['verified'], 'savedAt' => $record['savedAt'] ?? null];
        }
        usort($list, static fn (array $left, array $right): int => strcmp($right['savedAt'] ?? '', $left['savedAt'] ?? '')
            ?: strcmp($right['snapshotId'], $left['snapshotId']));
        return $list;
    }

    public function preflight(array $input, array $secrets, string $actor): array
    {
        $lock = $this->runLock();
        try {
            $this->removeExpiredPlans();
            $review = $this->review($input, $secrets);
            if ($review['ok']) {
                $key = bin2hex(random_bytes(32));
                $this->writeJson($this->planFile($review['planHash']), ['actor' => hash('sha256', $actor), 'expiresAt' => time() + self::PLAN_LIFETIME, 'secretKey' => $key, 'secretMac' => $this->secretMac($review['plan'], $secrets, $key)]);
            }
            unset($review['plan'], $review['facts'], $review['manifest']);
            return $review;
        } finally { $this->release($lock); }
    }

    /** The server repeats every check with the values sent now. A changed request or environment no longer matches the confirmed plan. */
    public function start(array $input, array $secrets, string $planHash, string $confirmation, string $actor): array
    {
        $lock = $this->runLock();
        try {
            $planFile = preg_match('/\A[a-f0-9]{64}\z/D', $planHash) ? $this->planFile($planHash) : null;
            $record = $planFile !== null && is_file($planFile) ? $this->readJson($planFile) : null;
            if ($record === null || ($record['expiresAt'] ?? 0) < time() || !hash_equals((string) ($record['actor'] ?? ''), hash('sha256', $actor))) {
                throw new RestoreError('確認の期限が切れたか、別のセッションで作られた確認です。事前検査をやり直してください。');
            }
            try { $secretsMatch = hash_equals((string) ($record['secretMac'] ?? ''), $this->secretMac(RestorePlan::normalize($input, $this->env['connections']), $secrets, (string) ($record['secretKey'] ?? ''))); }
            catch (RestoreError $error) { $secretsMatch = false; }
            if (!$secretsMatch) { throw new RestoreError('事前検査の後に内容または環境が変わりました。事前検査をやり直してください。'); }
            $review = $this->review($input, $secrets);
            if (!$review['ok'] || !hash_equals($planHash, (string) ($review['planHash'] ?? ''))) {
                throw new RestoreError('事前検査の後に内容または環境が変わりました。事前検査をやり直してください。');
            }
            $plan = $review['plan'];
            if ($confirmation !== $plan['name']) { throw new RestoreError('確認欄へ復元の名前を正確に入力してください。', 'confirmName'); }
            if (!unlink($planFile)) { throw new RestoreError('確認を使用済みにできないため開始しません。'); }
            $job = $this->jobDirectory($plan['name']);
            if (!@mkdir($job, 0700)) { throw new RestoreError('この名前はすでに使われています。', 'name'); }
            $sqliteTemp = null;
            if ($plan['database'] === 'sqlite') {
                try { $sqliteTemp = FileSafety::createPrivateDirectory($job . '/sqlite-tmp'); }
                catch (\RuntimeException $error) { throw new RestoreError('SQLite照合用の一時フォルダーを用意できません。復元先の権限と空き容量を確認し、新しい復元名を指定してください。'); }
            }
            $selected = $this->selectedSource($secrets);
            $source = $selected; unset($source['profile']);
            $connection = null;
            if ($plan['database'] === 'mysql') { $connection = $this->env['connections'][$plan['mysql']['connection']]; unset($connection['inspector'], $connection['importer'], $connection['runner']); }
            $this->writeJson($job . '/job.json', ['plan' => $plan, 'root' => $review['facts']['root'], 'sourceUrl' => $review['facts']['sourceUrl'], 'source' => $source, 'age' => $this->env['age'], 'connection' => $connection, 'startupAvailable' => $this->startupCapability()['available']]);
            $this->writeJson($job . '/status.json', ['name' => $plan['name'], 'state' => 'queued', 'phase' => 'queued', 'capturedAt' => $review['manifest']['capturedAt'], 'startedAt' => time(), 'updatedAt' => time(), 'results' => self::initialResults($plan)]);
            $this->writeBytes($this->base . '/latest', $plan['name']);
            $frame = ['mode' => 'restore', 'identity' => $secrets['identity'], 'adminHash' => $plan['adminLogin'] === null ? null : RestorePlan::portableHash($secrets['adminPassword']), 'accounts' => $plan['database'] === 'mysql' ? $this->accounts($plan, $secrets) : null, 'profile' => $selected['profile'] ?? null];
            $this->launch($plan['name'], $frame, 'worker.lock', $sqliteTemp);
            return $this->status($plan['name']);
        } finally { $this->release($lock); }
    }

    public function status(?string $name = null): array
    {
        $name = $name ?? $this->latestName();
        if ($name === null) { return ['state' => 'none']; }
        $job = $this->jobDirectory($name);
        if (!is_file($job . '/status.json')) { throw new RestoreError('指定の復元が見つかりません。'); }
        $status = $this->readJson($job . '/status.json');
        if ($status === null) { throw new RestoreError('復元の状態を読み取れません。'); }
        if (self::possiblyInterrupted($status)) { $status = $this->resolveWorkerStatus($job, $status); }
        $status['site'] = $this->siteStatus($job, $status);
        $status['phaseLabel'] = self::phaseLabel((string) ($status['phase'] ?? ''));
        return $status;
    }

    private static function possiblyInterrupted(array $status): bool
    {
        return $status['state'] === 'running' || ($status['state'] === 'queued' && time() - ($status['startedAt'] ?? 0) > 20);
    }

    private function resolveWorkerStatus(string $job, array $observed): array
    {
        $path = $job . '/worker.lock';
        FileSafety::assertNoLinks($path);
        $lock = @fopen($path, 'c+b');
        if ($lock === false) { throw new RestoreError('復元処理の状態を確認できません。'); }
        try {
            // A worker can publish its final state after the first read and before releasing its exclusive lock.
            if (!flock($lock, LOCK_SH | LOCK_NB)) { return $observed; }
            $status = $this->readJson($job . '/status.json');
            if ($status === null) { throw new RestoreError('復元の状態を読み取れません。'); }
            if (!self::possiblyInterrupted($status)) { return $status; }
            $requested = is_file($job . '/stop-requested');
            $this->removeCredentials($job);
            $current = self::failCurrent($status['results'] ?? []);
            $status = array_merge($status, ['state' => $requested ? 'stopped' : 'interrupted', 'results' => $current, 'updatedAt' => time(),
                'message' => $requested ? '依頼により処理を停止しました。' : '処理が途中で終了しました。同じデータベースへは再取込しません。新しい名前と新しいデータベースでやり直してください。']);
            $this->writeJson($job . '/status.json', $status);
            return $status;
        } finally { fclose($lock); }
    }

    public function stop(string $name): array
    {
        $job = $this->jobDirectory($name);
        if (!is_file($job . '/status.json')) { throw new RestoreError('指定の復元が見つかりません。'); }
        if ($this->held($job . '/worker.lock')) {
            $this->writeBytes($job . '/stop-requested', '1');
            $this->signal($job . '/worker.lock');
            $this->waitFor(function () use ($job): bool { return !$this->held($job . '/worker.lock'); }, 5);
        }
        return $this->status($name);
    }

    public function startSite(string $name): array
    {
        $lock = $this->runLock();
        try {
            $job = $this->jobDirectory($name); $status = $this->status($name); $capability = $this->startupCapability();
            if (!$capability['available']) { throw new RestoreError($capability['reason']); }
            if (($status['startup']['state'] ?? null) !== 'prepared') { throw new RestoreError('確認用サイトの準備が完了していません。'); }
            if ($this->held($job . '/site.lock')) { return $status; }
            if ($this->activeSite() !== null) { throw new RestoreError('別の確認用サイトが動作中です。先に停止してください。'); }
            if (is_file($job . '/site-stop-requested')) { unlink($job . '/site-stop-requested'); }
            $this->writeJson($job . '/site-state.json', ['state' => 'starting', 'updatedAt' => time()]);
            $this->writeBytes($this->base . '/active-site', $name);
            $this->launch($name, ['mode' => 'serve'], 'site.lock');
            return $this->status($name);
        } finally { $this->release($lock); }
    }

    public function stopSite(string $name): array
    {
        $job = $this->jobDirectory($name);
        if ($this->held($job . '/site.lock')) {
            $this->writeBytes($job . '/site-stop-requested', '1');
            $this->signal($job . '/site.lock');
            $this->waitFor(function () use ($job): bool { return !$this->held($job . '/site.lock'); }, 6);
        }
        return $this->status($name);
    }

    /** Only the startup stage again, from the verified original. The original database is not imported again. */
    public function retryStartup(string $name, array $input, array $secrets): array
    {
        $lock = $this->runLock();
        try {
            $job = $this->jobDirectory($name); $status = $this->status($name); $data = $this->readJson($job . '/job.json');
            if ($data === null || !in_array($status['state'] ?? '', ['startup-failed'], true)) { throw new RestoreError('起動準備をやり直せる状態ではありません。'); }
            if ($data['plan']['database'] !== 'sqlite') { throw new RestoreError('MySQL／MariaDBの起動用DBは再利用できません。新しい接続セットと復元名で最初から実行してください。'); }
            $prefix = is_string($input['tablePrefix'] ?? null) && trim($input['tablePrefix']) !== '' ? trim($input['tablePrefix']) : $data['plan']['tablePrefix'];
            if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $prefix)) { throw new RestoreError('表の接頭辞は英数字と下線で指定してください。', 'tablePrefix'); }
            $this->assertWorkerSignals();
            $createAdmin = ($data['plan']['adminLogin'] ?? null) !== null;
            if ($createAdmin && (!is_string($secrets['adminPassword'] ?? null) || strlen($secrets['adminPassword']) < 12)) { throw new RestoreError('確認用ログインのパスワードを12文字以上で入力してください。', 'adminPassword'); }
            $data['plan']['tablePrefix'] = $prefix;
            $this->writeJson($job . '/job.json', $data);
            $status = array_merge($this->readJson($job . '/status.json'), ['state' => 'queued', 'phase' => 'site-copy', 'startedAt' => time(), 'updatedAt' => time()]);
            unset($status['message'], $status['startup'], $status['restoredUrls']);
            $this->writeJson($job . '/status.json', $status);
            $this->launch($name, ['mode' => 'startup', 'adminHash' => $createAdmin ? RestorePlan::portableHash($secrets['adminPassword']) : null], 'worker.lock');
            return $this->status($name);
        } finally { $this->release($lock); }
    }

    public function isActive(?array $status = null): bool
    {
        $status = $status ?? $this->status();
        return in_array($status['state'] ?? '', ['queued', 'running'], true) || in_array($status['site']['state'] ?? '', ['starting', 'running'], true);
    }

    public static function initialResults(array $plan): array
    {
        $results = [];
        foreach (['archive' => 'バックアップの検証', 'database' => 'データベースの照合', 'files' => 'ファイルの照合', 'media' => $plan['purpose'] === 'full' ? 'メディアの配置' : 'メディアの検証（配置なし）', 'startup' => '確認用サイトの準備'] as $id => $label) {
            $results[$id] = ['label' => $label, 'state' => 'pending'];
        }
        return $results;
    }

    public static function failCurrent(array $results): array
    {
        foreach ($results as $id => $item) { if (($item['state'] ?? '') === 'running') { $results[$id]['state'] = 'failed'; } }
        return $results;
    }

    public static function phaseLabel(string $phase): string
    {
        return ['queued' => '開始待ち', 'starting' => '開始準備', 'prepare' => 'バックアップ全体を検証しています', 'database-import' => 'データベースを取り込んでいます', 'database-verify' => 'データベースを照合しています', 'files-publish' => 'ファイルを配置しています', 'files-verify' => 'ファイルを照合しています', 'permissions-verify' => '権限を照合しています', 'cleanup' => '作業領域を整理しています', 'site-copy' => '確認用サイトを準備しています', 'done' => '完了'][$phase] ?? '処理中';
    }

    /** @return array{available:bool,reason:string,guidance:string} */
    public function startupCapability(): array
    {
        if (!($this->env['isolationSupported'] ?? LocalSiteServer::platformAvailable())) {
            return ['available' => false, 'reason' => 'このサーバーではOSの通信隔離（macOSのsandbox-exec）を使えないため、サイトを起動しません。', 'guidance' => '復元・照合と確認用サイトの準備までは行えます。動作確認は、独立復旧キットのWeb画面を対応環境のmacOSで実行してください。隔離なしで起動する代替はありません。'];
        }
        if (empty($this->probe()['pcntl'])) {
            return ['available' => false, 'reason' => 'PHP CLIのシグナル機能（pcntl）を確認できないため、サイトを起動しません。', 'guidance' => 'pcntlを含むPHP CLIを指定してください。'];
        }
        return ['available' => true, 'reason' => '', 'guidance' => ''];
    }

    /** Runs the same checks for preflight and start. No check text carries an exception's message, so values cannot leak through it. */
    private function review(array $input, array $secrets): array
    {
        $checks = [];
        $add = static function (string $id, string $label, string $state, string $message = '', ?string $field = null) use (&$checks): void { $checks[] = ['id' => $id, 'label' => $label, 'state' => $state, 'message' => $message, 'field' => $field]; };
        try { $plan = RestorePlan::normalize($input, $this->env['connections']); }
        catch (RestoreError $error) { $add('request', '入力内容', 'failed', $error->getMessage(), $error->field()); return ['ok' => false, 'checks' => $checks]; }
        $add('request', '入力内容', 'ok');
        $active = $this->latestName();
        if ($active !== null && in_array($this->status($active)['state'] ?? '', ['queued', 'running'], true)) { $add('exclusive', '他の復元との重なり', 'failed', '別の復元が実行中です。完了または停止を待ってください。'); return ['ok' => false, 'checks' => $checks]; }
        $add('exclusive', '他の復元との重なり', 'ok');
        $facts = ['base' => $this->base];
        $manifest = null;
        $identity = $secrets['identity'] ?? '';
        if (!is_string($identity) || $identity === '' || strlen($identity) > 65536) { $add('key', '秘密鍵', 'failed', '秘密鍵を入れてください。保存はしません。', 'identity'); }
        else {
            try {
                $stream = fopen('php://memory', 'w+b'); fwrite($stream, $identity); rewind($stream);
                try { $manifest = (new Restorer(new AgeProcess($this->env['age']), $this->repository($secrets)))->describe($plan['snapshot'], $stream); }
                finally { fclose($stream); }
                $add('key', '秘密鍵でバックアップを開けるか', 'ok');
            } catch (\Throwable $error) { $add('key', '秘密鍵でバックアップを開けるか', 'failed', '選んだバックアップを開けません。この保存先の秘密鍵か確認してください。', 'identity'); }
        }
        $root = null;
        if ($manifest !== null) {
            if ($manifest['manifestVersion'] !== 4) { $add('format', 'バックアップの形式', 'failed', 'このバックアップは古い形式のため、この画面では復元できません。独立キットのコマンドで復元してください。', 'snapshot'); $manifest = null; }
            else {
                $names = [];
                foreach ($manifest['roots'] as $entry) { if ($entry['type'] === 'directory') { $names[] = $entry['name']; } }
                $root = $plan['root'] ?? (in_array('r0001', $names, true) ? 'r0001' : null);
                if ($root === null || !in_array($root, $names, true)) { $add('format', 'WordPressの保存ルート', 'failed', 'バックアップに指定のルートがありません。詳細設定で指定してください。', 'root'); $root = null; }
                else { $add('format', 'バックアップの形式', 'ok'); $facts += ['receipt' => $manifest['receiptSha256'], 'root' => $root, 'sourceUrl' => rtrim((string) $manifest['siteUrl'], '/')]; }
            }
        }
        $job = $this->jobDirectory($plan['name']);
        if (@lstat($job) !== false) { $add('destination', '保存先', 'failed', 'この名前はすでに使われています。別の名前にしてください。既存の保存先は上書きしません。', 'name'); }
        else { $add('destination', '保存先', 'ok', $job); }
        if ($manifest !== null) { $this->checkDisk($plan, $manifest, $add); }
        if ($plan['database'] === 'mysql') { $facts['connection'] = $this->checkMysql($plan, $secrets, $add); }
        else { $this->checkSqliteRuntime($add); }
        if ($manifest !== null && $root !== null) { $this->checkStartup($plan, $facts['sourceUrl'], $secrets, $add); }
        $this->checkPort($plan, $add);
        try { $this->assertWorkerSignals(); }
        catch (RestoreError $error) { $add('worker', '復元処理の起動', 'failed', $error->getMessage()); }
        $ok = true;
        foreach ($checks as $check) { if ($check['state'] === 'failed') { $ok = false; } }
        $review = ['ok' => $ok, 'checks' => $checks];
        if ($ok) {
            $review += ['plan' => $plan, 'facts' => $facts, 'manifest' => $manifest, 'planHash' => RestorePlan::hash($plan, $facts), 'summary' => $this->summary($plan, $facts, $manifest, $job), 'startup' => $this->startupCapability()];
        }
        return $review;
    }

    /**
     * Binds every value the start will use without storing any. The keyed result stays in the owner-only plan record; the public plan
     * hash never depends on a password. An account set the registered file supplies is bound too, so editing that file expires the check.
     */
    private function secretMac(array $plan, array $secrets, string $key): string
    {
        $bound = ['identity' => $secrets['identity'] ?? null, 'r2Connection' => $secrets['r2Connection'] ?? null, 'adminPassword' => $plan['adminLogin'] !== null ? ($secrets['adminPassword'] ?? null) : null];
        if ($plan['database'] === 'mysql') {
            $set = $this->env['connections'][$plan['mysql']['connection']];
            foreach (['inspector', 'importer', 'runner'] as $role) { $bound[$role] = $set[$role] ?? ['user' => $secrets[$role . 'User'] ?? null, 'password' => $secrets[$role . 'Password'] ?? null]; }
        }
        return hash_hmac('sha256', json_encode($bound, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), $key);
    }

    private function summary(array $plan, array $facts, array $manifest, string $job): array
    {
        $summary = ['name' => $plan['name'], 'snapshot' => $plan['snapshot'], 'sourceUrl' => $facts['sourceUrl'], 'capturedAt' => $manifest['capturedAt'], 'counts' => ['files' => $manifest['counts']['files'], 'bytes' => $manifest['counts']['fileBytes'], 'tables' => $manifest['database']['tableCount']],
            'destination' => $job, 'purpose' => $plan['purpose'], 'database' => $plan['database'] === 'mysql' ? ['mode' => 'データベースサーバー（MySQL／MariaDB）', 'original' => $plan['mysql']['database'], 'running' => $plan['mysql']['runningDatabase'], 'connection' => $this->env['connections'][$plan['mysql']['connection']]['label']] : ['mode' => 'かんたん（SQLite）', 'original' => $job . '/original-db/site.sqlite', 'running' => $job . '/running-db/site.sqlite'],
            'targetUrl' => $plan['targetUrl'], 'publicationUrls' => RestorePlan::publicationUrls($plan), 'urlMode' => RestorePlan::urlMode($plan),
            'previewUrl' => $plan['previewUrl'], 'aliases' => $plan['aliases'], 'adminLogin' => $plan['adminLogin']];
        if (($manifest['consistency']['mode'] ?? null) === 'online') { $summary['consistency'] = $manifest['consistency']; }
        return $summary;
    }

    private function checkDisk(array $plan, array $manifest, callable $add): void
    {
        $free = @disk_free_space($this->base);
        if ($free === false) { $add('disk', '空き容量', 'warning', '空き容量を確認できません。'); return; }
        $files = (float) $manifest['counts']['fileBytes']; $database = (float) $manifest['database']['size'];
        $migration = ($plan['targetUrl'] !== null || $plan['adminLogin'] !== null) ? $database : 0;
        if ($plan['purpose'] === 'full' ? $free < ($files + $database) * 2 + $migration : $free < $database * 3 + $migration) { $add('disk', '空き容量', 'failed', '保存先の空き容量が不足しています。'); return; }
        if ($plan['purpose'] !== 'full' && $free < ($files + $database) * 2) { $add('disk', '空き容量', 'warning', '空き容量に余裕がありません。メディア以外の容量は実行時に確定します。'); return; }
        $add('disk', '空き容量', 'ok');
    }

    private function checkSqliteRuntime(callable $add): void
    {
        $probe = $this->probe();
        if (empty($probe['pdo_sqlite']) || empty($probe['sqlite3'])) { $add('database', 'データベース（SQLite）', 'failed', 'PHP CLIにpdo_sqliteとsqlite3が必要です。'); return; }
        $add('database', 'データベース（SQLite）', 'ok', '新しいファイルを作成します。');
    }

    /** @return string digest of the non-secret connection facts bound into the plan */
    private function checkMysql(array $plan, array $secrets, callable $add): string
    {
        $connection = $this->env['connections'][$plan['mysql']['connection']];
        $digest = hash('sha256', json_encode([$connection['workspace'], $connection['socket'], $connection['mysql'], $connection['mysqldump'], $plan['mysql']], JSON_THROW_ON_ERROR));
        $probe = $this->probe();
        if (empty($probe['pdo_mysql']) || empty($probe['mysqli'])) { $add('database', 'MySQL／MariaDB', 'failed', 'PHP CLIにpdo_mysqlとmysqliが必要です。'); return $digest; }
        foreach (['database', 'runningDatabase'] as $schema) {
            if ($this->env['activeDatabase'] !== null && $plan['mysql'][$schema] === $this->env['activeDatabase']) { $add('database', 'MySQL／MariaDB', 'failed', '稼働中のサイトが使うDBは指定できません。', 'mysqlDatabase'); return $digest; }
        }
        try {
            $accounts = $this->accounts($plan, $secrets);
            $digest = hash('sha256', $digest . json_encode([$accounts['inspector']['user'], $accounts['importer']['user'], $accounts['runner']['user']], JSON_THROW_ON_ERROR));
        } catch (RestoreError $error) { $add('database', 'MySQL／MariaDB', 'failed', $error->getMessage(), $error->field()); return $digest; }
        $files = [];
        try {
            $files = self::writeCredentials($connection, $plan, $accounts);
            $client = new MysqlClient($connection['mysql'], $connection['mysqldump'], $files['defaults'], $connection['workspace'], 60);
            foreach ([$plan['mysql']['database'], $plan['mysql']['runningDatabase']] as $schema) { $client->assertImportReady($schema, []); }
            $add('database', 'MySQL／MariaDB', 'ok', '元データ用と確認用のデータベースが空で、アカウントはそれぞれのデータベースだけに限定されています。');
        } catch (RestoreError $error) { $add('database', 'MySQL／MariaDB', 'failed', $error->getMessage());
        } catch (\Throwable $error) {
            $add('database', 'MySQL／MariaDB', 'failed', '接続できないか、準備の条件を満たしていません。準備手順の、空のデータベース2つ・専用アカウントの権限・サーバーの起動設定を確認してください。', 'connection');
        } finally { self::removeFiles($files['all'] ?? []); }
        return $digest;
    }

    private function checkStartup(array $plan, string $sourceUrl, array $secrets, callable $add): void
    {
        $password = $secrets['adminPassword'] ?? '';
        if ($plan['adminLogin'] !== null && (!is_string($password) || strlen($password) < 12)) { $add('startup', '確認用サイトの設定', 'failed', '確認用ログインのパスワードを12文字以上で決めてください。保存はしません。', 'adminPassword'); return; }
        $administrator = $plan['adminLogin'] === null ? [] : ['login' => $plan['adminLogin'], 'passwordHash' => '$P$B' . str_repeat('.', 30)];
        $target = $plan['targetUrl'] ?? $sourceUrl;
        $aliases = $plan['targetUrl'] === null ? [] : $plan['aliases'];
        $job = $this->base . '/.check-' . bin2hex(random_bytes(8));
        try {
            if ($plan['targetUrl'] !== null) { SiteUrlRewriter::replacements($sourceUrl, $target, $aliases); }
            mkdir($job, 0700);
            try {
                if ($plan['database'] === 'sqlite') {
                    mkdir($job . '/running-db', 0700);
                    (new SqliteSiteSetup($this->library . '/dependencies/sqlite-database-integration'))->preflight($job . '/site', $job . '/running-db/site.sqlite', $sourceUrl, $plan['previewUrl'], $plan['tablePrefix'], $administrator, $aliases, $job . '/restore', $job . '/original-db/site.sqlite', $target);
                }
            } finally { @rmdir($job . '/running-db'); }
            $capability = $this->startupCapability();
            if (!$capability['available']) { $add('startup', '確認用サイトの起動', 'warning', $capability['reason'] . $capability['guidance']); return; }
            $socket = $plan['database'] === 'mysql' ? $this->env['connections'][$plan['mysql']['connection']]['socket'] : null;
            LocalSiteServer::preflight($job . '/site', $socket === null ? $job . '/running-db/site.sqlite' : null, $plan['previewUrl'], $this->library . '/local-router.php', $socket, $this->phpBinary(), $target);
            $add('startup', '確認用サイトの設定', 'ok', '動作確認には、運用URLとは別の確認用URLを使います。');
        } catch (\Throwable $error) {
            $add('startup', '確認用サイトの設定', 'failed', 'アドレスの設定を受け付けられません。新しいアドレスが元のサイトのアドレスと重なっていないか確認してください。', 'targetUrl');
        } finally { @rmdir($job); }
    }

    private function checkPort(array $plan, callable $add): void
    {
        $server = @stream_socket_server('tcp://' . substr($plan['previewUrl'], strlen('http://')), $code, $message);
        if ($server === false) { $add('port', '確認用のポート番号', 'failed', 'このポート番号は使用中です。詳細設定で別の番号を指定してください。', 'previewPort'); return; }
        fclose($server);
        $add('port', '確認用のポート番号', 'ok');
    }

    /** @return array{inspector:array,importer:array,runner:array} */
    private function accounts(array $plan, array $secrets): array
    {
        $set = $this->env['connections'][$plan['mysql']['connection']];
        $accounts = [];
        foreach (['inspector' => '確認用', 'importer' => '取込用', 'runner' => '起動用'] as $role => $label) {
            $account = $set[$role] ?? ['user' => $secrets[$role . 'User'] ?? '', 'password' => $secrets[$role . 'Password'] ?? ''];
            $user = $account['user'] ?? ''; $password = $account['password'] ?? '';
            if (!is_string($user) || !preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/D', $user)) { throw new RestoreError($label . 'アカウントの名前を確認してください。', $role . 'User'); }
            if (!is_string($password) || ($role !== 'inspector' && $password === '') || preg_match('/["\\\\\x00-\x1f\x7f]/', $password)) { throw new RestoreError($label . 'アカウントのパスワードを確認してください。引用符・バックスラッシュ・制御文字は使えません。', $role . 'Password'); }
            $accounts[$role] = ['user' => $user, 'password' => $password];
        }
        if (count(array_unique(array_column($accounts, 'user'))) !== 3) { throw new RestoreError('確認用・取込用・起動用には別々のアカウントを指定してください。', 'importerUser'); }
        return $accounts;
    }

    /**
     * Writes the client settings MysqlClient requires, owner-only, inside the registered workspace. Callers remove them when finished.
     * @return array{defaults:string,all:list<string>}
     */
    public static function writeCredentials(array $connection, array $plan, array $accounts): array
    {
        $body = static function (array $account) use ($connection): string {
            return "[client]\nuser=\"" . $account['user'] . "\"\n" . ($account['password'] === '' ? '' : 'password="' . $account['password'] . "\"\n") . 'socket="' . $connection['socket'] . "\"\n";
        };
        $files = [
            $connection['workspace'] . '/.wrb-restore-inspect-' . bin2hex(random_bytes(8)) . '.cnf' => $body($accounts['inspector']),
            $connection['workspace'] . '/importer-' . $plan['mysql']['database'] . '.cnf' => $body($accounts['importer']),
            $connection['workspace'] . '/importer-' . $plan['mysql']['runningDatabase'] . '.cnf' => $body($accounts['runner']),
        ];
        $written = [];
        try {
            foreach ($files as $path => $contents) {
                $stream = @fopen($path, 'xb');
                if ($stream === false) { throw new RestoreError('作業領域に同じDB名の接続設定が残っています。使い終えた設定を確認してください。', 'mysqlDatabase'); }
                $written[] = $path;
                try { if (!chmod($path, 0600) || fwrite($stream, $contents) !== strlen($contents)) { throw new \RuntimeException('Cannot write private client settings.'); } }
                finally { fclose($stream); }
            }
        } catch (\Throwable $error) { self::removeFiles($written); throw $error; }
        return ['defaults' => array_key_first($files), 'all' => $written];
    }

    public static function removeFiles(array $paths): void
    {
        foreach ($paths as $path) { if (@lstat($path) !== false) { @unlink($path); } }
    }

    private function removeCredentials(string $job): void
    {
        $record = $job . '/credential-files.json';
        if (!is_file($record)) { return; }
        $files = $this->readJson($record);
        self::removeFiles(is_array($files) ? array_values(array_filter($files, 'is_string')) : []);
        @unlink($record);
    }

    private function selectedSource(array $secrets): array
    {
        $body = $secrets['r2Connection'] ?? '';
        if ($body === '') { return $this->env['source']; }
        try { return ['type' => 'direct-r2', 'profile' => DirectR2Repository::connectionFromContents($body)]; }
        catch (\Throwable $error) { throw new RestoreError('R2接続ファイルの形式を確認してください。', 'r2Connection'); }
    }

    private function repository(array $secrets = [])
    {
        return self::openRepository($this->selectedSource($secrets));
    }

    public static function openRepository(array $source)
    {
        if ($source['type'] === 'repository') { return LocalRepository::forRecovery($source['path'], $source['unit']); }
        if ($source['type'] === 'direct-r2-file') { return DirectR2Repository::fromFile($source['path']); }
        if ($source['type'] === 'direct-r2') { return new DirectR2Repository($source['profile']); }
        throw new RestoreError('保存先を確認できません。');
    }

    private function siteStatus(string $job, array $status): array
    {
        $data = $this->readJson($job . '/job.json');
        $plan = $data['plan'] ?? null;
        if ($plan === null) { return ['state' => 'none']; }
        $capability = $this->startupCapability();
        $site = ['adminLogin' => $plan['adminLogin'], 'previewUrl' => $plan['previewUrl'], 'targetUrl' => $plan['targetUrl'], 'state' => 'not-started', 'reason' => '', 'guidance' => '', 'openable' => false, 'adminOpenable' => false, 'response' => 'not-checked'];
        $site['publication'] = RestorePlan::publicationUrls($plan) + ['mode' => RestorePlan::urlMode($plan), 'recordedHome' => $data['sourceUrl'] ?? null];
        if (!$capability['available']) { return array_merge($site, ['state' => 'unavailable', 'reason' => $capability['reason'], 'guidance' => $capability['guidance']]); }
        $state = is_file($job . '/site-state.json') ? $this->readJson($job . '/site-state.json') : null;
        if ($this->held($job . '/site.lock')) {
            $site['state'] = 'running';
            $probe = $this->probeSite($plan['previewUrl'], $this->env['viewerLocal'] === true);
            $site['response'] = $probe['response'];
            $site['adminOpenable'] = $probe['adminOpenable'];
        } elseif (is_array($state)) {
            $site['state'] = ($state['state'] ?? '') === 'starting' && time() - ($state['updatedAt'] ?? 0) < 15 ? 'starting' : (($state['state'] ?? '') === 'failed' ? 'failed' : 'stopped');
            if ($site['state'] === 'failed') { $site['reason'] = '確認用サイトを起動できませんでした。ポート番号を確認してください。'; }
        }
        if ($this->env['viewerLocal'] !== true) {
            $port = (int) parse_url($plan['previewUrl'], PHP_URL_PORT);
            $site['reason'] = $site['reason'] !== '' ? $site['reason'] : '確認用アドレスはサーバー自身だけを指すため、このパソコンからは直接開けません。';
            $site['guidance'] = 'SSHトンネル（ssh -L ' . $port . ':127.0.0.1:' . $port . ' <サーバー>）を接続してから、このパソコンのブラウザーで確認用アドレスを開いてください。サーバー内の応答だけでは、画面の確認は完了扱いにしません。';
        } elseif ($site['state'] === 'running' && $site['response'] === 'responding') { $site['openable'] = true; }
        return $site;
    }

    /** A fixed request to this machine's own listener; the address comes from the validated plan, never from a request. */
    private function probeSite(string $previewUrl, bool $checkAdmin): array
    {
        $parts = parse_url($previewUrl);
        $read = static function (string $path) use ($parts): ?string {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $parts['port'], $code, $message, 1.0);
            if ($socket === false) { return null; }
            stream_set_timeout($socket, 3);
            fwrite($socket, 'GET ' . $path . " HTTP/1.0\r\nHost: 127.0.0.1:" . $parts['port'] . "\r\nConnection: close\r\n\r\n");
            $response = stream_get_contents($socket, 8192); fclose($socket);
            return is_string($response) ? $response : null;
        };
        $isolation = $read('/__wrb_isolation_check');
        $result = ['response' => 'not-responding', 'adminOpenable' => false];
        if ($isolation === null || strpos($isolation, '"localOnly":true') === false) { return $result; }
        $ok = static fn (?string $response): bool => $response !== null && preg_match('~\AHTTP/1\.[01] 2[0-9]{2} ~', $response) === 1;
        if (!$ok($read('/'))) { $result['response'] = 'error'; return $result; }
        $result['response'] = 'responding';
        if (!$checkAdmin) { return $result; }
        $admin = $read('/wp-admin/');
        if ($ok($admin)) { $result['adminOpenable'] = true; }
        elseif ($admin !== null && preg_match('~\AHTTP/1\.[01] 30[12378] ~', $admin) === 1
            && preg_match('~^Location: ([^\r\n]+)~mi', explode("\r\n\r\n", $admin, 2)[0], $match) === 1
            && preg_match('~\A(?:' . preg_quote($previewUrl, '~') . ')?/wp-login\.php(?:[?#]|\z)~', trim($match[1])) === 1) {
            $result['adminOpenable'] = $ok($read('/wp-login.php'));
        }
        return $result;
    }

    private function latestStatus(): array
    {
        return $this->latestName() === null ? ['state' => 'none'] : $this->status();
    }

    private function latestName(): ?string
    {
        $path = $this->base . '/latest';
        if (!is_file($path)) { return null; }
        $name = (string) file_get_contents($path);
        return preg_match('/\A[a-z0-9-]{1,40}\z/D', $name) ? $name : null;
    }

    private function activeSite(): ?string
    {
        $path = $this->base . '/active-site';
        if (!is_file($path)) { return null; }
        $name = (string) file_get_contents($path);
        return preg_match('/\A[a-z0-9-]{1,40}\z/D', $name) && $this->held($this->jobDirectory($name) . '/site.lock') ? $name : null;
    }

    private function jobDirectory(string $name): string
    {
        if (!preg_match('/\A[a-z0-9-]{1,40}\z/D', $name)) { throw new RestoreError('復元名が正しくありません。', 'name'); }
        return $this->base . '/jobs/' . $name;
    }

    private function planFile(string $hash): string { return $this->base . '/plans/' . $hash . '.json'; }

    private function removeExpiredPlans(): void
    {
        foreach (glob($this->base . '/plans/*.json') ?: [] as $file) {
            $record = $this->readJson($file);
            if ($record === null || ($record['expiresAt'] ?? 0) < time()) { @unlink($file); }
        }
    }

    /** The worker is detached before it receives anything, so a slow restore holds no web request. Secrets go only through its stdin. */
    private function launch(string $name, array $frame, string $lockName, ?string $sqliteTemp = null): void
    {
        $job = $this->jobDirectory($name); $php = $this->phpBinary();
        $body = json_encode($frame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        // POSIX shells may replace an asynchronous command's fd 0 with /dev/null before applying redirections.
        // Save the input pipe first, then restore it for the worker and close the duplicate there.
        $command = 'exec 3<&0 4>&- 5>&- 6>&- 7>&- 8>&- 9>&-; ' . escapeshellarg($php) . ' ' . escapeshellarg($this->library . '/restore-worker.php') . ' ' . escapeshellarg($this->base) . ' ' . escapeshellarg($name) . ' <&3 3<&- >/dev/null 2>&1 & echo $!';
        $environment = ['PATH' => '/usr/bin:/bin:/usr/sbin:/sbin', 'LANG' => 'C.UTF-8'];
        foreach (['HOME', 'TMPDIR'] as $variable) { $value = getenv($variable); if (is_string($value) && $value !== '') { $environment[$variable] = $value; } }
        // Some SQLite builds select the temporary directory before the PHP script starts.
        if ($sqliteTemp !== null) { $environment['SQLITE_TMPDIR'] = $sqliteTemp; }
        if (!isset($environment['HOME']) && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) { $environment['HOME'] = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? ''); }
        $process = @proc_open(['/bin/sh', '-c', $command], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $job, $environment);
        if (!is_resource($process)) { $this->launchFailed($job); throw new RestoreError('バックグラウンド処理を起動できません。'); }
        $pid = trim((string) stream_get_contents($pipes[1])); fclose($pipes[1]);
        // The shell has exited; the worker alone holds the read end. A bounded non-blocking write can never tie this request to it.
        stream_set_blocking($pipes[0], false);
        $offset = 0; $length = strlen($body); $deadline = microtime(true) + 10; $sent = true;
        while ($offset < $length) {
            $count = @fwrite($pipes[0], substr($body, $offset, 65536));
            if ($count === false || microtime(true) > $deadline) { $sent = false; break; }
            $offset += $count;
            if ($count === 0) { usleep(5000); }
        }
        fclose($pipes[0]); $body = ''; proc_close($process);
        if (!$sent || !preg_match('/\A[1-9][0-9]*\z/D', $pid) || !$this->waitFor(function () use ($job, $lockName): bool { return $this->workerAnswered($job, $lockName); }, 10)) {
            $this->launchFailed($job);
            throw new RestoreError('バックグラウンド処理を開始できません。PHP CLIの設定を確認してください。');
        }
    }

    /** Acquiring a lock alone does not acknowledge startup. The worker must write its first state. */
    private function workerAnswered(string $job, string $lockName): bool
    {
        if ($lockName === 'site.lock') { return ($this->readJson($job . '/site-state.json')['state'] ?? 'starting') !== 'starting'; }
        return ($this->readJson($job . '/status.json')['state'] ?? 'queued') !== 'queued';
    }

    private function launchFailed(string $job): void
    {
        $status = $this->readJson($job . '/status.json') ?? [];
        if (($status['state'] ?? '') === 'queued') { $this->writeJson($job . '/status.json', array_merge($status, ['state' => 'failed', 'updatedAt' => time(), 'message' => 'バックグラウンド処理を開始できませんでした。'])); }
    }

    private function phpBinary(): string
    {
        return is_string($this->env['php']) && $this->env['php'] !== '' ? $this->env['php'] : LiveRunner::phpBinary();
    }

    private function assertWorkerSignals(): void
    {
        if ($this->workerSignals === null) {
            $code = 'exit(function_exists("pcntl_async_signals") && function_exists("pcntl_signal") ? 0 : 1);';
            $process = @proc_open([$this->phpBinary(), '-r', $code], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            $this->workerSignals = is_resource($process) && proc_close($process) === 0;
        }
        if (!$this->workerSignals) {
            throw new RestoreError('復元に使うPHP CLIでシグナル拡張（pcntl）が有効になっていません。復元サーバー側で有効にしてください。');
        }
    }

    /** The web process's own extensions do not tell what the CLI has, nor what the isolated server keeps with no ini file. */
    private function probe(): array
    {
        if ($this->probe !== null) { return $this->probe; }
        $this->probe = [];
        try {
            $code = 'echo json_encode(["pcntl"=>function_exists("pcntl_async_signals"),"pdo_sqlite"=>extension_loaded("pdo_sqlite"),"sqlite3"=>extension_loaded("sqlite3"),"pdo_mysql"=>extension_loaded("pdo_mysql"),"mysqli"=>extension_loaded("mysqli")]);';
            $process = @proc_open([$this->phpBinary(), '-n', '-r', $code], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (is_resource($process)) {
                $decoded = json_decode((string) stream_get_contents($pipes[1]), true); fclose($pipes[1]); proc_close($process);
                if (is_array($decoded)) { $this->probe = $decoded; }
            }
        } catch (\Throwable $error) { $this->probe = []; }
        return $this->probe;
    }

    private function runLock()
    {
        $path = $this->base . '/run.lock'; FileSafety::assertNoLinks($path);
        $lock = @fopen($path, 'c+b');
        if ($lock === false || !chmod($path, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            throw new RestoreError('別の操作を処理中です。少し待ってからやり直してください。');
        }
        return $lock;
    }

    /** Explicit: a descriptor inherited by a child would otherwise keep the lock after this request closes it. */
    private function release($lock): void { flock($lock, LOCK_UN); fclose($lock); }

    private function held(string $path): bool
    {
        if (!is_file($path)) { return false; }
        $lock = @fopen($path, 'rb');
        if ($lock === false) { return false; }
        try {
            if (flock($lock, LOCK_SH | LOCK_NB)) { flock($lock, LOCK_UN); return false; }
            return true;
        } finally { fclose($lock); }
    }

    /** The worker writes its pid into the lock it holds, so a stale number is never signalled. */
    private function signal(string $lockPath): void
    {
        $pid = trim((string) @file_get_contents($lockPath));
        if (!preg_match('/\A[1-9][0-9]*\z/D', $pid) || !$this->held($lockPath)) { return; }
        if (function_exists('posix_kill')) { posix_kill((int) $pid, 15); return; }
        $process = @proc_open(['/bin/kill', '-TERM', $pid], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (is_resource($process)) { proc_close($process); }
    }

    private function waitFor(callable $condition, int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($condition()) { return true; }
            usleep(50000);
        } while (microtime(true) < $deadline);
        return false;
    }

    private function inside(string $parent, string $child): bool { return $child === $parent || strpos($child, rtrim($parent, '/') . '/') === 0; }

    private function readJson(string $path): ?array
    {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) { return null; }
        $data = json_decode($bytes, true, 32);
        return is_array($data) ? $data : null;
    }

    private function writeJson(string $path, array $data): void { $this->writeBytes($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }

    private function writeBytes(string $path, string $bytes): void
    {
        FileSafety::assertNoLinks($path);
        $temporary = $path . '.' . bin2hex(random_bytes(6));
        $stream = fopen($temporary, 'xb');
        if ($stream === false) { throw new RestoreError('復元の専用領域に書き込めません。'); }
        try { if (!chmod($temporary, 0600) || fwrite($stream, $bytes) !== strlen($bytes)) { throw new RestoreError('復元の専用領域に書き込めません。'); } }
        finally { fclose($stream); }
        if (!rename($temporary, $path)) { @unlink($temporary); throw new RestoreError('復元の専用領域に書き込めません。'); }
    }
}
