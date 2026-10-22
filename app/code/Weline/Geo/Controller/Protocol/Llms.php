<?php

declare(strict_types=1);

namespace Weline\Geo\Controller\Protocol;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Geo\Service\Protocol\GeoProtocolRenderer;
use Weline\Geo\Service\Protocol\IndustryProtocolDocuments;

class Llms extends FrontendController
{
    public function __construct(
        private readonly GeoProtocolRenderer $renderer
    ) {
    }

    public function index(): Response
    {
        $path = strtolower(trim((string) (parse_url($this->request->getUrlPath(), PHP_URL_PATH) ?: ''), '/'));

        return match ($path) {
            'ai.txt' => Response::text(IndustryProtocolDocuments::aiTxt(), 200, 'text/plain; charset=utf-8'),
            'license.xml' => Response::text($this->renderer->renderLicenseXml(), 200, 'application/rsl+xml; charset=utf-8'),
            'trust.txt', '.well-known/trust.txt' => Response::text($this->renderer->renderTrustTxt(), 200, 'text/plain; charset=utf-8'),
            '.well-known/tdmrep.json' => Response::text(IndustryProtocolDocuments::tdmRepJson(), 200, 'application/json; charset=utf-8'),
            default => Response::text($this->renderer->renderLlms(false), 200, 'text/plain; charset=utf-8'),
        };
    }
}
