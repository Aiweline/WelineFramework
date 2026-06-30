<?php

declare(strict_types=1);

namespace Weline\Benchmark\Controller;

use Weline\Benchmark\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Framework\Session\SessionFactory;

class Session extends FrontendController
{
    public function index(): Response
    {
        $session = SessionFactory::getInstance()->createFrontendSession();
        $session->set('weline_benchmark_probe', 'ok');

        return Response::json([
            'ok' => $session->get('weline_benchmark_probe') === 'ok',
        ]);
    }
}
