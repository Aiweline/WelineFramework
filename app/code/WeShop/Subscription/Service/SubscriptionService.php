<?php

declare(strict_types=1);

namespace WeShop\Subscription\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Subscription\Model\Subscription;
use WeShop\Subscription\Model\SubscriptionHistory;
use WeShop\Subscription\Model\SubscriptionPlan;

/**
 * @DESC | 订阅业务服务
 */
class SubscriptionService
{
    private Subscription $subscription;
    private SubscriptionPlan $subscriptionPlan;
    private SubscriptionHistory $subscriptionHistory;
    private EventsManager $eventsManager;

    public function __construct(
        Subscription        $subscription,
        SubscriptionPlan    $subscriptionPlan,
        SubscriptionHistory $subscriptionHistory,
        EventsManager       $eventsManager
    ) {
        $this->subscription = $subscription;
        $this->subscriptionPlan = $subscriptionPlan;
        $this->subscriptionHistory = $subscriptionHistory;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 创建订阅
     *
     * @param int    $customerId 客户ID
     * @param int    $planId     计划ID
     * @param int    $orderId    订单ID
     * @param string $paymentMethod 支付方式
     * @return Subscription
     * @throws \Exception
     */
    public function createSubscription(
        int    $customerId,
        int    $planId,
        int    $orderId = 0,
        string $paymentMethod = ''
    ): Subscription {
        // 加载订阅计划
        $plan = ObjectManager::getInstance(SubscriptionPlan::class);
        $plan->load($planId);

        if (!$plan->getId()) {
            throw new \Exception(__('订阅计划不存在'));
        }

        if ((int)$plan->getData(SubscriptionPlan::schema_fields_STATUS) !== SubscriptionPlan::STATUS_ENABLED) {
            throw new \Exception(__('订阅计划已停用'));
        }

        $now = date('Y-m-d H:i:s');
        $trialDays = (int)$plan->getData(SubscriptionPlan::schema_fields_TRIAL_DAYS);
        $billingCycle = $plan->getData(SubscriptionPlan::schema_fields_BILLING_CYCLE);
        $billingInterval = (int)$plan->getData(SubscriptionPlan::schema_fields_BILLING_INTERVAL);

        // 计算周期
        $periodStart = $now;
        $periodEnd = $this->calculatePeriodEnd($periodStart, $billingCycle, $billingInterval);

        // 确定初始状态
        $status = Subscription::STATUS_ACTIVE;
        $trialEndsAt = null;

        if ($trialDays > 0) {
            $status = Subscription::STATUS_TRIALING;
            $trialEndsAt = date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));
            $periodEnd = $trialEndsAt;
        }

        // 创建订阅记录
        /** @var Subscription $subscription */
        $subscription = ObjectManager::getInstance(Subscription::class);
        $subscription->clearData()
            ->setData(Subscription::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(Subscription::schema_fields_PLAN_ID, $planId)
            ->setData(Subscription::schema_fields_PRODUCT_ID, $plan->getData(SubscriptionPlan::schema_fields_PRODUCT_ID))
            ->setData(Subscription::schema_fields_ORDER_ID, $orderId)
            ->setData(Subscription::schema_fields_STATUS, $status)
            ->setData(Subscription::schema_fields_PRICE, $plan->getData(SubscriptionPlan::schema_fields_PRICE))
            ->setData(Subscription::schema_fields_BILLING_CYCLE, $billingCycle)
            ->setData(Subscription::schema_fields_BILLING_INTERVAL, $billingInterval)
            ->setData(Subscription::schema_fields_TRIAL_ENDS_AT, $trialEndsAt)
            ->setData(Subscription::schema_fields_CURRENT_PERIOD_START, $periodStart)
            ->setData(Subscription::schema_fields_CURRENT_PERIOD_END, $periodEnd)
            ->setData(Subscription::schema_fields_NEXT_BILLING_AT, $periodEnd)
            ->setData(Subscription::schema_fields_PAYMENT_METHOD, $paymentMethod)
            ->setData(Subscription::schema_fields_RENEWAL_COUNT, 0)
            ->setData(Subscription::schema_fields_CREATED_AT, $now)
            ->setData(Subscription::schema_fields_UPDATED_AT, $now)
            ->save();

        // 记录历史
        $action = $trialDays > 0 ? SubscriptionHistory::ACTION_TRIAL_STARTED : SubscriptionHistory::ACTION_CREATED;
        $this->addHistory(
            (int)$subscription->getId(),
            $action,
            (float)$plan->getData(SubscriptionPlan::schema_fields_PRICE),
            $orderId,
            __('创建订阅：%{plan}', ['plan' => $plan->getData(SubscriptionPlan::schema_fields_NAME)])
        );

        // 触发事件
        $eventData = ['data' => ['subscription' => $subscription, 'plan' => $plan]];
        $this->eventsManager->dispatch('WeShop_Subscription::subscription_created', $eventData);

        return $subscription;
    }

