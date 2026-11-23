<?php
declare(strict_types=1);
// Synthetic cURL for DirectR2Repository::verifyMany(). No network. Usage: php r2-parallel-verify.php <scenario>
namespace WordPressR2Backup {
    function curl_init() { ++$GLOBALS['r2']['inits']; return (object) ['opts' => [], 'status' => 0, 'errno' => 0]; }
    function curl_close($h): void {}
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
    use WordPressR2Backup\{DirectR2Repository, R2HttpException, R2TransportException};

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

    $scenario = $argv[1] ?? '';
    $unit = str_repeat('c', 64); $snapshot = str_repeat('a', 64);
    $GLOBALS['r2'] = ['inits' => 0, 'parallel' => 0, 'objects' => [], 'attempts' => [], 'events' => [], 'faults' => [], 'puts' => [], 'now' => 1800000000, 'waits' => [],
        'ticks' => 5000000000000, 'launches' => [], 'failures' => []];
    $receipts = [];
    for ($number = 0; $number < 20; $number++) {
        $key = 'packs/' . str_repeat('d', 64) . '/' . str_pad(dechex($number), 64, '0', STR_PAD_LEFT) . '.age';
        $body = 'synthetic ciphertext ' . $number . str_repeat('#', 10 * $number);
        $GLOBALS['r2']['objects'][$key] = $body; $receipts[] = ['key' => $key, 'bytes' => strlen($body), 'sha256' => hash('sha256', $body)];
    }
    $manifestBody = 'synthetic manifest'; $manifestKey = 'manifests/' . $snapshot . '.age';
    $GLOBALS['r2']['objects'][$manifestKey] = $manifestBody;
    $manifest = ['key' => $manifestKey, 'bytes' => strlen($manifestBody), 'sha256' => hash('sha256', $manifestBody)];
    $third = $receipts[3]['key']; $fifth = $receipts[5]['key'];
    $faults = [
        'success' => [],
        'retry' => ['GET ' . $third => [['errno' => 56, 'partial' => 9], ['errno' => 28, 'partial' => 4]], 'HEAD ' . $fifth => [['errno' => 7]]],
        'tls' => ['GET ' . $third => [['errno' => 60]]],
        'corrupt' => ['GET ' . $fifth => [['body' => str_repeat('x', $receipts[5]['bytes'])]]],
        'length' => ['HEAD ' . $fifth => [['length' => $receipts[5]['bytes'] + 1]]],
        'overflow' => ['GET ' . $fifth => [['body' => $GLOBALS['r2']['objects'][$fifth] . 'extra']]],
        'status' => ['GET ' . $third => [['status' => 403]]],
        'throttled' => ['GET ' . $third => [['status' => 429]]],
        'server' => ['GET ' . $third => [['status' => 503]]],
        'retry_then_denied' => ['HEAD ' . $fifth => [['errno' => 7]], 'HEAD ' . $third => [['status' => 403]]],
        'missing' => ['HEAD ' . $third => [['status' => 404]]],
        'budget' => ['GET ' . $third => [['errno' => 56, 'advance' => 181]]],
        'exhausted' => ['GET ' . $third => [['errno' => 56], ['errno' => 56], ['errno' => 56], ['errno' => 56]]],
        // Two retries wait at once with different deadlines; the later one must not start when the earlier wait ends.
        'staggered' => ['GET ' . $third => [['errno' => 56]], 'GET ' . $fifth => [['errno' => 56, 'ticks' => 1500000000]]],
        'boundary15' => [], 'boundary16' => [],
    ];
    if (!array_key_exists($scenario, $faults)) { throw new RuntimeException('Unknown scenario'); }
    $GLOBALS['r2']['faults'] = $faults[$scenario];
    $logged = [];
    set_error_handler(static function (): bool { return false; });
    ini_set('error_log', sys_get_temp_dir() . '/wrb-parallel-' . getmypid() . '.log');
    $repository = new DirectR2Repository(['accountId' => str_repeat('a', 32), 'bucket' => 'synthetic-bucket', 'accessKeyId' => str_repeat('A', 16), 'secretAccessKey' => str_repeat('b', 32), 'unitId' => $unit],
        null, static fn (): int => $GLOBALS['r2']['now'],
        static function (int $seconds): void { $GLOBALS['r2']['waits'][] = $seconds; $GLOBALS['r2']['now'] += $seconds; $GLOBALS['r2']['ticks'] += $seconds * 1000000000; },
        static fn (): int => $GLOBALS['r2']['ticks']);
    if (in_array($scenario,['boundary15','boundary16'],true)) {
        $count=$scenario==='boundary15'?15:16; $repository->verifyMany(array_slice($receipts,0,$count));
        check($GLOBALS['r2']['parallel']===($count===15?4:8),'Incorrect threshold concurrency');
        foreach(array_slice($receipts,0,$count) as $receipt) {
            check(($GLOBALS['r2']['attempts']['HEAD '.$receipt['key']]??0)===1 && ($GLOBALS['r2']['attempts']['GET '.$receipt['key']]??0)===1,'Boundary skipped verification');
        }
        echo 'ok '.$scenario."\n"; exit;
    }
    $failure = null;
    try { $repository->publishWithObjects($snapshot, $manifest, $receipts); }
    catch (\Throwable $error) { $failure = $error; }
    $safeLog = is_file(ini_get('error_log')) ? file_get_contents(ini_get('error_log')) : '';
    @unlink(ini_get('error_log'));
    if ($scenario === 'retry_then_denied') {
        check(strpos($safeLog, 'kind=connection errno=7 attempt=1/4 retry=yes') !== false, 'The first transfer was retryable');
        check(strpos($safeLog, 'method=HEAD reason=http_status status=403') !== false, 'The final rejection must be distinguishable');
        check($failure instanceof R2HttpException && $failure->retryNotBefore() === null, 'The earlier error must not authorize a retry');
    }

