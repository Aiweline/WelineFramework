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
}
