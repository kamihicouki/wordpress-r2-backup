<?php

declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPressR2Backup\AgeProcess;

final class AgeProcessTest extends TestCase
{
    private string $directory;
    private string $binary;
    private array $identities = [];
    private array $recipients = [];

    protected function setUp(): void
    {
        $runtime = Fixture::runtime();
        $this->binary = $runtime['age'];
        $this->directory = realpath(sys_get_temp_dir()) . '/wrb-age-test-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->directory, 0700));
        for ($i = 0; $i < 3; $i++) {
            $identity = $this->directory . '/identity-' . $i;
            $this->command([$runtime['keygen'], '-o', $identity]);
            $this->identities[] = $identity;
            $this->recipients[] = trim($this->command([$runtime['keygen'], '-y', $identity]));
        }
    }

    protected function tearDown(): void
    {
        foreach (new \FilesystemIterator($this->directory) as $file) {
            unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testSmallMemoryInputEncryptsWithoutPlaintextFileAndWorksWithStdinIdentity(): void
    {
        $age = new AgeProcess($this->binary);
        $plain = json_encode(['code' => bin2hex(random_bytes(32)), 'slot' => 1], JSON_THROW_ON_ERROR);
        $cipher = $this->directory . '/memory-cipher';
        $age->encryptBytes($plain, $cipher, [$this->recipients[0]]);
        self::assertSame(['identity-0', 'identity-1', 'identity-2', 'memory-cipher'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
        self::assertStringNotContainsString($plain, file_get_contents($cipher));
        $process = proc_open([$this->binary, '--decrypt', '--identity', '/dev/stdin', $cipher], [0 => ['file', $this->identities[0], 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, []);
        self::assertIsResource($process); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error); self::assertSame($plain, $output);
        $identity = fopen($this->identities[1], 'rb'); $failure = null;
        try { $age->decrypt($cipher, $this->directory . '/wrong-key', $identity); }
        catch (RuntimeException $caught) { $failure = $caught; }
        finally { fclose($identity); }
        self::assertInstanceOf(RuntimeException::class, $failure); self::assertFileDoesNotExist($this->directory . '/wrong-key');
    }

    public function testMemoryInputLimitRejectsOversizeWithoutCreatingOutput(): void
    {
        $age = new AgeProcess($this->binary); $failure = null;
        try { $age->encryptBytes(str_repeat('x', 65537), $this->directory . '/oversize', [$this->recipients[0]]); }
        catch (RuntimeException $caught) { $failure = $caught; }
        self::assertInstanceOf(RuntimeException::class, $failure); self::assertFileDoesNotExist($this->directory . '/oversize');
    }

    public function testMemoryDecryptionIsBoundedAndLeavesNoPlaintextFile(): void
    {
        $age = new AgeProcess($this->binary); $plain = str_repeat('private memory content ', 6000);
        $cipher = $this->directory . '/memory.age';
        $age->encryptStream([$plain], $cipher, [$this->recipients[0]]);
        $before = scandir($this->directory);
        $identity = fopen($this->identities[0], 'rb');
        try {
            $memory = $age->decryptToMemory($cipher, $identity, strlen($plain));
            try { self::assertSame('MEMORY', stream_get_meta_data($memory)['stream_type']); self::assertSame($plain, stream_get_contents($memory)); }
            finally { fclose($memory); }
            rewind($identity);
            try { $age->decryptToMemory($cipher, $identity, strlen($plain) - 1); self::fail('Oversized plaintext must fail.'); }
            catch (RuntimeException $expected) { self::assertStringContainsString('limit', $expected->getMessage()); }
        } finally { fclose($identity); }
        self::assertSame($before, scandir($this->directory));
        $wrong = fopen($this->identities[1], 'rb');
        try { $age->decryptToMemory($cipher, $wrong, strlen($plain)); self::fail('Wrong identity must fail.'); }
        catch (RuntimeException $expected) { self::assertSame($before, scandir($this->directory)); }
        finally { fclose($wrong); }
    }

    public function testLargeIterableIsEncryptedWithoutPlaintextStaging(): void
    {
        $plain = str_repeat('streamed private content ', 12000);
        $chunks = (static function () use ($plain): \Generator {
            foreach (str_split($plain, 3001) as $chunk) { yield $chunk; }
        })();
        $cipher = $this->directory . '/stream.age';
        $result = (new AgeProcess($this->binary))->encryptStream($chunks, $cipher, array_slice($this->recipients, 0, 2));
        self::assertSame(['size' => strlen($plain), 'sha256' => hash('sha256', $plain)], $result);
        self::assertSame(0600, fileperms($cipher) & 0777);
        self::assertSame(['identity-0', 'identity-1', 'identity-2', 'stream.age'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
        foreach ([0, 1] as $key) {
            $identity = fopen($this->identities[$key], 'rb');
            try { (new AgeProcess($this->binary))->decrypt($cipher, $this->directory . '/restored-' . $key, $identity); }
            finally { fclose($identity); }
            self::assertSame($plain, file_get_contents($this->directory . '/restored-' . $key));
        }
    }

    public function testStreamProducerFailureDoesNotPublishPartialCiphertext(): void
    {
        $chunks = (static function (): \Generator {
            yield str_repeat('x', 10000);
            throw new RuntimeException('producer failed');
        })();
        $failure = null;
        try { (new AgeProcess($this->binary))->encryptStream($chunks, $this->directory . '/partial.age', [$this->recipients[0]]); }
        catch (RuntimeException $error) { $failure = $error; }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertFileDoesNotExist($this->directory . '/partial.age');
        self::assertSame([], glob($this->directory . '/.wrb-age-*'));
    }

    public function testEitherRecipientCanDecryptAndReuseCallerOwnedIdentity(): void
    {
        $plain = $this->directory . '/plain';
        file_put_contents($plain, random_bytes(256 * 1024));
        $age = new AgeProcess($this->binary);
        $age->encrypt($plain, $this->directory . '/cipher', array_slice($this->recipients, 0, 2));
        foreach ([0, 1] as $i) {
            $identity = fopen('php://memory', 'w+b');
            fwrite($identity, file_get_contents($this->identities[$i]));
            foreach ([0, 1] as $attempt) {
                rewind($identity);
                $restored = $this->directory . '/restored-' . $i . '-' . $attempt;
                $age->decrypt($this->directory . '/cipher', $restored, $identity);
                self::assertTrue(is_resource($identity));
                self::assertSame(hash_file('sha256', $plain), hash_file('sha256', $restored));
            }
            fclose($identity);
        }
    }

    public function testWrongIdentityAndTamperingAreRejected(): void
    {
        file_put_contents($this->directory . '/plain', random_bytes(100000));
        $age = new AgeProcess($this->binary);
        $age->encrypt($this->directory . '/plain', $this->directory . '/cipher', array_slice($this->recipients, 0, 2));
        $cipher = file_get_contents($this->directory . '/cipher');
        $cipher[strlen($cipher) - 1] = chr(ord($cipher[strlen($cipher) - 1]) ^ 1);
        file_put_contents($this->directory . '/tampered', $cipher);
        foreach ([['cipher', 2], ['tampered', 0]] as [$name, $key]) {
            $identity = fopen($this->identities[$key], 'rb');
            try {
                $age->decrypt($this->directory . '/' . $name, $this->directory . '/rejected', $identity);
                self::fail('Invalid ciphertext or identity must fail.');
            } catch (RuntimeException $error) {
                self::assertStringNotContainsString('AGE-SECRET', $error->getMessage());
                self::assertFileDoesNotExist($this->directory . '/rejected');
                self::assertTrue(is_resource($identity));
            } finally {
                fclose($identity);
            }
        }
        self::assertSame([], glob($this->directory . '/.wrb-age-*'));
    }

    public function testLiteralNamesAndExistingOutputAreSafe(): void
    {
        $plain = $this->directory . '/日本語 空白 \' " $(touch injected)';
        $cipher = $plain . '.age';
        $restored = $plain . '.restored';
        file_put_contents($plain, 'synthetic content');
        $age = new AgeProcess($this->binary);
        $age->encrypt($plain, $cipher, array_slice($this->recipients, 0, 2));
        $identity = fopen($this->identities[0], 'rb');
        try {
            $age->decrypt($cipher, $restored, $identity);
        } finally {
            fclose($identity);
        }
        self::assertSame('synthetic content', file_get_contents($restored));
        self::assertFileDoesNotExist($this->directory . '/injected');
        try {
            $age->encrypt($plain, $cipher, array_slice($this->recipients, 0, 2));
            self::fail('Existing output must be rejected.');
        } catch (RuntimeException $ignoredError) {
            self::assertSame('synthetic content', file_get_contents($restored));
        }
        $identity = fopen($this->identities[0], 'rb');
        try {
            $age->decrypt($cipher, $restored, $identity);
            self::fail('Existing plaintext must be rejected.');
        } catch (RuntimeException $ignoredError) {
            self::assertSame('synthetic content', file_get_contents($restored));
        } finally {
            fclose($identity);
        }
    }

    public function testChildFailureDiscardsPartialOutputAndPrivateStderr(): void
    {
        $fake = $this->directory . '/failing-age';
        file_put_contents($fake, '#!' . Fixture::phpForShebang() . "\n<?php fwrite(STDOUT, 'partial'); fwrite(STDERR, str_repeat('private stderr', 10000)); exit(23);\n");
        chmod($fake, 0700);
        file_put_contents($this->directory . '/plain', 'synthetic');
        try {
            (new AgeProcess($fake))->encrypt($this->directory . '/plain', $this->directory . '/cipher', array_slice($this->recipients, 0, 2));
            self::fail('Child failure must fail.');
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('private stderr', $error->getMessage());
            self::assertFileDoesNotExist($this->directory . '/cipher');
            self::assertSame([], glob($this->directory . '/.wrb-age-*'));
        }
    }

    public function testConcurrentDestinationCreationCannotBeReplaced(): void
    {
        $destination = $this->directory . '/cipher';
        $fake = $this->directory . '/racing-age';
        $script = '#!' . Fixture::phpForShebang() . "\n<?php fwrite(STDOUT, 'candidate'); file_put_contents("
            . var_export($destination, true) . ", 'concurrent owner');\n";
        file_put_contents($fake, $script);
        chmod($fake, 0700);
        file_put_contents($this->directory . '/plain', 'synthetic');
        try {
            (new AgeProcess($fake))->encrypt($this->directory . '/plain', $destination, array_slice($this->recipients, 0, 2));
            self::fail('A concurrent destination must be preserved.');
        } catch (RuntimeException $ignoredError) {
            self::assertSame('concurrent owner', file_get_contents($destination));
            self::assertSame([], glob($this->directory . '/.wrb-age-*'));
        }
    }

    public function testOversizedIdentityFailsWithoutClosingCallerStream(): void
    {
        file_put_contents($this->directory . '/cipher', 'synthetic ciphertext');
        $fake = $this->directory . '/identity-reader';
        file_put_contents($fake, '#!' . Fixture::phpForShebang() . "\n<?php stream_get_contents(STDIN);\n");
        chmod($fake, 0700);
        $identity = fopen('php://memory', 'w+b');
        fwrite($identity, str_repeat('#', 65537));
        rewind($identity);
        try {
            (new AgeProcess($fake))->decrypt($this->directory . '/cipher', $this->directory . '/plain', $identity);
            self::fail('An oversized identity must fail.');
        } catch (RuntimeException $ignoredError) {
            self::assertTrue(is_resource($identity));
            self::assertFileDoesNotExist($this->directory . '/plain');
            self::assertSame([], glob($this->directory . '/.wrb-age-*'));
        } finally {
            fclose($identity);
        }
    }

    private function command(array $command): string
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        return $output;
    }
}
