<?php
declare(strict_types=1);
namespace WordPressR2Backup\Tests;
use PHPUnit\Framework\TestCase;

/** Synthetic cURL only; no network or recovery. */
final class R2ParallelPagePutTest extends TestCase
{
    /** @dataProvider scenarios */
    public function testParallelPagePutRejectsFaultsAndKeepsVerification(string $scenario): void
    {
        $out=tmpfile();$err=tmpfile();
        $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/r2-parallel-put-pages.php',$scenario],[0=>['file','/dev/null','r'],1=>$out,2=>$err],$pipes);
        self::assertIsResource($process);$exit=proc_close($process);rewind($out);rewind($err);$body=stream_get_contents($out);$error=stream_get_contents($err);fclose($out);fclose($err);
        self::assertSame(0,$exit,$body.$error);self::assertSame('ok '.$scenario."\n",$body);
    }
    public function scenarios(): array { return array_map(static fn(string $scenario): array => [$scenario],['success','existing','status409','conflict','network','tls','status','http500','redirect','bad-input']); }

    public function testInjectedTransportRemainsSerialAndRechecksExistingObjects(): void
    {
        $dir=Fixture::temp();$stored=[];$events=[];$pages=[];
        $transport=static function(array $request,callable $consume) use (&$stored,&$events): array {
            $path=(string)parse_url($request['url'],PHP_URL_PATH);$method=$request['method'];$events[]=$method;
            if($method==='PUT') { self::assertSame('*',$request['headers']['if-none-match']??null); if(isset($stored[$path])) return ['status'=>412,'headers'=>[]]; $stored[$path]=stream_get_contents($request['body']);return ['status'=>200,'headers'=>[]]; }
            if($method==='HEAD') return ['status'=>200,'headers'=>['content-length'=>(string)strlen($stored[$path])]];
            $consume($stored[$path]);return ['status'=>200,'headers'=>[]];
        };
        $repo=new \WordPressR2Backup\DirectR2Repository(['accountId'=>str_repeat('a',32),'bucket'=>'synthetic-bucket','accessKeyId'=>str_repeat('A',16),'secretAccessKey'=>str_repeat('b',32),'unitId'=>str_repeat('c',64)],$transport);
        for($i=0;$i<4;++$i) { $bytes='synthetic'.$i;$file=$dir.'/'.$i;file_put_contents($file,$bytes);chmod($file,0600);$pages[]=['object'=>['key'=>'pages/'.str_repeat('a',64).'/'.hash('sha256',(string)$i).'.age','bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)],'cipherFile'=>$file]; }
        self::assertSame(array_column($pages,'object'),$repo->putNewPages($pages));
        self::assertSame(array_column($pages,'object'),$repo->putNewPages($pages));
        $one=['PUT','HEAD','GET','PUT','HEAD','GET','PUT','HEAD','GET','PUT','HEAD','GET'];
        self::assertSame(array_merge($one,$one),$events);
        Fixture::cleanup();
    }

    public function testMutationChecksDetectMissingHashSigningAndStatusRejection(): void
    {
        $source=file_get_contents(dirname(__DIR__).'/src/DirectR2Repository.php');$directory=Fixture::temp();
        $mutations=[
            ['!hash_equals($receipt[\'sha256\'], hash_final($hash))','false','bad-input'],
            ["'payloadHash'=>\$receipt['sha256']","'payloadHash'=>str_repeat('0',64)",'success'],
            ["'allowed'=>[200,201,204,409,412]","'allowed'=>[200,201,204,302,403,409,412]",'status'],
            ["'allowed'=>[200,201,204,409,412]","'allowed'=>[200,201,204,302,403,409,412]",'redirect'],
        ];
        foreach ($mutations as $index=>[$from,$to,$scenario]) {
            $mutant=str_replace($from,$to,$source,$count); self::assertSame(1,$count);
            $path=$directory.'/mutant-'.$index.'.php';file_put_contents($path,$mutant);
            $bootstrap=$directory.'/bootstrap-'.$index.'.php';file_put_contents($bootstrap,'<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php',true).'; require '.var_export($path,true).';');
            $out=tmpfile();$err=tmpfile();$process=proc_open([PHP_BINARY,'-d','auto_prepend_file='.$bootstrap,__DIR__.'/fixtures/r2-parallel-put-pages.php',$scenario],[0=>['file','/dev/null','r'],1=>$out,2=>$err],$pipes);
            self::assertIsResource($process);$exit=proc_close($process);fclose($out);fclose($err);self::assertNotSame(0,$exit,'Mutation must be detected: '.$scenario);
        }
        Fixture::cleanup();
    }
}
