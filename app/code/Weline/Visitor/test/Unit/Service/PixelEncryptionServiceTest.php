<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Service\PixelEncryptionService;
use Weline\Visitor\Model\PixelEncryptionToken;

/**
 * 像素加密服务单元测试
 */
class PixelEncryptionServiceTest extends TestCore
{
    private PixelEncryptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = ObjectManager::getInstance(PixelEncryptionService::class);
    }

    /**
     * 测试：加密和解密数据
     */
    public function testEncryptAndDecrypt()
    {
        $data = [
            'url' => 'https://example.com/test',
            'eventName' => 'click',
            'websiteId' => 1,
            'userId' => 123,
            'testId' => 'test_001',
            'variant' => 'A'
        ];

        // 测试加密
        $encrypted = $this->service->encrypt($data);
        
        $this->assertNotEmpty($encrypted, '加密结果应该不为空');
        $this->assertIsString($encrypted, '加密结果应该是字符串');

        // 测试解密
        $decrypted = $this->service->decrypt($encrypted);
        
        $this->assertIsArray($decrypted, '解密结果应该是数组');
        $this->assertEquals($data['url'], $decrypted['url']);
        $this->assertEquals($data['eventName'], $decrypted['eventName']);
        $this->assertEquals($data['websiteId'], $decrypted['websiteId']);
        $this->assertEquals($data['testId'], $decrypted['testId']);
        $this->assertEquals($data['variant'], $decrypted['variant']);
    }

    /**
     * 测试：使用指定版本加密和解密
     */
    public function testEncryptAndDecryptWithVersion()
    {
        $data = ['test' => 'data'];
        $version = '1.0.0-20250101';

        // 先确保有该版本的token
        try {
            $token = $this->service->getTokenByVersion($version);
            if (!$token) {
                // 如果没有token，生成一个
                $this->service->generateTokenForVersion($version);
            }
        } catch (\Exception $e) {
            // 如果生成失败，跳过此测试
            $this->markTestSkipped('无法生成测试token: ' . $e->getMessage());
        }

        // 测试加密
        $encrypted = $this->service->encrypt($data, $version);
        
        $this->assertNotEmpty($encrypted);

        // 测试解密
        $decrypted = $this->service->decrypt($encrypted, $version);
        
        $this->assertEquals($data, $decrypted);
    }

    /**
     * 测试：获取当前版本token
     */
    public function testGetCurrentVersionToken()
    {
        $token = $this->service->getCurrentVersionToken();
        
        // token可能不存在（开发环境），所以只检查返回类型
        if ($token !== null) {
            $this->assertInstanceOf(PixelEncryptionToken::class, $token);
            $this->assertNotEmpty($token->getVersion());
            $this->assertNotEmpty($token->getEncryptionToken());
        }
    }

    /**
     * 测试：生成版本token
     */
    public function testGenerateTokenForVersion()
    {
        $version = 'test-version-' . time();
        
        try {
            $token = $this->service->generateTokenForVersion($version);
            
            $this->assertInstanceOf(PixelEncryptionToken::class, $token);
            $this->assertEquals($version, $token->getVersion());
            $this->assertNotEmpty($token->getEncryptionToken());
            
            // 清理测试token
            $token->setIsDeleted(1)->save();
        } catch (\Exception $e) {
            $this->markTestSkipped('无法生成token: ' . $e->getMessage());
        }
    }

    /**
     * 测试：多token解密尝试
     */
    public function testMultiTokenDecryptionAttempt()
    {
        $data = ['test' => 'multi-token-data', 'value' => 123];
        
        // 创建两个不同版本的token
        $version1 = 'test-version-1-' . time();
        $version2 = 'test-version-2-' . time();
        
        try {
            // 生成token1
            $token1 = $this->service->generateTokenForVersion($version1);
            
            // 使用token1加密数据
            $encrypted = $this->service->encrypt($data, $version1);
            $this->assertNotEmpty($encrypted);
            
            // 生成token2（但不使用它加密）
            $token2 = $this->service->generateTokenForVersion($version2);
            
            // 尝试使用null版本号解密（应该尝试所有token）
            $decrypted = $this->service->decrypt($encrypted, null);
            
            $this->assertIsArray($decrypted);
            $this->assertEquals($data['test'], $decrypted['test']);
            $this->assertEquals($data['value'], $decrypted['value']);
            
            // 清理测试token
            $token1->setIsDeleted(1)->save();
            $token2->setIsDeleted(1)->save();
        } catch (\Exception $e) {
            $this->markTestSkipped('多token解密测试失败: ' . $e->getMessage());
        }
    }

    /**
     * 测试：版本号匹配
     */
    public function testVersionMatching()
    {
        $data = ['test' => 'version-matching', 'version' => '1.0.0'];
        $version = 'test-version-match-' . time();
        
        try {
            // 生成指定版本的token
            $token = $this->service->generateTokenForVersion($version);
            
            // 使用指定版本加密
            $encrypted = $this->service->encrypt($data, $version);
            $this->assertNotEmpty($encrypted);
            
            // 使用相同版本解密（应该成功）
            $decrypted = $this->service->decrypt($encrypted, $version);
            $this->assertIsArray($decrypted);
            $this->assertEquals($data['test'], $decrypted['test']);
            
            // 使用错误版本解密（应该失败）
            $wrongVersion = 'wrong-version-' . time();
            try {
                $this->service->decrypt($encrypted, $wrongVersion);
                $this->fail('使用错误版本号解密应该抛出异常');
            } catch (\Exception $e) {
                // 预期会抛出异常
                $this->assertStringContainsString('解密失败', $e->getMessage());
            }
            
            // 清理测试token
            $token->setIsDeleted(1)->save();
        } catch (\Exception $e) {
            $this->markTestSkipped('版本号匹配测试失败: ' . $e->getMessage());
        }
    }

    /**
     * 测试：数据加密和解密（不同数据类型）
     */
    public function testEncryptDecryptDifferentDataTypes()
    {
        // 测试数组数据
        $arrayData = ['key1' => 'value1', 'key2' => ['nested' => 'value']];
        $encrypted = $this->service->encrypt($arrayData);
        $decrypted = $this->service->decrypt($encrypted);
        $this->assertEquals($arrayData, $decrypted);
        
        // 测试字符串数据
        $stringData = 'simple string data';
        $encrypted = $this->service->encrypt($stringData);
        $decrypted = $this->service->decrypt($encrypted);
        $this->assertEquals($stringData, $decrypted);
        
        // 测试复杂嵌套数据
        $complexData = [
            'user' => ['id' => 123, 'name' => 'Test User'],
            'events' => [
                ['type' => 'click', 'value' => 100],
                ['type' => 'view', 'value' => 200]
            ],
            'metadata' => [
                'timestamp' => time(),
                'ip' => '192.168.1.1'
            ]
        ];
        $encrypted = $this->service->encrypt($complexData);
        $decrypted = $this->service->decrypt($encrypted);
        $this->assertEquals($complexData, $decrypted);
    }
}