    $commit = 'commits/' . $snapshot . '.json';
    // No attempt may start before its previous failure plus the retry delay, measured on the monotonic time.
    foreach ($GLOBALS['r2']['failures'] as $name => $failed) {
        foreach ($failed as $attempt => $at) {
            if (!isset($GLOBALS['r2']['launches'][$name][$attempt])) { continue; }
            $earliest = $at + [2, 5, 15][$attempt - 1] * 1000000000;
            check($GLOBALS['r2']['launches'][$name][$attempt] >= $earliest, 'Retry ' . ($attempt + 1) . ' of ' . $name . ' started ' . (($earliest - $GLOBALS['r2']['launches'][$name][$attempt]) / 1e9) . ' s early');
        }
    }
    $reads = array_values(array_filter($GLOBALS['r2']['events'], static fn (array $event): bool => $event[0] !== 'PUT' && $event[1] !== $commit && strpos($event[1], 'deletions/') !== 0));
    check($reads !== [] && count(array_filter($reads, static fn (array $event): bool => !$event[2])) === 0, 'Object verification must use the parallel reader');
    check($GLOBALS['r2']['parallel'] === 8, 'Expected eight reads at once, saw ' . $GLOBALS['r2']['parallel']);
    $expected = array_merge([$manifest], $receipts);
    if (in_array($scenario, ['success', 'retry', 'staggered'], true)) {
        check($failure === null, 'Publication failed: ' . ($failure ? get_class($failure) . ' ' . $failure->getMessage() : ''));
        check($GLOBALS['r2']['puts'] === [$commit], 'Exactly one completion record must be written');
        $lastRead = max(array_keys(array_filter($GLOBALS['r2']['events'], static fn (array $event): bool => $event[0] === 'GET' && $event[2])));
        $put = array_search(['PUT', $commit, false], $GLOBALS['r2']['events'], true);
        check($put > $lastRead, 'The completion record must follow every verified read');
        foreach ($expected as $receipt) {
            $retried = $scenario === 'retry' && $receipt['key'] === $third ? 3 : (($scenario === 'staggered' && in_array($receipt['key'], [$third, $fifth], true)) ? 2 : 1);
            check(($GLOBALS['r2']['attempts']['GET ' . $receipt['key']] ?? 0) === $retried, 'Unexpected GET attempts');
            check(($GLOBALS['r2']['attempts']['HEAD ' . $receipt['key']] ?? 0) === ($scenario === 'retry' && $receipt['key'] === $fifth ? 2 : 1), 'Unexpected HEAD attempts');
        }
        $heads = array_keys(array_filter($reads, static fn (array $event): bool => $event[0] === 'HEAD'));
        $gets = array_keys(array_filter($reads, static fn (array $event): bool => $event[0] === 'GET'));
        check(max($heads) < min($gets), 'Every length must be checked before whole-object reads begin');
        check($GLOBALS['r2']['waits'] === ['success' => [], 'retry' => [2, 2, 5], 'staggered' => [2]][$scenario], 'Unexpected retry delays ' . json_encode($GLOBALS['r2']['waits']));
        check(count($GLOBALS['r2']['failures']) === ['success' => 0, 'retry' => 2, 'staggered' => 2][$scenario], 'Expected failures did not happen');
        $completed = $repository->completed($snapshot);
        check($completed['objects'] === $receipts && $completed['manifest'] === $manifest, 'Completion record differs');
        $GLOBALS['r2']['objects'][$fifth] = str_repeat('y', $receipts[5]['bytes']);
        $rejected = false; try { $repository->completed($snapshot); } catch (RuntimeException $error) { $rejected = $error->getMessage() === 'Ciphertext verification failed.'; }
        check($rejected, 'A changed stored object must fail completed()');
    } else {
        check($failure !== null, 'A failed verification published');
        check($GLOBALS['r2']['puts'] === [] && !array_key_exists($commit, $GLOBALS['r2']['objects']), 'A failed verification wrote a completion record');
        $want = [
            'tls' => [R2TransportException::class, 'R2 transport failed (tls, errno 60, attempt 1 of 4).'],
            'corrupt' => [RuntimeException::class, 'Ciphertext verification failed.'],
            'length' => [RuntimeException::class, 'Stored ciphertext length does not match.'],
            'overflow' => [R2TransportException::class, 'R2 transport failed (overflow, errno 23, attempt 1 of 4).'],
            'status' => [R2HttpException::class, 'R2 returned HTTP 403.'],
            'throttled' => [R2HttpException::class, 'R2 returned HTTP 429.'],
            'server' => [R2HttpException::class, 'R2 returned HTTP 503.'],
            'missing' => [R2HttpException::class, 'R2 returned HTTP 404.'],
            'retry_then_denied' => [R2HttpException::class, 'R2 returned HTTP 403.'],
            'budget' => [R2TransportException::class, 'R2 transport failed (connection, errno 56, attempt 1 of 4).'],
            'exhausted' => [R2TransportException::class, 'R2 transport failed (connection, errno 56, attempt 4 of 4).'],
        ][$scenario];
        check(get_class($failure) === $want[0] && $failure->getMessage() === $want[1], 'Unexpected failure ' . get_class($failure) . ': ' . $failure->getMessage());
        check($GLOBALS['r2']['waits'] === ($scenario === 'exhausted' ? [2, 5, 15] : []), 'Unexpected retry delays ' . json_encode($GLOBALS['r2']['waits']));
        if ($scenario === 'length' || $scenario === 'missing') { check(count(array_filter($reads, static fn (array $event): bool => $event[0] === 'GET')) === 0, 'Whole-object reads began after a failed length check'); }
    }
    echo 'ok ' . $scenario . "\n";
}
