<?php

declare(strict_types=1);

namespace Weline\Subscription\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Subscription\Model\Subscription;
use Weline\Subscription\Model\SubscriptionBillingAttempt;
use Weline\Subscription\Model\SubscriptionMissedWatermark;
use Weline\Subscription\Model\SubscriptionPeriod;
use Weline\Subscription\Model\SubscriptionSchedulerLease;
use Weline\Subscription\Service\SubscriptionAdminService;
use Weline\Subscription\Service\SubscriptionConflictException;
use Weline\Subscription\Service\SubscriptionRolloutGate;

#[Acl('Weline_Subscription::commerce:partner:control-center', '订阅管理', 'mdi-calendar-sync-outline', '订阅创建、周期与续费管理', 'Weline_Backend::commerce:partner:group')]
final class ControlCenter extends BackendController
{
    public function __construct(private readonly SubscriptionAdminService $adminService)
    {
    }

    #[Acl('Weline_Subscription::commerce:partner:subscriptions', '订阅', 'mdi-calendar-check-outline', '查看订阅')]
    public function subscriptions(): string
    {
        return $this->renderWorkspace('subscriptions', '订阅', ['订阅记录' => [Subscription::class, ['subscription_id', 'customer_id', 'website_id', 'store_id', 'provider_code', 'plan_code', 'environment', 'status', 'version', 'current_period_index', 'created_at', 'updated_at', 'cancelled_at']]], [], ['kind' => 'subscription', 'action' => 'subscription/backend/control-center/save-subscription']);
    }

    #[Acl('Weline_Subscription::commerce:partner:periods', '订阅周期', 'mdi-calendar-range', '查看订阅周期')]
    public function periods(): string
    {
        return $this->renderWorkspace('periods', '订阅周期', ['周期记录' => [SubscriptionPeriod::class, ['period_key', 'subscription_id', 'period_index', 'website_id', 'status', 'period_version', 'order_ref', 'missed_reason', 'opened_at', 'updated_at']]]);
    }

    #[Acl('Weline_Subscription::commerce:partner:renewals', '续费调度', 'mdi-autorenew', '查看续费调度租约')]
    public function renewals(): string
    {
        return $this->renderWorkspace('renewals', '续费调度', ['调度租约' => [SubscriptionSchedulerLease::class, ['subscription_id', 'worker_id', 'lease_version', 'expires_at_epoch', 'updated_at']]], [], ['kind' => 'renewal', 'action' => 'subscription/backend/control-center/run-renewal']);
    }

    #[Acl('Weline_Subscription::commerce:partner:attempts', '续费尝试', 'mdi-history', '查看续费尝试')]
    public function attempts(): string
    {
        return $this->renderWorkspace('attempts', '续费尝试', ['尝试记录' => [SubscriptionBillingAttempt::class, ['attempt_id', 'period_key', 'subscription_id', 'attempt_no', 'worker_id', 'status', 'order_ref', 'payment_intent_code', 'payment_attempt_code', 'payment_status', 'error_code', 'attempt_version', 'started_at', 'updated_at', 'finished_at']]]);
    }

    #[Acl('Weline_Subscription::commerce:partner:missed-watermarks', '失败水位', 'mdi-alert-circle-outline', '查看订阅失败水位')]
    public function missedWatermarks(): string
    {
        return $this->renderWorkspace('missed-watermarks', '失败水位', ['失败水位记录' => [SubscriptionMissedWatermark::class, ['subscription_id', 'period_index', 'period_key', 'reason', 'watermark_version', 'updated_at']]]);
    }

    #[Acl('Weline_Subscription::commerce:partner:migration', '迁移状态', 'mdi-database-eye-outline', '只读查看订阅迁移状态')]
    public function migration(): string
    {
        return $this->renderWorkspace('migration', '迁移状态', [], $this->rolloutStatus() + [
            'execution_policy' => 'registered_postgresql_full_clone_cli_only',
            'production_actions_exposed' => false,
        ]);
    }

    #[Acl('Weline_Subscription::commerce:partner:subscriptions', '创建订阅', 'mdi-calendar-plus', '创建订阅并开启首个周期')]
    public function saveSubscription()
    {
        return $this->executeWrite('create', 'subscriptions', '订阅及首个周期已创建。');
    }

    #[Acl('Weline_Subscription::commerce:partner:renewals', '执行续费', 'mdi-autorenew', '通过订单与沙箱支付端口执行续费尝试')]
    public function runRenewal()
    {
        return $this->executeWrite('renew', 'renewals', '续费尝试已执行。');
    }

    /** @param array<string,array{0:class-string,1:list<string>}> $sources */
    private function renderWorkspace(string $code, string $title, array $sources, array $status = [], array $form = []): string
    {
        $datasets = [];
        foreach ($sources as $label => [$modelClass, $fields]) {
            $datasets[] = $this->loadRows($label, $modelClass, $fields);
        }
        $this->assign('workspace_code', $code);
        $this->assign('workspace_title', __($title));
        $this->assign('workspace_status', $status);
        $this->assign('workspace_datasets', $datasets);
        $this->assign('workspace_form', $form);
        return $this->fetch('index');
    }

    private function executeWrite(string $command, string $returnPage, string $successMessage)
    {
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $this->adminService->{$command}((array)$this->request->getPost());
            MessageManager::success((string)__($successMessage));
        } catch (\Throwable $throwable) {
            $reference = $this->reportFailure($throwable, 'write:' . $command);
            MessageManager::error((string)__('操作失败，请稍后重试。参考编号：%{1}', [$reference]));
        }
        return $this->redirect('subscription/backend/control-center/' . $returnPage);
    }

    /** @param class-string $modelClass @param list<string> $fields */
    private function loadRows(string $label, string $modelClass, array $fields): array
    {
        try {
            $rows = ObjectManager::getInstance($modelClass)->reset()->limit(50)->select()->fetchArray();
            $safeRows = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $safeRows[] = array_intersect_key($row, array_flip($fields));
                }
            }
            return ['label' => __($label), 'rows' => $safeRows, 'error' => ''];
        } catch (\Throwable $throwable) {
            $reference = $this->reportFailure($throwable, 'load:' . $modelClass);
            return ['label' => __($label), 'rows' => [], 'error' => (string)__('数据暂时无法加载。参考编号：%{1}', [$reference])];
        }
    }

    private function rolloutStatus(): array
    {
        try {
            $configuration = ObjectManager::getInstance(SubscriptionRolloutGate::class)->configuration();
            return [
                'mode' => (string)($configuration['mode'] ?? 'off'),
                'allowlist_count' => count((array)($configuration['allowlist'] ?? [])),
                'env_locked' => !empty($configuration['env_locked']),
            ];
        } catch (\Throwable $throwable) {
            $reference = $this->reportFailure($throwable, 'rollout-status');
            return ['status_error' => (string)__('状态暂时无法读取。参考编号：%{1}', [$reference])];
        }
    }

    private function reportFailure(\Throwable $throwable, string $operation): string
    {
        $reference = strtoupper(substr(hash('sha256', self::class . '|' . $operation . '|' . uniqid('', true)), 0, 12));
        try {
            $context = [
                'reference' => $reference,
                'controller' => self::class,
                'operation' => $operation,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ];
            if ($throwable instanceof SubscriptionConflictException) {
                $context['error_code'] = $throwable->errorCode;
                $context['error_context'] = $throwable->context;
            }
            w_log_error('Commerce backend operation failed', $context, 'commerce_backend');
        } catch (\Throwable) {
        }
        return $reference;
    }
}
