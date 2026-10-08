<?php

declare(strict_types=1);

namespace Weline\Frontend\Controller;

use Weline\Framework\Controller\AbstractRestController;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;

/**
 * Frontend-area REST gate (storefront / public API).
 * Owning module: Weline_Frontend.
 */
class FrontendRestController extends AbstractRestController
{
    protected function errorXml(string $msg = '错误', mixed $data = false, int $code = 400): never
    {
        $payload = $this->fetch(['msg' => $msg, 'data' => $data, 'code' => $code], self::fetch_XML);
        throw new ResponseTerminateException(
            Response::text($payload->getBody(), $code, 'text/xml; charset=UTF-8')
        );
    }
}
