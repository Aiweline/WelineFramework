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

        $response = match ($path) {
            'ai.txt' => Response::text(IndustryProtocolDocuments::aiTxt(), 200, 'text/plain; charset=utf-8'),
            'license.xml' => Response::text($this->renderer->renderLicenseXml(), 200, 'application/rsl+xml; charset=utf-8'),
            'trust.txt', '.well-known/trust.txt' => Response::text($this->renderer->renderTrustTxt(), 200, 'text/plain; charset=utf-8'),
            '.well-known/tdmrep.json' => Response::text(IndustryProtocolDocuments::tdmRepJson(), 200, 'application/json; charset=utf-8'),
            '.well-known/ai-catalog.json', '.well-known/ard.json' => $this->agentCatalog(),
            default => Response::text($this->renderer->renderLlms(false), 200, 'text/plain; charset=utf-8'),
        };

        // Agent discovery documents are fetched with arbitrary UAs and must stay
        // Set-Cookie-free: identity cookies stop ARD validators and CDN edge caching.
        // Request-scoped arm also prevents the WLS worker from re-queueing the cookie.
        \Weline\Framework\Http\Fpc\SharedFpcSetCookieSuppress::arm('industry_protocol_document');
        $response->setHeader(self::PROTOCOL_DOCUMENT_HEADER, '1');

        return $response;
    }

    /** Marks a response as an agent/AI discovery document (see StorefrontGenerationOutbound). */
    public const PROTOCOL_DOCUMENT_HEADER = 'X-Weline-Protocol-Document';

    /**
     * ARD agent catalog manifest; served for both the normative ard.json and the
     * predecessor ai-catalog.json well-known paths (same body).
     */
    private function agentCatalog(): Response
    {
        $baseUrl = $this->requestBaseUrl();
        $body = IndustryProtocolDocuments::aiCatalogJson($this->renderer->agentCatalogEntries(), $baseUrl);

        // ARD media type is application/ai-catalog+json (not generic application/json).
        // Validators that follow the link MIME reject a plain JSON content-type as
        // "schema invalid / cannot load" even when the body itself validates.
        $response = Response::text($body, 200, 'application/ai-catalog+json; charset=utf-8');
        // Edge-cache the manifest so ARD/Lighthouse fetches stay sub-second and avoid
        // cold feed renders that trip worker memory_pressure 503. CDN-* headers are
        // required for Cloudflare; Cache-Control alone still BYPASS when Set-Cookie
        // previously polluted the response (see StorefrontGenerationOutbound).
        $response->setHeader('Cache-Control', 'public, max-age=300, must-revalidate');
        $response->setHeader('CDN-Cache-Control', 'public, max-age=300');
        $response->setHeader('Cloudflare-CDN-Cache-Control', 'public, max-age=300');

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
