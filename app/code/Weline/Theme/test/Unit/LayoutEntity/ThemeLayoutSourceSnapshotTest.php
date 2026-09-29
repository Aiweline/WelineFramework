<?php

declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;
use Weline\Framework\View\Template;

final class ThemeLayoutSourceSnapshotTest extends TestCase
{
    public function testCapturedPageAndPartialStayOnOneRevisionAfterReplacement(): void
    {
        self::assertTrue(class_exists(ThemeLayoutSourceSnapshot::class), 'A request must retain captured source bytes, not mutable paths.');
        $root = sys_get_temp_dir() . '/weline-snapshot-' . bin2hex(random_bytes(6)) . '/theme-layout-entities';
        $paths = new ThemeLayoutEntityPaths($root);
        $identity = new ThemeVersionIdentity(3, 'default.default.default', 'normal', 'frontend', 10, 'draft', 2);
        $page = $paths->pageLayoutPhtml($identity, 'homepage');
        $partial = $paths->partialPhtml($identity, 'header', 'compact');
        try {
            mkdir(dirname($page), 0770, true);
            mkdir(dirname($partial), 0770, true);
            file_put_contents($page, $this->source($identity, '<b>old-page</b>', ['layout_type'=>'homepage', 'layout_option'=>'default','target_type'=>'global','target_id'=>0]));
            file_put_contents($partial, $this->source($identity, '<i>old-header</i>', ['resource_type'=>'partial','partial_type'=>'header','partial_option'=>'compact']));
            $snapshot = ThemeLayoutSourceSnapshot::capture($paths, $identity, 'homepage');
            file_put_contents($page, $this->source($identity->withVersion(10, 'draft', 3), '<b>new-page</b>', []));
            file_put_contents($partial, $this->source($identity->withVersion(10, 'draft', 3), '<i>new-header</i>', []));
            $snapshot->install(Template::getInstance());
            self::assertStringContainsString('old-page', Template::getInstance()->fetchHtml($page));
            self::assertStringContainsString('old-header', Template::getInstance()->fetchHtml($partial));
            self::assertStringContainsString('old-header', Template::getInstance()->fetchHtml('Weline_Theme::theme/frontend/partials/header/default.phtml'));
            self::assertSame($partial, $snapshot->partialPath('header', 'compact'));
            self::assertNull($snapshot->partialPath('header', 'default'));
            $stale = ThemeLayoutSourceSnapshot::capture($paths, $identity, 'homepage');
            self::assertNull($stale->pagePath());
            self::assertNull($stale->partialPath('header', 'compact'));
        } finally {
            if (is_dir($root)) { $paths->purgeAllEntities(); @rmdir(dirname($root)); }
        }
    }

    private function source(ThemeVersionIdentity $identity, string $html, array $metadata): string
    {
        return '<?php /* weline-source:' . base64_encode(json_encode(['origin'=>__FILE__, 'identity'=>$identity->toArray()] + $metadata, JSON_THROW_ON_ERROR)) . ' */ ?>' . $html;
    }
}
