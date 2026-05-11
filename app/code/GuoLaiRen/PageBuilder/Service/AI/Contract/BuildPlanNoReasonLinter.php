<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class BuildPlanNoReasonLinter
{
    public function __construct(
        private readonly ?BuildPlanContractSchema $schema = null
    ) {
    }

    /**
     * @param array<string, mixed> $contract
     * @return array{valid:bool,errors:list<string>}
     */
    public function validate(array $contract): array
    {
        $errors = [];
        $this->scan($contract, '$', $errors);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param list<string> $errors
     */
    private function scan(mixed $node, string $path, array &$errors): void
    {
        if (!\is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $keyText = (string)$key;
            $nextPath = $path === '$' ? '$.' . $keyText : $path . '.' . $keyText;
            if ($this->isForbiddenKey($keyText)) {
                $errors[] = 'Forbidden explanatory field at ' . $nextPath . ': ' . $keyText;
            }
            $this->scan($value, $nextPath, $errors);
        }
    }

    private function isForbiddenKey(string $key): bool
    {
        $normalized = \strtolower(\trim($key));
        if ($normalized === '') {
            return false;
        }

        foreach ($this->schema()->forbiddenFieldNames() as $forbidden) {
            $forbiddenNormalized = \strtolower($forbidden);
            if ($normalized === $forbiddenNormalized) {
                return true;
            }
            if (\preg_match('/(^|[_\\-])' . \preg_quote($forbiddenNormalized, '/') . '($|[_\\-])/i', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    private function schema(): BuildPlanContractSchema
    {
        return $this->schema ?? new BuildPlanContractSchema();
    }
}
