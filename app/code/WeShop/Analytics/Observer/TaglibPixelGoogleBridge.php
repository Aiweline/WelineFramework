<?php

declare(strict_types=1);

namespace WeShop\Analytics\Observer;

use WeShop\Analytics\Service\AnalyticsConfigService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class TaglibPixelGoogleBridge implements ObserverInterface
{
    public function __construct(
        private readonly AnalyticsConfigService $analyticsConfigService
    ) {
    }

    public function execute(Event &$event): void
    {
        if (!$this->analyticsConfigService->isProviderReady(AnalyticsConfigService::PROVIDER_GOOGLE)) {
            return;
        }

        $data = $event->getData();
        if (!is_object($data) || !method_exists($data, 'getData') || !method_exists($data, 'setData')) {
            return;
        }

        $existingCode = trim((string) $data->getData('pixel_code'));
        $bridgeCode = $this->buildBridgeCode();
        if ($bridgeCode === '') {
            return;
        }

        $segments = array_values(array_filter([$existingCode, $bridgeCode], static fn(string $code): bool => $code !== ''));
        $data->setData('pixel_code', implode("\n", $segments));
    }

    private function buildBridgeCode(): string
    {
        return trim(<<<'JS'
(function () {
    var parentWindow = window.parent || window;
    if (!parentWindow || typeof parentWindow.gtag !== 'function') {
        return;
    }

    var pixel = window.WelinePixel || {};
    var payload = pixel.initData || null;
    if (!payload || typeof payload !== 'object') {
        return;
    }

    var rawEventName = String(payload.eventName || '').trim();
    if (rawEventName === '' || rawEventName === 'click') {
        return;
    }

    function normalizeEventName(eventName) {
        var normalized = String(eventName || '')
            .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
            .replace(/[-\s]+/g, '_')
            .toLowerCase()
            .replace(/_+/g, '_')
            .replace(/^_+|_+$/g, '');

        switch (normalized) {
            case 'view_item':
            case 'add_to_cart':
            case 'add_to_wishlist':
            case 'begin_checkout':
            case 'view_cart':
            case 'login':
                return normalized;
            case 'register':
                return 'sign_up';
            case 'checkout_success':
                return 'purchase';
            default:
                return normalized || 'custom_event';
        }
    }

    function normalizeCurrency(currency) {
        var value = String(currency || '').trim().toUpperCase();
        if (value === 'RMB') {
            return 'CNY';
        }

        return value;
    }

    function toNumber(value) {
        if (typeof value === 'number') {
            return isFinite(value) ? value : null;
        }

        if (typeof value !== 'string') {
            return null;
        }

        var cleaned = value.replace(/[^0-9.\-]+/g, '');
        if (cleaned === '') {
            return null;
        }

        var parsed = Number(cleaned);
        return isFinite(parsed) ? parsed : null;
    }

    function buildItems(data) {
        if (!Array.isArray(data.items)) {
            return [];
        }

        var items = [];
        for (var i = 0; i < data.items.length; i++) {
            var item = data.items[i];
            if (!item || typeof item !== 'object') {
                continue;
            }

            var normalizedItem = {};
            var itemId = item.item_id || item.product_id || item.id || '';
            if (itemId) {
                normalizedItem.item_id = String(itemId);
            }

            var itemName = item.item_name || item.name || '';
            if (itemName) {
                normalizedItem.item_name = String(itemName);
            }

            var itemPrice = toNumber(item.price);
            if (itemPrice !== null) {
                normalizedItem.price = itemPrice;
            }

            var itemQuantity = toNumber(item.quantity != null ? item.quantity : item.qty);
            if (itemQuantity !== null) {
                normalizedItem.quantity = itemQuantity;
            }

            if (Object.keys(normalizedItem).length > 0) {
                items.push(normalizedItem);
            }
        }

        return items;
    }

    var eventName = normalizeEventName(rawEventName);
    if (!eventName) {
        return;
    }

    var params = {
        pixel_name: String(payload.name || ''),
        pixel_event_name: rawEventName,
        module: String(payload.module || ''),
        website_id: String(payload.websiteId || ''),
        user_lang: String(payload.userLang || '')
    };

    var pageLocation = String(payload.url || parentWindow.location.href || '');
    if (pageLocation !== '') {
        params.page_location = pageLocation;
    }

    var pageTitle = parentWindow.document && parentWindow.document.title
        ? String(parentWindow.document.title)
        : '';
    if (pageTitle !== '') {
        params.page_title = pageTitle;
    }

    var pageReferrer = String(payload.referrer || '');
    if (pageReferrer !== '') {
        params.page_referrer = pageReferrer;
    }

    var currency = normalizeCurrency(payload.currency);
    if (currency !== '') {
        params.currency = currency;
    }

    var value = toNumber(payload.value);
    if (value !== null) {
        params.value = value;
    }

    var contentName = String(payload.content_name || '');
    if (contentName === '' && payload.elementInfo && payload.elementInfo.innerText) {
        contentName = String(payload.elementInfo.innerText).trim();
    }
    if (contentName !== '') {
        params.content_name = contentName;
    }

    var linkUrl = payload.elementInfo && payload.elementInfo.href
        ? String(payload.elementInfo.href)
        : '';
    if (linkUrl !== '') {
        params.link_url = linkUrl;
    }

    var items = buildItems(payload);
    if (items.length > 0) {
        params.items = items;
    }

    parentWindow.gtag('event', eventName, params);
}());
JS);
    }
}
