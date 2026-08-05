<?php

declare(strict_types=1);

namespace Weline\Seo\Controller\Protocol;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Seo\Service\Protocol\SitemapProtocolRenderer;

class Sitemap extends FrontendController
{
    public function __construct(
        private readonly SitemapProtocolRenderer $renderer
    ) {
    }

    public function index(): Response
    {
        $result = $this->renderer->render();
        return Response::text($result['body'], $result['status'], 'application/xml; charset=utf-8');
    }
}
