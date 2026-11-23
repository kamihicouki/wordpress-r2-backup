<?php
declare(strict_types=1);

namespace WordPressR2Backup;

/** A complete HTTP response with an unexpected status. Never retain raw headers or response data. */
final class R2HttpException extends \RuntimeException
{
    private ?int $retryNotBefore;

    public function __construct(int $status, ?string $retryAfter)
    {
        parent::__construct('R2 returned HTTP ' . $status . '.', $status);
        $this->retryNotBefore = in_array($status, [429, 503], true) ? self::retryTime($retryAfter) : null;
    }

    /** Null requires manual intervention. Zero leaves the normal scheduled backoff in effect. */
    public function retryNotBefore(): ?int { return $this->retryNotBefore; }

    private static function retryTime(?string $header): ?int
    {
        if ($header === null) { return 0; }
        if (preg_match('/\A[0-9]+\z/D', $header)) {
            $seconds = ltrim($header, '0');
            if ($seconds === '') { return 0; }
            $now = time();
            $delay = filter_var($seconds, FILTER_VALIDATE_INT);
            return is_int($delay) && $delay <= PHP_INT_MAX - $now ? $now + $delay : null;
        }
        if (strlen($header) !== 29 || !preg_match('/\A[A-Za-z0-9:, ]+\z/D', $header)) { return null; }
        $format = 'D, d M Y H:i:s \G\M\T';
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $header, new \DateTimeZone('UTC'));
        return $date !== false && $date->format($format) === $header ? max(0, $date->getTimestamp()) : null;
    }
}
