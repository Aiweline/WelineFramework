<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 新建商品（catalog create）与编辑页规格矩阵共用同一套 variant matrix 管线：
 * EAV 规格维度多选 → axes → ProductVariantMatrixService::generate → offerSpecs。
 */
final class ProductCreateVariantFlowContractTest extends TestCase
{
    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function read(string $relativePath): string
    {
        $path = $this->moduleRoot() . '/' . ltrim($relativePath, '/');
        self::assertFileExists($path, 'Missing fixture: ' . $relativePath);
        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }

    public function testCreateConfigurableSubmitUsesCollectedVariantAxesNotManualJson(): void
    {
        $script = $this->read('view/statics/js/backend/product-admin.js');

        foreach ([
            'collectCreateVariantAxes',
            'validateCreateVariantAxes',
            'renderCreateVariantPreview',
            'buildCreateVariantAxisFieldHtml',
            'payload.axes = variantAxes.map',
            'payload.sku_prefix = payload.sku',
        ] as $marker) {
            self::assertStringContainsString($marker, $script, 'Create flow marker missing: ' . $marker);
        }

        self::assertStringNotContainsString(
            "parseJsonField(\n                            document.getElementById('product-create-axes')",
            $script,
        );
        self::assertStringNotContainsString(
            "parseJsonField(document.getElementById('product-create-axes')",
            $script,
        );
    }

    public function testCreateAndEditShareVariantCombinationEngine(): void
    {
        $script = $this->read('view/statics/js/backend/product-admin.js');
        $command = $this->read('Service/ProductAdminCommandService.php');

        foreach ([
            'function buildVariantCombinations(axes)',
            'function generatedVariantSku(prefix, combination, axes)',
            'buildVariantCombinations(axes)',
        ] as $marker) {
            self::assertStringContainsString($marker, $script);
        }

        self::assertStringContainsString('variantMatrix->generate(', $command);
        self::assertStringContainsString("if (\$productType === 'configurable')", $command);
        self::assertStringContainsString("\$axes = \$payload['axes'] ?? [];", $command);
    }

    public function testVariantAxisGroupUsesChipPickerOnlyForConfigurableType(): void
    {
        $script = $this->read('view/statics/js/backend/product-admin.js');
        $css = $this->read('view/statics/css/backend/product-admin.css');

        foreach ([
            "code === 'hanfu_variants'",
            "code.endsWith('_variants')",
            'createProductTypeSupportsVariants()',
            'buildCreateVariantAxisFieldHtml',
            'data-create-variant-axis',
            'data-create-variant-option',
            'renderCreateEavFields(setId)',
        ] as $marker) {
            self::assertStringContainsString($marker, $script);
        }

        foreach ([
            'w-product-create__axis-chip',
            'w-product-create__axis-chip-grid',
            'w-product-create__variant-panel',
        ] as $marker) {
            self::assertStringContainsString($marker, $css);
        }
    }

    public function testProductLevelAttributesExcludeConfigurableVariantAxes(): void
    {
        $script = $this->read('view/statics/js/backend/product-admin.js');

        self::assertStringContainsString(
            "createProductTypeSupportsVariants() && field.hasAttribute('data-create-variant-axis')",
            $script,
        );
        self::assertStringContainsString("data-value-type=\"variant-axis\"", $script);
        self::assertStringContainsString("valueType === 'variant-axis'", $script);
    }

    public function testWarrantyMonthsUsesPresetSelectOptionsInBootstrap(): void
    {
        $bootstrap = $this->read('Service/ProductCatalogEavBootstrap.php');
        $script = $this->read('view/statics/js/backend/product-admin.js');

        foreach ([
            'WARRANTY_MONTHS_OPTIONS',
            'ensureWarrantyMonthsSelect',
            "'code' => '12', 'label' => '12 个月'",
            "'code' => '24', 'label' => '24 个月'",
            "'code' => '36', 'label' => '36 个月'",
        ] as $marker) {
            self::assertStringContainsString($marker, $bootstrap);
        }

        self::assertStringContainsString("code === 'warranty_months'", $script);
        self::assertStringContainsString("defaultSelected = '12'", $script);
    }

