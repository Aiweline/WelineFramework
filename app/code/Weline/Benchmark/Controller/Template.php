<?php

declare(strict_types=1);

namespace Weline\Benchmark\Controller;

use Weline\Benchmark\FrontendController;
use Weline\Framework\Http\Response;

class Template extends FrontendController
{
    public function index(): Response
    {
        return Response::html(
            $this->template('Weline_Benchmark::templates/benchmark/template.phtml', ['label' => 'ok'])
        );
    }
}
