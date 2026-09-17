<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Winback\WinbackCampaign;
use Weline\Marketing\Model\Winback\WinbackSendLog;

/**
 * 结账遗弃挽回：读启用活动 → 时间窗 → checkout_signals → 再验 → 发信 → 写日志。
 *
 * 主体键为 quote_token，复用 SendLog.order_uuid 列存储。
 * 依赖均可注入，便于纯 PHP 单测（假时钟 / 假信号 / 假发信）。
 */
final class WinbackCheckoutAbandonRunner
{
    /** @var callable():int */
    private $nowTs;

    /** @var callable(array):array */
    private $listStaleQuotes;

    /** @var callable(string):?array */
    private $getStaleQuote;

    /** @var callable(array):array */
    private $sendMail;

    /** @var callable():list<array<string,mixed>> */
    private $loadCampaigns;

    /** @var callable(int,string,int):bool */
    private $hasStepLog;

    /** @var callable(int,string,int):?string */
    private $lastSentAt;

    /** @var callable(array):void */
    private $writeLog;

    /** @var callable(int,int,?int):bool */
    private $matchesSegment;

    /** @var callable(int,array):array */
    private $issueCoupon;

    /**
     * @param callable():int|null $nowTs
     * @param callable(array):array|null $listStaleQuotes
     * @param callable(string):?array|null $getStaleQuote
     * @param callable(array):array|null $sendMail
     * @param callable():list<array<string,mixed>>|null $loadCampaigns
     * @param callable(int,string,int):bool|null $hasStepLog
     * @param callable(int,string,int):?string|null $lastSentAt
     * @param callable(array):void|null $writeLog
     * @param callable(int,int,?int):bool|null $matchesSegment
     * @param callable(int,array):array|null $issueCoupon
     */
    public function __construct(
        ?callable $nowTs = null,
        ?callable $listStaleQuotes = null,
        ?callable $getStaleQuote = null,
        ?callable $sendMail = null,
        ?callable $loadCampaigns = null,
        ?callable $hasStepLog = null,
        ?callable $lastSentAt = null,
        ?callable $writeLog = null,
        ?callable $matchesSegment = null,
        ?callable $issueCoupon = null,
    ) {
        $this->nowTs = $nowTs ?? static fn (): int => \time();
        $this->listStaleQuotes = $listStaleQuotes ?? [$this, 'defaultListStaleQuotes'];
        $this->getStaleQuote = $getStaleQuote ?? [$this, 'defaultGetStaleQuote'];
        $this->sendMail = $sendMail ?? [$this, 'defaultSendMail'];
        $this->loadCampaigns = $loadCampaigns ?? [$this, 'defaultLoadCampaigns'];
        $this->hasStepLog = $hasStepLog ?? [$this, 'defaultHasStepLog'];
        $this->lastSentAt = $lastSentAt ?? [$this, 'defaultLastSentAt'];
        $this->writeLog = $writeLog ?? [$this, 'defaultWriteLog'];
        $this->matchesSegment = $matchesSegment ?? [$this, 'defaultMatchesSegment'];
        $this->issueCoupon = $issueCoupon ?? [$this, 'defaultIssueCoupon'];
    }

