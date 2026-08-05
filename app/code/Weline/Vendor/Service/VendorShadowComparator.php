<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

/**
 * Stateless Vendor shadow comparison over checkpoint-scoped durable facts.
 */
final class VendorShadowComparator
{
    /**
     * @param list<array<string,mixed>> $snapshots
     * @param list<array<string,mixed>> $payouts
     * @param list<array<string,mixed>> $reversals
     * @return array<string,mixed>
     */
    public function compare(
        array $snapshots,
        array $payouts,
        array $reversals,
        bool $forceMismatch = false,
    ): array {
        $diffs = [];
        $snapshotById = [];
        $conservedSnapshots = 0;
        foreach ($snapshots as $index => $snapshot) {
            $snapshotId = trim((string) ($snapshot['snapshot_id'] ?? ''));
            $gross = (int) ($snapshot['gross_minor'] ?? 0);
            $vendorShare = (int) ($snapshot['vendor_share_minor'] ?? -1);
            $platformShare = (int) ($snapshot['platform_share_minor'] ?? -1);
            if ($snapshotId === ''
                || isset($snapshotById[$snapshotId])
                || $gross <= 0
                || $vendorShare < 0
                || $platformShare < 0
                || $vendorShare + $platformShare !== $gross
            ) {
                $diffs[] = [
                    'code' => 'split_not_conserved',
                    'index' => $index,
                    'snapshot_id' => $snapshotId,
                ];
                continue;
            }
            $snapshotById[$snapshotId] = $snapshot;
            $conservedSnapshots++;
        }

        $payoutById = [];
        $reversalSums = [];
        $grossPayout = 0;
        $netPayout = 0;
        foreach ($payouts as $index => $payout) {
            $payoutId = trim((string) ($payout['payout_id'] ?? ''));
            $snapshotId = trim((string) ($payout['snapshot_id'] ?? ''));
            $snapshot = $snapshotById[$snapshotId] ?? null;
            $amount = (int) ($payout['amount_minor'] ?? -1);
            $reversed = (int) ($payout['reversed_minor'] ?? -1);
            $net = (int) ($payout['net_minor'] ?? -1);
            if ($payoutId === ''
                || isset($payoutById[$payoutId])
                || $snapshot === null
                || $amount !== (int) ($snapshot['vendor_share_minor'] ?? -2)
                || $reversed < 0
                || $net < 0
                || $amount - $reversed !== $net
                || (string) ($payout['vendor_id'] ?? '') !== (string) ($snapshot['vendor_id'] ?? '')
                || (int) ($payout['store_id'] ?? 0) !== (int) ($snapshot['store_id'] ?? -1)
                || (string) ($payout['environment'] ?? '') !== (string) ($snapshot['environment'] ?? '')
            ) {
                $diffs[] = [
                    'code' => 'payout_snapshot_mismatch',
                    'index' => $index,
                    'payout_id' => $payoutId,
                    'snapshot_id' => $snapshotId,
                ];
                continue;
            }
            $payoutById[$payoutId] = $payout;
            $reversalSums[$payoutId] = 0;
            $grossPayout += $amount;
            $netPayout += $net;
        }

        foreach ($reversals as $index => $reversal) {
            $payoutId = trim((string) ($reversal['payout_id'] ?? ''));
            $payout = $payoutById[$payoutId] ?? null;
            $amount = (int) ($reversal['amount_minor'] ?? 0);
            if ($payout === null
                || $amount <= 0
                || (string) ($reversal['snapshot_id'] ?? '') !== (string) ($payout['snapshot_id'] ?? '')
                || (string) ($reversal['vendor_id'] ?? '') !== (string) ($payout['vendor_id'] ?? '')
                || (int) ($reversal['store_id'] ?? 0) !== (int) ($payout['store_id'] ?? -1)
                || (string) ($reversal['environment'] ?? '') !== (string) ($payout['environment'] ?? '')
            ) {
                $diffs[] = [
                    'code' => 'reversal_payout_mismatch',
                    'index' => $index,
                    'payout_id' => $payoutId,
                ];
                continue;
            }
            $reversalSums[$payoutId] += $amount;
        }

        foreach ($payoutById as $payoutId => $payout) {
            if (($reversalSums[$payoutId] ?? 0) !== (int) $payout['reversed_minor']) {
                $diffs[] = [
                    'code' => 'reversal_sum_mismatch',
                    'payout_id' => $payoutId,
                    'journal_minor' => $reversalSums[$payoutId] ?? 0,
                    'ledger_minor' => (int) $payout['reversed_minor'],
                ];
            }
        }

        if ($forceMismatch) {
            $diffs[] = ['code' => 'forced_shadow_mismatch'];
        }

        $reversedTotal = array_sum($reversalSums);
        if ($grossPayout - $reversedTotal !== $netPayout) {
            $diffs[] = [
                'code' => 'reconcile_not_conserved',
                'gross_payout_minor' => $grossPayout,
                'reversed_minor' => $reversedTotal,
                'net_minor' => $netPayout,
            ];
        }

        $summary = [
            'snapshot_count' => count($snapshots),
            'conserved_snapshot_count' => $conservedSnapshots,
            'payout_count' => count($payouts),
            'reversal_count' => count($reversals),
            'gross_payout_minor' => $grossPayout,
            'reversed_minor' => $reversedTotal,
            'net_minor' => $netPayout,
            'diffs' => $diffs,
        ];

        return [
            'ok' => $diffs === [],
            'sample_count' => count($snapshots),
            'conserved_sample_count' => $conservedSnapshots,
            'unclassified_diff_count' => count($diffs),
            'diffs' => $diffs,
            'gross_payout_minor' => $grossPayout,
            'reversed_minor' => $reversedTotal,
            'net_minor' => $netPayout,
            'conserved' => $diffs === [],
            'report_hash' => hash(
                'sha256',
                json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ),
        ];
    }
}
