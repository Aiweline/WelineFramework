<?php

declare(strict_types=1);

namespace Weline\Geo\Controller\Protocol;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Geo\Service\Protocol\GeoProtocolRenderer;

class Llms extends FrontendController
{
    public function __construct(
        private readonly GeoProtocolRenderer $renderer
    ) {
    }

    public function index(): Response
    {
        return Response::text($this->renderer->renderLlms(false), 200, 'text/plain; charset=utf-8');
    }
}
