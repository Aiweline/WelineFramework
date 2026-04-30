<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Tool;

use GuoLaiRen\PageBuilder\Service\AiSiteBlockPartialPatchService;
use Weline\Ai\Interface\ToolInterface;
use Weline\Framework\Manager\ObjectManager;

class ReplaceCurrentBlockTool implements ToolInterface
{
    public function getName(): string
    {
        return 'replace_current_block';
    }

    public function getDescription(): string
    {
        return 'Validate and replace exactly one current PageBuilder block. The replacement must keep the same page and block_id, and cannot add, remove, or reorder other blocks.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'admin_id' => [
                    'type' => 'integer',
                    'description' => 'Current backend admin id.',
                ],
                'public_id' => [
                    'type' => 'string',
                    'description' => 'AI site workspace public id.',
                ],
                'page_type' => [
                    'type' => 'string',
                    'description' => 'Target page type.',
                ],
                'block_id' => [
                    'type' => 'string',
                    'description' => 'Target current block_id.',
                ],
                'component_code' => [
                    'type' => 'string',
                    'description' => 'Optional component code fallback when block_id is omitted.',
                ],
                'replacement_block' => [
                    'type' => 'object',
                    'description' => 'Complete replacement block with block_id, type, config, html, and field_schema.',
                ],
                'change_summary' => [
                    'type' => 'string',
                    'description' => 'Short human-readable summary of what changed.',
                ],
                'changed_fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Changed config/schema fields.',
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'Why the change satisfies the user instruction.',
                ],
                'execution_token' => [
                    'type' => 'string',
                    'description' => 'Current operation execution token for rollback history.',
                ],
            ],
            'required' => ['admin_id', 'public_id', 'page_type', 'replacement_block', 'change_summary'],
        ];
    }

    public function execute(array $args): mixed
    {
        $blockId = \trim((string)($args['block_id'] ?? ''));
        if ($blockId === '') {
            $blockId = \trim((string)($args['component_code'] ?? ''));
        }
        $replacement = \is_array($args['replacement_block'] ?? null) ? $args['replacement_block'] : [];

        return ObjectManager::getInstance(AiSiteBlockPartialPatchService::class)->applyReplacementBlockByPublicId(
            (int)($args['admin_id'] ?? 0),
            \trim((string)($args['public_id'] ?? '')),
            \trim((string)($args['page_type'] ?? '')),
            $blockId,
            $replacement,
            [
                'change_summary' => \trim((string)($args['change_summary'] ?? '')),
                'changed_fields' => \is_array($args['changed_fields'] ?? null) ? $args['changed_fields'] : [],
                'reason' => \trim((string)($args['reason'] ?? '')),
                'execution_token' => \trim((string)($args['execution_token'] ?? '')),
            ]
        );
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
