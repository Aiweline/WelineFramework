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
        if ($title !== '') {
            $provided['seo_title'] = $title;
        }

        return $provided;
    }
}
