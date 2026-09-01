<?php

declare(strict_types=1);

namespace Weline\Catalog\Extends\Module\Weline_Framework\Query;

use Weline\Catalog\Service\CatalogHubService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

final class CatalogQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly CatalogHubService $hub,
    ) {
    }

    public function getProviderName(): string
    {
        return 'catalog';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        if ($operation === 'spaces') {
            return [
                'success' => true,
                'spaces' => $this->hub->listSpaces(),
            ];
        }

        return $this->hub->execute($operation, $params);
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'catalog',
            'name' => (string)__('万能分类'),
            'description' => (string)__('Catalog hub facade for multi-space category trees.'),
            'module' => 'Weline_Catalog',
            'operations' => [
                ['name' => 'spaces', 'frontend' => false, 'mode' => 'read', 'params' => []],
                ['name' => 'tree', 'frontend' => true, 'mode' => 'read', 'params' => [
                    ['name' => 'space', 'type' => 'string', 'required' => true],
                    ['name' => 'scope_level', 'type' => 'string', 'required' => false],
                    ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                ]],
                ['name' => 'search', 'frontend' => false, 'mode' => 'read', 'params' => [
                    ['name' => 'space', 'type' => 'string', 'required' => true],
                    ['name' => 'q', 'type' => 'string', 'required' => true],
                ]],
                ['name' => 'save', 'frontend' => false, 'mode' => 'write', 'params' => [
                    ['name' => 'space', 'type' => 'string', 'required' => true],
                    ['name' => 'scope_level', 'type' => 'string', 'required' => true],
                    ['name' => 'website_id', 'type' => 'int', 'required' => true],
                ]],
                ['name' => 'readDisplaySelection', 'frontend' => false, 'mode' => 'read', 'params' => [
                    ['name' => 'space', 'type' => 'string', 'required' => true],
                    ['name' => 'scope_level', 'type' => 'string', 'required' => true],
                    ['name' => 'website_id', 'type' => 'int', 'required' => true],
                    ['name' => 'store_id', 'type' => 'int', 'required' => false],
                    ['name' => 'channel_id', 'type' => 'int', 'required' => false],
                ]],
                ['name' => 'saveDisplaySelection', 'frontend' => false, 'mode' => 'write', 'params' => [
                    ['name' => 'space', 'type' => 'string', 'required' => true],
                    ['name' => 'scope_level', 'type' => 'string', 'required' => true],
                    ['name' => 'website_id', 'type' => 'int', 'required' => true],
                    ['name' => 'store_id', 'type' => 'int', 'required' => false],
                    ['name' => 'channel_id', 'type' => 'int', 'required' => false],
                ]],
                ['name' => 'googleTaxonomyTree', 'frontend' => false, 'mode' => 'read', 'params' => []],
                ['name' => 'googleTaxonomySearch', 'frontend' => false, 'mode' => 'read', 'params' => [
                    ['name' => 'q', 'type' => 'string', 'required' => false],
                    ['name' => 'limit', 'type' => 'int', 'required' => false],
                ]],
                ['name' => 'enqueueGoogleTaxonomyAiTranslation', 'frontend' => false, 'mode' => 'write', 'params' => [
                    ['name' => 'locale', 'type' => 'string', 'required' => true],
                    ['name' => 'google_ids', 'type' => 'array', 'required' => false],
                ]],
            ],
        ];
    }
}
