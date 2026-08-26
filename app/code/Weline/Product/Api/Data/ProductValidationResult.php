<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * Stable, UI-ready Provider diagnostics. Errors block the whole selected Store publish set.
 */
final readonly class ProductValidationResult
{
    /**
     * @param list<array{code:string,message:string,path?:string,store_id?:int,offer_uuid?:string}> $errors
     * @param list<array{code:string,message:string,path?:string,store_id?:int,offer_uuid?:string}> $warnings
     */
    public function __construct(
        public array $errors = [],
        public array $warnings = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function merge(self $other): self
    {
        return new self(
            errors: array_values(array_merge($this->errors, $other->errors)),
            warnings: array_values(array_merge($this->warnings, $other->warnings)),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(?ProductValidationContext $context = null): array
    {
        $errors = $this->normalizeIssues($this->errors, 'error', $context);
        $warnings = $this->normalizeIssues($this->warnings, 'warning', $context);
        $groups = $this->groupIssues($errors, $warnings, $context);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => [
                'error_count' => count($errors),
                'warning_count' => count($warnings),
                'group_count' => count($groups),
            ],
            'groups' => $groups,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function normalizeIssues(
        array $issues,
        string $severity,
        ?ProductValidationContext $context,
    ): array {
        $normalized = [];
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $row = $issue;
            $row['code'] = trim((string)($issue['code'] ?? 'product_validation_issue'));
            $row['message'] = (string)($issue['message'] ?? '');
            $row['severity'] = $severity;
            $row['locale'] = trim((string)($issue['locale'] ?? ($context?->localeCode() ?? '')));
            $row['currency'] = strtoupper(trim((string)(
                $issue['currency'] ?? ($context?->currencyCode() ?? 'CNY')
            ))) ?: 'CNY';
            if (array_key_exists('store_id', $issue)) {
                $storeId = (int)$issue['store_id'];
                $row['store_id'] = $storeId;
                $row['store_label'] = trim((string)($issue['store_label'] ?? ''))
                    ?: ($context?->storeLabel($storeId) ?? ('Store #' . $storeId));
            }
            $offerUuid = trim((string)($issue['offer_uuid'] ?? ''));
            if ($offerUuid !== '') {
                $row['offer_uuid'] = $offerUuid;
                $row['offer_label'] = trim((string)($issue['offer_label'] ?? ''))
                    ?: ($context?->offerLabel($offerUuid) ?? $offerUuid);
            }
            $normalized[] = $row;
        }
        return $normalized;
    }

    /** @return list<array<string, mixed>> */
    private function groupIssues(
        array $errors,
        array $warnings,
        ?ProductValidationContext $context,
    ): array {
        $groups = [];
        foreach (['errors' => $errors, 'warnings' => $warnings] as $bucket => $issues) {
            foreach ($issues as $issue) {
                $storeIds = array_key_exists('store_id', $issue)
                    ? [(int)$issue['store_id']]
                    : array_values(array_map('intval', $context?->storeIds ?? []));
                if ($storeIds === []) {
                    $storeIds = [0];
                }
                $locale = trim((string)($issue['locale'] ?? ($context?->localeCode() ?? '')));
                $currency = strtoupper(trim((string)(
                    $issue['currency'] ?? ($context?->currencyCode() ?? 'CNY')
                ))) ?: 'CNY';
                $offerUuid = trim((string)($issue['offer_uuid'] ?? ''));
                foreach ($storeIds as $storeId) {
                    $key = 'store:' . $storeId
                        . '|locale:' . $locale
                        . '|currency:' . $currency
                        . '|offer:' . ($offerUuid !== '' ? $offerUuid : 'product');
                    if (!isset($groups[$key])) {
                        $groups[$key] = [
                            'key' => $key,
                            'store_id' => $storeId,
                            'store_label' => $context?->storeLabel($storeId) ?? ('Store #' . $storeId),
                            'locale' => $locale,
                            'currency' => $currency,
                            'offer_uuid' => $offerUuid,
                            'offer_label' => $offerUuid !== ''
                                ? ($context?->offerLabel($offerUuid) ?? $offerUuid)
                                : '',
                            'valid' => true,
                            'severity' => 'ready',
                            'errors' => [],
                            'warnings' => [],
                        ];
                    }
                    $scoped = $issue;
                    $scoped['store_id'] = $storeId;
                    $scoped['store_label'] = $groups[$key]['store_label'];
                    if ($offerUuid !== '') {
                        $scoped['offer_label'] = $groups[$key]['offer_label'];
                    }
                    $groups[$key][$bucket][] = $scoped;
                }
            }
        }

        $groups = array_values($groups);
        foreach ($groups as &$group) {
            $group['valid'] = $group['errors'] === [];
            $group['severity'] = $group['errors'] !== []
                ? 'error'
                : ($group['warnings'] !== [] ? 'warning' : 'ready');
        }
        unset($group);
        usort(
            $groups,
            static fn(array $left, array $right): int => [
                (int)$left['store_id'],
                (string)$left['offer_label'],
                (string)$left['key'],
            ] <=> [
                (int)$right['store_id'],
                (string)$right['offer_label'],
                (string)$right['key'],
            ],
        );
        return $groups;
    }
}
