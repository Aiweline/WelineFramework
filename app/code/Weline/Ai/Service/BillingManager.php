<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiBillingPlan;
use Weline\Ai\Model\AiBillingInvoice;
use Weline\Ai\Model\AiTenant;
use Weline\Framework\App\Exception;

/**
 * 计费管理服务
 * 
 * 功能：
 * - 计费计划管理
 * - 发票生成和管理
 * - 支付处理
 * - 使用量计费
 * - 订阅管理
 */
class BillingManager
{
    /**
     * @var AiBillingPlan
     */
    private AiBillingPlan $billingPlanModel;

    /**
     * @var AiBillingInvoice
     */
    private AiBillingInvoice $billingInvoiceModel;

    /**
     * @var AiTenant
     */
    private AiTenant $tenantModel;

    /**
     * 构造函数
     * 
     * @param AiBillingPlan $billingPlanModel
     * @param AiBillingInvoice $billingInvoiceModel
     * @param AiTenant $tenantModel
     */
    public function __construct(
        AiBillingPlan $billingPlanModel,
        AiBillingInvoice $billingInvoiceModel,
        AiTenant $tenantModel
    ) {
        $this->billingPlanModel = $billingPlanModel;
        $this->billingInvoiceModel = $billingInvoiceModel;
        $this->tenantModel = $tenantModel;
    }

    /**
     * 获取所有计费计划
     * 
     * @param bool $activeOnly 仅激活计划
     * @return array
     */
    public function getAllBillingPlans(bool $activeOnly = true): array
    {
        $query = $this->billingPlanModel->reset();
        
        if ($activeOnly) {
            $query->where(AiBillingPlan::schema_fields_IS_ACTIVE, 1);
        }

        return $query->select()->fetch();
    }

    /**
     * 获取计费计划
     * 
     * @param int $planId 计划ID
     * @return AiBillingPlan|null
     */
    public function getBillingPlan(int $planId): ?AiBillingPlan
    {
        $plan = $this->billingPlanModel->reset()
            ->where(AiBillingPlan::schema_fields_ID, $planId)
            ->find()
            ->fetch();

        return $plan->getId() ? $plan : null;
    }

    /**
     * 创建计费计划
     * 
     * @param string $planName 计划名称
     * @param string $planType 计划类型
     * @param float $price 价格
     * @param string $currency 货币
     * @param string $billingCycle 计费周期
     * @param array $features 功能列表
     * @param array $limits 限制配置
     * @return AiBillingPlan
     */
    public function createBillingPlan(
        string $planName,
        string $planType,
        float $price,
        string $currency = 'USD',
        string $billingCycle = AiBillingPlan::CYCLE_MONTHLY,
        array $features = [],
        array $limits = []
    ): AiBillingPlan {
        $plan = new AiBillingPlan();
        $plan->setData(AiBillingPlan::schema_fields_PLAN_NAME, $planName)
             ->setData(AiBillingPlan::schema_fields_PLAN_TYPE, $planType)
             ->setData(AiBillingPlan::schema_fields_PRICE, $price)
             ->setData(AiBillingPlan::schema_fields_CURRENCY, $currency)
             ->setData(AiBillingPlan::schema_fields_BILLING_CYCLE, $billingCycle)
             ->setFeatures($features)
             ->setLimits($limits)
             ->save();

        return $plan;
    }

    /**
     * 生成发票
     * 
     * @param int $tenantId 租户ID
     * @param float $amount 金额
     * @param string $currency 货币
     * @param array $items 发票项目
     * @param int $dueDays 到期天数
     * @return AiBillingInvoice
     * @throws Exception
     */
    public function generateInvoice(
        int $tenantId,
        float $amount,
        string $currency = 'USD',
        array $items = [],
        int $dueDays = 30
    ): AiBillingInvoice {
        // 验证租户是否存在
        $tenant = $this->tenantModel->reset()
            ->where(AiTenant::schema_fields_ID, $tenantId)
            ->find()
            ->fetch();

        if (!$tenant->getId()) {
            throw new Exception("租户不存在: {$tenantId}");
        }

        // 创建发票
        $invoice = new AiBillingInvoice();
        $invoice->setData(AiBillingInvoice::schema_fields_TENANT_ID, $tenantId)
                ->setData(AiBillingInvoice::schema_fields_AMOUNT, $amount)
                ->setData(AiBillingInvoice::schema_fields_CURRENCY, $currency)
                ->setData(AiBillingInvoice::schema_fields_STATUS, AiBillingInvoice::STATUS_PENDING)
                ->setData(AiBillingInvoice::schema_fields_DUE_DATE, time() + ($dueDays * 24 * 3600))
                ->setItems($items)
                ->save();

        return $invoice;
    }

