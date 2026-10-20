<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Compiler\Shadow\ShadowConfig;
use Weline\Framework\View\Compiler\V3Compiler;
use Weline\Framework\View\Template;

final class S2WhitelistTest extends TestCase
{
    public function testTemplateWhitelistMatchesAbsolutePathOnly(): void
    {
        $config = ShadowConfig::fromArray([
            'engine' => [
                'templates' => ['app/code/Weline/Demo/view/demo.phtml'],
            ],
        ]);

        self::assertTrue($config->shouldCompileTemplate(BP . 'app/code/Weline/Demo/view/demo.phtml'));
        self::assertFalse($config->shouldCompileTemplate(BP . 'app/code/Weline/Demo/view/other.phtml'));
    }

    public function testNoWhitelistKeepsLegacyPath(): void
    {
        $config = ShadowConfig::fromArray([]);
        self::assertFalse($config->shouldCompileTemplate(BP . 'app/code/Weline/Demo/view/demo.phtml'));
    }

    public function testV3CompilerMatchesLegacyForPlainText(): void
    {
        $template = Template::getInstance();
        $source = '<div>plain S2 text</div>';
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 's2-plain.phtml');

        self::assertSame($legacy, V3Compiler::compile($source, 's2-plain.phtml', $template));
    }
}
