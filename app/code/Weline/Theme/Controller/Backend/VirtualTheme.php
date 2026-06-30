<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Backend;

use Weline\Ai\Model\AiStyle;
use Weline\Ai\Service\Skill\AdapterSkillResolver;
use Weline\Ai\Service\Style\AdapterStyleResolver;
use Weline\Ai\Service\Style\StyleRegistry;
use Weline\Ai\Service\Style\StyleService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\ThemeVirtualLayoutVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeVirtualLayoutService;
use Weline\Theme\Service\ThemeVirtualThemeManifestService;

class VirtualTheme extends BackendController
{
    private const ADAPTER_CODE = 'theme';
    private const DEFAULT_SCOPE = ThemeVirtualLayoutService::DEFAULT_SCOPE;

    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeVirtualThemeManifestService $manifestService,
        private readonly ThemeVirtualLayoutService $virtualLayoutService,
    ) {
    }

    public function getManifest()
    {
        try {
            $area = $this->normalizeArea((string)$this->request->getParam('area', 'frontend'));
            $themeId = (int)$this->request->getParam('theme_id', 0);
            if ($themeId <= 0) {
                $theme = clone $this->welineTheme;
                $theme->clearData()->clearQuery()->getActiveTheme($area);
                $themeId = (int)$theme->getId();
            }
            if ($themeId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('缺少主题ID'),
                ]);
            }

            $pageType = (string)$this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_DEFAULT);
            $summaryOnly = (bool)$this->request->getParam('summary', false);
            $manifest = $this->manifestService->build(
                $themeId,
                $area,
                $pageType,
                (bool)$this->request->getParam('force_reload', false)
            );

            return $this->fetchJson([
                'success' => true,
                'data' => $summaryOnly ? $this->manifestService->coverage($manifest) : $manifest,
            ]);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function getAiCatalog()
    {
        try {
            $adapterCode = trim((string)$this->request->getParam('adapter_code', self::ADAPTER_CODE)) ?: self::ADAPTER_CODE;
            $temporarySkillCodes = $this->normalizeCodeList($this->request->getParam('temporary_skill_codes', []));
            $temporaryStyleCodes = $this->normalizeCodeList($this->request->getParam('temporary_style_codes', []));
            $adminId = $this->currentAdminId();

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'adapter_code' => $adapterCode,
                    'skills' => $this->buildSkillCatalog($adapterCode, $temporarySkillCodes),
                    'styles' => $this->buildStyleCatalog($adapterCode, $temporaryStyleCodes, $adminId),
                ],
            ]);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function getSource()
    {
        try {
            $payload = $this->getPayload();
            $themeId = $this->resolveThemeId($payload);
            $area = $this->normalizeArea((string)($payload['area'] ?? 'frontend'));
            $layoutType = $this->normalizeLayoutType((string)($payload['layout_type'] ?? $payload['page_type'] ?? ThemeLayout::PAGE_TYPE_DEFAULT));
            $layoutOption = $this->normalizeLayoutOption((string)($payload['layout_option'] ?? 'default')) ?: 'default';
            if ($themeId <= 0 || $layoutType === '') {
                return $this->fetchJson(['success' => false, 'message' => __('缺少主题或布局身份')]);
            }

            $identity = $this->buildIdentity($payload, $themeId, $area, $layoutType, $layoutOption);
            $latest = $layoutOption !== 'default'
                ? $this->virtualLayoutService->loadLatestVersionDetails($identity)
                : null;
            if ($latest !== null) {
                return $this->fetchJson([
                    'success' => true,
                    'data' => $latest + [
                        'source' => 'theme_virtual_layout',
                        'editable' => true,
                        'versions' => $this->virtualLayoutService->listVersionDetails($identity),
                    ],
                ]);
            }

            $manifest = $this->manifestService->build($themeId, $area, $layoutType, false);
            $reference = $this->loadReferenceLayoutSource($manifest, $layoutType, 'default');
            if ($reference['source_code'] === '') {
                return $this->fetchJson(['success' => false, 'message' => __('未找到默认布局源码')]);
            }

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'source' => 'default_theme',
                    'editable' => false,
                    'source_code' => $reference['source_code'],
                    'identity' => $identity,
                    'reference' => $reference['entry'],
                    'versions' => [],
                ],
            ]);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }
    }

    public function postCreateDraft()
    {
        try {
            $payload = $this->getPayload();
            $result = $this->shouldCreateAllLayoutTypes($payload)
                ? $this->createVirtualLayoutDrafts('create', $payload)
                : $this->createVirtualLayoutDraft('create', $payload);

            return $this->fetchJson($result);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }
    }

    public function postBlockAction()
    {
        try {
            $payload = $this->getPayload();
            $action = $this->normalizeBlockAction((string)($payload['action'] ?? ''));
            if ($action === '') {
                return $this->fetchJson(['success' => false, 'message' => __('不支持的 AI block 操作')]);
            }
            if ($action === 'regenerate_images') {
                $assetRef = trim((string)($payload['asset_ref'] ?? $payload['asset_url'] ?? ''));
                if ($assetRef !== '' && !$this->isVirtualThemeOwnedAsset($assetRef)) {
                    return $this->fetchJson([
                        'success' => false,
                        'status' => 'asset_not_owned',
                        'message' => __('只能重新生成虚拟主题拥有的图片资源'),
                    ]);
                }
            }

            return $this->fetchJson($this->createVirtualLayoutDraft($action, $payload));
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }
    }

    public function postSaveSource()
    {
        try {
            $payload = $this->getPayload();
            $themeId = $this->resolveThemeId($payload);
            $area = $this->normalizeArea((string)($payload['area'] ?? 'frontend'));
            $layoutType = $this->normalizeLayoutType((string)($payload['layout_type'] ?? $payload['page_type'] ?? ThemeLayout::PAGE_TYPE_DEFAULT));
            $layoutOption = $this->normalizeLayoutOption((string)($payload['layout_option'] ?? '')) ?: 'default';
            $source = (string)($payload['source'] ?? $payload['source_code'] ?? '');
            $identity = $this->buildIdentity($payload, $themeId, $area, $layoutType, $layoutOption);
            $result = $this->virtualLayoutService->saveSourceVersion($identity, $source, [
                'source_type' => ThemeVirtualLayout::SOURCE_TYPE_VIRTUAL,
                'is_ai_generated' => false,
                'generation_meta' => [
                    'mode' => 'source_edit',
                    'request_id' => (string)($payload['request_id'] ?? ''),
                ],
                'reason' => (string)($payload['reason'] ?? __('保存虚拟布局源码草稿')),
                'actor_id' => (string)$this->currentAdminId(),
            ], $this->truthy($payload['publish'] ?? false));

            return $this->fetchJson($result);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }
    }

    public function postPublishVersion()
    {
        try {
            $payload = $this->getPayload();
            $versionId = (int)($payload['version_id'] ?? $payload['draft_version_id'] ?? 0);
            if ($versionId <= 0) {
                return $this->fetchJson(['success' => false, 'message' => __('缺少 version_id')]);
            }

            $published = $this->virtualLayoutService->publishVersion($versionId);
            return $this->fetchJson([
                'success' => $published,
                'message' => $published ? __('虚拟布局版本已发布') : __('虚拟布局版本发布失败'),
                'data' => $published ? $this->virtualLayoutService->loadVersionDetails($versionId) : null,
            ]);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }
    }

    public function postRollbackVersion()
    {
        try {
            $payload = $this->getPayload();
            $result = $this->virtualLayoutService->rollbackPublishedVersion(
                (int)($payload['asset_id'] ?? 0),
                (int)($payload['version_id'] ?? $payload['target_version_id'] ?? 0),
                [
                    'reason' => (string)($payload['reason'] ?? __('回滚虚拟主题布局版本')),
                    'actor_id' => (string)$this->currentAdminId(),
                ]
            );

            return $this->fetchJson($result);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }
    }

    private function normalizeArea(string $area): string
    {
        return strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
    }

    /**
     * @return array<string,mixed>
     */
    private function createVirtualLayoutDraft(string $mode, array $payload): array
    {
        $themeId = $this->resolveThemeId($payload);
        $area = $this->normalizeArea((string)($payload['area'] ?? 'frontend'));
        $layoutType = $this->normalizeLayoutType((string)($payload['layout_type'] ?? $payload['page_type'] ?? ThemeLayout::PAGE_TYPE_DEFAULT));
        $currentOption = $this->normalizeLayoutOption((string)($payload['layout_option'] ?? 'default')) ?: 'default';
        if ($themeId <= 0 || $layoutType === '') {
            return ['success' => false, 'message' => __('缺少主题或布局身份')];
        }

        $layoutOption = $currentOption !== 'default'
            ? $currentOption
            : $this->deriveVirtualLayoutOption($layoutType, $mode, $payload);
        $identity = $this->buildIdentity($payload, $themeId, $area, $layoutType, $layoutOption);
        $manifest = $this->manifestService->build($themeId, $area, $layoutType, false);
        $reference = $this->loadReferenceLayoutSource($manifest, $layoutType, 'default');
        if ($reference['source_code'] === '') {
            return ['success' => false, 'message' => __('未找到默认布局源码，无法创建虚拟主题草稿')];
        }

        $currentSource = $currentOption !== 'default'
            ? ($this->virtualLayoutService->loadEditableSource($layoutType, $currentOption, $identity) ?: $reference['source_code'])
            : $reference['source_code'];

        $selectedSkillCodes = $this->normalizeCodeList($payload['selected_skill_codes'] ?? $payload['skill_codes'] ?? []);
        $selectedStyleCodes = $this->normalizeCodeList($payload['selected_style_codes'] ?? $payload['style_codes'] ?? []);
        $styleSnapshot = $this->resolveStyleSnapshot($selectedStyleCodes, $this->currentAdminId());
        $blockContext = $this->buildBlockContext($payload);
        $useAi = $this->truthy($payload['use_ai'] ?? true);
        $prompt = $this->buildVirtualLayoutPrompt(
            $mode,
            $payload,
            $manifest,
            $reference,
            $currentSource,
            $selectedSkillCodes,
            $selectedStyleCodes,
            $styleSnapshot,
            $blockContext
        );

        $aiPayload = [];
        $nextSource = $currentSource;
        if ($useAi) {
            $aiResult = $this->generateVirtualLayoutSource(
                $mode,
                $prompt,
                $payload,
                $selectedSkillCodes,
                $styleSnapshot,
                $layoutType,
                $layoutOption
            );
            $nextSource = $aiResult['source_code'];
            $aiPayload = $aiResult['payload'];
        }

        $requestId = trim((string)($payload['request_id'] ?? ''));
        $result = $this->virtualLayoutService->saveSourceVersion($identity, $nextSource, [
            'source_type' => $useAi ? ThemeVirtualLayout::SOURCE_TYPE_AI : ThemeVirtualLayout::SOURCE_TYPE_VIRTUAL,
            'is_ai_generated' => $useAi,
            'ai_prompt' => $useAi ? $prompt : '',
            'generation_meta' => [
                'mode' => $mode,
                'request_id' => $requestId,
                'selected_skill_codes' => $selectedSkillCodes,
                'selected_style_codes' => $selectedStyleCodes,
                'style_snapshot' => $styleSnapshot,
                'manifest_fingerprint' => (string)($manifest['fingerprint'] ?? ''),
                'manifest_coverage' => $manifest['coverage'] ?? [],
                'reference' => [
                    'layout_type' => $layoutType,
                    'layout_option' => 'default',
                    'sha1' => sha1($reference['source_code']),
                    'entry' => $reference['entry'],
                ],
                'block_context' => $blockContext,
                'source_changed' => sha1($nextSource) !== sha1($currentSource),
                'ai_payload_keys' => array_keys($aiPayload),
            ],
            'validation' => [
                'valid' => true,
                'checks' => [
                    'default_reference_present' => true,
                    'stored_as_draft' => true,
                    'publish_required' => true,
                ],
            ],
            'reason' => $this->reasonForMode($mode),
            'actor_id' => (string)$this->currentAdminId(),
        ], false);

        if (!($result['success'] ?? false)) {
            return $result;
        }

        $versionId = (int)($result['version_id'] ?? 0);
        return [
            'success' => true,
            'message' => __('虚拟主题草稿已生成，发布前不会影响前台渲染'),
            'data' => $result + [
                'draft_version_id' => $versionId,
                'layout_option' => $layoutOption,
                'source_changed' => sha1($nextSource) !== sha1($currentSource),
                'source_code' => $nextSource,
                'reference_sha1' => sha1($reference['source_code']),
                'use_ai' => $useAi,
                'mode' => $mode,
            ],
        ];
    }

    private function shouldCreateAllLayoutTypes(array $payload): bool
    {
        $value = strtolower(trim((string)($payload['layout_type'] ?? $payload['page_type'] ?? '')));
        return $this->truthy($payload['all_layout_types'] ?? false)
            || in_array($value, ['all', '*', 'all_types', 'all_layout_types'], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function createVirtualLayoutDrafts(string $mode, array $payload): array
    {
        $themeId = $this->resolveThemeId($payload);
        $area = $this->normalizeArea((string)($payload['area'] ?? 'frontend'));
        if ($themeId <= 0) {
            return ['success' => false, 'message' => __('缺少参照主题')];
        }

        $layoutTypes = $this->collectDefaultLayoutTypes($themeId, $area);
        if ($layoutTypes === []) {
            return ['success' => false, 'message' => __('参照主题没有可生成的默认布局类型')];
        }

        $layoutOption = $this->resolveBatchLayoutOption($mode, $payload);
        $baseRequestId = trim((string)($payload['request_id'] ?? ''));
        if ($baseRequestId === '') {
            $baseRequestId = 'virtual-theme-' . $themeId . '-' . substr(sha1((string)microtime(true)), 0, 10);
        }

        $results = [];
        $failures = [];
        foreach ($layoutTypes as $layoutType) {
            $childPayload = $payload;
            $childPayload['layout_type'] = $layoutType;
            $childPayload['page_type'] = $layoutType;
            $childPayload['layout_option'] = $layoutOption;
            $childPayload['request_id'] = $baseRequestId . '|' . $layoutType;
            $childPayload['name'] = (string)($payload['name'] ?? ('AI Virtual Theme ' . $layoutOption));
            $childPayload['description'] = (string)($payload['description'] ?? __('AI 虚拟主题全类型草稿'));

            $result = $this->createVirtualLayoutDraft($mode, $childPayload);
            if (!($result['success'] ?? false)) {
                $failures[] = [
                    'layout_type' => $layoutType,
                    'message' => (string)($result['message'] ?? __('生成失败')),
                ];
                continue;
            }

            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $results[] = [
                'layout_type' => $layoutType,
                'asset_id' => (int)($data['asset_id'] ?? 0),
                'version_id' => (int)($data['version_id'] ?? 0),
                'draft_version_id' => (int)($data['draft_version_id'] ?? 0),
                'layout_option' => (string)($data['layout_option'] ?? $layoutOption),
                'source_changed' => (bool)($data['source_changed'] ?? false),
                'use_ai' => (bool)($data['use_ai'] ?? false),
            ];
        }

        if ($failures !== []) {
            return [
                'success' => false,
                'message' => __('虚拟主题部分布局类型生成失败'),
                'data' => [
                    'layout_option' => $layoutOption,
                    'layout_types' => $layoutTypes,
                    'created_count' => count($results),
                    'failed_count' => count($failures),
                    'results' => $results,
                    'failures' => $failures,
                ],
            ];
        }

        return [
            'success' => true,
            'message' => __('虚拟主题全部布局草稿已生成，发布前不会影响前台渲染'),
            'data' => [
                'layout_option' => $layoutOption,
                'layout_types' => $layoutTypes,
                'created_count' => count($results),
                'failed_count' => 0,
                'results' => $results,
            ],
        ];
    }

    private function resolveBatchLayoutOption(string $mode, array $payload): string
    {
        $requested = $this->normalizeLayoutOption((string)($payload['layout_option'] ?? ''));
        if ($requested !== '' && !in_array($requested, ['default', 'all', 'all_types', 'all_layout_types'], true)) {
            return $requested;
        }

        $requestId = trim((string)($payload['request_id'] ?? ''));
        $seed = $requestId !== ''
            ? $requestId
            : $mode . '|' . (string)($payload['theme_id'] ?? '') . '|' . microtime(true);
        return $this->normalizeLayoutOption('ai-theme-' . substr(sha1($seed), 0, 10));
    }

    /**
     * @return list<string>
     */
    private function collectDefaultLayoutTypes(int $themeId, string $area): array
    {
        $manifest = $this->manifestService->build($themeId, $area, ThemeLayout::PAGE_TYPE_DEFAULT, false);
        $types = [];
        foreach (($manifest['files']['layouts'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $layoutType = $this->defaultLayoutTypeFromEntry($entry);
            if ($layoutType !== '') {
                $types[$layoutType] = true;
            }
        }

        $layoutTypes = array_keys($types);
        sort($layoutTypes, SORT_STRING);
        return array_values($layoutTypes);
    }

    private function defaultLayoutTypeFromEntry(array $entry): string
    {
        foreach (['logical_path', 'relative_path'] as $key) {
            $path = trim(str_replace('\\', '/', (string)($entry[$key] ?? '')), '/');
            if ($path === '') {
                continue;
            }

            $path = (string)preg_replace('/\.[^.\/]+$/', '', $path);
            $segments = array_values(array_filter(explode('/', $path), static fn(string $segment): bool => $segment !== ''));
            if (($segments[0] ?? '') === 'layouts') {
                array_shift($segments);
            }

            $last = end($segments);
            if ($last !== 'default' || count($segments) < 2) {
                continue;
            }

            $layoutType = $this->normalizeLayoutType((string)$segments[count($segments) - 2]);
            if ($layoutType !== '') {
                return $layoutType;
            }
        }

        return '';
    }

    private function resolveThemeId(array $payload): int
    {
        $area = $this->normalizeArea((string)($payload['area'] ?? $this->request->getParam('area', 'frontend')));
        $themeId = (int)($payload['theme_id'] ?? $this->request->getParam('theme_id', 0));
        if ($themeId > 0) {
            return $themeId;
        }

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->getActiveTheme($area);
        return (int)$theme->getId();
    }

    /**
     * @return array<string,mixed>
     */
    private function buildIdentity(array $payload, int $themeId, string $area, string $layoutType, string $layoutOption): array
    {
        return [
            'theme_id' => $themeId,
            'area' => $area,
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
            'scope' => (string)($payload['scope'] ?? self::DEFAULT_SCOPE),
            'target_type' => (string)($payload['target_type'] ?? ThemeVirtualLayout::TARGET_GLOBAL),
            'target_id' => (int)($payload['target_id'] ?? 0),
            'name' => (string)($payload['name'] ?? ('AI ' . ucfirst($layoutType))),
            'description' => (string)($payload['description'] ?? __('AI 虚拟主题布局草稿')),
            'metadata' => [
                'virtual_theme' => true,
                'source_of_truth' => 'theme_virtual_layout',
                'reference_layout_option' => 'default',
                'request_id' => (string)($payload['request_id'] ?? ''),
            ],
        ];
    }

    /**
     * @return array{entry:array<string,mixed>,source_code:string}
     */
    private function loadReferenceLayoutSource(array $manifest, string $layoutType, string $layoutOption): array
    {
        $entry = $this->findLayoutEntry($manifest, $layoutType, $layoutOption)
            ?: $this->findLayoutEntry($manifest, $layoutType, 'default')
            ?: [];
        $path = (string)($entry['absolute_path'] ?? '');
        $source = $path !== '' && is_file($path) ? (string)file_get_contents($path) : '';

        return [
            'entry' => $entry,
            'source_code' => $source,
        ];
    }

    private function findLayoutEntry(array $manifest, string $layoutType, string $layoutOption): ?array
    {
        $layoutType = $this->normalizeLayoutType($layoutType);
        $layoutOption = $this->normalizeLayoutOption($layoutOption);
        $candidates = [
            'layouts/' . $layoutType . '/' . $layoutOption,
            'layouts/' . $layoutType . '/' . $layoutOption . '.phtml',
            $layoutType . '/' . $layoutOption . '.phtml',
        ];
        foreach (($manifest['files']['layouts'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $logical = trim((string)($entry['logical_path'] ?? ''), '/');
            $relative = trim((string)($entry['relative_path'] ?? ''), '/');
            if (in_array($logical, $candidates, true) || in_array($relative, $candidates, true)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param list<string> $skillCodes
     * @param list<string> $styleCodes
     * @param array<string,mixed> $styleSnapshot
     * @param array<string,mixed> $blockContext
     */
    private function buildVirtualLayoutPrompt(
        string $mode,
        array $payload,
        array $manifest,
        array $reference,
        string $currentSource,
        array $skillCodes,
        array $styleCodes,
        array $styleSnapshot,
        array $blockContext
    ): string {
        $instructions = trim((string)($payload['instructions'] ?? $payload['prompt'] ?? $payload['description'] ?? ''));
        $manifestSummary = $this->manifestPromptSummary($manifest);

        return "Create or update one Weline Theme virtual layout draft.\n"
            . "Operation: {$mode}\n"
            . "User instructions:\n" . ($instructions !== '' ? $instructions : '-') . "\n\n"
            . "Selected skill codes: " . json_encode($skillCodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            . "Selected design direction/style codes: " . json_encode($styleCodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
            . "Selected design direction snapshot:\n" . json_encode($styleSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
            . "Block hover context:\n" . json_encode($blockContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
            . "Default theme manifest summary. Treat this as the source of truth and preserve every capability the default theme exposes unless explicitly asked otherwise:\n"
            . json_encode($manifestSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
            . "Reference default layout source. The virtual layout must fully respect its slots, hooks, metadata, variables, and render conventions:\n<<<DEFAULT_LAYOUT_SOURCE\n"
            . $reference['source_code'] . "\nDEFAULT_LAYOUT_SOURCE\n\n"
            . "Current editable layout source:\n<<<CURRENT_LAYOUT_SOURCE\n"
            . $currentSource . "\nCURRENT_LAYOUT_SOURCE\n\n"
            . "Return exactly one JSON object with keys: source_code, name, description, asset_rewrites, notes.\n"
            . "source_code must contain the complete final .phtml virtual layout source. Do not output markdown or code fences.\n"
            . "Keep only layout skeleton, slots, hooks, taglib markup, PHP variables, markup, and styles. Do not include <script> tags, addEventListener, fetch, XMLHttpRequest, axios, EventSource, or new frontend request logic.\n";
    }

    /**
     * @return array{source_code:string,payload:array<string,mixed>}
     */
    private function generateVirtualLayoutSource(
        string $mode,
        string $prompt,
        array $payload,
        array $skillCodes,
        array $styleSnapshot,
        string $layoutType,
        string $layoutOption
    ): array {
        if (!function_exists('w_query')) {
            throw new \RuntimeException((string)__('AI 查询入口不可用。'));
        }

        $params = [
            'operation' => 'virtual_layout_' . $mode,
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
            'selected_skill_codes' => $skillCodes,
            'temporary_skill_codes' => $skillCodes,
            'design_direction_mode' => $styleSnapshot !== [] ? StyleService::MODE_MANUAL : (string)($payload['design_direction_mode'] ?? $payload['style_mode'] ?? StyleService::MODE_AUTO),
            'design_direction_snapshot' => $styleSnapshot,
            'style_snapshot' => $styleSnapshot,
            'site_title' => (string)($payload['site_title'] ?? $payload['title'] ?? ''),
            'brief_description' => (string)($payload['brief_description'] ?? $payload['instructions'] ?? $payload['prompt'] ?? ''),
            'disable_conversation_history' => true,
            'disable_conversation_persist' => true,
            'is_backend' => true,
        ];

        $response = w_query('ai', 'generate', [
            'prompt' => $prompt,
            'scenario_code' => self::ADAPTER_CODE,
            'params' => $params,
            'model_code' => isset($payload['model_code']) ? (string)$payload['model_code'] : null,
            'is_backend' => true,
        ]);
        if (!is_string($response)) {
            $response = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        $decoded = $this->extractJsonPayload($response);
        $source = '';
        if (is_array($decoded)) {
            foreach (['source_code', 'layout_source', 'source'] as $key) {
                if (is_scalar($decoded[$key] ?? null) && trim((string)$decoded[$key]) !== '') {
                    $source = (string)$decoded[$key];
                    break;
                }
            }
        }
        if ($source === '' && is_array($decoded)) {
            throw new \RuntimeException((string)__('AI 返回内容缺少 source_code 字段。'));
        }
        if ($source === '') {
            $source = trim($response);
        }
        $payloadMeta = is_array($decoded) ? $decoded : ['raw_response_sha1' => sha1($response)];
        $normalizedSource = $this->normalizeAiVirtualLayoutSource($source);
        if ($normalizedSource !== trim($source)) {
            $payloadMeta['_layout_runtime_sanitized'] = true;
        }
        $source = $normalizedSource;
        if ($source === '') {
            throw new \RuntimeException((string)__('AI 未返回虚拟主题源码。'));
        }

        return [
            'source_code' => $source,
            'payload' => $payloadMeta,
        ];
    }

    private function normalizeAiVirtualLayoutSource(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }
        if (str_starts_with($source, '```')) {
            $source = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $source) ?? $source;
            $source = preg_replace('/\s*```$/', '', $source) ?? $source;
            $source = trim($source);
        }

        return $this->stripForbiddenVirtualLayoutRuntime($source);
    }

    private function stripForbiddenVirtualLayoutRuntime(string $source): string
    {
        $cleaned = preg_replace('/<script\b[^>]*>.*?<\/script\s*>/is', '', $source) ?? $source;
        $cleaned = preg_replace('/<script\b[^>]*\/\s*>/is', '', $cleaned) ?? $cleaned;
        return trim($cleaned);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractJsonPayload(string $response): ?array
    {
        $text = trim($response);
        if ($text === '') {
            return null;
        }
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
            $text = trim($text);
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function manifestPromptSummary(array $manifest): array
    {
        $files = [];
        foreach (($manifest['files'] ?? []) as $category => $entries) {
            if (!is_array($entries)) {
                continue;
            }
            $files[$category] = array_map(static fn(array $entry): array => [
                'logical_path' => (string)($entry['logical_path'] ?? ''),
                'relative_path' => (string)($entry['relative_path'] ?? ''),
                'sha1' => (string)($entry['sha1'] ?? ''),
                'bytes' => (int)($entry['bytes'] ?? 0),
                'slot_ids' => is_array($entry['slot_ids'] ?? null) ? $entry['slot_ids'] : [],
                'asset_ref_count' => is_array($entry['asset_refs'] ?? null) ? count($entry['asset_refs']) : 0,
                'coverage_state' => (string)($entry['coverage_state'] ?? ''),
            ], $entries);
        }

        return [
            'theme' => $manifest['theme'] ?? [],
            'fingerprint' => (string)($manifest['fingerprint'] ?? ''),
            'summary' => $manifest['summary'] ?? [],
            'coverage' => $manifest['coverage'] ?? [],
            'extraction_quality' => $manifest['extraction_quality'] ?? [],
            'slots' => $manifest['slots'] ?? [],
            'asset_refs' => $manifest['asset_refs'] ?? [],
            'files' => $files,
        ];
    }

    private function normalizeLayoutType(string $layoutType): string
    {
        $layoutType = strtolower(trim($layoutType));
        $layoutType = preg_replace('/[^a-z0-9_-]+/', '_', $layoutType) ?? '';
        return trim($layoutType, '_');
    }

    private function normalizeLayoutOption(string $layoutOption): string
    {
        return $this->virtualLayoutService->normalizeLayoutOption($layoutOption);
    }

    private function deriveVirtualLayoutOption(string $layoutType, string $mode, array $payload): string
    {
        $requestId = trim((string)($payload['request_id'] ?? ''));
        $seed = $requestId !== ''
            ? $requestId
            : $layoutType . '|' . $mode . '|' . (string)($payload['layout_id'] ?? '') . '|' . microtime(true);
        return $this->normalizeLayoutOption('ai-' . $layoutType . '-' . substr(sha1($seed), 0, 10));
    }

    private function normalizeBlockAction(string $action): string
    {
        $action = strtolower(trim(str_replace('-', '_', $action)));
        return match ($action) {
            'ai_edit', 'edit', 'refine' => 'edit',
            'ai_rebuild', 'rebuild' => 'rebuild',
            'ai_image', 'image', 'regenerate_image', 'regenerate_images' => 'regenerate_images',
            default => '',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function buildBlockContext(array $payload): array
    {
        return [
            'layout_id' => (string)($payload['layout_id'] ?? ''),
            'slot_id' => (string)($payload['slot_id'] ?? ''),
            'widget_code' => (string)($payload['widget_code'] ?? ''),
            'widget_type' => (string)($payload['widget_type'] ?? ''),
            'asset_ref' => (string)($payload['asset_ref'] ?? $payload['asset_url'] ?? ''),
            'instructions' => (string)($payload['instructions'] ?? $payload['prompt'] ?? ''),
        ];
    }

    private function isVirtualThemeOwnedAsset(string $assetRef): bool
    {
        $normalized = strtolower(str_replace('\\', '/', trim($assetRef)));
        if ($normalized === '') {
            return false;
        }

        foreach ([
            '/media/theme/virtual/',
            '/media/virtual-theme/',
            '/theme-virtual/',
            '/theme_virtual/',
            'pub/media/theme/virtual/',
            'media/theme/virtual/',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function reasonForMode(string $mode): string
    {
        return match ($mode) {
            'edit' => (string)__('AI 编辑 block 后生成虚拟主题草稿'),
            'rebuild' => (string)__('AI 重建 block 后生成虚拟主题草稿'),
            'regenerate_images' => (string)__('AI 重新生成 block 图片资源后生成虚拟主题草稿'),
            default => (string)__('AI 生成虚拟主题草稿'),
        };
    }

    /**
     * @return list<string>
     */
    private function normalizeCodeList(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        $codes = [];
        foreach ($raw as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $code = trim((string)$item);
            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param list<string> $temporarySkillCodes
     * @return array<string,mixed>
     */
    private function buildSkillCatalog(string $adapterCode, array $temporarySkillCodes): array
    {
        try {
            /** @var AdapterSkillResolver $resolver */
            $resolver = ObjectManager::getInstance(AdapterSkillResolver::class);
            return $resolver->buildSkillCatalog($adapterCode, $temporarySkillCodes, false);
        } catch (\Throwable $throwable) {
            return [
                'items' => [],
                'default_skill_codes' => [],
                'warnings' => [$throwable->getMessage()],
            ];
        }
    }

    /**
     * @param list<string> $temporaryStyleCodes
     * @return array<string,mixed>
     */
    private function buildStyleCatalog(string $adapterCode, array $temporaryStyleCodes, int $adminId): array
    {
        try {
            /** @var AdapterStyleResolver $resolver */
            $resolver = ObjectManager::getInstance(AdapterStyleResolver::class);
            return $resolver->buildStyleCatalog($adapterCode, $temporaryStyleCodes, $adminId, false);
        } catch (\Throwable $throwable) {
            return [
                'items' => [],
                'default_style_codes' => [],
                'manual_style_codes' => [],
                'warnings' => [$throwable->getMessage()],
            ];
        }
    }

    /**
     * @param list<string> $styleCodes
     * @return array<string,mixed>
     */
    private function resolveStyleSnapshot(array $styleCodes, int $adminId): array
    {
        $styleCode = $styleCodes[0] ?? '';
        if ($styleCode === '') {
            return [];
        }
        try {
            /** @var StyleRegistry $registry */
            $registry = ObjectManager::getInstance(StyleRegistry::class);
            $style = $registry->getStyle($styleCode, $adminId, false);
            if (empty($style['exists']) || (string)($style['status'] ?? '') !== AiStyle::STATUS_ACTIVE) {
                return [];
            }

            /** @var StyleService $styleService */
            $styleService = ObjectManager::getInstance(StyleService::class);
            return $styleService->buildSnapshot($style, 'Theme visual editor selection');
        } catch (\Throwable) {
            return [];
        }
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function currentAdminId(): int
    {
        try {
            return max(0, (int)$this->getLoginUserId());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function getPayload(): array
    {
        $params = $this->request->getParams();
        $bodyParams = $this->request->getBodyParams();
        if (is_array($bodyParams) && $bodyParams !== []) {
            $params = array_replace($params, $bodyParams);
        } elseif (is_string($bodyParams) && trim($bodyParams) !== '') {
            $decoded = json_decode($bodyParams, true);
            if (is_array($decoded)) {
                $params = array_replace($params, $decoded);
            }
        }

        $rawBody = file_get_contents('php://input');
        if (is_string($rawBody) && trim($rawBody) !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $params = array_replace($params, $decoded);
            }
        }

        return is_array($params) ? $params : [];
    }
}
