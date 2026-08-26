<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

final class ProductAdminSurfaceContractTest extends TestCase
{
    /** @var array<string, array{code: string, action: string}> */
    private const FEATURES = [
        'products' => ['code' => 'products', 'action' => 'products'],
        'offers' => ['code' => 'offers', 'action' => 'offers'],
        'skuRegistry' => ['code' => 'sku-registry', 'action' => 'skuregistry'],
        'media' => ['code' => 'media', 'action' => 'media'],
        'siteContent' => ['code' => 'site-content', 'action' => 'sitecontent'],
        'storeCopy' => ['code' => 'store-copy', 'action' => 'storecopy'],
        'shards' => ['code' => 'shards', 'action' => 'shards'],
    ];

    public function testEveryProductMenuRouteHasAnExactControllerAcl(): void
    {
        $menu = $this->read('app/code/Weline/Product/etc/backend/menu.xml');
        $controller = $this->read('app/code/Weline/Product/Controller/Backend/Catalog.php');

        foreach (self::FEATURES as $method => $feature) {
            $code = $feature['code'];
            $action = $feature['action'];
            self::assertStringContainsString(
                "source=\"Weline_Product::commerce:catalog:{$code}\"",
                $menu,
            );
            self::assertStringContainsString(
                "action=\"weline_product/backend/catalog/{$action}\"",
                $menu,
            );
            self::assertMatchesRegularExpression(
                '/#\\[Acl\\(\'Weline_Product::commerce:catalog:'
                . preg_quote($code, '/')
                . '\'.*?\\)\\]\\s+public function '
                . preg_quote($method, '/')
                . '\\(\\): string/s',
                $controller,
            );
        }

        self::assertStringContainsString('销售规格（高级维护）', $menu);
        self::assertStringContainsString('SKU 身份（高级维护）', $menu);
    }

    public function testUniversalProductPagesUseThePublicAdminContracts(): void
    {
        $controller = $this->read('app/code/Weline/Product/Controller/Backend/Catalog.php');
        $index = $this->read('app/code/Weline/Product/view/templates/backend/catalog/index.phtml');
        $edit = $this->read('app/code/Weline/Product/view/templates/backend/catalog/edit.phtml');

        self::assertStringContainsString('ProductAdminReadInterface', $controller);
        self::assertStringContainsString('ProductAdminCommandInterface', $controller);
        self::assertStringContainsString('ProductAdminCommand::ACTION_CREATE', $controller);
        self::assertStringContainsString('ProductAdminCommand::ACTION_SAVE', $controller);
        self::assertStringContainsString('ProductAdminCommand::ACTION_PUBLISH', $controller);
        self::assertStringNotContainsString('ProductRepository', $controller);
        self::assertStringNotContainsString('OfferRepository', $controller);

        foreach ([
            'product-management-<?= $escape($section) ?>',
            'product-create-form',
            'product-filter-form',
            'product-catalog-table',
            'product-edit-button',
        ] as $testId) {
            self::assertStringContainsString('data-testid="' . $testId . '"', $index);
        }
        self::assertStringContainsString('w:websites:website:select', $index);
        self::assertStringContainsString('name="store_ids[]"', $index);
        self::assertStringContainsString('name="store_ids[]" value="<?= $escape($store[\'store_id\'] ?? \'\') ?>" checked', $index);
        self::assertStringContainsString('data-supports-variants', $index);
        self::assertStringContainsString('product-create-axes', $index);
        self::assertStringContainsString('product-admin-state', $index);
        self::assertStringContainsString('Weline_Product::js/backend/product-admin.js', $index);
        self::assertStringContainsString('Weline_Product::css/backend/product-admin.css', $index);

        foreach ([
            'product-edit-workbench',
            'product-edit-form',
            'product-edit-offers',
            'product-edit-media',
            'product-publish-diagnostics',
            'product-edit-audit',
        ] as $testId) {
            self::assertStringContainsString('data-testid="' . $testId . '"', $edit);
        }
        foreach ([
            'overview',
            'basic',
            'attributes',
            'offers',
            'taxonomy',
            'stores',
            'fulfillment',
            'type',
            'diagnostics',
            'audit',
        ] as $panel) {
            self::assertStringContainsString('data-product-panel="' . $panel . '"', $edit);
        }
        self::assertStringContainsString('data-product-command="validate"', $edit);
        self::assertStringContainsString('data-product-command="publish"', $edit);
        self::assertStringContainsString('data-offer-price=', $edit);
        self::assertStringContainsString('name="store_ids[]"', $edit);
        self::assertStringContainsString('product-admin-state', $edit);
    }

