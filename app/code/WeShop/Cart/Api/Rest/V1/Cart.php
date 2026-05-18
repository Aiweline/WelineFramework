<?php

declare(strict_types=1);

namespace WeShop\Cart\Api\Rest\V1;

use WeShop\Cart\Service\CartApiPayloadService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

class Cart extends FrontendRestController
{
    private const LOGIN_ROUTE = 'weshop/customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly CartApiPayloadService $cartApiPayloadService,
        private ?Url $url = null
    ) {
    }

    public function postAdd(): string
    {
        return $this->deprecatedBrowserDirectResponse("Weline.Api.resource('cart').add()");

        $payload = $this->cartApiPayloadService->buildAddResponse($this->getCustomerId(), [
            'product_id' => (int) ($this->readRequestValue('product_id') ?? 0),
            'qty' => (int) ($this->readRequestValue('qty') ?? 1),
            'selected_options' => $this->readRequestValue('selected_options') ?? [],
        ]);

        if (($payload['code'] ?? 0) === 401 && \is_array($payload['data'] ?? null)) {
            $payload['data']['redirect_url'] = $this->getUrlService()->getUrl(self::LOGIN_ROUTE);
        }

        return $this->fetchJson($payload);
    }

    public function getOptions(): string
    {
        return $this->deprecatedBrowserDirectResponse("Weline.Api.resource('cart').options()");

        return $this->fetchJson(
            $this->cartApiPayloadService->buildOptionsResponse(
                (int) ($this->readRequestValue('product_id') ?? 0)
            )
        );
    }

    public function postUpdate(): string
    {
        return $this->deprecatedBrowserDirectResponse("Weline.Api.resource('cart').update()");

        $payload = $this->cartApiPayloadService->buildUpdateResponse(
            $this->getCustomerId(),
            (int) (($this->readRequestValue('item_id') ?? $this->readRequestValue('cart_id')) ?? 0),
            (int) ($this->readRequestValue('quantity') ?? 1)
        );

        if (($payload['code'] ?? 0) === 401 && \is_array($payload['data'] ?? null)) {
            $payload['data']['redirect_url'] = $this->getUrlService()->getUrl(self::LOGIN_ROUTE);
        }

        return $this->fetchJson($payload);
    }

    public function postRemove(): string
    {
        return $this->deprecatedBrowserDirectResponse("Weline.Api.resource('cart').remove()");

        $payload = $this->cartApiPayloadService->buildRemoveResponse(
            $this->getCustomerId(),
            (int) (($this->readRequestValue('item_id') ?? $this->readRequestValue('cart_id')) ?? 0)
        );

        if (($payload['code'] ?? 0) === 401 && \is_array($payload['data'] ?? null)) {
            $payload['data']['redirect_url'] = $this->getUrlService()->getUrl(self::LOGIN_ROUTE);
        }

        return $this->fetchJson($payload);
    }

    public function getMiniItems(): string
    {
        return $this->deprecatedBrowserDirectResponse("Weline.Api.resource('cart').miniItems()");

        $payload = $this->cartApiPayloadService->buildMiniItemsResponse($this->getCustomerId());
        if (\is_array($payload['data'] ?? null)) {
            $payload['data']['html'] = $this->renderMiniItemsHtml($payload['data']);
        }

        return $this->fetchJson($payload);
    }

    public function postMiniItems(): string
    {
        return $this->getMiniItems();
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function renderMiniItemsHtml(array $data): string
    {
        $items = $data['items'] ?? [];
        if (!\is_array($items) || $items === []) {
            return $this->renderEmptyCartHtml();
        }

        $html = '';
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $cartId = (int) ($item['cart_id'] ?? $item['item_id'] ?? 0);
            $productId = (int) ($item['product_id'] ?? 0);
            $name = $this->escapeHtml((string) ($item['name'] ?? ''));
            $url = $this->escapeHtml((string) ($item['url'] ?? '#'));
            $priceFormatted = $this->escapeHtml((string) ($item['price_formatted'] ?? ''));
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $options = trim((string) ($item['options'] ?? ''));
            $image = trim((string) ($item['image'] ?? ''));

            $imageHtml = $image !== ''
                ? '<img src="' . $this->escapeHtml($image) . '" alt="' . $name . '" loading="lazy"/>'
                : '<div class="mini-cart-item__placeholder"><span class="mini-cart-icon" aria-hidden="true">' . $this->renderMiniCartIconHtml('image') . '</span></div>';

            $optionsHtml = $options !== ''
                ? '<div class="mini-cart-item__options">' . $this->escapeHtml($options) . '</div>'
                : '';

            $cartIdAttr = $this->escapeHtml((string) $cartId);
            $productIdAttr = $this->escapeHtml((string) $productId);
            $quantityText = $this->escapeHtml((string) $quantity);

            $html .= '<div class="mini-cart-item" data-item-id="' . $cartIdAttr . '" data-product-id="' . $productIdAttr . '">'
                . '<div class="mini-cart-item__image">' . $imageHtml . '</div>'
                . '<div class="mini-cart-item__details">'
                . '<a href="' . $url . '" class="mini-cart-item__name">' . $name . '</a>'
                . $optionsHtml
                . '<div class="mini-cart-item__price">' . $priceFormatted . '</div>'
                . '<div class="mini-cart-item__qty">'
                . '<button type="button" class="mini-cart-item__qty-btn" data-action="decrease-qty" data-item-id="' . $cartIdAttr . '" aria-label="' . $this->escapeHtml((string) __('Decrease quantity')) . '">'
                . '<span class="mini-cart-icon" aria-hidden="true">' . $this->renderMiniCartIconHtml('minus') . '</span>'
                . '</button>'
                . '<span class="mini-cart-item__qty-value">' . $quantityText . '</span>'
                . '<button type="button" class="mini-cart-item__qty-btn" data-action="increase-qty" data-item-id="' . $cartIdAttr . '" aria-label="' . $this->escapeHtml((string) __('Increase quantity')) . '">'
                . '<span class="mini-cart-icon" aria-hidden="true">' . $this->renderMiniCartIconHtml('plus') . '</span>'
                . '</button>'
                . '</div>'
                . '</div>'
                . '<button type="button" class="mini-cart-item__remove" data-action="remove-item" data-item-id="' . $cartIdAttr . '" aria-label="' . $this->escapeHtml((string) __('Remove item')) . '">'
                . '<span class="mini-cart-icon" aria-hidden="true">' . $this->renderMiniCartIconHtml('trash') . '</span>'
                . '</button>'
                . '</div>';
        }

        return $html !== '' ? $html : $this->renderEmptyCartHtml();
    }

    protected function renderEmptyCartHtml(): string
    {
        return '<div class="mini-cart-empty" id="mini-cart-empty">'
            . '<div class="empty-state">'
            . '<span class="mini-cart-empty__icon mini-cart-icon" aria-hidden="true">' . $this->renderMiniCartIconHtml('cart') . '</span>'
            . '<p class="empty-message">' . __('购物车是空的') . '</p>'
            . '<a href="' . $this->getUrlService()->getUrl('/') . '" class="start-shopping-link" data-action="close-mini-cart">' . __('开始购物') . '</a>'
            . '</div>'
            . '</div>';
    }

    private function renderMiniCartIconHtml(string $name): string
    {
        return match ($name) {
            'minus' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 11a1 1 0 0 0 0 2h12a1 1 0 1 0 0-2H6Z" fill="currentColor"/></svg>',
            'plus' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M11 6a1 1 0 1 1 2 0v5h5a1 1 0 1 1 0 2h-5v5a1 1 0 1 1-2 0v-5H6a1 1 0 0 1 0-2h5V6Z" fill="currentColor"/></svg>',
            'trash' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3a1 1 0 0 0-.8.4L7.4 4H5a1 1 0 1 0 0 2h.5l1 11.1A2 2 0 0 0 8.5 19h7a2 2 0 0 0 2-1.9l1-11.1H19a1 1 0 1 0 0-2h-2.4l-.8-.6A1 1 0 0 0 15 3H9Zm-.5 3h7l-.9 10.9H9.4L8.5 6Zm1.5 2a1 1 0 0 1 1 1v5a1 1 0 1 1-2 0V9a1 1 0 0 1 1-1Zm4 0a1 1 0 0 1 1 1v5a1 1 0 1 1-2 0V9a1 1 0 0 1 1-1Z" fill="currentColor"/></svg>',
            'image' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 5a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3h12a3 3 0 0 0 3-3V8a3 3 0 0 0-3-3H6Zm0 2h12a1 1 0 0 1 1 1v5.4l-2.8-2.8a1 1 0 0 0-1.4 0L10 15.4l-1.8-1.8a1 1 0 0 0-1.4 0L5 15.4V8a1 1 0 0 1 1-1Zm0 10 1.5-1.5 1.8 1.8a1 1 0 0 0 1.4 0l4.8-4.8 3.5 3.5a1 1 0 0 1-1 1H6Zm9-7a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" fill="currentColor"/></svg>',
            'cart' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 4a1 1 0 0 1 1-1h1.3a2 2 0 0 1 2 1.6L7.5 6H20a1 1 0 0 1 1 .8 1 1 0 0 1-.1.7l-2.4 5.8A2 2 0 0 1 16.6 15H9a2 2 0 0 1-2-1.6L5.1 5H4a1 1 0 0 1-1-1Zm5 4 .9 4.2H16.6L18.2 8H8ZM9 20a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm8 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z" fill="currentColor"/></svg>',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function fetchJson(array $data): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode(200);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');

        $json = \json_encode($data, JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    private function deprecatedBrowserDirectResponse(string $replacement): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode(410);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');

        $json = \json_encode([
            'code' => 410,
            'msg' => (string) __('Direct browser cart REST API is deprecated. Use the frontend worker API.'),
            'data' => [
                'deprecated' => true,
                'browser_direct' => false,
                'replacement' => $replacement,
            ],
        ], JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    protected function getCustomerId(): ?int
    {
        return $this->customerContext->getUserId();
    }

    private function getUrlService(): Url
    {
        return $this->url ??= ObjectManager::getInstance(Url::class);
    }

    protected function readRequestValue(string $key): mixed
    {
        return $this->request->getBodyParam($key, null)
            ?? $this->request->getPost($key, null)
            ?? $this->request->getParam($key, null);
    }

    private function escapeHtml(string $value): string
    {
        return \htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
