<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\{RestorePage, WordPressRestoreAdmin};

final class WordPressRestoreAdminTest extends TestCase
{
    protected function tearDown(): void { Fixture::cleanup(); }

    public function testAViewerIsLocalOnlyByPeerAddressAndLocalHostName(): void
    {
        self::assertTrue(WordPressRestoreAdmin::viewerLocal(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost:8080']));
        self::assertTrue(WordPressRestoreAdmin::viewerLocal(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'site.local']));
        self::assertFalse(WordPressRestoreAdmin::viewerLocal(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_HOST' => 'localhost', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1']), 'A forwarding header proves nothing');
        self::assertFalse(WordPressRestoreAdmin::viewerLocal(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'www.example.com']), 'A local reverse proxy in front of a public name');
    }

    public function testTheSharedPageCarriesTheScopeAndNoSecretInput(): void
    {
        $html = RestorePage::render(['endpoint' => '/api', 'tokenName' => 'csrf', 'token' => 'TOKEN', 'reauth' => true, 'prefix' => 'wp_"><script>']);
        self::assertStringContainsString('既存のデータベース、別のサーバーのデータベース、マルチサイト', $html, 'The supported range is stated on the screen');
        self::assertStringNotContainsString('<script>', $html, 'Values are escaped');
        self::assertStringContainsString('name="reauthPassword"', $html);
        foreach (['動作確認用のURL', 'サイトアドレス (URL)', 'WordPress アドレス (URL)', 'URLの優先順位と、公開前に必要な設定', 'AGE-SECRET-KEY-1', 'restore-admin'] as $text) { self::assertStringContainsString($text, $html); }
        self::assertStringContainsString('name="urlMode" value="keep" checked', $html);
        preg_match_all('/<span aria-hidden="true">(\d)<\/span>/', $html, $steps);
        self::assertSame(['1', '2', '3', '4'], $steps[1], 'Visible step numbers run without a gap');
        foreach (['接続セット', '起動コピー', '世代', '復元名', 'DBへ'] as $jargon) { self::assertStringNotContainsString($jargon, $html, $jargon); }
        self::assertStringNotContainsString('value="AGE-SECRET', $html);
        self::assertSame(0, preg_match('/<input[^>]*type="password"[^>]*value=/', $html), 'A password field never carries a value');
        self::assertStringContainsString('&lt;取込用パスワード&gt;', $html, 'The preparation template has placeholders only');
    }

    /** The screen once: with no storage the one notice, with storage the management tools folded away below the form. */
    public function testTheRestoreTabShowsOneNoticeAndFoldsTheManagementTools(): void
    {
        $script = Fixture::temp() . '/view.php';
        file_put_contents($script, str_replace('__AUTOLOAD__', var_export(dirname(__DIR__) . '/vendor/autoload.php', true), <<<'CODE'
<?php
namespace {
function current_user_can($c) { return true; }
function is_multisite() { return false; }
function is_ssl() { return true; }
function admin_url($p = '') { return '/wp-admin/' . $p; }
function wp_create_nonce($a) { return 'nonce'; }
function esc_url($u) { return (string) $u; }
function esc_attr($u) { return (string) $u; }
function add_query_arg($a, $b = null, $c = null) { return '/link'; }
function wp_nonce_field($a, $b, $c = true) {}
function submit_button($t, $k = '', $n = '', $w = true) { echo '<button>' . $t . '</button>'; }
require __AUTOLOAD__;
$render = new \ReflectionMethod(\WordPressR2Backup\LiveAdminView::class, 'restore'); $render->setAccessible(true);
$capture = static function ($settings) use ($render) { ob_start(); $render->invoke(null, $settings); return ob_get_clean(); };
echo json_encode(['none' => $capture(null), 'set' => $capture(['accountId' => 'x'])], JSON_UNESCAPED_UNICODE);
}
CODE
));
        $process = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        $views = json_decode((string) $output, true);
        self::assertIsArray($views, $output . $error);
        self::assertSame(1, substr_count($views['none'], '未設定'), 'One notice');
        self::assertStringContainsString('data-r2-file', $views['none'], 'A connection file can be selected without saved settings');
        self::assertStringNotContainsString('未設定', $views['set']);
        self::assertStringContainsString('<details class="wrb-panel wrb-r-admin-tools">', $views['set'], 'Closed by default');
        self::assertGreaterThan(strpos($views['set'], 'id="wrb-restore"'), strpos($views['set'], 'wrb-r-admin-tools'), 'Below the form');
        self::assertStringNotContainsString('世代', $views['set']);
    }

    /** The admin entry against a small stand-in for WordPress, in its own process. */
    public function testAdminRequestsNeedCapabilityNonceAndAPasswordCheckBoundToThisUser(): void
    {
        $state = Fixture::temp();
        $script = Fixture::temp() . '/admin.php';
        file_put_contents($script, str_replace(['__AUTOLOAD__', '__STATE__'], [var_export(dirname(__DIR__) . '/vendor/autoload.php', true), var_export($state, true)], <<<'CODE'
<?php
namespace {
define('ABSPATH', sys_get_temp_dir() . '/wrb-no-such-site/');
define('WRB_RESTORE_STATE_DIR', __STATE__);
$GLOBALS['t'] = []; $GLOBALS['caps'] = true; $GLOBALS['nonce'] = true; $GLOBALS['uid'] = 5; $GLOBALS['session'] = 'one'; $GLOBALS['checks'] = 0; $GLOBALS['ssl'] = false;
function current_user_can($c) { return $GLOBALS['caps']; }
function is_multisite() { return false; }
function is_ssl() { return $GLOBALS['ssl']; }
function wp_die($m = '', $t = '', $a = []) { throw new \RuntimeException('die:' . ($a['response'] ?? 0)); }
function check_ajax_referer($a, $f) { if (!$GLOBALS['nonce']) { throw new \RuntimeException('die:nonce'); } }
function nocache_headers() {}
function wp_unslash($v) { return $v; }
function get_current_user_id() { return $GLOBALS['uid']; }
function wp_get_session_token() { return $GLOBALS['session']; }
function wp_get_current_user() { return (object) ['ID' => $GLOBALS['uid'], 'user_pass' => 'hash']; }
function wp_check_password($p, $h, $i) { $GLOBALS['checks']++; return $p === 'correct'; }
function get_transient($k) { return $GLOBALS['t'][$k] ?? false; }
function set_transient($k, $v, $e) { $GLOBALS['t'][$k] = $v; }
function delete_transient($k) { unset($GLOBALS['t'][$k]); }
function get_option($k, $d = null) { return $d; }
function update_option($k, $v, $a = null) { return true; }
function home_url($p = '') { return 'https://example.invalid' . $p; }
function wp_send_json_success($b) { throw new \RuntimeException('ok:' . json_encode($b)); }
function wp_send_json_error($b, $c) { throw new \RuntimeException('err:' . $c . ':' . json_encode($b, JSON_UNESCAPED_UNICODE)); }
require __AUTOLOAD__;
$call = static function (array $post) { $_POST = $post; try { \WordPressR2Backup\WordPressRestoreAdmin::ajax(); return 'none'; } catch (\RuntimeException $e) { return $e->getMessage(); } };
$out = [];
$GLOBALS['caps'] = false; $out['noCapability'] = $call(['command' => 'status']); $GLOBALS['caps'] = true;
$GLOBALS['nonce'] = false; $out['badNonce'] = $call(['command' => 'status']); $GLOBALS['nonce'] = true;
$_SERVER['REMOTE_ADDR'] = '192.0.2.1'; $_SERVER['HTTP_HOST'] = 'example.invalid';
$out['httpKey'] = $call(['command' => 'preflight', 'identity' => 'synthetic identity']);
$out['httpConnection'] = $call(['command' => 'snapshots', 'r2Connection' => 'synthetic profile']);
$GLOBALS['ssl'] = true;
for ($i = 0; $i < 6; $i++) { $last = $call(['command' => 'start', 'reauthPassword' => 'wrong']); }
$out['lockedAfterFailures'] = $last; $out['checks'] = $GLOBALS['checks'];
$before = \WordPressR2Backup\WordPressRestoreAdmin::actor(); $GLOBALS['session'] = 'two'; $otherSession = \WordPressR2Backup\WordPressRestoreAdmin::actor(); $GLOBALS['uid'] = 6; $otherUser = \WordPressR2Backup\WordPressRestoreAdmin::actor();
$out['actorsDiffer'] = count(array_unique([$before, $otherSession, $otherUser])) === 3;
echo json_encode($out, JSON_UNESCAPED_UNICODE);
}
CODE
));
        $process = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        $result = json_decode((string) $output, true);
        self::assertIsArray($result, $output . $error);
        self::assertSame('die:403', $result['noCapability']);
        self::assertSame('die:nonce', $result['badNonce']);
        self::assertStringContainsString('"field":"identity"', $result['httpKey']);
        self::assertStringContainsString('"field":"r2Connection"', $result['httpConnection']);
        self::assertSame(5, $result['checks'], 'Past the limit the password is no longer even checked');
        self::assertStringStartsWith('err:400', $result['lockedAfterFailures']);
        self::assertStringContainsString('reauthPassword', $result['lockedAfterFailures']);
        self::assertTrue($result['actorsDiffer'], 'A plan belongs to one user and one login session');
    }
}
