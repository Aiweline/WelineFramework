<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Article;

use Weline\Seo\Structure\AbstractSeoStructureNormalizer;
use Weline\Seo\Structure\SeoStructureFactsInterface;

/**
 * 文章/资讯结构化事实归一化基类。
 *
 * 标准 context 键：article
 * 典型字段：headline、datePublished、dateModified、author、image
 */
abstract class AbstractArticleStructureNormalizer extends AbstractSeoStructureNormalizer implements SeoStructureFactsInterface
{
    /**
     * @return array<string, mixed>
     */
    abstract public function normalize(mixed $article): array;

    /**
     * @param array<string, mixed> $article
     * @return array<string, mixed>
     */
    protected function baseArticleFacts(array $article): array
    {
        $facts = [];
        foreach ([
            'headline' => ['headline', 'title', 'name'],
            'description' => ['description', 'excerpt'],
            'datePublished' => ['datePublished', 'date_published', 'published_at'],
            'dateModified' => ['dateModified', 'date_modified', 'updated_at'],
            'author_name' => ['author_name', 'author'],
            'image' => ['image', 'cover_image', 'featured_image'],
            'articleSection' => ['articleSection', 'section', 'category'],
        ] as $target => $aliases) {
            $value = $this->firstString($article, $aliases);
            if ($value !== '') {
                $facts[$target] = $value;
            }
        }

        return $facts;
    }
}
