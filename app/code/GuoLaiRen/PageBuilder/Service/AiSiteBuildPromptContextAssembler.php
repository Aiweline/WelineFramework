<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteBuildPromptContextAssembler
{
    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    public function assemble(array $contract, array $task): array
    {
        $this->assertNoForbiddenBuildContextKeys($task['runtime_context'] ?? [], 'task.runtime_context');
        $inputScope = \is_array($task['input_scope'] ?? null) ? $task['input_scope'] : [];
        $blockId = \trim((string)($inputScope['block_id'] ?? $task['block_id'] ?? ''));
        $pageId = \trim((string)($inputScope['page_id'] ?? $task['page_id'] ?? ''));
        $pages = $this->normalizeRecordSet($contract['pages'] ?? [], ['page_id', 'id']);
        $blocks = $this->extractPlanJsonBlocks($pages);
        $contentManifest = \is_array($contract['content_manifest'] ?? null) ? $contract['content_manifest'] : [];
        $items = \is_array($contentManifest['items'] ?? null) ? $contentManifest['items'] : [];
        $block = \is_array($blocks[$blockId] ?? null) ? $blocks[$blockId] : [];
        $page = \is_array($pages[$pageId] ?? null) ? $pages[$pageId] : [];
        $pageType = \trim((string)($page['page_type'] ?? $inputScope['page_type'] ?? ''));
        $pageIdentity = $this->resolvePageIdentityContract($page, $items, $pageType);
        $blockFlowRole = $this->firstNonEmpty([
            $block['page_flow_role'] ?? null,
            $task['page_flow_role'] ?? null,
            $inputScope['page_flow_role'] ?? null,
        ]);
        $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
        $contentLocale = $this->firstNonEmpty([
            $runtimeContext['content_locale'] ?? null,
        ]);
        $languageContract = \is_array($runtimeContext['language_contract'] ?? null)
            ? $runtimeContext['language_contract']
            : $this->buildLanguageContract($contentLocale, $contract);
        $designTokens = $this->resolveDesignTokens($contract, $runtimeContext);

        $assembled = [
            'contract_id' => (string)($contract['contract_meta']['id'] ?? ''),
            'task' => $task,
            'content_locale' => $contentLocale,
            'language_contract' => $languageContract,
            'design_tokens' => $designTokens,
            'page' => $page,
            'page_goal' => (string)($pageIdentity['page_goal'] ?? ''),
            'page_flow_role' => $blockFlowRole,
            'page_identity_contract' => $pageIdentity,
            'page_design_plan' => \is_array($pageIdentity['page_design_plan'] ?? null) ? $pageIdentity['page_design_plan'] : [],
            'block' => $block,
            'content_items' => $this->sliceContentItems($items, $this->stringList($block['content_keys'] ?? [])),
            'site_identity_contract' => $this->resolveSiteIdentityContract($contract, $items),
            'runtime_context' => $runtimeContext,
            'output_contract' => \is_array($task['output_contract'] ?? null) ? $task['output_contract'] : [],
            'acceptance' => \is_array($task['acceptance'] ?? null) ? $task['acceptance'] : [],
            'policy_ref' => \is_array($contract['policy_ref'] ?? null) ? $contract['policy_ref'] : [],
            'policy_slices' => $this->stringList($task['policy_slices'] ?? []),
            'acceptance_rule_ids' => $this->stringList($task['acceptance_rule_ids'] ?? []),
            'context_budget' => \is_array($task['context_budget'] ?? null) ? $task['context_budget'] : [],
        ];
        AiSiteWorkflowTrace::json('prompt_context_assembled', $assembled, [
            'contract_id' => (string)($contract['contract_meta']['id'] ?? ''),
            'task_id' => (string)($task['task_id'] ?? $task['id'] ?? ''),
            'page_type' => $pageType,
            'block_id' => $blockId,
        ]);

        return $assembled;
    }

    private function assertNoForbiddenBuildContextKeys(mixed $value, string $path): void
    {
        if (!\is_array($value)) {
            return;
        }

        $forbidden = [
            'scope' => true,
            'plan_json' => true,
            'presentation_projection' => true,
            'ui_projection' => true,
        ];
        foreach ($value as $key => $item) {
            $keyText = \trim((string)$key);
            $nextPath = $path . '.' . $keyText;
            if (isset($forbidden[$keyText])) {
                throw new \RuntimeException('Forbidden broad build context key: ' . $nextPath);
            }
            $this->assertNoForbiddenBuildContextKeys($item, $nextPath);
        }
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $contentItems
     * @return array<string, mixed>
     */
    private function resolvePageIdentityContract(array $page, array $contentItems, string $pageType): array
    {
        if ($pageType === '') {
            return [];
        }

        $pageDesignPlan = \is_array($page['page_design_plan'] ?? null) ? $page['page_design_plan'] : [];
        $title = $this->firstNonEmpty([
            $this->contentItemValue($contentItems, (string)($page['title_key'] ?? '')),
            $page['title'] ?? null,
            $page['page_title'] ?? null,
            $pageDesignPlan['page_title'] ?? null,
        ]);
        $description = $this->firstNonEmpty([
            $this->contentItemValue($contentItems, (string)($page['description_key'] ?? '')),
            $page['description'] ?? null,
            $page['summary'] ?? null,
            $pageDesignPlan['summary'] ?? null,
        ]);
        $pageGoal = $this->firstNonEmpty([
            $page['page_goal'] ?? null,
            $page['goal'] ?? null,
            $pageDesignPlan['page_role'] ?? null,
            $pageDesignPlan['content_focus'] ?? null,
            $description,
        ]);
        $contentFocus = $this->firstNonEmpty([
            $page['content_focus'] ?? null,
            $pageDesignPlan['content_focus'] ?? null,
            $description,
        ]);
        $conversionRole = $this->firstNonEmpty([
            $page['conversion_role'] ?? null,
            $pageDesignPlan['conversion_role'] ?? null,
        ]);

        return [
            'page_type' => $pageType,
            'title' => $title,
            'description' => $description,
            'page_goal' => $pageGoal,
            'content_focus' => $contentFocus,
            'conversion_role' => $conversionRole,
            'page_flow_role' => $this->firstNonEmpty([$page['page_flow_role'] ?? null]),
            'page_design_plan' => $pageDesignPlan,
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $contentItems
     * @return array<string, mixed>
     */
    private function resolveSiteIdentityContract(array $contract, array $contentItems): array
    {
        $sourceOfTruth = \is_array($contract['source_of_truth'] ?? null) ? $contract['source_of_truth'] : [];
        $userRequirements = \is_array($sourceOfTruth['user_requirements'] ?? null) ? $sourceOfTruth['user_requirements'] : [];
        $brandIdentity = \is_array($userRequirements['brand_identity'] ?? null) ? $userRequirements['brand_identity'] : [];

        return [
            'site_name' => $this->firstNonEmpty([
                $contentItems['site.name'] ?? null,
                $userRequirements['site_name'] ?? null,
                $contract['site_brief']['site_name'] ?? null,
            ]),
            'allowed_brand_terms' => $this->stringList($brandIdentity['allowed_brand_terms'] ?? []),
            'forbidden_template_brand_terms' => $this->stringList($brandIdentity['forbidden_template_brand_terms'] ?? []),
            'template_scaffold_rule' => (string)($brandIdentity['template_scaffold_rule'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $items
     */
    private function contentItemValue(array $items, string $key): string
    {
        if ($key === '' || !\array_key_exists($key, $items)) {
            return '';
        }

        return \trim((string)$items[$key]);
    }

    /**
     * @param list<mixed> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function buildLanguageContract(string $locale, array $contract = []): array
    {
        $base = [
            'source_of_truth_locale' => $locale,
            'visible_copy_rule' => 'All visitor-facing copy must use source_of_truth_locale.',
            'plan_text_rule' => 'PlanJson copy is intent only and must be rewritten before rendering.',
        ];

        $voiceResolver = new AiSiteLanguageVoiceResolver();
        $extension = $voiceResolver->buildLanguageContractExtension($contract, $locale);

        return \array_replace($base, $extension);
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $runtimeContext
     * @return array<string, mixed>
     */
    private function resolveDesignTokens(array $contract, array $runtimeContext): array
    {
        if (\is_array($runtimeContext['design_tokens'] ?? null) && $runtimeContext['design_tokens'] !== []) {
            return $runtimeContext['design_tokens'];
        }

        $resolver = new AiSiteDesignTokenResolver();
        $blueprint = $contract;
        if (\is_array($contract['plan_json'] ?? null) && !isset($blueprint['theme_design'])) {
            $blueprint['plan_json'] = $contract['plan_json'];
            $blueprint['theme_design'] = $contract['plan_json']['theme_design'] ?? [];
        }

        return $blueprint !== [] ? $resolver->resolveFromBlueprint($blueprint) : [];
    }

    /**
     * @param list<string> $idFields
     * @return array<string, array<string, mixed>>
     */
    private function normalizeRecordSet(mixed $items, array $idFields): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $key => $item) {
            if (!\is_array($item)) {
                continue;
            }
            $id = '';
            foreach ($idFields as $field) {
                $id = \trim((string)($item[$field] ?? ''));
                if ($id !== '') {
                    break;
                }
            }
            if ($id === '' && \is_string($key)) {
                $id = $key;
            }
            if ($id !== '') {
                $normalized[$id] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, array<string, mixed>> $pages
     * @return array<string, array<string, mixed>>
     */
    private function extractPlanJsonBlocks(array $pages): array
    {
        $blocks = [];
        foreach ($pages as $pageId => $page) {
            foreach ($this->extractPageBlocks($page) as $blockKey => $block) {
                $blockId = \trim((string)($block['block_id'] ?? $block['id'] ?? $blockKey));
                if ($blockId === '') {
                    continue;
                }
                $blocks[$blockId] = $block + [
                    'block_key' => (string)$blockKey,
                    'page_id' => (string)$pageId,
                    'page_type' => (string)($page['page_type'] ?? $pageId),
                ];
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, array<string, mixed>>
     */
    private function extractPageBlocks(array $page): array
    {
        $reserved = [
            'page_id' => true,
            'id' => true,
            'page_type' => true,
            'type' => true,
            'title' => true,
            'description' => true,
            'page_goal' => true,
            'page_design_plan' => true,
            'theme_alignment_summary' => true,
            'status' => true,
            'seo' => true,
            'route' => true,
            'meta' => true,
            'layout' => true,
            'blocks' => true,
            'block_previews' => true,
            'sections' => true,
            'components' => true,
        ];
        $blocks = [];
        foreach ($page as $key => $value) {
            if (!\is_string($key) || isset($reserved[$key]) || !\is_array($value)) {
                continue;
            }
            $blocks[$key] = $value;
        }

        return $blocks;
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

    /**
     * @param array<string, mixed> $items
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function sliceContentItems(array $items, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (\array_key_exists($key, $items)) {
                $result[$key] = $items[$key];
            }
        }

        return $result;
    }
}
