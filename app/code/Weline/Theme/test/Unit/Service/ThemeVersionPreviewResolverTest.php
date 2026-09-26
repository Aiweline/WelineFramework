<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\ThemeVersionPreviewResolver;

final class ThemeVersionPreviewResolverTest extends TestCase
{
    private function resolver(): ThemeVersionPreviewResolver
    {
        return new ThemeVersionPreviewResolver();
    }

    public function testExplicitReleaseSucceedsWithoutLegacyEntityKey(): void
    {
        $releases = [
            ['release_id' => 1, 'effective_payload_json' => ['nodes' => [['widget_code' => 'old']]]],
            ['release_id' => 2, 'effective_payload_json' => ['nodes' => [['widget_code' => 'new']]]],
        ];
        $result = $this->resolver()->selectPageBinding([], $releases, [], 1);
        self::assertTrue($result['resolved']);
        self::assertSame(1, $result['release_id']);
        self::assertSame('', $result['entity_key']);
        self::assertDoesNotMatchRegularExpression('/^[rds]\\d+$/', (string)$result['entity_key']);
    }

    public function testStructureEqualWithoutExplicitIdDoesNotResolve(): void
    {
        $node = ['node_uid' => 'x', 'widget_code' => 'same'];
        $payload = ['nodes' => [$node]];
        $releases = [
            ['release_id' => 1, 'effective_payload_json' => $payload],
            ['release_id' => 2, 'effective_payload_json' => $payload],
        ];
        $draft = ['draft_revision_id' => 9, 'draft_payload' => $payload];

        self::assertFalse($this->resolver()->selectPageBinding([$node], $releases, [])['resolved']);
        self::assertFalse($this->resolver()->selectPageBinding([$node], [], $draft)['resolved']);
        self::assertSame('preview_page_version_unresolved', $this->resolver()->selectPageBinding([$node], $releases, $draft)['reason']);
    }

    public function testAmbiguousStructureNeverPicksCurrentOrNewest(): void
    {
        $node = ['node_uid' => 'x', 'widget_code' => 'old'];
        $payload = ['nodes' => [$node]];
        $releases = [
            ['release_id' => 1, 'effective_payload_json' => $payload],
            ['release_id' => 2, 'effective_payload_json' => $payload],
        ];
        $result = $this->resolver()->selectPageBinding([$node], $releases, []);
        self::assertFalse($result['resolved']);
        self::assertSame('', $result['entity_key']);

        $explicit = $this->resolver()->selectPageBinding([$node], $releases, [], 2);
        self::assertTrue($explicit['resolved']);
        self::assertSame(2, $explicit['release_id']);
        self::assertSame('', $explicit['entity_key']);
    }

    public function testExplicitDraftRevisionSucceedsWithoutDPrefixKey(): void
    {
        $draft = ['draft_revision_id' => 9, 'draft_payload' => ['nodes' => [['widget_code' => 'draft']]]];
        $result = $this->resolver()->selectPageBinding([], [], $draft, null, 9);
        self::assertTrue($result['resolved']);
        self::assertSame(9, $result['draft_revision_id']);
        self::assertSame('', $result['entity_key']);
        self::assertStringNotContainsString('d9', (string)$result['entity_key']);
    }

    public function testApplyTokenVersionCursorOverlaysOwnerAndRevision(): void
    {
        $identity = new ThemeVersionIdentity(3, 'default.1.1', 'normal', 'frontend', 10, 'formal', 1);
        $resolved = [
            'resolved' => true,
            'theme_version_id' => 10,
            'version_id' => 10,
            'chrome_version_id' => 10,
            'mode' => 'formal',
            'content_revision' => 1,
            'version_identity' => $identity,
        ];
        $out = $this->resolver()->applyTokenVersionCursor([
            'theme_version_id' => 42,
            'mode' => 'draft',
            'content_revision' => 7,
            'canonical_scope' => 'default.2.2',
            'store_mode' => 'b2b',
            'area' => 'frontend',
        ], $resolved);

        self::assertSame(42, $out['theme_version_id']);
        self::assertSame(42, $out['version_id']);
        self::assertSame(42, $out['chrome_version_id']);
        self::assertSame('draft', $out['mode']);
        self::assertSame(7, $out['content_revision']);
        self::assertSame('default.2.2', $out['canonical_scope']);
        self::assertInstanceOf(ThemeVersionIdentity::class, $out['version_identity']);
        self::assertSame(42, $out['version_identity']->themeVersionId);
        self::assertSame('draft', $out['version_identity']->mode);
        self::assertSame(7, $out['version_identity']->contentRevision);
    }

    public function testMissingExplicitIdentityStaysUnresolved(): void
    {
        $result = $this->resolver()->selectPageBinding(
            [['node_uid' => 'x', 'widget_code' => 'any']],
            [['release_id' => 1, 'effective_payload_json' => ['nodes' => [['widget_code' => 'any']]]]],
            [],
        );
        self::assertFalse($result['resolved']);
        self::assertSame('', $result['entity_key']);
        self::assertDoesNotMatchRegularExpression('/^r\\d+$/', (string)$result['entity_key']);
    }
}
