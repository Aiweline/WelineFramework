<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterPartialComposer;

final class FooterPartialComposerTest extends TestCase
{
    public function testComposerExposesSlotRenderMethods(): void
    {
        $this->assertTrue(method_exists(FooterPartialComposer::class, 'renderNewsletter'));
        $this->assertTrue(method_exists(FooterPartialComposer::class, 'renderSocial'));
        $this->assertTrue(method_exists(FooterPartialComposer::class, 'renderPayment'));
    }
}
