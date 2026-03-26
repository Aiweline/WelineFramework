<?php

declare(strict_types=1);

namespace WeShop\Search\Controller\Frontend\Search;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Search\Service\SearchPageDataService;

class Index extends BaseController
{
    protected const CONTENT_TEMPLATE = 'templates/Frontend/Search/index';

    protected ?string $layoutType = 'search';

    public function __construct(
        private readonly SearchPageDataService $searchPageDataService
    ) {
    }

    public function index(): string
    {
        $pageData = $this->searchPageDataService->build(
            $this->readKeyword(),
            $this->collectFilters(),
            $this->readPositiveInt('page', 1),
            $this->readPositiveInt('page_size', 20)
        );

        foreach ($pageData as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->renderPage();
    }

    /**
     * @return array<string, mixed>
     */
    private function collectFilters(): array
    {
        $query = method_exists($this->request, 'getQuery') ? $this->request->getQuery() : null;
        if (is_array($query)) {
            $filters = [];
            foreach ($query as $field => $value) {
                if (in_array($field, ['q', 'page', 'page_size'], true)) {
                    continue;
                }

                if ($value === null || $value === '') {
                    continue;
                }

                $filters[$field] = $value;
            }

            return $filters;
        }

        $filters = [];
        foreach (['category_id', 'price_min', 'price_max', 'order_by', 'order_dir'] as $field) {
            $value = $this->request->getParam($field);
            if ($value === null || $value === '') {
                continue;
            }
            $filters[$field] = $value;
        }

        return $filters;
    }

    private function readKeyword(): string
    {
        return trim((string) ($this->request->getParam('q') ?? ''));
    }

    private function readPositiveInt(string $field, int $default): int
    {
        return max(1, (int) ($this->request->getParam($field) ?? $default));
    }

    protected function renderPage(): string
    {
        return $this->fetchTemplateWithEvents(self::CONTENT_TEMPLATE);
    }
}