    /**
     * 获取租户发票
     * 
     * @param int $tenantId 租户ID
     * @param string $status 状态过滤
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array
     */
    public function getTenantInvoices(int $tenantId, string $status = '', int $limit = 20, int $offset = 0): array
    {
        $query = $this->billingInvoiceModel->reset()
            ->where(AiBillingInvoice::schema_fields_TENANT_ID, $tenantId);

        if ($status) {
            $query->where(AiBillingInvoice::schema_fields_STATUS, $status);
        }

        return $query->orderBy(AiBillingInvoice::schema_fields_CREATED_TIME, 'DESC')
                    ->limit($limit, $offset)
                    ->select()
                    ->fetch();
    }

    /**
     * 处理支付
     * 
     * @param int $invoiceId 发票ID
     * @param string $paymentMethod 支付方式
     * @param string $transactionId 交易ID
     * @return bool
     * @throws Exception
     */
    public function processPayment(int $invoiceId, string $paymentMethod, string $transactionId = ''): bool
    {
        $invoice = $this->billingInvoiceModel->reset()
            ->where(AiBillingInvoice::schema_fields_ID, $invoiceId)
            ->find()
            ->fetch();

        if (!$invoice->getId()) {
            throw new Exception("发票不存在: {$invoiceId}");
        }

        if ($invoice->isPaid()) {
            throw new Exception("发票已支付");
        }

        if ($invoice->isCancelled()) {
            throw new Exception("发票已取消");
        }

        // 标记为已支付
        $invoice->markAsPaid($paymentMethod, $transactionId);
        $invoice->save();

        // 更新租户订阅状态
        $this->updateTenantSubscription($invoice->getTenantId());

        return true;
    }

    /**
     * 更新租户订阅状态
     * 
     * @param int $tenantId 租户ID
     * @return bool
     */
    private function updateTenantSubscription(int $tenantId): bool
    {
        $tenant = $this->tenantModel->reset()
            ->where(AiTenant::schema_fields_ID, $tenantId)
            ->find()
            ->fetch();

        if (!$tenant->getId()) {
            return false;
        }

        // 检查是否有未支付的发票
        $pendingInvoices = $this->billingInvoiceModel->reset()
            ->where(AiBillingInvoice::schema_fields_TENANT_ID, $tenantId)
            ->where(AiBillingInvoice::schema_fields_STATUS, AiBillingInvoice::STATUS_PENDING)
            ->select()
            ->fetch();

        $hasOverdue = false;
        if ($pendingInvoices && is_iterable($pendingInvoices)) {
            foreach ($pendingInvoices as $invoice) {
                if (is_object($invoice) && $invoice->checkOverdue()) {
                    $invoice->markAsOverdue()->save();
                    $hasOverdue = true;
                }
            }
        }

        // 更新租户状态
        if ($hasOverdue) {
            $tenant->setData(AiTenant::schema_fields_STATUS, AiTenant::STATUS_SUSPENDED);
        } else {
            $tenant->setData(AiTenant::schema_fields_STATUS, AiTenant::STATUS_ACTIVE);
        }

        return $tenant->save();
    }

    /**
     * 计算使用量费用
     * 
     * @param int $tenantId 租户ID
     * @param string $resourceType 资源类型
     * @param int $usage 使用量
     * @param float $unitPrice 单价
     * @return float
     */
    public function calculateUsageCost(int $tenantId, string $resourceType, int $usage, float $unitPrice): float
    {
        // 获取租户的计费计划
        $tenant = $this->tenantModel->reset()
            ->where(AiTenant::schema_fields_ID, $tenantId)
            ->find()
            ->fetch();

        if (!$tenant->getId()) {
            return 0.0;
        }

        $planType = $tenant->getPlanType();
        
        // 免费计划不收费
        if ($planType === AiTenant::PLAN_FREE) {
            return 0.0;
        }

        // 计算费用
        return $usage * $unitPrice;
    }

    /**
     * 生成使用量发票
     * 
     * @param int $tenantId 租户ID
     * @param array $usageData 使用量数据
     * @return AiBillingInvoice
     * @throws Exception
     */
    public function generateUsageInvoice(int $tenantId, array $usageData): AiBillingInvoice
    {
        $totalAmount = 0.0;
        $items = [];

        foreach ($usageData as $resource => $data) {
            $usage = $data['usage'] ?? 0;
            $unitPrice = $data['unit_price'] ?? 0.0;
            $cost = $this->calculateUsageCost($tenantId, $resource, $usage, $unitPrice);
            
            if ($cost > 0) {
                $totalAmount += $cost;
                $items[] = [
                    'resource' => $resource,
                    'usage' => $usage,
                    'unit_price' => $unitPrice,
                    'cost' => $cost
                ];
            }
        }

        if ($totalAmount <= 0) {
            throw new Exception("没有需要计费的使用量");
        }

        return $this->generateInvoice($tenantId, $totalAmount, 'USD', $items);
    }

