<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\ItemList;

use Weline\Seo\Structure\AbstractSeoStructureNormalizer;
use Weline\Seo\Structure\SeoStructureFactsInterface;

/**
 * 列表页结构化事实归一化基类。
 *
 * 标准 context 键：item_list
 * 每项典型字段：name、url、image、description
 */
abstract class AbstractItemListStructureNormalizer extends AbstractSeoStructureNormalizer implements SeoStructureFactsInterface
{
    /**
     * @return array<int, array{name:string,url:string,image?:string,description?:string}>
     */
    abstract public function normalize(mixed $items): array;

    /**
     * @param array<string, mixed> $item
     * @return array{name:string,url:string,image?:string,description?:string}|null
     */
    protected function normalizeItem(array $item): ?array
    {
        $name = $this->firstString($item, ['name', 'title', 'label']);
        $url = $this->firstString($item, ['url', 'link', 'href']);
        if ($name === '' || $url === '') {
            return null;
        }

        $normalized = [
            'name' => $name,
            'url' => $url,
        ];
        $image = $this->firstString($item, ['image', 'img', 'thumbnail']);
        if ($image !== '') {
            $normalized['image'] = $image;
        }
        $description = $this->firstString($item, ['description', 'summary']);
        if ($description !== '') {
            $normalized['description'] = $description;
        }

        return $normalized;
    }
}
