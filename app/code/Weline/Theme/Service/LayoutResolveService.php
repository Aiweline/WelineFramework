<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * Dispatch layout_resolve so the public path claims its layout file 1:1.
 * Controllers that already set layoutType skip this. There is no reverse
 * layout→route alias table: the storefront path is the layout path.
 */
final class LayoutResolveService
{
    public const EVENT_LAYOUT_RESOLVE = 'Weline_Theme::layout_resolve';

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

    private function normalizeRequestPath(?Request $request): string
    {
        if ($request === null) {
            return '';
        }

        try {
            $path = (string)$request->getUrlPath();
        } catch (\Throwable) {
            return '';
        }

        return strtolower(trim(str_replace('\\', '/', $path), '/'));
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
