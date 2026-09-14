<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Extends\Module\Weline_Shipping\ShippingProvider\YanwenOpenApiClient;

final class YanwenOpenApiClientSignTest extends TestCase
{
    public function testSignMatchesDocumentedFormula(): void
    {
        $client = new YanwenOpenApiClient();
        $token = 'token';
        $userId = '100';
        $data = '{"weight":100}';
        $format = 'json';
        $method = 'calc.list';
        $timestamp = '1710000000000';
        $version = 'V1.0';
        $expected = md5($token . $userId . $data . $format . $method . $timestamp . $version . $token);
        self::assertSame(
            $expected,
            $client->sign($token, $userId, $data, $format, $method, $timestamp, $version),
        );
    }

    public function testSandboxEndpoint(): void
    {
        $client = new YanwenOpenApiClient();
        self::assertStringContainsString('open-fat.yw56.com.cn', $client->endpointForEnvironment('sandbox'));
        self::assertStringContainsString('open.yw56.com.cn', $client->endpointForEnvironment('live'));
    }

    public function testCallUsesInjectedHttpHandler(): void
    {
        $handler = static function (string $url, array $headers, string $body): array {
            self::assertStringContainsString('method=calc.list', $url);
            self::assertSame('application/json', $headers['Content-Type'] ?? '');
            self::assertStringContainsString('"weight":100', $body);

            return [
                'status' => 200,
                'body' => json_encode([
                    'success' => true,
                    'code' => '0',
                    'data' => [['productNumber' => '1', 'productName' => 'Demo', 'totalMoney' => 12.3, 'currency' => 'CNY']],
                ], JSON_THROW_ON_ERROR),
            ];
        };
        $client = new YanwenOpenApiClient($handler);
        $response = $client->call('u1', 'tok', 'calc.list', ['weight' => 100], 'sandbox');
        self::assertTrue((bool)$response['success']);
        self::assertSame('1', $response['data'][0]['productNumber']);
    }
}