    public function testLegacyProductCategoriesRouteRedirectsToCatalogHub(): void
    {
        $controller = $this->read('app/code/Weline/Product/Controller/Backend/Catalog.php');
        $menu = $this->read('app/code/Weline/Product/etc/backend/menu.xml');

        self::assertStringContainsString("redirect('weline_catalog/backend/category/index'", $controller);
        self::assertStringNotContainsString('renderCategoriesSection', $controller);
        self::assertStringNotContainsString('postCategoryPost', $controller);
        self::assertStringNotContainsString('weline_product/backend/catalog/categories', $menu);
    }

    public function testAttributeEditorUsesEavMetadataWithAccessibleVisualControls(): void
    {
        $edit = $this->read('app/code/Weline/Product/view/templates/backend/catalog/edit.phtml');
        $script = $this->read('app/code/Weline/Product/view/statics/js/backend/product-admin.js');
        $style = $this->read('app/code/Weline/Product/view/statics/css/backend/product-admin.css');
        $module = $this->read('app/code/Weline/Product/etc/module.php');

        foreach ([
            'data-testid="product-eav-editor"',
            'array_is_list($attributeCatalog)',
            'id="product-attribute-set"',
            'data-eav-set-panel',
            'data-eav-field',
            'data-eav-state',
            'data-eav-input',
            'data-testid="product-eav-advanced"',
            "'multiselect', 'multi_select', 'multiple'",
        ] as $contract) {
            self::assertStringContainsString($contract, $edit);
        }
        foreach ([
            'function collectVisualAttributes',
            'function mergeAdvancedAttributeRows',
            'function initializeEavEditor',
            "scope_state: state",
            "advancedInput.value = JSON.stringify(merged, null, 2)",
        ] as $contract) {
            self::assertStringContainsString($contract, $script);
        }
        self::assertStringContainsString('.w-product-eav__grid', $style);
        self::assertStringContainsString("'Weline_Eav' => '*'", $module);
        self::assertStringNotContainsString('fetch' . '(', $script);
        self::assertStringNotContainsString('XMLHttpRequest', $script);
    }

    public function testConfigurableOfferMatrixEditorUsesUnifiedAdminContracts(): void
    {
        $readService = $this->read('app/code/Weline/Product/Service/ProductAdminReadService.php');
        $edit = $this->read('app/code/Weline/Product/view/templates/backend/catalog/edit.phtml');
        $script = $this->read('app/code/Weline/Product/view/statics/js/backend/product-admin.js');

        self::assertStringContainsString("'offer_matrix'", $readService);
        foreach ([
            'data-product-offer-matrix',
            'data-product-variant-axes',
            'data-product-variant-sku-prefix',
            'data-product-variant-generate',
            'data-product-variant-rows',
            'data-product-variant-impact',
            'data-can-edit-structure',
        ] as $marker) {
            self::assertStringContainsString($marker, $edit);
        }
        self::assertStringContainsString('collectOfferMatrix', $script);
        self::assertStringContainsString('buildVariantCombinations', $script);
        self::assertStringContainsString('offer_matrix: collectOfferMatrix', $script);
        self::assertStringContainsString("window.Weline.Api.resource('product_admin')", $script);
        self::assertStringNotContainsString('fetch' . '(', $script);
        self::assertStringNotContainsString('XMLHttpRequest', $script);
    }

