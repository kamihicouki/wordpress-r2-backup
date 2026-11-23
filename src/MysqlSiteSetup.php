<?php
declare(strict_types=1);

namespace WordPressR2Backup;

use PDO;
use RuntimeException;

/**
 * Prepares a separate running copy on MySQL/MariaDB. The verified original schema stays with its own accounts: the running
 * account holds rights on the running schema only, so the site cannot read or change the original.
 */
final class MysqlSiteSetup
{
    /** @param array{user:string,password:string} $runner */
    public function prepare(MysqlClient $client, string $originalSchema, string $runningSchema, array $runner, string $socket, string $sourceWordPressRoot, string $newSiteRoot, string $sourceUrl, string $localUrl, ?string $targetUrl, string $tablePrefix, array $administrator, array $sourceAliases, string $workspace, ?string $migrationDatabase = null): array
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $tablePrefix)) { throw new RuntimeException('Invalid WordPress table prefix.'); }
        SiteUrlRewriter::assertUrl($sourceUrl, false); SiteUrlRewriter::assertUrl($localUrl, true);
        if ($targetUrl !== null) { SiteUrlRewriter::assertUrl($targetUrl, false); $targetUrl = rtrim($targetUrl, '/'); }
        $localUrl = rtrim($localUrl, '/'); $storedUrl = $targetUrl ?? $localUrl;
        $preserveUrls = rtrim($sourceUrl, '/') === $storedUrl && $sourceAliases === [];
        $replacements = $preserveUrls ? [] : SiteUrlRewriter::replacements(rtrim($sourceUrl, '/'), $storedUrl, $sourceAliases);
        SiteUrlRewriter::assertAdministrator($administrator);
        FileSafety::assertNoLinks($sourceWordPressRoot); FileSafety::assertNoLinks($newSiteRoot); FileSafety::privateDirectory(dirname($newSiteRoot)); FileSafety::privateDirectory($workspace);
        if (realpath($sourceWordPressRoot) !== $sourceWordPressRoot || !is_dir($sourceWordPressRoot)) { throw new RuntimeException('The restored source must be canonical.'); }
        foreach (['wp-load.php', 'wp-settings.php', 'wp-includes/version.php'] as $file) { FileSafety::assertRegularFile($sourceWordPressRoot . '/' . $file); }
        if (@lstat($newSiteRoot) !== false) { throw new RuntimeException('Running copies require unused site and database destinations.'); }
        // A dump keeps each definer, and the running account cannot act for the importing account; refuse before anything is created.
        if ($client->definedObjectCount($originalSchema) !== 0) { throw new RuntimeException('A running copy cannot reproduce views, triggers, routines or events owned by another account.'); }
        $dump = $workspace . '/running-copy.sql';
        try {
            $client->dump($originalSchema, $dump);
            $client->importIntoEmpty($runningSchema, $dump, []);
        } finally { if (@lstat($dump) !== false) { unlink($dump); } }
        if ($client->tableChecksums($originalSchema) !== $client->tableChecksums($runningSchema)) { throw new RuntimeException('The running database copy differs from the verified original.'); }
        if (!mkdir($newSiteRoot, 0700)) { throw new RuntimeException('Cannot create a new running site.'); }
        RunningSiteFiles::copyTree($sourceWordPressRoot, $newSiteRoot, '', true);
        $dsn = 'mysql:unix_socket=' . $socket . ';dbname=' . $runningSchema . ';charset=utf8mb4';
        $writer = new PDO($dsn, $runner['user'], $runner['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_LOCAL_INFILE => false]);
        $reader = new PDO($dsn, $runner['user'], $runner['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_LOCAL_INFILE => false, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]);
        $rewriter = new SiteUrlRewriter();
        $updated = 0;
        if (!$preserveUrls) {
            $updated = $rewriter->rewrite($reader, $writer, $replacements);
            $rewriter->setOption($writer, $tablePrefix . 'options', 'home', $storedUrl);
            $rewriter->setOption($writer, $tablePrefix . 'options', 'siteurl', $storedUrl);
        }
        $reader = null;
        if ($migrationDatabase !== null) { $client->dump($runningSchema, $migrationDatabase); }
        if ($administrator !== []) { $rewriter->createAdministrator($writer, $tablePrefix, $administrator); }
        $writer = null;
        $content = $newSiteRoot . '/wp-content';
        if (!is_dir($content) && !mkdir($content, 0700)) { throw new RuntimeException('Cannot create the running content directory.'); }
        RunningSiteFiles::writeIsolationPlugin($content);
        $database = "define('DB_NAME', " . var_export($runningSchema, true) . ");\ndefine('DB_USER', " . var_export($runner['user'], true) . ");\ndefine('DB_PASSWORD', " . var_export($runner['password'], true) . ");\ndefine('DB_HOST', " . var_export('localhost:' . $socket, true) . ");\n";
        RunningSiteFiles::writeNew($newSiteRoot . '/wp-config.php', RunningSiteFiles::configuration($database, $localUrl, $targetUrl, $tablePrefix));
        return ['siteRoot' => $newSiteRoot, 'databaseSchema' => $runningSchema, 'databaseSocket' => $socket, 'url' => $localUrl, 'storedUrl' => $storedUrl, 'urlFieldsUpdated' => $updated, 'administratorCreated' => $administrator !== [], 'osNetworkIsolationRequired' => true];
    }
}
