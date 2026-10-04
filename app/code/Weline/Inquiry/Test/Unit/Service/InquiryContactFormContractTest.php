<?php

declare(strict_types=1);

namespace Weline\Inquiry\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inquiry\Service\InquiryFormBootstrap;

/**
 * 「联系我们」询盘表单接线契约。
 *
 * 背景：contact 页此前只有前端 mailto，后台无任何接收与查看入口。
 * 本测试钉住三件事，避免接线被回退：
 *  1) 内置 contact 表单的 code 与字段形状；
 *  2) Install/Upgrade 都会幂等播种该表单；
 *  3) 后台存在只读的「询盘提交记录」列表页（控制器 + 菜单 + 模板）。
 */
final class InquiryContactFormContractTest extends TestCase
{
    public function testBootstrapDefinesContactCode(): void
    {
        self::assertSame('contact', InquiryFormBootstrap::CODE_CONTACT);
    }

    public function testBootstrapSeedsContactFormWithExpectedFields(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/InquiryFormBootstrap.php';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('public function ensureContact(): void', $src);
        self::assertStringContainsString("self::CODE_CONTACT", $src);
        foreach (['name', 'email', 'topic', 'order_ref', 'message', 'attach_photos'] as $key) {
            self::assertStringContainsString("'key' => '{$key}'", $src, "contact 表单缺少字段：{$key}");
        }
        self::assertStringContainsString('$service->publish(', $src);
    }

    public function testInstallAndUpgradeBothSeedContactForm(): void
    {
        foreach (['Install', 'Upgrade'] as $stage) {
            $path = dirname(__DIR__, 3) . '/Setup/' . $stage . '.php';
            $src = (string)file_get_contents($path);
            self::assertStringContainsString('ensureContact()', $src, "{$stage} 未播种 contact 表单");
        }
    }

    public function testBackendSubmissionControllerIsReadOnlyList(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Backend/Submission.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('class Submission extends BackendController', $src);
        self::assertStringContainsString("Acl('Weline_Inquiry::submissions'", $src);
        self::assertStringContainsString('SubmissionModel::schema_fields_CREATED_AT', $src);
        self::assertStringContainsString('getItems()', $src);
        // 只读列表：不得出现写动作方法。
        self::assertStringNotContainsString('function post', $src);
    }

    public function testBackendMenuExposesSubmissionsEntry(): void
    {
        $path = dirname(__DIR__, 3) . '/etc/backend/menu.xml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('inquiry/backend/submission', $src);
        self::assertStringContainsString('Weline_Inquiry::submissions', $src);
    }

    public function testBackendSubmissionTemplateRendersPayloadSummary(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Submission/index.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('data-testid="inquiry-submissions"', $src);
        self::assertStringContainsString('payload_json', $src);
        self::assertStringContainsString('w-table', $src);
    }
}
