<?php
declare(strict_types=1);
// Synthetic cURL for DirectR2Repository::putNewPages(). No network or recovery.
namespace WordPressR2Backup {
    function curl_init() { ++$GLOBALS['r2']['inits']; return (object) ['opts' => [], 'status' => 0, 'errno' => 0]; }
    function curl_close($h): void { ++$GLOBALS['r2']['closed']; }
    function curl_reset($h): void { $h->opts = []; }
    function curl_setopt_array($h, array $o): bool { $h->opts = $o + $h->opts; return true; }
    function curl_setopt($h, int $option, $value): bool { $h->opts[$option] = $value; return true; }
    function curl_errno($h): int { return $h->errno; }
    function curl_getinfo($h, int $option) { return $h->status; }
    function curl_exec($h) { $h->errno = \r2_perform($h, false); return $h->errno === 0; }
    function curl_multi_init() { return (object) ['handles' => [], 'done' => []]; }
    function curl_multi_add_handle($m, $h): int {
        $name = \r2_name($h->opts); $GLOBALS['r2']['launches'][$name][] = $GLOBALS['r2']['ticks'];
        $h->finished = false; $m->handles[] = $h; $GLOBALS['r2']['parallel'] = max($GLOBALS['r2']['parallel'], count($m->handles)); return CURLM_OK; }
    function curl_multi_remove_handle($m, $h): int { $m->handles = array_values(array_filter($m->handles, static fn ($x): bool => $x !== $h)); return CURLM_OK; }
    function curl_multi_exec($m, &$running): int {
        // Finish the most recently added unfinished transfer first, so completions arrive out of order.
        foreach (array_reverse($m->handles) as $h) {
            if (!empty($h->finished)) { continue; }
            $h->finished = true; $m->done[] = ['msg' => CURLMSG_DONE, 'result' => \r2_perform($h, true), 'handle' => $h]; break;
        }
        $running = count(array_filter($m->handles, static fn ($h): bool => empty($h->finished)));
        return CURLM_OK;
    }
    function curl_multi_info_read($m) { return $m->done === [] ? false : array_shift($m->done); }
    function curl_multi_select($m, float $timeout = 1.0): int { return 0; }
    function curl_multi_close($m): void {}
}

namespace {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    use WordPressR2Backup\{DirectR2Repository, R2TransportException};

