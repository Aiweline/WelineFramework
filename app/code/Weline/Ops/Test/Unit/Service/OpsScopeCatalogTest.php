<?php

declare(strict_types=1);

namespace Weline\Ops\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ops\Service\OpsScopeCatalog;

final class OpsScopeCatalogTest extends TestCase
{
    public function test_catalog_contains_required_ops_scopes(): void
    {
        $codes = (new OpsScopeCatalog())->codes();
        foreach (['site', 'seo', 'social', 'insight', 'content', 'growth', 'promotion', 'catalog'] as $code) {
            self::assertContains($code, $codes);
        }
    }
}
