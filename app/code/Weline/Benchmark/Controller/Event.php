<?php

declare(strict_types=1);

namespace Weline\Benchmark\Controller;

use Weline\Benchmark\FrontendController;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Http\Response;

class Event extends FrontendController
{
    public function index(): Response
    {
        $payload = new DataObject(['count' => 0]);
        $this->getEventManager()->dispatch('Weline_Benchmark::fixed_observer_chain', $payload);
        $count = (int)$payload->getData('count');

        return Response::json([
            'ok' => $count === 10,
            'observers' => $count,
        ]);
    }
}
