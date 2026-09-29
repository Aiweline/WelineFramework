<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Version\ThemeVersionResourceSnapshotService;

final class ThemeVersionPublicationReferencesTest extends TestCase
{
    public function testOnlySelectedDraftResourcesReplacePublishedReferences(): void
    {
        $service = new ThemeVersionResourceSnapshotService();
        $draft = [$this->row('home', 'layout', 'homepage', 101), $this->row('product', 'layout', 'product', 102), $this->row('meta', 'meta', 'homepage', 103)];
        $published = [$this->row('home', 'layout', 'homepage', 11), $this->row('product', 'layout', 'product', 12), $this->row('meta', 'meta', 'homepage', 13)];
        $rows = $service->composePublishedReferences($draft, $published, ['layout:homepage']);
        self::assertSame(['home'=>101, 'meta'=>103, 'product'=>12], array_column($rows, 'intent_revision_id', 'resource_identity_hash'));
        self::assertSame(102, $draft[1]['intent_revision_id'], 'The remainder retains the original draft reference.');
    }

    public function testSingleMetaResourceDoesNotPublishPageDraftAndMissingSelectedMeansPackageDefault(): void
    {
        $service = new ThemeVersionResourceSnapshotService();
        $draft = [$this->row('home', 'layout', 'homepage', 101), $this->row('meta', 'meta', 'homepage', 103)];
        $published = [$this->row('home', 'layout', 'homepage', 11), $this->row('meta', 'meta', 'homepage', 13), $this->row('product', 'layout', 'product', 12)];
        $rows = $service->composePublishedReferences($draft, $published, ['meta']);
        self::assertSame(['home'=>11, 'meta'=>103, 'product'=>12], array_column($rows, 'intent_revision_id', 'resource_identity_hash'));
        $rows = $service->composePublishedReferences($draft, $published, ['layout:product']);
        self::assertArrayNotHasKey('product', array_column($rows, null, 'resource_identity_hash'));
    }

    public function testChromePublicationCarriesItsFrozenPartialOptionAndLeavesPageSettingsPublished(): void
    {
        $draft = ['partial_options'=>['header'=>'compact'],
            'params'=>['partials.header.compact'=>['title'=>'Draft header'], 'layouts.homepage.default'=>['title'=>'Draft page']]];
        $published = ['partial_options'=>['header'=>'default'],
            'params'=>['partials.header.default'=>['title'=>'Published header'], 'layouts.homepage.default'=>['title'=>'Published page']]];
        $method = new \ReflectionMethod(ThemeVersionResourceSnapshotService::class, 'publicationConfiguration');
        $result = $method->invoke(new ThemeVersionResourceSnapshotService(), $draft, $published, ['chrome']);
        self::assertSame(['header'=>'compact'], $result['partial_options']);
        self::assertSame(['title'=>'Draft header'], $result['params']['partials.header.compact']);
        self::assertArrayNotHasKey('partials.header.default', $result['params']);
        self::assertSame(['title'=>'Published page'], $result['params']['layouts.homepage.default']);
    }

    private function row(string $hash, string $type, string $layout, int $revision): array
    {
        return ['resource_identity_hash'=>$hash, 'resource_type'=>$type, 'resource_key_json'=>json_encode(['resource_type'=>$type, 'layout_type'=>$layout]), 'intent_revision_id'=>$revision];
    }
}
