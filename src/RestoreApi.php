<?php
declare(strict_types=1);

namespace WordPressR2Backup;

/** Maps one authenticated request onto the service. Authentication, CSRF and origin checks belong to the entry that calls this. */
final class RestoreApi
{
    public const OBSERVE_COMMANDS = ['context', 'snapshots', 'status', 'stop', 'site_start', 'site_stop', 'startup_retry'];
    public const START_COMMANDS = ['preflight', 'start'];

    /** @return array{0:int,1:array} HTTP status and body */
    public static function handle(RestoreService $service, string $command, array $post, string $actor): array
    {
        try {
            [$input, $secrets] = self::split($post);
            $name = is_string($post['job'] ?? null) ? $post['job'] : '';
            switch ($command) {
                case 'context': return [200, $service->context()];
                case 'snapshots': return [200, ['snapshots' => $service->snapshots($secrets)]];
                case 'preflight': return [200, $service->preflight($input, $secrets, $actor)];
                case 'start': return [200, $service->start($input, $secrets, is_string($post['planHash'] ?? null) ? $post['planHash'] : '', is_string($post['confirmName'] ?? null) ? trim($post['confirmName']) : '', $actor)];
                case 'status': return [200, $service->status($name === '' ? null : $name)];
                case 'stop': return [200, $service->stop($name)];
                case 'site_start': return [200, $service->startSite($name)];
                case 'site_stop': return [200, $service->stopSite($name)];
                case 'startup_retry': return [200, $service->retryStartup($name, $input, $secrets)];
            }
            return [404, ['error' => '不明な操作です。']];
        } catch (RestoreError $error) {
            return [400, ['error' => $error->getMessage(), 'field' => $error->field()]];
        } catch (\Throwable $error) {
            return [500, ['error' => '処理を完了できませんでした。入力と環境を確認してください。']];
        }
    }

    /** @return array{0:array<string,string>,1:array<string,string>} form text and secret values, each bounded */
    private static function split(array $post): array
    {
        $input = [];
        foreach (RestorePlan::TEXT_FIELDS as $field => $limit) {
            if (isset($post[$field])) {
                if (!is_string($post[$field]) || strlen($post[$field]) > $limit) { throw new RestoreError('入力が長すぎるか、形式が正しくありません。', $field); }
                $input[$field] = $post[$field];
            }
        }
        $secrets = [];
        foreach (RestorePlan::SECRET_FIELDS as $field => $limit) {
            if (isset($post[$field])) {
                if (!is_string($post[$field]) || strlen($post[$field]) > $limit) { throw new RestoreError('入力が長すぎるか、形式が正しくありません。', $field); }
                $secrets[$field] = $post[$field];
            }
        }
        return [$input, $secrets];
    }
}
