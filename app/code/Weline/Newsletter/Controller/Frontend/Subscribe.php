<?php

declare(strict_types=1);

namespace Weline\Newsletter\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Newsletter\Service\SubscribeService;

/**
 * Progressive-enhancement shell for newsletter/subscribe.
 * Zero business logic — delegates to SubscribeService.
 */
class Subscribe extends FrontendController
{
    public function postIndex()
    {
        /** @var SubscribeService $service */
        $service = ObjectManager::getInstance(SubscribeService::class);
        $result = $service->subscribe($this->collectInput());

        if ($this->expectsJsonResponse()) {
            throw new ResponseTerminateException(
                !empty($result['ok']) ? 200 : 400,
                (string)\json_encode($result, \JSON_UNESCAPED_UNICODE),
                ['Content-Type' => 'application/json; charset=utf-8'],
            );
        }

        if (!empty($result['ok'])) {
            $this->getMessageManager()->addSuccess((string)($result['message'] ?? __('订阅成功！')));
        } else {
            $this->getMessageManager()->addError((string)($result['message'] ?? __('订阅失败。')));
        }

        $referer = (string)($this->request->getReferer() ?: '/');

        return (string)$this->redirect($referer !== '' ? $referer : '/');
    }

    public function index()
    {
        return $this->postIndex();
    }

    /**
     * @return array<string, mixed>
     */
    private function collectInput(): array
    {
        $get = function (string $key) {
            $v = $this->request->getBodyParam($key);
            if ($v === null) {
                $v = $this->request->getPost($key);
            }
            if ($v === null) {
                $v = $this->request->getParam($key);
            }

            return $v;
        };

        return [
            'email' => (string)($get('email') ?? ''),
            'topic_promo' => $get('topic_promo'),
            'topic_new_arrivals' => $get('topic_new_arrivals'),
            'source_surface' => (string)($get('source_surface') ?? 'footer'),
            'shop_url' => (string)($get('shop_url') ?? '/'),
            'site_name' => (string)($get('site_name') ?? ''),
        ];
    }

    private function expectsJsonResponse(): bool
    {
        $accept = \strtolower((string)($this->request->getHeader('Accept') ?? ''));
        $xrw = \strtolower((string)($this->request->getHeader('X-Requested-With') ?? ''));

        return \str_contains($accept, 'application/json')
            || $xrw === 'xmlhttprequest'
            || (string)$this->request->getParam('format') === 'json';
    }
}
