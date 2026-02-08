<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Server\Extends\Module\Weline_Cdn\Adapter\WlsMemory;
use Weline\Server\Service\MemoryCacheRuleManager;

/**
 * 规则同步流程测试
 * 
 * 测试场景：
 * 1. WlsMemory::putRules() 写入规则文件和标记文件
 * 2. MemoryCacheRuleManager 从 var/server/ 加载规则
 * 3. 规则更新标记检测
 */
class RulesSyncTest extends TestCase
{
    private string $serverDir;
    private string $rulesFile;
    private string $flagFile;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->serverDir = Env::VAR_DIR . 'server';
        $this->rulesFile = $this->serverDir . DIRECTORY_SEPARATOR . 'memory-cache-rules.json';
        $this->flagFile = $this->serverDir . DIRECTORY_SEPARATOR . 'rules-update.flag';
        
        // 备份现有文件
        if (file_exists($this->rulesFile)) {
            @copy($this->rulesFile, $this->rulesFile . '.bak');
        }
        if (file_exists($this->flagFile)) {
            @copy($this->flagFile, $this->flagFile . '.bak');
        }
    }

    protected function tearDown(): void
    {
        // 恢复备份文件
        if (file_exists($this->rulesFile . '.bak')) {
            @rename($this->rulesFile . '.bak', $this->rulesFile);
        }
        if (file_exists($this->flagFile . '.bak')) {
            @rename($this->flagFile . '.bak', $this->flagFile);
        }
        
        parent::tearDown();
    }

    /**
     * 测试 WlsMemory::putRules() 正确写入规则文件
     */
    public function testPutRulesWritesRulesFile(): void
    {
        $adapter = new WlsMemory();
        
        $testRules = [
            [
                'id' => 'test-rule-1',
                'name' => 'Test Rule 1',
                'expression' => 'http.request.uri.path matches "^/test/"',
                'action' => 'cache',
                'ttl' => 300,
                'priority' => 100,
                'enabled' => true,
            ],
            [
                'id' => 'test-rule-2',
                'name' => 'Test Rule 2',
                'expression' => 'http.request.uri.path matches "^/admin/"',
                'action' => 'bypass',
                'priority' => 200,
                'enabled' => true,
            ],
        ];
        
        $result = $adapter->putRules('default', $testRules, []);
        
        // 验证返回成功
        $this->assertTrue($result['success'], '规则写入应该成功');
        $this->assertEquals(2, $result['rules_count'], '规则数量应该为 2');
        
        // 验证规则文件存在
        $this->assertFileExists($this->rulesFile, '规则文件应该存在');
        
        // 验证规则文件内容
        $content = file_get_contents($this->rulesFile);
        $decoded = json_decode($content, true);
        
        $this->assertIsArray($decoded, '规则文件应该是有效的 JSON');
        $this->assertArrayHasKey('rules', $decoded, '应该包含 rules 键');
        $this->assertCount(2, $decoded['rules'], '应该包含 2 条规则');
        $this->assertEquals('test-rule-1', $decoded['rules'][0]['id'], '第一条规则 ID 应该正确');
        $this->assertEquals('test-rule-2', $decoded['rules'][1]['id'], '第二条规则 ID 应该正确');
    }

    /**
     * 测试 WlsMemory::putRules() 正确写入标记文件
     */
    public function testPutRulesWritesFlagFile(): void
    {
        $adapter = new WlsMemory();
        
        $testRules = [
            [
                'id' => 'test-rule-1',
                'name' => 'Test Rule',
                'expression' => 'true',
                'action' => 'cache',
                'ttl' => 60,
                'priority' => 1,
                'enabled' => true,
            ],
        ];
        
        $beforeTime = time();
        $result = $adapter->putRules('default', $testRules, []);
        $afterTime = time();
        
        // 验证标记文件存在
        $this->assertFileExists($this->flagFile, '标记文件应该存在');
        
        // 验证标记文件内容是时间戳
        $flagContent = file_get_contents($this->flagFile);
        $flagTime = (int) $flagContent;
        
        $this->assertGreaterThanOrEqual($beforeTime, $flagTime, '标记时间应该 >= 开始时间');
        $this->assertLessThanOrEqual($afterTime, $flagTime, '标记时间应该 <= 结束时间');
    }

    /**
     * 测试 MemoryCacheRuleManager 从 var/server/ 优先加载规则
     */
    public function testRuleManagerLoadsCdnRulesFirst(): void
    {
        // 先写入测试规则
        $adapter = new WlsMemory();
        
        $testRules = [
            [
                'id' => 'cdn-pushed-rule',
                'name' => 'CDN Pushed Rule',
                'expression' => 'http.request.uri.path matches "^/cdn-test/"',
                'action' => 'cache',
                'ttl' => 600,
                'priority' => 500,
                'enabled' => true,
            ],
        ];
        
        $adapter->putRules('default', $testRules, []);
        
        // 使用单例 RuleManager 并重载规则
        $ruleManager = MemoryCacheRuleManager::getInstance();
        $ruleManager->reload();
        $loadedRules = $ruleManager->loadRules();
        
        // 验证加载的规则包含 CDN 推送的规则
        $foundCdnRule = false;
        foreach ($loadedRules as $rule) {
            if (($rule['id'] ?? '') === 'cdn-pushed-rule') {
                $foundCdnRule = true;
                $this->assertEquals('CDN Pushed Rule', $rule['name'], '规则名称应该匹配');
                $this->assertEquals(600, $rule['ttl'], 'TTL 应该匹配');
                break;
            }
        }
        
        $this->assertTrue($foundCdnRule, '应该找到 CDN 推送的规则');
    }

    /**
     * 测试规则优先级排序（高优先级在前）
     */
    public function testRulesPrioritySorting(): void
    {
        $adapter = new WlsMemory();
        
        // 故意乱序写入
        $testRules = [
            [
                'id' => 'low-priority',
                'name' => 'Low Priority Rule',
                'expression' => 'true',
                'action' => 'cache',
                'priority' => 10,
                'enabled' => true,
            ],
            [
                'id' => 'high-priority',
                'name' => 'High Priority Rule',
                'expression' => 'true',
                'action' => 'bypass',
                'priority' => 100,
                'enabled' => true,
            ],
            [
                'id' => 'medium-priority',
                'name' => 'Medium Priority Rule',
                'expression' => 'true',
                'action' => 'cache',
                'priority' => 50,
                'enabled' => true,
            ],
        ];
        
        $adapter->putRules('default', $testRules, []);
        
        // 使用单例 RuleManager 并重载规则
        $ruleManager = MemoryCacheRuleManager::getInstance();
        $ruleManager->reload();
        $loadedRules = $ruleManager->loadRules();
        
        // 验证排序：高优先级在前
        $ids = array_column($loadedRules, 'id');
        
        $highIdx = array_search('high-priority', $ids);
        $mediumIdx = array_search('medium-priority', $ids);
        $lowIdx = array_search('low-priority', $ids);
        
        $this->assertLessThan($mediumIdx, $highIdx, '高优先级应该排在中等优先级前面');
        $this->assertLessThan($lowIdx, $mediumIdx, '中等优先级应该排在低优先级前面');
    }

    /**
     * 测试静态路径方法
     */
    public function testStaticPathMethods(): void
    {
        $flagPath = MemoryCacheRuleManager::getRulesUpdateFlagPath();
        $cdnRulesPath = MemoryCacheRuleManager::getCdnRulesFilePath();
        
        $this->assertStringContainsString('var', $flagPath, '标记文件路径应该包含 var');
        $this->assertStringContainsString('server', $flagPath, '标记文件路径应该包含 server');
        $this->assertStringContainsString('rules-update.flag', $flagPath, '标记文件路径应该包含 rules-update.flag');
        
        $this->assertStringContainsString('var', $cdnRulesPath, '规则文件路径应该包含 var');
        $this->assertStringContainsString('server', $cdnRulesPath, '规则文件路径应该包含 server');
        $this->assertStringContainsString('memory-cache-rules.json', $cdnRulesPath, '规则文件路径应该包含 memory-cache-rules.json');
    }

    /**
     * 测试 reload 方法重新加载规则
     */
    public function testReloadReloadsRules(): void
    {
        $adapter = new WlsMemory();
        
        // 第一次写入规则
        $rules1 = [
            [
                'id' => 'rule-v1',
                'name' => 'Version 1 Rule',
                'expression' => 'true',
                'action' => 'cache',
                'priority' => 1,
                'enabled' => true,
            ],
        ];
        $adapter->putRules('default', $rules1, []);
        
        // 加载规则
        $ruleManager = MemoryCacheRuleManager::getInstance();
        $ruleManager->reload();
        
        // 验证加载了 v1 规则
        $loaded1 = $ruleManager->loadRules();
        $found1 = false;
        foreach ($loaded1 as $rule) {
            if (($rule['id'] ?? '') === 'rule-v1') {
                $found1 = true;
                break;
            }
        }
        $this->assertTrue($found1, '应该找到 v1 规则');
        
        // 更新规则
        $rules2 = [
            [
                'id' => 'rule-v2',
                'name' => 'Version 2 Rule',
                'expression' => 'true',
                'action' => 'bypass',
                'priority' => 2,
                'enabled' => true,
            ],
        ];
        $adapter->putRules('default', $rules2, []);
        
        // 重新加载
        $ruleManager->reload();
        
        // 验证加载了 v2 规则
        $loaded2 = $ruleManager->loadRules();
        $found2 = false;
        foreach ($loaded2 as $rule) {
            if (($rule['id'] ?? '') === 'rule-v2') {
                $found2 = true;
                break;
            }
        }
        $this->assertTrue($found2, '应该找到 v2 规则');
    }
}
