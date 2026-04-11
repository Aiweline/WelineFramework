<?php

namespace Weline\Framework\App\Controller;

use Weline\Framework\Controller\AbstractRestController;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;

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
