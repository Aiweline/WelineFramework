<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Coupon\Coupon;
use Weline\Marketing\Model\Winback\WinbackCampaign;
use Weline\Marketing\Model\Winback\WinbackSendLog;

/**
 * 挽回运营看板：按活动汇总 sent / skipped(reason) / 已发券 / 券核销（若可关联）。
 */
final class WinbackDashboardAggregator
{
    /**
     * @return list<array<string,mixed>>
     */
    public function summarize(?int $campaignId = null): array
    {
        /** @var WinbackCampaign $campaignModel */
        $campaignModel = ObjectManager::getInstance(WinbackCampaign::class);
        $campaignModel->clear()->order(WinbackCampaign::schema_fields_ID, 'DESC')->select()->fetch();
        $campaigns = $campaignModel->getItems();
        $out = [];

        foreach ($campaigns as $campaign) {
            if (!$campaign instanceof WinbackCampaign) {
                continue;
            }
            $id = (int)$campaign->getId();
            if ($campaignId !== null && $campaignId > 0 && $id !== $campaignId) {
                continue;
            }
            $out[] = $this->summarizeCampaign($id, [
                'id' => $id,
                'name' => (string)$campaign->getData(WinbackCampaign::schema_fields_NAME),
                'type' => (string)$campaign->getData(WinbackCampaign::schema_fields_TYPE),
                'status' => (string)$campaign->getData(WinbackCampaign::schema_fields_STATUS),
            ]);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function summarizeCampaign(int $campaignId, array $meta = []): array
    {
        /** @var WinbackSendLog $log */
        $log = ObjectManager::getInstance(WinbackSendLog::class);
        $log->clear()
            ->where(WinbackSendLog::schema_fields_CAMPAIGN_ID, $campaignId)
            ->select()
            ->fetch();

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $reasons = [];
        $couponCodes = [];
        $subjectKinds = ['order' => 0, 'qt' => 0, 'cart' => 0, 'other' => 0];

        foreach ($log->getItems() as $row) {
            if (!$row instanceof WinbackSendLog) {
                continue;
            }
            $status = (string)$row->getData(WinbackSendLog::schema_fields_STATUS);
            if ($status === WinbackSendLog::STATUS_SENT) {
                $sent++;
            } elseif ($status === WinbackSendLog::STATUS_SKIPPED) {
                $skipped++;
                $reason = \trim((string)$row->getData(WinbackSendLog::schema_fields_REASON));
                if ($reason === '') {
                    $reason = 'unknown';
                }
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            } elseif ($status === WinbackSendLog::STATUS_FAILED) {
                $failed++;
            }

            $code = \trim((string)$row->getData(WinbackSendLog::schema_fields_COUPON_CODE));
            if ($code !== '') {
                $couponCodes[$code] = true;
            }

            $subject = \trim((string)$row->getData(WinbackSendLog::schema_fields_ORDER_UUID));
            if (\str_starts_with($subject, 'order:')) {
                $subjectKinds['order']++;
            } elseif (\str_starts_with($subject, 'qt:')) {
                $subjectKinds['qt']++;
            } elseif (\str_starts_with($subject, 'cart:')) {
                $subjectKinds['cart']++;
            } elseif ($subject !== '') {
                $subjectKinds['other']++;
            }
        }

        $issued = \count($couponCodes);
        $redeemed = $this->countRedeemedCoupons(\array_keys($couponCodes));

        return \array_merge($meta, [
            'campaign_id' => $campaignId,
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
            'skipped_by_reason' => $reasons,
            'coupons_issued' => $issued,
            'coupons_redeemed' => $redeemed,
            'subject_kinds' => $subjectKinds,
        ]);
    }

    /**
     * 纯数组聚合（UT / 无 DB）；前缀解析 order/qt/cart/raw。
     *
     * @param list<array<string,mixed>> $rows
     * @return array{
     *   total:int,
     *   by_status:array<string,int>,
     *   by_reason:array<string,int>,
     *   by_prefix:array<string,int>,
     *   coupon_sent:int,
     *   coupon_codes:array<string,int>
     * }
     */
    public function summarizeRows(array $rows): array
    {
        $byStatus = [];
        $byReason = [];
        $byPrefix = [];
        $couponCodes = [];
        $couponSent = 0;
        $total = 0;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $total++;
            $status = (string)($row['status'] ?? '');
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            if ($status === 'skipped') {
                $reason = \trim((string)($row['reason'] ?? '')) ?: '(empty)';
                $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            }
            $uuid = (string)($row['order_uuid'] ?? '');
            $prefix = 'raw';
            if (\str_starts_with($uuid, 'order:')) {
                $prefix = 'order';
            } elseif (\str_starts_with($uuid, 'qt:')) {
                $prefix = 'qt';
            } elseif (\str_starts_with($uuid, 'cart:')) {
                $prefix = 'cart';
            }
            $byPrefix[$prefix] = ($byPrefix[$prefix] ?? 0) + 1;
            $code = \trim((string)($row['coupon_code'] ?? ''));
            if ($code !== '') {
                $couponSent++;
                $couponCodes[$code] = ($couponCodes[$code] ?? 0) + 1;
            }
        }
        \arsort($byReason);
        \arsort($couponCodes);

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'by_reason' => $byReason,
            'by_prefix' => $byPrefix,
            'coupon_sent' => $couponSent,
            'coupon_codes' => $couponCodes,
        ];
    }

    /** @param list<string> $codes */
    private function countRedeemedCoupons(array $codes): int
    {
        if ($codes === []) {
            return 0;
        }
        $redeemed = 0;
        try {
            /** @var Coupon $coupon */
            $coupon = ObjectManager::getInstance(Coupon::class);
            foreach ($codes as $code) {
                $coupon->clear()
                    ->where(Coupon::schema_fields_CODE, $code)
                    ->find()
                    ->fetch();
                if (!(int)$coupon->getId()) {
                    continue;
                }
                $usage = (int)$coupon->getData(Coupon::schema_fields_USAGE_COUNT);
                $status = (string)$coupon->getData(Coupon::schema_fields_STATUS);
                if ($usage > 0 || $status === Coupon::STATUS_EXHAUSTED) {
                    $redeemed++;
                }
            }
        } catch (\Throwable) {
        }

        return $redeemed;
    }
}
