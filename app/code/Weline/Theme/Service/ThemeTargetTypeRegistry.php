<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\TargetTypeProviderInterface;
use Weline\Theme\Model\ThemeVirtualLayout;

class ThemeTargetTypeRegistry
{
    private const EXTENDS_PREFIX = 'extends/module/weline_theme/targettype/';

    /** @var array<string, TargetTypeProviderInterface>|null */
    private ?array $providers = null;

    public function normalize(string $targetType): string
    {
        $targetType = strtolower(trim($targetType));
        if ($targetType === '') {
            return ThemeVirtualLayout::TARGET_GLOBAL;
        }

        return $this->has($targetType) ? $targetType : ThemeVirtualLayout::TARGET_GLOBAL;
    }

    public function normalizeForWrite(string $targetType): string
    {
        $targetType = strtolower(trim($targetType));
        if ($targetType === '') {
            throw new \InvalidArgumentException((string)__('目标类型不能为空'));
        }
        if (!$this->has($targetType)) {
            throw new \InvalidArgumentException((string)__('未注册的主题目标类型：%{1}', [$targetType]));
        }

        return $targetType;
    }

    public function has(string $targetType): bool
    {
        $targetType = strtolower(trim($targetType));
        return $targetType !== '' && isset($this->all()[$targetType]);
    }

    public function get(string $targetType): ?TargetTypeProviderInterface
    {
        $targetType = strtolower(trim($targetType));
        return $this->all()[$targetType] ?? null;
    }

    /**
     * @return array<string, TargetTypeProviderInterface>
     */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = $this->builtInProviders();
        foreach (ExtendsData::getExtendedBy('Weline_Theme') as $extensions) {
            foreach ($extensions as $extension) {
                $relativePath = strtolower(str_replace('\\', '/', (string)($extension['relative_path'] ?? '')));
                if (!str_starts_with($relativePath, self::EXTENDS_PREFIX)) {
                    continue;
                }

                $provider = $this->instantiateProvider($extension);
                if (!$provider instanceof TargetTypeProviderInterface) {
                    continue;
                }

                $code = strtolower(trim($provider->getCode()));
                if ($code !== '') {
                    $providers[$code] = $provider;
                }
            }
        }

        return $this->providers = $providers;
    }

    public function clear(): void
    {
        $this->providers = null;
    }

    /**
     * @param array<string,mixed> $extension
     */
    private function instantiateProvider(array $extension): ?TargetTypeProviderInterface
    {
        $sourceFile = (string)($extension['source_file'] ?? '');
        $className = trim((string)($extension['class_name'] ?? ''));
        if ($className === '') {
            $className = $this->resolveClassName($sourceFile);
        }
        if ($className === '') {
            return null;
        }

        if (!class_exists($className, false) && $sourceFile !== '' && is_file($sourceFile)) {
            require_once $sourceFile;
        }
        if (!class_exists($className)) {
            return null;
        }

        try {
            $provider = ObjectManager::getInstance($className);
        } catch (\Throwable) {
            return null;
        }

        return $provider instanceof TargetTypeProviderInterface ? $provider : null;
    }

    private function resolveClassName(string $sourceFile): string
    {
        if ($sourceFile === '' || !is_file($sourceFile)) {
            return '';
        }

        $content = file_get_contents($sourceFile);
        if ($content === false) {
            return '';
        }

        $namespace = '';
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = trim((string)$matches[1]);
        }

        $class = '';
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $class = trim((string)$matches[1]);
        }

        return $namespace !== '' && $class !== '' ? $namespace . '\\' . $class : '';
    }

    /**
     * @return array<string, TargetTypeProviderInterface>
     */
    private function builtInProviders(): array
    {
        $providers = [];
        foreach ([
            new BuiltInThemeTargetTypeProvider(ThemeVirtualLayout::TARGET_GLOBAL, (string)__('全局'), ['*']),
            new BuiltInThemeTargetTypeProvider(ThemeVirtualLayout::TARGET_PRODUCT, (string)__('商品'), ['product']),
            new BuiltInThemeTargetTypeProvider(ThemeVirtualLayout::TARGET_CATEGORY, (string)__('分类'), ['category', 'product_list']),
            new BuiltInThemeTargetTypeProvider(ThemeVirtualLayout::TARGET_CATEGORY_PRODUCT_DEFAULT, (string)__('分类商品默认'), ['product']),
            new BuiltInThemeTargetTypeProvider('website', (string)__('站点'), ['dashboard']),
        ] as $provider) {
            $providers[$provider->getCode()] = $provider;
        }

        return $providers;
    }
}

final class BuiltInThemeTargetTypeProvider implements TargetTypeProviderInterface
{
    /**
     * @param list<string> $layoutTypes
     */
    public function __construct(
        private readonly string $code,
        private readonly string $label,
        private readonly array $layoutTypes
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getModule(): string
    {
        return ThemeVirtualLayoutService::MODULE_CODE;
    }

    public function getLayoutTypes(): array
    {
        return $this->layoutTypes;
    }

    public function getCapabilities(): array
    {
        return ['layout_selection', 'visual_editor_lock', 'virtual_layout', 'meta', 'preview', 'render'];
    }

    public function validate(int $targetId, array $context = []): bool
    {
        return $this->code === ThemeVirtualLayout::TARGET_GLOBAL || $targetId > 0;
    }

    public function resolve(int $targetId, array $context = []): ?array
    {
        if (!$this->validate($targetId, $context)) {
            return null;
        }

        return [
            'target_type' => $this->code,
            'target_id' => $targetId,
            'label' => $this->label,
        ];
    }

    public function canUseLayoutType(string $layoutType): bool
    {
        $layoutType = strtolower(trim($layoutType));
        return in_array('*', $this->layoutTypes, true) || in_array($layoutType, $this->layoutTypes, true);
    }
}
