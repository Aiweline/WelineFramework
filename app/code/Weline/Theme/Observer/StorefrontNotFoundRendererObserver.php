<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\StorefrontNotFoundRenderer;

/**
 * Contribute the theme-rendered storefront 404 body to the Framework error renderer.
 *
 * Framework\Http\ErrorPageRenderer dispatches `Weline_Framework_Http::storefront_not_found_render`
 * only when the per-language special FPC misses; this observer renders the theme
 * `not_found` layout and hands the HTML back for caching + serving.
 */
final class StorefrontNotFoundRendererObserver implements ObserverInterface
{
    public function __construct(
        private readonly StorefrontNotFoundRenderer $renderer,
    ) {
    }

    public function execute(Event &$event): void
    {
        try {
            $data = $event->getEvenData();
            if (!\is_object($data) || !\method_exists($data, 'getData')) {
                return;
            }

            if ((int)($data->getData('status_code') ?? 404) !== 404) {
                return;
            }
            $area = \strtolower((string)($data->getData('area') ?? ''));
            if ($area === 'backend' || $area === 'rest_backend') {
                return;
            }
            if (\trim((string)($data->getData('html') ?? '')) !== '') {
                return;
            }

            $websiteCode = (string)($data->getData('website_code') ?? '');
            $websiteId = 0;
            $websiteUrl = '';
            if (\class_exists(RequestContext::class)) {
                if ($websiteCode === '') {
                    $websiteCode = (string)RequestContext::getWelineWebsiteCode();
                }
                try {
                    $websiteId = (int)RequestContext::getWelineWebsiteId();
                } catch (\Throwable) {
                    $websiteId = 0;
                }
                try {
                    $websiteUrl = (string)RequestContext::getWelineWebsiteUrl();
                } catch (\Throwable) {
                    $websiteUrl = '';
                }
            }
            if ($websiteCode === '') {
                $websiteCode = 'default';
            }
            $lang = (string)($data->getData('lang') ?? '');

            $html = $this->renderer->render($websiteId, $websiteCode, $lang, $websiteUrl);
            if ($html !== '') {
                $data->setData('html', $html);
            }
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning(
                    '[storefront_not_found] theme render failed: ' . $e->getMessage()
                    . ' @ ' . $e->getFile() . ':' . $e->getLine(),
                    [],
                    'theme_not_found_render'
                );
            }
        }
    }
}
