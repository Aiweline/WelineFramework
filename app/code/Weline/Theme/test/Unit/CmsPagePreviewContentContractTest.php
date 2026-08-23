<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class CmsPagePreviewContentContractTest extends TestCase
{
    public function testCmsLayoutsRenderAssignedPreviewContentInsideTheContentSlot(): void
    {
        $root = dirname(__DIR__, 6);

        foreach (['default', 'blank'] as $layoutOption) {
            $file = $root . '/app/code/Weline/Theme/view/theme/frontend/layouts/cms_page/'
                . $layoutOption . '.phtml';
            self::assertFileExists($file);

            $content = (string)file_get_contents($file);
            self::assertStringContainsString('$rawContent = (string)$this->getData(\'content\');', $content);
            self::assertStringContainsString('if (trim($rawContent) !== \'\')', $content);
            self::assertStringContainsString('<?= $rawContent ?>', $content);
            self::assertLessThan(
                strpos($content, 'data-placeholder="content"'),
                strpos($content, '<?= $rawContent ?>'),
                $file . ' must prefer the server-rendered scoped preview over its empty placeholder.'
            );
        }
    }
}
