<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeVersionPreviewResolver;
use Weline\Theme\Service\ThemeLayoutVersionBindingResolver;
use Weline\Theme\Service\SharedChromeService;

final class ThemeVersionPreviewResolverTest extends TestCase
{
    private function resolver(): ThemeVersionPreviewResolver
    {
        return new ThemeVersionPreviewResolver(new ThemeLayoutVersionBindingResolver(
            (new ReflectionClass(SharedChromeService::class))->newInstanceWithoutConstructor()));
    }
    public function testExactHistoricalSnapshotWinsOverNewCurrentRelease(): void
    {
        $old = ['node_uid' => 'x', 'area' => 'content', 'widget_code' => 'old'];
        $new = array_replace($old, ['widget_code' => 'new']);
        $releases = [['release_id' => 1, 'effective_payload_json' => ['nodes' => ['x' => $old]]], ['release_id' => 2, 'effective_payload_json' => ['nodes' => ['x' => $new]]]];
        $result = $this->resolver()->selectPageBinding(['x' => $old], $releases, []);
        self::assertTrue($result['resolved']);
        self::assertSame('r1', $result['entity_key']);
    }
    public function testNoMatchAndAmbiguousMatchesNeverUseCurrent(): void
    {
        $node = ['node_uid' => 'x', 'widget_code' => 'old'];
        self::assertFalse($this->resolver()->selectPageBinding([$node], [], [])['resolved']);
        $payload = ['nodes' => [$node]];
        $releases = [['release_id' => 1, 'effective_payload_json' => $payload], ['release_id' => 2, 'effective_payload_json' => $payload]];
        self::assertFalse($this->resolver()->selectPageBinding([$node], $releases, [])['resolved']);
        self::assertSame('r2', $this->resolver()->selectPageBinding([$node], $releases, [], 2)['entity_key']);
    }
    public function testDraftRequiresExactPayloadAndExplicitRevision(): void
    {
        $node = ['node_uid' => 'x', 'widget_code' => 'draft'];
        $draft = ['draft_revision_id' => 9, 'draft_payload' => ['nodes' => [$node]]];
        self::assertSame('d9', $this->resolver()->selectPageBinding([$node], [], $draft)['entity_key']);
        self::assertFalse($this->resolver()->selectPageBinding([array_replace($node, ['widget_code' => 'other'])], [], $draft)['resolved']);
    }
    public function testHistoricalDraftSelectionDoesNotUseNewestRevision(): void
    {
        $node = ['node_uid' => 'x', 'widget_code' => 'old'];
        $drafts = [['draft_revision_id' => 3, 'draft_payload' => ['nodes' => [$node]]],
            ['draft_revision_id' => 9, 'draft_payload' => ['nodes' => [array_replace($node, ['widget_code' => 'new'])]]]];
        self::assertSame('d3', $this->resolver()->selectPageBinding([$node], [], $drafts)['entity_key']);
    }
}