    /**
     * 获取计费统计信息
     * 
     * @param int $tenantId 租户ID
     * @param int $days 统计天数
     * @return array
     */
    public function getBillingStats(int $tenantId, int $days = 30): array
    {
        $startTime = time() - ($days * 24 * 3600);
        
        // 获取发票统计
        $invoices = $this->billingInvoiceModel->reset()
            ->where(AiBillingInvoice::schema_fields_TENANT_ID, $tenantId)
            ->where(AiBillingInvoice::schema_fields_CREATED_TIME, '>=', $startTime)
            ->select()
            ->fetch();

        $stats = [
            'total_invoices' => 0,
            'paid_invoices' => 0,
            'pending_invoices' => 0,
            'overdue_invoices' => 0,
            'total_amount' => 0.0,
            'paid_amount' => 0.0,
            'pending_amount' => 0.0,
            'overdue_amount' => 0.0
        ];

        if ($invoices && is_iterable($invoices)) {
            foreach ($invoices as $invoice) {
                if (is_object($invoice)) {
                    $stats['total_invoices']++;
                    $amount = $invoice->getTotalAmount();
                    $stats['total_amount'] += $amount;

                    switch ($invoice->getStatus()) {
                        case AiBillingInvoice::STATUS_PAID:
                            $stats['paid_invoices']++;
                            $stats['paid_amount'] += $amount;
                            break;
                        case AiBillingInvoice::STATUS_PENDING:
                            $stats['pending_invoices']++;
                            $stats['pending_amount'] += $amount;
                            break;
                        case AiBillingInvoice::STATUS_OVERDUE:
                            $stats['overdue_invoices']++;
                            $stats['overdue_amount'] += $amount;
                            break;
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * 获取逾期发票
     * 
     * @param int $tenantId 租户ID
     * @return array
     */
    public function getOverdueInvoices(int $tenantId): array
    {
        $invoices = $this->billingInvoiceModel->reset()
            ->where(AiBillingInvoice::schema_fields_TENANT_ID, $tenantId)
            ->where(AiBillingInvoice::schema_fields_STATUS, AiBillingInvoice::STATUS_PENDING)
            ->select()
            ->fetch();

        $overdueInvoices = [];
        
        if ($invoices && is_iterable($invoices)) {
            foreach ($invoices as $invoice) {
                if (is_object($invoice) && $invoice->checkOverdue()) {
                    $invoice->markAsOverdue()->save();
                    $overdueInvoices[] = $invoice;
                }
            }
        }

        return $overdueInvoices;
    }

    /**
     * 取消发票
     * 
     * @param int $invoiceId 发票ID
     * @return bool
     * @throws Exception
     */
    public function cancelInvoice(int $invoiceId): bool
    {
        $invoice = $this->billingInvoiceModel->reset()
            ->where(AiBillingInvoice::schema_fields_ID, $invoiceId)
            ->find()
            ->fetch();

        if (!$invoice->getId()) {
            throw new Exception("发票不存在: {$invoiceId}");
        }

        if ($invoice->isPaid()) {
            throw new Exception("已支付的发票不能取消");
        }

        $invoice->cancel()->save();
        return true;
    }

    /**
     * 退款
     * 
     * @param int $invoiceId 发票ID
     * @return bool
     * @throws Exception
     */
    public function refundInvoice(int $invoiceId): bool
    {
        $invoice = $this->billingInvoiceModel->reset()
            ->where(AiBillingInvoice::schema_fields_ID, $invoiceId)
            ->find()
            ->fetch();

        if (!$invoice->getId()) {
            throw new Exception("发票不存在: {$invoiceId}");
        }

        if (!$invoice->isPaid()) {
            throw new Exception("只有已支付的发票才能退款");
        }

        $invoice->refund()->save();
        return true;
    }

    /**
     * 获取计费计划比较数据
     * 
     * @return array
     */
    public function getPlanComparison(): array
    {
        $plans = $this->getAllBillingPlans();
        $comparison = [];

        if ($plans && is_iterable($plans)) {
            foreach ($plans as $plan) {
                if (is_object($plan)) {
                    $comparison[] = $plan->getComparisonData();
                }
            }
        }

        return $comparison;
    }
}
