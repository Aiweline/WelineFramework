<?php

declare(strict_types=1);

namespace Weline\Benchmark\Controller;

use Weline\Benchmark\FrontendController;
use Weline\Framework\Http\Response;

class Json extends FrontendController
{
    public function index(): Response
    {
        return Response::json([
            'ok' => true,
            'route' => '/__bench/json',
        ]);
    }
}
