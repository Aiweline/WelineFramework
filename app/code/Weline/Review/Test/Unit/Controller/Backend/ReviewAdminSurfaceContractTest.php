<?php

declare(strict_types=1);

namespace Weline\Review\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

final class ReviewAdminSurfaceContractTest extends TestCase
{
    public function testMenuControllerAclAndCompilerSafeMediaControlsStayConnected(): void
    {
        $moduleRoot = dirname(__DIR__, 4);
        $controller = (string)file_get_contents($moduleRoot . '/Controller/Backend/Review.php');
        $menu = (string)file_get_contents($moduleRoot . '/etc/backend/menu.xml');
        $service = (string)file_get_contents($moduleRoot . '/Service/ReviewAdminService.php');
        $template = (string)file_get_contents($moduleRoot . '/view/templates/Backend/Review/index.phtml');

        self::assertStringContainsString("#[Acl('Weline_Review::list'", $controller);
        self::assertStringContainsString("#[Acl('Weline_Review::moderate'", $controller);
        self::assertStringContainsString("\$redirect = '*/backend/review';", $controller);
        self::assertStringContainsString('website_id_filter', $controller);
        self::assertStringContainsString('store_id_filter', $controller);
        self::assertStringContainsString("w_query('websites', 'getWebsiteSelectOptions'", $controller);
        self::assertStringContainsString("w_query('websites', 'getStoreCatalogV1'", $controller);
        self::assertStringContainsString('action="weline_review/backend/review"', $menu);
        self::assertStringContainsString('source="Weline_Review::list"', $menu);
        self::assertStringContainsString('foreach ($query->getItems() as $review)', $service);
        self::assertStringContainsString('if (!$review instanceof ProductReview)', $service);
        self::assertStringContainsString('$query->getPaginationState()', $service);
        self::assertStringContainsString("\$pagination['totalSize'] ?? count(\$items)", $service);
        self::assertStringContainsString("'ratings' => \$this->ratingValues('product'", $service);
        self::assertStringContainsString('ProductReview::schema_fields_WEBSITE_ID', $service);
        self::assertStringContainsString('ProductReview::schema_fields_STORE_ID', $service);
        self::assertStringContainsString('STATUS_AI_PENDING_BLOCKED', $service);
        self::assertStringContainsString('等待 AI 预审', $service);
        self::assertStringContainsString('data-review-role="rating-breakdown"', $template);
        self::assertStringContainsString('data-review-role="approve"', $template);
        self::assertStringContainsString('data-review-role="reject"', $template);
        self::assertStringContainsString('data-review-role="awaiting-ai"', $template);
        self::assertStringContainsString('data-w-sticky="end"', $template);
        self::assertStringContainsString('data-w-sticky-end', $template);
        self::assertStringContainsString('data-review-role="table-scroll"', $template);
        self::assertStringNotContainsString('position:sticky;right:0', $template);
        self::assertStringContainsString('data-review-role="filters"', $template);
        self::assertFileExists($moduleRoot . '/Cron/AiModeration.php');
        self::assertFileExists($moduleRoot . '/Service/ReviewAiModerationService.php');
        $cron = (string)file_get_contents($moduleRoot . '/Cron/AiModeration.php');
        $aiService = (string)file_get_contents($moduleRoot . '/Service/ReviewAiModerationService.php');
        self::assertStringContainsString('weline_review_ai_moderation', $cron);
        self::assertStringContainsString("w_query('ai', 'generateText'", $aiService);
        self::assertStringContainsString('w_msg(', $aiService);
        self::assertStringContainsString('STATUS_AI_PENDING_BLOCKED', $aiService);
        self::assertStringContainsString('<w:websites:website:select', $template);
        self::assertStringContainsString('<w:websites:store:select', $template);
        self::assertStringContainsString('name="website_id"', $template);
        self::assertStringContainsString('name="store_id"', $template);
        self::assertStringContainsString('name="website_id_filter"', $template);
        self::assertStringContainsString('name="store_id_filter"', $template);
        self::assertStringContainsString('<video', $template);
        self::assertStringContainsString('<img', $template);
        self::assertStringContainsString('csrf="auto"', $template);
        self::assertSame(1, substr_count($template, '<w:form'));
        self::assertStringNotContainsString('data-testid', $template);
        self::assertStringNotContainsString('<?php foreach', $template);
        self::assertStringNotContainsString('<?php if', $template);
    }
}
