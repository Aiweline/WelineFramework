<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class SourceContractHelper
{
    /**
     * @param array<int|string, mixed> $sources
     * @return list<array{id:string,type:string,version:string,status:string}>
     */
    public function normalize(array $sources): array
    {
        $normalized = [];
        foreach ($sources as $key => $source) {
            if (\is_string($source)) {
                $source = ['id' => $source, 'type' => \is_string($key) ? $key : ''];
            }
            if (!\is_array($source)) {
                continue;
            }
            $id = \trim((string)($source['id'] ?? $source['contract_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $normalized[] = [
                'id' => $id,
                'type' => \trim((string)($source['type'] ?? '')),
                'version' => \trim((string)($source['version'] ?? ContractType::VERSION_V1)),
                'status' => \trim((string)($source['status'] ?? '')),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $contract
     * @param list<string> $requiredTypes
     * @return array{valid:bool,errors:list<string>}
     */
    public function validateRequired(array $contract, array $requiredTypes): array
    {
        $sources = $this->normalize(\is_array($contract['source_contracts'] ?? null) ? $contract['source_contracts'] : []);
        $present = [];
        foreach ($sources as $source) {
            if ($source['type'] !== '') {
                $present[$source['type']] = true;
            }
        }

        $errors = [];
        foreach ($requiredTypes as $type) {
            if (!isset($present[$type])) {
                $errors[] = 'Missing source contract: ' . $type;
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }
}
