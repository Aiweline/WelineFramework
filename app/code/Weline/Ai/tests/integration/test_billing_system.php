<?php
declare(strict_types=1);

/**
 * 计费系统集成测试
 * 
 * 测试场景: 计费计划管理和发票生成功能
 */

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiBillingPlan;
use Weline\Ai\Model\AiBillingInvoice;
use Weline\Ai\Service\BillingManager;

class BillingSystemIntegrationTest extends TestCase
{
    private AiBillingPlan $planModel;
    private AiBillingInvoice $invoiceModel;
    private BillingManager $billingManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planModel = new AiBillingPlan();
        $this->invoiceModel = new AiBillingInvoice();
        $this->billingManager = new BillingManager();
    }

    /**
     * 测试计费计划创建
     */
    public function testBillingPlanCreation(): void
    {
        $planData = [
            'plan_name' => '专业版',
            'plan_type' => 'paid',
            'price' => 99.00,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => 1
        ];
        
        $plan = new AiBillingPlan();
        $plan->setData($planData);
        $result = $plan->save();
        
        $this->assertTrue($result);
        $this->assertGreaterThan(0, $plan->getId());
    }

    /**
     * 测试发票生成
     */
    public function testInvoiceGeneration(): void
    {
        $invoice = $this->billingManager->generateInvoice(
            1, // 租户ID
            99.00, // 金额
            'USD', // 货币
            [['item' => '专业版订阅', 'amount' => 99.00]] // 项目
        );
        
        $this->assertNotNull($invoice);
        $this->assertGreaterThan(0, $invoice->getId());
        $this->assertEquals(99.00, $invoice->getAmount());
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        $this->planModel->getCollection()
            ->where('plan_name', '专业版')
            ->delete();
        
        parent::tearDown();
    }
}
