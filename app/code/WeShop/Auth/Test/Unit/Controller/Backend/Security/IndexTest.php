<?php

declare(strict_types=1);

namespace WeShop\Auth\Test\Unit\Controller\Backend\Security;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Controller\Backend\Security\Index as SecurityIndex;

class IndexTest extends TestCase
{
    public function testIndexRendersBackendSecurityHookHostTemplate(): void
    {
        $controller = $this->getMockBuilder(SecurityIndex::class)
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $controller->expects($this->once())
            ->method('assign')
            ->with('page_title', $this->anything());
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Auth::templates/Backend/Security/index.phtml')
            ->willReturn('security page');

        $this->assertSame('security page', $controller->index());
    }
}