    public function testCreateTemplateExposesVariantPreviewPanelNotAxesJsonEditor(): void
    {
        $index = $this->read('view/templates/backend/catalog/index.phtml');

        foreach ([
            'id="product-create-wizard"',
            'data-testid="product-create-wizard"',
            'data-create-step="variant-preview"',
            'id="product-create-variant-preview"',
            'data-testid="product-create-variant-preview"',
            'id="product-create-variant-values"',
            'id="product-create-variant-axes"',
        ] as $marker) {
            self::assertStringContainsString($marker, $index, 'Create template marker missing: ' . $marker);
        }

        $css = $this->read('view/statics/css/backend/product-admin.css');
        self::assertStringContainsString('w-product-create__variant-preview-scroll', $css);

        self::assertStringContainsString(
            '<textarea id="product-create-axes" name="axes_json" hidden aria-hidden="true"></textarea>',
            $index,
        );
        self::assertStringNotContainsString('规格轴 JSON', $index);
        self::assertStringNotContainsString('"options":["red","blue"]', $index);
    }

    public function testCreateFlowSupportsVariantValueImagesAndFullPreview(): void
    {
        $script = $this->read('view/statics/js/backend/product-admin.js');

        foreach ([
            'renderCreateVariantValueConfig',
            'initializeCreateWizard',
            'maybeAdvanceCreateWizard',
            'openCreateWizardStep',
            'initializeCreateVariantImagePicker',
            'resolveMediaPickerFileUrl',
            'getFirstIncompletePreviousCreateWizardStepId',
            'requiresCreateStepManualConfirm',
            'isCreateStepContentReady',
            'data-create-variant-option-image',
            'w-product-create__variant-preview-scroll',
            'resolveCombinationPreview',
            'swatch_image',
            // Selecting a sample image must refresh preview but never auto-advance.
            "Selecting a preview image must stay on this step",
        ] as $marker) {
            self::assertStringContainsString($marker, $script, 'Create flow marker missing: ' . $marker);
        }

        self::assertStringNotContainsString(
            "maybeAdvanceCreateWizard('variant-values', true);\n            closePicker();",
            $script,
            '规格值预览图选图后不得自动下一步',
        );
        self::assertStringNotContainsString(
            "if (isCreateStepContentReady(stepId)) {\n                return true;\n            }\n            var continueButton = getCreateWizardStepContinueButton('variant-values');",
            $script,
            'variant-values 不得因已上传图片就视为步骤完成',
        );
    }

    public function testCreateWizardStepsExposeNextButtons(): void
    {
        $index = $this->read('view/templates/backend/catalog/index.phtml');

        foreach ([
            'data-create-step-continue="basics"',
            'data-create-step-continue="attribute-set"',
            'data-create-step-continue="variant-axes"',
            'data-create-step-continue="variant-values"',
            'data-create-step-continue="product-attributes"',
            'data-create-step-continue="variant-preview"',
            'w-product-create__step-actions',
        ] as $marker) {
            self::assertStringContainsString($marker, $index, 'Wizard next button marker missing: ' . $marker);
        }
    }

