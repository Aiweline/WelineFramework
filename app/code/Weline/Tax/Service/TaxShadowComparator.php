<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

/**
 * Independent primary/current-source vs frozen-shadow Tax comparison.
 */
final class TaxShadowComparator
{
    public const MIN_OBSERVATION_QUOTES = 100;

    private const CLASSIFIED_CODES = [
        'observation_window_incomplete',
        'duplicate_request',
        'invalid_request',
        'primary_engine_error',
        'shadow_engine_error',
        'mixed_primary_scope',
        'mixed_primary_rule_set',
        'shadow_scope_mismatch',
        'shadow_rule_set_mismatch',
        'primary_duplicate_line',
        'shadow_duplicate_line',
        'primary_not_conserved',
        'shadow_not_conserved',
        'total_mismatch',
        'missing_shadow_line',
        'extra_shadow_line',
        'line_rounding_overflow',
        'line_amount_mismatch',
        'line_rule_mismatch',
    ];

    public function __construct(
        private readonly TaxEngine $primary,
        private readonly TaxEngine $shadow,
        private readonly ?TaxLkgStore $lkg = null,
    ) {
        if ($primary === $shadow) {
            throw new \InvalidArgumentException('Primary and shadow Tax engines must be independent instances');
        }
    }

    public static function forTesting(): self
    {
        // Separate instances are intentional; tests mutate shadow independently.
        $primary = TaxEngine::forTesting();
        $shadow = TaxEngine::fromSnapshot($primary->ruleSetSnapshot([
            'website_id' => 0,
            'store_id' => 0,
            'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
        ]));

        return new self($primary, $shadow, TaxLkgStore::forTesting());
    }

    public function primary(): TaxEngine
    {
        return $this->primary;
    }

    public function shadow(): TaxEngine
    {
        return $this->shadow;
    }

    public function lkg(): ?TaxLkgStore
    {
        return $this->lkg;
    }

