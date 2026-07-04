<?php
declare(strict_types=1);

namespace Weline\Cms\Observer;

use Weline\Cms\Service\PageService;
use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;

class ProcessCmsPageUriBefore implements ObserverInterface
{
    /** @var list<string> */
    private const BYPASS_PREFIXES = [
        'admin',
        'backend',
        'api',
        'rest',
        'graphql',
        'static',
        'pub/static',
        'pub/media',
        'media',
        'uploads',
        'cms',
        'theme',
    ];

    public function __construct(
        private readonly Request $request,
        private readonly PageService $pageService
    ) {
    }

    public function execute(Event &$event): void
    {
        if ($this->request->isBackend() || $this->request->isApiBackend() || $this->request->isApiFrontend()) {
            return;
        }

        /** @var DataObject|null $data */
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }

        $rule = $data->getData('rule');
        $ruleArray = $rule instanceof DataObject ? $rule->getData() : (is_array($rule) ? $rule : []);
        if (!empty($ruleArray['module'])) {
            return;
        }

        $path = $data->getData('path');
        if (!is_string($path)) {
            return;
        }

        try {
            $slug = $this->pageService->parseSlugPreviewPath($path);
        } catch (\Throwable) {
            return;
        }

        $identifier = (string)($slug['identifier'] ?? '');
        $previewByPath = !empty($slug['preview']);
        $previewByQuery = (int)$this->request->getParam('preview', 0) === 1
            || (int)$this->request->getParam(PageService::PREVIEW_QUERY_FLAG, 0) === 1;
        $preview = $previewByPath || $previewByQuery;
        $identifierCandidates = $this->buildIdentifierCandidates($path, $identifier);
        if ($identifierCandidates === []) {
            return;
        }

        $page = null;
        try {
            $websiteId = (int)$this->request->getParam('website_id', $this->request->getParam('site_id', 0));
            foreach ($identifierCandidates as $candidateIdentifier) {
                $page = $this->pageService->getPageByIdentifier(
                    $candidateIdentifier,
                    null,
                    $preview,
                    $websiteId > 0 ? $websiteId : null
                );
                if ($page !== null) {
                    $identifier = $candidateIdentifier;
                    break;
                }
            }
        } catch (\Throwable) {
            return;
        }
        if ($page === null || (!$preview && !$page->isPublished())) {
            return;
        }

        $this->request->setGet('identifier', $page->getIdentifier());
        $this->request->setGet('cms_identifier', $page->getIdentifier());
        $this->request->setGet('page_id', $page->getPageId());
        $this->request->setGet('website_id', $page->getWebsiteId());
        $this->request->setGet('website_code', $page->getWebsiteCode());
        $this->request->setGet('path_group', $page->getPathGroup());
        $this->request->setGet('slug', $page->getSlug());
        $this->request->setGet('scope', $page->getScope());
        if ($preview) {
            $this->request->setGet('preview', 1);
            $this->request->setGet(PageService::PREVIEW_QUERY_FLAG, 1);
            $previewVersion = (string)($slug['preview_version'] ?? '');
            if ($previewVersion !== '') {
                $this->request->setGet('preview_version', $previewVersion);
            }
        }
        $this->request->setData('params', $this->request->getParameterBag()->all());

        $data->setData('path', 'cms/frontend/page/view');
        $data->setData('rule', new DataObject($ruleArray));
    }

    /**
     * @return list<string>
     */
    private function buildIdentifierCandidates(string $eventPath, string $parsedIdentifier): array
    {
        $rawCandidates = [
            (string)(\w_env('request.uri', '') ?? ''),
            (string)(\w_env('full_request_uri', '') ?? ''),
            (string)$this->request->getServer('WELINE_ORIGIN_REQUEST_URI'),
            $eventPath,
            $parsedIdentifier,
        ];

        $candidates = [];
        foreach ($rawCandidates as $rawCandidate) {
            $rawCandidate = trim($rawCandidate);
            if ($rawCandidate === '') {
                continue;
            }
            try {
                $slug = $this->pageService->parseSlugPreviewPath($rawCandidate);
                $identifier = (string)($slug['identifier'] ?? '');
            } catch (\Throwable) {
                continue;
            }
            if ($identifier === '' || $this->shouldBypass($identifier) || $this->generatedFrontendRouteExists($identifier)) {
                continue;
            }
            $candidates[] = $identifier;
        }

        return array_values(array_unique($candidates));
    }

    private function shouldBypass(string $identifier): bool
    {
        if (str_contains($identifier, '.') || str_contains($identifier, '..')) {
            return true;
        }

        foreach (self::BYPASS_PREFIXES as $prefix) {
            if ($identifier === $prefix || str_starts_with($identifier, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function generatedFrontendRouteExists(string $identifier): bool
    {
        if (!is_file(Env::path_FRONTEND_PC_ROUTER_FILE)) {
            return false;
        }

        try {
            $routes = include Env::path_FRONTEND_PC_ROUTER_FILE;
        } catch (\Throwable) {
            return false;
        }

        if (!is_array($routes)) {
            return false;
        }

        return isset($routes[$identifier]) || isset($routes[$identifier . '::GET']);
    }
}