    public function testTaxonomyAndSecureMediaUseUnifiedAdminContracts(): void
    {
        $readService = $this->read('app/code/Weline/Product/Service/ProductAdminReadService.php');
        $commandService = $this->read('app/code/Weline/Product/Service/ProductAdminCommandService.php');
        $edit = $this->read('app/code/Weline/Product/view/templates/backend/catalog/edit.phtml');
        $script = $this->read('app/code/Weline/Product/view/statics/js/backend/product-admin.js');
        $categoryLink = $this->read('app/code/Weline/Product/Model/Shard/CategoryLink.php');
        $media = $this->read('app/code/Weline/Product/Model/Shard/Media.php');

        foreach ([
            'category_assignments',
            'media_assignments',
            'store_category_overrides',
            'store_media_overrides',
        ] as $field) {
            self::assertStringContainsString("'" . $field . "'", $readService);
            self::assertStringContainsString("'" . $field . "'", $commandService);
        }
        foreach ([
            'data-product-category-assignments',
            'data-product-media-assignments',
            'data-product-store-category-overrides',
            'data-product-store-media-overrides',
        ] as $marker) {
            self::assertStringContainsString($marker, $edit);
        }
        foreach ([
            'collectCategoryAssignments',
            'collectMediaAssignments',
            'collectStoreCategoryOverrides',
            'collectStoreMediaOverrides',
        ] as $collector) {
            self::assertStringContainsString('function ' . $collector, $script);
        }
        self::assertStringContainsString('category_assignments: collectCategoryAssignments', $script);
        self::assertStringContainsString('media_assignments: collectMediaAssignments', $script);
        self::assertStringContainsString('store_category_overrides: collectStoreCategoryOverrides', $script);
        self::assertStringContainsString('store_media_overrides: collectStoreMediaOverrides', $script);
        foreach (['STORE_ID', 'SCOPE_STATE', 'SELECTED', 'POSITION'] as $field) {
            self::assertStringContainsString('schema_fields_' . $field, $categoryLink);
        }
        foreach ([
            'STORE_ID',
            'SCOPE_STATE',
            'HIDDEN',
            'ROLE',
            'ASSET_ID',
            'ASSET_VISIBILITY',
            'MIME_TYPE',
            'ACCESS_POLICY_JSON',
        ] as $field) {
            self::assertStringContainsString('schema_fields_' . $field, $media);
        }
        self::assertStringNotContainsString('name="media_path"', $edit);
        self::assertStringNotContainsString('name="blob_key"', $edit);
        self::assertStringNotContainsString('fetch' . '(', $script);
        self::assertStringNotContainsString('XMLHttpRequest', $script);
    }

    public function testBackendBrowserUsesAclProtectedProductAdminResourceWithoutInlineRequests(): void
    {
        $provider = $this->read(
            'app/code/Weline/Product/extends/module/Weline_Framework/Query/ProductAdminQueryProvider.php',
        );
        $script = $this->read('app/code/Weline/Product/view/statics/js/backend/product-admin.js');
        $index = $this->read('app/code/Weline/Product/view/templates/backend/catalog/index.phtml');
        $edit = $this->read('app/code/Weline/Product/view/templates/backend/catalog/edit.phtml');

        self::assertStringContainsString("return 'product_admin';", $provider);
        self::assertStringContainsString("'auth' => 'backend'", $provider);
        self::assertStringContainsString("\$this->operation('command', (string)__('执行商品创建、保存、校验与生命周期命令'), 'write'", $provider);
        self::assertStringContainsString("'mode' => \$mode", $provider);
        self::assertStringContainsString(
            "public const ACL_SOURCE = 'Weline_Product::commerce:catalog:products';",
            $provider,
        );
        foreach (['search', 'creationContext', 'snapshot', 'command'] as $operation) {
            self::assertStringContainsString("'" . $operation . "'", $provider);
        }

        self::assertStringContainsString("window.Weline.Api.resource('product_admin')", $script);
        self::assertStringContainsString('keepBusinessResult: true', $script);
        self::assertMatchesRegularExpression("/commandEnvelope\\(\\s*'create'/", $script);
        self::assertStringContainsString("executeCommand('save'", $script);
        self::assertStringContainsString('data-product-command', $script);
        self::assertStringContainsString("scope_state = 'cleared'", $script);
        self::assertStringNotContainsString('fetch' . '(', $script);
        self::assertStringNotContainsString('XMLHttpRequest', $script);
        self::assertSame(1, substr_count($index, '<script type="application/json"'));
        self::assertSame(1, substr_count($edit, '<script type="application/json"'));
        self::assertSame(1, substr_count($index, '<script src='));
        self::assertSame(1, substr_count($edit, '<script src='));
        self::assertDoesNotMatchRegularExpression(
            '/<script(?![^>]*type="application\\/json"|[^>]*src=)[^>]*>\\s*\\S+/i',
            $index . $edit,
        );
    }

    public function testSecureMediaAssignmentsRejectLegacyInputsAndExposeStableDiagnostics(): void
    {
        $commandService = $this->read('app/code/Weline/Product/Service/ProductAdminCommandService.php');
        $edit = $this->read('app/code/Weline/Product/view/templates/backend/catalog/edit.phtml');
        $script = $this->read('app/code/Weline/Product/view/statics/js/backend/product-admin.js');

        foreach ([
            'product_media_legacy_input_forbidden',
            'product_media_asset_not_found',
            'product_media_asset_not_ready',
            'product_media_image_required',
            'product_download_asset_must_be_private',
        ] as $errorCode) {
            self::assertStringContainsString($errorCode, $commandService);
        }
        self::assertStringContainsString('FileAssetManagerInterface', $commandService);
        self::assertStringContainsString("array_key_exists('path', \$row)", $commandService);
        self::assertStringContainsString("array_key_exists('blob_key', \$row)", $commandService);
        self::assertStringNotContainsString('name="media_path"', $edit);
        self::assertStringNotContainsString('name="blob_key"', $edit);
        self::assertStringContainsString("event.origin !== window.location.origin", $script);
        self::assertStringContainsString("event.source !== frame.contentWindow", $script);
        self::assertStringContainsString("String(event.data.target || '') !== 'product-media-picker'", $script);
    }