    /**
     * 续费订阅
     *
     * @param int $subscriptionId 订阅ID
     * @param int $orderId        订单ID
     * @return Subscription
     * @throws \Exception
     */
    public function renewSubscription(int $subscriptionId, int $orderId = 0): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = ObjectManager::getInstance(Subscription::class);
        $subscription->load($subscriptionId);

        if (!$subscription->getId()) {
            throw new \Exception(__('订阅不存在'));
        }

        if (!$subscription->isActive() && $subscription->getData(Subscription::schema_fields_STATUS) !== Subscription::STATUS_PAST_DUE) {
            throw new \Exception(__('订阅状态不允许续费'));
        }

        $now = date('Y-m-d H:i:s');
        $billingCycle = $subscription->getData(Subscription::schema_fields_BILLING_CYCLE);
        $billingInterval = (int)$subscription->getData(Subscription::schema_fields_BILLING_INTERVAL);

        $periodStart = $now;
        $periodEnd = $this->calculatePeriodEnd($periodStart, $billingCycle, $billingInterval);

        $renewalCount = (int)$subscription->getData(Subscription::schema_fields_RENEWAL_COUNT);

        $subscription
            ->setData(Subscription::schema_fields_STATUS, Subscription::STATUS_ACTIVE)
            ->setData(Subscription::schema_fields_CURRENT_PERIOD_START, $periodStart)
            ->setData(Subscription::schema_fields_CURRENT_PERIOD_END, $periodEnd)
            ->setData(Subscription::schema_fields_NEXT_BILLING_AT, $periodEnd)
            ->setData(Subscription::schema_fields_RENEWAL_COUNT, $renewalCount + 1)
            ->setData(Subscription::schema_fields_UPDATED_AT, $now)
            ->save();

        // 记录历史
        $this->addHistory(
            $subscriptionId,
            SubscriptionHistory::ACTION_RENEWED,
            (float)$subscription->getData(Subscription::schema_fields_PRICE),
            $orderId,
            __('续费成功，第%{count}次续费', ['count' => $renewalCount + 1])
        );

        // 触发事件
        $eventData = ['data' => ['subscription' => $subscription]];
        $this->eventsManager->dispatch('WeShop_Subscription::subscription_renewed', $eventData);

