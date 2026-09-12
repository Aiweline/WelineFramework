<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Backend;

use Weline\Dropship\Model\DropshipPushOutbox;
use Weline\Dropship\Service\DropshipOutboxService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Dropship::commerce:dropship:aftersale', '售后异常', 'alert', '货源售后异常', 'Weline_Dropship::commerce:dropship:group')]
class AfterSale extends BackendController
{
    #[Acl('Weline_Dropship::commerce:dropship:aftersale_index', '查看售后异常', 'alert', '查看出库失败与跳过')]
    public function index(): string
    {
        $providerFilter = trim((string)$this->request->getGet('provider', ''));
        /** @var DropshipOutboxService $outboxSvc */
        $outboxSvc = ObjectManager::getInstance(DropshipOutboxService::class);
        /** @var DropshipPushOutbox $o */
        $o = ObjectManager::getInstance(DropshipPushOutbox::class);
        $q = $o->clear()
            ->where(DropshipPushOutbox::schema_fields_STATUS, [
                DropshipPushOutbox::STATUS_ERROR,
                DropshipPushOutbox::STATUS_SKIPPED,
                DropshipPushOutbox::STATUS_DEAD,
            ], 'IN');
        if ($providerFilter !== '') {
            $q->where(DropshipPushOutbox::schema_fields_PROVIDER_CODE, $providerFilter);
        }
        $rows = $q->order(DropshipPushOutbox::schema_fields_ID, 'DESC')
            ->limit(100)
            ->select()
            ->fetchArray();

        $enriched = [];
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bizKey = (string)($row['biz_key'] ?? '');
            $outboxStatus = (string)($row['status'] ?? '');
            if ($bizKey !== '' && $outboxStatus === DropshipPushOutbox::STATUS_DEAD) {
                $loaded = ObjectManager::getInstance(DropshipPushOutbox::class)
                    ->clear()
                    ->where(DropshipPushOutbox::schema_fields_BIZ_KEY, $bizKey)
                    ->find()
                    ->fetch();
                if ($loaded && $loaded->getId()) {
                    if (!$outboxSvc->maybeRecoverExistingFulfillment($loaded, $bizKey)) {
                        $outboxSvc->maybeBackfillTerminalCompensation($loaded, $bizKey);
                    }
                    $refreshed = ObjectManager::getInstance(DropshipPushOutbox::class)
                        ->clear()
                        ->where(DropshipPushOutbox::schema_fields_BIZ_KEY, $bizKey)
                        ->find()
                        ->fetch();
                    if ($refreshed && $refreshed->getId()) {
                        $row = $refreshed->getData();
                        $outboxStatus = (string)($row['status'] ?? $outboxStatus);
                    }
                }
            }

            // 已恢复为 done 的样例不再出现在售后列表
            if ($outboxStatus === DropshipPushOutbox::STATUS_DONE) {
                continue;
            }

            $payload = json_decode((string)($row['payload_json'] ?? ''), true) ?: [];
            $comp = is_array($payload['compensation'] ?? null) ? $payload['compensation'] : [];
            $compStatus = (string)($comp['status'] ?? '');
            $compError = trim((string)($comp['error_code'] ?? ''));

            $row['status_label'] = match ($outboxStatus) {
                DropshipPushOutbox::STATUS_DEAD => (string)__('无法履约'),
                DropshipPushOutbox::STATUS_ERROR => (string)__('推单失败'),
                DropshipPushOutbox::STATUS_SKIPPED => (string)__('已跳过'),
                default => $outboxStatus,
            };
            $row['status_tone'] = match ($outboxStatus) {
                DropshipPushOutbox::STATUS_DEAD => 'danger',
                DropshipPushOutbox::STATUS_ERROR => 'warning',
                default => 'neutral',
            };
            $row['compensation_status'] = $compStatus;
            $row['compensation_error_code'] = $compError;
            if ($compStatus === '') {
                $row['compensation_label'] = $outboxStatus === DropshipPushOutbox::STATUS_DEAD
                    ? (string)__('待补偿')
                    : (string)__('—');
                $row['compensation_tone'] = $outboxStatus === DropshipPushOutbox::STATUS_DEAD ? 'warning' : 'neutral';
            } else {
                $row['compensation_label'] = match ($compStatus) {
                    'refunded' => (string)__('已自动退款'),
                    'refund_failed' => (string)__('自动退款失败')
                        . ($compError !== '' ? ' · ' . $compError : ''),
                    'switch_off' => (string)__('开关关闭待人工'),
                    'mail_failed' => (string)__('已退款邮件失败'),
                    'recovered_existing' => (string)__('已对齐远端订单'),
                    default => $compStatus,
                };
                $row['compensation_tone'] = match ($compStatus) {
                    'refunded', 'recovered_existing' => 'success',
                    'refund_failed', 'mail_failed' => 'danger',
                    'switch_off' => 'warning',
                    default => 'neutral',
                };
            }
            $enriched[] = $row;
        }

        $this->assign('page_title', __('售后异常'));
        $this->assign('rows', $enriched);
        $this->assign('provider_filter', $providerFilter);
        $urlBuilder = $this->request->getUrlBuilder();
        $this->assign('orders_url', (string)$urlBuilder->getBackendUrl('dropship/backend/order/index'));
        $this->assign('config_url', (string)$urlBuilder->getBackendUrl('dropship/backend/config', [
            'target_scope' => 'default.default.default',
        ]));

        return $this->fetch();
    }
}
