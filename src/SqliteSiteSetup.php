<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use PDO;
use RuntimeException;

/** Prepares a separate local-only running copy. OS network isolation belongs to the caller. */
final class SqliteSiteSetup
{
    private const STORAGE_PROTECTION = ['.htaccess' => 'DENY FROM ALL', 'index.php' => '<?php // Silence is golden.'];
    private string $implementation;

    public function __construct(string $sqlitePluginDirectory)
    {
        FileSafety::assertNoLinks($sqlitePluginDirectory);
        if (realpath($sqlitePluginDirectory) !== $sqlitePluginDirectory || !is_dir($sqlitePluginDirectory)) { throw new RuntimeException('The SQLite implementation directory must be canonical.'); }
        foreach (['wp-includes/sqlite/db.php', 'wp-includes/database/load.php', 'wp-includes/sqlite/class-wp-sqlite-storage.php'] as $file) { FileSafety::assertRegularFile($sqlitePluginDirectory . '/' . $file); }
        $this->implementation = $sqlitePluginDirectory;
    }

    /**
     * Checks everything that depends only on the requested values and paths, so a mistaken running-copy request fails before any recovery work.
     * prepare() repeats its own checks against the restored files; the running copy must stay outside the restored destination.
     */
    public function preflight(string $newSiteRoot, string $newDatabaseFile, string $sourceUrl, string $localUrl, string $tablePrefix, array $administrator, array $sourceAliases, string $destination, string $sourceSqliteFile, ?string $targetUrl = null): void
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $tablePrefix)) { throw new RuntimeException('Invalid WordPress table prefix.'); }
        SiteUrlRewriter::assertUrl($sourceUrl, false); SiteUrlRewriter::assertUrl($localUrl, true);
        if ($targetUrl !== null) { SiteUrlRewriter::assertUrl($targetUrl, false); }
        if (rtrim($sourceUrl, '/') !== rtrim($targetUrl ?? $localUrl, '/') || $sourceAliases !== []) {
            SiteUrlRewriter::replacements(rtrim($sourceUrl, '/'), rtrim($targetUrl ?? $localUrl, '/'), $sourceAliases);
        }
        SiteUrlRewriter::assertAdministrator($administrator);
        FileSafety::assertNoLinks($newSiteRoot); FileSafety::assertNoLinks($newDatabaseFile);
        FileSafety::privateDirectory(dirname($newSiteRoot)); FileSafety::privateDirectory(dirname($newDatabaseFile));
        $this->storageProtection(dirname($newDatabaseFile), false);
        if (@lstat($newSiteRoot) !== false || @lstat($newDatabaseFile) !== false) { throw new RuntimeException('Running copies require unused site and database destinations.'); }
        $others = [];
        foreach ([$destination, $sourceSqliteFile, $this->implementation] as $other) { if ($other !== '' && $other[0] === '/') { $others[] = $other; } }
        if ($this->overlap($newSiteRoot, $newDatabaseFile)) { throw new RuntimeException('The running site and database must be separate.'); }
        foreach ($others as $other) {
            if ($this->overlap($newSiteRoot, $other) || $this->overlap($newDatabaseFile, $other)) { throw new RuntimeException('The running copy must be separate from the restored destination, source database and implementation.'); }
        }
    }

    public function prepare(string $sourceWordPressRoot, string $newSiteRoot, string $sourceSqliteFile, string $newDatabaseFile, string $sourceUrl, string $localUrl, string $tablePrefix, array $administrator = [], array $sourceAliases = [], ?string $targetUrl = null, ?string $migrationDatabase = null): array
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $tablePrefix)) { throw new RuntimeException('Invalid WordPress table prefix.'); }
        SiteUrlRewriter::assertUrl($sourceUrl, false); SiteUrlRewriter::assertUrl($localUrl, true);
        if ($targetUrl !== null) { SiteUrlRewriter::assertUrl($targetUrl, false); $targetUrl = rtrim($targetUrl, '/'); }
        $sourceUrl = rtrim($sourceUrl, '/'); $localUrl = rtrim($localUrl, '/'); $storedUrl = $targetUrl ?? $localUrl;
        $preserveUrls = $sourceUrl === $storedUrl && $sourceAliases === [];
        $replacements = $preserveUrls ? [] : SiteUrlRewriter::replacements($sourceUrl, $storedUrl, $sourceAliases);
        FileSafety::assertNoLinks($sourceWordPressRoot); FileSafety::assertNoLinks($newSiteRoot);
        if (realpath($sourceWordPressRoot) !== $sourceWordPressRoot || !is_dir($sourceWordPressRoot)) { throw new RuntimeException('The restored source must be canonical.'); }
        foreach (['wp-load.php', 'wp-settings.php', 'wp-includes/version.php'] as $file) { FileSafety::assertRegularFile($sourceWordPressRoot . '/' . $file); }
        FileSafety::privateDirectory(dirname($newSiteRoot)); FileSafety::privateDirectory(dirname($newDatabaseFile));
        FileSafety::privateDirectory(dirname($sourceSqliteFile));
        FileSafety::assertRegularFile($sourceSqliteFile); FileSafety::assertNoLinks($newDatabaseFile);
        $stat = lstat($sourceSqliteFile);
        if (($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1) { throw new RuntimeException('The source SQLite file must be owner-only.'); }
        if (@lstat($newSiteRoot) !== false || @lstat($newDatabaseFile) !== false) { throw new RuntimeException('Running copies require unused site and database destinations.'); }
        if ($this->overlap($newSiteRoot, $sourceWordPressRoot) || $this->overlap($newDatabaseFile, $newSiteRoot)
            || $this->overlap($this->implementation, $newSiteRoot) || $this->overlap($newDatabaseFile, $sourceWordPressRoot)) { throw new RuntimeException('The source, running site, database and implementation must be separate.'); }
        SiteUrlRewriter::assertAdministrator($administrator);
        $this->storageProtection(dirname($newDatabaseFile), false);
        if (!class_exists('WP_MySQL_On_SQLite', false)) { require_once $this->implementation . '/wp-includes/database/load.php'; }
        if (!mkdir($newSiteRoot, 0700)) { throw new RuntimeException('Cannot create a new running site.'); }
        RunningSiteFiles::copyTree($sourceWordPressRoot, $newSiteRoot, '', true);
        $this->copyDatabase($sourceSqliteFile, $newDatabaseFile);
        $db = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . str_replace(';', ';;', $newDatabaseFile) . ';dbname=sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rewriter = new SiteUrlRewriter();
        $updated = 0;
        if (!$preserveUrls) {
            $updated = $rewriter->rewrite($db, $db, $replacements);
            $rewriter->setOption($db, $tablePrefix . 'options', 'home', $storedUrl);
            $rewriter->setOption($db, $tablePrefix . 'options', 'siteurl', $storedUrl);
        }
        if ($migrationDatabase !== null) {
            FileSafety::privateDirectory(dirname($migrationDatabase));
            $this->copyDatabase($newDatabaseFile, $migrationDatabase);
        }
        if ($administrator !== []) { $rewriter->createAdministrator($db, $tablePrefix, $administrator); }
        $db = null;
        $this->storageProtection(dirname($newDatabaseFile), false);
        FileSafety::assertRegularFile($newDatabaseFile);
        if (!class_exists('WP_SQLite_Storage', false)) { require_once $this->implementation . '/wp-includes/sqlite/class-wp-sqlite-storage.php'; }
        if (\WP_SQLite_Storage::with_explicit_path($newDatabaseFile)->initialize() !== $newDatabaseFile) { throw new RuntimeException('The local database storage path changed during preparation.'); }
        $this->storageProtection(dirname($newDatabaseFile), true);
        $content = $newSiteRoot . '/wp-content';
        if (!is_dir($content) && !mkdir($content, 0700)) { throw new RuntimeException('Cannot create the running content directory.'); }
        $plugins = $content . '/plugins';
        if (!is_dir($plugins) && !mkdir($plugins, 0700)) { throw new RuntimeException('Cannot create the running plugins directory.'); }
        $plugin = $plugins . '/sqlite-database-integration';
        if (@lstat($plugin) !== false) { throw new RuntimeException('The SQLite plugin destination is already present.'); }
        if (!mkdir($plugin, 0700)) { throw new RuntimeException('Cannot create the SQLite plugin directory.'); }
        RunningSiteFiles::copyTree($this->implementation, $plugin, '', false);
        RunningSiteFiles::writeNew($content . '/db.php', "<?php\nrequire_once __DIR__ . '/plugins/sqlite-database-integration/wp-includes/sqlite/db.php';\n");
        RunningSiteFiles::writeIsolationPlugin($content);
        $database = "define('DB_ENGINE', 'sqlite');\ndefine('DB_NAME', 'sqlite');\ndefine('DB_USER', '');\ndefine('DB_PASSWORD', '');\ndefine('DB_HOST', '');\ndefine('DB_PATH', " . var_export($newDatabaseFile, true) . ");\n";
        RunningSiteFiles::writeNew($newSiteRoot . '/wp-config.php', RunningSiteFiles::configuration($database, $localUrl, $targetUrl, $tablePrefix));
        return ['siteRoot' => $newSiteRoot, 'databaseFile' => $newDatabaseFile, 'url' => $localUrl, 'storedUrl' => $storedUrl, 'urlFieldsUpdated' => $updated, 'administratorCreated' => $administrator !== [], 'osNetworkIsolationRequired' => true, 'replacedRuntimeFiles' => RunningSiteFiles::REPLACED_FILES];
    }

    private function storageProtection(string $directory, bool $required): void
    {
        FileSafety::privateDirectory($directory); $owner = lstat($directory)['uid'];
        $maintenance = $directory . '/.ht.sqlite.maintenance'; FileSafety::assertNoLinks($maintenance);
        if (@lstat($maintenance) !== false) { throw new RuntimeException('The local database directory has an active or unknown maintenance marker.'); }
        foreach (self::STORAGE_PROTECTION as $name => $contents) {
            $path = $directory . '/' . $name; FileSafety::assertNoLinks($path); $stat = @lstat($path);
            if ($stat === false && !$required) { continue; }
            if ($stat === false || ($stat['mode'] & 0177777) !== 0100600 || $stat['nlink'] !== 1 || $stat['uid'] !== $owner
                || @file_get_contents($path, false, null, 0, strlen($contents) + 1) !== $contents) { throw new RuntimeException('The local database protection file is unsafe or has different contents.'); }
        }
    }

    private function copyDatabase(string $source, string $destination): void
    {
        $stream = fopen($destination, 'x+b');
        if ($stream === false) { throw new RuntimeException('Cannot create the independent local database.'); }
        try { if (!chmod($destination, 0600)) { throw new RuntimeException('Cannot protect the independent local database.'); } }
        finally { fclose($stream); }
        $reader = null; $writer = null;
        try {
            $reader = new \SQLite3($source, SQLITE3_OPEN_READONLY); $reader->enableExceptions(true); $reader->busyTimeout(5000);
            $writer = new \SQLite3($destination, SQLITE3_OPEN_READWRITE); $writer->enableExceptions(true); $writer->busyTimeout(5000);
            if (!$reader->backup($writer) || $writer->querySingle('PRAGMA integrity_check') !== 'ok') { throw new RuntimeException('Cannot copy the verified local database.'); }
        } catch (\Throwable $error) { throw new RuntimeException('Cannot copy the verified local database.', 0, $error); }
        finally { if ($writer !== null) { $writer->close(); } if ($reader !== null) { $reader->close(); } }
    }

    private function overlap(string $a, string $b): bool { return $a === $b || strpos($a . '/', $b . '/') === 0 || strpos($b . '/', $a . '/') === 0; }
}