    public function testAuditTimelineUsesImmutableProductEventReadModel(): void
    {
        $identityService = $this->read('app/code/Weline/Product/Service/ProductIdentityV2Service.php');
        $readService = $this->read('app/code/Weline/Product/Service/ProductAdminReadService.php');
        $edit = $this->read('app/code/Weline/Product/view/templates/backend/catalog/edit.phtml');

        foreach ([
            'ProductAuditLog::schema_fields_PRODUCT_UUID',
            'ProductAuditLog::schema_fields_ACTION',
            'ProductAuditLog::schema_fields_BEFORE_VERSION',
            'ProductAuditLog::schema_fields_AFTER_VERSION',
            'ProductAuditLog::schema_fields_PAYLOAD_JSON',
            'ProductAuditLog::schema_fields_CREATED_AT',
            'listAudit',
            'json_decode',
        ] as $contract) {
            self::assertStringContainsString($contract, $identityService);
        }
        self::assertStringContainsString('$this->identities->listAudit', $readService);
        self::assertStringNotContainsString('audit: []', $readService);
        self::assertStringContainsString('data-product-audit-timeline', $edit);
        self::assertStringContainsString('data-product-audit-event', $edit);
        self::assertStringContainsString("['before_version']", $edit);
        self::assertStringContainsString("['after_version']", $edit);
        self::assertStringContainsString("['payload']", $edit);
        self::assertStringNotContainsString("['request_hash']", $edit);
    }

    public function testInventoryEditorUsesAtomicPublicInventoryCapability(): void
    {
        $snapshot = $this->read('app/code/Weline/Product/Api/Data/ProductAdminSnapshot.php');
        $readService = $this->read('app/code/Weline/Product/Service/ProductAdminReadService.php');
        $commandService = $this->read('app/code/Weline/Product/Service/ProductAdminCommandService.php');
        $inventoryPort = $this->read('app/code/Weline/Inventory/Api/InventoryCatalogCopyCapabilityInterface.php');
        $edit = $this->read('app/code/Weline/Product/view/templates/backend/catalog/edit.phtml');
        $script = $this->read('app/code/Weline/Product/view/statics/js/backend/product-admin.js');

        self::assertStringContainsString('public readonly array $inventory', $snapshot);
        self::assertStringContainsString("'inventory' => \$this->inventory", $snapshot);

        self::assertStringContainsString('InventoryCatalogCopyCapabilityInterface', $readService);
        self::assertStringContainsString('getAvailability', $readService);
        self::assertStringContainsString('inventory:', $readService);

        self::assertStringContainsString('InventoryCatalogCopyCapabilityInterface', $commandService);
        self::assertStringContainsString('->transactional(', $commandService);
        self::assertStringContainsString('->setOnHand(', $commandService);
        self::assertStringNotContainsString('Inventory\\Service\\InventoryService', $commandService);

        foreach (['transactional', 'getAvailability', 'setOnHand'] as $method) {
            self::assertStringContainsString('function ' . $method, $inventoryPort);
        }

        foreach ([
            'data-inventory-row',
            'data-inventory-on-hand',
            'data-inventory-reserved',
            'data-inventory-available',
            'data-inventory-strategy',
            'data-inventory-sellable',
        ] as $contract) {
            self::assertStringContainsString($contract, $edit);
        }

        self::assertStringContainsString('function collectInventoryRows', $script);
        self::assertStringContainsString('inventory: collectInventoryRows(root)', $script);
        self::assertStringContainsString('Weline.Api', $script);
        self::assertStringNotContainsString('fetch(', $script);
    }