    function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }

    function r2_name(array $o): string
    {
        return $o[CURLOPT_CUSTOMREQUEST] . ' ' . substr(parse_url($o[CURLOPT_URL], PHP_URL_PATH), strlen('/synthetic-bucket/wordpress-r2/' . str_repeat('c', 64) . '/'));
    }

    /** Returns the cURL result code of one synthetic transfer. */
    function r2_perform($h, bool $multi): int
    {
        $state = &$GLOBALS['r2']; $o = $h->opts; $h->status = 0;
        $method = $o[CURLOPT_CUSTOMREQUEST];
        check(($o[CURLOPT_SSL_VERIFYPEER] ?? null) === true && ($o[CURLOPT_SSL_VERIFYHOST] ?? null) === 2 && ($o[CURLOPT_FOLLOWLOCATION] ?? null) === false
            && ($o[CURLOPT_PROTOCOLS] ?? null) === CURLPROTO_HTTPS && ($o[CURLOPT_REDIR_PROTOCOLS] ?? null) === CURLPROTO_HTTPS, 'TLS policy changed');
        check(in_array($method, ['GET', 'HEAD'], true) === isset($o[CURLOPT_LOW_SPEED_TIME]), 'Stall limit must apply to reads only');
        check($method === 'PUT' ? !empty($o[CURLOPT_UPLOAD]) && !empty($o[CURLOPT_FRESH_CONNECT]) : !isset($o[CURLOPT_UPLOAD]) && empty($o[CURLOPT_FRESH_CONNECT]), 'Upload state leaked');
        check(($method === 'HEAD') === !empty($o[CURLOPT_NOBODY]), 'HEAD state leaked');
        $headers = [];
        foreach ($o[CURLOPT_HTTPHEADER] as $line) { [$name, $value] = explode(':', $line, 2); $headers[strtolower($name)] = trim($value); }
        check(($headers['x-amz-date'] ?? '') === gmdate('Ymd\THis\Z', $state['now']), 'Each attempt must be signed when it starts');
        check(strpos($headers['authorization'] ?? '', 'AWS4-HMAC-SHA256 Credential=') === 0, 'Missing signature');
        $path = parse_url($o[CURLOPT_URL], PHP_URL_PATH);
        $key = substr($path, strlen('/synthetic-bucket/wordpress-r2/' . str_repeat('c', 64) . '/'));
        $attempt = $state['attempts'][$method . ' ' . $key] = ($state['attempts'][$method . ' ' . $key] ?? 0) + 1;
        $state['events'][] = [$method, $key, $multi];
        $fault = $state['faults'][$method . ' ' . $key][$attempt - 1] ?? null;
        if (is_array($fault) && isset($fault['advance'])) { $state['now'] += $fault['advance']; }
        if (is_array($fault) && isset($fault['ticks'])) { $state['ticks'] += $fault['ticks']; }
        if (is_array($fault) && isset($fault['partial'])) { ($o[CURLOPT_WRITEFUNCTION])($h, substr($state['objects'][$key], 0, $fault['partial'])); }
        if (is_array($fault) && isset($fault['errno'])) { $state['failures'][$method . ' ' . $key][$attempt] = $state['ticks']; return $fault['errno']; }
        if (is_array($fault) && isset($fault['status'])) { $h->status = $fault['status']; return 0; }
        if ($method === 'PUT') {
            check(($headers['if-none-match'] ?? '') === '*', 'Missing conditional PUT');
            check(strpos($headers['authorization'], 'if-none-match') !== false, 'Conditional header is unsigned');
            $body=stream_get_contents($o[CURLOPT_INFILE]); rewind($o[CURLOPT_INFILE]);
            check(($headers['x-amz-content-sha256']??'')===hash('sha256',$body) && strpos($headers['authorization'],'x-amz-content-sha256')!==false,'Payload SHA is not signed');
            if (isset($state['objects'][$key])) { $h->status = 412; return 0; }
            $state['objects'][$key] = stream_get_contents($o[CURLOPT_INFILE]); $h->status = 200; $state['puts'][] = $key; return 0;
        }
        if (!isset($state['objects'][$key])) {
            $h->status = 404;
            if ($method === 'GET') { ($o[CURLOPT_WRITEFUNCTION])($h, '<Error/>'); }
            return 0;
        }
        $body = $state['objects'][$key];
        if (is_array($fault) && isset($fault['body'])) { $body = $fault['body']; }
        $h->status = 200;
        ($o[CURLOPT_HEADERFUNCTION])($h, "HTTP/1.1 200 OK\r\n");
        if ($method === 'HEAD') {
            $length = is_array($fault) && isset($fault['length']) ? $fault['length'] : strlen($body);
            ($o[CURLOPT_HEADERFUNCTION])($h, 'Content-Length: ' . $length . "\r\n");
            return 0;
        }
        foreach (str_split($body, 7) as $chunk) { if (($o[CURLOPT_WRITEFUNCTION])($h, $chunk) !== strlen($chunk)) { return 23; } }
        return 0;
    }

    $scenario=$argv[1]??''; $unit=str_repeat('c',64); $snapshot=str_repeat('a',64);
    $GLOBALS['r2']=['inits'=>0,'closed'=>0,'parallel'=>0,'objects'=>[],'attempts'=>[],'events'=>[],'faults'=>[],'puts'=>[],
        'now'=>1800000000,'waits'=>[],'ticks'=>5000000000000,'launches'=>[],'failures'=>[]];
    $directory=realpath(sys_get_temp_dir()).'/wrb-put-pages-'.bin2hex(random_bytes(8)); mkdir($directory,0700);$pages=[];
    for($i=0;$i<16;++$i) {
        $key='pages/'.$snapshot.'/'.str_pad(dechex($i),64,'0',STR_PAD_LEFT).'.age'; $bytes='synthetic ciphertext '.$i;
        $file=$directory.'/'.$i; file_put_contents($file,$bytes);chmod($file,0600);
        $pages[]=['object'=>['key'=>$key,'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)],'cipherFile'=>$file];
    }
    $third=$pages[2]['object']['key'];
    if ($scenario==='existing' || $scenario==='conflict') { $GLOBALS['r2']['objects'][$third]=$scenario==='existing'?'synthetic ciphertext 2':str_repeat('x',$pages[2]['object']['bytes']); }
    if ($scenario==='status409') { $GLOBALS['r2']['objects'][$third]='synthetic ciphertext 2'; $GLOBALS['r2']['faults']['PUT '.$third]=[['status'=>409]]; }
    if ($scenario==='network') { $GLOBALS['r2']['faults']['PUT '.$third]=[['errno'=>7]]; }
    if ($scenario==='tls') { $GLOBALS['r2']['faults']['PUT '.$third]=[['errno'=>60]]; }
    if ($scenario==='http500') { $GLOBALS['r2']['faults']['PUT '.$third]=[['status'=>500]]; }
    if ($scenario==='status') { $GLOBALS['r2']['faults']['PUT '.$third]=[['status'=>403]]; }
    if ($scenario==='redirect') { $GLOBALS['r2']['faults']['PUT '.$third]=[['status'=>302]]; }
    if ($scenario==='bad-input') { file_put_contents($pages[3]['cipherFile'],str_repeat('x',$pages[3]['object']['bytes'])); }
    $repo=new DirectR2Repository(['accountId'=>str_repeat('a',32),'bucket'=>'synthetic-bucket','accessKeyId'=>str_repeat('A',16),'secretAccessKey'=>str_repeat('b',32),'unitId'=>$unit],null,static fn(): int => $GLOBALS['r2']['now'],static function(int $seconds): void { $GLOBALS['r2']['waits'][]=$seconds; },static fn(): int => $GLOBALS['r2']['ticks']);
    $failure=null; $result=null; try { $result=$repo->putNewPages($pages); } catch (\Throwable $error) { $failure=$error; }
    $expected=in_array($scenario,['success','existing','status409'],true);
    check($expected ? $failure===null : $failure!==null,'Unexpected upload result');
    if ($expected) {
        check($result===array_column($pages,'object'),'Input order or receipts differ');
        foreach($pages as $page) { $key=$page['object']['key']; check(($GLOBALS['r2']['attempts']['GET '.$key]??0)===1,'Missing full read'); }
        check($GLOBALS['r2']['parallel']===8,'Expected eight concurrent verification requests');
    }
    if ($scenario==='http500') {
        check($failure instanceof \WordPressR2Backup\R2HttpException && $failure->retryNotBefore()===0,'Conditional parallel PUT must be eligible for scheduled recovery');
        check($GLOBALS['r2']['attempts']['PUT '.$third]===1 && $GLOBALS['r2']['waits']===[],'The transport must not replay the write');
    }
    if ($scenario==='network'||$scenario==='tls') { check(($GLOBALS['r2']['attempts']['PUT '.$third]??0)===1 && $GLOBALS['r2']['waits']===[],'PUT was retried'); }
    if ($scenario==='bad-input') { check($GLOBALS['r2']['events']===[],'PUT started before all files were hashed'); }
    if ($scenario==='status'||$scenario==='redirect') { foreach($GLOBALS['r2']['events'] as $event) { check($event[0]==='PUT','Rejected PUT status reached verification'); } }
    if ($scenario==='conflict') { check(($GLOBALS['r2']['objects'][$third]??null)===str_repeat('x',$pages[2]['object']['bytes']),'Existing object was replaced'); }
    check($GLOBALS['r2']['closed']===$GLOBALS['r2']['inits'],'cURL handle leaked');
    echo 'ok '.$scenario."\n";
}
