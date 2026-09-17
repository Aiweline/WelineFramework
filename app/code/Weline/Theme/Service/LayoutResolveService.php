<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * Dispatch layout.resolve so modules (and Theme path↔layout fallback) claim
 * layout_path / layout_option / entity_slug from the public request path.
 * Visual editor reverse-infers storefront paths via layout_preview_sample
 * (module observers + LayoutStorefrontRouteFromModuleRouter fallback).
 */
final class LayoutResolveService
{
    public const EVENT_LAYOUT_RESOLVE = 'Weline_Theme::layout_resolve';
    public const EVENT_LAYOUT_PREVIEW_SAMPLE = 'Weline_Theme::layout_preview_sample';

    /**
     * @return array{
     *   claimed:bool,
     *   layout_path:string,
     *   layout_option:string,
     *   entity_slug:string,
     *   entity_kind:string,
     *   request_path:string
     * }
     */
    public function resolveFromRequest(?Request $request = null): array
    {
        $request ??= ObjectManager::getInstance(Request::class);

        return $this->resolveFromPath($this->normalizeRequestPath($request));
    }

    /**
     * Same as resolveFromRequest, but from an explicit public path
     * (Router rewrite / editor navigation before Request URL is settled).
     *
     * @return array{
     *   claimed:bool,
     *   layout_path:string,
     *   layout_option:string,
     *   entity_slug:string,
     *   entity_kind:string,
     *   request_path:string
     * }
     */
    public function resolveFromPath(string $requestPath): array
    {
        $requestPath = $this->normalizePathSegment($requestPath);

        $payload = new DataObject([
            'request_path' => $requestPath,
            'claimed' => false,
            'layout_path' => '',
            'layout_option' => 'default',
            'entity_slug' => '',
            'entity_kind' => '',
            'entity_id' => 0,
        ]);

        $eventData = ['data' => $payload];
        /** @var EventsManager $events */
        $events = ObjectManager::getInstance(EventsManager::class);
        $events->dispatch(self::EVENT_LAYOUT_RESOLVE, $eventData);
        if (($eventData['data'] ?? null) instanceof DataObject) {
            $payload = $eventData['data'];
        }

        return [
            'claimed' => (bool)$payload->getData('claimed'),
            'layout_path' => $this->normalizePathSegment((string)$payload->getData('layout_path')),
            'layout_option' => $this->normalizeOption((string)$payload->getData('layout_option')),
            'entity_slug' => trim((string)$payload->getData('entity_slug')),
            'entity_kind' => trim((string)$payload->getData('entity_kind')),
            'request_path' => $requestPath,
        ];
    }

    /**
     * @return array{
     *   claimed:bool,
     *   preview_entity_route:string,
     *   entity_slug:string,
     *   sample_source:string
     * }
     */
    public function resolvePreviewSample(
        string $layoutPath,
        string $layoutOption = 'default',
        string $preferredSlug = '',
        int $websiteId = 0,
        string $locale = '',
    ): array {
        $payload = new DataObject([
            'layout_path' => $this->normalizePathSegment($layoutPath),
            'layout_option' => $this->normalizeOption($layoutOption),
            'preferred_slug' => strtolower(trim($preferredSlug)),
            'website_id' => max(0, $websiteId),
            'locale' => trim($locale),
            'claimed' => false,
            'preview_entity_route' => '',
            'entity_slug' => '',
            'entity_id' => 0,
            'sample_source' => 'none',
        ]);

        $eventData = ['data' => $payload];
        /** @var EventsManager $events */
        $events = ObjectManager::getInstance(EventsManager::class);
        $events->dispatch(self::EVENT_LAYOUT_PREVIEW_SAMPLE, $eventData);
        if (($eventData['data'] ?? null) instanceof DataObject) {
            $payload = $eventData['data'];
        }

        if (!(bool)$payload->getData('claimed')) {
            $this->claimFromModuleRouter($payload);
        }

        return [
            'claimed' => (bool)$payload->getData('claimed'),
            'preview_entity_route' => strtolower(trim(str_replace('\\', '/', (string)$payload->getData('preview_entity_route')), '/')),
            'entity_slug' => trim((string)$payload->getData('entity_slug')),
            'sample_source' => trim((string)$payload->getData('sample_source')) ?: 'none',
        ];
    }

    private function claimFromModuleRouter(DataObject $payload): void
    {
        $layoutPath = $this->normalizePathSegment((string)$payload->getData('layout_path'));
        $layoutOption = $this->normalizeOption((string)$payload->getData('layout_option'));

        try {
            /** @var LayoutStorefrontRouteFromModuleRouter $resolver */
            $resolver = ObjectManager::getInstance(LayoutStorefrontRouteFromModuleRouter::class);
            $route = $resolver->resolve($layoutPath, $layoutOption);
        } catch (\Throwable) {
            return;
        }

        // Homepage resolves to empty path and is still a valid canvas entry.
        if ($route === '' && !$this->isHomepageLayoutPath($layoutPath)) {
            return;
        }

        $payload->setData('claimed', true);
        $payload->setData('preview_entity_route', $route);
        $payload->setData('entity_slug', '');
        $payload->setData('sample_source', 'module_router');
    }

    private function isHomepageLayoutPath(string $layoutPath): bool
    {
        $layoutPath = $this->normalizePathSegment($layoutPath);

        return $layoutPath === ''
            || $layoutPath === 'homepage'
            || $layoutPath === 'default'
            || $layoutPath === 'index'
            || $layoutPath === 'index/index';
    }

    public function previewKindForLayoutPath(string $layoutPath): string
    {
        $layoutPath = $this->normalizePathSegment($layoutPath);
        $shellPlusSlug = [
            'promotion',
            'product',
            'category',
            'blog',
            'blog_category',
            'faq',
            'cms_page',
            'payment_guide',
        ];

        return in_array($layoutPath, $shellPlusSlug, true) ? 'shell_plus_slug' : 'fixed';
    }

    private function normalizeRequestPath(?Request $request): string
    {
        if ($request === null) {
            return '';
        }

        try {
            $path = (string)$request->getUrlPath();
        } catch (\Throwable) {
            $path = (string)$request->getParam('theme_public_route', '');
        }

        $path = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($path === '') {
            $path = strtolower(trim(str_replace('\\', '/', (string)$request->getParam('theme_public_route', '')), '/'));
        }

        return $path;
    }

    private function normalizePathSegment(string $value): string
    {
        return strtolower(trim(str_replace('\\', '/', $value), '/'));
    }

    private function normalizeOption(string $value): string
    {
        $value = strtolower(trim($value));
        return $value !== '' ? $value : 'default';
    }
}