    public function testCatalogCreatePageExposesPanelFocusControls(): void
    {
        $index = $this->read('view/templates/backend/catalog/index.phtml');
        $script = $this->read('view/statics/js/backend/product-admin.js');
        $css = $this->read('view/statics/css/backend/product-admin.css');

        foreach ([
            'data-product-list-panel',
            'data-product-panel-focus="create"',
            'data-product-panel-focus="list"',
            'data-testid="product-create-panel-focus"',
            'data-testid="product-list-panel-focus"',
        ] as $marker) {
            self::assertStringContainsString($marker, $index, 'Panel focus template marker missing: ' . $marker);
        }

        foreach ([
            'setCatalogPanelMode',
            'is-list-focused',
            'is-list-pinned',
            'data-product-panel-focus',
            'PRODUCT_CATALOG_PANEL_MODE_STORAGE_KEY',
            'weline.product.catalog.panel_mode',
            'readCatalogPanelMode',
            'writeCatalogPanelMode',
            'readCatalogPanelMode()',
        ] as $marker) {
            self::assertStringContainsString($marker, $script, 'Panel focus script marker missing: ' . $marker);
        }

        foreach ([
            'is-list-focused',
            'w-product-panel__header',
        ] as $marker) {
            self::assertStringContainsString($marker, $css, 'Panel focus css marker missing: ' . $marker);
        }
    }

    public function testHanfuSchemaDefinesVariantAxesAsSelectAttributes(): void
    {
        $bootstrap = $this->read('Service/ProductCatalogEavBootstrap.php');

        foreach ([
            "'hanfu_variants', '规格维度'",
            "'code' => 'color'",
            "'code' => 'size'",
            "'code' => 'style_type'",
            'ensureSelectAttribute(',
        ] as $marker) {
            self::assertStringContainsString($marker, $bootstrap);
        }
    }

    public function testCreateWizardOrdersProductAttributesBeforeVariantAxes(): void
    {
        $index = $this->read('view/templates/backend/catalog/index.phtml');
        $setFlowStart = strpos($index, 'id="product-create-set-flow"');
        self::assertNotFalse($setFlowStart);
        $setFlow = substr($index, $setFlowStart);

        $productPos = strpos($setFlow, 'data-create-step="product-attributes"');
        $axesPos = strpos($setFlow, 'data-create-step="variant-axes"');
        $valuesPos = strpos($setFlow, 'data-create-step="variant-values"');
        $previewPos = strpos($setFlow, 'data-create-step="variant-preview"');

        self::assertNotFalse($productPos);
        self::assertNotFalse($axesPos);
        self::assertNotFalse($valuesPos);
        self::assertNotFalse($previewPos);
        self::assertLessThan($axesPos, $productPos, '商品属性步必须紧接属性集之后、规格维度之前');
        self::assertLessThan($valuesPos, $axesPos);
        self::assertLessThan($previewPos, $valuesPos);

        self::assertStringContainsString('data-create-step-number="product"', $index);
        self::assertStringContainsString('id="product-create-product-attributes"', $index);
    }

