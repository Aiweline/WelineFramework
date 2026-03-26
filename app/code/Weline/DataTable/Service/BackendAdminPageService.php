<?php

declare(strict_types=1);

namespace Weline\DataTable\Service;

use Weline\DataTable\Helper\TableContext;
use Weline\DataTable\Taglib\Field;
use Weline\DataTable\Taglib\Form;
use Weline\DataTable\Taglib\Table;
use Weline\DataTable\Taglib\TableFilter;
use Weline\DataTable\Taglib\TableHeader;

class BackendAdminPageService
{
    /**
     * @var array<string,array<string,string>>
     */
    private const DOCUMENTS = [
        'quickstart' => [
            'title' => 'Quick Start',
            'filename' => '快速入门指南.md',
            'summary' => 'Boot the demo routes, seed demo data, and verify the core table and form flows.',
        ],
        'guide' => [
            'title' => 'Usage Guide',
            'filename' => '使用指南.md',
            'summary' => 'Route conventions, tag usage, backend/frontend API notes, and expected behaviors.',
        ],
        'testing' => [
            'title' => 'Test Cases',
            'filename' => '测试用例文档.md',
            'summary' => 'Manual and automated verification points for CRUD, join, upload, and auto-generation paths.',
        ],
        'api' => [
            'title' => 'API Reference',
            'filename' => 'API参考文档.md',
            'summary' => 'Relevant REST endpoints used by the DataTable demos and admin verification pages.',
        ],
        'troubleshooting' => [
            'title' => 'Troubleshooting',
            'filename' => '故障排查文档.md',
            'summary' => 'Common runtime failures, route pitfalls, and debugging notes for demo and taglib flows.',
        ],
    ];

