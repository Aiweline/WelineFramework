<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\ThemeDirectoryResolver;

/**
 * Resolves default header nav items from the active theme chain.
 *
 * Design themes may override `partials/header/nav-defaults.phtml` (returns array).
 * Framework shell ships a generic ecommerce fallback.
 */
final class HeaderDefaultNavItems
{
    /**
     * @return list<array<string, mixed>>
     */
    public function resolve(): array
    {
        $file = $this->resolveNavDefaultsFile();
        if ($file !== null) {
            $items = include $file;
            if (is_array($items)) {
                return array_values($items);
            }
        }

        return self::genericDefaults();
    }

    private function resolveNavDefaultsFile(): ?string
    {
        try {
            /** @var ThemeDirectoryResolver $resolver */
            $resolver = ObjectManager::getInstance(ThemeDirectoryResolver::class);
            foreach ($resolver->getAreaDirectories('frontend') as $directory) {
                $path = rtrim((string)($directory['path'] ?? ''), '\\/') . '/partials/header/nav-defaults.phtml';
                if (is_file($path)) {
                    return $path;
                }
            }
        } catch (\Throwable) {
        }

        $fallback = dirname(__DIR__) . '/view/theme/frontend/partials/header/nav-defaults.phtml';

        return is_file($fallback) ? $fallback : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function genericDefaults(): array
    {
        return [
            [
                'text' => '全部商品',
                'url' => '/products',
                'description' => '浏览全部已发布商品',
                'children' => [],
            ],
            [
                'text' => '分类',
                'url' => '/categories',
                'description' => '按分类浏览商品',
                'children' => [],
            ],
            [
                'text' => '新品',
                'url' => '/new-arrivals',
                'description' => '查看最新上架商品',
                'children' => [],
            ],
            [
                'text' => '关于我们',
                'url' => '/about',
                'description' => '了解品牌与服务',
                'children' => [
                    ['text' => '品牌故事', 'url' => '/about'],
                    ['text' => '帮助中心', 'url' => '/faq'],
                    ['text' => '联系我们', 'url' => '/contact'],
                ],
            ],
        ];
    }
}
