<?php

declare(strict_types=1);

namespace WeShop\Search\Engine;

class OpenSearchEngine extends ElasticsearchEngine
{
    public function getEngineType(): string
    {
        return 'opensearch';
    }

    public function getEngineName(): string
    {
        return 'OpenSearch';
    }
}
