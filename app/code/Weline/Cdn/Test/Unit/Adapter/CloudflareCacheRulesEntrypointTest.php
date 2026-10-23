<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Adapter\Cloudflare;
use Weline\Cdn\Exception\CloudflareApiException;
use Weline\Cdn\Service\CloudflareHttpClient;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * A zone without the http_request_cache_settings entrypoint answers HTTP 404 / code 10003.
 * The transport redacts the body, so detection must use the typed status + code on the chain.
 */
final class CloudflareCacheRulesEntrypointTest extends TestCase
{
    private const ZONE = 'zone-fixture';
    private const ENTRYPOINT = '/zones/zone-fixture/rulesets/phases/http_request_cache_settings/entrypoint';

    /** @var object{calls: list<array{string,string,?array}>} */
    private object $http;

    protected function setUp(): void
    {
        $this->http = new class {
            /** @var list<array{string,string,?array}> */
            public array $calls = [];
            public ?CloudflareApiException $getFailure = null;

            public function apiWithCredentials(
                array $credentials,
                string $method,
                string $path,
                array $query = [],
                ?array $body = null,
                bool $allowEmpty = false,
            ): array {
                $this->calls[] = [$method, $path, $body];
                if ($method === 'GET' && $this->getFailure !== null) {
                    throw $this->getFailure;
                }

                return ['success' => true, 'result' => ['id' => 'created-ruleset', 'rules' => $body['rules'] ?? []]];
            }
        };
        $http = $this->http;
        ObjectManager::setInstance(CloudflareHttpClient::class, $http);
        // Always Online flag reads SystemConfig; a non-reader keeps the resolver on its default.
        $noReader = new \stdClass();
        ObjectManager::setInstance(ConfigReader::class, $noReader);
    }

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(CloudflareHttpClient::class);
        ObjectManager::removeInstance(ConfigReader::class);
    }

    public function testTypedNotFound10003OnChainIsMissingEntrypoint(): void
    {
        $api = new CloudflareApiException('Cloudflare 请求失败（HTTP 404，代码 10003）。', 404, '10003');

        self::assertTrue(Cloudflare::isMissingCacheRulesEntrypoint($api));
        self::assertTrue(Cloudflare::isMissingCacheRulesEntrypoint(new Core($api->getMessage(), 0, $api)));
    }

    public function testOtherStatusesOrCodesAreNotMissingEntrypoint(): void
    {
        foreach ([[404, '7003'], [403, '10003'], [400, '10000']] as [$status, $code]) {
            $api = new CloudflareApiException('Cloudflare 请求失败。', $status, $code);
            self::assertFalse(
                Cloudflare::isMissingCacheRulesEntrypoint(new Core($api->getMessage(), 0, $api)),
                "HTTP {$status} / {$code} must not be treated as a missing entrypoint",
            );
        }
        // Localized redacted text alone carries no provider semantics.
        self::assertFalse(Cloudflare::isMissingCacheRulesEntrypoint('Cloudflare 请求失败（HTTP 404，代码 10003）。'));
    }

    public function testGetRulesReturnsEmptyWhenEntrypointMissing(): void
    {
        $this->http->getFailure = new CloudflareApiException('Cloudflare 请求失败（HTTP 404，代码 10003）。', 404, '10003');

        self::assertSame([], (new Cloudflare())->getRules(self::ZONE, ['api_token' => 'fixture']));
        self::assertSame([['GET', self::ENTRYPOINT, null]], $this->http->calls);
    }

    public function testGetRulesKeepsOtherApiFailuresWithCause(): void
    {
        $denied = new CloudflareApiException('Cloudflare 请求失败（HTTP 403，代码 10000）。', 403, '10000');
        $this->http->getFailure = $denied;

        try {
            (new Cloudflare())->getRules(self::ZONE, ['api_token' => 'fixture']);
            self::fail('Non-entrypoint failures must surface.');
        } catch (Core $e) {
            self::assertSame($denied, $e->getPrevious());
        }
    }

    public function testPutRulesCreatesEntrypointWhenMissing(): void
    {
        $this->http->getFailure = new CloudflareApiException('Cloudflare 请求失败（HTTP 404，代码 10003）。', 404, '10003');

        $result = (new Cloudflare())->putRules(
            self::ZONE,
            [['description' => 'static', 'expression' => 'true', 'action' => ['cache' => ['ttl' => 3600]]]],
            ['api_token' => 'fixture'],
        );

        self::assertTrue($result['success'] ?? false, (string)($result['message'] ?? ''));
        self::assertSame('GET', $this->http->calls[0][0]);
        self::assertSame('PUT', $this->http->calls[1][0]);
        self::assertSame(self::ENTRYPOINT, $this->http->calls[1][1]);
        self::assertNotEmpty($this->http->calls[1][2]['rules'] ?? []);
    }
}
