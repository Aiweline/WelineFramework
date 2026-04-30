<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Agent;

use GuoLaiRen\PageBuilder\Service\AI\Tool\ReadCurrentBlockTool;
use GuoLaiRen\PageBuilder\Service\AI\Tool\ReplaceCurrentBlockTool;
use Weline\Framework\Manager\ObjectManager;

class PageBuilderBlockPatchAgent extends PageBuilderRefineAgent
{
    private ?array $blockPatchTools = null;

    public function getCode(): string
    {
        return 'pagebuilder_block_partial_patch';
    }

    public function getName(): string
    {
        return __('PageBuilder block partial patch agent');
    }

    public function getDescription(): string
    {
        return __('Reads one existing PageBuilder block and replaces only that block after validation.');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getScenarios(): array
    {
        return ['pagebuilder_component_generation'];
    }

    public function getTools(): array
    {
        if ($this->blockPatchTools === null) {
            $this->blockPatchTools = [
                ObjectManager::getInstance(ReadCurrentBlockTool::class),
                ObjectManager::getInstance(ReplaceCurrentBlockTool::class),
            ];
        }

        return $this->blockPatchTools;
    }

    public function getSystemPrompt(array $context = []): string
    {
        return <<<'PROMPT'
You are a PageBuilder block partial patch agent.

Workflow:
1. Call read_current_block with the provided admin_id, public_id, page_type, and block_id/component_code.
2. Modify only the returned block to satisfy the instruction.
3. Call replace_current_block with a complete replacement_block and concise change_summary, changed_fields, and reason.
4. Do not add, delete, move, or edit any other block.

Replacement rules:
- replacement_block.block_id must exactly match the current block_id.
- replacement_block must include type, config, html, and field_schema.
- Preserve server-only metadata keys beginning with _pb_server_ unless the template itself must change.
- If _pb_server_template_phtml exists, keep it renderable.
- Final response should be a short JSON summary only.
PROMPT;
    }
}
