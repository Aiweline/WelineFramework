<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Block;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Block\Partials;

/**
 * Design-theme chrome is resolved as an absolute path; its @param defaults
 * must still be parsed (Module::path-only parsing silently drops them).
 */
final class PartialsAbsoluteMetaTest extends TestCase
{
    public function testAbsoluteDesignPartialReadsParamDefaults(): void
    {
        $header = BP . '/app/design/Weline/hanfu/frontend/partials/header/default.phtml';
        self::assertFileExists($header);

        $block = new Partials();
        $block->__init();

        $method = new \ReflectionMethod(Partials::class, 'parsePartialsMetaFromFile');
        $method->setAccessible(true);
        $meta = $method->invoke($block, $header, 'frontend', 'header', 'default');

        self::assertIsArray($meta);
        self::assertArrayHasKey('showCurrency', $meta);
        self::assertTrue($meta['showCurrency']);
    }
}
