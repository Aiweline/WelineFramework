<?php

declare(strict_types=1);

namespace Weline\Eav\Controller\Api;

use Weline\Eav\Api\Options\EavOptionsQueryInterface;
use Weline\Framework\App\Controller\BackendController;

/**
 * EAV option API controller used by the visual editor.
 *
 * ORM access stays inside EavOptionsQuery; the HTTP layer only translates
 * request scalars to the public data-only contract and serializes its payload.
 */
class Options extends BackendController
{
    public function __construct(
        private readonly EavOptionsQueryInterface $optionsQuery,
    ) {
    }

    /**
     * GET /weline/eav/api/options?entity_code=product&attribute_code=color
     * GET /weline/eav/api/options?attribute_id=123
     */
    public function getIndex(): string
    {
        return $this->fetchJson($this->optionsQuery->queryOptions([
            'entity_code' => $this->request->getParam('entity_code'),
            'attribute_code' => $this->request->getParam('attribute_code'),
            'attribute_id' => $this->request->getParam('attribute_id'),
            'search' => $this->request->getParam('search'),
            'page' => $this->request->getParam('page') ?? 1,
            'limit' => $this->request->getParam('limit') ?? 100,
        ]));
    }

    /** GET /weline/eav/api/options/attributes?entity_code=product */
    public function getAttributes(): string
    {
        return $this->fetchJson($this->optionsQuery->queryAttributes([
            'entity_code' => $this->request->getParam('entity_code'),
            'set_id' => $this->request->getParam('set_id'),
            'has_option_only' => $this->request->getParam('has_option_only'),
        ]));
    }

    /** GET /weline/eav/api/options/entities */
    public function getEntities(): string
    {
        return $this->fetchJson($this->optionsQuery->queryEntities());
    }
}
