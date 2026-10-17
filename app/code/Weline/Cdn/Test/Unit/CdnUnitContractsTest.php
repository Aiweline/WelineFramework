<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCdnUnitContractsPass().
 */
final class CdnUnitContractsTest extends TestCase
{
    public function testAllCdnUnitContractsPass(): void
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
            'EventDescriptionNoMultilineTranslate::testEventDescriptionsHaveNoEmbeddedNewlinesInsideTranslateCalls' => function () {
            $this->assert_EventDescriptionNoMultilineTranslate_testEventDescriptionsHaveNoEmbeddedNewlinesInsideTranslateCalls();
        },
            'FpcPolicyStateReducer::testDeclarationKeepsNormalizedQueryKeys' => function () {
            $this->assert_FpcPolicyStateReducer_testDeclarationKeepsNormalizedQueryKeys();
        },
        ];
    }

private function assert_EventDescriptionNoMultilineTranslate_testEventDescriptionsHaveNoEmbeddedNewlinesInsideTranslateCalls(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/event.php');
    self::assertDoesNotMatchRegularExpression("/__\\([^)]*\\n/s", $src);
    self::assertStringContainsString('CDN 统一操作请求', $src);
    self::assertStringContainsString('doc', $src);
}

private function assert_FpcPolicyStateReducer_testDeclarationKeepsNormalizedQueryKeys(): void
{
    $base = [
        'module' => 'Weline_Product',
        'class' => 'Weline\\Product\\Controller\\Frontend\\Catalog',
        'method' => 'index',
        'type' => 'fpc',
        'path_pattern' => '/product/frontend/catalog',
        'public_path_patterns' => ['/products'],
        'namespaces' => ['website/default/catalog'],
        'attrs' => [
            'enabled' => true,
            'ttl' => 31536000,
            'namespaces' => ['website/default/catalog'],
        ],
    ];

    $with = $base;
    $with['attrs']['query_keys'] = ['price', 'AF_Color', 'price'];
    $kept = \Weline\Cdn\Service\FpcPolicyStateReducer::declaration($with);
    self::assertSame(['af_color', 'price'], $kept['attrs']['query_keys']);
    self::assertArrayHasKey('query_keys', $kept['attrs']);

    $without = \Weline\Cdn\Service\FpcPolicyStateReducer::declaration($base);
    self::assertSame([], $without['attrs']['query_keys']);

    $star = $base;
    $star['attrs']['query_keys'] = ['*'];
    self::assertSame([], \Weline\Cdn\Service\FpcPolicyStateReducer::declaration($star)['attrs']['query_keys']);

    $storm = $base;
    $storm['attrs']['query_keys'] = ['_wb_nc', 'price'];
    self::assertSame([], \Weline\Cdn\Service\FpcPolicyStateReducer::declaration($storm)['attrs']['query_keys']);
}
}
