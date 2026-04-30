<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Tool;

use GuoLaiRen\PageBuilder\Service\AiSiteBlockPartialPatchService;
use Weline\Ai\Interface\ToolInterface;
use Weline\Framework\Manager\ObjectManager;

class ReadCurrentBlockTool implements ToolInterface
{
    public function getName(): string
    {
        return 'read_current_block';
    }

    public function getDescription(): string
    {
        return 'Read the current PageBuilder block by public_id, page_type, and block_id/component_code. Returns the full block, server template metadata, field schema, and page/layout context.';
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
                    'description' => 'Target block_id. component_code is accepted when block_id is not known.',
                ],
                'component_code' => [
                    'type' => 'string',
                    'description' => 'Optional component code fallback.',
                ],
            ],
            'required' => ['admin_id', 'public_id', 'page_type'],
        ];
    }

    public function execute(array $args): mixed
    {
        $blockId = \trim((string)($args['block_id'] ?? ''));
        if ($blockId === '') {
            $blockId = \trim((string)($args['component_code'] ?? ''));
        }

        return ObjectManager::getInstance(AiSiteBlockPartialPatchService::class)->readCurrentBlock(
            (int)($args['admin_id'] ?? 0),
            \trim((string)($args['public_id'] ?? '')),
            \trim((string)($args['page_type'] ?? '')),
            $blockId
        );
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
