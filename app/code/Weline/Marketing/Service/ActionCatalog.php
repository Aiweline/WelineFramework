<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Marketing\Api\Rule\ActionCatalogInterface;
use Weline\Marketing\Api\Rule\ActionDescriptor;

final class ActionCatalog implements ActionCatalogInterface
{
    public function __construct(
        private readonly RuleEngine $ruleEngine,
    ) {
    }

    public function all(): array
    {
        $descriptors = [];
        foreach ($this->ruleEngine->getAvailableActions() as $key => $action) {
            if (!is_array($action)) {
                continue;
            }
            $code = strtolower(trim((string)($action['code'] ?? $key)));
            if ($code === '') {
                continue;
            }
            $descriptors[] = new ActionDescriptor(
                code: $code,
                name: (string)($action['name'] ?? $code),
                description: (string)($action['description'] ?? ''),
                formFields: is_array($action['form_fields'] ?? null)
                    ? $this->dataOnly($action['form_fields'])
                    : [],
            );
        }

        return $descriptors;
    }

    /**
     * Keep executable objects, resources and callbacks inside Marketing.
     *
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private function dataOnly(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = $this->dataOnly($value);
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
