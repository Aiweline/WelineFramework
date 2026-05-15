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
        if (($contract['version'] ?? '') !== ContractType::VERSION_V1) {
            $errors[] = 'version must be "' . ContractType::VERSION_V1 . '"';
        }

        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        if ($meta === []) {
            $errors[] = 'contract_meta must be an object';
        } else {
            foreach (['id', 'version', 'type', 'stage', 'status', 'creator', 'adapter_type', 'created_at'] as $field) {
                if (\trim((string)($meta[$field] ?? '')) === '') {
                    $errors[] = 'contract_meta.' . $field . ' is required';
                }
            }
            if ((string)($meta['type'] ?? '') !== ContractType::TYPE_SOURCE_TRUTH) {
                $errors[] = 'contract_meta.type must be "' . ContractType::TYPE_SOURCE_TRUTH . '"';
            }
            if ((string)($meta['stage'] ?? '') !== ContractType::STAGE_STAGE1) {
                $errors[] = 'contract_meta.stage must be "' . ContractType::STAGE_STAGE1 . '"';
            }
        }

        if (!\is_array($contract['permission_matrix'] ?? null)) {
            $errors[] = 'permission_matrix must be an object';
        }
        if (!\is_array($contract['frozen_fields'] ?? null)) {
            $errors[] = 'frozen_fields must be an array';
        }
        if (!\is_array($contract['mutable_fields'] ?? null)) {
            $errors[] = 'mutable_fields must be an array';
        }
        if (!\is_array($contract['source_contracts'] ?? null)) {
            $errors[] = 'source_contracts must be an array';
        }
        if (!\is_array($contract['qa_gates'] ?? null)) {
            $errors[] = 'qa_gates must be an array or object';
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
