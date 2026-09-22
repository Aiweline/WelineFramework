<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\FooterPartialComposer;

final class FooterPartialComposerTest extends TestCase
{
    public function testComposerExposesSlotRenderMethods(): void
    {
        $this->assertTrue(method_exists(FooterPartialComposer::class, 'renderNewsletter'));
        $this->assertTrue(method_exists(FooterPartialComposer::class, 'renderSocial'));
        $this->assertTrue(method_exists(FooterPartialComposer::class, 'renderPayment'));
    }

    public function testRenderNewsletterIsRetiredEmpty(): void
    {
        $template = $this->createMock(Template::class);
        $template->expects($this->never())->method('fetch');
        self::assertSame('', FooterPartialComposer::renderNewsletter($template));

        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Helper/FooterPartialComposer.php'
        );
        self::assertStringNotContainsString(
            'widgets/newsletter/footer-newsletter',
            $src
        );
    }
}
