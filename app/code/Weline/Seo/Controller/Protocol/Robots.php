<?php

declare(strict_types=1);

namespace Weline\Seo\Controller\Protocol;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Seo\Service\Protocol\RobotsTxtRenderer;

class Robots extends FrontendController
{
    public function __construct(
        private readonly RobotsTxtRenderer $renderer
    ) {
    }

    public function index(): Response
    {
        return Response::text($this->renderer->render(), 200, 'text/plain; charset=utf-8');
    }
}
