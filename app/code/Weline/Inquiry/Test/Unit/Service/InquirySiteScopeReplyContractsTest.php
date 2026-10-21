<?php

declare(strict_types=1);

namespace Weline\Inquiry\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inquiry\Service\InquiryMailReplySlotService;

/**
 * 询盘站点归属、范围筛选、站内回复 / 站外 mailto 的集成契约。
 */
final class InquirySiteScopeReplyContractsTest extends TestCase
{
    private const MODULE_ROOT = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry';

    public function testSubmissionBelongsToWebsite(): void
    {
        $model = $this->source('/Model/Submission.php');
        self::assertStringContainsString("schema_fields_WEBSITE_ID = 'website_id'", $model);
        self::assertStringContainsString('idx_inquiry_submission_website_created', $model);

        $service = $this->source('/Service/SubmissionService.php');
        self::assertStringContainsString('schema_fields_WEBSITE_ID', $service);
        self::assertStringContainsString('resolveSubmissionWebsiteId', $service);
        self::assertStringContainsString('$params[\'website_id\']', $service);

        $scope = $this->source('/Service/InquiryWebsiteScopeService.php');
        self::assertStringContainsString('WebsiteData::getWebsiteId', $scope);
        self::assertStringContainsString('websiteIdForHost', $scope);

        $renderer = $this->source('/Service/InquiryRenderer.php');
        self::assertStringContainsString("'website_id' =>", $renderer);
        self::assertStringContainsString('function websiteId', $renderer);
        self::assertStringContainsString('website_id:Number(c.website_id', $renderer);

        $provider = $this->source('/extends/module/Weline_Framework/Query/InquiryQueryProvider.php');
        self::assertStringContainsString("'name' => 'website_id'", $provider);
    }

    public function testBackendListHasWebsiteScopeFilterAndReplyActions(): void
    {
        $controller = $this->source('/Controller/Backend/Submission.php');
        self::assertStringContainsString('website_id', $controller);
        self::assertStringContainsString('scope_website_id', $controller);
        self::assertStringContainsString('applyScopeFilter', $controller);
        self::assertStringContainsString('InquiryMailReplySlotService', $controller);
        self::assertStringContainsString('function postReply', $controller);
        self::assertStringContainsString('function getThread', $controller);
        self::assertStringContainsString("Weline_Inquiry::reply", $controller);
        self::assertStringContainsString('loadScopedSubmission', $controller);
    }

    public function testReplySlotUsesOwnOrSearchableBackendMailboxAndMailto(): void
    {
        $slot = $this->source('/Service/InquiryMailReplySlotService.php');
        self::assertStringContainsString("SOURCE = 'inquiry'", $slot);
        self::assertStringContainsString('buildForSubmission', $slot);
        self::assertStringContainsString('resolveAccountId', $slot);
        self::assertStringContainsString('listLocalMailboxes', $slot);
        self::assertStringContainsString('resolveLocalMailboxByEmail', $slot);
        self::assertStringContainsString('canPickAnyLocalMailbox', $slot);
        self::assertStringContainsString("'mode' => 'own'", $slot);
        self::assertStringContainsString("'mode' => 'pick'", $slot);
        self::assertStringContainsString("'mailto_url' =>", $slot);
        self::assertStringContainsString("'source' => self::SOURCE", $slot);
    }

    public function testTemplateRendersScopeFilterMailComposerAndExternalReply(): void
    {
        $template = $this->source('/view/templates/backend/Submission/index.phtml');
        self::assertStringContainsString('inquiry-scope-filter', $template);
        self::assertStringContainsString("__('全部站点')", $template);
        self::assertStringContainsString('name="website_id"', $template);
        self::assertStringContainsString('<w:mail-composer area="backend"/>', $template);
        self::assertStringContainsString('data-inquiry-mail-open', $template);
        self::assertStringContainsString("__('站内回复')", $template);
        self::assertStringContainsString("__('站外回复')", $template);
        self::assertStringContainsString('mailto_url', $template);
        self::assertStringContainsString('data-send-url', $template);
        self::assertStringContainsString('data-thread-url', $template);
    }

    public function testReplySlotPublicContract(): void
    {
        $slot = new InquiryMailReplySlotService();
        self::assertSame('inquiry', InquiryMailReplySlotService::SOURCE);
        self::assertSame(0, $slot->resolveAccountId(0, 0));
    }

    private function source(string $relativePath): string
    {
        $path = self::MODULE_ROOT . $relativePath;
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
