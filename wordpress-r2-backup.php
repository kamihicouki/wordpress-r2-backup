<?php
/**
 * Plugin Name: WordPress R2 Backup
 * Description: このWordPressのファイルとデータベースを公開鍵で暗号化し、Cloudflare R2へバックアップします。
 * Version: 0.7.1
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
if (defined('WRB_ACTIVE_RELEASE_LOADED')) {
    wp_die('WordPress R2 Backup の別の版が有効です。同時に有効化せず、配備手順に従って切り替えてください。');
}
define('WRB_ACTIVE_RELEASE_LOADED', true);
require_once __DIR__ . '/vendor/autoload.php';
\WordPressR2Backup\WordPressAdmin::register(__FILE__);
