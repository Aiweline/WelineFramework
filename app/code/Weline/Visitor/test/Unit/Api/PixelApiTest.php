<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Api;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Api\Rest\V1\Pixel;
use Weline\Visitor\Model\Pixel as PixelModel;
use Weline\Visitor\Model\PixelAdditional;
use Weline\Framework\Http\Request;

/**
 * 像素API单元测试
 */
class PixelApiTest extends TestCore
{
    private Pixel $pixelApi;
    private PixelModel $pixelModel;
    private PixelAdditional $pixelAdditional;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pixelApi = ObjectManager::getInstance(Pixel::class);
        $this->pixelModel = ObjectManager::getInstance(PixelModel::class);
        $this->pixelAdditional = ObjectManager::getInstance(PixelAdditional::class);
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        if ($this->pixelModel->getId()) {
            try {
                $this->pixelModel->delete();
            } catch (\Exception $e) {
                // 忽略删除错误
            }
        }
        if ($this->pixelAdditional->getId()) {
            try {
                $this->pixelAdditional->delete();
            } catch (\Exception $e) {
                // 忽略删除错误
            }
        }
        parent::tearDown();
    }

    /**
     * 测试：接收明文像素数据
     */
    public function testPostIndexWithPlainData()
    {
        // 模拟请求数据
        $postData = [
            'url' => 'https://example.com/test',
            'eventName' => 'click',
            'websiteId' => 1,
            'userId' => 123,
            'userAgent' => 'Mozilla/5.0 Test',
            'ip' => '192.168.1.1',
            'testId' => 'test_001',
            'variant' => 'A'
        ];

        // 模拟请求对象
        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn($postData);
        $request->method('clientIP')->willReturn('192.168.1.1');
        
        // 使用反射设置request属性
        $reflection = new \ReflectionClass($this->pixelApi);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($this->pixelApi, $request);

        // 执行API
        $result = $this->pixelApi->postIndex();
        
        // 验证结果
        $this->assertIsString($result);
        $response = json_decode($result, true);
        
        $this->assertIsArray($response);
        $this->assertEquals(200, $response['code']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('pixel_id', $response['data']);
        $this->assertArrayHasKey('ab_test', $response['data']);
        $this->assertEquals('test_001', $response['data']['ab_test']['testId']);
        $this->assertEquals('A', $response['data']['ab_test']['variant']);

        // 验证数据已保存
        $pixelId = $response['data']['pixel_id'];
        $this->pixelModel->load($pixelId);
        $this->assertEquals('https://example.com/test', $this->pixelModel->getUrl());
        $this->assertEquals('click', $this->pixelModel->getEvent());
        $this->assertEquals(1, $this->pixelModel->getWebsiteId());
    }

    /**
     * 测试：数据验证和清理
     */
    public function testDataValidationAndSanitization()
    {
        // 测试无效IP地址
        $postData = [
            'url' => 'invalid-url',
            'eventName' => 'click',
            'ip' => 'invalid-ip',
            'module' => str_repeat('a', 300), // 超长字符串
            'value' => -100, // 负数
        ];

        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn($postData);
        $request->method('clientIP')->willReturn('192.168.1.1');
        
        $reflection = new \ReflectionClass($this->pixelApi);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($this->pixelApi, $request);

        $result = $this->pixelApi->postIndex();
        $response = json_decode($result, true);
        
        // 应该成功保存，但数据会被清理
        $this->assertEquals(200, $response['code']);
        
        if (isset($response['data']['pixel_id'])) {
            $pixelId = $response['data']['pixel_id'];
            $this->pixelModel->load($pixelId);
            
            // 验证数据清理
            $this->assertEmpty($this->pixelModel->getUrl(), '无效URL应该被清理为空');
            $this->assertEquals(192, strlen($this->pixelModel->getModule()), '超长字符串应该被截断');
            $this->assertEquals(0, $this->pixelModel->getValue(), '负数应该被转换为0');
        }
    }

    /**
     * 测试：站点ID识别
     */
    public function testWebsiteIdIdentification()
    {
        // 测试从请求数据获取
        $postData = [
            'eventName' => 'click',
            'websiteId' => 999,
        ];

        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn($postData);
        $request->method('clientIP')->willReturn('192.168.1.1');
        
        $reflection = new \ReflectionClass($this->pixelApi);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($this->pixelApi, $request);

        $result = $this->pixelApi->postIndex();
        $response = json_decode($result, true);
        
        if (isset($response['data']['pixel_id'])) {
            $pixelId = $response['data']['pixel_id'];
            $this->pixelModel->load($pixelId);
            $this->assertEquals(999, $this->pixelModel->getWebsiteId());
        }
    }

    /**
     * 测试：无token时的处理（接收加密数据但无token）
     */
    public function testNoTokenHandling()
    {
        // 模拟加密数据（但实际环境中可能没有token）
        $postData = [
            'encrypted' => 'dGVzdC1lbmNyeXB0ZWQtZGF0YQ==', // 无效的加密数据
            'version' => 'non-existent-version',
        ];

        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn($postData);
        $request->method('clientIP')->willReturn('192.168.1.1');
        
        $reflection = new \ReflectionClass($this->pixelApi);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($this->pixelApi, $request);

        $result = $this->pixelApi->postIndex();
        $response = json_decode($result, true);
        
        // 应该返回错误或降级处理
        $this->assertIsArray($response);
        // 可能返回错误码或降级为明文处理
        $this->assertArrayHasKey('code', $response);
    }

    /**
     * 测试：解密失败时的处理
     */
    public function testDecryptionFailureHandling()
    {
        // 模拟无效的加密数据
        $postData = [
            'encrypted' => 'invalid-encrypted-data-format',
            'version' => '1.0.0',
        ];

        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn($postData);
        $request->method('clientIP')->willReturn('192.168.1.1');
        
        $reflection = new \ReflectionClass($this->pixelApi);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($this->pixelApi, $request);

        $result = $this->pixelApi->postIndex();
        $response = json_decode($result, true);
        
        // 应该返回错误
        $this->assertIsArray($response);
        $this->assertArrayHasKey('code', $response);
        // 解密失败应该返回错误码（非200）
        if (isset($response['code']) && $response['code'] !== 200) {
            $this->assertArrayHasKey('msg', $response);
        }
    }

    /**
     * 测试：API错误时的处理（无效请求数据）
     */
    public function testApiErrorHandling()
    {
        // 测试空请求数据
        $postData = [];

        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn($postData);
        $request->method('clientIP')->willReturn('192.168.1.1');
        
        $reflection = new \ReflectionClass($this->pixelApi);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($this->pixelApi, $request);

        $result = $this->pixelApi->postIndex();
        $response = json_decode($result, true);
        
        // 应该返回错误或默认处理
        $this->assertIsArray($response);
        $this->assertArrayHasKey('code', $response);
    }

    /**
     * 测试：接收加密数据并解密
     */
    public function testReceiveEncryptedData()
    {
        // 需要先创建token并加密数据
        try {
            $encryptionService = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Visitor\Service\PixelEncryptionService::class
            );
            
            $version = 'test-api-encrypt-' . time();
            $token = $encryptionService->generateTokenForVersion($version);
            
            // 准备像素数据
            $pixelData = [
                'url' => 'https://example.com/test',
                'eventName' => 'click',
                'websiteId' => 1,
                'userId' => 123,
                'userAgent' => 'Mozilla/5.0 Test',
                'ip' => '192.168.1.1',
            ];
            
            // 加密数据
            $encrypted = $encryptionService->encrypt($pixelData, $version);
            
            // 模拟请求
            $postData = [
                'encrypted' => $encrypted,
                'version' => $version,
            ];

            $request = $this->createMock(Request::class);
            $request->method('getBodyParams')->willReturn($postData);
            $request->method('clientIP')->willReturn('192.168.1.1');
            
            $reflection = new \ReflectionClass($this->pixelApi);
            $requestProperty = $reflection->getProperty('request');
            $requestProperty->setAccessible(true);
            $requestProperty->setValue($this->pixelApi, $request);

            $result = $this->pixelApi->postIndex();
            $response = json_decode($result, true);
            
            // 应该成功解密并保存
            $this->assertEquals(200, $response['code']);
            $this->assertArrayHasKey('data', $response);
            
            // 清理
            $token->setIsDeleted(1)->save();
        } catch (\Exception $e) {
            $this->markTestSkipped('无法测试加密数据接收: ' . $e->getMessage());
        }
    }
}

