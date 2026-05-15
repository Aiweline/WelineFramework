<?php

declare(strict_types=1);

namespace Weline\Seo\Extends\Module\Weline_Frontend\HeadContextProvider;

use Weline\Frontend\Interface\HeadContextProviderInterface;
use Weline\Seo\Service\Head\PageSeoContextResolver;

class SeoHeadContextProvider implements HeadContextProviderInterface
{
    public function __construct(
        private readonly PageSeoContextResolver $resolver
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provide($template, array $context): array
    {
        $seoContext = $this->resolver->resolve($template, ['slot' => 'title']);
        $provided = [];

        foreach (['site_name', 'current_page'] as $key) {
            if (!empty($seoContext[$key])) {
                $provided[$key] = $seoContext[$key];
            }
        }

        $title = trim((string)($seoContext['title'] ?? ''));
        $currentTitle = trim((string)($context['title'] ?? ''));
        if ($title !== '' && ($currentTitle === '' || $this->hasExplicitSeoTitle($template))) {
            $provided['seo_title'] = $title;
        }

        return $provided;
    }

    private function hasExplicitSeoTitle($template): bool
    {
        $seo = $this->readTemplateArray($template, 'seo');
        $meta = $this->pageMeta($template);

        foreach ([
            $seo['title'] ?? null,
            $seo['meta_title'] ?? null,
            $this->readTemplateValue($template, 'meta_title'),
            $meta['meta_title'] ?? null,
        ] as $value) {
            if (!is_array($value) && $value !== null && trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function readTemplateArray($template, string $key): array
    {
        $value = $this->readTemplateValue($template, $key);
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function pageMeta($template): array
    {
        $meta = $this->readTemplateArray($template, 'meta');
        $layoutMeta = $this->readTemplateArray($template, 'layout');
        if ($layoutMeta === []) {
            return $meta;
        }

        $pageMeta = array_replace($meta, $layoutMeta);
        foreach (['meta_title', 'title'] as $key) {
            if (!array_key_exists($key, $layoutMeta)) {
                unset($pageMeta[$key]);
            }
        }

        return $pageMeta;
    }

    private function readTemplateValue($template, string $key): mixed
    {
        if (is_object($template) && method_exists($template, 'getData')) {
            return $template->getData($key);
        }
        return null;
    }
}
