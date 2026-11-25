<?php

declare(strict_types=1);

namespace WordPressR2Backup;

use RuntimeException;

final class AgeProcess
{
    private const TIMEOUT_SECONDS = 300;
    private string $binary;
    private $beforeEncryptStream;
    /** Elapsed time, calls and plaintext bytes of encryptStream(), for measurement only; never read back by any check. */
    private int $streamNanoseconds = 0;
    private int $streamCalls = 0;
    private int $streamPlainBytes = 0;

    public function __construct(string $verifiedAgeBinary, ?callable $beforeEncryptStream = null)
    {
        $binary = realpath($verifiedAgeBinary);
        if ($binary === false || !is_file($binary) || !is_executable($binary)) {
            throw new RuntimeException('The verified age executable is unavailable.');
        }
        $this->binary = $binary;
        $this->beforeEncryptStream = $beforeEncryptStream;
    }

    public function encrypt(string $plainFile, string $cipherFile, array $recipients): void
    {
        $command = $this->encryptionCommand($recipients);
        $command[] = $this->inputPath($plainFile);
        $this->run($command, $cipherFile, null);
    }

    public function encryptBytes(string $plain, string $cipherFile, array $recipients): void
    {
        if (strlen($plain) > 65536) { throw new RuntimeException('The encryption input exceeds 64 KiB.'); }
        $command = $this->encryptionCommand($recipients);
        $command[] = '-';
        $input = fopen('php://memory', 'w+b');
        if ($input === false) { throw new RuntimeException('Cannot open encryption input.'); }
        try {
            if (fwrite($input, $plain) !== strlen($plain) || !rewind($input)) { throw new RuntimeException('Cannot prepare encryption input.'); }
            $this->run($command, $cipherFile, $input);
        } finally { fclose($input); }
    }

    public function encryptStream(iterable $chunks, string $cipherFile, array $recipients): array
    {
        if ($this->beforeEncryptStream !== null) { ($this->beforeEncryptStream)(); }
        $command = $this->encryptionCommand($recipients);
        $command[] = '-';
        $plainBytes = 0;
        $input = (static function () use ($chunks, &$plainBytes): \Generator {
            foreach ($chunks as $chunk) {
                if (!is_string($chunk)) { throw new RuntimeException('Encryption chunks must be strings.'); }
                $length = strlen($chunk); $plainBytes += $length;
                for ($offset = 0; $offset < $length; $offset += 8192) { yield substr($chunk, $offset, 8192); }
            }
        })();
        $started = hrtime(true);
        try { return $this->run($command, $cipherFile, null, $input); }
        finally { $this->streamNanoseconds += hrtime(true) - $started; ++$this->streamCalls; $this->streamPlainBytes += $plainBytes; }
    }

    /** Totals of encryptStream() on this instance: elapsed nanoseconds, calls and plaintext bytes. Measurement only. */
    public function streamTotals(): array
    {
        return ['nanoseconds' => $this->streamNanoseconds, 'calls' => $this->streamCalls, 'plainBytes' => $this->streamPlainBytes];
    }

    private function encryptionCommand(array $recipients): array
    {
        if ($recipients === [] || !array_is_list($recipients)) {
            throw new RuntimeException('At least one X25519 recipient is required.');
        }
        $command = [$this->binary, '--encrypt'];
        foreach ($recipients as $recipient) {
            if (!is_string($recipient) || preg_match('/\Aage1[0-9a-z]{58}\z/D', $recipient) !== 1) {
                throw new RuntimeException('An X25519 recipient is invalid.');
            }
            $command[] = '--recipient';
            $command[] = $recipient;
        }
        return $command;
    }

    public function decrypt(string $cipherFile, string $plainFile, $identityStream): void
    {
        if (!is_resource($identityStream) || get_resource_type($identityStream) !== 'stream') {
            throw new RuntimeException('A readable identity stream is required.');
        }
        $metadata = stream_get_meta_data($identityStream);
        if (!strpbrk($metadata['mode'], 'r+')) {
            throw new RuntimeException('A readable identity stream is required.');
        }
        $this->run(
            [$this->binary, '--decrypt', '--identity', '-', $this->inputPath($cipherFile)],
            $plainFile,
            $identityStream
        );
    }

