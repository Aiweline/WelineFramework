<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Model;

use GuoLaiRen\PageBuilder\Model\Page;
use PHPUnit\Framework\TestCase;

final class PageAiPublishSnapshotTest extends TestCase
{
    public function testPublishSnapshotsKeepOnlyLatestEntry(): void
    {
        $page = new Page();

        $page->appendAiPublishSnapshot(['blocks' => [['title' => 'first']]]);
        $page->appendAiPublishSnapshot(['blocks' => [['title' => 'latest']]]);

        $snapshots = $page->getAiPublishSnapshotsList();

        self::assertCount(1, $snapshots);
        self::assertSame('latest', $snapshots[0]['ai_layout']['blocks'][0]['title'] ?? null);
    }

    public function testNavigationLabelPrefersCompactPageNameOverSeoTitle(): void
    {
        $method = new \ReflectionMethod(Page::class, 'resolveNavigationLabel');

        $about = new Page();
        $about->setData(Page::schema_fields_TYPE, Page::TYPE_ABOUT);
        $about->setData(Page::schema_fields_NAME, 'About');
        $about->setData(Page::schema_fields_TITLE, 'About - Brand SEO Title');

        self::assertSame('About', $method->invoke($about));

        $home = new Page();
        $home->setData(Page::schema_fields_TYPE, Page::TYPE_HOME);
        $home->setData(Page::schema_fields_NAME, 'Teen Patti Royal APK');
        $home->setData(Page::schema_fields_TITLE, 'Teen Patti Royal APK');

        $homeLabel = \json_decode('"\u9996\u9875"', true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame((string)__($homeLabel), $method->invoke($home));
    }
}
