<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class ContractType
{
    public const VERSION_V1 = 'v1';

    public const STAGE_STAGE1 = 'stage1';
    public const STAGE_STAGE2 = 'stage2';
    public const STAGE_BUILD = 'build';
    public const STAGE_QA = 'qa';
    public const STAGE_REPAIR = 'repair';

    public const TYPE_SITE_BRIEF = 'site_brief';
    public const TYPE_DESIGN_MANIFEST = 'design_manifest';
    public const TYPE_PAGE_CONTRACT = 'page_contract';
    public const TYPE_BLOCK_PLAN = 'block_plan';
    public const TYPE_BLOCK_VISUAL_CONTRACT = 'block_visual_contract';
    public const TYPE_BLOCK_TASK_CONTRACT = 'block_task_contract';
    public const TYPE_RENDER_DATA = 'render_data';
    public const TYPE_THEME_MANIFEST = 'theme_manifest';
    public const TYPE_QA_REPORT = 'qa_report';
    public const TYPE_REPAIR_PATCH = 'repair_patch';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPATIBILITY = 'compatibility';
    public const STATUS_FAILED = 'failed';

    /**
     * @return list<string>
     */
    public function v1Types(): array
    {
        return [
            self::TYPE_SITE_BRIEF,
            self::TYPE_DESIGN_MANIFEST,
            self::TYPE_PAGE_CONTRACT,
            self::TYPE_BLOCK_PLAN,
            self::TYPE_BLOCK_VISUAL_CONTRACT,
            self::TYPE_BLOCK_TASK_CONTRACT,
            self::TYPE_RENDER_DATA,
            self::TYPE_THEME_MANIFEST,
            self::TYPE_QA_REPORT,
            self::TYPE_REPAIR_PATCH,
        ];
    }

    public function defaultStageForType(string $type): string
    {
        return match ($type) {
            self::TYPE_SITE_BRIEF,
            self::TYPE_DESIGN_MANIFEST,
            self::TYPE_PAGE_CONTRACT,
            self::TYPE_BLOCK_PLAN => self::STAGE_STAGE1,
            self::TYPE_BLOCK_VISUAL_CONTRACT,
            self::TYPE_BLOCK_TASK_CONTRACT => self::STAGE_STAGE2,
            self::TYPE_RENDER_DATA,
            self::TYPE_THEME_MANIFEST => self::STAGE_BUILD,
            self::TYPE_QA_REPORT => self::STAGE_QA,
            self::TYPE_REPAIR_PATCH => self::STAGE_REPAIR,
            default => '',
        };
    }
}
