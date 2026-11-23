<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\DirectR2Repository;
use WordPressR2Backup\R2HttpException;
use WordPressR2Backup\R2TransportException;

/** Faults at the real class's transport seam, and real cURL against a local TLS server. No network beyond loopback. */
final class DirectR2TransportRetryTest extends TestCase
{
    private array $connection;
    private string $directory;
    private string $body;
    private array $receipt;
    private array $calls = [];
    private array $waits = [];
    private string $logFile;
    private $previousLog;

    protected function setUp(): void
    {
        $this->connection = ['accountId' => str_repeat('a', 32), 'bucket' => 'backup-test', 'accessKeyId' => str_repeat('B', 32), 'secretAccessKey' => str_repeat('c', 64), 'unitId' => str_repeat('d', 64)];
        $this->directory = realpath(sys_get_temp_dir()) . '/wrb-r2-retry-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->body = str_repeat('encrypted-cipher-', 50);
        $this->receipt = ['key' => 'databases/' . str_repeat('4', 64) . '.age', 'bytes' => strlen($this->body), 'sha256' => hash('sha256', $this->body)];
        $this->logFile = $this->directory . '/php-error.log';
        $this->previousLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousLog);
        foreach (scandir($this->directory) as $entry) { if ($entry !== '.' && $entry !== '..') { unlink($this->directory . '/' . $entry); } }
        rmdir($this->directory);
    }

    /** @param callable(int,string,callable):void $fault may consume part of the body and throw; numbered per method */
    private function repository(callable $fault, ?callable $clock = null): DirectR2Repository
    {
        return new DirectR2Repository($this->connection, function (array $request, callable $consume) use ($fault): array {
            $this->calls[] = $request;
            $method = $request['method'];
            $seen = count(array_filter($this->calls, static function (array $call) use ($method): bool { return $call['method'] === $method; }));
            $outcome = $fault($seen, $method, $consume);
            if (is_array($outcome)) { return $outcome; }
            if ($method === 'HEAD') { return ['status' => 200, 'headers' => ['content-length' => (string) strlen($this->body)]]; }
            if ($method === 'GET') { foreach (str_split($this->body, 64) as $chunk) { if ($consume($chunk) !== strlen($chunk)) { break; } } }
            return ['status' => 200, 'headers' => []];
        }, $clock ?? static fn (): int => 1700000000, function (int $seconds): void { $this->waits[] = $seconds; });
    }

    private function methods(): array { return array_column($this->calls, 'method'); }

    private function assertNoStaging(): void { self::assertSame([], glob($this->directory . '/.download-*') ?: []); }

    public function testABlipOnHeadOrGetRecoversAndVerifies(): void
    {
        $this->repository(static function (int $n, string $method): void { if ($method === 'HEAD' && $n === 1) { throw new R2TransportException(28); } if ($method === 'GET' && $n === 1) { throw new R2TransportException(7); } })->verify($this->receipt);
        self::assertSame(['HEAD', 'HEAD', 'GET', 'GET'], $this->methods());
        self::assertSame([2, 2], $this->waits);
    }

    public function testADownloadCutMidBodyRestartsFromAnEmptyStagingFile(): void
    {
        $this->repository(function (int $n, string $method, callable $consume): void {
            if ($method === 'GET' && $n <= 2) { $consume(substr($this->body, 0, 100 * $n)); throw new R2TransportException($n === 1 ? 18 : 56); }
        })->download($this->receipt, $this->directory . '/out');
        self::assertSame($this->body, file_get_contents($this->directory . '/out'), 'Bytes from failed attempts do not survive');
        self::assertSame(0600, fileperms($this->directory . '/out') & 0777);
        self::assertSame(['HEAD', 'GET', 'GET', 'GET'], $this->methods());
        $this->assertNoStaging();
    }

    public function testRetriesStopAtTheLimitWithoutPublishing(): void
    {
        $repository = $this->repository(function (int $n, string $method, callable $consume): void {
            if ($method === 'GET') { $consume(substr($this->body, 0, 10)); throw new R2TransportException(56); }
        });
        try { $repository->download($this->receipt, $this->directory . '/out'); self::fail('Expected the attempts to run out.'); }
        catch (R2TransportException $error) {
            self::assertSame(56, $error->errno());
            self::assertSame('R2 transport failed (connection, errno 56, attempt 4 of 4).', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertSame(['HEAD', 'GET', 'GET', 'GET', 'GET'], $this->methods());
        self::assertSame([2, 5, 15], $this->waits);
        self::assertFileDoesNotExist($this->directory . '/out');
        $this->assertNoStaging();
    }

    /** @dataProvider permanentNumbers */
    public function testFailuresThatRetryingWouldHideStopAtOnce(int $errno, string $kind): void
    {
        try { $this->repository(static function (int $n, string $method) use ($errno): void { if ($method === 'HEAD') { throw new R2TransportException($errno); } })->verify($this->receipt); self::fail('Expected failure.'); }
        catch (R2TransportException $error) { self::assertSame($errno, $error->errno()); self::assertSame($kind, $error->kind()); self::assertFalse($error->isTransient()); }
        self::assertSame(['HEAD'], $this->methods());
        self::assertSame([], $this->waits);
    }

    public function permanentNumbers(): array { return ['untrusted certificate' => [60, 'tls'], 'certificate name' => [51, 'tls'], 'TLS handshake' => [35, 'tls'], 'CA file' => [77, 'tls'], 'write callback' => [23, 'callback'], 'aborted by callback' => [42, 'callback'], 'unknown' => [999, 'other']]; }

    public function testAnUntypedFailureKeepsItsFixedMessageAndOneAttempt(): void
    {
        $secret = $this->connection['secretAccessKey'];
        try { $this->repository(static function () use ($secret): void { throw new \RuntimeException('at ' . $secret); })->verify($this->receipt); self::fail('Expected failure.'); }
        catch (\RuntimeException $error) { self::assertSame('R2 transport failed.', $error->getMessage()); self::assertNull($error->getPrevious()); }
        self::assertSame(['HEAD'], $this->methods());
        self::assertStringNotContainsString($secret, (string) @file_get_contents($this->logFile));
    }

    /** @dataProvider statuses */
    public function testAnHttpAnswerIsNeverRetried(int $status): void
    {
        try { $this->repository(static function () use ($status): array { return ['status' => $status, 'headers' => []]; })->verify($this->receipt); self::fail('Expected failure.'); }
        catch (\RuntimeException $error) { self::assertInstanceOf(\RuntimeException::class, $error); }
        self::assertSame(['HEAD'], $this->methods());
    }

    public function statuses(): array { return ['forbidden' => [403], 'missing' => [404], 'unavailable' => [503]]; }

    public function testChecksumSizeAndShortBodyAreNotRetried(): void
    {
        $wrongSum = ['sha256' => str_repeat('0', 64)] + $this->receipt;
        try { $this->repository(static function (): void {})->verify($wrongSum); self::fail('Expected checksum failure.'); }
        catch (\RuntimeException $error) { self::assertSame('Ciphertext verification failed.', $error->getMessage()); }
        self::assertSame(['HEAD', 'GET'], $this->methods());
        $this->calls = [];
        $wrongSize = ['bytes' => strlen($this->body) + 1] + $this->receipt;
        try { $this->repository(static function (): void {})->verify($wrongSize); self::fail('Expected size failure.'); }
        catch (\RuntimeException $error) { self::assertSame('Stored ciphertext length does not match.', $error->getMessage()); }
        self::assertSame(['HEAD'], $this->methods());
        $this->calls = [];
        try { $this->repository(function (int $n, string $method, callable $consume): ?array { if ($method === 'GET') { $consume(substr($this->body, 0, 5)); return ['status' => 200, 'headers' => []]; } return null; })->verify($this->receipt); self::fail('Expected short body failure.'); }
        catch (\RuntimeException $error) { self::assertSame('Ciphertext verification failed.', $error->getMessage()); }
        self::assertSame(['HEAD', 'GET'], $this->methods());
    }

    /** @dataProvider numbersAlongsideOverflow */
    public function testASizeOverflowIsIdentifiedAndNotRetried(int $errno): void
    {
        // The real path: the bounded callback refuses the excess, and cURL then reports its write error.
        try {
            $this->repository(function (int $n, string $method, callable $consume) use ($errno): void {
                if ($method === 'GET') { $consume($this->body . 'more'); throw new R2TransportException($errno); }
            })->verify($this->receipt);
            self::fail('Expected failure.');
        } catch (R2TransportException $error) { self::assertSame('overflow', $error->kind()); self::assertFalse($error->isTransient()); }
        self::assertSame(['HEAD', 'GET'], $this->methods());
        self::assertSame([], $this->waits);
        self::assertStringContainsString('kind=overflow errno=' . $errno . ' attempt=1/4 retry=no', (string) file_get_contents($this->logFile));
    }

    public function numbersAlongsideOverflow(): array { return ['cURL write error' => [23], 'also a connection number' => [56]]; }

    /** @dataProvider numbersAlongsideOverflow */
    public function testALocalWriteFailureIsIdentifiedAndNotRetried(int $errno): void
    {
        file_put_contents($this->directory . '/readonly', '');
        $output = fopen($this->directory . '/readonly', 'rb');
        $repository = $this->repository(function (int $n, string $method, callable $consume) use ($errno): void {
            if ($method === 'GET') { $consume('x'); throw new R2TransportException($errno); }
        });
        $read = new \ReflectionMethod($repository, 'readObject'); $read->setAccessible(true);
        set_error_handler(static function (): bool { return true; });
        try { $read->invoke($repository, $this->receipt['key'], $this->receipt, $output); self::fail('Expected failure.'); }
        catch (R2TransportException $error) { self::assertSame('local-write', $error->kind()); self::assertFalse($error->isTransient()); }
        finally { restore_error_handler(); fclose($output); }
        self::assertSame(['HEAD', 'GET'], $this->methods());
    }

    /** Whatever the consume callback raises is local, however it is typed. */
    public function testAnExceptionFromConsumeIsLocalAndNotRetried(): void
    {
        foreach ([new R2TransportException(56), new \RuntimeException('local')] as $raised) {
            $this->calls = [];
            $repository = $this->repository(static function (int $n, string $method, callable $consume): void { $consume('x'); });
            $request = new \ReflectionMethod($repository, 'request'); $request->setAccessible(true);
            $consume = static function (string $chunk) use ($raised): int { throw $raised; };
            $failed = false;
            try { $request->invoke($repository, 'GET', $this->receipt['key'], [], null, 0, hash('sha256', ''), [], $consume, 100, [200], static function (): void {}); }
            catch (R2TransportException $error) { $failed = true; self::assertSame('local-write', $error->kind()); self::assertFalse($error->isTransient()); }
            catch (\RuntimeException $error) { $failed = true; self::assertSame('R2 transport failed.', $error->getMessage()); }
            self::assertTrue($failed);
            self::assertSame(['GET'], $this->methods(), get_class($raised));
        }
        self::assertSame([], $this->waits);
        self::assertStringContainsString('errno=none local=yes', (string) file_get_contents($this->logFile));
    }

    public function testATransportThatIgnoresARefusalCannotReportSuccess(): void
    {
        file_put_contents($this->directory . '/readonly', '');
        $output = fopen($this->directory . '/readonly', 'rb');
        $repository = $this->repository(function (int $n, string $method, callable $consume): ?array {
            if ($method === 'GET') { foreach (str_split($this->body, 64) as $chunk) { $consume($chunk); } return ['status' => 200, 'headers' => []]; }
            return null;
        });
        $read = new \ReflectionMethod($repository, 'readObject'); $read->setAccessible(true);
        set_error_handler(static function (): bool { return true; });
        try { $read->invoke($repository, $this->receipt['key'], $this->receipt, $output); self::fail('Expected failure.'); }
        catch (\RuntimeException $error) { self::assertSame('R2 request failed or exceeded its response limit.', $error->getMessage()); }
        finally { restore_error_handler(); fclose($output); }
        self::assertSame(['HEAD', 'GET'], $this->methods());
    }

    public function testNoFurtherAttemptAfterTheRetryBudget(): void
    {
        $now = 1700000000;
        $repository = $this->repository(static function (int $n, string $method): void { throw new R2TransportException(28); }, static function () use (&$now): int { $now += 60; return $now; });
        try { $repository->verify($this->receipt); self::fail('Expected failure.'); }
        catch (R2TransportException $error) { self::assertSame('R2 transport failed (connection, errno 28, attempt 2 of 4).', $error->getMessage()); }
        self::assertSame(['HEAD', 'HEAD'], $this->methods());
    }

    public function testOnlyExplicitTemporaryHttpResponsesCanResumeTheScheduledTransfer(): void
    {
        $time = time() + 7200;
        $date = gmdate('D, d M Y H:i:s', $time) . ' GMT';
        foreach ([[429, null, 0], [503, $date, $time], [403, '0', null], [404, null, null],
            [500, null, null], [503, 'not a date', null], [429, '99999999999999999999', null]] as [$status, $header, $expected]) {
            $httpHeaders = $header === null ? [] : ['Retry-After' => $header];
            try {
                $this->repository(static fn (): array => ['status' => $status, 'headers' => $httpHeaders])->verify($this->receipt);
                self::fail('Unexpected HTTP status accepted');
            } catch (R2HttpException $error) {
                self::assertSame($expected, $error->retryNotBefore());
                self::assertSame($status, $error->getCode());
                self::assertNull($error->getPrevious());
            }
        }
        self::assertEqualsWithDelta(time()+600, (new R2HttpException(429, '00600'))->retryNotBefore(), 1);
        self::assertSame([], $this->waits, 'No immediate HTTP request replay');
        $log = file_get_contents($this->logFile);
        self::assertStringContainsString('method=HEAD reason=http_status status=429', $log);
        foreach ([$this->receipt['key'], $this->connection['accountId'], $this->connection['secretAccessKey'], 'not a date'] as $value) {
            self::assertStringNotContainsString($value, $log);
        }
    }

    public function testHttp500MustComeFromAConditionalCiphertextWrite(): void
    {
        $repository=$this->repository(static fn (): array => ['status'=>500,'headers'=>['retry-after'=>'60']]);
        $send=new \ReflectionMethod($repository,'request'); $send->setAccessible(true);
        $stream=fopen('php://temp','w+b'); fwrite($stream,'x');
        try {
            foreach ([
                ['PUT',$this->receipt['key'],['if-none-match'=>'*'],true],
                ['PUT',$this->receipt['key'],[],false],
                ['PUT','commits/'.str_repeat('a',64).'.json',['if-none-match'=>'*'],false],
                ['HEAD',$this->receipt['key'],[],false],
                ['GET',$this->receipt['key'],[],false],
                ['DELETE',$this->receipt['key'],[],false],
            ] as [$method,$key,$headers,$eligible]) {
                rewind($stream);
                try {
                    $send->invoke($repository,$method,$key,[],$method==='PUT'?$stream:null,$method==='PUT'?1:0,
                        hash('sha256',$method==='PUT'?'x':''),$headers,static fn (string $s): int => strlen($s),1024,[200]);
                    self::fail('Unexpected HTTP 500 accepted');
                } catch (R2HttpException $error) {
                    if ($eligible) { self::assertEqualsWithDelta(time()+60,$error->retryNotBefore(),1); }
                    else { self::assertNull($error->retryNotBefore()); }
                }
            }
        } finally { fclose($stream); }
        self::assertSame([], $this->waits);
    }

    public function testTemporaryStatusCannotHideInvalidResponseOrLocalRefusal(): void
    {
        foreach (['response_limit','invalid_headers','ambiguous_retry_after'] as $case) {
            $repository = $this->repository(static function (int $n, string $method, callable $consume) use ($case): array {
                $headers = [];
                if ($case === 'response_limit') { $consume('a HEAD cannot have a body'); }
                if ($case === 'invalid_headers') { $headers['broken'] = ['invalid']; }
                if ($case === 'ambiguous_retry_after') { $headers = ['Retry-After'=>'900','retry-after'=>'0']; }
                return ['status'=>503,'headers'=>$headers];
            });
            try { $repository->verify($this->receipt); self::fail('Invalid response accepted'); }
            catch (\RuntimeException $error) {
                if ($case === 'ambiguous_retry_after') {
                    self::assertInstanceOf(R2HttpException::class,$error);
                    self::assertNull($error->retryNotBefore());
                } else { self::assertNotInstanceOf(R2HttpException::class,$error); }
            }
        }
        self::assertSame([], $this->waits);
    }

    public function testAClockGoingBackwardsStopsRetrying(): void
    {
        $now = 1700000000;
        $repository = $this->repository(static function (int $n, string $method): void { throw new R2TransportException(28); }, static function () use (&$now): int { $now -= 60; return $now; });
        try { $repository->verify($this->receipt); self::fail('Expected failure.'); }
        catch (R2TransportException $error) { self::assertSame('R2 transport failed (connection, errno 28, attempt 1 of 4).', $error->getMessage()); }
        self::assertSame(['HEAD'], $this->methods());
        self::assertSame([], $this->waits);
    }

    public function testTheMessageCanOnlyCarryAFixedKindAndNumbers(): void
    {
        $error = new R2TransportException(56, 1, 4, 'https://example.invalid/?Signature=private');
        self::assertSame('other', $error->kind());
        self::assertFalse($error->isTransient());
        self::assertSame('R2 transport failed (other, errno 56, attempt 1 of 4).', $error->getMessage());
    }

    public function testPutAndDeleteAreNeverResent(): void
    {
        file_put_contents($this->directory . '/input', $this->body);
        try { $this->repository(static function (int $n, string $method): void { if ($method === 'PUT') { throw new R2TransportException(56); } })->putNew($this->receipt['key'], $this->directory . '/input'); self::fail('Expected failure.'); }
        catch (R2TransportException $error) { self::assertSame(56, $error->errno()); }
        self::assertSame(['PUT'], $this->methods());
        $this->calls = [];
        $repository = $this->repository(static function (int $n, string $method): void { if ($method === 'DELETE') { throw new R2TransportException(56); } });
        $delete = new \ReflectionMethod($repository, 'deleteKey'); $delete->setAccessible(true);
        try { $delete->invoke($repository, $this->receipt['key']); self::fail('Expected failure.'); }
        catch (R2TransportException $error) { self::assertSame(56, $error->errno()); }
        self::assertSame(['DELETE'], $this->methods());
    }

    public function testMetadataAndListingRetriesStartFromAnEmptyBody(): void
    {
        $id = str_repeat('1', 64);
        $record = json_encode(['snapshotId' => $id, 'manifest' => $this->receipt + []]);
        $unit = 'wordpress-r2/' . $this->connection['unitId'] . '/';
        $listing = '<ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>' . $unit . 'commits/' . $id . '.json</Key></Contents></ListBucketResult>';
        $repository = $this->repository(function (int $n, string $method, callable $consume) use ($listing): ?array {
            if ($method !== 'GET') { return null; }
            if ($n === 1) { $consume(substr($listing, 0, 20)); throw new R2TransportException(18); }
            $consume($listing); return ['status' => 200, 'headers' => []];
        });
        $ids = new \ReflectionMethod($repository, 'snapshotIds'); $ids->setAccessible(true);
        self::assertSame([$id], $ids->invoke($repository));
        self::assertSame(2, count($this->calls));
        $this->calls = [];
        $repository = $this->repository(function (int $n, string $method, callable $consume) use ($record): ?array {
            if ($n === 1) { $consume(substr($record, 0, 9)); throw new R2TransportException(52); }
            $consume($record); return ['status' => 200, 'headers' => []];
        });
        $optional = new \ReflectionMethod($repository, 'optionalText'); $optional->setAccessible(true);
        self::assertSame($record, $optional->invoke($repository, 'commits/' . $id . '.json'));
    }

    public function testEachAttemptIsSignedAgainAtItsOwnTime(): void
    {
        $now = 1700000000;
        $this->repository(static function (int $n, string $method): void { if ($method === 'HEAD' && $n === 1) { throw new R2TransportException(7); } }, static function () use (&$now): int { $now += 60; return $now; })->verify($this->receipt);
        self::assertNotSame($this->calls[0]['headers']['x-amz-date'], $this->calls[1]['headers']['x-amz-date']);
        self::assertNotSame($this->calls[0]['headers']['authorization'], $this->calls[1]['headers']['authorization']);
    }

    public function testTheLogCarriesNumbersOnly(): void
    {
        try { $this->repository(static function (int $n, string $method): void { if ($method === 'HEAD') { throw new R2TransportException(28); } })->verify($this->receipt); }
        catch (R2TransportException $expected) {}
        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('wrb-r2-transport method=HEAD kind=connection errno=28 attempt=1/4 retry=yes', $log);
        self::assertStringContainsString('wrb-r2-transport method=HEAD kind=connection errno=28 attempt=4/4 retry=no', $log);
        foreach ([$this->connection['secretAccessKey'], $this->connection['accessKeyId'], $this->connection['accountId'], $this->connection['bucket'], $this->connection['unitId'], str_repeat('4', 64), 'https://', 'Signature='] as $private) { self::assertStringNotContainsString($private, $log); }
    }

    /** Real cURL, real TLS on loopback: the numbers the classification depends on. */
    public function testRealCurlReportsTheFailureNumbers(): void
    {
        if (!extension_loaded('curl') || !extension_loaded('openssl')) { self::markTestSkipped('cURL and OpenSSL are required.'); }
        $config = $this->directory . '/openssl.cnf';
        file_put_contents($config, "[req]\ndistinguished_name=d\nx509_extensions=v\nprompt=no\n[d]\nCN=localhost\n[v]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyCertSign\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid:always\nextendedKeyUsage=serverAuth\n");
        $options = ['config' => $config, 'private_key_bits' => 2048, 'digest_alg' => 'sha256', 'x509_extensions' => 'v'];
        $key = openssl_pkey_new($options);
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'localhost'], $key, $options), null, $key, 2, $options);
        openssl_x509_export_to_file($certificate, $this->directory . '/cert.pem'); openssl_pkey_export_to_file($key, $this->directory . '/key.pem', null, $options);
        $listener = stream_socket_server('tcp://127.0.0.1:0'); $port = (int) substr(strrchr((string) stream_socket_get_name($listener, false), ':'), 1); fclose($listener);
        $server = $this->directory . '/server.php';
        file_put_contents($server, '<?php $c=stream_context_create(["ssl"=>["local_cert"=>' . var_export($this->directory . '/cert.pem', true) . ',"local_pk"=>' . var_export($this->directory . '/key.pem', true) . ',"allow_self_signed"=>true,"verify_peer"=>false]]);$s=stream_socket_server("ssl://127.0.0.1:' . $port . '",$a,$b,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$c);while(true){$k=@stream_socket_accept($s,30);if($k===false){continue;}$l=(string)fgets($k);while(($h=fgets($k))!==false&&trim($h)!==""){}if(strpos($l,"/truncate")!==false){fwrite($k,"HTTP/1.1 200 OK\r\nContent-Length: 100\r\nConnection: close\r\n\r\n0123456789");}elseif(strpos($l,"/ok")!==false){fwrite($k,"HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");}fclose($k);}');
        $process = proc_open([PHP_BINARY, $server], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $client = $this->directory . '/client.php';
        file_put_contents($client, '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';$r=new WordPressR2Backup\DirectR2Repository(' . var_export($this->connection, true) . ');$m=new ReflectionMethod($r,"curl");$m->setAccessible(true);$c=$argv[2]==="reject"?static function(string $b):int{return 0;}:static function(string $b):int{return strlen($b);};try{$o=$m->invoke($r,["method"=>"GET","url"=>$argv[1],"headers"=>["x"=>"y"],"body"=>null,"bodyBytes"=>0],$c);echo "status=",$o["status"];}catch(WordPressR2Backup\R2TransportException $e){echo "errno=",$e->errno();}catch(Throwable $e){echo "other";}');
        $run = function (string $url, string $mode, bool $trusted) use ($client): string {
            $command = array_merge([PHP_BINARY], $trusted ? ['-d', 'curl.cainfo=' . $this->directory . '/cert.pem', '-d', 'openssl.cafile=' . $this->directory . '/cert.pem'] : ['-d', 'curl.cainfo=', '-d', 'openssl.cafile='], [$client, $url, $mode]);
            $child = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $childPipes);
            $output = (string) stream_get_contents($childPipes[1]); fclose($childPipes[1]); proc_close($child);
            return $output;
        };
        try {
            $base = 'https://localhost:' . $port;
            for ($try = 0; $try < 40 && $run($base . '/ok', 'accept', true) !== 'status=200'; $try++) { usleep(100000); }
            self::assertSame('status=200', $run($base . '/ok', 'accept', true));
            $results = ['truncated' => $run($base . '/truncate', 'accept', true), 'empty' => $run($base . '/empty', 'accept', true), 'writeRefused' => $run($base . '/ok', 'reject', true), 'untrusted' => $run($base . '/ok', 'accept', false)];
            self::assertSame('errno=18', $results['truncated'], 'A cut body is a partial transfer');
            self::assertContains($results['empty'], ['errno=52', 'errno=56'], 'A closed connection is a receive failure');
            self::assertSame('errno=23', $results['writeRefused'], 'A refused local write has its own number');
            self::assertSame('callback', (new R2TransportException(23))->kind());
            self::assertMatchesRegularExpression('/\Aerrno=(60|51|35|77)\z/', $results['untrusted'], 'An untrusted certificate is a TLS failure');
            self::assertSame('tls', (new R2TransportException((int) substr($results['untrusted'], 6)))->kind(), 'and is never retried');
            self::assertTrue((new R2TransportException(18))->isTransient());
        } finally { proc_terminate($process); proc_close($process); }
    }
}
