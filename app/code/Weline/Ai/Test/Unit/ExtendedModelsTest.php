<?php
/**
 * 扩展模型单元测试
 * 
 * 测试覆盖：
 * - AiAssistantPromptTemplate（助手提示词模板）
 * - AiApiQuota（API配额管理）
 * - AiScenarioAdapterConfig（场景适配器配置）
 * - AiTenantConfig（租户配置）
 * - AiAuditLogDetail（审计日志详情）
 * - AiPerformanceMetricDetail（性能指标详情）
 * - AiBillingRecordDetail（计费记录详情）
 * - AiAssistantConversation（助手会话记录）
 */

namespace Weline\Ai\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiAssistantPromptTemplate;
use Weline\Ai\Model\AiApiQuota;
use Weline\Ai\Model\AiScenarioAdapterConfig;
use Weline\Ai\Model\AiTenantConfig;
use Weline\Ai\Model\AiAuditLogDetail;
use Weline\Ai\Model\AiPerformanceMetricDetail;
use Weline\Ai\Model\AiBillingRecordDetail;
use Weline\Ai\Model\AiAssistantConversation;

class ExtendedModelsTest extends TestCase
{
    /**
     * AiAssistantPromptTemplate - 测试变量功能
     */
    public function testPromptTemplateVariables()
    {
        $template = new AiAssistantPromptTemplate();
        
        $variables = [
            'user_name' => 'John',
            'product_name' => 'AI Assistant',
        ];
        
        $template->setVariables($variables);
        $result = $template->getVariables();
        
        $this->assertIsArray($result);
        $this->assertEquals('John', $result['user_name']);
        $this->assertEquals('AI Assistant', $result['product_name']);
    }
    
    /**
     * AiAssistantPromptTemplate - 测试模板渲染
     */
    public function testPromptTemplateRender()
    {
        $template = new AiAssistantPromptTemplate();
        $template->setData('template_content', 'Hello {{user_name}}, welcome to {{product_name}}!');
        
        $data = [
            'user_name' => 'Alice',
            'product_name' => 'Weline AI',
        ];
        
        $result = $template->render($data);
        
        $this->assertEquals('Hello Alice, welcome to Weline AI!', $result);
    }

    /**
     * AiApiQuota - 测试配额使用
     */
    public function testApiQuotaUse()
    {
        $quota = new AiApiQuota();
        $quota->setData([
            'quota_limit' => 1000,
            'quota_used' => 500,
            'token_limit' => 100000,
            'token_used' => 50000,
            'cost_limit' => 100.00,
            'cost_used' => 50.00,
            'reset_at' => time() + 86400,
            'is_exceeded' => 0,
        ]);
        
        $result = $quota->use(10, 1000, 1.0);
        
        $this->assertTrue($result, '配额使用成功');
        $this->assertEquals(510, $quota->getData('quota_used'));
        $this->assertEquals(51000, $quota->getData('token_used'));
    }
    
    /**
     * AiApiQuota - 测试配额超限
     */
    public function testApiQuotaExceeded()
    {
        $quota = new AiApiQuota();
        $quota->setData([
            'quota_limit' => 1000,
            'quota_used' => 1000,
            'reset_at' => time() + 86400,
            'is_exceeded' => 1,
        ]);
        
        $result = $quota->use(1, 100, 0.1);
        
        $this->assertFalse($result, '配额已超额，应拒绝使用');
    }
    
    /**
     * AiApiQuota - 测试使用率计算
     */
    public function testApiQuotaUsagePercent()
    {
        $quota = new AiApiQuota();
        $quota->setData([
            'quota_limit' => 1000,
            'quota_used' => 800,
        ]);
        
        $percent = $quota->getUsagePercent();
        
        $this->assertEquals(80.0, $percent);
    }
    
    /**
     * AiApiQuota - 测试警告阈值
     */
    public function testApiQuotaWarningThreshold()
    {
        $quota = new AiApiQuota();
        $quota->setData([
            'quota_limit' => 1000,
            'quota_used' => 850,
            'warning_threshold' => 80,
        ]);
        
        $this->assertTrue($quota->isNearWarningThreshold(), '应触发警告阈值');
    }

