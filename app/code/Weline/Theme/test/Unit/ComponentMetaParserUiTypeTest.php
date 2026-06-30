<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Helper\ComponentMetaParser;

class ComponentMetaParserUiTypeTest extends TestCore
{
    public function testParseParamPreservesUiTypeAndI18n(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'theme_meta_');
        $phtml = $file . '.phtml';
        rename($file, $phtml);
        file_put_contents($phtml, <<<'PHTML'
<?php
/**
 * @param hero_image {type="string", ui_type="media_image", name="Hero Image", i18n=false}
 * @param.color_accent {type="string", input="color", required=true}
 * @param alignment {type="string", ui_type="select", options=["left","right"]}
 */
?>
PHTML);

        try {
            $parsed = ComponentMetaParser::parse($phtml);
        } finally {
            @unlink($phtml);
        }

        $params = [];
        foreach ($parsed['params'] as $param) {
            $params[$param['param_name'] ?? $param['name']] = $param;
        }

        $this->assertSame('media_image', $params['hero_image']['ui_type']);
        $this->assertSame('media_image', $params['hero_image']['input']);
        $this->assertFalse($params['hero_image']['i18n']);
        $this->assertSame('color', $params['color_accent']['ui_type']);
        $this->assertTrue($params['color_accent']['required']);
        $this->assertSame('select', $params['alignment']['ui_type']);
        $this->assertSame(['left' => 'left', 'right' => 'right'], $params['alignment']['options']);
    }
}
