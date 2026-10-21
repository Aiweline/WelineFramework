<?php

declare(strict_types=1);

namespace Weline\Inquiry\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inquiry\Service\InquiryFormBootstrap;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllInquiryServiceContractsPass().
 */
final class InquiryServiceContractsTest extends TestCase
{
    public function testAllInquiryServiceContractsPass(): void
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
            'InquiryContactForm::testBootstrapDefinesContactCode' => function () {
            $this->assert_InquiryContactForm_testBootstrapDefinesContactCode();
        },
            'InquiryContactForm::testBootstrapSeedsContactFormWithExpectedFields' => function () {
            $this->assert_InquiryContactForm_testBootstrapSeedsContactFormWithExpectedFields();
        },
            'InquiryContactForm::testInstallAndUpgradeBothSeedContactForm' => function () {
            $this->assert_InquiryContactForm_testInstallAndUpgradeBothSeedContactForm();
        },
            'InquiryContactForm::testBackendSubmissionControllerKeepsInquiryDataReadOnly' => function () {
            $this->assert_InquiryContactForm_testBackendSubmissionControllerKeepsInquiryDataReadOnly();
        },
            'InquiryContactForm::testBackendMenuExposesSubmissionsEntry' => function () {
            $this->assert_InquiryContactForm_testBackendMenuExposesSubmissionsEntry();
        },
            'InquiryContactForm::testBackendSubmissionTemplateRendersPayloadSummary' => function () {
            $this->assert_InquiryContactForm_testBackendSubmissionTemplateRendersPayloadSummary();
        },
            'InquiryRenderer::testRenderedFormIsAttachedToTheInquiryBody' => function () {
            $this->assert_InquiryRenderer_testRenderedFormIsAttachedToTheInquiryBody();
        },
            'InquiryRenderer::testAddressAssetsResolveViaFetchTagSourceWithoutDevFallback' => function () {
            $this->assert_InquiryRenderer_testAddressAssetsResolveViaFetchTagSourceWithoutDevFallback();
        },
            'InquirySubmissionCaptchaGuard::testGuardDefinesInquirySubmitIntent' => function () {
            $this->assert_InquirySubmissionCaptchaGuard_testGuardDefinesInquirySubmitIntent();
        },
            'InquirySubmissionCaptchaGuard::testSubmissionServiceVerifiesCaptchaBeforePersist' => function () {
            $this->assert_InquirySubmissionCaptchaGuard_testSubmissionServiceVerifiesCaptchaBeforePersist();
        },
            'InquirySubmissionCaptchaGuard::testRendererInjectsLazyCaptchaHostWhenModulePresent' => function () {
            $this->assert_InquirySubmissionCaptchaGuard_testRendererInjectsLazyCaptchaHostWhenModulePresent();
        },
            'InquirySubmissionCaptchaGuard::testCaptchaModuleRegistersLazyRuntime' => function () {
            $this->assert_InquirySubmissionCaptchaGuard_testCaptchaModuleRegistersLazyRuntime();
        },
            'InquirySubmissionCaptchaGuard::testQueryProviderAcceptsCaptchaFieldsOnSubmit' => function () {
            $this->assert_InquirySubmissionCaptchaGuard_testQueryProviderAcceptsCaptchaFieldsOnSubmit();
        },
        ];
    }

private function assert_InquiryContactForm_testBootstrapDefinesContactCode(): void
{
    self::assertSame('contact', InquiryFormBootstrap::CODE_CONTACT);
}

private function assert_InquiryContactForm_testBootstrapSeedsContactFormWithExpectedFields(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/Service/InquiryFormBootstrap.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('public function ensureContact(): void', $src);
    self::assertStringContainsString("self::CODE_CONTACT", $src);
    foreach (['name', 'email', 'topic', 'order_ref', 'message', 'attach_photos'] as $key) {
        self::assertStringContainsString("'key' => '{$key}'", $src, "contact 表单缺少字段：{$key}");
    }
    self::assertStringContainsString('$service->publish(', $src);
}

private function assert_InquiryContactForm_testInstallAndUpgradeBothSeedContactForm(): void
{
    foreach (['Install', 'Upgrade'] as $stage) {
        $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/Setup/' . $stage . '.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('ensureContact()', $src, "{$stage} 未播种 contact 表单");
    }
}

