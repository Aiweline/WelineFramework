<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit;

use Weline\Framework\UnitTest\TestCore;
use Weline\Meta\Service\ParamDefinitionNormalizer;

class ParamDefinitionNormalizerTest extends TestCore
{
    public function testNormalizeCanonicalizesUiTypeAliasesAndKeepsOptions(): void
    {
        $normalizer = new ParamDefinitionNormalizer();

        $definitions = $normalizer->normalizeDefinitions([
            'hero_image' => [
                'type' => 'string',
                'ui' => 'media_image',
                'name' => 'Hero Image',
                'options' => 'left:Left,right:Right',
                'i18n' => 'false',
                'required' => 'true',
            ],
            'accent' => [
                'type' => 'string',
                'input' => 'color',
                'translate' => 'true',
            ],
        ]);

        $this->assertSame('media_image', $definitions['hero_image']['ui_type']);
        $this->assertSame('media_image', $definitions['hero_image']['input']);
        $this->assertSame(['left' => 'Left', 'right' => 'Right'], $definitions['hero_image']['options']);
        $this->assertFalse($definitions['hero_image']['i18n']);
        $this->assertTrue($definitions['hero_image']['required']);

        $this->assertSame('color', $definitions['accent']['ui_type']);
        $this->assertTrue($definitions['accent']['i18n']);
        $this->assertTrue($definitions['accent']['translate']);
    }

    public function testExtractParamAnnotationsParsesUiTypeAndStructuredOptions(): void
    {
        $normalizer = new ParamDefinitionNormalizer();

        $definitions = $normalizer->extractParamAnnotations(<<<'PHTML'
<?php
/**
 * @param array{foo:string} $phpDoc should not be parsed
 * @param position {type="string", ui_type="select", options=["left","right"], i18n=true}
 * @param.variant {type="string", input="select", options={primary:"Primary", secondary:"Secondary"}}
 */
?>
PHTML);

        $this->assertArrayNotHasKey('array', $definitions);
        $this->assertSame('select', $definitions['position']['ui_type']);
        $this->assertSame(['left' => 'left', 'right' => 'right'], $definitions['position']['options']);
        $this->assertTrue($definitions['position']['i18n']);
        $this->assertSame(['primary' => 'Primary', 'secondary' => 'Secondary'], $definitions['variant']['options']);
        $this->assertSame('select', $definitions['variant']['ui_type']);
    }

    public function testBooleanDefaultInfersSelectableBooleanDefinition(): void
    {
        $normalizer = new ParamDefinitionNormalizer();

        $definitions = $normalizer->extractParamAnnotations(<<<'PHTML'
<?php
/**
 * @param.showHeader {default=true,name="是否显示header",description="是否显示顶栏"}
 * @param showFooter {default=false,name="是否显示footer",description="是否显示底栏"}
 */
?>
PHTML);

        $this->assertSame('bool', $definitions['showHeader']['type']);
        $this->assertSame('select', $definitions['showHeader']['ui_type']);
        $this->assertSame(['1' => '显示', '0' => '隐藏'], $definitions['showHeader']['options']);
        $this->assertTrue($definitions['showHeader']['default']);

        $this->assertSame('bool', $definitions['showFooter']['type']);
        $this->assertSame('select', $definitions['showFooter']['input']);
        $this->assertSame(['1' => '显示', '0' => '隐藏'], $definitions['showFooter']['options']);
        $this->assertFalse($definitions['showFooter']['default']);
    }
}
