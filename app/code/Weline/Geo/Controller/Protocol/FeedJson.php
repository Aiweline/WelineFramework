<?php

declare(strict_types=1);

namespace Weline\Geo\Controller\Protocol;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Geo\Service\Protocol\GeoProtocolRenderer;

class FeedJson extends FrontendController
{
    public function __construct(
        private readonly GeoProtocolRenderer $renderer
    ) {
    }

    public function index(): Response
    {
        return Response::text($this->renderer->renderFeed('json_feed'), 200, 'application/feed+json; charset=utf-8');
    }
}
