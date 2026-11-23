<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;

final class AgeBinaryTest extends TestCase
{
    private string $root;
    private string $file;

    protected function setUp(): void
    {
        $platform = strtolower(PHP_OS_FAMILY) . '-' . (in_array(php_uname('m'), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64');
        $source = dirname(__DIR__) . '/bin/' . $platform . '/age';
        if (!is_file($source)) { self::markTestSkipped('No bundled executable for this platform.'); }
        $this->root = Fixture::temp();
        mkdir($this->root . '/src');
        mkdir($this->root . '/bin/' . $platform, 0755, true);
        $this->file = $this->root . '/bin/' . $platform . '/age';
        copy(dirname(__DIR__) . '/src/AgeBinary.php', $this->root . '/src/AgeBinary.php');
        copy($source, $this->file);
        chmod($this->file, 0644);
    }

    protected function tearDown(): void { Fixture::cleanup(); }

    /** Run the normal path resolver inside a private copy of the ZIP layout. */
    private function runAge(bool $denyChmod = false): array
    {
        $override = $denyChmod ? 'namespace WordPressR2Backup { function proc_open(...$args) { return false; } }' : '';
        $script = $override . 'namespace { require $argv[1]; require $argv[2]; try { $file=WordPressR2Backup\\AgeBinary::path();'
            . '$child=proc_open([$file,"--version"],[1=>STDOUT,2=>STDERR],$pipes); exit(proc_close($child));'
            . '} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(1); } }';
        $process = proc_open([PHP_BINARY,'-r',$script,dirname(__DIR__).'/vendor/autoload.php',$this->root.'/src/AgeBinary.php'],
            [1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process),$out,$err];
    }

    public function testZipModeIsRepairedAndReadOnlyExecutableStaysReadOnly(): void
    {
        foreach ([0644=>0744,0555=>0555] as $before=>$expected) {
            chmod($this->file,$before);
            [$exit,$out,$err] = $this->runAge();
            self::assertSame(0,$exit,$err);
            self::assertStringContainsString('1.3.2',$out);
            clearstatcache(true,$this->file);
            self::assertSame($expected,fileperms($this->file)&07777);
        }
    }

    public function testReadOnlyHostGetsTheExactVerifiedFileAndRecoveryInstruction(): void
    {
        [$exit,,$err] = $this->runAge(true);
        self::assertSame(1,$exit);
        self::assertStringContainsString('サーバー管理者',$err);
        self::assertStringContainsString(basename(dirname($this->file)).'/age',$err);
        self::assertStringContainsString(hash_file('sha256',$this->file),$err);
        self::assertSame(0644,fileperms($this->file)&07777);
    }

    public function testHashMismatchDoesNotChangePermissions(): void
    {
        file_put_contents($this->file,'modified');
        [$exit,,$err] = $this->runAge();
        self::assertSame(1,$exit); self::assertStringContainsString('SHA-256',$err);
        self::assertSame(0644,fileperms($this->file)&07777);
    }

    public function testLinkIsNotFollowedAndHardLinkIsNotMadeExecutable(): void
    {
        $other = $this->root.'/outside-age';
        rename($this->file,$other);
        symlink($other,$this->file);
        self::assertSame(1,$this->runAge()[0]);
        unlink($this->file);
        link($other,$this->file);
        [$exit,,$err] = $this->runAge();
        self::assertSame(1,$exit);
        self::assertStringContainsString('リンク',$err);
        clearstatcache(true,$other);
        self::assertSame(0644,fileperms($other)&07777);
    }
}
