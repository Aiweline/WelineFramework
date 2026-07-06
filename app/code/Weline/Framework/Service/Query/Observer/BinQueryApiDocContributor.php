<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Service\Query\QueryProviderRegistry;

class BinQueryApiDocContributor implements ObserverInterface
{
    public function __construct(
        private readonly QueryProviderRegistry $queryProviderRegistry
    ) {
    }

    public function execute(Event &$event): void
    {
        $apis = $event->getData('apis');
        if (!\is_array($apis)) {
            $apis = [];
        }

        $apis['BinQuery SDK'] = $this->mergeDocs(
            \is_array($apis['BinQuery SDK'] ?? null) ? $apis['BinQuery SDK'] : [],
            $this->generateDocs()
        );

        $event->setData('apis', $apis);
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergeDocs(array $existing, array $incoming): array
    {
        $merged = [];
        foreach (array_merge($existing, $incoming) as $doc) {
            if (!\is_array($doc)) {
                continue;
            }

            $key = implode('|', [
                (string)($doc['version'] ?? ''),
                (string)($doc['class'] ?? ''),
                (string)($doc['method'] ?? ''),
            ]);
            $merged[$key] = $doc;
        }

        return array_values($merged);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateDocs(): array
    {
        $externalOperations = 0;
        foreach ($this->queryProviderRegistry->getAllDescriptors() as $providerDescriptor) {
            foreach (($providerDescriptor['operations'] ?? []) as $operationDescriptor) {
                if (
                    \is_array($operationDescriptor)
                    && ($operationDescriptor['external'] ?? false) === true
                    && ($operationDescriptor['frontend'] ?? false) === true
                ) {
                    $externalOperations++;
                }
            }
        }

        $common = [
            'module' => 'Weline_Framework',
            'version' => 'binquery-v1',
            'class' => 'BinQuerySDK',
            'route' => [
                'method' => 'BINQUERY',
                'path' => '/bin/query',
                'is_backend' => false,
                'browser_direct' => false,
                'implementation' => '/bin/query',
            ],
            'responses' => [
                '200' => [
                    'description' => 'Binary WQB1 response: {ok,data,error,request_id}.',
                    'type' => 'application/x-weline-query-bin',
                ],
            ],
            'binquery' => true,
        ];

        return [
            $common + [
                'method' => 'overview',
                'document' => [
                    'summary' => 'BinQuery official gateway and SDK capability',
                    'description' => 'External SDKs derive https://{domain}/bin/query from domain, default to area=frontend, and use temporary Weline_Api app access_token as apiKey.',
                    'tags' => ['BinQuery SDK'],
                    'category' => 'BinQuery SDK',
                    'deprecated' => false,
                ],
                'parameters' => [
                    ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => 'Site domain, for example example.com.'],
                    ['name' => 'apiKey', 'type' => 'string', 'required' => true, 'description' => 'Weline_Api third-party app access_token; temporary, default TTL 3600 seconds.'],
                ],
                'example' => [
                    'domain' => 'example.com',
                    'download' => 'pub/source/binquery-php, pub/source/binquery-js',
                    'downloads' => [
                        ['label' => '下载 PHP SDK', 'url' => '/dev/tool/docs/api/sdk-download?sdk=php'],
                        ['label' => '下载 JS SDK', 'url' => '/dev/tool/docs/api/sdk-download?sdk=js'],
                    ],
                    'guide_url' => '/dev/tool/docs/api/sdk-guide?doc=sdk',
                    'derived_path' => '/bin/query',
                    'protocol' => 'binquery-v1',
                    'api_key_source' => 'Create/authorize a Weline_Api third-party app, then exchange code through POST /api/rest/v1/apps/token.',
                    'api_key_type' => 'temporary access_token',
                    'api_key_ttl' => '3600 seconds',
                    'refresh_token_ttl' => '2592000 seconds',
                    'scope' => 'Weline_Framework::binquery or Weline_Framework::binquery::post',
                    'external_operation_count' => $externalOperations,
                ],
            ],
            $common + [
                'method' => 'php-sdk',
                'document' => [
                    'summary' => 'PHP SDK download and install',
                    'description' => 'Composer package aiweline/binquery-php; repository source directory pub/source/binquery-php.',
                    'tags' => ['BinQuery SDK', 'PHP'],
                    'category' => 'BinQuery SDK',
                    'deprecated' => false,
                ],
                'parameters' => [],
                'example' => [
                    'package' => 'aiweline/binquery-php',
                    'download' => 'pub/source/binquery-php',
                    'download_url' => '/dev/tool/docs/api/sdk-download?sdk=php',
                    'install' => 'composer require aiweline/binquery-php',
                    'docs' => 'app/code/Weline/Framework/doc/BinQuery/SDK使用指南.md',
                    'guide_url' => '/dev/tool/docs/api/sdk-guide?doc=sdk',
                    'code' => "use Aiweline\\BinQuery\\BinQueryClient;\n\n\$client = BinQueryClient::connect([\n    'domain' => 'example.com',\n    'apiKey' => getenv('WELINE_BINQUERY_KEY'),\n]);\n\nif (\$client->hasOperation('theme', 'list')) {\n    \$docs = \$client->docs('theme', 'list');\n    \$result = \$client->call('theme', 'list', ['page' => 1, 'page_size' => 20]);\n}",
                ],
            ],
            $common + [
                'method' => 'js-sdk',
                'document' => [
                    'summary' => 'JS SDK download and install',
                    'description' => 'npm package @aiweline/binquery; repository source directory pub/source/binquery-js.',
                    'tags' => ['BinQuery SDK', 'JavaScript'],
                    'category' => 'BinQuery SDK',
                    'deprecated' => false,
                ],
                'parameters' => [],
                'example' => [
                    'package' => '@aiweline/binquery',
                    'download' => 'pub/source/binquery-js',
                    'download_url' => '/dev/tool/docs/api/sdk-download?sdk=js',
                    'install' => 'npm install @aiweline/binquery',
                    'docs' => 'app/code/Weline/Framework/doc/BinQuery/SDK使用指南.md',
                    'guide_url' => '/dev/tool/docs/api/sdk-guide?doc=sdk',
                    'code' => "import { BinQueryClient } from '@aiweline/binquery';\n\nconst client = await BinQueryClient.connect({\n  domain: 'example.com',\n  apiKey: process.env.WELINE_BINQUERY_KEY,\n});\n\nif (await client.hasOperation('theme', 'list')) {\n  const docs = await client.docs('theme', 'list');\n  const result = await client.call('theme', 'list', { page: 1, page_size: 20 });\n}",
                ],
            ],
            $common + [
                'method' => 'protocol',
                'document' => [
                    'summary' => 'Protocol guide for unsupported languages',
                    'description' => 'Unsupported languages derive https://{domain}/bin/query from domain, POST a WQB1 binary payload, and send Authorization: Bearer {apiKey} with X-Weline-BinQuery-Protocol: binquery-v1.',
                    'tags' => ['BinQuery SDK', 'Protocol'],
                    'category' => 'BinQuery SDK',
                    'deprecated' => false,
                ],
                'parameters' => [
                    ['name' => 'type', 'type' => 'string', 'required' => true, 'description' => 'connect/query/call/graph.'],
                    ['name' => '__wq_cache', 'type' => 'string', 'required' => false, 'description' => 'SDK cache:auto marker; the server recalculates and validates it.'],
                ],
                'example' => [
                    'docs' => 'app/code/Weline/Framework/doc/BinQuery/协议对接指南.md',
                    'guide_url' => '/dev/tool/docs/api/sdk-guide?doc=protocol',
                    'derived_path' => '/bin/query',
                    'protocol' => 'binquery-v1',
                    'content_type' => 'application/x-weline-query-bin',
                    'response' => ['ok' => true, 'data' => [], 'error' => null, 'request_id' => '...'],
                ],
            ],
        ];
    }
}
