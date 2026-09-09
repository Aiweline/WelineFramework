<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\ThemeSocialSameAsSeoContextService;

/**
 * 将主题页脚社交链接注入 SEO Head 的 Organization.sameAs。
 */
class SeoSocialSameAsHeadContextResolve implements ObserverInterface
{
    public function __construct(
        private readonly ThemeSocialSameAsSeoContextService $themeSocialSameAsSeoContextService
    ) {
    }

    public function execute(Event &$event): void
    {
        $context = $event->getData('context');
        $context = is_array($context) ? $context : [];

        $headContext = $event->getData('head_context');
        $headContext = is_array($headContext) ? $headContext : [];

        $probe = $context;
        $probe['organization'] = is_array($headContext['organization'] ?? null)
            ? $headContext['organization']
            : (is_array($context['organization'] ?? null) ? $context['organization'] : []);

        $resolved = $this->themeSocialSameAsSeoContextService->resolve($probe);
        if ($resolved === []) {
            return;
        }

        $event->setData('head_context', array_replace_recursive($headContext, $resolved));
    }
}
