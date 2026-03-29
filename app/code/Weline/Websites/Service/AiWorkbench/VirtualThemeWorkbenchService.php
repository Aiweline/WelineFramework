<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\AiSiteBuilderEvent;
use Weline\Websites\Model\AiSiteBuilderSession;

class VirtualThemeWorkbenchService
{
    public function __construct(
        private readonly SessionService $sessionService,
        private readonly EventStreamService $eventStreamService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{success:bool,message:string,data?:array<string,mixed>}
     */
    public function saveVirtualThemeByPublicId(string $publicId, int $adminUserId, array $payload): array
    {
        $session = $this->sessionService->loadByPublicId($publicId, $adminUserId);
        if ($session === null) {
            return ['success' => false, 'message' => (string)__('会话不存在或无访问权限')];
        }

        $scope = $session->getScopeArray();
        $themeId = (int)($payload['weline_theme_id'] ?? $scope['weline_theme_id'] ?? 0);
        $themeName = \trim((string)($payload['virtual_theme_name'] ?? $scope['virtual_theme_name'] ?? ''));
        $styleDirection = \trim((string)($payload['theme_style_direction'] ?? $scope['theme_style_direction'] ?? ''));
        $colorScheme = \trim((string)($payload['theme_color_scheme'] ?? $scope['theme_color_scheme'] ?? ''));
        $pageTypes = $this->normalizeStringList($payload['page_types'] ?? $scope['page_types'] ?? []);
        $pageLayouts = $this->normalizeArray($payload['page_type_layouts'] ?? $scope['page_type_layouts'] ?? []);

        if ($themeName === '') {
            $themeName = 'ai-site-' . $session->getId();
        }

        $theme = $this->loadOrCreateTheme($themeId, $session->getId(), $themeName);
        if ($theme === null) {
            return ['success' => false, 'message' => (string)__('当前环境未启用主题模块，无法保存虚拟主题')];
        }

        $config = \is_array($theme->getConfig()) ? $theme->getConfig() : [];
        $config['virtual_theme_name'] = $themeName;
        $config['theme_style_direction'] = $styleDirection;
        $config['theme_color_scheme'] = $colorScheme;
        $config['selected_page_types'] = $pageTypes;
        $config['virtual_page_layouts'] = $pageLayouts;
        $config['source'] = 'ai_site_workbench';
        $config['scope_public_id'] = $session->getPublicId();
        $config['scope_session_id'] = $session->getId();

        $theme->setName($themeName);
        $theme->setModuleName('Weline_Websites');
        $theme->setConfig($config);
        $theme->save();

        $scopePatch = [
            'weline_theme_id' => (int)$theme->getId(),
            'virtual_theme_name' => $themeName,
            'theme_style_direction' => $styleDirection,
            'theme_color_scheme' => $colorScheme,
            'page_types' => $pageTypes,
            'page_type_layouts' => $pageLayouts,
            'theme_source_mode' => 'database_virtual_theme',
        ];
        $this->sessionService->mergeScope($session->getId(), $adminUserId, $scopePatch);

        $fresh = $this->sessionService->loadById($session->getId(), $adminUserId) ?? $session;
        $this->appendEvent($fresh, $adminUserId, 'virtual_theme_saved', [
            'weline_theme_id' => (int)$theme->getId(),
            'virtual_theme_name' => $themeName,
            'page_types' => $pageTypes,
        ]);

        return [
            'success' => true,
            'message' => (string)__('虚拟主题已保存到数据库'),
            'data' => [
                'weline_theme_id' => (int)$theme->getId(),
                'virtual_theme_name' => $themeName,
                'page_types' => $pageTypes,
                'page_type_layouts' => $pageLayouts,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $layoutPayload
     * @return array{success:bool,message:string,data?:array<string,mixed>}
     */
    public function savePageTypeLayoutByPublicId(string $publicId, int $adminUserId, string $pageType, array $layoutPayload): array
    {
        $session = $this->sessionService->loadByPublicId($publicId, $adminUserId);
        if ($session === null) {
            return ['success' => false, 'message' => (string)__('会话不存在或无访问权限')];
        }

        $pageType = $this->normalizePageType($pageType);
        if ($pageType === '') {
            return ['success' => false, 'message' => (string)__('页面类型不能为空')];
        }

        $scope = $session->getScopeArray();
        $themeId = (int)($scope['weline_theme_id'] ?? 0);
        $themeName = \trim((string)($scope['virtual_theme_name'] ?? ''));
        if ($themeId <= 0) {
            $saveResult = $this->saveVirtualThemeByPublicId($publicId, $adminUserId, [
                'virtual_theme_name' => $themeName !== '' ? $themeName : ('ai-site-' . $session->getId()),
            ]);
            if (!$saveResult['success']) {
                return $saveResult;
            }
            $themeId = (int)($saveResult['data']['weline_theme_id'] ?? 0);
        }

        $theme = $this->loadOrCreateTheme($themeId, $session->getId(), $themeName !== '' ? $themeName : ('ai-site-' . $session->getId()));
        if ($theme === null || (int)$theme->getId() <= 0) {
            return ['success' => false, 'message' => (string)__('无法加载虚拟主题')];
        }

        $config = \is_array($theme->getConfig()) ? $theme->getConfig() : [];
        $layouts = $this->normalizeArray($config['virtual_page_layouts'] ?? []);
        $layouts[$pageType] = $layoutPayload;
        $config['virtual_page_layouts'] = $layouts;
        $theme->setConfig($config)->save();

        $scopeLayouts = $this->normalizeArray($scope['page_type_layouts'] ?? []);
        $scopeLayouts[$pageType] = $layoutPayload;
        $scopePageTypes = $this->normalizeStringList($scope['page_types'] ?? []);
        if (!\in_array($pageType, $scopePageTypes, true)) {
            $scopePageTypes[] = $pageType;
        }
        $this->sessionService->mergeScope($session->getId(), $adminUserId, [
            'weline_theme_id' => (int)$theme->getId(),
            'page_types' => \array_values(\array_unique($scopePageTypes)),
            'page_type_layouts' => $scopeLayouts,
        ]);

        $fresh = $this->sessionService->loadById($session->getId(), $adminUserId) ?? $session;
        $this->appendEvent($fresh, $adminUserId, 'page_type_layout_saved', [
            'weline_theme_id' => (int)$theme->getId(),
            'page_type' => $pageType,
        ]);

        return [
            'success' => true,
            'message' => (string)__('页面类型布局已保存到数据库虚拟主题'),
            'data' => [
                'weline_theme_id' => (int)$theme->getId(),
                'page_type' => $pageType,
                'layout' => $layoutPayload,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{success:bool,message:string,data?:array<string,mixed>}
     */
    public function saveVirtualComponentByPublicId(string $publicId, int $adminUserId, array $payload): array
    {
        $session = $this->sessionService->loadByPublicId($publicId, $adminUserId);
        if ($session === null) {
            return ['success' => false, 'message' => (string)__('会话不存在或无访问权限')];
        }

        $scope = $session->getScopeArray();
        $themeId = (int)($payload['weline_theme_id'] ?? $scope['weline_theme_id'] ?? 0);
        if ($themeId <= 0) {
            return ['success' => false, 'message' => (string)__('请先保存虚拟主题，再保存组件')];
        }

        if (!\class_exists(\Weline\Theme\Service\ThemeAiDraftService::class)) {
            return ['success' => false, 'message' => (string)__('主题 AI 草稿服务不可用')];
        }

        $componentCode = \trim((string)($payload['component_code'] ?? ''));
        $componentName = \trim((string)($payload['name'] ?? $payload['component_name'] ?? 'AI Component'));
        $category = \trim((string)($payload['category'] ?? 'content'));
        $templateContent = (string)($payload['template_content'] ?? '');
        if ($templateContent === '') {
            return ['success' => false, 'message' => (string)__('组件模板内容不能为空')];
        }

        /** @var \Weline\Theme\Service\ThemeAiDraftService $draftService */
        $draftService = ObjectManager::getInstance(\Weline\Theme\Service\ThemeAiDraftService::class);
        $draftVersion = $draftService->saveDraft([
            'theme_id' => $themeId,
            'area' => 'frontend',
            'category' => $category,
            'component_code' => $componentCode,
            'name' => $componentName,
            'description' => (string)($payload['description'] ?? ''),
            'meta' => $this->normalizeArray($payload['meta'] ?? []),
            'is_ai_generated' => true,
            'source_type' => 'virtual',
        ], [
            'template_content' => $templateContent,
            'generation_meta' => [
                'source' => 'ai_site_workbench',
                'public_id' => $session->getPublicId(),
            ],
        ]);
        $component = $draftService->publishDraft((int)$draftVersion->getId());

        $this->appendEvent($session, $adminUserId, 'virtual_component_saved', [
            'weline_theme_id' => $themeId,
            'component_code' => $component->getComponentCode(),
            'component_id' => $component->getId(),
            'version_id' => $draftVersion->getId(),
        ]);

        return [
            'success' => true,
            'message' => (string)__('AI 组件已保存并发布到虚拟主题'),
            'data' => [
                'weline_theme_id' => $themeId,
                'component_id' => (int)$component->getId(),
                'component_code' => $component->getComponentCode(),
                'version_id' => (int)$draftVersion->getId(),
            ],
        ];
    }

    private function loadOrCreateTheme(int $themeId, int $sessionId, string $themeName): ?object
    {
        if (!\class_exists(\Weline\Theme\Model\WelineTheme::class)) {
            return null;
        }

        /** @var object $theme */
        $theme = ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class);
        $theme->clearData()->clearQuery();
        if ($themeId > 0) {
            $theme->load($themeId);
        }

        if ((int)$theme->getId() <= 0) {
            $slug = $this->slugify($themeName !== '' ? $themeName : ('ai-site-' . $sessionId));
            $theme->setPath('ai/workbench-' . $sessionId . '-' . $slug . '-' . \substr(\md5((string)\microtime(true)), 0, 8));
            $theme->setIsActive(false);
            if (\method_exists($theme, 'setData')) {
                $theme->setData('is_active_frontend', 0);
                $theme->setData('is_active_backend', 0);
            }
        }

        return $theme;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendEvent(AiSiteBuilderSession $session, int $adminUserId, string $eventType, array $payload): void
    {
        $this->eventStreamService->appendEvent(
            $session->getId(),
            $adminUserId,
            $session->getCurrentStage() !== '' ? $session->getCurrentStage() : 'generate',
            $eventType,
            $payload,
            AiSiteBuilderEvent::LEVEL_INFO
        );
    }

    private function normalizePageType(string $pageType): string
    {
        $pageType = \strtolower(\trim($pageType));
        if ($pageType === '') {
            return '';
        }

        return (string)\preg_replace('/[^a-z0-9_\\-]+/', '', $pageType);
    }

    private function slugify(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = (string)\preg_replace('/[^a-z0-9]+/', '-', $value);
        return \trim($value, '-') !== '' ? \trim($value, '-') : 'theme';
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (\is_string($value) && \trim($value) !== '') {
            try {
                $decoded = \json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
            return \is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        $items = [];
        if (\is_array($value)) {
            $items = $value;
        } elseif (\is_string($value) && \trim($value) !== '') {
            $decoded = \json_decode($value, true);
            $items = \is_array($decoded) ? $decoded : \preg_split('/[\\s,]+/', $value, -1, \PREG_SPLIT_NO_EMPTY);
        }

        $result = [];
        foreach ($items as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $val = $this->normalizePageType((string)$item);
            if ($val === '' || \in_array($val, $result, true)) {
                continue;
            }
            $result[] = $val;
        }
        return $result;
    }
}