    /**
     * @return array{processed:int,sent:int,skipped:int,failed:int}
     */
    public function run(): array
    {
        $stats = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        $now = (int)($this->nowTs)();
        $campaigns = ($this->loadCampaigns)();

        foreach ($campaigns as $campaign) {
            $campaignId = (int)($campaign['id'] ?? 0);
            if ($campaignId <= 0) {
                continue;
            }
            $abandonHours = max(1, (int)($campaign['abandon_after_hours'] ?? 24));
            $maxSteps = max(1, (int)($campaign['max_steps'] ?? 1));
            $cooldownHours = max(0, (int)($campaign['cooldown_hours'] ?? 0));
            $websiteId = (int)($campaign['website_id'] ?? 0);
            $lookbackHours = max($abandonHours + 24, $abandonHours * ($maxSteps + 1));

            $params = [
                'lookback_hours' => $lookbackHours,
                'limit' => 200,
            ];
            if ($websiteId > 0) {
                $params['website_id'] = $websiteId;
            }

            $listed = ($this->listStaleQuotes)($params);
            $items = \is_array($listed['items'] ?? null) ? $listed['items'] : [];

            foreach ($items as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $stats['processed']++;
                $quoteToken = \trim((string)($item['quote_token'] ?? ''));
                if ($quoteToken === '') {
                    $stats['skipped']++;
                    ($this->writeLog)([
                        'campaign_id' => $campaignId,
                        'order_uuid' => '',
                        'step' => 1,
                        'status' => WinbackSendLog::STATUS_SKIPPED,
                        'reason' => 'missing_quote_token',
                        'sent_at' => \gmdate('Y-m-d H:i:s', $now),
                    ]);
                    continue;
                }

                $createdAt = \trim((string)($item['created_at'] ?? ''));
                $createdTs = $this->parseUtcTs($createdAt);
                if ($createdTs === null || $now < ($createdTs + $abandonHours * 3600)) {
                    $stats['skipped']++;
                    // Not yet abandon-eligible: do not pollute send log (re-evaluated next cron).
                    continue;
                }

                $subjectKey = \str_starts_with($quoteToken, 'qt:') ? $quoteToken : ('qt:' . $quoteToken);
                $segmentId = max(0, (int)($campaign['segment_id'] ?? 0));
                $customerIdForSeg = (int)($item['customer_id'] ?? 0);
                if ($segmentId > 0 && !($this->matchesSegment)($customerIdForSeg, $segmentId, $now)) {
                    $stats['skipped']++;
                    ($this->writeLog)([
                        'campaign_id' => $campaignId,
                        'order_uuid' => $subjectKey,
                        'step' => 1,
                        'status' => WinbackSendLog::STATUS_SKIPPED,
                        'reason' => 'segment_mismatch',
                        'sent_at' => \gmdate('Y-m-d H:i:s', $now),
                    ]);
                    continue;
                }

                $stepInterval = max(1, (int)($campaign['step_interval_hours'] ?? 24));
                $incentiveRuleId = max(0, (int)($campaign['incentive_rule_id'] ?? 0));
                $next = (new WinbackStepResolver())->resolveNext(
                    $campaignId,
                    $subjectKey,
                    $maxSteps,
                    $stepInterval,
                    $now,
                    $this->hasStepLog,
                    $this->lastSentAt,
                );
                if ($next === null || empty($next['ready'])) {
                    $stats['skipped']++;
                    continue;
                }
                $step = (int)$next['step'];

                if ($cooldownHours > 0) {
                    $last = ($this->lastSentAt)($campaignId, $subjectKey, $step);
                    $lastTs = $last !== null ? $this->parseUtcTs($last) : null;
                    if ($lastTs !== null && $now < ($lastTs + $cooldownHours * 3600)) {
                        $stats['skipped']++;
                        continue;
                    }
                }

                $fresh = ($this->getStaleQuote)($quoteToken);
                if ($fresh === null) {
                    $stats['skipped']++;
                    ($this->writeLog)([
                        'campaign_id' => $campaignId,
                        'order_uuid' => $subjectKey,
                        'step' => $step,
                        'status' => WinbackSendLog::STATUS_SKIPPED,
                        'reason' => 'already_converted_or_ineligible',
                        'sent_at' => \gmdate('Y-m-d H:i:s', $now),
                    ]);
                    continue;
                }
                if (empty($fresh['reachable']) || \trim((string)($fresh['continue_checkout_url'] ?? '')) === '') {
                    $stats['skipped']++;
                    ($this->writeLog)([
                        'campaign_id' => $campaignId,
                        'order_uuid' => $subjectKey,
                        'step' => $step,
                        'status' => WinbackSendLog::STATUS_SKIPPED,
                        'reason' => 'unreachable',
                        'sent_at' => \gmdate('Y-m-d H:i:s', $now),
                    ]);
                    continue;
                }

                $couponCode = '';
                if ($step >= 2 && $incentiveRuleId > 0) {
                    $issued = ($this->issueCoupon)($incentiveRuleId, [
                        'customer_email' => (string)($fresh['email'] ?? $fresh['customer_email'] ?? ''),
                        'quote_token' => $quoteToken,
                        'source_type' => 'winback_checkout',
                    ]);
                    $couponCode = \is_array($issued) ? (string)($issued['coupon_code'] ?? '') : '';
                    if ($couponCode !== '') {
                        $fresh['coupon_code'] = $couponCode;
                    }
                }

                $mailResult = ($this->sendMail)($fresh);
                if (!empty($mailResult['success'])) {
                    $stats['sent']++;
                    ($this->writeLog)([
                        'campaign_id' => $campaignId,
                        'order_uuid' => $subjectKey,
                        'step' => $step,
                        'status' => WinbackSendLog::STATUS_SENT,
                        'reason' => '',
                        'coupon_code' => $couponCode,
                        'sent_at' => \gmdate('Y-m-d H:i:s', $now),
                    ]);
                    continue;
                }

                if (!empty($mailResult['skipped'])) {
                    $stats['skipped']++;
                    ($this->writeLog)([
                        'campaign_id' => $campaignId,
                        'order_uuid' => $subjectKey,
                        'step' => $step,
                        'status' => WinbackSendLog::STATUS_SKIPPED,
                        'reason' => (string)($mailResult['message'] ?? 'mail_skipped'),
                        'sent_at' => \gmdate('Y-m-d H:i:s', $now),
                    ]);
                    continue;
                }

                $stats['failed']++;
                ($this->writeLog)([
                    'campaign_id' => $campaignId,
                    'order_uuid' => $subjectKey,
                    'step' => $step,
                    'status' => WinbackSendLog::STATUS_FAILED,
                    'reason' => (string)($mailResult['message'] ?? 'send_failed'),
                    'sent_at' => \gmdate('Y-m-d H:i:s', $now),
                ]);
            }
        }

        return $stats;
    }

