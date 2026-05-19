<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Head;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;

/**
 * 通过 Weline_Seo::integration::head_context_resolve 事件收集站点级 SEO 上下文。
 */
class HeadIntegrationContextService
{
    public function __construct(
        private readonly EventsManager $eventsManager
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function resolve($template, array $context = []): array
    {
        $payload = new DataObject([
            'template' => $template,
            'context' => $context,
            'head_context' => [],
        ]);

        try {
            $this->eventsManager->dispatch('Weline_Seo::integration::head_context_resolve', $payload);
        } catch (\Throwable) {
            return [];
        }

        $headContext = $payload->getData('head_context');
        return is_array($headContext) ? $headContext : [];
    }
}
