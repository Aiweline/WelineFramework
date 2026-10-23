<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

/**
 * Binds storefront warmup progress to the storefront generation fingerprint
 * (live website/theme namespace generations), not the global @clock.
 *
 * @clock still ticks on leftover/e2e namespace bumps; those must not restart
 * a finished or in-flight storefront warmup. First-deploy / clock-only ledgers
 * with existing progress bind the live fingerprint without rewarming.
 */
final class StorefrontWarmupGenerationFence
{
    /**
     * @param array<string, mixed> $state
     * @return array{state:array<string,mixed>,rewarm:bool,dirty:bool}
     */
    public function align(array $state, string $fingerprint, int $currentClock = 0): array
    {
        if ($currentClock < 0) {
            throw new \InvalidArgumentException('Storefront warmup generation clock cannot be negative.');
        }

        $recordedFingerprint = \trim((string)($state['storefront_generation_fingerprint'] ?? ''));
        $fingerprint = \trim($fingerprint);
        $recordedClock = \max(0, (int)($state['generation_authority_clock'] ?? 0));

        if ($fingerprint === '') {
            $dirty = false;
            if ($currentClock > 0 && $recordedClock !== $currentClock) {
                $state['generation_authority_clock'] = $currentClock;
                $dirty = true;
            }

            return ['state' => $state, 'rewarm' => false, 'dirty' => $dirty];
        }

        if ($recordedFingerprint === $fingerprint) {
            $dirty = false;
            if ($recordedClock !== $currentClock) {
                $state['generation_authority_clock'] = $currentClock;
                $dirty = true;
            }
            if (($state['phase'] ?? '') === StorefrontWarmupProgressLedger::PHASE_DONE
                && !empty($state['needs_rewarm'])
            ) {
                $state['needs_rewarm'] = false;
                $dirty = true;
            }

            return ['state' => $state, 'rewarm' => false, 'dirty' => $dirty];
        }

        if ($recordedFingerprint === '' && $this->hasProgress($state)) {
            $state['storefront_generation_fingerprint'] = $fingerprint;
            $state['generation_authority_clock'] = $currentClock;
            $state['needs_rewarm'] = false;

            return ['state' => $state, 'rewarm' => false, 'dirty' => true];
        }

        if ($recordedFingerprint === '' && !$this->hasProgress($state)) {
            $state['storefront_generation_fingerprint'] = $fingerprint;
            $state['generation_authority_clock'] = $currentClock;
            $state['needs_rewarm'] = false;

            return ['state' => $state, 'rewarm' => false, 'dirty' => true];
        }

        return [
            'state' => [
                'version' => \max(1, (int)($state['version'] ?? 1)),
                'phase' => StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE,
                'sitemap_doc_cursor' => 0,
                'extras_cursor' => 0,
                'loc_cursor' => 0,
                'priority_homes' => [],
                'priority_home_cursor' => 0,
                'last_url' => '',
                'last_status' => 'needs_rewarm',
                'last_reason' => 'generation_changed',
                'ok' => 0,
                'skip' => 0,
                'fail' => 0,
                'backoff_until' => 0.0,
                'last_yield_at' => 0.0,
                'sitemap_docs' => [],
                'extras' => [],
                'locs' => [],
                'updated_at' => (string)($state['updated_at'] ?? ''),
                'generation_authority_clock' => $currentClock,
                'storefront_generation_fingerprint' => $fingerprint,
                'needs_rewarm' => true,
            ],
            'rewarm' => true,
            'dirty' => true,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function hasProgress(array $state): bool
    {
        if ((string)($state['phase'] ?? StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE)
            !== StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE
        ) {
            return true;
        }
        foreach (['priority_home_cursor', 'sitemap_doc_cursor', 'extras_cursor', 'loc_cursor', 'ok', 'skip', 'fail'] as $key) {
            if ((int)($state[$key] ?? 0) > 0) {
                return true;
            }
        }
        foreach (['sitemap_docs', 'extras', 'locs'] as $key) {
            if (\is_array($state[$key] ?? null) && $state[$key] !== []) {
                return true;
            }
        }

        return false;
    }
}
