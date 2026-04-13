<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\StateManager;

class PreparedContentStore
{
    private const STORAGE_KEY = 'theme.prepared_content_store.items_by_scope';
    private const COUNTER_KEY = 'theme.prepared_content_store.counter_by_scope';

    /** @var array<string,string> */
    private static array $fallbackContentByKey = [];

    private static int $fallbackCounter = 0;

    private static bool $stateManagerRegistered = false;

    private static function registerStateManager(): void
    {
        if (self::$stateManagerRegistered) {
            return;
        }

        if (class_exists(StateManager::class)) {
            StateManager::registerResetCallback('Theme_PreparedContentStore', [self::class, 'resetRequestState']);
            self::$stateManagerRegistered = true;
        }
    }

    public static function put(string $content): string
    {
        self::registerStateManager();

        $items = self::getItems();
        $key = 'prepared_content_' . self::nextCounter();
        $items[$key] = $content;
        self::setItems($items);

        return $key;
    }

    public static function has(?string $key): bool
    {
        self::registerStateManager();

        return $key !== null && $key !== '' && array_key_exists($key, self::getItems());
    }

    public static function get(?string $key, string $fallback = ''): string
    {
        self::registerStateManager();

        if ($key === null || $key === '') {
            return $fallback;
        }

        return self::getItems()[$key] ?? $fallback;
    }

    public static function resolveLayoutContent(
        mixed $contentRenderKey,
        mixed $content = null,
        mixed $meta = null,
        mixed $childHtml = null,
        ?string $contentTemplate = null,
        ?callable $templateRenderer = null
    ): string {
        $resolvedKey = self::resolveKey($contentRenderKey, $meta, $childHtml);
        if (self::has($resolvedKey)) {
            return self::get($resolvedKey);
        }

        $directContent = self::resolveDirectContent($content, $meta, $childHtml);
        if ($directContent !== null) {
            return $directContent;
        }

        if ($contentTemplate !== null && $contentTemplate !== '' && $templateRenderer !== null) {
            return (string)$templateRenderer($contentTemplate);
        }

        return '';
    }

    public static function resetRequestState(): void
    {
        if (self::shouldUseRequestContextStorage()) {
            $scopeId = self::getCurrentScopeId();
            if ($scopeId !== null) {
                $allItems = RequestContext::get(self::STORAGE_KEY, []);
                if (\is_array($allItems)) {
                    unset($allItems[$scopeId]);
                    if ($allItems === []) {
                        RequestContext::remove(self::STORAGE_KEY);
                    } else {
                        RequestContext::set(self::STORAGE_KEY, $allItems);
                    }
                }

                $allCounters = RequestContext::get(self::COUNTER_KEY, []);
                if (\is_array($allCounters)) {
                    unset($allCounters[$scopeId]);
                    if ($allCounters === []) {
                        RequestContext::remove(self::COUNTER_KEY);
                    } else {
                        RequestContext::set(self::COUNTER_KEY, $allCounters);
                    }
                }
            } else {
                RequestContext::remove(self::STORAGE_KEY);
                RequestContext::remove(self::COUNTER_KEY);
            }
            return;
        }

        self::$fallbackContentByKey = [];
        self::$fallbackCounter = 0;
    }

    private static function resolveKey(mixed $contentRenderKey, mixed $meta, mixed $childHtml): string
    {
        if (is_string($contentRenderKey) && $contentRenderKey !== '') {
            return $contentRenderKey;
        }

        if (is_array($meta) && isset($meta['contentRenderKey']) && is_string($meta['contentRenderKey'])) {
            return $meta['contentRenderKey'];
        }

        if (is_array($childHtml) && isset($childHtml['contentRenderKey']) && is_string($childHtml['contentRenderKey'])) {
            return $childHtml['contentRenderKey'];
        }

        return '';
    }

    private static function resolveDirectContent(mixed $content, mixed $meta, mixed $childHtml): ?string
    {
        if (is_string($content)) {
            return $content;
        }

        if (is_array($meta) && array_key_exists('content', $meta) && is_string($meta['content'])) {
            return $meta['content'];
        }

        if (is_array($childHtml) && array_key_exists('content', $childHtml) && is_string($childHtml['content'])) {
            return $childHtml['content'];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function getItems(): array
    {
        if (self::shouldUseRequestContextStorage()) {
            $scopeId = self::getCurrentScopeId();
            if ($scopeId === null) {
                return [];
            }

            $allItems = RequestContext::get(self::STORAGE_KEY, []);
            if (!\is_array($allItems)) {
                return [];
            }

            $items = $allItems[$scopeId] ?? [];
            return \is_array($items) ? $items : [];
        }

        return self::$fallbackContentByKey;
    }

    /**
     * @param array<string, string> $items
     */
    private static function setItems(array $items): void
    {
        if (self::shouldUseRequestContextStorage()) {
            $scopeId = self::getCurrentScopeId();
            if ($scopeId === null) {
                return;
            }

            $allItems = RequestContext::get(self::STORAGE_KEY, []);
            if (!\is_array($allItems)) {
                $allItems = [];
            }
            $allItems[$scopeId] = $items;
            RequestContext::set(self::STORAGE_KEY, $allItems);
            return;
        }

        self::$fallbackContentByKey = $items;
    }

    private static function nextCounter(): int
    {
        if (self::shouldUseRequestContextStorage()) {
            $scopeId = self::getCurrentScopeId();
            if ($scopeId === null) {
                return 1;
            }

            $allCounters = RequestContext::get(self::COUNTER_KEY, []);
            if (!\is_array($allCounters)) {
                $allCounters = [];
            }

            $counter = (int)($allCounters[$scopeId] ?? 0) + 1;
            $allCounters[$scopeId] = $counter;
            RequestContext::set(self::COUNTER_KEY, $allCounters);
            return $counter;
        }

        return ++self::$fallbackCounter;
    }

    private static function shouldUseRequestContextStorage(): bool
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return false;
        }

        return (string)$context->get('meta.type', '') === 'request' || RequestContext::isInitialized();
    }

    private static function getCurrentScopeId(): ?string
    {
        $connectionId = RequestContext::getConnectionId();
        if ($connectionId !== null && $connectionId !== '') {
            return 'conn:' . $connectionId;
        }

        $requestId = RequestContext::getId();
        if ($requestId !== null && $requestId !== '') {
            return 'request:' . $requestId;
        }

        return null;
    }
}
