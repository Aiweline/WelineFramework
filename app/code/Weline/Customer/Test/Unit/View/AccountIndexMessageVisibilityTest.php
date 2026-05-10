<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountIndexMessageVisibilityTest extends TestCase
{
    public function testAccountIndexMessageContainersStayHiddenUntilTheyHaveText(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/templates/frontend/account/index.phtml';

        $this->assertFileExists($templateFile);
        $content = (string) file_get_contents($templateFile);

        $this->assertStringContainsString('id="profileSuccessMsg"', $content);
        $this->assertStringContainsString('id="profileErrorMsg"', $content);
        $this->assertStringContainsString('id="securitySuccessMsg"', $content);
        $this->assertStringContainsString('id="securityErrorMsg"', $content);
        $this->assertStringContainsString('.account-index .d-none,', $content);
        $this->assertStringContainsString('.account-card__alert:empty', $content);
        $this->assertStringContainsString('display: none !important;', $content);
    }
}
