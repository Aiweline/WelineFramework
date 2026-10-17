<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllMailTaglibContractsPass().
 */
final class MailTaglibContractsTest extends TestCase
{
    public function testAllMailTaglibContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AccountSelect::testAccountSelectTaglibUsesLocalSearchableTagUi' => function () {
            $this->assert_AccountSelect_testAccountSelectTaglibUsesLocalSearchableTagUi();
        },
            'MailComposerTaglib::testTaglibImplementsMailComposer' => function () {
            $this->assert_MailComposerTaglib_testTaglibImplementsMailComposer();
        },
            'MailComposerTaglib::testJsApiAndStaticsExist' => function () {
            $this->assert_MailComposerTaglib_testJsApiAndStaticsExist();
        },
            'MailComposerTaglib::testComposerServiceAndControllers' => function () {
            $this->assert_MailComposerTaglib_testComposerServiceAndControllers();
        },
            'MailComposerTaglib::testSceneMapListsMailComposer' => function () {
            $this->assert_MailComposerTaglib_testSceneMapListsMailComposer();
        },
            'MailComposerTaglib::testModuleVersion' => function () {
            $this->assert_MailComposerTaglib_testModuleVersion();
        },
        ];
    }

private function assert_AccountSelect_testAccountSelectTaglibUsesLocalSearchableTagUi(): void
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

private function assert_MailComposerTaglib_testTaglibImplementsMailComposer(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Mail/Taglib/MailComposer.php');
    self::assertStringContainsString("return 'mail-composer'", $src);
    self::assertStringContainsString('TaglibInterface', $src);
    self::assertStringNotContainsString('@static(', $src);
    self::assertStringContainsString('resolveStatic', $src);
    self::assertStringContainsString('fetchTagSource', $src);
}

private function assert_MailComposerTaglib_testJsApiAndStaticsExist(): void
{
    self::assertFileExists(BP . 'app/code/Weline/Mail/view/statics/js/mail-composer.js');
    self::assertFileExists(BP . 'app/code/Weline/Mail/view/statics/css/mail-composer.css');
    $js = (string) file_get_contents(BP . 'app/code/Weline/Mail/view/statics/js/mail-composer.js');
    self::assertStringContainsString('WelineMailComposer', $js);
    self::assertStringContainsString('open:', $js);
}

private function assert_MailComposerTaglib_testComposerServiceAndControllers(): void
{
    self::assertFileExists(BP . 'app/code/Weline/Mail/Service/MailComposerService.php');
    $svc = (string) file_get_contents(BP . 'app/code/Weline/Mail/Service/MailComposerService.php');
    self::assertStringContainsString('listThreadBySource', $svc);
    self::assertStringContainsString('mail_message_sent', $svc);
    self::assertFileExists(BP . 'app/code/Weline/Mail/Controller/Backend/Composer.php');
    self::assertFileExists(BP . 'app/code/Weline/Mail/Controller/Frontend/Composer.php');
}

private function assert_MailComposerTaglib_testSceneMapListsMailComposer(): void
{
    $map = (string) file_get_contents(BP . 'app/code/Weline/Taglib/doc/场景映射表.md');
    self::assertStringContainsString('mail-composer', $map);
    self::assertStringContainsString('企业邮箱', $map);
}

private function assert_MailComposerTaglib_testModuleVersion(): void
{
    $module = include BP . 'app/code/Weline/Mail/etc/module.php';
    self::assertSame('0.2.1', $module['version'] ?? null);
}
}
