<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Article;

use Weline\Seo\Structure\AbstractSeoStructureNodeBuilder;
use Weline\Seo\Structure\SeoStructureContextKeys;
use Weline\Seo\Structure\SeoStructureType;

/**
 * 文章 JSON-LD Builder 基类。
 *
 * 内置 BlogPosting/NewsArticle 节点当前仍由 HeadRenderer 渲染。
 */
abstract class AbstractArticleStructureNodeBuilder extends AbstractSeoStructureNodeBuilder
{
    public function structureType(): string
    {
        return SeoStructureType::ARTICLE;
    }

    protected function contextFactKey(): string
    {
        return SeoStructureContextKeys::ARTICLE;
    }

    protected function schemaNodeType(): string
    {
        return 'Article';
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function hasSupportedFacts(array $context): bool
    {
        if (!$this->hasNonEmptyFacts($context)) {
            return false;
        }

        return $this->isPageType(
            $context,
            'article',
            'blog_post',
            'post',
            'news',
            'news_article'
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function hasSchemaNodeType(array $context, string $type): bool
    {
        foreach (['Article', 'BlogPosting', 'NewsArticle'] as $articleType) {
            if (parent::hasSchemaNodeType($context, $articleType)) {
                return true;
            }
        }

        return parent::hasSchemaNodeType($context, $type);
    }
}