private function assert_InquiryContactForm_testBackendSubmissionControllerKeepsInquiryDataReadOnly(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/Controller/Backend/Submission.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('class Submission extends BackendController', $src);
    self::assertStringContainsString("Acl('Weline_Inquiry::submissions'", $src);
    self::assertStringContainsString('SubmissionModel::schema_fields_CREATED_AT', $src);
    self::assertStringContainsString('getItems()', $src);
    // 询盘数据只读：除 postReply 外不得出现其它 post 写动作。
    self::assertDoesNotMatchRegularExpression('/function post(?!Reply\b)/', $src);
    self::assertStringContainsString('function postReply', $src);
}

private function assert_InquiryContactForm_testBackendMenuExposesSubmissionsEntry(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/etc/backend/menu.xml';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('inquiry/backend/submission', $src);
    self::assertStringContainsString('Weline_Inquiry::submissions', $src);
}

private function assert_InquiryContactForm_testBackendSubmissionTemplateRendersPayloadSummary(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/view/templates/backend/Submission/index.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="inquiry-submissions"', $src);
    self::assertStringContainsString('payload_json', $src);
    self::assertStringContainsString('w-table', $src);
}

private function assert_InquiryRenderer_testRenderedFormIsAttachedToTheInquiryBody(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/Service/InquiryRenderer.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('form.appendChild(submit);body.appendChild(form);', $source, 'The renderer must attach the constructed form after all fields and the submit button are added.');
}

private function assert_InquiryRenderer_testAddressAssetsResolveViaFetchTagSourceWithoutDevFallback(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/Service/InquiryRenderer.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('ADDRESS_SCRIPT_SOURCE = \'Weline_Theme::js/address.js\'', $source);
    self::assertStringContainsString('ADDRESS_LOADER_SOURCE = \'Weline_Theme::js/address-loader.js\'', $source);
    self::assertStringContainsString('fetchTagSource(DataInterface::dir_type_STATICS', $source);
    self::assertStringContainsString('function addressAssetUrl', $source);
    self::assertStringNotContainsString('/Weline/Theme/view/statics/', $source);
    self::assertDoesNotMatchRegularExpression('/addressScript\|\|["\']\/Weline\//', $source, 'JS must not fall back to DEV-shaped /Weline/*/view/statics/ paths');
}

private function assert_InquirySubmissionCaptchaGuard_testGuardDefinesInquirySubmitIntent(): void
{
    self::assertSame('inquiry.submit', \Weline\Inquiry\Service\InquirySubmissionCaptchaGuard::INTENT);
}

private function assert_InquirySubmissionCaptchaGuard_testSubmissionServiceVerifiesCaptchaBeforePersist(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/Service/SubmissionService.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('InquirySubmissionCaptchaGuard', $src);
    self::assertStringContainsString('$this->captchaGuard->verify($params)', $src);
}

private function assert_InquirySubmissionCaptchaGuard_testRendererInjectsLazyCaptchaHostWhenModulePresent(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/Service/InquiryRenderer.php';
    $src = (string) file_get_contents($path);
    self::assertStringNotContainsString('LazyCaptchaClientRuntime::onceScriptHtml', $src);
    self::assertStringContainsString('captchaEnabled', $src);
    self::assertStringContainsString('captchaModule', $src);
    self::assertStringContainsString('ensureCaptchaModule', $src);
    self::assertStringContainsString('captchaModulePath', $src);
    self::assertStringContainsString('LazyCaptchaClientRuntime::resolveScriptUrl', $src);
    self::assertStringContainsString('Weline.load(moduleName,modulePath||null)', $src);
    self::assertStringContainsString('data-weline-captcha-lazy', $src);
    self::assertStringContainsString('captchaPayload(dataForm)', $src);
    self::assertStringContainsString('inquiry.submit', $src);
}

private function assert_InquirySubmissionCaptchaGuard_testCaptchaModuleRegistersLazyRuntime(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Captcha/view/statics/frontend/weline.modules.js';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('captchaLazy', $src);
    self::assertStringContainsString('captcha-lazy.js', $src);
    self::assertStringContainsString('globalVar: null', $src);
}

private function assert_InquirySubmissionCaptchaGuard_testQueryProviderAcceptsCaptchaFieldsOnSubmit(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/extends/module/Weline_Framework/Query/InquiryQueryProvider.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("'captcha_provider'", $src);
    self::assertStringContainsString("'captcha_token'", $src);
}
}