    public function testCreateWizardUsesScopePersistenceForDraftAutosave(): void
    {
        $index = $this->read('view/templates/backend/catalog/index.phtml');
        $script = $this->read('view/statics/js/backend/product-admin.js');

        self::assertStringContainsString(
            "<w:scope url=\"@backend-url('taglib/backend/scope')\" container-id=\"product-create-form\"",
            $index,
        );
        foreach ([
            'scope="product_create_draft"',
            'data-basics-section="identity"',
            'data-basics-section="commerce"',
            'data-basics-section="shipping"',
            'data-basics-section="content"',
            'data-basics-section="seo"',
            'data-basics-section="categories"',
            'data-basics-section="media"',
            'w-product-create__basics-grid',
            'data-cols="4"',
            'id="product-create-type"',
            'id="product-create-name"',
            'id="product-create-sku"',
            'id="product-create-spu"',
            'id="product-create-brand"',
            'id="product-create-barcode"',
            'id="product-create-status"',
            'id="product-create-visibility"',
            'id="product-create-price"',
            'data-label-draft',
            'data-label-published',
            '创建并立即发布',
            'id="product-create-cost"',
            'id="product-create-currency"',
            'id="product-create-stock"',
            'id="product-create-weight"',
            'id="product-create-length"',
            'id="product-create-width"',
            'id="product-create-height"',
            'id="product-create-short-description"',
            'id="product-create-description"',
            'id="product-create-slug"',
            'data-product-create-slug-status',
            'id="product-create-meta-name"',
            'id="product-create-meta-description"',
            'id="product-create-meta-keywords"',
            'id="product-create-media-json"',
            'data-product-create-media-picker-open',
            'data-w-component="dialog"',
            'data-product-create-media-picker-dialog',
            'data-product-create-category-assignments',
            'w:catalog:category:select',
            'id="product-create-categories"',
            'allow-create="true"',
            'website-id="productCreateCategoryWebsiteId"',
            '不是属性集里的扩展属性',
        ] as $marker) {
            self::assertStringContainsString($marker, $index);
        }
        self::assertStringNotContainsString('data-product-create-category-id', $index);
        self::assertStringNotContainsString('w-product-create__category-list', $index);
        foreach ([
            'name="spu"',
            'name="brand_id"',
            'name="barcode"',
            'name="status"',
            'name="visibility"',
            'name="stock"',
            'name="length"',
            'name="width"',
            'name="height"',
            'name="price"',
            'name="cost"',
            'name="short_description"',
            'name="description"',
            'name="slug"',
            'name="meta_name"',
            'name="meta_keywords"',
            'name="media_assignments_json"',
            'name="create_eav_attributes_json"',
            'name="create_variant_axes_json"',
            'name="create_variant_option_images_json"',
        ] as $marker) {
            self::assertStringContainsString($marker, $index);
        }
        self::assertMatchesRegularExpression(
            '/id="product-create-name"[^>]*scope="product_create_draft"|scope="product_create_draft"[^>]*id="product-create-name"/',
            $index,
        );
        self::assertStringContainsString(
            'event="change"',
            $index,
            '创建草稿 scope 不得用 input/click 监听（易在填写时触发主线程风暴）',
        );
        self::assertStringNotContainsString('event="input change click"', $index);

        foreach ([
            "PRODUCT_CREATE_DRAFT_SCOPE = 'product_create_draft'",
            'clearProductCreateDraftLocal',
            'clearProductCreateDraftRemote',
            'hydrateProductCreateDraftUi',
            'bindCreateSlugAutoGenerate',
            'slugifyProductHandle',
            'slugifyProductHandleSegment',
            'resolveCreateSlugFromBasics',
            'syncCreateSeoTitleFromBasics',
            'syncCreateSlugFromBasics',
            'hydrateCreateSeoFromDraft',
            'scheduleCreateSlugAvailabilityCheck',
            'checkCreateSlugAvailability',
            'ensureCreateSlugAvailableForSubmit',
            'resource.checkSlug',
            'payload.price_minor = Math.round(priceYuan * 100)',
            'payload.cost = costYuan',
            'payload.stock = parseInt(stockRaw, 10)',
            "payload.media_assignments = createMedia",
            'payload.category_assignments = categoryIds.map',
            "window.WelineCatalogCategorySelect",
            "product-create-categories",
            "createStatusIntent === 'published'",
            'initializeCreateMedia',
            'hydrateCreateMediaFromJson',
            'forceHydrateAttributeSetFromDraft',
            'persistCreateEavDraft',
            'applyCreateEavDraft',
            'shouldApplyCreateEavDraft',
            'createEavDraftHasValues',
            '__welineCreateEavUserEdited',
            'bindCreateEavDraftPersistence',
            "pagehide",
            "#product-create-product-attributes [data-eav-field]",
            'delete row.entity_id',
            'Never include #product-create-eav-editor',
            'last-write-wins in persistCreateEavDraft',
            'Keep legacy editor node empty',
            'flushProductCreateDraftRemoteNow',
            '__welineCreateEavDraftDocBound',
            'bindCreateEavDraftFieldNodes',
            'onCreateEavDraftDomEvent',
            'Capture on document so dynamically rendered attribute fields always hit',
            'readProductCreateDraftData',
            'patchProductCreateDraftLocal',
            'openProductMediaDialog',
            'closeProductMediaDialog',
            'createWizardAdvancing',
            'createMediaHydrating',
            'createWizardToggleGuard',
            'syncCreateMediaJson({silent: true})',
            'field input/change must NOT auto-advance',
            'No input/change auto-advance',
            "PRODUCT_CREATE_WIZARD_STEP_STORAGE_KEY = 'weline.product.create.wizard_step'",
            'function resumeCreateWizardStep(',
            'function rememberCreateWizardStep(',
            'function unlockCreateWizardStepsBefore(',
            'forceHydrateCreateBasicsFromDraft',
            'forceHydrateCategoriesFromDraft',
            "product-create-categories_chips",
            'clearCreateWizardStep()',
            'remember: false',
            'forceConfirmPreviousStepsForResume',
            'Never rewrite remembered step to basics while waiting',
            'isHydrateableFormControl',
            'HTMLSelectElement',
            "forceHydrateScopedHiddenFromDraft('product-create-status', 'status')",
            "forceHydrateScopedHiddenFromDraft('product-create-price', 'price')",
            'syncCreateSubmitButtonLabel',
            'diagnosticsMessage',
            'Clear draft only after create(+publish) fully succeeds',
            '选择「创建后立即发布」时请填写售价',
        ] as $marker) {
            self::assertStringContainsString($marker, $script);
        }

        self::assertStringContainsString(
            'forceConfirmPreviousStepsForResume',
            $script,
            '刷新恢复须重建上一步「下一步」确认，避免弹回 basics',
        );
        self::assertStringContainsString(
            'Never rewrite remembered step to basics while waiting',
            $script,
            '步骤尚未可见时不得把记忆覆盖成 basics',
        );
        self::assertStringContainsString(
            'force: true',
            $script,
            'resume 须 force 打开上次步骤，不被中间门禁打回',
        );

        self::assertStringContainsString(
            'warranty_months defaultSelected must not block backfill',
            $script,
            '草稿回填不得被质保默认值挡住',
        );

        self::assertStringNotContainsString(
            "openCreateWizardStep('basics');\n            } else if (pinnedMode === 'list'",
            $script,
            '进入创建面板不得写死打开 basics，应 resume 上次步骤',
        );
    }

