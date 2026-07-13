<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Websites\Api\AiWorkbench\VirtualThemeStoreInterface;
use Weline\Websites\Model\AiSiteBuilderEvent;
use Weline\Websites\Model\AiSiteBuilderSession;

class VirtualThemeWorkbenchService
{
    private bool $themeStoreResolved = false;
    private ?VirtualThemeStoreInterface $themeStore = null;

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

        $themeStore = $this->themeStore();
        if ($themeStore === null) {
            return ['success' => false, 'message' => (string)__('当前环境未启用主题模块，无法保存虚拟主题')];
        }

        $themeResult = $themeStore->saveTheme($themeId, $session->getId(), $themeName, [
            'virtual_theme_name' => $themeName,
            'theme_style_direction' => $styleDirection,
            'theme_color_scheme' => $colorScheme,
            'selected_page_types' => $pageTypes,
            'virtual_page_layouts' => $pageLayouts,
            'source' => 'ai_site_workbench',
            'scope_public_id' => $session->getPublicId(),
            'scope_session_id' => $session->getId(),
        ]);
        if ($themeResult === null || (int)($themeResult['theme_id'] ?? 0) <= 0) {
            return ['success' => false, 'message' => (string)__('无法保存虚拟主题')];
        }
        $savedThemeId = (int)$themeResult['theme_id'];

        $scopePatch = [
            'weline_theme_id' => $savedThemeId,
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
            'weline_theme_id' => $savedThemeId,
            'virtual_theme_name' => $themeName,
            'page_types' => $pageTypes,
        ]);

        return [
            'success' => true,
            'message' => (string)__('虚拟主题已保存到数据库'),
            'data' => [
                'weline_theme_id' => $savedThemeId,
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

        $themeStore = $this->themeStore();
        $themeResult = $themeStore?->savePageTypeLayout(
            $themeId,
            $session->getId(),
            $themeName !== '' ? $themeName : ('ai-site-' . $session->getId()),
            $pageType,
            $layoutPayload,
        );
        if ($themeResult === null || (int)($themeResult['theme_id'] ?? 0) <= 0) {
            return ['success' => false, 'message' => (string)__('无法加载虚拟主题')];
        }
        $themeId = (int)$themeResult['theme_id'];

        $scopeLayouts = $this->normalizeArray($scope['page_type_layouts'] ?? []);
        $scopeLayouts[$pageType] = $layoutPayload;
        $scopePageTypes = $this->normalizeStringList($scope['page_types'] ?? []);
        if (!\in_array($pageType, $scopePageTypes, true)) {
            $scopePageTypes[] = $pageType;
        }
        $this->sessionService->mergeScope($session->getId(), $adminUserId, [
            'weline_theme_id' => $themeId,
            'page_types' => \array_values(\array_unique($scopePageTypes)),
            'page_type_layouts' => $scopeLayouts,
        ]);

        $fresh = $this->sessionService->loadById($session->getId(), $adminUserId) ?? $session;
        $this->appendEvent($fresh, $adminUserId, 'page_type_layout_saved', [
            'weline_theme_id' => $themeId,
            'page_type' => $pageType,
        ]);

        return [
            'success' => true,
            'message' => (string)__('页面类型布局已保存到数据库虚拟主题'),
            'data' => [
                'weline_theme_id' => $themeId,
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

        $themeStore = $this->themeStore();
        if ($themeStore === null) {
            return ['success' => false, 'message' => (string)__('主题 AI 草稿服务不可用')];
        }

        $componentCode = \trim((string)($payload['component_code'] ?? ''));
        $componentName = \trim((string)($payload['name'] ?? $payload['component_name'] ?? 'AI Component'));
        $category = \trim((string)($payload['category'] ?? 'content'));
        $templateContent = (string)($payload['template_content'] ?? '');
        if ($templateContent === '') {
            return ['success' => false, 'message' => (string)__('组件模板内容不能为空')];
        }

        $componentResult = $themeStore->saveComponent($themeId, [
            'category' => $category,
            'component_code' => $componentCode,
            'name' => $componentName,
            'description' => (string)($payload['description'] ?? ''),
            'meta' => $this->normalizeArray($payload['meta'] ?? []),
            'template_content' => $templateContent,
        ], $session->getPublicId());
        if ($componentResult === null) {
            return ['success' => false, 'message' => (string)__('主题 AI 草稿服务不可用')];
        }

        $this->appendEvent($session, $adminUserId, 'virtual_component_saved', [
            'weline_theme_id' => $themeId,
            'component_code' => (string)$componentResult['component_code'],
            'component_id' => (int)$componentResult['component_id'],
            'version_id' => (int)$componentResult['version_id'],
        ]);

        return [
            'success' => true,
            'message' => (string)__('AI 组件已保存并发布到虚拟主题'),
            'data' => [
                'weline_theme_id' => $themeId,
                'component_id' => (int)$componentResult['component_id'],
                'component_code' => (string)$componentResult['component_code'],
                'version_id' => (int)$componentResult['version_id'],
            ],
        ];
    }

    private function themeStore(): ?VirtualThemeStoreInterface
    {
        if ($this->themeStoreResolved) {
            return $this->themeStore;
        }
        $this->themeStoreResolved = true;
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(VirtualThemeStoreInterface::class);
        $this->themeStore = $provider instanceof VirtualThemeStoreInterface ? $provider : null;
        return $this->themeStore;
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
