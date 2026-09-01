<?php

declare(strict_types=1);

namespace Weline\Wishlist\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

/**
 * 愿望清单页视图数据：在内容模板渲染阶段解析（晚于 Theme fetch_file_before / Cookie 作用域）。
 */
final class WishlistPagePresenter
{
    public function __construct(
        private readonly WishlistService $wishlist,
    ) {
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     count: int
     * }
     */
    public function resolve(?Template $template = null): array
    {
        $template ??= ObjectManager::getInstance(Template::class);
        unset($template);

        $payload = $this->wishlist->listPage();
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        return [
            'items' => $items,
            'count' => (int)($payload['wishlist_count'] ?? count($items)),
        ];
    }
}