    /**
     * @param list<array<string,mixed>> $requests
     * @return array<string,mixed>
     */
    public function observe(array $requests): array
    {
        $diffs = [];
        $maxDrift = 0.0;
        $conserved = true;
        $requestHashes = [];
        $seenRequests = [];
        $primarySnapshot = null;
        $scopeKey = null;
        $ruleSetHash = null;

        if (count($requests) < self::MIN_OBSERVATION_QUOTES) {
            $diffs[] = $this->diff('observation_window_incomplete', [
                'required' => self::MIN_OBSERVATION_QUOTES,
                'actual' => count($requests),
            ]);
        }

        foreach ($requests as $index => $request) {
            if (!is_array($request)) {
                $diffs[] = $this->diff('invalid_request', ['index' => $index]);
                $conserved = false;
                continue;
            }
            $requestHash = $this->hashPayload($request);
            $requestHashes[] = $requestHash;
            if (isset($seenRequests[$requestHash])) {
                $diffs[] = $this->diff('duplicate_request', [
                    'index' => $index,
                    'first_index' => $seenRequests[$requestHash],
                    'request_hash' => $requestHash,
                ]);
                continue;
            }
            $seenRequests[$requestHash] = $index;

            try {
                $primary = $this->primary->calculate($request);
            } catch (\Throwable $exception) {
                $diffs[] = $this->diff('primary_engine_error', [
                    'index' => $index,
                    'error' => $this->errorCode($exception),
                ]);
                $conserved = false;
                continue;
            }
            try {
                $shadow = $this->shadow->calculate($request);
            } catch (\Throwable $exception) {
                $diffs[] = $this->diff('shadow_engine_error', [
                    'index' => $index,
                    'error' => $this->errorCode($exception),
                ]);
                $conserved = false;
                continue;
            }

            $currentScope = (string) ($primary['scope_key'] ?? '');
            $currentHash = (string) ($primary['rule_set_hash'] ?? '');
            if ($primarySnapshot === null) {
                try {
                    $primarySnapshot = $this->primary->ruleSetSnapshot($request);
                } catch (\Throwable $exception) {
                    $diffs[] = $this->diff('primary_engine_error', [
                        'index' => $index,
                        'error' => $this->errorCode($exception),
                    ]);
                    $conserved = false;
                    continue;
                }
                $scopeKey = $currentScope;
                $ruleSetHash = $currentHash;
            } else {
                if ($currentScope !== $scopeKey) {
                    $diffs[] = $this->diff('mixed_primary_scope', [
                        'index' => $index,
                        'expected' => $scopeKey,
                        'actual' => $currentScope,
                    ]);
                }
                if ($currentHash !== $ruleSetHash) {
                    $diffs[] = $this->diff('mixed_primary_rule_set', [
                        'index' => $index,
                        'expected' => $ruleSetHash,
                        'actual' => $currentHash,
                    ]);
                }
            }

            if ((string) ($shadow['scope_key'] ?? '') !== $currentScope) {
                $diffs[] = $this->diff('shadow_scope_mismatch', [
                    'index' => $index,
                    'primary' => $currentScope,
                    'shadow' => $shadow['scope_key'] ?? null,
                ]);
            }
            if ((string) ($shadow['rule_set_hash'] ?? '') !== $currentHash) {
                $diffs[] = $this->diff('shadow_rule_set_mismatch', [
                    'index' => $index,
                    'primary' => $currentHash,
                    'shadow' => $shadow['rule_set_hash'] ?? null,
                ]);
            }

            [$primaryById, $primarySum, $primaryDuplicate] = $this->lineMap($primary['lines'] ?? []);
            [$shadowById, $shadowSum, $shadowDuplicate] = $this->lineMap($shadow['lines'] ?? []);
            foreach ($primaryDuplicate as $lineId) {
                $diffs[] = $this->diff('primary_duplicate_line', [
                    'index' => $index,
                    'line_id' => $lineId,
                ]);
            }
            foreach ($shadowDuplicate as $lineId) {
                $diffs[] = $this->diff('shadow_duplicate_line', [
                    'index' => $index,
                    'line_id' => $lineId,
                ]);
            }

            if ($primarySum !== (int) ($primary['tax_amount_minor'] ?? PHP_INT_MIN)) {
                $diffs[] = $this->diff('primary_not_conserved', [
                    'index' => $index,
                    'line_sum' => $primarySum,
                    'total' => $primary['tax_amount_minor'] ?? null,
                ]);
                $conserved = false;
            }
            if ($shadowSum !== (int) ($shadow['tax_amount_minor'] ?? PHP_INT_MIN)) {
                $diffs[] = $this->diff('shadow_not_conserved', [
                    'index' => $index,
                    'line_sum' => $shadowSum,
                    'total' => $shadow['tax_amount_minor'] ?? null,
                ]);
                $conserved = false;
            }
            if ((int) ($primary['tax_amount_minor'] ?? PHP_INT_MIN)
                !== (int) ($shadow['tax_amount_minor'] ?? PHP_INT_MAX)
            ) {
                $diffs[] = $this->diff('total_mismatch', [
                    'index' => $index,
                    'primary' => $primary['tax_amount_minor'] ?? null,
                    'shadow' => $shadow['tax_amount_minor'] ?? null,
                ]);
                $conserved = false;
            }

            foreach ($primaryById as $lineId => $line) {
                $other = $shadowById[$lineId] ?? null;
                if ($other === null) {
                    $diffs[] = $this->diff('missing_shadow_line', [
                        'index' => $index,
                        'line_id' => $lineId,
                    ]);
                    $conserved = false;
                    continue;
                }
                $drift = abs(
                    (int) ($line['tax_amount_minor'] ?? PHP_INT_MIN)
                    - (int) ($other['tax_amount_minor'] ?? PHP_INT_MAX),
                );
                $maxDrift = max($maxDrift, (float) $drift);
                if ($drift > 1) {
                    $diffs[] = $this->diff('line_rounding_overflow', [
                        'index' => $index,
                        'line_id' => $lineId,
                        'drift' => $drift,
                    ]);
                }
                if ($drift !== 0) {
                    $diffs[] = $this->diff('line_amount_mismatch', [
                        'index' => $index,
                        'line_id' => $lineId,
                        'drift' => $drift,
                    ]);
                }
                if ((int) ($line['rate_bps'] ?? -1) !== (int) ($other['rate_bps'] ?? -2)
                    || (int) ($line['rule_version'] ?? -1) !== (int) ($other['rule_version'] ?? -2)
                ) {
                    $diffs[] = $this->diff('line_rule_mismatch', [
                        'index' => $index,
                        'line_id' => $lineId,
                    ]);
                }
            }
            foreach ($shadowById as $lineId => $line) {
                if (!isset($primaryById[$lineId])) {
                    $diffs[] = $this->diff('extra_shadow_line', [
                        'index' => $index,
                        'line_id' => $lineId,
                    ]);
                    $conserved = false;
                }
            }
        }

        $classifiedCount = 0;
        $unclassifiedCount = 0;
        foreach ($diffs as $diff) {
            if (in_array((string) ($diff['code'] ?? ''), self::CLASSIFIED_CODES, true)) {
                $classifiedCount++;
            } else {
                $unclassifiedCount++;
            }
        }
        $requestSetHash = $this->hashPayload($requestHashes);
        $reportCore = [
            'quote_count' => count($requests),
            'unique_quote_count' => count($seenRequests),
            'classified_diff_count' => $classifiedCount,
            'unclassified_diff_count' => $unclassifiedCount,
            'diffs' => $diffs,
            'max_line_rounding_drift' => $maxDrift,
            'conserved' => $conserved,
            'scope_key' => $scopeKey,
            'rule_set_hash' => $ruleSetHash,
            'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
            'request_set_hash' => $requestSetHash,
        ];
        $reportHash = $this->hashPayload($reportCore);
        $ok = $diffs === []
            && count($seenRequests) >= self::MIN_OBSERVATION_QUOTES
            && $primarySnapshot !== null
            && $scopeKey !== null
            && $ruleSetHash !== null;
        $lkgId = null;
        if ($ok && $this->lkg !== null) {
            $lkgId = $this->lkg->saveVerified(
                $primarySnapshot,
                $requestSetHash,
                $reportHash,
                count($seenRequests),
            );
        }

        return array_merge($reportCore, [
            'ok' => $ok,
            'conserved' => $conserved && $ok,
            'report_hash' => $reportHash,
            'lkg_id' => $lkgId,
        ]);
    }

    /**
     * @param mixed $lines
     * @return array{0:array<string,array<string,mixed>>,1:int,2:list<string>}
     */
    private function lineMap(mixed $lines): array
    {
        if (!is_array($lines)) {
            return [[], 0, ['__invalid_lines__']];
        }
        $map = [];
        $duplicates = [];
        $sum = 0;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                $duplicates[] = '__invalid_line__';
                continue;
            }
            $lineId = (string) ($line['line_id'] ?? '');
            if ($lineId === '' || isset($map[$lineId])) {
                $duplicates[] = $lineId === '' ? '__empty_line_id__' : $lineId;
                continue;
            }
            $map[$lineId] = $line;
            $sum += (int) ($line['tax_amount_minor'] ?? 0);
        }

        return [$map, $sum, $duplicates];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function diff(string $code, array $context = []): array
    {
        return array_merge(['code' => $code, 'classification' => 'known'], $context);
    }

    private function errorCode(\Throwable $exception): string
    {
        return $exception instanceof \Weline\Tax\Api\TaxConflictException
            ? $exception->errorCode()
            : $exception::class;
    }

    private function hashPayload(mixed $payload): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->canonicalize($payload),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
