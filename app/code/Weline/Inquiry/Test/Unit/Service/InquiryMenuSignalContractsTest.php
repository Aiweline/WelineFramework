<?php

declare(strict_types=1);

namespace Weline\Inquiry\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inquiry\Extends\Module\Weline_Admin\BackendMenuSignalProvider\InquirySubmissionMenuSignalProvider;
use Weline\Inquiry\Service\SubmissionMenuSignalPublisher;

/**
 * 询盘菜单消息提醒：发布 → 侧栏 provider → 打开列表标已读 的集成契约。
 */
final class InquiryMenuSignalContractsTest extends TestCase
{
    private const MODULE_ROOT = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry';

    public function testNewSubmissionPublishesMenuSignal(): void
    {
        $service = $this->source('/Service/SubmissionService.php');
        self::assertStringContainsString('SubmissionMenuSignalPublisher', $service);
        self::assertStringContainsString('menuSignalPublisher->publish', $service);
        self::assertStringContainsString('(int)$submission->getId()', $service);
        self::assertStringContainsString('(array)$form', $service);
        self::assertStringContainsString('$clean', $service);
    }

    public function testProviderTargetsSubmissionsMenuAndReturnsUnreadDeepLinks(): void
    {
        $provider = $this->source('/extends/module/Weline_Admin/BackendMenuSignalProvider/InquirySubmissionMenuSignalProvider.php');

        self::assertStringContainsString("MENU_SOURCE_ID = 'Weline_Inquiry::submissions'", $provider);
        self::assertStringContainsString('BackendMenuSignalProviderInterface', $provider);
        self::assertStringContainsString('BackendMenuSignalItemsProviderInterface', $provider);
        self::assertStringContainsString('BackendMenuSignalAggregator::MAX_ITEMS_PER_CODE', $provider);
        self::assertStringContainsString('unreadItems(', $provider);
        self::assertStringContainsString("'unread' => true", $provider);
        self::assertStringContainsString("'count' => 1", $provider);
        self::assertStringContainsString("getBackendUrl('inquiry/backend/submission'", $provider);
        self::assertStringContainsString('#inquiry-submission-', $provider);
    }

    public function testPublisherGuardsMissingAdminModuleAndBuildsFocusHref(): void
    {
        $publisher = $this->source('/Service/SubmissionMenuSignalPublisher.php');

        self::assertStringContainsString('class_exists(BackendMenuSignalInbox::class)', $publisher);
        self::assertStringContainsString('InquirySubmissionMenuSignalProvider::MENU_SOURCE_ID', $publisher);
        self::assertStringContainsString("getBackendUrl('inquiry/backend/submission'", $publisher);
        self::assertStringContainsString('#inquiry-submission-', $publisher);
        self::assertStringContainsString('w_log_error(', $publisher);
    }

    public function testBackendListMarksSignalReadAfterFetchingRows(): void
    {
        $controller = $this->source('/Controller/Backend/Submission.php');

        self::assertStringContainsString('focus_submission_id', $controller);
        self::assertStringContainsString('markMenuSignalRead($focusId)', $controller);
        self::assertStringContainsString('menuSignalActorId', $controller);
        self::assertStringContainsString('BackendUserContextProviderInterface', $controller);
        self::assertDoesNotMatchRegularExpression('/function post(?!Reply\b)/', $controller);
        self::assertStringContainsString('function postReply', $controller);

        $assignPos = strpos($controller, "assign('submissions'");
        $markPos = strpos($controller, 'markMenuSignalRead($focusId)');
        self::assertNotFalse($assignPos);
        self::assertNotFalse($markPos);
        self::assertGreaterThan($assignPos, $markPos, 'markMenuSignalRead 必须在列表 fetch 之后');
    }

    public function testTemplateExposesSubmissionAnchorAndFocusHighlight(): void
    {
        $template = $this->source('/view/templates/backend/Submission/index.phtml');

        self::assertStringContainsString('$focus_submission_id', $template);
        self::assertStringContainsString('id="inquiry-submission-', $template);
        self::assertStringContainsString('data-submission-id=', $template);
        self::assertStringContainsString('$isFocus', $template);
        self::assertStringContainsString('inquiry-submission-row is-focused', $template);
        self::assertStringNotContainsString('color-warning-soft', $template);
        self::assertStringNotContainsString('#fffbeb', $template);
    }

    public function testProviderAndPublisherPublicContracts(): void
    {
        $provider = new InquirySubmissionMenuSignalProvider();
        self::assertSame('Weline_Inquiry::submissions', $provider->code());
        self::assertSame(0, $provider->count(0));
        self::assertSame([], $provider->items(0));

        self::assertTrue(class_exists(SubmissionMenuSignalPublisher::class));
        foreach (['publish', 'markRead', 'href'] as $method) {
            self::assertTrue(method_exists(SubmissionMenuSignalPublisher::class, $method));
        }
    }

    private function source(string $relativePath): string
    {
        $path = self::MODULE_ROOT . $relativePath;
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
