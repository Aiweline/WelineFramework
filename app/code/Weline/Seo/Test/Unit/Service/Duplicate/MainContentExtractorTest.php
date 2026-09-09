<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Duplicate;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Duplicate\MainContentExtractor;

final class MainContentExtractorTest extends TestCase
{
    public function testPrefersArticleBody(): void
    {
        $html = '<html><body><nav>菜单</nav><article><p>' . \str_repeat('正文段落。', 20) . '</p></article><footer>页脚</footer></body></html>';
        $text = (new MainContentExtractor())->extract($html);
        self::assertStringContainsString('正文段落', $text);
        self::assertStringNotContainsString('菜单', $text);
        self::assertStringNotContainsString('页脚', $text);
    }
}