    /**
     * @return array<string,mixed>
     */
    public function getDashboardData(): array
    {
        $scenarios = $this->getScenarioCatalog();
        $models = $this->getModelCatalog();
        $docs = $this->getDocumentationCatalog();

        return [
            'summary' => [
                'scenario_count' => count($scenarios),
                'direct_demo_count' => count(array_filter($scenarios, static fn (array $scenario): bool => ($scenario['group'] ?? '') === 'Direct demos')),
                'compatibility_route_count' => count(array_filter($scenarios, static fn (array $scenario): bool => ($scenario['group'] ?? '') === 'Compatibility routes')),
                'model_count' => count($models),
                'doc_count' => count($docs),
            ],
            'scenarios' => array_values($scenarios),
            'models' => array_values($models),
            'docs' => array_values($docs),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getDocumentationPageData(string $selectedKey): array
    {
        $docs = $this->getDocumentationCatalog();
        if (!isset($docs[$selectedKey])) {
            $selectedKey = 'quickstart';
        }

        $selectedDoc = $docs[$selectedKey];
        $selectedDoc['content'] = $this->loadDocumentContent((string) ($selectedDoc['filename'] ?? ''));

        return [
            'docs' => array_values($docs),
            'selectedDoc' => $selectedDoc,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getTagVerificationReport(): array
    {
        $sections = [
            'tag_registration' => $this->verifyTagRegistration(),
            'table_functionality' => $this->verifyTableFunctionality(),
            'field_validation' => $this->verifyFieldValidation(),
            'context_management' => $this->verifyContextManagement(),
            'attribute_inheritance' => $this->verifyAttributeInheritance(),
            'auto_generation' => $this->verifyAutoGeneration(),
        ];

        $totalChecks = 0;
        $passedChecks = 0;
        foreach ($sections as $section) {
            foreach ($section as $result) {
                $totalChecks++;
                if (($result['status'] ?? '') === 'success') {
                    $passedChecks++;
                }
            }
        }

        return [
            'summary' => [
                'total_checks' => $totalChecks,
                'passed_checks' => $passedChecks,
                'status' => $passedChecks === $totalChecks ? 'success' : 'warning',
            ],
            'sections' => $sections,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getScenarioCatalog(): array
    {
        return [
            [
                'route' => 'basic',
                'title' => 'Basic Table',
                'description' => 'Single-model CRUD, toolbar actions, filters, sorting, pagination, export, and form binding.',
                'group' => 'Direct demos',
                'icon' => 'table',
            ],
            [
                'route' => 'join',
                'title' => 'Join Query',
                'description' => 'Alias-prefixed fields across users and orders with searchable and sortable joined columns.',
                'group' => 'Direct demos',
                'icon' => 'link-variant',
            ],
            [
                'route' => 'form',
                'title' => 'Standalone Form',
                'description' => 'Inline standalone form that creates users and refreshes its related table.',
                'group' => 'Direct demos',
                'icon' => 'form-select',
            ],
            [
                'route' => 'upload',
                'title' => 'Upload Fields',
                'description' => 'Image and file field rendering, preview, and persistence using the demo form manager.',
                'group' => 'Direct demos',
                'icon' => 'paperclip',
            ],
            [
                'route' => 'transaction',
                'title' => 'Transaction Save',
                'description' => 'Multi-model create flow that saves user and order data within the same transaction.',
                'group' => 'Direct demos',
                'icon' => 'database-sync',
            ],
            [
                'route' => 'dependency',
                'title' => 'Dependency Order',
                'description' => 'Dependency-based save ordering without a transaction wrapper so linked IDs still resolve.',
                'group' => 'Direct demos',
                'icon' => 'source-branch',
            ],
            [
                'route' => 'cascade',
                'title' => 'Cascade Delete',
                'description' => 'Delete a user and verify related orders are removed with the refreshed demo tables.',
                'group' => 'Direct demos',
                'icon' => 'delete-sweep-outline',
            ],
            [
                'route' => 'performance',
                'title' => 'Auto-generated Table',
                'description' => 'Auto-generated table fields, filter fields, and toolbar behavior built from model metadata.',
                'group' => 'Direct demos',
                'icon' => 'speedometer',
            ],
            [
                'route' => 'filter',
                'title' => 'Filter Search',
                'description' => 'Compatibility route mapped to the working basic demo so legacy links no longer 404.',
                'group' => 'Compatibility routes',
                'icon' => 'filter-variant',
            ],
            [
                'route' => 'sorting',
                'title' => 'Sorting Pagination',
                'description' => 'Compatibility route mapped to the working basic demo for sort and paging coverage.',
                'group' => 'Compatibility routes',
                'icon' => 'sort',
            ],
            [
                'route' => 'crud',
                'title' => 'CRUD',
                'description' => 'Compatibility route mapped to the working basic demo for full create, update, delete flow coverage.',
                'group' => 'Compatibility routes',
                'icon' => 'database-edit-outline',
            ],
            [
                'route' => 'fieldTypes',
                'title' => 'Field Types',
                'description' => 'Compatibility route mapped to the upload demo so image and file field logic is exercised.',
                'group' => 'Compatibility routes',
                'icon' => 'form-textbox',
            ],
            [
                'route' => 'multiModel',
                'title' => 'Multi-model Query',
                'description' => 'Compatibility route mapped to the working join demo for old backend links.',
                'group' => 'Compatibility routes',
                'icon' => 'table-multiple',
            ],
            [
                'route' => 'autoGeneration',
                'title' => 'Auto Generation',
                'description' => 'Compatibility route mapped to the working performance demo for metadata-driven rendering.',
                'group' => 'Compatibility routes',
                'icon' => 'auto-fix',
            ],
            [
                'route' => 'inheritance',
                'title' => 'Attribute Inheritance',
                'description' => 'Dedicated verification page that isolates the attribute inheritance and context propagation checks.',
                'group' => 'Compatibility routes',
                'icon' => 'layers-triple-outline',
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getModelCatalog(): array
    {
        return [
            'Weline\DataTable\Model\TestUser' => [
                'class' => 'Weline\DataTable\Model\TestUser',
                'label' => 'TestUser',
                'purpose' => 'Primary CRUD model for table, form, filter, sort, upload, and delete flows.',
                'relations' => [
                    '1:N -> TestOrder.user_id',
                    '1:1 -> TestUserProfile.user_id',
                    '1:N -> TestUserAddress.user_id',
                ],
            ],
            'Weline\DataTable\Model\TestOrder' => [
                'class' => 'Weline\DataTable\Model\TestOrder',
                'label' => 'TestOrder',
                'purpose' => 'Order-side model for join, dependency, transaction, and cascade-delete demos.',
                'relations' => [
                    'N:1 -> TestUser.id',
                ],
            ],
            'Weline\DataTable\Model\TestProduct' => [
                'class' => 'Weline\DataTable\Model\TestProduct',
                'label' => 'TestProduct',
                'purpose' => 'Product metadata model used by the auto-generated performance demo.',
                'relations' => [
                    'Standalone performance dataset',
                ],
            ],
            'Weline\DataTable\Model\TestUserProfile' => [
                'class' => 'Weline\DataTable\Model\TestUserProfile',
                'label' => 'TestUserProfile',
                'purpose' => 'Supplemental relation model for richer join and cascade test data.',
                'relations' => [
                    'N:1 -> TestUser.id',
                ],
            ],
            'Weline\DataTable\Model\TestUserAddress' => [
                'class' => 'Weline\DataTable\Model\TestUserAddress',
                'label' => 'TestUserAddress',
                'purpose' => 'Address relation model used to validate multi-related cleanup paths.',
                'relations' => [
                    'N:1 -> TestUser.id',
                ],
            ],
        ];
    }

    /**
     * @return array<string,array<string,string>>
     */
    public function getDocumentationCatalog(): array
    {
        $result = [];
        foreach (self::DOCUMENTS as $key => $document) {
            $content = $this->loadDocumentContent($document['filename']);
            $result[$key] = [
                'key' => $key,
                'title' => $document['title'],
                'filename' => $document['filename'],
                'summary' => $document['summary'],
                'preview' => $this->buildDocumentPreview($content),
            ];
        }

        return $result;
    }

    private function loadDocumentContent(string $filename): string
    {
        if ($filename === '') {
            return '';
        }

        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'doc' . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            return 'Document file is missing: ' . $filename;
        }

        $content = (string) file_get_contents($path);
        return trim($content);
    }

    private function buildDocumentPreview(string $content): string
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $previewLines = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $line = preg_replace('/^[#>*`\-\d.\s]+/u', '', $line) ?: '';
            if ($line === '') {
                continue;
            }
            $previewLines[] = $line;
            if (count($previewLines) >= 3) {
                break;
            }
        }

        return implode(' ', $previewLines);
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function verifyTagRegistration(): array
    {
        $tags = [
            'd-table' => Table::class,
            't-header' => TableHeader::class,
            't-filter' => TableFilter::class,
            'field' => Field::class,
            'd-form' => Form::class,
        ];

        $results = [];
        foreach ($tags as $tagName => $className) {
            if (!class_exists($className)) {
                $results[$tagName] = [
                    'status' => 'error',
                    'message' => 'Missing class: ' . $className,
                ];
                continue;
            }

            $requiredMethods = ['name', 'tag', 'attr', 'callback'];
            $missingMethods = [];
            foreach ($requiredMethods as $method) {
                if (!method_exists($className, $method)) {
                    $missingMethods[] = $method;
                }
            }

            if ($missingMethods) {
                $results[$tagName] = [
                    'status' => 'error',
                    'message' => 'Missing methods: ' . implode(', ', $missingMethods),
                ];
                continue;
            }

            $nameMatches = $className::name() === $tagName;
            $isTagEnabled = $className::tag() === true;
            $attrs = $className::attr();

            $results[$tagName] = [
                'status' => $nameMatches && $isTagEnabled && is_array($attrs) ? 'success' : 'error',
                'message' => $nameMatches && $isTagEnabled && is_array($attrs)
                    ? 'Tag registered and callable.'
                    : 'Tag metadata is inconsistent.',
            ];
        }

        return $results;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function verifyTableFunctionality(): array
    {
        $results = [];

        try {
            $callback = Table::callback();

            try {
                $callback('d-table', [], ['', '', ''], []);
                $results['required_params'] = [
                    'status' => 'error',
                    'message' => 'Missing required model/scope did not fail.',
                ];
            } catch (\Throwable) {
                $results['required_params'] = [
                    'status' => 'success',
                    'message' => 'Required model/scope validation is enforced.',
                ];
            }

            $result = $callback('d-table', [], ['', '', ''], [
                'model' => 'Weline\DataTable\Model\TestUser',
                'scope' => 'backend-verification-table',
            ]);
            $results['basic_render'] = [
                'status' => is_string($result) && $result !== '' ? 'success' : 'error',
                'message' => is_string($result) && $result !== ''
                    ? 'Single-model table HTML renders successfully.'
                    : 'Single-model table HTML is empty.',
            ];

            $multiResult = $callback('d-table', [], ['', '', ''], [
                'model' => 'Weline\DataTable\Model\TestUser as u, Weline\DataTable\Model\TestOrder as o',
                'join' => 'left o on u.id = o.user_id',
                'scope' => 'backend-verification-join',
            ]);
            $results['multi_model_render'] = [
                'status' => is_string($multiResult) && str_contains($multiResult, 'modelConfig') ? 'success' : 'error',
                'message' => is_string($multiResult) && str_contains($multiResult, 'modelConfig')
                    ? 'Joined model configuration is embedded in the table payload.'
                    : 'Joined model configuration is missing from the table payload.',
            ];
        } catch (\Throwable $throwable) {
            $results['exception'] = [
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ];
        }

        return $results;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function verifyFieldValidation(): array
    {
        $results = [];

        try {
            $callback = Field::callback();
            TableContext::pushChildTag('t-filter', 'backend-verification-filter', [
                'type' => 't-filter',
                'scope' => 'backend-verification-filter',
            ]);

            try {
                $callback('field', [], ['', '', ''], ['name' => 'test_field']);
                $results['belong_required'] = [
                    'status' => 'error',
                    'message' => 'Missing belong attribute did not fail.',
                ];
            } catch (\Throwable) {
                $results['belong_required'] = [
                    'status' => 'success',
                    'message' => 'Missing belong attribute is rejected.',
                ];
            }

            $validField = $callback('field', [], ['', '', ''], [
                'belong' => 't-filter',
                'name' => 'test_field',
                'type' => 'text',
            ]);
            $results['valid_field'] = [
                'status' => is_string($validField) && $validField !== '' ? 'success' : 'error',
                'message' => is_string($validField) && $validField !== ''
                    ? 'Valid field metadata renders successfully.'
                    : 'Valid field metadata render returned an empty result.',
            ];
        } catch (\Throwable $throwable) {
            $results['exception'] = [
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ];
        } finally {
            TableContext::clearAll();
        }

        return $results;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function verifyContextManagement(): array
    {
        $results = [];

        try {
            TableContext::clearAll();

            $context = [
                'model' => 'Weline\DataTable\Model\TestUser',
                'scope' => 'backend-context-scope',
                'sortable' => true,
            ];

            TableContext::setTableContext('backend-context-scope', $context);
            $retrieved = TableContext::getTableContext('backend-context-scope');

            $results['set_and_get'] = [
                'status' => is_array($retrieved) && ($retrieved['model'] ?? '') === 'Weline\DataTable\Model\TestUser'
                    ? 'success'
                    : 'error',
                'message' => is_array($retrieved) && ($retrieved['model'] ?? '') === 'Weline\DataTable\Model\TestUser'
                    ? 'Table context is stored and retrieved correctly.'
                    : 'Table context could not be retrieved correctly.',
            ];

            TableContext::pushChildTag('t-header', 'backend-header-scope', ['type' => 't-header']);
            TableContext::popTag();
            $results['stack_management'] = [
                'status' => 'success',
                'message' => 'Child tag push/pop flow completed without exception.',
            ];
        } catch (\Throwable $throwable) {
            $results['exception'] = [
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ];
        } finally {
            TableContext::clearAll();
        }

        return $results;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function verifyAttributeInheritance(): array
    {
        $results = [];

        try {
            TableContext::clearAll();
            TableContext::pushChildTag('d-table', 'parent-scope', [
                'type' => 'd-table',
                'scope' => 'parent-scope',
                'model' => 'Weline\DataTable\Model\TestUser',
                'sortable' => true,
                'searchable' => true,
            ]);

            $inherited = TableContext::inheritTableAttributes(
                ['scope' => 'child-scope'],
                'child-scope-header',
                ['model', 'sortable', 'searchable']
            );

            $results['model_inheritance'] = [
                'status' => ($inherited['model'] ?? '') === 'Weline\DataTable\Model\TestUser' ? 'success' : 'error',
                'message' => ($inherited['model'] ?? '') === 'Weline\DataTable\Model\TestUser'
                    ? 'Model attribute was inherited correctly.'
                    : 'Model attribute inheritance failed.',
            ];
            $results['sortable_inheritance'] = [
                'status' => ($inherited['sortable'] ?? false) === true ? 'success' : 'error',
                'message' => ($inherited['sortable'] ?? false) === true
                    ? 'Sortable attribute was inherited correctly.'
                    : 'Sortable attribute inheritance failed.',
            ];
            $results['scope_generation'] = [
                'status' => str_contains((string) ($inherited['scope'] ?? ''), 'child-scope-header') ? 'success' : 'error',
                'message' => str_contains((string) ($inherited['scope'] ?? ''), 'child-scope-header')
                    ? 'Derived child scope was generated correctly.'
                    : 'Derived child scope was not generated correctly.',
            ];
        } catch (\Throwable $throwable) {
            $results['exception'] = [
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ];
        } finally {
            TableContext::clearAll();
        }

        return $results;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function verifyAutoGeneration(): array
    {
        $results = [];

        try {
            $callback = Table::callback();
            $result = $callback('d-table', [], ['', '', ''], [
                'model' => 'Weline\DataTable\Model\TestUser',
                'scope' => 'auto-generation-scope',
            ]);

            $results['generated_tags'] = [
                'status' => is_string($result) && str_contains($result, 't-filter') && str_contains($result, 't-header')
                    ? 'success'
                    : 'error',
                'message' => is_string($result) && str_contains($result, 't-filter') && str_contains($result, 't-header')
                    ? 'Auto-generated filter and header tags are present.'
                    : 'Auto-generated filter/header tags are missing.',
            ];

            $results['manager_bootstrap'] = [
                'status' => is_string($result) && str_contains($result, 'DataTableManager') ? 'success' : 'error',
                'message' => is_string($result) && str_contains($result, 'DataTableManager')
                    ? 'DataTable manager bootstrap is present in the output.'
                    : 'DataTable manager bootstrap is missing from the output.',
            ];
        } catch (\Throwable $throwable) {
            $results['exception'] = [
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ];
        }

        return $results;
    }
}
