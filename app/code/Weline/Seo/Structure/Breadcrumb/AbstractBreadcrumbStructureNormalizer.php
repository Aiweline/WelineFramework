<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Breadcrumb;

use Weline\Seo\Structure\AbstractSeoStructureNormalizer;
use Weline\Seo\Structure\SeoStructureFactsInterface;

/**
 * 面包屑结构化事实归一化基类。
 *
 * 标准 context 键：breadcrumbs
 * 每项字段：name、url
 */
abstract class AbstractBreadcrumbStructureNormalizer extends AbstractSeoStructureNormalizer implements SeoStructureFactsInterface
{
    /**
     * @return array<int, array{name:string,url:string}>
     */
    abstract public function normalize(mixed $breadcrumbs): array;

    /**
     * @return array<int, array{name:string,url:string}>
     */
    protected function normalizeList(mixed $breadcrumbs): array
    {
        $normalized = [];
        foreach ($this->filterList($breadcrumbs) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = $this->firstString($item, ['name', 'label', 'title']);
            $url = $this->firstString($item, ['url', 'link', 'href']);
            if ($name !== '' && $url !== '') {
                $normalized[] = ['name' => $name, 'url' => $url];
            }
        }

        return $normalized;
    }
}