    /** @param array<string, mixed> $params */
    private function defaultListStaleQuotes(array $params): array
    {
        if (!\function_exists('w_query')) {
            return ['items' => [], 'meta' => []];
        }
        $result = w_query('checkout_signals', 'list_stale_quotes', $params);

        return \is_array($result) ? $result : ['items' => [], 'meta' => []];
    }

    private function defaultGetStaleQuote(string $quoteToken): ?array
    {
        if (!\function_exists('w_query')) {
            return null;
        }
        $result = w_query('checkout_signals', 'get_stale_quote', ['quote_token' => $quoteToken]);
        $item = \is_array($result) ? ($result['item'] ?? null) : null;

        return \is_array($item) ? $item : null;
    }

    /** @param array<string, mixed> $quoteDto */
    private function defaultSendMail(array $quoteDto): array
    {
        /** @var WinbackMailNotifier $notifier */
        $notifier = ObjectManager::getInstance(WinbackMailNotifier::class);

        return $notifier->notifyCheckoutAbandon($quoteDto);
    }

    /** @return list<array<string, mixed>> */
    private function defaultLoadCampaigns(): array
    {
        /** @var WinbackCampaign $model */
        $model = ObjectManager::getInstance(WinbackCampaign::class);
        $model->clear()
            ->where(WinbackCampaign::schema_fields_STATUS, WinbackCampaign::STATUS_ENABLED)
            ->where(WinbackCampaign::schema_fields_TYPE, WinbackCampaign::TYPE_CHECKOUT_ABANDON_REMINDER)
            ->select()
            ->fetch();
        $out = [];
        foreach ($model->getItems() as $row) {
            if ($row instanceof WinbackCampaign) {
                $out[] = $row->getData();
            }
        }

        return $out;
    }

