<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Resumable;

use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemePreviewGenerator;

/**
 * Freezes and executes one Theme preview target for the resumable runtime.
 *
 * This class deliberately owns only data that can be reconstructed by a new
 * CLI Runner. It never retains an HTTP request, an SSE connection, a process
 * resource, or a browser session.
 */
class ThemePreviewTaskProcessor
{
    public function __construct(private readonly WelineTheme $themeModel)
    {
    }

    /**
     * @return list<array{key:string,theme_id:int,area:string,force:bool,capture_base_url:?string}>
     */
    public function freezeTargets(?int $themeId, ?string $area, bool $force, ?string $captureBaseUrl = null): array
    {
        $areas = $this->areas($area);
        $captureBaseUrl = ThemePreviewGenerator::normalizeCaptureBaseUrl($captureBaseUrl);
        if ($themeId !== null) {
            $theme = $this->loadTheme($themeId);
            if (!$theme->getId()) {
                throw new \InvalidArgumentException((string)__('主题不存在'));
            }

            return $this->targetsForTheme($theme, $areas, $force, $captureBaseUrl);
        }

        $items = (clone $this->themeModel)->clearData()->clearQuery()->select()->fetch()->getItems();
        $targets = [];
        foreach ($items as $item) {
            $id = is_object($item) && method_exists($item, 'getId')
                ? (int)$item->getId()
                : (int)($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $targets = array_merge($targets, $this->targetsForTheme($this->loadTheme($id), $areas, $force, $captureBaseUrl));
        }

        usort($targets, static fn(array $left, array $right): int => [$left['theme_id'], $left['area']] <=> [$right['theme_id'], $right['area']]);
        return $targets;
    }

    /**
     * @param array{key:string,theme_id:int,area:string,force:bool,capture_base_url?:?string} $target
     * @return array{key:string,theme_id:int,area:string,success:bool,image_path?:string,image_url?:string,error?:string}
     */
    public function runTarget(array $target, ?callable $heartbeat = null): array
    {
        $themeId = (int)($target['theme_id'] ?? 0);
        $area = $this->normalizeArea((string)($target['area'] ?? ''));
        if ($themeId <= 0 || $area === null) {
            throw new \InvalidArgumentException('Invalid frozen Theme preview target.');
        }

        $theme = $this->loadTheme($themeId);
        if (!$theme->getId()) {
            throw new \InvalidArgumentException((string)__('主题不存在'));
        }
        if (!$this->themeSupportsArea($theme, $area)) {
            throw new \InvalidArgumentException((string)__('主题不支持 %{1} 区域', [$area]));
        }

        $heartbeat?->__invoke();
        $imagePath = ThemePreviewGenerator::generatePreviewImage(
            $theme,
            $area,
            (bool)($target['force'] ?? false),
            isset($target['capture_base_url']) ? (string)$target['capture_base_url'] : null,
        );
        $heartbeat?->__invoke();
        if ($imagePath === false) {
            throw new \RuntimeException((string)__('预览图生成失败'));
        }

        $relativePath = ThemePreviewGenerator::normalizePreviewRelativePath($imagePath);
        if ($area === 'backend') {
            $theme->setBackendPreviewImage($relativePath);
        } else {
            $theme->setFrontendPreviewImage($relativePath)->setPreviewImage($relativePath);
        }
        $theme->save();

        return [
            'key' => $this->targetKey($themeId, $area),
            'theme_id' => $themeId,
            'area' => $area,
            'success' => true,
            'image_path' => $relativePath,
            'image_url' => '/' . $relativePath,
        ];
    }

    private function loadTheme(int $themeId): WelineTheme
    {
        $theme = clone $this->themeModel;
        $theme->clearData()->clearQuery()->load($themeId);
        return $theme;
    }

    /**
     * @param list<string> $areas
     * @return list<array{key:string,theme_id:int,area:string,force:bool,capture_base_url:?string}>
     */
    private function targetsForTheme(WelineTheme $theme, array $areas, bool $force, ?string $captureBaseUrl = null): array
    {
        if (!$theme->getId()) {
            return [];
        }

        $targets = [];
        foreach ($areas as $area) {
            if (!$this->themeSupportsArea($theme, $area)) {
                continue;
            }
            // force=false：已有预览文件且库中已登记路径时跳过，供页面自动补缺使用。
            if (!$force && $this->themeAlreadyHasPreview($theme, $area)) {
                continue;
            }
            $targets[] = [
                'key' => $this->targetKey((int)$theme->getId(), $area),
                'theme_id' => (int)$theme->getId(),
                'area' => $area,
                'force' => $force,
                'capture_base_url' => $captureBaseUrl,
            ];
        }
        return $targets;
    }

    /** @return list<string> */
    private function areas(?string $area): array
    {
        if ($area === null || trim($area) === '') {
            return ['frontend', 'backend'];
        }
        $normalized = $this->normalizeArea($area);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Invalid Theme preview area.');
        }
        return [$normalized];
    }

    private function normalizeArea(string $area): ?string
    {
        $area = strtolower(trim($area));
        return in_array($area, ['frontend', 'backend'], true) ? $area : null;
    }

    private function themeSupportsArea(WelineTheme $theme, string $area): bool
    {
        $basePath = rtrim($theme->getPath(), '/\\');
        if ($basePath === '') {
            return false;
        }

        return is_dir($basePath . DS . $area)
            || is_dir($basePath . DS . 'view' . DS . 'theme' . DS . $area)
            || is_dir($basePath . DS . 'theme' . DS . $area);
    }

    private function themeAlreadyHasPreview(WelineTheme $theme, string $area): bool
    {
        $themeId = (int)$theme->getId();
        if ($themeId <= 0) {
            return false;
        }

        $dbPath = $area === 'backend'
            ? trim((string)($theme->getBackendPreviewImage() ?? ''))
            : trim((string)($theme->getFrontendPreviewImage() ?? $theme->getPreviewImage() ?? ''));
        if ($dbPath === '') {
            return false;
        }

        return is_file(ThemePreviewGenerator::getPreviewImagePath($themeId, $area));
    }

    private function targetKey(int $themeId, string $area): string
    {
        return 'theme_' . $themeId . '_' . $area;
    }
}
