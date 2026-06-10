<?php

declare(strict_types=1);

namespace Weline\Seo\Structure;

/**
 * SEO 结构化节点 Builder 抽象基类。
 *
 * 业务模块应继承各类型目录下的 Abstract*StructureNodeBuilder，
 * 或通过 extends/module/Weline_Seo/SeoStructureNodeBuilder 注册自定义 Builder。
 */
abstract class AbstractSeoStructureNodeBuilder implements SeoStructureNodeBuilderInterface
{
    abstract public function structureType(): string;

    /**
     * 页面 context 中承载该结构事实的主键，例如 product、article、faqs。
     */
    abstract protected function contextFactKey(): string;

    /**
     * 对应 schema.org @type，用于 schema_nodes 去重。
     */
    abstract protected function schemaNodeType(): string;

    /**
     * @param array<string, mixed> $context
     */
    abstract protected function hasSupportedFacts(array $context): bool;

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    abstract protected function buildFactNodes(array $context, string $url): array;

    /**
     * @param array<string, mixed> $context
     */
    public function supports(array $context): bool
    {
        if ($this->hasSchemaNodeType($context, $this->schemaNodeType())) {
            return false;
        }

        return $this->hasSupportedFacts($context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function buildNodes(array $context, string $url): array
    {
        return $this->buildFactNodes($context, $this->resolveUrl($context, $url));
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function readFacts(array $context): mixed
    {
        return $context[$this->contextFactKey()] ?? null;
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function hasNonEmptyFacts(array $context): bool
    {
        $facts = $this->readFacts($context);
        if (!is_array($facts)) {
            return false;
        }

        return $facts !== [];
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function isPageType(array $context, string ...$pageTypes): bool
    {
        $current = $this->normalizePageType((string) ($context['page_type'] ?? ''));
        $normalized = array_map(fn (string $type): string => $this->normalizePageType($type), $pageTypes);

        return in_array($current, $normalized, true);
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function hasSchemaNodeType(array $context, string $type): bool
    {
        $needle = strtolower(trim($type));
        foreach ((array) ($context['schema_nodes'] ?? []) as $node) {
            if (!is_array($node)) {
                continue;
            }
            $nodeType = strtolower((string) ($node['@type'] ?? $node['type'] ?? ''));
            if ($nodeType === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function resolveUrl(array $context, string $url): string
    {
        $resolved = trim($url);
        if ($resolved !== '') {
            return $resolved;
        }

        return trim((string) ($context['canonical_url'] ?? $context['url'] ?? ''));
    }

    protected function nodeId(string $url, string $fragment): string
    {
        return $url . '#' . ltrim($fragment, '#');
    }

    protected function normalizePageType(string $pageType): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($pageType)));
    }
}
