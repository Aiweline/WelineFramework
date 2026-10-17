<?php

declare(strict_types=1);

namespace Weline\Faq\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllFaqControllerContractsPass().
 */
final class FaqControllerContractsTest extends TestCase
{
    public function testAllFaqControllerContractsPass(): void
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
            'FaqBackendListing::testListingIsWebsiteGrouped' => function () {
            $this->assert_FaqBackendListing_testListingIsWebsiteGrouped();
        },
            'FaqBackendListing::testBackendTemplatesUseWelineUiNotBootstrap' => function () {
            $this->assert_FaqBackendListing_testBackendTemplatesUseWelineUiNotBootstrap();
        },
            'FaqViewLayoutFetch::testSuccessPathsReturnFetchNotBareEmptyString' => function () {
            $this->assert_FaqViewLayoutFetch_testSuccessPathsReturnFetchNotBareEmptyString();
        },
            'FaqViewLayoutFetch::testFaqLayoutPrefersMetaContentForArticles' => function () {
            $this->assert_FaqViewLayoutFetch_testFaqLayoutPrefersMetaContentForArticles();
        },
        ];
    }

private function assert_FaqBackendListing_testListingIsWebsiteGrouped(): void
{
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/Controller/Backend/Page.php');
    $listing = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/view/templates/backend/Page/listing.phtml');
    self::assertStringContainsString('groupPagesByWebsite', $controller);
    self::assertStringContainsString('loadWebsiteOptions', $controller);
    self::assertStringContainsString("'website_id'", $controller);
    self::assertStringContainsString('在此网站新建', $listing);
    self::assertStringContainsString('全部网站', $listing);
    self::assertStringContainsString('site_groups', $listing);
    self::assertStringContainsString('public_path', $listing);
}

private function assert_FaqBackendListing_testBackendTemplatesUseWelineUiNotBootstrap(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/view/templates/backend';
    $files = [$root . '/Page/listing.phtml', $root . '/Page/edit.phtml', $root . '/Item/listing.phtml', $root . '/Item/edit.phtml'];
    foreach ($files as $file) {
        $body = (string) file_get_contents($file);
        self::assertStringContainsString('w-button', $body, $file);
        self::assertStringNotContainsString('form-control', $body, $file);
        self::assertStringNotContainsString('btn-primary', $body, $file);
        self::assertStringNotContainsString('form-select', $body, $file);
        self::assertStringNotContainsString('--ha-', $body, $file);
        self::assertStringNotContainsString('table table-', $body, $file);
    }
    $pageListing = (string) file_get_contents($root . '/Page/listing.phtml');
    $itemListing = (string) file_get_contents($root . '/Item/listing.phtml');
    self::assertStringContainsString('w-toolbar', $pageListing);
    self::assertStringContainsString('w-table', $pageListing);
    self::assertStringContainsString('w-empty', $pageListing);
    self::assertStringContainsString('w-toolbar', $itemListing);
    self::assertStringContainsString('w-table', $itemListing);
    self::assertStringContainsString('w-empty', $itemListing);
    $itemEdit = (string) file_get_contents($root . '/Item/edit.phtml');
    self::assertStringContainsString('w-field', $itemEdit);
    self::assertStringContainsString('w-input', $itemEdit);
    self::assertStringContainsString('w-select', $itemEdit);
}

private function assert_FaqViewLayoutFetch_testSuccessPathsReturnFetchNotBareEmptyString(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/Controller/Frontend/View.php');
    self::assertStringContainsString("return (string)\$this->fetch('Weline_Faq::templates/frontend/view.phtml');", $source);
    self::assertSame(2, substr_count($source, "return (string)\$this->fetch('Weline_Faq::templates/frontend/view.phtml');"), 'SPI and CMS success paths must both return fetch(view.phtml)');
    // Bare return '' after renderSpiPage / CMS assign is the blank-page regression.
    self::assertDoesNotMatchRegularExpression('/renderSpiPage\([\s\S]*?return\s+[\'"]{2}\s*;/', $source);
    self::assertDoesNotMatchRegularExpression("/assign\\('seo',\\s*\$this->seoFacts->buildArticleProfile\\([\\s\\S]*?return\\s+['\"]{2}\\s*;/", $source);
    self::assertStringNotContainsString('$this->getResponse()', $source);
    self::assertStringContainsString('$this->request->getResponse()->setCode(404)', $source);
}

private function assert_FaqViewLayoutFetch_testFaqLayoutPrefersMetaContentForArticles(): void
{
    $layout = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Faq' . '/view/theme/frontend/layouts/faq/default.phtml');
    self::assertStringContainsString("\$meta['content']", $layout);
    self::assertStringContainsString('faq-article-panel', $layout);
    self::assertStringContainsString("Weline_Faq::templates/frontend/view.phtml", $layout);
    // Article hero must stay Hub chrome; article title only in view.phtml h1.
    self::assertStringContainsString('$heroTitle = $isFaqArticle ? $hubTitle : $title;', $layout);
    self::assertStringContainsString('$escape($heroTitle)', $layout);
}
}
