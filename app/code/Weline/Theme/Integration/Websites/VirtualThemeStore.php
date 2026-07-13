<?php

declare(strict_types=1);

namespace Weline\Theme\Integration\Websites;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeAiDraftService;
use Weline\Websites\Api\AiWorkbench\VirtualThemeStoreInterface;

final class VirtualThemeStore implements VirtualThemeStoreInterface
{
    public function saveTheme(
        int $themeId,
        int $sessionId,
        string $themeName,
        array $configPatch,
    ): ?array {
        $theme = $this->loadOrCreateTheme($themeId, $sessionId, $themeName);
        $config = \is_array($theme->getConfig()) ? $theme->getConfig() : [];
        $config = \array_replace($config, $configPatch);
        $theme->setName($themeName);
        $theme->setModuleName('Weline_Websites');
        $theme->setConfig($config);
        $theme->save();

        return [
            'theme_id' => (int)$theme->getId(),
            'config' => $config,
        ];
    }

    public function savePageTypeLayout(
        int $themeId,
        int $sessionId,
        string $themeName,
        string $pageType,
        array $layoutPayload,
    ): ?array {
        $theme = $this->loadOrCreateTheme($themeId, $sessionId, $themeName);
        if ((int)$theme->getId() <= 0) {
            return null;
        }
        $config = \is_array($theme->getConfig()) ? $theme->getConfig() : [];
        $layouts = \is_array($config['virtual_page_layouts'] ?? null)
            ? $config['virtual_page_layouts']
            : [];
        $layouts[$pageType] = $layoutPayload;
        $config['virtual_page_layouts'] = $layouts;
        $theme->setConfig($config)->save();

        return [
            'theme_id' => (int)$theme->getId(),
            'page_type' => $pageType,
            'layout' => $layoutPayload,
        ];
    }

    public function saveComponent(int $themeId, array $payload, string $publicId): ?array
    {
        /** @var ThemeAiDraftService $draftService */
        $draftService = ObjectManager::getInstance(ThemeAiDraftService::class);
        $draftVersion = $draftService->saveDraft([
            'theme_id' => $themeId,
            'area' => 'frontend',
            'category' => (string)($payload['category'] ?? 'content'),
            'component_code' => (string)($payload['component_code'] ?? ''),
            'name' => (string)($payload['name'] ?? 'AI Component'),
            'description' => (string)($payload['description'] ?? ''),
            'meta' => \is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            'is_ai_generated' => true,
            'source_type' => 'virtual',
        ], [
            'template_content' => (string)($payload['template_content'] ?? ''),
            'generation_meta' => [
                'source' => 'ai_site_workbench',
                'public_id' => $publicId,
            ],
        ]);
        $component = $draftService->publishDraft((int)$draftVersion->getId());

        return [
            'component_id' => (int)$component->getId(),
            'component_code' => (string)$component->getComponentCode(),
            'version_id' => (int)$draftVersion->getId(),
        ];
    }

    private function loadOrCreateTheme(int $themeId, int $sessionId, string $themeName): WelineTheme
    {
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->clearData()->clearQuery();
        if ($themeId > 0) {
            $theme->load($themeId);
        }
        if ((int)$theme->getId() <= 0) {
            $slug = $this->slugify($themeName !== '' ? $themeName : ('ai-site-' . $sessionId));
            $theme->setPath('ai/workbench-' . $sessionId . '-' . $slug . '-' . \substr(\md5((string)\microtime(true)), 0, 8));
            $theme->setIsActive(false);
            $theme->setData('is_active_frontend', 0);
            $theme->setData('is_active_backend', 0);
        }
        return $theme;
    }

    private function slugify(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = (string)\preg_replace('/[^a-z0-9]+/', '-', $value);
        return \trim($value, '-') !== '' ? \trim($value, '-') : 'theme';
    }
}
