<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\View;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

final class PromotionDeskIndexTemplateContractTest extends TestCore
{
    private const TEMPLATE_RELATIVE_PATH = '/view/templates/backend/promotion/index.phtml';

    public function testDeskTemplateDoesNotUseHtmlDdTag(): void
    {
        $templatePath = dirname(__DIR__, 3) . self::TEMPLATE_RELATIVE_PATH;
        $source = (string)file_get_contents($templatePath);

        self::assertStringNotContainsString('<dd>', $source);
    }

    public function testDeskTemplateCompilesWithoutDdTagCollision(): void
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $source = (string)file_get_contents(dirname(__DIR__, 3) . self::TEMPLATE_RELATIVE_PATH);

        $compiled = $taglib->compile(
            $template,
            $source,
            'promotion-desk-index-contract-' . uniqid('', true) . '.phtml',
        );

        self::assertStringNotContainsString('<?=dd(', $compiled);
        self::assertStringContainsString('w-backend-page__heading', $compiled);
        self::assertStringContainsString('w-card', $compiled);
        self::assertStringNotContainsString('<dd>', $compiled);
    }
}
