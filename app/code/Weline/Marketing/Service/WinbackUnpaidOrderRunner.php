<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Winback\WinbackCampaign;
use Weline\Marketing\Model\Winback\WinbackSendLog;

/**
 * 未付订单挽回：读启用活动 → 时间窗 → order_signals → 再验 → 发信 → 写日志。
 *
 * 依赖均可注入，便于纯 PHP 单测（假时钟 / 假信号 / 假发信）。
 */
final class WinbackUnpaidOrderRunner
{
    /** @var callable():int */
    private $nowTs;

    /** @var callable(array):array */
    private $listUnpaid;

    /** @var callable(string):?array */
    private $getUnpaid;

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

    /**
     * @param callable():int|null $nowTs
     * @param callable(array):array|null $listUnpaid
     * @param callable(string):?array|null $getUnpaid
     * @param callable(array):array|null $sendMail
     * @param callable():list<array<string,mixed>>|null $loadCampaigns
     * @param callable(int,string,int):bool|null $hasStepLog
     * @param callable(int,string,int):?string|null $lastSentAt
     * @param callable(array):void|null $writeLog
     */
    public function __construct(
        ?callable $nowTs = null,
        ?callable $listUnpaid = null,
        ?callable $getUnpaid = null,
        ?callable $sendMail = null,
        ?callable $loadCampaigns = null,
        ?callable $hasStepLog = null,
        ?callable $lastSentAt = null,
        ?callable $writeLog = null,
    ) {
        $this->nowTs = $nowTs ?? static fn (): int => \time();
        $this->listUnpaid = $listUnpaid ?? [$this, 'defaultListUnpaid'];
        $this->getUnpaid = $getUnpaid ?? [$this, 'defaultGetUnpaid'];
        $this->sendMail = $sendMail ?? [$this, 'defaultSendMail'];
        $this->loadCampaigns = $loadCampaigns ?? [$this, 'defaultLoadCampaigns'];
        $this->hasStepLog = $hasStepLog ?? [$this, 'defaultHasStepLog'];
        $this->lastSentAt = $lastSentAt ?? [$this, 'defaultLastSentAt'];
        $this->writeLog = $writeLog ?? [$this, 'defaultWriteLog'];
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

            $listed = ($this->listUnpaid)($params);
            $items = \is_array($listed['items'] ?? null) ? $listed['items'] : [];

            foreach ($items as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $stats['processed']++;
                $orderUuid = \trim((string)($item['order_uuid'] ?? ''));
                if ($orderUuid === '') {
                    $stats['skipped']++;
                    ($this->writeLog)([
                        'campaign_id' => $campaignId,
                        'order_uuid' => '',
                        'step' => 1,
                        'status' => WinbackSendLog::STATUS_SKIPPED,
                        'reason' => 'missing_order_uuid',
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

                $step = 1;
                if (($this->hasStepLog)($campaignId, $orderUuid, $step)) {
                    $stats['skipped']++;
                    continue;
                }

                if ($cooldownHours > 0) {
                    $last = ($this->lastSentAt)($campaignId, $orderUuid, $step);
                    $lastTs = $last !== null ? $this->parseUtcTs($last) : null;
                    if ($lastTs !== null && $now < ($lastTs + $cooldownHours * 3600)) {
                        $stats['skipped']++;
                        continue;
                    }
                }

                $fresh = ($this->getUnpaid)($orderUuid);
                if ($fresh === null) {
                    $stats['skipped']++;
                    ($this->writeLog)([
                        'campaign_id' => $campaignId,
                        'order_uuid' => $orderUuid,
                        'step' => $step,
                        'status' => WinbackSendLog::STATUS_SKIPPED,
                        'reason' => 'already_paid_or_ineligible',
                        'sent_at' => \gmdate('Y-m-d H:i:s', $now),
                    ]);
                    continue;
                }
                if (empty($fresh['reachable']) || \trim((string)($fresh['continue_pay_url'] ?? '')) === '') {
                    $stats['skipped']++;
                    ($this->writeLog)([
                        'campaign_id' => $campaignId,
                        'order_uuid' => $orderUuid,
                        'step' => $step,
                        'status' => WinbackSendLog::STATUS_SKIPPED,
                        'reason' => 'unreachable',
                        'sent_at' => \gmdate('Y-m-d H:i:s', $now),
                    ]);
                    continue;
                }

                $mailResult = ($this->sendMail)($fresh);
                if (!empty($mailResult['success'])) {
                    $stats['sent']++;
                    ($this->writeLog)([
                        'campaign_id' => $campaignId,
                        'order_uuid' => $orderUuid,
                        'step' => $step,
                        'status' => WinbackSendLog::STATUS_SENT,
                        'reason' => '',
                        'sent_at' => \gmdate('Y-m-d H:i:s', $now),
                    ]);
                    continue;
                }

                if (!empty($mailResult['skipped'])) {
                    $stats['skipped']++;
                    ($this->writeLog)([
                        'campaign_id' => $campaignId,
                        'order_uuid' => $orderUuid,
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
                    'order_uuid' => $orderUuid,
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
    private function defaultListUnpaid(array $params): array
    {
        if (!\function_exists('w_query')) {
            return ['items' => [], 'meta' => []];
        }
        $result = w_query('order_signals', 'list_unpaid_orders', $params);

        return \is_array($result) ? $result : ['items' => [], 'meta' => []];
    }

    private function defaultGetUnpaid(string $orderUuid): ?array
    {
        if (!\function_exists('w_query')) {
            return null;
        }
        $result = w_query('order_signals', 'get_unpaid_order', ['order_uuid' => $orderUuid]);
        $item = \is_array($result) ? ($result['item'] ?? null) : null;

        return \is_array($item) ? $item : null;
    }

    /** @param array<string, mixed> $orderDto */
    private function defaultSendMail(array $orderDto): array
    {
        /** @var WinbackMailNotifier $notifier */
        $notifier = ObjectManager::getInstance(WinbackMailNotifier::class);

        return $notifier->notifyUnpaidReminder($orderDto);
    }

    /** @return list<array<string, mixed>> */
    private function defaultLoadCampaigns(): array
    {
        /** @var WinbackCampaign $model */
        $model = ObjectManager::getInstance(WinbackCampaign::class);
        $model->clear()
            ->where(WinbackCampaign::schema_fields_STATUS, WinbackCampaign::STATUS_ENABLED)
            ->where(WinbackCampaign::schema_fields_TYPE, WinbackCampaign::TYPE_UNPAID_ORDER_REMINDER)
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

    private function defaultHasStepLog(int $campaignId, string $orderUuid, int $step): bool
    {
        /** @var WinbackSendLog $model */
        $model = ObjectManager::getInstance(WinbackSendLog::class);
        $model->clear()
            ->where(WinbackSendLog::schema_fields_CAMPAIGN_ID, $campaignId)
            ->where(WinbackSendLog::schema_fields_ORDER_UUID, $orderUuid)
            ->where(WinbackSendLog::schema_fields_STEP, $step)
            ->where(WinbackSendLog::schema_fields_STATUS, WinbackSendLog::STATUS_SENT)
            ->find()
            ->fetch();

        return (bool)$model->getId();
    }

    private function defaultLastSentAt(int $campaignId, string $orderUuid, int $step): ?string
    {
        /** @var WinbackSendLog $model */
        $model = ObjectManager::getInstance(WinbackSendLog::class);
        $model->clear()
            ->where(WinbackSendLog::schema_fields_CAMPAIGN_ID, $campaignId)
            ->where(WinbackSendLog::schema_fields_ORDER_UUID, $orderUuid)
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
}