        return $subscription;
    }

    /**
     * 取消订阅
     *
     * @param int    $subscriptionId 订阅ID
     * @param int    $customerId     客户ID（验证用）
     * @param string $reason         取消原因
     * @return Subscription
     * @throws \Exception
     */
    public function cancelSubscription(int $subscriptionId, int $customerId = 0, string $reason = ''): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = ObjectManager::getInstance(Subscription::class);
        $subscription->load($subscriptionId);

        if (!$subscription->getId()) {
            throw new \Exception(__('订阅不存在'));
        }

        // 验证客户权限
        if ($customerId > 0 && (int)$subscription->getData(Subscription::schema_fields_CUSTOMER_ID) !== $customerId) {
            throw new \Exception(__('无权操作此订阅'));
        }

        if (!$subscription->canCancel()) {
            throw new \Exception(__('当前订阅状态不允许取消'));
        }

        $now = date('Y-m-d H:i:s');

        $subscription
            ->setData(Subscription::schema_fields_STATUS, Subscription::STATUS_CANCELLED)
            ->setData(Subscription::schema_fields_CANCELLED_AT, $now)
            ->setData(Subscription::schema_fields_CANCEL_REASON, $reason)
            ->setData(Subscription::schema_fields_UPDATED_AT, $now)
            ->save();

        // 记录历史
        $note = $reason ? __('取消订阅，原因：%{reason}', ['reason' => $reason]) : __('取消订阅');
        $this->addHistory($subscriptionId, SubscriptionHistory::ACTION_CANCELLED, 0, 0, $note);

        // 触发事件
        $eventData = ['data' => ['subscription' => $subscription, 'reason' => $reason]];
        $this->eventsManager->dispatch('WeShop_Subscription::subscription_cancelled', $eventData);

        return $subscription;
    }

    /**
     * 暂停订阅
     *
     * @param int $subscriptionId 订阅ID
     * @param int $customerId     客户ID（验证用）
     * @return Subscription
     * @throws \Exception
     */
    public function pauseSubscription(int $subscriptionId, int $customerId = 0): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = ObjectManager::getInstance(Subscription::class);
        $subscription->load($subscriptionId);

        if (!$subscription->getId()) {
            throw new \Exception(__('订阅不存在'));
        }

        if ($customerId > 0 && (int)$subscription->getData(Subscription::schema_fields_CUSTOMER_ID) !== $customerId) {
            throw new \Exception(__('无权操作此订阅'));
        }

        if (!$subscription->canPause()) {
            throw new \Exception(__('当前订阅状态不允许暂停'));
        }

        $now = date('Y-m-d H:i:s');

        $subscription
            ->setData(Subscription::schema_fields_STATUS, Subscription::STATUS_PAUSED)
            ->setData(Subscription::schema_fields_PAUSED_AT, $now)
            ->setData(Subscription::schema_fields_UPDATED_AT, $now)
            ->save();

        // 记录历史
        $this->addHistory($subscriptionId, SubscriptionHistory::ACTION_PAUSED, 0, 0, __('暂停订阅'));

        // 触发事件
        $eventData = ['data' => ['subscription' => $subscription]];
        $this->eventsManager->dispatch('WeShop_Subscription::subscription_paused', $eventData);

        return $subscription;
    }

    /**
     * 恢复订阅
     *
     * @param int $subscriptionId 订阅ID
     * @param int $customerId     客户ID（验证用）
     * @return Subscription
     * @throws \Exception
     */
    public function resumeSubscription(int $subscriptionId, int $customerId = 0): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = ObjectManager::getInstance(Subscription::class);
        $subscription->load($subscriptionId);

        if (!$subscription->getId()) {
            throw new \Exception(__('订阅不存在'));
        }

        if ($customerId > 0 && (int)$subscription->getData(Subscription::schema_fields_CUSTOMER_ID) !== $customerId) {
            throw new \Exception(__('无权操作此订阅'));
        }

        if (!$subscription->canResume()) {
            throw new \Exception(__('当前订阅状态不允许恢复'));
        }

        $now = date('Y-m-d H:i:s');
        $billingCycle = $subscription->getData(Subscription::schema_fields_BILLING_CYCLE);
        $billingInterval = (int)$subscription->getData(Subscription::schema_fields_BILLING_INTERVAL);

        $periodStart = $now;
        $periodEnd = $this->calculatePeriodEnd($periodStart, $billingCycle, $billingInterval);

        $subscription
            ->setData(Subscription::schema_fields_STATUS, Subscription::STATUS_ACTIVE)
            ->setData(Subscription::schema_fields_PAUSED_AT, null)
            ->setData(Subscription::schema_fields_CURRENT_PERIOD_START, $periodStart)
            ->setData(Subscription::schema_fields_CURRENT_PERIOD_END, $periodEnd)
            ->setData(Subscription::schema_fields_NEXT_BILLING_AT, $periodEnd)
            ->setData(Subscription::schema_fields_UPDATED_AT, $now)
            ->save();

        // 记录历史
        $this->addHistory($subscriptionId, SubscriptionHistory::ACTION_RESUMED, 0, 0, __('恢复订阅'));

        // 触发事件
        $eventData = ['data' => ['subscription' => $subscription]];
        $this->eventsManager->dispatch('WeShop_Subscription::subscription_resumed', $eventData);

        return $subscription;
    }

    /**
     * 获取客户订阅列表
     *
     * @param int   $customerId 客户ID
     * @param int   $page       页码
     * @param int   $pageSize   每页数量
     * @param array $filters    筛选条件
     * @return array
     */
    public function getCustomerSubscriptions(int $customerId, int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        /** @var Subscription $subscription */
        $subscription = ObjectManager::getInstance(Subscription::class);

        $subscription->clear()
            ->where(Subscription::schema_fields_CUSTOMER_ID, $customerId);

        if (!empty($filters['status'])) {
            $subscription->where(Subscription::schema_fields_STATUS, $filters['status']);
        }

        $subscription->order(Subscription::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        $items = $subscription->select()->fetchArray();

        return [
            'items'      => $items,
            'total'      => $subscription->getTotalCount(),
            'pagination' => $subscription->getPagination(),
        ];
    }

    /**
     * 获取需要续费的订阅列表（定时任务用）
     *
     * @return array
     */
    public function getDueSubscriptions(): array
    {
        /** @var Subscription $subscription */
        $subscription = ObjectManager::getInstance(Subscription::class);

        $now = date('Y-m-d H:i:s');

        return $subscription->clear()
            ->where(Subscription::schema_fields_STATUS, [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING], 'IN')
            ->where(Subscription::schema_fields_NEXT_BILLING_AT, $now, '<=')
            ->order(Subscription::schema_fields_NEXT_BILLING_AT, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * 标记订阅为逾期
     *
     * @param int $subscriptionId 订阅ID
     * @return void
     */
    public function markAsPastDue(int $subscriptionId): void
    {
        /** @var Subscription $subscription */
        $subscription = ObjectManager::getInstance(Subscription::class);
        $subscription->load($subscriptionId);

        if ($subscription->getId() && $subscription->isActive()) {
            $now = date('Y-m-d H:i:s');
            $subscription
                ->setData(Subscription::schema_fields_STATUS, Subscription::STATUS_PAST_DUE)
                ->setData(Subscription::schema_fields_UPDATED_AT, $now)
                ->save();

            $this->addHistory($subscriptionId, SubscriptionHistory::ACTION_PAYMENT_FAILED, 0, 0, __('支付失败，订阅已逾期'));
        }
    }

    /**
     * 标记订阅为过期
     *
     * @param int $subscriptionId 订阅ID
     * @return void
     */
    public function markAsExpired(int $subscriptionId): void
    {
        /** @var Subscription $subscription */
        $subscription = ObjectManager::getInstance(Subscription::class);
        $subscription->load($subscriptionId);

        if ($subscription->getId()) {
            $now = date('Y-m-d H:i:s');
            $subscription
                ->setData(Subscription::schema_fields_STATUS, Subscription::STATUS_EXPIRED)
                ->setData(Subscription::schema_fields_UPDATED_AT, $now)
                ->save();

            $this->addHistory($subscriptionId, SubscriptionHistory::ACTION_EXPIRED, 0, 0, __('订阅已过期'));

            // 触发事件
            $eventData = ['data' => ['subscription' => $subscription]];
            $this->eventsManager->dispatch('WeShop_Subscription::subscription_expired', $eventData);
        }
    }

    /**
     * 获取订阅历史记录
     *
     * @param int $subscriptionId 订阅ID
     * @param int $page           页码
     * @param int $pageSize       每页数量
     * @return array
     */
    public function getSubscriptionHistory(int $subscriptionId, int $page = 1, int $pageSize = 20): array
    {
        /** @var SubscriptionHistory $history */
        $history = ObjectManager::getInstance(SubscriptionHistory::class);

        $history->clear()
            ->where(SubscriptionHistory::schema_fields_SUBSCRIPTION_ID, $subscriptionId)
            ->order(SubscriptionHistory::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        $items = $history->select()->fetchArray();

        return [
            'items'      => $items,
            'total'      => $history->getTotalCount(),
            'pagination' => $history->getPagination(),
        ];
    }

    /**
     * 获取活跃订阅统计
     *
     * @return array
     */
    public function getStatistics(): array
    {
        /** @var Subscription $subscription */
        $subscription = ObjectManager::getInstance(Subscription::class);

        $activeCount = $subscription->clear()
            ->where(Subscription::schema_fields_STATUS, Subscription::STATUS_ACTIVE)
            ->select()
            ->getTotalCount();

        $trialingCount = $subscription->clear()
            ->where(Subscription::schema_fields_STATUS, Subscription::STATUS_TRIALING)
            ->select()
            ->getTotalCount();

        $pastDueCount = $subscription->clear()
            ->where(Subscription::schema_fields_STATUS, Subscription::STATUS_PAST_DUE)
            ->select()
            ->getTotalCount();

        $cancelledCount = $subscription->clear()
            ->where(Subscription::schema_fields_STATUS, Subscription::STATUS_CANCELLED)
            ->select()
            ->getTotalCount();

        $totalCount = $subscription->clear()
            ->select()
            ->getTotalCount();

        return [
            'total'     => $totalCount,
            'active'    => $activeCount,
            'trialing'  => $trialingCount,
            'past_due'  => $pastDueCount,
            'cancelled' => $cancelledCount,
        ];
    }

    /**
     * 添加订阅历史记录
     *
     * @param int    $subscriptionId 订阅ID
     * @param string $action         操作类型
     * @param float  $amount         金额
     * @param int    $orderId        订单ID
     * @param string $note           备注
     * @param string $operator       操作者
     * @return void
     */
    public function addHistory(
        int    $subscriptionId,
        string $action,
        float  $amount = 0,
        int    $orderId = 0,
        string $note = '',
        string $operator = ''
    ): void {
        /** @var SubscriptionHistory $history */
        $history = ObjectManager::getInstance(SubscriptionHistory::class);
        $history->clearData()
            ->setData(SubscriptionHistory::schema_fields_SUBSCRIPTION_ID, $subscriptionId)
            ->setData(SubscriptionHistory::schema_fields_ORDER_ID, $orderId)
            ->setData(SubscriptionHistory::schema_fields_ACTION, $action)
            ->setData(SubscriptionHistory::schema_fields_AMOUNT, $amount)
            ->setData(SubscriptionHistory::schema_fields_NOTE, $note)
            ->setData(SubscriptionHistory::schema_fields_OPERATOR, $operator)
            ->setData(SubscriptionHistory::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    /**
     * 计算周期结束时间
     *
     * @param string $startDate       开始日期
     * @param string $billingCycle    计费周期
     * @param int    $billingInterval 计费间隔
     * @return string
     */
    protected function calculatePeriodEnd(string $startDate, string $billingCycle, int $billingInterval): string
    {
        $interval = match ($billingCycle) {
            SubscriptionPlan::CYCLE_DAY   => "+{$billingInterval} days",
            SubscriptionPlan::CYCLE_WEEK  => "+{$billingInterval} weeks",
            SubscriptionPlan::CYCLE_MONTH => "+{$billingInterval} months",
            SubscriptionPlan::CYCLE_YEAR  => "+{$billingInterval} years",
            default                       => "+{$billingInterval} months",
        };

        return date('Y-m-d H:i:s', strtotime($interval, strtotime($startDate)));
    }
}
