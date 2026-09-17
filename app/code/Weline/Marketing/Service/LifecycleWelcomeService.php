<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Lifecycle\LifecycleCampaign;
use Weline\Marketing\Model\Lifecycle\LifecycleSendLog;
use Weline\Websites\Model\Website;

/**
 * 新客欢迎：发信 + 可选激励券；按 campaign+customer 去重。
 */
final class LifecycleWelcomeService
{
    /** @var callable():list<array<string,mixed>> */
    private $loadCampaigns;

    /** @var callable(int,int):bool */
    private $hasSent;

    /** @var callable(array):array */
    private $sendMail;

    /** @var callable(int,array):array */
    private $issueCoupon;

    /** @var callable(array):void */
    private $writeLog;

    /** @var callable(int,int):bool */
    private $matchesSegment;

    /**
     * @param callable():list<array<string,mixed>>|null $loadCampaigns
     * @param callable(int,int):bool|null $hasSent
     * @param callable(array):array|null $sendMail
     * @param callable(int,array):array|null $issueCoupon
     * @param callable(array):void|null $writeLog
     * @param callable(int,int):bool|null $matchesSegment
     */
    public function __construct(
        ?callable $loadCampaigns = null,
        ?callable $hasSent = null,
        ?callable $sendMail = null,
        ?callable $issueCoupon = null,
        ?callable $writeLog = null,
        ?callable $matchesSegment = null,
    ) {
        $this->loadCampaigns = $loadCampaigns ?? [$this, 'defaultLoadCampaigns'];
        $this->hasSent = $hasSent ?? [$this, 'defaultHasSent'];
        $this->sendMail = $sendMail ?? [$this, 'defaultSendMail'];
        $this->issueCoupon = $issueCoupon ?? [$this, 'defaultIssueCoupon'];
        $this->writeLog = $writeLog ?? [$this, 'defaultWriteLog'];
        $this->matchesSegment = $matchesSegment ?? [$this, 'defaultMatchesSegment'];
    }

    /**
     * @param array{customer_id:int,email?:string,customer_name?:string,website_id?:int,locale?:string} $customer
     * @return array{processed:int,sent:int,skipped:int,failed:int}
     */
    public function handleRegistered(array $customer): array
    {
        $stats = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        $customerId = (int)($customer['customer_id'] ?? 0);
        $email = \trim((string)($customer['email'] ?? $customer['customer_email'] ?? ''));
        if ($customerId <= 0 || $email === '') {
            return $stats;
        }
        $websiteId = (int)($customer['website_id'] ?? 0);

        foreach (($this->loadCampaigns)() as $campaign) {
            if (!\is_array($campaign)) {
                continue;
            }
            $campaignId = (int)($campaign['id'] ?? 0);
            if ($campaignId <= 0) {
                continue;
            }
            $campWebsite = (int)($campaign['website_id'] ?? 0);
            if ($campWebsite > 0 && $websiteId > 0 && $campWebsite !== $websiteId) {
                continue;
            }
            $stats['processed']++;
            if (($this->hasSent)($campaignId, $customerId)) {
                $stats['skipped']++;
                continue;
            }
            $segmentId = (int)($campaign['segment_id'] ?? 0);
            if ($segmentId > 0 && !($this->matchesSegment)($customerId, $segmentId)) {
                $stats['skipped']++;
                ($this->writeLog)([
                    'campaign_id' => $campaignId,
                    'customer_id' => $customerId,
                    'status' => LifecycleSendLog::STATUS_SKIPPED,
                    'coupon_code' => '',
                    'sent_at' => \gmdate('Y-m-d H:i:s'),
                ]);
                continue;
            }

            $couponCode = '';
            $ruleId = (int)($campaign['incentive_rule_id'] ?? 0);
            if ($ruleId > 0) {
                $issued = ($this->issueCoupon)($ruleId, [
                    'customer_id' => $customerId,
                    'customer_email' => $email,
                    'source_type' => 'lifecycle_welcome',
                ]);
                $couponCode = \trim((string)($issued['coupon_code'] ?? ''));
            }

            $payload = [
                'customer_id' => $customerId,
                'email' => $email,
                'customer_email' => $email,
                'customer_name' => (string)($customer['customer_name'] ?? $customer['name'] ?? ''),
                'website_id' => $websiteId,
                'locale' => (string)($customer['locale'] ?? ''),
                'coupon_code' => $couponCode,
            ];
            $mail = ($this->sendMail)($payload);
            if (!empty($mail['success'])) {
                $stats['sent']++;
                ($this->writeLog)([
                    'campaign_id' => $campaignId,
                    'customer_id' => $customerId,
                    'status' => LifecycleSendLog::STATUS_SENT,
                    'coupon_code' => $couponCode,
                    'sent_at' => \gmdate('Y-m-d H:i:s'),
                ]);
                continue;
            }
            $stats['failed']++;
            ($this->writeLog)([
                'campaign_id' => $campaignId,
                'customer_id' => $customerId,
                'status' => LifecycleSendLog::STATUS_FAILED,
                'coupon_code' => $couponCode,
                'sent_at' => \gmdate('Y-m-d H:i:s'),
            ]);
        }

        return $stats;
    }

