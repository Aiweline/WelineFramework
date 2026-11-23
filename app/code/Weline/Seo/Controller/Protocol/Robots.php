<?php

declare(strict_types=1);

namespace Weline\Seo\Controller\Protocol;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Seo\Service\Protocol\RobotsTxtRenderer;

/**
 * @Extra type=fpc ttl=3600 namespaces=website/default/seo public_path_patterns=/robots.txt,/seo/protocol/robots
 */
class Robots extends FrontendController
{
    public function __construct(
        private readonly RobotsTxtRenderer $renderer
    ) {
    }

    public function index(): Response
    {
        $response = Response::text($this->renderer->render(), 200, 'text/plain; charset=utf-8');
        // Lighthouse/agent fetchers abort slowly; keep robots.txt on the edge so a
        // cold PHP render can never trip "Timed out fetching robots.txt" (SEO 92).
        $response->setHeader('Cache-Control', 'public, max-age=300, must-revalidate');
        $response->setHeader('CDN-Cache-Control', 'public, max-age=300');
        $response->setHeader('Cloudflare-CDN-Cache-Control', 'public, max-age=300');

        return $response;
    }
}
