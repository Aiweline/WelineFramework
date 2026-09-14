<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\DataTable\Extends\Module\Weline_Framework\Security\Csp\DataTablePublicCdnCsp;

final class DataTablePublicCdnCspContractTest extends TestCase
{
    public function testContributionDeclaresPublicCdnHosts(): void
    {
        $directives = (new DataTablePublicCdnCsp())->contribution()->directives;
        self::assertContains('https://cdn.jsdelivr.net', $directives['script-src'] ?? []);
        self::assertContains('https://cdnjs.cloudflare.com', $directives['style-src'] ?? []);
        self::assertContains('https://unpkg.com', $directives['script-src'] ?? []);
        self::assertContains('https://fonts.googleapis.com', $directives['style-src'] ?? []);
    }
}