    /**
     * AiScenarioAdapterConfig - 测试配置类型
     */
    public function testScenarioAdapterConfigTypes()
    {
        $config = new AiScenarioAdapterConfig();
        
        // 测试整数类型
        $config->setData([
            'config_value' => '123',
            'config_type' => AiScenarioAdapterConfig::CONFIG_TYPE_INT,
        ]);
        $this->assertSame(123, $config->getConfigValue());
        
        // 测试布尔类型
        $config->setData([
            'config_value' => '1',
            'config_type' => AiScenarioAdapterConfig::CONFIG_TYPE_BOOL,
        ]);
        $this->assertTrue($config->getConfigValue());
        
        // 测试JSON类型
        $config->setData([
            'config_value' => '{"key": "value"}',
            'config_type' => AiScenarioAdapterConfig::CONFIG_TYPE_JSON,
        ]);
        $this->assertIsArray($config->getConfigValue());
        $this->assertEquals('value', $config->getConfigValue()['key']);
    }

    /**
     * AiAuditLogDetail - 测试变更摘要
     */
    public function testAuditLogDetailChangeSummary()
    {
        $log = new AiAuditLogDetail();
        
        // 测试创建操作
        $log->setData([
            'field_name' => 'status',
            'change_type' => AiAuditLogDetail::CHANGE_TYPE_CREATE,
            'new_value' => 'active',
        ]);
        $this->assertStringContainsString('创建', $log->getChangeSummary());
        
        // 测试更新操作
        $log->setData([
            'field_name' => 'status',
            'change_type' => AiAuditLogDetail::CHANGE_TYPE_UPDATE,
            'old_value' => 'inactive',
            'new_value' => 'active',
        ]);
        $this->assertStringContainsString('→', $log->getChangeSummary());
    }

    /**
     * AiPerformanceMetricDetail - 测试阈值检查
     */
    public function testPerformanceMetricAboveThreshold()
    {
        $metric = new AiPerformanceMetricDetail();
        $metric->setData([
            'metric_value' => 5.0,
            'threshold' => 3.0,
        ]);
        
        $this->assertTrue($metric->isAboveThreshold(), '指标应超过阈值');
    }
    
    /**
     * AiPerformanceMetricDetail - 测试格式化值
     */
    public function testPerformanceMetricFormattedValue()
    {
        $metric = new AiPerformanceMetricDetail();
        $metric->setData([
            'metric_value' => 1.234,
            'metric_unit' => 'ms',
        ]);
        
        $formatted = $metric->getFormattedValue();
        
        $this->assertEquals('1.234 ms', $formatted);
    }

    /**
     * AiBillingRecordDetail - 测试总额计算
     */
    public function testBillingRecordCalculateTotal()
    {
        $billing = new AiBillingRecordDetail();
        $billing->setData([
            'subtotal' => 100.00,
            'discount' => 10.00,
            'tax' => 5.00,
        ]);
        
        $total = $billing->calculateTotal();
        
        $this->assertEquals(95.00, $total, '总额 = 小计 - 折扣 + 税费');
    }
    
    /**
     * AiBillingRecordDetail - 测试格式化总额
     */
    public function testBillingRecordFormattedTotal()
    {
        $billing = new AiBillingRecordDetail();
        $billing->setData([
            'total' => 95.50,
            'currency' => 'CNY',
        ]);
        
        $formatted = $billing->getFormattedTotal();
        
        $this->assertStringContainsString('¥', $formatted);
        $this->assertStringContainsString('95.50', $formatted);
    }

    /**
     * AiAssistantConversation - 测试消息角色
     */
    public function testConversationMessageRoles()
    {
        $conversation = new AiAssistantConversation();
        
        // 测试用户消息
        $conversation->setData('message_role', AiAssistantConversation::ROLE_USER);
        $this->assertTrue($conversation->isUserMessage());
        $this->assertFalse($conversation->isAssistantMessage());
        
        // 测试助手消息
        $conversation->setData('message_role', AiAssistantConversation::ROLE_ASSISTANT);
        $this->assertFalse($conversation->isUserMessage());
        $this->assertTrue($conversation->isAssistantMessage());
    }
    
    /**
     * AiAssistantConversation - 测试收藏功能
     */
    public function testConversationBookmark()
    {
        $conversation = new AiAssistantConversation();
        $conversation->setData('is_bookmarked', 0);
        
        $conversation->toggleBookmark();
        $this->assertEquals(1, $conversation->getData('is_bookmarked'));
        
        $conversation->toggleBookmark();
        $this->assertEquals(0, $conversation->getData('is_bookmarked'));
    }
    
    /**
     * AiAssistantConversation - 测试评分功能
     */
    public function testConversationRating()
    {
        $conversation = new AiAssistantConversation();
        
        $conversation->setRating(3);
        $this->assertEquals(3, $conversation->getData('rating'));
        
        // 测试边界值
        $conversation->setRating(10); // 超过最大值
        $this->assertEquals(5, $conversation->getData('rating'));
        
        $conversation->setRating(0); // 低于最小值
        $this->assertEquals(1, $conversation->getData('rating'));
    }
}

