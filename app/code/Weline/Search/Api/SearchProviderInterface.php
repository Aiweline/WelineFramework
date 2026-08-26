<?php

declare(strict_types=1);

namespace Weline\Search\Api;

use Weline\Search\Dto\IndexDocument;
use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;
use Weline\Search\Service\SearchExpression;

/**
 * Business searcher registered via extends/module/Weline_Search/Searcher/.
 */
interface SearchProviderInterface
{
    public function code(): string;

    public function label(): string;

    /** Lower runs earlier when type=all sections are ordered. */
    public function sortOrder(): int;

    public function expression(SearchRequest $request): SearchExpression;

    /**
     * Extra client params allowed when type === code() (name => constraint).
     *
     * @return array<string, array{type:string,required?:bool,min?:int,max?:int}>
     */
    public function allowedClientParams(): array;

    /** Optional hit template path (module::path). Empty uses platform default. */
    public function hitTemplate(): string;

    /**
     * Default path: compile expression through configured engine.
     * Complex modules may override (e.g. Product projection path).
     */
    public function execute(SearchRequest $request, SearchExpression $expression): SearchResult;

    /**
     * Optional documents to index for engine upsert (rebuild helpers).
     *
     * @return list<IndexDocument>
     */
    public function documentsForIndex(SearchRequest $request): array;
}
