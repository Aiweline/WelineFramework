<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Lifecycle\LifecycleCampaign;
use Weline\Marketing\Model\Lifecycle\LifecycleSendLog;

/**
 * 欢迎礼：注册后发信 ± 券；同客同活动仅一次。
 */
final class WelcomeLifecycleService
{
    /**
     * @param array{customer_id:int,email:string,name?:string,website_id?:int,locale?:string} $customer
     * @return array{sent:int,skipped:int,failed:int}
     */
    public function handleRegistration(array $customer): array
    {
        $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        $customerId = (int)($customer['customer_id'] ?? 0);
        $email = \trim((string)($customer['email'] ?? ''));
        if ($customerId <= 0 || $email === '') {
            $stats['skipped']++;

            return $stats;
        }

        /** @var LifecycleCampaign $model */
        $model = ObjectManager::getInstance(LifecycleCampaign::class);
        $model->clear()
            ->where(LifecycleCampaign::schema_fields_STATUS, LifecycleCampaign::STATUS_ENABLED)
            ->where(LifecycleCampaign::schema_fields_TYPE, LifecycleCampaign::TYPE_WELCOME_CUSTOMER)
            ->select()
            ->fetch();

        foreach ($model->getItems() as $row) {
            if (!$row instanceof LifecycleCampaign) {
                continue;
            }
            $campaignId = (int)$row->getId();
            $websiteId = (int)$row->getData(LifecycleCampaign::schema_fields_WEBSITE_ID);
            $custWebsite = (int)($customer['website_id'] ?? 0);
            if ($websiteId > 0 && $custWebsite > 0 && $websiteId !== $custWebsite) {
                continue;
            }
            if ($this->alreadySent($campaignId, $customerId)) {
                $stats['skipped']++;
                continue;
            }

            $couponCode = '';
            $ruleId = (int)$row->getData(LifecycleCampaign::schema_fields_INCENTIVE_RULE_ID);
            if ($ruleId > 0) {
                $issued = (new WinbackIncentiveIssuer())->issue($ruleId, [
                    'customer_email' => $email,
                    'customer_id' => $customerId,
                    'source_type' => 'welcome',
                ]);
                $couponCode = \is_array($issued) ? (string)($issued['coupon_code'] ?? '') : '';
            }

            $send = $this->sendWelcomeMail([
                'customer_id' => $customerId,
                'email' => $email,
                'customer_name' => (string)($customer['name'] ?? ''),
                'coupon_code' => $couponCode,
                'website_id' => $custWebsite,
                'locale' => (string)($customer['locale'] ?? ''),
            ]);
            $now = \gmdate('Y-m-d H:i:s');
            if (!empty($send['success'])) {
                $stats['sent']++;
                $this->writeLog($campaignId, $customerId, LifecycleSendLog::STATUS_SENT, $couponCode, '', $now);
            } elseif (!empty($send['skipped'])) {
                $stats['skipped']++;
                $this->writeLog($campaignId, $customerId, LifecycleSendLog::STATUS_SKIPPED, '', (string)($send['message'] ?? ''), $now);
            } else {
                $stats['failed']++;
                $this->writeLog($campaignId, $customerId, LifecycleSendLog::STATUS_FAILED, '', (string)($send['message'] ?? ''), $now);
            }
        }

        return $stats;
    }

    private function alreadySent(int $campaignId, int $customerId): bool
    {
        /** @var LifecycleSendLog $log */
        $log = ObjectManager::getInstance(LifecycleSendLog::class);
        $log->clear()
            ->where(LifecycleSendLog::schema_fields_CAMPAIGN_ID, $campaignId)
            ->where(LifecycleSendLog::schema_fields_CUSTOMER_ID, $customerId)
            ->where(LifecycleSendLog::schema_fields_STATUS, LifecycleSendLog::STATUS_SENT)
            ->find()->fetch();

        return (bool)$log->getId();
    }

    /** @param array<string,mixed> $vars */
    private function sendWelcomeMail(array $vars): array
    {
        if (!\function_exists('w_query')) {
            return ['success' => false, 'message' => 'smtp_unavailable', 'skipped' => true];
        }
        try {
            /** @var WinbackMailNotifier $notifier */
            $notifier = ObjectManager::getInstance(WinbackMailNotifier::class);
            $scope = $notifier->resolveScopeLocale((int)($vars['website_id'] ?? 0), (string)($vars['locale'] ?? ''));
            $result = w_query('smtp', 'send', [
                'module' => 'Weline_Marketing',
                'channel' => 'Weline_Marketing::welcome_customer',
                'to' => (string)$vars['email'],
                'vars' => [
                    'customer_name' => (string)($vars['customer_name'] ?? ''),
                    'customer_email' => (string)$vars['email'],
                    'coupon_code' => (string)($vars['coupon_code'] ?? ''),
                    'locale' => $scope['locale'],
                ],
                'website_code' => $scope['website_code'],
                'scope' => $scope['storage_scope'],
                'locale' => $scope['locale'],
            ]);
            if (\is_array($result) && !empty($result['success'])) {
                return ['success' => true, 'message' => ''];
            }

            return ['success' => false, 'message' => \is_array($result) ? (string)($result['message'] ?? 'send_failed') : 'send_failed'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function writeLog(int $campaignId, int $customerId, string $status, string $coupon, string $reason, string $sentAt): void
    {
        try {
            /** @var LifecycleSendLog $log */
            $log = ObjectManager::getInstance(LifecycleSendLog::class);
            $log->clear()->setData([
                LifecycleSendLog::schema_fields_CAMPAIGN_ID => $campaignId,
                LifecycleSendLog::schema_fields_CUSTOMER_ID => $customerId,
                LifecycleSendLog::schema_fields_STATUS => $status,
                LifecycleSendLog::schema_fields_COUPON_CODE => $coupon,
                LifecycleSendLog::schema_fields_REASON => $reason,
                LifecycleSendLog::schema_fields_SENT_AT => $sentAt,
            ])->save();
        } catch (\Throwable) {
        }
    }
}
