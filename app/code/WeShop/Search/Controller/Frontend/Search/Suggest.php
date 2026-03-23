<?php

declare(strict_types=1);

namespace WeShop\Search\Controller\Frontend\Search;

use WeShop\Search\Service\SearchService;
use Weline\Framework\App\Controller\FrontendController;

class Suggest extends FrontendController
{
    public function __construct(
        private readonly SearchService $searchService
    ) {
    }

    public function index(): string
    {
        $keyword = trim((string) ($this->request->getParam('q') ?? ''));
        $limit = max(1, (int) ($this->request->getParam('limit') ?? 10));

        if ($keyword === '') {
            return $this->fetchJson([
                'success' => true,
                'suggestions' => [],
                'data' => [],
            ]);
        }

        $suggestions = $this->searchService->getSearchSuggestions($keyword, $limit);

        return $this->fetchJson([
            'success' => true,
            'suggestions' => $suggestions,
            'data' => $suggestions,
        ]);
    }
}
