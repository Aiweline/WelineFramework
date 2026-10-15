<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class AccountSelectContractTest extends TestCase
{
    public function testAccountSelectTaglibUsesLocalSearchableTagUi(): void
    {
        $taglibPath = BP . 'app/code/Weline/Mail/Taglib/AccountSelect.php';
        $rendererPath = BP . 'app/code/Weline/Mail/Taglib/SearchableAccountSelect.php';
        $scriptPath = BP . 'app/code/Weline/Mail/view/statics/ui/components/weline-mail-account-select.js';
        $cssPath = BP . 'app/code/Weline/Mail/view/statics/ui/components/weline-mail-account-select.css';
        $enterprisePath = BP . 'app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml';

        self::assertFileExists($taglibPath);
        self::assertFileExists($rendererPath);
        self::assertFileExists($scriptPath);
        self::assertFileExists($cssPath);

        $taglib = (string) file_get_contents($taglibPath);
        $renderer = (string) file_get_contents($rendererPath);
        $script = (string) file_get_contents($scriptPath);
        $enterprise = (string) file_get_contents($enterprisePath);

        self::assertSame('mail:account:select', \Weline\Mail\Taglib\AccountSelect::name());
        self::assertStringContainsString('SearchableAccountSelect::render', $taglib);
        self::assertStringContainsString('auto-submit', $taglib);
        self::assertStringContainsString('data-w-component="mail-account-select"', $renderer);
        self::assertStringContainsString('data-w-mail-account-select-panel', $renderer);
        self::assertStringContainsString('data-w-options', $renderer);
        self::assertStringContainsString("UI.define('mail-account-select'", $script);
        self::assertStringContainsString('floating.portal', $script);
        self::assertStringContainsString('autoSubmit', $script);
        self::assertStringContainsString('filterOptions', $script);
        self::assertStringContainsString('dataset.hasValue', $script);
        self::assertStringContainsString("classList.toggle('has-value'", $script);
        self::assertStringContainsString('data-has-value=', $renderer);
        self::assertStringContainsString("\$__mas_value !== '' ? ' has-value' : ''", $renderer);
        self::assertStringContainsString('<w:mail:account:select', $enterprise);
        self::assertStringContainsString('mail-enterprise-current-account', $enterprise);
        self::assertStringContainsString('auto-submit="true"', $enterprise);
        self::assertStringNotContainsString('name="account" onchange="this.form.submit()"', $enterprise);
    }
}
