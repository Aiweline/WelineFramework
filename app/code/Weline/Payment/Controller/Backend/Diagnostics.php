<?php

declare(strict_types=1);

namespace Weline\Payment\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentReconciliationAudit;
use Weline\Payment\Model\PaymentRefund;
use Weline\Payment\Model\PaymentWebhookEndpoint;
use Weline\Payment\Model\PaymentWebhookInbox;

#[Acl('Weline_Payment::payment_diagnostics', '支付诊断工作台', 'mdi-stethoscope', '支付只读诊断', 'Weline_Backend::payment_group')]
final class Diagnostics extends BackendController
{
    #[Acl('Weline_Payment::payment_webhook', 'Webhook诊断', 'mdi-webhook', '查看 Webhook 端点与收件状态')]
    public function webhooks(): string
    {
        return $this->renderWorkspace('webhooks', 'Webhook诊断', [
            'Webhook 端点' => [PaymentWebhookEndpoint::class, ['endpoint_code', 'provider_code', 'method_code', 'environment', 'status', 'active_secret_version', 'context_version', 'allow_new_capture', 'retain_until', 'created_at', 'updated_at']],
            'Webhook 收件' => [PaymentWebhookInbox::class, ['inbox_code', 'endpoint_code', 'provider_event_id', 'provider_code', 'environment', 'schema_version', 'verification_secret_version', 'status', 'consumer_version', 'ignore_reason', 'intent_code', 'attempt_code', 'event_type', 'status_transition', 'received_at', 'applied_at', 'retain_until', 'created_at']],
        ]);
    }

    #[Acl('Weline_Payment::payment_reconciliation', '支付对账', 'mdi-scale-balance', '查看支付对账审计')]
    public function reconciliation(): string
    {
        return $this->renderWorkspace('reconciliation', '支付对账', ['对账审计' => [PaymentReconciliationAudit::class, ['audit_code', 'mode', 'scope', 'actor_user_id', 'approver_user_id', 'actor_grant_version', 'approver_grant_version', 'diff_count', 'repaired_count', 'status', 'retain_until', 'created_at']]]);
    }

    #[Acl('Weline_Payment::payment_refund_reconciliation', '退款对账', 'mdi-cash-refund', '查看退款对账事实')]
    public function refundReconciliation(): string
    {
        return $this->renderWorkspace('refund-reconciliation', '退款对账', ['退款记录' => [PaymentRefund::class, ['refund_code', 'transaction_code', 'intent_code', 'attempt_code', 'method_code', 'provider_code', 'payable_type', 'payable_id', 'reason', 'requested_amount_minor', 'approved_amount_minor', 'currency', 'precision', 'status', 'channel_status', 'refund_case_uuid', 'provider_refund_id', 'version', 'created_at', 'updated_at', 'requested_at', 'approved_at', 'completed_at', 'failed_at']]]);
    }

    /** @param array<string,array{0:class-string,1:list<string>}> $sources */
    private function renderWorkspace(string $code, string $title, array $sources): string
    {
        $datasets = [];
        foreach ($sources as $label => [$modelClass, $fields]) {
            try {
                $rows = ObjectManager::getInstance($modelClass)->reset()->limit(50)->select()->fetchArray();
                $safeRows = [];
                foreach ($rows as $row) if (is_array($row)) $safeRows[] = array_intersect_key($row, array_flip($fields));
                $datasets[] = ['label' => __($label), 'rows' => $safeRows, 'error' => ''];
            } catch (\Throwable $throwable) {
                $datasets[] = ['label' => __($label), 'rows' => [], 'error' => $throwable->getMessage()];
            }
        }
        $this->assign('workspace_code', $code);
        $this->assign('workspace_title', __($title));
        $this->assign('workspace_datasets', $datasets);
        return $this->fetch('index');
    }
}
