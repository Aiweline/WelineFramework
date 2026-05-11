<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class BuildPlanFrozenFieldValidator
{
    private const DEFAULT_FORBIDDEN_PREFIXES = [
        'source_of_truth',
        'policy_ref',
        'policy_projection',
        'design_manifest',
        'pages',
        'blocks',
        'tasks',
        'build_order',
        'permission_matrix',
        'frozen_fields',
    ];

    public function __construct(
        private readonly ?ContractPatchValidator $patchValidator = null
    ) {
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $next
     * @return array{valid:bool,errors:list<string>}
     */
    public function validate(array $previous, array $next): array
    {
        $frozen = \array_values(\array_unique(\array_merge(
            self::DEFAULT_FORBIDDEN_PREFIXES,
            $this->stringList($previous['frozen_fields'] ?? [])
        )));
        $permissionMatrix = \is_array($previous['permission_matrix'] ?? null) ? $previous['permission_matrix'] : [];
        $result = ($this->patchValidator ?? new ContractPatchValidator())->validate($previous, $next, $permissionMatrix, $frozen);
        $errors = \is_array($result['errors'] ?? null) ? \array_values(\array_map('strval', $result['errors'])) : [];

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array{valid:bool,errors:list<string>}
     */
    public function validateRepairCandidatePath(string $path, array $contract): array
    {
        $path = \trim($path);
        if ($path === '') {
            return ['valid' => false, 'errors' => ['Patch path is empty']];
        }

        foreach (\array_merge(self::DEFAULT_FORBIDDEN_PREFIXES, $this->stringList($contract['frozen_fields'] ?? [])) as $prefix) {
            $prefix = \trim((string)$prefix);
            if ($prefix === '') {
                continue;
            }
            if ($prefix === $path || \str_starts_with($path, $prefix . '.') || (\str_ends_with($prefix, '.*') && \str_starts_with($path, \substr($prefix, 0, -1)))) {
                return ['valid' => false, 'errors' => ['Path is frozen for build plan repair: ' . $path]];
            }
        }

        return ['valid' => true, 'errors' => []];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            $text = \trim((string)$value);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return \array_values(\array_unique($result));
    }
}
