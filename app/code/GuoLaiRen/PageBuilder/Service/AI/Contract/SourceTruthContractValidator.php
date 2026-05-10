<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class SourceTruthContractValidator
{
    /**
     * @param array<string, mixed> $contract
     * @return array{valid:bool, errors:list<string>}
     */
    public function validate(array $contract): array
    {
        $errors = [];

        if (($contract['contract_type'] ?? '') !== 'source_truth') {
            $errors[] = 'contract_type must be "source_truth"';
        }

        if (empty($contract['site_identity']['site_name'])) {
            $errors[] = 'site_identity.site_name is required';
        }

        $facts = \is_array($contract['must_include_facts'] ?? null) ? $contract['must_include_facts'] : [];
        if ($facts === []) {
            $errors[] = 'must_include_facts must not be empty';
        }
        foreach ($facts as $i => $fact) {
            if (!\is_array($fact)) {
                $errors[] = "must_include_facts[{$i}] must be an object";
                continue;
            }
            if (empty($fact['id'])) {
                $errors[] = "must_include_facts[{$i}].id is required";
            }
            if (empty($fact['text'])) {
                $errors[] = "must_include_facts[{$i}].text is required";
            }
            $weight = (int)($fact['weight'] ?? 0);
            if ($weight < 1 || $weight > 10) {
                $errors[] = "must_include_facts[{$i}].weight must be 1-10, got {$weight}";
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }
}
