<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\ThemeFaqSeoContextService;

/**
 * 将主题布局中的 FAQ 部件事实注入 SEO Head 上下文。
 */
class SeoFaqHeadContextResolve implements ObserverInterface
{
    public function __construct(
        private readonly ThemeFaqSeoContextService $themeFaqSeoContextService
    ) {
    }

    public function execute(Event &$event): void
    {
        $context = $event->getData('context');
        $context = is_array($context) ? $context : [];

        $resolved = $this->themeFaqSeoContextService->resolve($context);
        if ($resolved === []) {
            return;
        }

        $headContext = $event->getData('head_context');
        $headContext = is_array($headContext) ? $headContext : [];

        foreach (['faqs'] as $listKey) {
            if (!isset($resolved[$listKey]) || !is_array($resolved[$listKey])) {
                continue;
            }
            $existing = isset($headContext[$listKey]) && is_array($headContext[$listKey])
                ? $headContext[$listKey]
                : [];
            $headContext[$listKey] = array_values(array_merge($existing, $resolved[$listKey]));
            unset($resolved[$listKey]);
        }

        $event->setData('head_context', array_replace_recursive($headContext, $resolved));
    }
}
