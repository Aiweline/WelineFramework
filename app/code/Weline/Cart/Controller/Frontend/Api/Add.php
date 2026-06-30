<?php

declare(strict_types=1);

namespace Weline\Cart\Controller\Frontend\Api;

use Weline\Framework\App\Controller\FrontendController;

class Add extends FrontendController
{
    public function index(): string
    {
        return $this->deprecatedBrowserDirectResponse("Weline.Api.resource('cart').add()");
    }

    public function getOptions(): string
    {
        return $this->deprecatedBrowserDirectResponse("Weline.Api.resource('cart').options()");
    }

    private function deprecatedBrowserDirectResponse(string $replacement): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode(410);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');

        $json = \json_encode([
            'code' => 410,
            'msg' => (string) __('Direct browser cart API is deprecated. Use the frontend worker API.'),
            'data' => [
                'deprecated' => true,
                'browser_direct' => false,
                'replacement' => $replacement,
            ],
        ], JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}

