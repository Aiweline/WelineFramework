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
            '.well-known/ai-catalog.json', '.well-known/ard.json' => $this->agentCatalog(),
            default => Response::text($this->renderer->renderLlms(false), 200, 'text/plain; charset=utf-8'),
        };
    }

    /**
     * ARD agent catalog manifest; served for both the normative ard.json and the
     * predecessor ai-catalog.json well-known paths (same body).
     */
    private function agentCatalog(): Response
    {
        $baseUrl = $this->requestBaseUrl();
        $manifest = json_decode(
            IndustryProtocolDocuments::aiCatalogJson($this->renderer->agentCatalogEntries(), $baseUrl),
            true
        );

        // Edge-cache the manifest so the Lighthouse ARD fetch (and other agents)
        // get a sub-second response instead of a cold feed render.
        $response = Response::json(is_array($manifest) ? $manifest : ['specVersion' => '1.0', 'entries' => []], 200);
        $response->setHeader('Cache-Control', 'public, max-age=300, must-revalidate');

        return $response;
    }

    private function requestBaseUrl(): string
    {
        $fullUrl = (string) \Weline\Framework\Env\WelineEnv::server('WELINE_FULL_REQUEST_URI', '');
        if ($fullUrl !== '' && preg_match('#^https?://#i', $fullUrl) === 1) {
            $parts = parse_url($fullUrl);
            if (is_array($parts) && !empty($parts['host'])) {
                return (string) ($parts['scheme'] ?? 'https') . '://' . (string) $parts['host'];
            }
        }
        $host = (string) \Weline\Framework\Env\WelineEnv::server('HTTP_HOST', '');

        return $host !== '' ? (\Weline\Framework\Http\RequestSchemeResolver::current() . '://' . $host) : '';
    }
}
