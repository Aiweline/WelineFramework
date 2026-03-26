<?php

declare(strict_types=1);

namespace Weline\Saas\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class ProvisioningTemplateProxyTest extends TestCase
{
    public function testLegacyProvisioningTemplateProxiesToWebsitesCanonicalTemplate(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/templates/Backend/Provisioning/index.phtml');

        self::assertIsString($template);
        self::assertStringContainsString(
            'Weline_Websites::templates/Backend/Provisioning/index.phtml',
            $template
        );
    }
}
