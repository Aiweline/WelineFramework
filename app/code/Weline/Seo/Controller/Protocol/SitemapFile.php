<?php

declare(strict_types=1);

namespace Weline\Seo\Controller\Protocol;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Seo\Service\Protocol\SitemapProtocolRenderer;

class SitemapFile extends FrontendController
{
    public function __construct(
        private readonly SitemapProtocolRenderer $renderer
    ) {
    }

    public function index(): Response
    {
        $path = $this->resolveSitemapRelativePath();
        if (!preg_match('#^sitemaps/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)/([A-Za-z0-9._-]+\.xml)$#D', $path, $matches)) {
            return Response::text(
                '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<error>' . htmlspecialchars((string)__('Sitemap 文件路径无效'), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</error>',
                404,
                'application/xml; charset=utf-8',
            );
        }

        $result = $this->renderer->renderFile($matches[1], $matches[2], $matches[3]);
        return Response::text($result['body'], $result['status'], 'application/xml; charset=utf-8');
    }

    private function resolveSitemapRelativePath(): string
    {
        $candidates = [
            (string)$this->request->getData('seo_sitemap_file'),
            (string)$this->request->getServer('WELINE_ORIGIN_REQUEST_URI'),
            (string)$this->request->getServer('ORIGIN_REQUEST_URI'),
            (string)($_SERVER['WELINE_ORIGIN_REQUEST_URI'] ?? ''),
            (string)$this->request->getUri(),
            (string)($_SERVER['REQUEST_URI'] ?? ''),
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $path = (string)(parse_url($candidate, PHP_URL_PATH) ?: $candidate);
            $path = strtolower(trim($path, '/'));
            if (str_starts_with($path, 'sitemaps/')) {
                return $path;
            }
        }

        return '';
    }
}
