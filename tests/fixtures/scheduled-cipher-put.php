<?php
// Used only by BackupSchedulerLaunchIntegrationTest inside its isolated WordPress, with synthetic storage.
declare(strict_types=1);

use WordPressR2Backup\{BackupHistory,DirectR2Repository,LiveBackup,LiveSettings};
use WordPressR2Backup\Tests\MemoryR2Transport;

require_once __DIR__.'/../MemoryR2Transport.php';
$settings = LiveSettings::load();
$storage = new MemoryR2Transport($directory);
$trace = [];
$marker = $directory.'/interrupted-object';
$transport = static function (array $request, callable $consume) use ($storage,$directory,$marker,$fault,&$trace): array {
    $method = $request['method'];
    $path = parse_url($request['url'], PHP_URL_PATH);
    if ($fault !== 'healthy' && $method === 'PUT' && str_ends_with($path,'.age')) {
        if (($request['headers']['if-none-match'] ?? null) !== '*') { throw new LogicException('Missing conditional header'); }
        file_put_contents($marker,$path);
        if ($fault !== 'persistent') {
            if ($storage($request,$consume)['status'] !== 200) { throw new LogicException('Expected a new synthetic object'); }
            if ($fault === 'corrupt') {
                $file = $directory.'/'.hash('sha256',$path).'.cipher';
                $stream = fopen($file,'r+b'); fwrite($stream,'X'); fclose($stream);
            }
        }
        return ['status'=>500,'headers'=>$fault === 'persistent' ? [] : ['retry-after'=>'600']];
    }
    $response = $storage($request,$consume);
    if (is_file($marker) && file_get_contents($marker) === $path) { $trace[] = [$method,$response['status']]; }
    return $response;
};
$connection = array_intersect_key($settings,array_flip(['accountId','bucket','accessKeyId','secretAccessKey','unitId']));
$backup = new LiveBackup($settings,new DirectR2Repository($connection,$transport));
$state = $backup->state();
$error = null;
try {
    if ($action === 'capture') {
        $backup->begin($state['launchId']); $backup->step();
    } elseif ($action === 'resume') {
        $ticket = json_decode(file_get_contents($backup->workspace().'/launch-'.$state['launchId'].'.json'),true,16,JSON_THROW_ON_ERROR);
        $backup->runQueued($state['launchId'],$ticket);
    } else { $backup->step(); }
} catch (RuntimeException $failure) { $error = $failure->getMessage(); }
$history = (new BackupHistory($backup->workspace()))->find($state['id']);
echo json_encode(['state'=>$backup->state(),'events'=>$history['events'],'trace'=>$trace,'error'=>$error],JSON_THROW_ON_ERROR);
