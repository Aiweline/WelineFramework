<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Ai\Tool;

use Weline\Ai\Api\ToolInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeResourceCatalog;

class GetSlotContractTool implements ToolInterface
{
    public function __construct(
        private readonly ThemeResourceCatalog $resourceCatalog,
        private readonly WelineTheme $welineTheme,
    ) {
    }

    public function getName(): string
    {
        return 'get_slot_contract';
    }

    public function getDescription(): string
    {
        return 'Return slot contracts extracted from current theme layouts and partials, including accept/exclusive/multiple rules.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'theme_id' => ['type' => 'integer', 'description' => 'Theme ID'],
                'area' => ['type' => 'string', 'description' => 'frontend or backend'],
                'slot_id' => ['type' => 'string', 'description' => 'Optional specific slot id'],
            ],
            'required' => ['theme_id'],
        ];
    }

    public function execute(array $args): mixed
    {
        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load((int)($args['theme_id'] ?? 0));
        $area = strtolower((string)($args['area'] ?? 'frontend')) === 'backend' ? 'backend' : 'frontend';
        $slotId = trim((string)($args['slot_id'] ?? ''));
        $slots = $this->resourceCatalog->getSlots($area, $theme);

        if ($slotId !== '') {
            return [
                'slot' => $slots[$slotId] ?? null,
            ];
        }

        return [
            'total' => count($slots),
            'slots' => $slots,
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
