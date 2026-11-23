<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';
umask(0077);
set_error_handler(static function (int $severity, string $message): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException('A filesystem or runtime operation failed.', 0, $severity);
});
$output = null;
$stage = '引数検査';

function wrbCopyTree(string $source, string $destination): void
{
    if (is_link($source)) { throw new RuntimeException('Links cannot be packaged.'); }
    if (is_dir($source)) {
        if (!mkdir($destination, 0700)) { throw new RuntimeException('Cannot create a kit directory.'); }
        foreach (scandir($source) as $entry) {
            if ($entry !== '.' && $entry !== '..') { wrbCopyTree($source . '/' . $entry, $destination . '/' . $entry); }
        }
    } elseif (is_file($source)) {
        $input = fopen($source, 'rb');
        $file = fopen($destination, 'xb');
        try {
            if (stream_copy_to_stream($input, $file) !== filesize($source) || !fflush($file) || !\WordPressR2Backup\FileSafety::sync($file)) {
                throw new RuntimeException('Cannot copy a kit file.');
            }
        } finally { fclose($input); fclose($file); }
        chmod($destination, 0600);
    } else { throw new RuntimeException('A kit source is unavailable.'); }
}

function wrbRemoveOwned(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') { wrbRemoveOwned($path . '/' . $entry); }
        }
        rmdir($path);
    } elseif (@lstat($path) !== false) { unlink($path); }
}

function wrbRun(array $command, string $directory, array $environment = []): void
{
    $log = tmpfile();
    if ($log === false) { throw new RuntimeException('Cannot create a build stream.'); }
    try {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, $directory, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start the build process.'); }
        if (proc_close($process) !== 0) { throw new RuntimeException('The build dependency command failed.'); }
    } finally { fclose($log); }
}

