<?php
declare(strict_types=1);

// Web requests never reach the worker. Its output goes nowhere; the status file carries only fixed text.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
umask(0077);
ini_set('display_errors', '0'); ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
set_error_handler(static function (int $severity, string $message): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException('A filesystem or runtime operation failed.', 0, $severity);
});
require_once __DIR__ . '/vendor/autoload.php';
try {
    if (count($argv) !== 3) { exit(2); }
    $frame = stream_get_contents(STDIN, 1048577);
    if (!is_string($frame) || strlen($frame) > 1048576) { exit(2); }
    fclose(STDIN);
    exit(\WordPressR2Backup\RestoreWorker::main($argv[1], $argv[2], $frame));
} catch (Throwable $error) { exit(1); }
