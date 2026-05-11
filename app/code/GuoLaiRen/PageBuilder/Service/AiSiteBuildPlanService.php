<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanContractSchema;
use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanContractValidator;

final class AiSiteBuildPlanService
{
    private const DEFAULT_POLICY_RULES = [
        'priority.user_requirements_first',
        'priority.default_premium_when_unspecified',
        'layout.grid_alignment',
        'layout.4_8_spacing',
        'typography.refined_font_stack',
        'color.readable_contrast',
        'image.integrated_not_pasted',
        'responsive.no_horizontal_scroll',
        'a11y.alt_focus_semantic',
    ];

    public function __construct(
        private readonly ?AiSiteDesignPolicyRegistry $policyRegistry = null,
        private readonly ?BuildPlanContractValidator $validator = null,
        private readonly ?AiSiteBuildPlanProjectionService $projectionService = null
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    public function buildFromScope(array $scope, array $websiteProfile = []): array
    {
        $existing = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if ($this->looksLikeBuildPlanV2($existing)) {
            return $this->normalizeExistingContract($existing, $scope, $websiteProfile);
        }

        $policy = $this->policyRegistry()->get();
        $policyRef = $this->policyRegistry()->policyRef();
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planStructured = \is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : [];
        $executionBlueprint = \is_array($scope['execution_blueprint_draft'] ?? null) && $scope['execution_blueprint_draft'] !== []
            ? $scope['execution_blueprint_draft']
            : (\is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : []);
        $sourcePlan = $planJson !== [] ? $planJson : ($planStructured !== [] ? $planStructured : $executionBlueprint);
        $profile = \array_replace(
            \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            $websiteProfile
        );
        $siteName = $this->firstNonEmpty([
            $profile['site_title'] ?? null,
            $profile['site_name'] ?? null,
            $scope['site_title'] ?? null,
            $scope['store_name'] ?? null,
            'AI Site',
        ]);
        $primaryGoal = $this->firstNonEmpty([
            $profile['brief_description'] ?? null,
            $profile['primary_goal'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
            'Present the business clearly and convert qualified visitors.',
        ]);
        $locale = $this->firstNonEmpty([
            $scope['plan_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $profile['default_locale'] ?? null,
            'zh_Hans_CN',
        ]);
        $sourceSignature = $this->sourceSignature($scope, $sourcePlan, $executionBlueprint);
        $contractId = 'build_plan_v2_' . \substr($sourceSignature, 0, 16);

        [$pages, $blocks, $tasks, $buildOrder, $contentItems] = $this->buildPageBlockTaskGraph(
            $scope,
            $sourcePlan,
            $executionBlueprint,
            $siteName,
            $primaryGoal
        );

        $contentItems = \array_replace(
            [
                'site.name' => $siteName,
                'site.primary_goal' => $primaryGoal,
            ],
            $contentItems,
            $this->extractExistingContentItems($scope)
        );

        return [
            'contract_meta' => [
                'id' => $contractId,
                'version' => BuildPlanContractSchema::VERSION,
                'status' => 'draft',
                'created_at' => \date('Y-m-d H:i:s'),
                'source_signature' => $sourceSignature,
            ],
            'source_of_truth' => [
                'stage_one_plan_signature' => (string)($executionBlueprint['signature'] ?? $scope['execution_blueprint_confirmed_signature'] ?? $sourceSignature),
                'design_policy_id' => AiSiteDesignPolicyRegistry::DEFAULT_POLICY_ID,
                'user_requirements' => [
                    'site_name' => $siteName,
                    'primary_goal' => $primaryGoal,
                    'page_types' => $this->resolvePageTypes($scope, $sourcePlan, $executionBlueprint),
                ],
            ],
            'policy_ref' => $policyRef,
            'policy_projection' => [
                'applied_rule_ids' => self::DEFAULT_POLICY_RULES,
                'banned_rule_ids' => ['ban.reason_fields', 'ban.lorem_ipsum'],
                'quality_floor' => \is_array($policy['quality_floor'] ?? null) ? $policy['quality_floor'] : [],
                'user_overrides' => [],
            ],
            'site_brief' => [
                'site_name' => $siteName,
                'primary_goal' => $primaryGoal,
                'summary' => $primaryGoal,
                'locale' => $locale,
            ],
            'design_manifest' => [
                'policy_id' => AiSiteDesignPolicyRegistry::DEFAULT_POLICY_ID,
                'tokens' => \is_array($policy['default_tokens'] ?? null) ? $policy['default_tokens'] : [],
                'recipes' => \is_array($policy['default_recipes'] ?? null) ? $policy['default_recipes'] : [],
            ],
            'i18n' => [
                'primary_locale' => $locale,
                'required_locales' => [$locale],
            ],
            'content_manifest' => [
                'primary_locale' => $locale,
                'items' => $contentItems,
            ],
            'pages' => $pages,
            'blocks' => $blocks,
            'tasks' => $tasks,
            'build_order' => $buildOrder,
            'permission_matrix' => [
                'read' => ['policy_ref', 'policy_projection', 'design_manifest', 'content_manifest', 'pages', 'blocks', 'tasks', 'build_order'],
                'create' => ['task_results', 'qa_report', 'repair_patch'],
                'patch' => ['render_data.*', 'asset_manifest.*', 'content_manifest.items.*', 'qa_gates.*'],
                'forbidden' => ['source_of_truth', 'policy_ref', 'policy_projection', 'design_manifest', 'pages', 'blocks', 'tasks', 'build_order'],
                'read_only' => ['source_of_truth', 'policy_ref', 'policy_projection', 'design_manifest', 'pages', 'blocks', 'tasks', 'build_order'],
            ],
            'frozen_fields' => ['source_of_truth', 'policy_ref', 'policy_projection', 'design_manifest', 'pages', 'blocks', 'tasks', 'build_order'],
            'mutable_fields' => ['render_data.*', 'asset_manifest.*', 'content_manifest.items.*', 'qa_gates.*'],
            'qa_gates' => [
                ['id' => 'schema_valid', 'status' => 'pending'],
                ['id' => 'policy_ref_valid', 'status' => 'pending'],
                ['id' => 'responsive_ready', 'status' => 'pending'],
            ],
            'presentation_projection' => [
                'never_feed_to_build' => true,
                'headline_key' => 'site.name',
                'summary_key' => 'site.primary_goal',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    public function confirm(array $contract): array
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        $confirmedAt = \date('Y-m-d H:i:s');
        $meta['version'] = (string)($meta['version'] ?? BuildPlanContractSchema::VERSION);
        $meta['status'] = 'confirmed';
        $meta['confirmed_at'] = (string)($meta['confirmed_at'] ?? $confirmedAt);
        $meta['signature'] = $this->contractSignature(\array_replace($contract, ['contract_meta' => \array_diff_key($meta, ['signature' => true])]));
        $contract['contract_meta'] = $meta;

        return $contract;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array{valid:bool,errors:list<string>}
     */
    public function validate(array $contract): array
    {
        return $this->validator()->validate($contract);
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    public function projection(array $contract): array
    {
        return $this->projectionService()->build($contract);
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function looksLikeBuildPlanV2(array $contract): bool
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        return (string)($meta['version'] ?? '') === BuildPlanContractSchema::VERSION
            && \is_array($contract['tasks'] ?? null)
            && \is_array($contract['pages'] ?? null)
            && \is_array($contract['blocks'] ?? null);
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    private function normalizeExistingContract(array $contract, array $scope, array $websiteProfile): array
    {
        if (!\is_array($contract['content_manifest'] ?? null) || !\is_array($contract['content_manifest']['items'] ?? null)) {
            $contract['content_manifest'] = [
                'primary_locale' => $this->firstNonEmpty([$scope['plan_locale'] ?? null, $scope['default_locale'] ?? null, 'zh_Hans_CN']),
                'items' => $this->extractExistingContentItems($scope),
            ];
        }
        if (!\is_array($contract['site_brief'] ?? null)) {
            $contract['site_brief'] = [
                'site_name' => $this->firstNonEmpty([$websiteProfile['site_title'] ?? null, $scope['site_title'] ?? null, 'AI Site']),
                'primary_goal' => $this->firstNonEmpty([$websiteProfile['brief_description'] ?? null, $scope['brief_description'] ?? null, 'Present the business clearly.']),
            ];
        }

        return $contract;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $sourcePlan
     * @param array<string, mixed> $executionBlueprint
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:list<array<string,mixed>>,3:list<string>,4:array<string,string>}
     */
    private function buildPageBlockTaskGraph(
        array $scope,
        array $sourcePlan,
        array $executionBlueprint,
        string $siteName,
        string $primaryGoal
    ): array {
        $pagesByType = $this->resolvePagesByType($scope, $sourcePlan, $executionBlueprint);
        $pages = [];
        $blocks = [];
        $tasks = [];
        $buildOrder = [];
        $contentItems = [];

        $sharedTasks = [
            [
                'task_id' => 'shared:header',
                'task_kind' => 'block_build',
                'executor' => 'AiSiteBuildQueue',
                'input_scope' => ['region' => 'header', 'component' => 'header'],
                'policy_slices' => ['layout.grid_alignment', 'typography.refined_font_stack', 'color.readable_contrast'],
                'context_budget' => ['max_tokens' => 1200],
                'acceptance_rule_ids' => ['responsive.no_horizontal_scroll', 'a11y.alt_focus_semantic'],
                'depends_on' => [],
            ],
            [
                'task_id' => 'shared:footer',
                'task_kind' => 'block_build',
                'executor' => 'AiSiteBuildQueue',
                'input_scope' => ['region' => 'footer', 'component' => 'footer'],
                'policy_slices' => ['layout.grid_alignment', 'typography.body_16_18', 'color.readable_contrast'],
                'context_budget' => ['max_tokens' => 1200],
                'acceptance_rule_ids' => ['responsive.no_horizontal_scroll', 'a11y.alt_focus_semantic'],
                'depends_on' => [],
            ],
        ];
        foreach ($sharedTasks as $task) {
            $tasks[] = $task;
            $buildOrder[] = (string)$task['task_id'];
        }

        foreach ($pagesByType as $pageIndex => $page) {
            $pageType = (string)($page['page_type'] ?? 'home_page');
            $pageId = $this->slugify($pageType);
            $pageTitleKey = 'page.' . $pageId . '.title';
            $pageDescriptionKey = 'page.' . $pageId . '.description';
            $pageTitle = $this->firstNonEmpty([$page['title'] ?? null, $page['page_title'] ?? null, Page::getPageTypes()[$pageType] ?? null, $siteName]);
            $pageDescription = $this->firstNonEmpty([$page['description'] ?? null, $page['page_goal'] ?? null, $page['goal'] ?? null, $primaryGoal]);
            $contentItems[$pageTitleKey] = $pageTitle;
            $contentItems[$pageDescriptionKey] = $pageDescription;

            $pageBlockIds = [];
            $pageBlocks = \is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
            if ($pageBlocks === []) {
                $pageBlocks = [['block_key' => 'hero', 'title' => $pageTitle, 'goal' => $pageDescription, 'block_type' => 'hero']];
            }

            foreach ($pageBlocks as $blockIndex => $rawBlock) {
                if (!\is_array($rawBlock)) {
                    continue;
                }
                $blockKey = $this->resolveBlockKey($rawBlock, $blockIndex);
                $blockId = $pageId . '.' . $this->slugify($blockKey);
                $taskId = 'page:' . $pageType . ':' . $this->slugify($blockKey);
                $titleKey = 'block.' . $blockId . '.title';
                $copyKey = 'block.' . $blockId . '.copy';
                $ctaKey = 'block.' . $blockId . '.cta';
                $blockTitle = $this->extractBlockTitle($rawBlock, $blockKey, $pageTitle);
                $blockCopy = $this->extractBlockCopy($rawBlock, $pageDescription);
                $blockCta = $this->extractBlockCta($rawBlock, $siteName);
                $contentItems[$titleKey] = $blockTitle;
                $contentItems[$copyKey] = $blockCopy;
                $contentItems[$ctaKey] = $blockCta;
                $blockType = $this->resolveBlockType($rawBlock, $blockIndex);

                $blocks[] = [
                    'block_id' => $blockId,
                    'page_id' => $pageId,
                    'block_type' => $blockType,
                    'content_keys' => [$titleKey, $copyKey, $ctaKey],
                    'task_ids' => [$taskId],
                    'visual' => [
                        'image_integration' => 'Integrate imagery as part of the section composition, with responsive crop and readable overlays when needed.',
                    ],
                    'sort_order' => 1000 + ((int)$pageIndex * 100) + ((int)$blockIndex * 10),
                ];
                $pageBlockIds[] = $blockId;
                $tasks[] = [
                    'task_id' => $taskId,
                    'task_kind' => 'block_build',
                    'executor' => 'AiSiteBuildQueue',
                    'input_scope' => [
                        'page_id' => $pageId,
                        'page_type' => $pageType,
                        'block_id' => $blockId,
                        'block_type' => $blockType,
                        'section_key' => $blockKey,
                    ],
                    'policy_slices' => ['layout.4_8_spacing', 'typography.refined_font_stack', 'image.integrated_not_pasted', 'responsive.no_horizontal_scroll'],
                    'context_budget' => ['max_tokens' => 1800],
                    'acceptance_rule_ids' => ['responsive.no_horizontal_scroll', 'a11y.alt_focus_semantic', 'color.readable_contrast'],
                    'depends_on' => ['shared:header', 'shared:footer'],
                ];
                $buildOrder[] = $taskId;
            }

            $pages[] = [
                'page_id' => $pageId,
                'page_type' => $pageType,
                'title_key' => $pageTitleKey,
                'description_key' => $pageDescriptionKey,
                'blocks' => $pageBlockIds,
                'sort_order' => 100 + ((int)$pageIndex * 10),
            ];
        }

        return [$pages, $blocks, $tasks, $buildOrder, $contentItems];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $sourcePlan
     * @param array<string, mixed> $executionBlueprint
     * @return list<array<string, mixed>>
     */
    private function resolvePagesByType(array $scope, array $sourcePlan, array $executionBlueprint): array
    {
        $sources = [
            $executionBlueprint['pages'] ?? null,
            $executionBlueprint['page_plans'] ?? null,
            $sourcePlan['pages'] ?? null,
            $sourcePlan['page_plans'] ?? null,
        ];
        foreach ($sources as $source) {
            $pages = $this->normalizePagesSource($source);
            if ($pages !== []) {
                return $pages;
            }
        }

        $pageTypes = $this->resolvePageTypes($scope, $sourcePlan, $executionBlueprint);
        $pages = [];
        foreach ($pageTypes as $pageType) {
            $pages[] = [
                'page_type' => $pageType,
                'title' => (string)(Page::getPageTypes()[$pageType] ?? $pageType),
                'page_goal' => 'Present the ' . $pageType . ' content clearly.',
                'blocks' => [['block_key' => 'hero', 'block_type' => 'hero']],
            ];
        }

        return $pages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizePagesSource(mixed $source): array
    {
        if (!\is_array($source) || $source === []) {
            return [];
        }

        $pages = [];
        foreach ($source as $key => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? $page['type'] ?? (\is_string($key) ? $key : '')));
            if ($pageType === '') {
                $pageType = 'page_' . ((int)\count($pages) + 1);
            }
            $page['page_type'] = $pageType;
            $pages[] = $page;
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $sourcePlan
     * @param array<string, mixed> $executionBlueprint
     * @return list<string>
     */
    private function resolvePageTypes(array $scope, array $sourcePlan, array $executionBlueprint): array
    {
        $candidates = [
            $scope['page_types'] ?? null,
            $executionBlueprint['page_types'] ?? null,
            $sourcePlan['page_types'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $types = $this->stringList($candidate);
            if ($types !== []) {
                return $types;
            }
        }

        return ['home_page'];
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveBlockKey(array $block, int $index): string
    {
        $key = $this->firstNonEmpty([
            $block['block_key'] ?? null,
            $block['source_block_key'] ?? null,
            $block['key'] ?? null,
            $block['id'] ?? null,
            $block['type'] ?? null,
            'block_' . ((int)$index + 1),
        ]);

        return $key !== '' ? $key : 'block_' . ((int)$index + 1);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveBlockType(array $block, int $index): string
    {
        $type = \strtolower($this->firstNonEmpty([
            $block['block_type'] ?? null,
            $block['type'] ?? null,
            $block['template'] ?? null,
            $index === 0 ? 'hero' : 'section',
        ]));
        $type = \preg_replace('/[^a-z0-9_-]+/', '_', $type) ?? $type;
        $type = \trim($type, '_-');

        return $type !== '' ? $type : ($index === 0 ? 'hero' : 'section');
    }

    /**
     * @param array<string, mixed> $block
     */
    private function extractBlockTitle(array $block, string $fallbackKey, string $pageTitle): string
    {
        $fieldTitle = $this->extractFieldPlanText($block, ['title', 'heading', 'headline']);
        return $this->firstNonEmpty([
            $fieldTitle,
            $block['title'] ?? null,
            $block['name'] ?? null,
            $block['label'] ?? null,
            $block['goal'] ?? null,
            $pageTitle . ' ' . \ucfirst(\str_replace(['_', '-'], ' ', $fallbackKey)),
        ]);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function extractBlockCopy(array $block, string $fallback): string
    {
        $fieldCopy = $this->extractFieldPlanText($block, ['description', 'body', 'copy', 'subtitle']);
        $realtime = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        $supporting = \is_array($realtime['supporting_copy'] ?? null) ? \implode(' ', \array_map('strval', $realtime['supporting_copy'])) : '';

        return $this->firstNonEmpty([
            $fieldCopy,
            $realtime['headline'] ?? null,
            $supporting,
            $block['content'] ?? null,
            $block['implementation_detail'] ?? null,
            $block['goal'] ?? null,
            $fallback,
        ]);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function extractBlockCta(array $block, string $siteName): string
    {
        $fieldCta = $this->extractFieldPlanText($block, ['cta', 'button', 'action']);
        $realtime = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        $ctas = \is_array($realtime['ctas'] ?? null) ? $realtime['ctas'] : [];
        foreach ($ctas as $cta) {
            if (!\is_array($cta)) {
                continue;
            }
            $text = $this->firstNonEmpty([$cta['text'] ?? null, $cta['label'] ?? null]);
            if ($text !== '') {
                return $text;
            }
        }

        return $this->firstNonEmpty([$fieldCta, 'Start with ' . $siteName]);
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string> $fieldNames
     */
    private function extractFieldPlanText(array $block, array $fieldNames): string
    {
        $wanted = \array_fill_keys(\array_map('strtolower', $fieldNames), true);
        foreach (\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [] as $field) {
            if (!\is_array($field)) {
                continue;
            }
            $name = \strtolower(\trim((string)($field['field'] ?? $field['name'] ?? '')));
            if (!isset($wanted[$name])) {
                continue;
            }
            $text = $this->cleanVisibleCopy((string)($field['sample'] ?? $field['value'] ?? $field['text'] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, string>
     */
    private function extractExistingContentItems(array $scope): array
    {
        $contentManifest = \is_array($scope['content_manifest'] ?? null) ? $scope['content_manifest'] : [];
        $items = \is_array($contentManifest['items'] ?? null) ? $contentManifest['items'] : [];
        $result = [];
        foreach ($items as $key => $value) {
            $key = \trim((string)$key);
            if ($key === '') {
                continue;
            }
            $text = $this->extractScalarText($value);
            if ($text !== '') {
                $result[$key] = $text;
            }
        }

        return $result;
    }

    private function extractScalarText(mixed $value): string
    {
        if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
            return $this->cleanVisibleCopy((string)$value);
        }
        if (!\is_array($value)) {
            return '';
        }
        foreach (['text', 'value', 'copy', 'content'] as $field) {
            if (\array_key_exists($field, $value)) {
                return $this->extractScalarText($value[$field]);
            }
        }

        return '';
    }

    /**
     * @param list<mixed> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $text = $this->cleanVisibleCopy((string)$value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
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

    private function cleanVisibleCopy(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        $patterns = [
            '/^(Visitors\s+see|Visitor\s+sees|Visitors\s+can|Visitors\s+will|Provide|Show|Display|List|Present)\s+/iu' => '',
            '/^(The|This|A)\s+(visitor|customer|user)\s+/iu' => '',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $value = \preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return \trim($value);
    }

    private function slugify(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \preg_replace('/[^a-z0-9]+/i', '_', $value) ?? $value;
        $value = \trim($value, '_');

        return $value !== '' ? $value : 'item';
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $sourcePlan
     * @param array<string, mixed> $executionBlueprint
     */
    private function sourceSignature(array $scope, array $sourcePlan, array $executionBlueprint): string
    {
        return \sha1((string)\json_encode([
            'plan_generated_source_signature' => (string)($scope['plan_generated_source_signature'] ?? ''),
            'execution_blueprint_signature' => (string)($executionBlueprint['signature'] ?? ''),
            'source_plan' => $sourcePlan,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function contractSignature(array $contract): string
    {
        return \sha1((string)\json_encode($contract, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    private function policyRegistry(): AiSiteDesignPolicyRegistry
    {
        return $this->policyRegistry ?? new AiSiteDesignPolicyRegistry();
    }

    private function validator(): BuildPlanContractValidator
    {
        return $this->validator ?? new BuildPlanContractValidator();
    }

    private function projectionService(): AiSiteBuildPlanProjectionService
    {
        return $this->projectionService ?? new AiSiteBuildPlanProjectionService();
    }
}
