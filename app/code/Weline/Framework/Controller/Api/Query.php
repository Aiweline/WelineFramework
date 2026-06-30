<?php
declare(strict_types=1);

namespace Weline\Framework\Controller\Api;

use Weline\Framework\App\Controller\FrontendController;

class Query extends FrontendController
{
    public function postIndex(): string
    {
        return $this->deprecatedJsonQueryResponse();
    }

    private function deprecatedJsonQueryResponse(): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode(410);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');

        $json = \json_encode([
            'code' => 410,
            'msg' => (string)__('Frontend JSON query is deprecated. Use the Weline frontend worker API.'),
            'data' => [
                'deprecated' => true,
                'browser_direct' => false,
                'replacement' => 'Weline.Api.resource()/graph()/stream()',
                'implementation' => '/api/framework/query-bin',
            ],
        ], JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}
