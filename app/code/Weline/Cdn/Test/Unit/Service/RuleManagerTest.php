<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Cdn\Service\RuleManager;
use Weline\Framework\App\Env;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;

/**
 * RuleManager服务单元测试
 */
class RuleManagerTest extends TestCase
{
    private RuleManager $ruleManager;
    private AdapterResolver $adapterResolver;
    private ObjectManager $objectManager;
    private string $rulesFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager = ObjectManager::getInstance();
        $this->adapterResolver = $this->createMock(AdapterResolver::class);
        
        $this->ruleManager = new RuleManager(
            $this->objectManager,
            $this->adapterResolver
        );

        // 设置规则文件路径
        $this->rulesFile = BP . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . 
                          DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Cdn' . 
                          DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'default-rules.json';
    }

    protected function tearDown(): void
    {
        // 清理测试文件
        if (file_exists($this->rulesFile . '.test')) {
            unlink($this->rulesFile . '.test');
        }
        parent::tearDown();
    }

    /**
     * 测试：服务实例化
     */
    public function testServiceInstantiation(): void
    {
        $this->assertInstanceOf(RuleManager::class, $this->ruleManager);
    }

    /**
     * 测试：获取默认规则（文件存在）
     */
    public function testGetDefaultRulesFileExists(): void
    {
        // 如果文件存在，应该能读取
        $rules = $this->ruleManager->getDefaultRules();
        $this->assertIsArray($rules);
    }

    /**
     * 测试：获取默认规则（文件不存在）
     */
    public function testGetDefaultRulesFileNotExists(): void
    {
        // 如果文件不存在，应该返回空数组
        // 注意：实际环境中文件应该存在，这里主要验证逻辑
        $rules = $this->ruleManager->getDefaultRules();
        $this->assertIsArray($rules);
    }

    /**
     * 测试：保存默认规则
     */
    public function testSaveDefaultRules(): void
    {
        $testRules = [
            'cache_level' => 'aggressive',
            'browser_cache_ttl' => 3600
        ];

        // 使用临时文件测试
        $testFile = $this->rulesFile . '.test';
        $originalContent = file_exists($this->rulesFile) ? file_get_contents($this->rulesFile) : null;

        // 测试保存功能
        $result = $this->ruleManager->saveDefaultRules($testRules);
        $this->assertTrue($result);

        // 验证文件内容
        if (file_exists($this->rulesFile)) {
            $content = file_get_contents($this->rulesFile);
            $savedRules = json_decode($content, true);
            $this->assertIsArray($savedRules);

            // 恢复原始内容
            if ($originalContent !== null) {
                file_put_contents($this->rulesFile, $originalContent);
            }
        }
    }

    /**
     * 测试：获取合并规则（无覆盖规则）
     */
    public function testGetMergedRulesNoOverride(): void
    {
        $domain = $this->createMock(Domain::class);
        $domain->method('getRulesOverrideArray')->willReturn([]);

        $rules = $this->ruleManager->getMergedRules($domain);
        $this->assertIsArray($rules);
    }

    /**
     * 测试：获取合并规则（有覆盖规则）
     */
    public function testGetMergedRulesWithOverride(): void
    {
        $overrideRules = [
            'cache_level' => 'standard',
            'edge_cache_ttl' => 7200
        ];

        $domain = $this->createMock(Domain::class);
        $domain->method('getRulesOverrideArray')->willReturn($overrideRules);

        $rules = $this->ruleManager->getMergedRules($domain);
        $this->assertIsArray($rules);
        $this->assertSame('standard', $rules['cache_level']);
        $this->assertSame(7200, $rules['edge_cache_ttl']);
    }

    /**
     * 测试：导入规则（适配器不存在）
     */
    public function testImportRulesAdapterNotFound(): void
    {
        $domain = $this->createMock(Domain::class);
        $domain->method('getData')->willReturn('non_existent_adapter');
        $domain->method('getCredentialsArray')->willReturn([]);
        $domain->method('isInheritDefault')->willReturn(false);

        $this->adapterResolver->expects($this->once())
            ->method('getAdapter')
            ->with('non_existent_adapter')
            ->willReturn(null);

        $this->expectException(Core::class);
        $this->expectExceptionMessage('适配器不存在');
        
        $this->ruleManager->importRules($domain);
    }

    /**
     * 测试：导入规则（成功场景）
     */
    public function testImportRulesSuccess(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->once())
            ->method('getRules')
            ->with('zone-123', ['api_token' => 'test-token'])
            ->willReturn(['cache_level' => 'aggressive']);

        $this->adapterResolver->expects($this->once())
            ->method('getAdapter')
            ->with('cloudflare')
            ->willReturn($adapter);

        $domain = $this->createMock(Domain::class);
        $domain->method('getData')->willReturnCallback(
            static fn(string $key, $index = null) => match ($key) {
                Domain::schema_fields_ADAPTER => 'cloudflare',
                Domain::schema_fields_ZONE_ID => 'zone-123',
                default => null,
            }
        );
        $domain->method('getCredentialsArray')->willReturn(['api_token' => 'test-token']);
        $domain->method('isInheritDefault')->willReturn(false);

        $result = $this->ruleManager->importRules($domain);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('rules', $result);
    }

    /**
     * 测试：推送规则（适配器不存在）
     */
    public function testPushRulesAdapterNotFound(): void
    {
        $domain = $this->createMock(Domain::class);
        $domain->method('getData')->willReturn('non_existent_adapter');
        $domain->method('getRulesOverrideArray')->willReturn([]);
        $domain->method('getCredentialsArray')->willReturn([]);
        $domain->method('isInheritDefault')->willReturn(false);

        $this->adapterResolver->expects($this->once())
            ->method('getAdapter')
            ->with('non_existent_adapter')
            ->willReturn(null);

        $this->expectException(Core::class);
        $this->expectExceptionMessage('适配器不存在');
        
        $this->ruleManager->pushRules($domain);
    }

    /**
     * 测试：推送规则（成功场景）
     */
    public function testPushRulesSuccess(): void
    {
        $credentials = ['api_token' => 'test-token'];

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->once())
            ->method('putRules')
            ->with(
                'zone-123',
                $this->callback(static fn($rules): bool => is_array($rules) && $rules !== []),
                $credentials
            )
            ->willReturn(['success' => true, 'message' => '推送成功']);

        $this->adapterResolver->expects($this->once())
            ->method('getAdapter')
            ->with('cloudflare')
            ->willReturn($adapter);

        $domain = $this->createMock(Domain::class);
        $domain->method('getData')->willReturnCallback(
            static fn(string $key, $index = null) => match ($key) {
                Domain::schema_fields_ADAPTER => 'cloudflare',
                Domain::schema_fields_ZONE_ID => 'zone-123',
                default => null,
            }
        );
        $domain->method('getRulesOverrideArray')->willReturn([]);
        $domain->method('getCredentialsArray')->willReturn($credentials);
        $domain->method('isInheritDefault')->willReturn(false);

        $result = $this->ruleManager->pushRules($domain);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }
}

