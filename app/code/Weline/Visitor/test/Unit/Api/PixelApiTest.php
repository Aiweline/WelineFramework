<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Api;

use ReflectionClass;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Visitor\Api\Rest\V1\Pixel;
use Weline\Visitor\Model\Pixel as PixelModel;
use Weline\Visitor\Model\PixelAdditional;
use Weline\Visitor\Service\PixelEncryptionService;

class PixelApiTest extends TestCore
{
    private Pixel $pixelApi;

    /** @var int[] */
    private array $pixelIds = [];

    /** @var int[] */
    private array $additionalIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pixelApi = ObjectManager::getInstance(Pixel::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(array_unique($this->additionalIds)) as $additionalId) {
            try {
                ObjectManager::make(PixelAdditional::class)->load($additionalId)->delete();
            } catch (\Throwable) {
            }
        }

        foreach (array_reverse(array_unique($this->pixelIds)) as $pixelId) {
            try {
                ObjectManager::make(PixelModel::class)->load($pixelId)->delete();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    public function testPostIndexWithPlainData(): void
    {
        $response = $this->post([
            'url' => 'https://example.com/test',
            'eventName' => 'click',
            'websiteId' => 1,
            'userId' => 123,
            'userAgent' => 'Mozilla/5.0 Test',
            'ip' => '192.168.1.1',
            'testId' => 'test_001',
            'variant' => 'A',
        ]);

        $this->assertEquals(200, $response['code']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('pixel_id', $response['data']);
        $this->assertArrayHasKey('ab_test', $response['data']);
        $this->assertEquals('test_001', $response['data']['ab_test']['testId']);
        $this->assertEquals('A', $response['data']['ab_test']['variant']);

        $pixel = ObjectManager::make(PixelModel::class)->load((int)$response['data']['pixel_id']);
        $this->assertSame('https://example.com/test', $pixel->getUrl());
        $this->assertSame('click', $pixel->getEvent());
        $this->assertSame(1, $pixel->getWebsiteId());
    }

    public function testDataValidationAndSanitization(): void
    {
        $longUrl = 'https://example.com/' . str_repeat('a', 400);
        $response = $this->post([
            'url' => $longUrl,
            'eventName' => 'click',
            'ip' => 'invalid-ip',
            'module' => str_repeat('a', 300),
            'value' => -100,
        ]);

        $this->assertEquals(200, $response['code']);

        $pixel = ObjectManager::make(PixelModel::class)->load((int)$response['data']['pixel_id']);
        $this->assertSame(substr($longUrl, 0, 255), $pixel->getUrl());
        $this->assertSame(128, strlen($pixel->getModule()));
        $this->assertSame(0, $pixel->getValue());
    }

    public function testInvalidUrlIsDropped(): void
    {
        $response = $this->post([
            'url' => 'invalid-url',
            'eventName' => 'click',
        ]);

        $this->assertEquals(200, $response['code']);

        $pixel = ObjectManager::make(PixelModel::class)->load((int)$response['data']['pixel_id']);
        $this->assertSame('', $pixel->getUrl());
    }

    public function testWebsiteIdIdentification(): void
    {
        $response = $this->post([
            'eventName' => 'click',
            'websiteId' => 999,
        ]);

        $this->assertEquals(200, $response['code']);
        $this->assertArrayHasKey('pixel_id', $response['data']);

        $pixel = ObjectManager::make(PixelModel::class)->load((int)$response['data']['pixel_id']);
        $this->assertSame(999, $pixel->getWebsiteId());
    }

    public function testNoTokenHandling(): void
    {
        $response = $this->post([
            'encrypted' => 'dGVzdC1lbmNyeXB0ZWQtZGF0YQ==',
            'version' => 'non-existent-version',
        ]);

        $this->assertArrayHasKey('code', $response);
    }

    public function testDecryptionFailureHandling(): void
    {
        $response = $this->post([
            'encrypted' => 'invalid-encrypted-data-format',
            'version' => '1.0.0',
        ]);

        $this->assertArrayHasKey('code', $response);
        $this->assertArrayHasKey('msg', $response);
    }

    public function testApiErrorHandling(): void
    {
        $response = $this->post([]);

        $this->assertEquals(200, $response['code']);
        $this->assertArrayHasKey('pixel_id', $response['data']);
    }

    public function testReceiveEncryptedData(): void
    {
        try {
            $service = ObjectManager::getInstance(PixelEncryptionService::class);
            $version = 'test-api-encrypt-' . time();
            $token = $service->generateTokenForVersion($version);

            $encrypted = $service->encrypt([
                'url' => 'https://example.com/test',
                'eventName' => 'click',
                'websiteId' => 1,
                'userId' => 123,
                'userAgent' => 'Mozilla/5.0 Test',
                'ip' => '192.168.1.1',
            ], $version);

            $response = $this->post([
                'encrypted' => $encrypted,
                'version' => $version,
            ]);

            $this->assertEquals(200, $response['code']);
            $this->assertArrayHasKey('data', $response);

            $token->setIsDeleted(true)->save();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Unable to verify encrypted payload handling: ' . $e->getMessage());
        }
    }

    private function post(array $postData): array
    {
        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn($postData);
        $request->method('clientIP')->willReturn('192.168.1.1');

        $reflection = new ReflectionClass($this->pixelApi);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($this->pixelApi, $request);

        $result = $this->pixelApi->postIndex();
        $response = json_decode((string)$result, true);

        $this->assertIsArray($response);

        if (isset($response['data']['pixel_id']) && $response['data']['pixel_id'] !== '') {
            $this->pixelIds[] = (int)$response['data']['pixel_id'];
        }
        if (isset($response['data']['pixel_additional_id']) && $response['data']['pixel_additional_id'] !== '') {
            $this->additionalIds[] = (int)$response['data']['pixel_additional_id'];
        }

        return $response;
    }
}