try {
    if ($argc === 2 && $argv[1] === '--help') {
        fwrite(STDOUT, "php build-kit.php --output <new-Git-external-directory> --age-archive <age-v1.3.2-darwin-arm64.tar.gz> [--composer <composer.phar>]\n");
        exit(0);
    }
    $options = [];
    for ($i = 1; $i < $argc; $i += 2) {
        $name = $argv[$i];
        if (!in_array($name, ['--output', '--age-archive', '--composer'], true) || isset($options[$name]) || !isset($argv[$i + 1]) || $argv[$i + 1] === '' || str_contains($argv[$i + 1], "\0")) {
            throw new InvalidArgumentException('Invalid build arguments.');
        }
        $options[$name] = $argv[$i + 1];
    }
    if (!isset($options['--output'], $options['--age-archive'])) { throw new InvalidArgumentException('Required build arguments missing.'); }
    $stage = '公式age配布物のSHA-256照合';
    $archive = realpath($options['--age-archive']);
    $sha256 = 'e2020b073c44f692685a24d6abc378817eb81ffaaf49fd0531ef8565f767f2f5';
    if ($archive === false || !is_file($archive) || hash_file('sha256', $archive) !== $sha256) { throw new RuntimeException('Official archive SHA-256 mismatch.'); }
    if (PHP_OS_FAMILY !== 'Darwin' || php_uname('m') !== 'arm64') {
        $stage = '確認済みDarwin arm64実行環境の検査';
        throw new RuntimeException('The kit build is verified only on Darwin arm64.');
    }
    $stage = 'Git外の新規出力先の検査';
    $requested = $options['--output'];
    $parent = realpath(dirname($requested));
    $name = basename($requested);
    if ($parent === false || !is_dir($parent) || in_array($name, ['', '.', '..'], true)) { throw new RuntimeException('Invalid output parent.'); }
    for ($ancestor = $parent; ; $ancestor = dirname($ancestor)) {
        if (@lstat($ancestor . '/.git') !== false) { throw new RuntimeException('The kit output must be outside Git.'); }
        if ($ancestor === dirname($ancestor)) { break; }
    }
    $candidate = $parent . '/' . $name;
    if (!@mkdir($candidate, 0700)) { throw new RuntimeException('The kit output must be new.'); }
    $output = $candidate;
    $plugin = dirname(__DIR__);
    $stage = '復旧処理と形式仕様の同梱';
    $header = file_get_contents($plugin . '/wordpress-r2-backup.php', false, null, 0, 8192);
    if (!is_string($header) || preg_match('/^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)\r?$/m', $header, $version) !== 1) {
        throw new RuntimeException('The plugin release version is unavailable.');
    }
    mkdir($output . '/library', 0700);
    foreach (['composer.json', 'composer.lock', 'LICENSE', 'THIRD-PARTY-NOTICES.md', 'src', 'dependencies', 'assets', 'local-router.php', 'restore-worker.php'] as $entry) { wrbCopyTree($plugin . '/' . $entry, $output . '/library/' . $entry); }
    wrbCopyTree(__DIR__ . '/recover.php', $output . '/recover.php');
    wrbCopyTree($plugin . '/local-router.php', $output . '/local-router.php');
    wrbCopyTree(__DIR__ . '/recover-web.php', $output . '/recover-web.php');
    wrbCopyTree(__DIR__ . '/README.md', $output . '/README.md');
    mkdir($output . '/docs', 0700);
    wrbCopyTree($plugin . '/docs/format-and-design.md', $output . '/docs/format-and-design.md');
    wrbCopyTree($plugin . '/docs/v4-format.md', $output . '/docs/v4-format.md');
    mkdir($output . '/dependencies', 0700);
    mkdir($output . '/.age-extract', 0700);
    $stage = '照合済み公式ageの展開';
    wrbRun(['/usr/bin/tar', '-xzf', $archive, '-C', $output . '/.age-extract'], $output);
    mkdir($output . '/dependencies/age', 0700);
    wrbCopyTree($output . '/.age-extract/age/age', $output . '/dependencies/age/age');
    chmod($output . '/dependencies/age/age', 0700);
    wrbCopyTree($output . '/.age-extract/age/LICENSE', $output . '/dependencies/age/LICENSE');
    wrbRemoveOwned($output . '/.age-extract');
    $stage = 'Composer本番用依存物と読み込み設定の生成';
    $composer = realpath($options['--composer'] ?? '/opt/homebrew/bin/composer');
    if ($composer === false || !is_file($composer)) { throw new RuntimeException('Composer is unavailable.'); }
    wrbRun([PHP_BINARY, $composer, 'install', '--no-dev', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress', '--prefer-dist', '--optimize-autoloader'], $output . '/library', [
        'PATH' => dirname(PHP_BINARY) . ':/usr/bin:/bin',
        'COMPOSER_HOME' => $output . '/.composer-home',
        'COMPOSER_CACHE_DIR' => $output . '/.composer-cache',
        'COMPOSER_ROOT_VERSION' => $version[1],
    ]);
    foreach (['.composer-home', '.composer-cache'] as $entry) { if (is_dir($output . '/' . $entry)) { wrbRemoveOwned($output . '/' . $entry); } }
    $dependencies = [
        'releaseVersion' => $version[1],
        'readableManifestVersions' => [1, 2, 3, 4],
        'ageVersion' => '1.3.2', 'packagedPlatform' => 'darwin-arm64',
        'archiveSha256' => $sha256,
        'ageExecutableSha256' => hash_file('sha256', $output . '/dependencies/age/age'),
        'downloadBase' => 'https://github.com/FiloSottile/age/releases/download/v1.3.2/',
        'unverifiedLinuxAmd64ArchiveSha256' => 'cbe24006683f8eb669266162894b9a522a1af52f2665fbc63a4bb032ed26ac10',
        'phpMinimum' => '7.4', 'phpVerified' => PHP_VERSION, 'extensions' => ['intl', 'mbstring', 'curl', 'openssl', 'dom'],
        'diskSync' => 'PHP fflush plus native fsync when available; no external synchronization program. Directory sync unavailable without native fsync. Host power-loss durability is not guaranteed.',
        'optionalExtensions' => ['mysqli' => 'Required by WordPress during live capture.'],
        'stage' => 'WordPress filtered v4 backup with direct R2 recovery; legacy archive formats remain readable.',
        'syntheticHttpCiphertextMaximumBytes' => 67108864,
        'localR2CiphertextMaximumBytes' => 134217728,
        'localR2PartMaximumBytes' => 16777216,
        'mysqlClients' => 'Separately required. Isolated MySQL 8.0.35, or event-free v4 with MariaDB 10.5.29. Server startup event_scheduler=DISABLED is required.',
        'sqliteRecovery' => ['extensions' => ['pdo', 'pdo_sqlite', 'sqlite3'], 'driverCommit' => 'c156cf1066d1f0fce65ef8769debf0a2480bef65', 'scope' => 'Version 4 snapshots only; a new SQLite file is required. PDO/PDO_SQLite are required for import; SQLite3 is additionally required for the optional running copy.'],
    ];
    file_put_contents($output . '/dependencies/versions.json', json_encode($dependencies, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $hashes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink() || !$file->isFile()) { throw new RuntimeException('Unexpected kit entry.'); }
        $relative = substr($file->getPathname(), strlen($output) + 1);
        $hashes[$relative] = hash_file('sha256', $file->getPathname());
    }
    ksort($hashes, SORT_STRING);
    file_put_contents($output . '/SHA256SUMS.json', json_encode($hashes, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fwrite(STDOUT, json_encode(['kit' => $output, 'files' => count($hashes)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    $output = null;
} catch (Throwable $error) {
    if ($output !== null) { wrbRemoveOwned($output); }
    fwrite(STDERR, $stage . "に失敗しました。既存の出力先は置換しません。\n");
    exit(1);
}