    /** @return list<array<string, mixed>> */
    private function defaultLoadCampaigns(): array
    {
        /** @var LifecycleCampaign $model */
        $model = ObjectManager::getInstance(LifecycleCampaign::class);
        $model->clear()
            ->where(LifecycleCampaign::schema_fields_STATUS, LifecycleCampaign::STATUS_ENABLED)
            ->where(LifecycleCampaign::schema_fields_TYPE, LifecycleCampaign::TYPE_WELCOME_CUSTOMER)
            ->select()
            ->fetch();
        $out = [];
        foreach ($model->getItems() as $row) {
            if ($row instanceof LifecycleCampaign) {
                $out[] = $row->getData();
            }
        }

        return $out;
    }

    private function defaultHasSent(int $campaignId, int $customerId): bool
    {
        /** @var LifecycleSendLog $model */
        $model = ObjectManager::getInstance(LifecycleSendLog::class);
        $model->clear()
            ->where(LifecycleSendLog::schema_fields_CAMPAIGN_ID, $campaignId)
            ->where(LifecycleSendLog::schema_fields_CUSTOMER_ID, $customerId)
            ->where(LifecycleSendLog::schema_fields_STATUS, LifecycleSendLog::STATUS_SENT)
            ->find()
            ->fetch();

        return (bool)$model->getId();
    }

    /** @param array<string, mixed> $dto */
    private function defaultSendMail(array $dto): array
    {
        $email = \trim((string)($dto['email'] ?? ''));
        if ($email === '' || !\function_exists('w_query')) {
            return ['success' => false, 'message' => 'no_email_or_smtp', 'skipped' => true];
        }
        $websiteId = (int)($dto['website_id'] ?? 0);
        $websiteCode = '';
        $locale = \trim((string)($dto['locale'] ?? ''));
        if ($websiteId > 0) {
            try {
                /** @var Website $website */
                $website = ObjectManager::getInstance(Website::class);
                $website->load($websiteId);
                $websiteCode = (string)$website->getData('code');
                if ($locale === '') {
                    $locale = (string)$website->getData('default_locale');
                }
            } catch (\Throwable) {
            }
        }
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }
        try {
            $result = w_query('smtp', 'send', [
                'module' => 'Weline_Marketing',
                'channel' => 'Weline_Marketing::welcome_customer',
                'to' => $email,
                'vars' => [
                    'customer_name' => (string)($dto['customer_name'] ?? ''),
                    'customer_email' => $email,
                    'email' => $email,
                    'coupon_code' => (string)($dto['coupon_code'] ?? ''),
                ],
                'website_code' => $websiteCode,
                'locale' => $locale,
            ]);

            return [
                'success' => \is_array($result) && !empty($result['success']),
                'message' => \is_array($result) ? (string)($result['message'] ?? '') : 'send_failed',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $context @return array{coupon_code:string,coupon_id:int} */
    private function defaultIssueCoupon(int $ruleId, array $context): array
    {
        return (new WinbackIncentiveIssuer())->issue($ruleId, $context);
    }

    /** @param array<string, mixed> $row */
    private function defaultWriteLog(array $row): void
    {
        /** @var LifecycleSendLog $model */
        $model = ObjectManager::getInstance(LifecycleSendLog::class);
        try {
            $model->clear()->setData([
                LifecycleSendLog::schema_fields_CAMPAIGN_ID => (int)$row['campaign_id'],
                LifecycleSendLog::schema_fields_CUSTOMER_ID => (int)$row['customer_id'],
                LifecycleSendLog::schema_fields_STATUS => (string)$row['status'],
                LifecycleSendLog::schema_fields_COUPON_CODE => (string)($row['coupon_code'] ?? ''),
                LifecycleSendLog::schema_fields_SENT_AT => (string)($row['sent_at'] ?? \gmdate('Y-m-d H:i:s')),
            ])->save();
        } catch (\Throwable $e) {
            if (\function_exists('w_log_error')) {
                w_log_error('LifecycleSendLog write failed: ' . $e->getMessage(), [], 'marketing_lifecycle');
            }
        }
    }

    private function defaultMatchesSegment(int $customerId, int $segmentId): bool
    {
        return ObjectManager::getInstance(AudienceSegmentMatcher::class)->matches($customerId, $segmentId);
    }
}
