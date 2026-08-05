<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\View;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

final class BackendMessageRendererOwnershipTest extends TestCore
{
    public function testBackendPageTemplatesLeaveSystemMessagesToTheLayout(): void
    {
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);

        $layoutSource = (string)file_get_contents(
            BP . '/app/code/Weline/Theme/view/theme/backend/partials/layout/main-content.phtml'
        );
        $compiledLayout = $taglib->parse($template, 'cms-backend-main-content.phtml', $layoutSource);

        foreach (['listing.phtml', 'edit.phtml'] as $pageTemplate) {
            $pageSource = (string)file_get_contents(
                BP . '/app/code/Weline/Cms/view/templates/Backend/Page/' . $pageTemplate
            );
            $compiledPage = $taglib->parse($template, 'cms-backend-' . $pageTemplate, $pageSource);

            self::assertSame(
                1,
                substr_count(
                    $compiledLayout . $compiledPage,
                    "'Weline_Component::message.phtml'"
                ),
                $pageTemplate . ' must not add a second system-message renderer owned by the backend layout.'
            );
        }
    }
}
