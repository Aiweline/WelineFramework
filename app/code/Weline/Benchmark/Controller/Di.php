<?php

declare(strict_types=1);

namespace Weline\Benchmark\Controller;

use Weline\Benchmark\Dependency\Layer12;
use Weline\Benchmark\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;

class Di extends FrontendController
{
    public function index(): Response
    {
        /** @var Layer12 $layer */
        $layer = ObjectManager::getInstance(Layer12::class, [], false);

        return Response::json([
            'ok' => true,
            'depth' => $layer->depth(),
        ]);
    }
}