    private function defaultHasStepLog(int $campaignId, string $quoteToken, int $step): bool
    {
        /** @var WinbackSendLog $model */
        $model = ObjectManager::getInstance(WinbackSendLog::class);
        $model->clear()
            ->where(WinbackSendLog::schema_fields_CAMPAIGN_ID, $campaignId)
            ->where(WinbackSendLog::schema_fields_ORDER_UUID, $quoteToken)
            ->where(WinbackSendLog::schema_fields_STEP, $step)
            ->where(WinbackSendLog::schema_fields_STATUS, WinbackSendLog::STATUS_SENT)
            ->find()
            ->fetch();

        return (bool)$model->getId();
    }

    private function defaultLastSentAt(int $campaignId, string $quoteToken, int $step): ?string
    {
        /** @var WinbackSendLog $model */
        $model = ObjectManager::getInstance(WinbackSendLog::class);
        $model->clear()
            ->where(WinbackSendLog::schema_fields_CAMPAIGN_ID, $campaignId)
            ->where(WinbackSendLog::schema_fields_ORDER_UUID, $quoteToken)
            ->where(WinbackSendLog::schema_fields_STEP, $step)
            ->order(WinbackSendLog::schema_fields_SENT_AT, 'DESC')
            ->find()
            ->fetch();
        if (!$model->getId()) {
            return null;
        }
        $at = \trim((string)$model->getData(WinbackSendLog::schema_fields_SENT_AT));

        return $at !== '' ? $at : null;
    }

    /** @param array<string, mixed> $row */
    private function defaultWriteLog(array $row): void
    {
        if (\trim((string)($row['order_uuid'] ?? '')) === '') {
            return;
        }
        /** @var WinbackSendLog $model */
        $model = ObjectManager::getInstance(WinbackSendLog::class);
        try {
            $model->clear()->setData([
                WinbackSendLog::schema_fields_CAMPAIGN_ID => (int)$row['campaign_id'],
                WinbackSendLog::schema_fields_ORDER_UUID => (string)$row['order_uuid'],
                WinbackSendLog::schema_fields_STEP => (int)$row['step'],
                WinbackSendLog::schema_fields_STATUS => (string)$row['status'],
                WinbackSendLog::schema_fields_REASON => (string)($row['reason'] ?? ''),
                WinbackSendLog::schema_fields_COUPON_CODE => (string)($row['coupon_code'] ?? ''),
                WinbackSendLog::schema_fields_SENT_AT => (string)($row['sent_at'] ?? \gmdate('Y-m-d H:i:s')),
            ])->save();
        } catch (\Throwable $e) {
            if (\function_exists('w_log_error')) {
                w_log_error('WinbackSendLog write failed: ' . $e->getMessage(), [], 'marketing_winback');
            }
        }
    }

    private function parseUtcTs(string $value): ?int
    {
        $value = \trim($value);
        if ($value === '') {
            return null;
        }
        $ts = \strtotime($value . ' UTC');
        if ($ts === false) {
            $ts = \strtotime($value);
        }

        return $ts === false ? null : $ts;
    }

    private function defaultMatchesSegment(int $customerId, int $segmentId, ?int $nowTs = null): bool
    {
        try {
            return (new AudienceSegmentMatcher())->matches($customerId, $segmentId, $nowTs);
        } catch (\Throwable) {
            return $segmentId <= 0;
        }
    }

    /** @param array<string,mixed> $context */
    private function defaultIssueCoupon(int $ruleId, array $context = []): array
    {
        try {
            return (new WinbackIncentiveIssuer())->issue($ruleId, $context);
        } catch (\Throwable) {
            return [];
        }
    }
}
