<?php

declare(strict_types=1);

namespace Weline\Catalog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCatalogServiceContractsPass().
 */
final class CatalogServiceContractsTest extends TestCase
{
    public function testAllCatalogServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'GoogleTaxonomyImport::testSampleImportContainsCanonicalGoogleIds' => function () {
            $this->setUp_GoogleTaxonomyImport();
            $this->assert_GoogleTaxonomyImport_testSampleImportContainsCanonicalGoogleIds();
        },
            'GoogleTaxonomyImport::testSetupSeedsGoogleTaxonomyTable' => function () {
            $this->setUp_GoogleTaxonomyImport();
            $this->assert_GoogleTaxonomyImport_testSetupSeedsGoogleTaxonomyTable();
        },
            'GoogleTaxonomyImport::testGoogleTaxonomyAdminSurfaceIsReadOnlyWithAiEnqueue' => function () {
            $this->setUp_GoogleTaxonomyImport();
            $this->assert_GoogleTaxonomyImport_testGoogleTaxonomyAdminSurfaceIsReadOnlyWithAiEnqueue();
        },
            'GoogleTaxonomyImport::testCategoryEditorExposesGoogleMappingField' => function () {
            $this->setUp_GoogleTaxonomyImport();
            $this->assert_GoogleTaxonomyImport_testCategoryEditorExposesGoogleMappingField();
        },
        ];
    }

private function setUp_GoogleTaxonomyImport(): void
{
    if (!\defined('BP')) {
        require_once '/Users/weline/Project/Official/框架/app/code/Weline/Catalog/Test' . '/bootstrap.php';
    }
}

private function assert_GoogleTaxonomyImport_testSampleImportContainsCanonicalGoogleIds(): void
{
    $service = $this->read_GoogleTaxonomyImport('app/code/Weline/Catalog/Service/GoogleTaxonomyService.php');
    foreach (['267', '187', '1604', 'Animals & Pet Supplies', 'Mobile Phones'] as $needle) {
        self::assertStringContainsString($needle, $service);
    }
    self::assertStringContainsString('function importOfficial', $service);
    self::assertStringContainsString('taxonomy-with-ids.en-US.txt', $service);
    self::assertStringContainsString('function exists', $service);
    self::assertStringContainsString('function listTree', $service);
}

private function assert_GoogleTaxonomyImport_testSetupSeedsGoogleTaxonomyTable(): void
{
    $install = $this->read_GoogleTaxonomyImport('app/code/Weline/Catalog/Setup/Install.php');
    $upgrade = $this->read_GoogleTaxonomyImport('app/code/Weline/Catalog/Setup/Upgrade.php');
    $model = $this->read_GoogleTaxonomyImport('app/code/Weline/Catalog/Model/GoogleTaxonomy.php');
    self::assertStringContainsString('catalog_google_taxonomy', $model);
    self::assertStringContainsString('importSample()', $install);
    self::assertStringContainsString('importSample()', $upgrade);
    self::assertStringContainsString('GoogleTaxonomy::class', $install);
}

private function assert_GoogleTaxonomyImport_testGoogleTaxonomyAdminSurfaceIsReadOnlyWithAiEnqueue(): void
{
    $controller = $this->read_GoogleTaxonomyImport('app/code/Weline/Catalog/Controller/Backend/GoogleTaxonomy.php');
    $template = $this->read_GoogleTaxonomyImport('app/code/Weline/Catalog/view/templates/backend/google-taxonomy/index.phtml');
    $menu = $this->read_GoogleTaxonomyImport('app/code/Weline/Catalog/etc/backend/menu.xml');
    self::assertStringContainsString('postEnqueueAi', $controller);
    self::assertStringContainsString('GoogleTaxonomyTranslationQueueService', $controller);
    self::assertStringContainsString('data-testid="catalog-google-taxonomy"', $template);
    self::assertStringContainsString('weline-developer-catalogs.css', $template);
    self::assertStringContainsString('google-taxonomy-admin.css', $template);
    self::assertStringContainsString('data-testid="catalog-google-tree"', $template);
    self::assertStringContainsString('w-google-taxonomy-toolbar', $template);
    self::assertStringContainsString('data-google-ai-form', $template);
    self::assertStringNotContainsString('alert(', $template);
    self::assertStringContainsString('weline_catalog/backend/google-taxonomy/index', $menu);
    self::assertStringContainsString('Weline_Catalog::commerce:universal-catalog:google-taxonomy', $menu);
}

private function assert_GoogleTaxonomyImport_testCategoryEditorExposesGoogleMappingField(): void
{
    $template = $this->read_GoogleTaxonomyImport('app/code/Weline/Catalog/view/templates/backend/category/index.phtml');
    $controller = $this->read_GoogleTaxonomyImport('app/code/Weline/Catalog/Controller/Backend/Category.php');
    $provider = $this->read_GoogleTaxonomyImport('app/code/Weline/Product/extends/module/Weline_Catalog/Space/ProductCatalogSpaceProvider.php');
    self::assertStringContainsString('data-testid="catalog-google-mapping"', $template);
    self::assertStringContainsString('name="google_taxonomy_id"', $template);
    self::assertStringContainsString("'google_taxonomy_id'", $controller);
    self::assertStringContainsString('validateExternalTaxonomyId', $provider);
    self::assertStringContainsString('googleTaxonomy->exists', $provider);
    self::assertStringContainsString('googleTaxonomy->search', $provider);
}

private function read_GoogleTaxonomyImport(string $relative): string
{
    $path = BP . '/' . ltrim($relative, '/');
    $content = file_get_contents($path);
    self::assertIsString($content);
    return $content;
}
}