    public function testCreateAttributeWriteBindsEntityIdZeroToProduct(): void
    {
        $service = $this->read('Service/ProductAdminCommandService.php');
        self::assertStringContainsString(
            'Create wizard fields ship data-entity-id="0"',
            $service,
            'create 属性写入须纠正 entity_id=0',
        );
        self::assertStringContainsString(
            'if ($entityId <= 0) {',
            $service,
        );
        self::assertStringContainsString(
            '$entityId = $productId;',
            $service,
        );
    }

    public function testCreateEavRenderFallsBackToSnapshotCatalogAndGatesRequiredAttrs(): void
    {
        $script = $this->read('view/statics/js/backend/product-admin.js');

        foreach ([
            'function resolveCreateAttributeCatalog()',
            'state.creation_context.attribute_catalog',
            'state.snapshot.attribute_catalog',
            'function countExpectedCreateProductAttributeFields(setId)',
            'function getCreateSelectedAttributeSetId()',
            'product-create-product-attributes-empty',
            "'product-attributes': '请先按属性组填写必填商品属性。'",
            'expectedProductAttrs > 0 && renderedProductFields.length === 0',
        ] as $marker) {
            self::assertStringContainsString($marker, $script, 'Create attribute gate marker missing: ' . $marker);
        }

        self::assertStringNotContainsString(
            "|| stepId === 'product-attributes'\n            || stepId === 'variant-preview'",
            $script,
            '商品属性步不得再作为 optional continue 跳过必填',
        );
        self::assertStringContainsString("if (stepId === 'product-attributes') {\n            return isCreateStepContentReady(stepId);\n        }", $script);
    }
}