    public function testProviderSchemaRendersAccessibleTypedConfigurationEditor(): void
    {
        $readService = $this->read('app/code/Weline/Product/Service/ProductAdminReadService.php');
        $edit = $this->read('app/code/Weline/Product/view/templates/backend/catalog/edit.phtml');
        $script = $this->read('app/code/Weline/Product/view/statics/js/backend/product-admin.js');

        self::assertStringContainsString("'provider_fields' => \$providerFields", $readService);
        foreach ([
            'data-testid="product-provider-editor"',
            'data-provider-field',
            'data-provider-input',
            'data-provider-unknown',
            'for="<?= $escape($fieldId) ?>"',
        ] as $contract) {
            self::assertStringContainsString($contract, $edit);
        }
        foreach ([
            'function collectProviderConfiguration',
            'function parseProviderFieldValue',
            'provider_unknown_fields',
            'type_configuration: collectProviderConfiguration(root)',
        ] as $contract) {
            self::assertStringContainsString($contract, $script);
        }
        self::assertStringContainsString('Weline.Api', $script);
        self::assertStringNotContainsString('fetch(', $script);
    }

    public function testProviderSchemaNormalizationPreservesUnknownConfiguration(): void
    {
        $service = (new \ReflectionClass(
            \Weline\Product\Service\ProductAdminReadService::class,
        ))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'providerFormFields');
        $method->setAccessible(true);

        $normalized = $method->invoke($service, [
            'fields' => [
                'title' => [
                    'label' => 'Title',
                    'type' => 'text',
                    'required' => true,
                    'default' => 'Default',
                ],
                [
                    'code' => 'mode',
                    'label' => 'Mode',
                    'type' => 'select',
                    'default' => 'fixed',
                    'options' => [
                        'fixed' => 'Fixed',
                        ['value' => 'dynamic', 'label' => 'Dynamic'],
                    ],
                ],
                ['code' => 'unsafe', 'label' => 'Unsafe', 'type' => 'script'],
                ['code' => 'bad code', 'type' => 'string'],
                ['code' => 'title', 'type' => 'string'],
            ],
        ], [
            'title' => 'Current',
            'legacy_extension' => ['keep' => true],
        ]);

        self::assertCount(3, $normalized['fields']);
        self::assertSame('Current', $normalized['fields'][0]['value']);
        self::assertSame('fixed', $normalized['fields'][1]['value']);
        self::assertSame([
            ['value' => 'fixed', 'label' => 'Fixed'],
            ['value' => 'dynamic', 'label' => 'Dynamic'],
        ], $normalized['fields'][1]['options']);
        self::assertSame('string', $normalized['fields'][2]['type']);
        self::assertSame(
            ['legacy_extension' => ['keep' => true]],
            $normalized['unknown'],
        );
    }

    public function testPublishDiagnosticsAndLifecycleAreScopedAtomicAndArchivedReadOnly(): void
    {
        $context = $this->read('app/code/Weline/Product/Api/Data/ProductValidationContext.php');
        $result = $this->read('app/code/Weline/Product/Api/Data/ProductValidationResult.php');
        $command = $this->read('app/code/Weline/Product/Service/ProductAdminCommandService.php');
        $edit = $this->read('app/code/Weline/Product/view/templates/backend/catalog/edit.phtml');
        $script = $this->read('app/code/Weline/Product/view/statics/js/backend/product-admin.js');

        foreach ([
            'public string $locale',
            'public string $currency',
            'public array $inventory',
            'public array $stores',
        ] as $contract) {
            self::assertStringContainsString($contract, $context);
        }
        foreach ([
            "'summary' =>",
            "'groups' =>",
            "'severity' =>",
            'store_label',
            'offer_label',
        ] as $contract) {
            self::assertStringContainsString($contract, $result);
        }

        self::assertMatchesRegularExpression(
            '/private function transition\(.*?\$this->transactions->run\(.*?'
            . '\$this->offers->transition\(.*?\$this->products->transition\(/s',
            $command,
        );
        self::assertStringContainsString('product_archived_readonly', $command);

        foreach ([
            'data-diagnostic-group',
            'aria-live="polite"',
            '$canEditBusiness',
            '$canPublish = in_array',
            '$canArchive = in_array',
            'data-edit-business',
        ] as $contract) {
            self::assertStringContainsString($contract, $edit);
        }
        foreach ([
            'function renderDiagnosticGroup',
            'diagnostics.groups',
            'function initializeBusinessReadOnly',
        ] as $contract) {
            self::assertStringContainsString($contract, $script);
        }
        self::assertStringNotContainsString('fetch' . '(', $script);
        self::assertStringNotContainsString('XMLHttpRequest', $script);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . $path);
        self::assertIsString($content, 'Unable to read ' . $path);
        return $content;
    }
}
