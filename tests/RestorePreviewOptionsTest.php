<?php
declare(strict_types=1);

namespace WordPressR2Backup\Tests;

use PHPUnit\Framework\TestCase;
use WordPressR2Backup\RestorePlan;
use WordPressR2Backup\RestoreService;

final class RestorePreviewOptionsTest extends TestCase
{
    public function testSeparatedAddressesAreBoundToTheConfirmationAndDoNotAuthorizeADatabaseRewrite(): void
    {
        $input = ['snapshot' => str_repeat('1', 64), 'name' => 'example', 'purpose' => 'startup-check', 'database' => 'sqlite', 'previewPort' => '8181',
            'urlMode' => 'config', 'targetUrl' => 'https://EXAMPLE.com:443/blog/', 'targetSiteUrl' => 'https://example.com/blog/wordpress', 'aliases' => 'unused'];
        $plan = RestorePlan::normalize($input, []);
        self::assertSame(['home' => 'https://example.com/blog', 'siteurl' => 'https://example.com/blog/wordpress'], RestorePlan::publicationUrls($plan));
        self::assertNull($plan['targetUrl'], 'An older worker cannot interpret this as a DB migration');
        self::assertSame([], $plan['aliases']);
        $changed = RestorePlan::normalize(['targetSiteUrl' => 'https://example.com/blog/wp'] + $input, []);
        self::assertNotSame(RestorePlan::hash($plan, []), RestorePlan::hash($changed, []));
        $keep = RestorePlan::normalize(['urlMode' => 'keep'] + $input, []);
        self::assertSame(['home' => null, 'siteurl' => null], RestorePlan::publicationUrls($keep));
        $this->expectException(\WordPressR2Backup\RestoreError::class);
        RestorePlan::normalize(['urlMode' => 'database'] + $input, []);
    }

    /** @dataProvider invalidPublicationAddresses */
    public function testPublicationAddressesMustMatchTheSupportedLayout(string $address): void
    {
        $this->expectException(\WordPressR2Backup\RestoreError::class);
        RestorePlan::normalize(['snapshot' => str_repeat('1', 64), 'name' => 'example', 'purpose' => 'full', 'database' => 'sqlite', 'previewPort' => '8181',
            'urlMode' => 'config', 'targetUrl' => 'https://example.com/blog', 'targetSiteUrl' => $address], []);
    }

    public function invalidPublicationAddresses(): array
    {
        return [['https://other.example.com/blog/wp'], ['http://example.com/blog/wp'], ['https://example.com/blog2/wp'], ['https://example.com/blog/%2fwp'], ['https://example.com/blog/../wp'], ['https://example.com/blog//wp'], ['https://example.com/blog/wp?query=1'], ['http://127.1.2.3:8181'], ['http://[::1]:8181'], ['http://localhost.:8181']];
    }

    public function testAdministratorIsOptInAndDoesNotUseStaleFields(): void
    {
        $input = ['snapshot' => str_repeat('1', 64), 'name' => 'example', 'purpose' => 'startup-check', 'database' => 'sqlite', 'previewPort' => '8181', 'adminLogin' => 'preview-admin'];
        self::assertNull(RestorePlan::normalize($input, [])['adminLogin']);
        $input['createPreviewAdmin'] = '1';
        self::assertSame('preview-admin', RestorePlan::normalize($input, [])['adminLogin']);
    }

    public function testPortSuggestionSkipsThePortInUse(): void
    {
        $first = @stream_socket_server('tcp://127.0.0.1:8181');
        $second = @stream_socket_server('tcp://127.0.0.1:8182');
        try {
            if ($first === false || $second === false) { self::markTestSkipped('The test needs two unused local ports.'); }
            fclose($second); $second = false;
            $service = new RestoreService(['base' => Fixture::temp(), 'age' => PHP_BINARY, 'source' => ['type' => 'unconfigured'], 'isolationSupported' => false]);
            self::assertSame(8182, $service->context()['suggestedPort']);
        } finally {
            if (is_resource($first)) { fclose($first); }
            if (is_resource($second)) { fclose($second); }
            Fixture::cleanup();
        }
    }
}