    public function decryptToMemory(string $cipherFile, $identityStream, int $maximumBytes)
    {
        if (!is_resource($identityStream) || get_resource_type($identityStream) !== 'stream'
            || !strpbrk(stream_get_meta_data($identityStream)['mode'], 'r+') || $maximumBytes < 1 || $maximumBytes > 35651584) {
            throw new RuntimeException('Expected a readable identity and bounded decryption output.');
        }
        $cipher = $this->inputPath($cipherFile);
        $output = fopen('php://memory', 'w+b');
        if ($output === false) { throw new RuntimeException('Cannot create in-memory decryption output.'); }
        $process = null; $pipes = []; $complete = false;
        try {
            $process = @proc_open([$this->binary, '--decrypt', '--identity', '-', $cipher],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($cipher), [], ['bypass_shell' => true]);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start age.'); }
            foreach ($pipes as $pipe) { stream_set_blocking($pipe, false); }
            $pending = ''; $inputBytes = 0; $outputBytes = 0; $exit = null; $started = hrtime(true);
            while (true) {
                if ((hrtime(true) - $started) / 1e9 > self::TIMEOUT_SECONDS) { throw new RuntimeException('The age operation timed out.'); }
                if (isset($pipes[0]) && $pending === '' && !feof($identityStream)) {
                    $pending = fread($identityStream, 8192);
                    if ($pending === false || ($inputBytes += strlen($pending)) > 65536) { throw new RuntimeException('The identity stream exceeds its limit.'); }
                }
                if (isset($pipes[0]) && $pending === '' && feof($identityStream)) { fclose($pipes[0]); unset($pipes[0]); }
                $read = [];
                foreach ([1, 2] as $number) { if (isset($pipes[$number]) && !feof($pipes[$number])) { $read[] = $pipes[$number]; } }
                $write = isset($pipes[0]) && $pending !== '' ? [$pipes[0]] : [];
                $except = null;
                if ($read !== [] || $write !== []) {
                    if (@stream_select($read, $write, $except, 0, 100000) === false) { throw new RuntimeException('Cannot monitor age.'); }
                    if ($write !== []) {
                        $sent = @fwrite($pipes[0], $pending);
                        if ($sent === false) { throw new RuntimeException('Cannot send the identity to age.'); }
                        $pending = substr($pending, $sent);
                    }
                    foreach ($read as $pipe) {
                        $chunk = fread($pipe, 8192);
                        if ($chunk === false) { throw new RuntimeException('Cannot read age output.'); }
                        if (isset($pipes[1]) && $pipe === $pipes[1]) {
                            if (strlen($chunk) > $maximumBytes - $outputBytes || fwrite($output, $chunk) !== strlen($chunk)) { throw new RuntimeException('Decrypted output exceeds its memory limit.'); }
                            $outputBytes += strlen($chunk);
                        }
                    }
                }
                $status = proc_get_status($process);
                if (!$status['running'] && $exit === null) { $exit = $status['exitcode']; }
                if ($exit !== null && feof($pipes[1]) && feof($pipes[2])) { break; }
            }
            foreach ($pipes as $pipe) { fclose($pipe); }
            $pipes = [];
            $closed = proc_close($process); $process = null;
            if (($exit >= 0 ? $exit : $closed) !== 0 || !rewind($output)) { throw new RuntimeException('The age operation failed; no plaintext was published.'); }
            $complete = true;
            return $output;
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                usleep(100000);
                if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
                foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                proc_close($process);
            }
            if (!$complete) { fclose($output); }
        }
    }

    private function inputPath(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new RuntimeException('The input file is invalid.');
        }
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || !is_readable($path)) {
            throw new RuntimeException('A readable regular input file is required.');
        }
        $absolute = realpath($path);
        if ($absolute === false) {
            throw new RuntimeException('The input file is unavailable.');
        }
        return $absolute;
    }

    private function run(array $command, string $destination, $inputStream, ?\Iterator $chunks = null): array
    {
        if (str_contains($destination, "\0") || in_array(basename($destination), ['', '.', '..'], true)) {
            throw new RuntimeException('The output path is invalid.');
        }
        $directory = realpath(dirname($destination));
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException('The output directory is unavailable.');
        }
        $destination = $directory . '/' . basename($destination);
        clearstatcache(true, $destination);
        if (@lstat($destination) !== false) {
            throw new RuntimeException('The output already exists.');
        }
        $temporary = $directory . '/.wrb-age-' . bin2hex(random_bytes(16));
        $output = @fopen($temporary, 'x+b');
        if ($output === false) {
            throw new RuntimeException('Cannot create private temporary output.');
        }
        $process = null;
        $pipes = [];
        try {
            if (!@chmod($temporary, 0600)) {
                throw new RuntimeException('Cannot protect temporary output.');
            }
            $process = @proc_open(
                $command,
                [0 => $inputStream === null && $chunks === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'], 1 => $output, 2 => ['pipe', 'w']],
                $pipes,
                $directory,
                [],
                ['bypass_shell' => true]
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start age.');
            }
            stream_set_blocking($pipes[2], false);
            if (isset($pipes[0])) {
                stream_set_blocking($pipes[0], false);
            }
            $pendingInput = '';
            $inputBytes = 0;
            $hash = hash_init('sha256');
            $iteratorStarted = false;
            $iteratorEnded = false;
            $started = hrtime(true);
            do {
                if (isset($pipes[0])) {
                    if ($pendingInput === '') {
                        if ($chunks !== null) {
                            if (!$iteratorStarted) { $chunks->rewind(); $iteratorStarted = true; }
                            else { $chunks->next(); }
                            $iteratorEnded = !$chunks->valid();
                            if (!$iteratorEnded) { $pendingInput = $chunks->current(); }
                        } elseif (!feof($inputStream)) {
                            $pendingInput = fread($inputStream, 8192);
                            if ($pendingInput === false) { throw new RuntimeException('Cannot read the input stream.'); }
                        }
                        $inputBytes += strlen($pendingInput);
                        if ($chunks === null && $inputBytes > 65536) { throw new RuntimeException('The input stream exceeds 64 KiB.'); }
                        hash_update($hash, $pendingInput);
                    }
                    if ($pendingInput !== '') {
                        $written = @fwrite($pipes[0], $pendingInput);
                        if ($written === false) {
                            throw new RuntimeException('Cannot send the identity to age.');
                        }
                        $pendingInput = substr($pendingInput, $written);
                    }
                    if ($pendingInput === '' && ($chunks !== null ? $iteratorEnded : feof($inputStream))) {
                        fclose($pipes[0]);
                        unset($pipes[0]);
                    }
                }
                // Drain in bounded chunks. Child diagnostics may contain private material.
                while (($chunk = fread($pipes[2], 8192)) !== false && $chunk !== '') {
                    if ((hrtime(true) - $started) / 1e9 > self::TIMEOUT_SECONDS) {
                        throw new RuntimeException('The age operation timed out.');
                    }
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if ((hrtime(true) - $started) / 1e9 > self::TIMEOUT_SECONDS) {
                    throw new RuntimeException('The age operation timed out.');
                }
                $read = [$pipes[2]];
                $write = isset($pipes[0]) ? [$pipes[0]] : null;
                $except = null;
                if (@stream_select($read, $write, $except, 0, 100000) === false) {
                    throw new RuntimeException('Cannot monitor age.');
                }
            } while (true);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $pipes = [];
            $closedStatus = proc_close($process);
            $process = null;
            $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closedStatus;
            if ($exit !== 0) {
                throw new RuntimeException('The age operation failed; no output was published.');
            }
            if ($chunks !== null && (!$iteratorEnded || $pendingInput !== '')) {
                throw new RuntimeException('The age process ended before consuming the encryption input.');
            }
            if (!fflush($output) || !\WordPressR2Backup\FileSafety::sync($output)) {
                throw new RuntimeException('Cannot persist age output.');
            }
            fclose($output);
            $output = null;
            // A hard link creates the final name atomically and cannot replace an existing path.
            if (!@link($temporary, $destination)) {
                throw new RuntimeException('Cannot publish age output without replacement.');
            }
            return ['size' => $inputBytes, 'sha256' => hash_final($hash)];
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                usleep(100000);
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temporary);
        }
    }
}
