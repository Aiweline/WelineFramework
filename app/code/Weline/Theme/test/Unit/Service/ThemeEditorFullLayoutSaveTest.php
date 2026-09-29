<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ThemeEditorFullLayoutSaveTest extends TestCase
{
    public function testExplicitEmptyLayoutUsesOneCanonicalSaveAndPreservesPublicChrome(): void
    {
        $result = $this->scenario('empty');
        self::assertTrue($result['response']['success'], $result['response']['message']);
        self::assertSame(['replace.begin', 'db.commit', 'phtml.publish'], $result['events']);
        self::assertSame(0, $result['legacy_calls']);
        self::assertSame(5, $result['response']['data']['scoped_workspace']['revision']);
        self::assertSame(4, $result['response']['data']['content_revision']);
        foreach (['a', 't'] as $key) {
            $node = $result['payload']['nodes'][str_repeat($key === 't' ? 'd' : 'a', 32)];
            self::assertFalse($node['is_active']);
            self::assertSame('user_deleted', $node['source']);
        }
        foreach (['c', 'e', 'f'] as $key) {
            self::assertSame($result['before']['nodes'][str_repeat($key, 32)], $result['payload']['nodes'][str_repeat($key, 32)]);
        }
        self::assertCount(1, array_filter($result['payload']['nodes'], static fn(array $node): bool => $node['widget_code'] === '__no_widget_placements__'));
        self::assertNotContains('__no_widget_placements__', $result['projected_widget_codes'], 'Layout state must not be validated as a registered widget.');
    }

    public function testGroupedNonEmptyFormKeepsIdentityPlacementAndExplicitConfigValues(): void
    {
        $result = $this->scenario('nonempty');
        self::assertTrue($result['response']['success'], $result['response']['message']);
        self::assertSame(['replace.begin', 'db.commit', 'phtml.publish'], $result['events']);
        self::assertSame(0, $result['legacy_calls']);
        $node = $result['payload']['nodes'][str_repeat('b', 32)];
        self::assertSame(str_repeat('b', 32), $node['node_uid']);
        self::assertSame(str_repeat('a', 32), $node['parent_uid']);
        self::assertSame('inside', $node['position']);
        self::assertSame('children', $node['slot_id']);
        self::assertSame(17, $node['sort_order']);
        self::assertFalse($node['is_active']);
        self::assertSame(['enabled' => false, 'nullable' => null, 'items' => [], 'zero' => 0, '_i18n_instance' => 'wi_' . str_repeat('b', 32)], $node['config']);
    }

    public function testNonEmptyFormAfterClearingKeepsRemovalIntentAndPublicPartials(): void
    {
        $result = $this->scenario('empty_then_nonempty');
        self::assertTrue($result['empty_response']['success']);
        self::assertTrue($result['response']['success'], $result['response']['message']);
        self::assertSame(5, $result['response']['data']['content_revision']);
        self::assertSame(['replace.begin', 'db.commit', 'phtml.publish', 'replace.begin', 'db.commit', 'phtml.publish'], $result['events']);
        self::assertStringContainsString('<b>replacement</b>', $result['rendered_page']);
        self::assertStringNotContainsString('TEMPLATE-DEFAULT', $result['rendered_page']);
        self::assertStringContainsString('<i>DYNAMIC</i>', $result['rendered_page']);
        foreach (['a', 'd'] as $key) {
            $uid = str_repeat($key, 32);
            self::assertArrayHasKey($uid, $result['payload']['nodes'], 'Adding a placement must not discard a previous removal.');
            self::assertSame($result['empty_payload']['nodes'][$uid], $result['payload']['nodes'][$uid]);
            self::assertFalse($result['payload']['nodes'][$uid]['is_active']);
        }
        foreach (['c', 'e', 'f'] as $key) {
            $uid = str_repeat($key, 32);
            self::assertSame($result['before']['nodes'][$uid], $result['payload']['nodes'][$uid]);
        }
        self::assertCount(1, array_filter($result['payload']['nodes'], static fn(array $node): bool => $node['widget_code'] === '__no_widget_placements__'));
        self::assertTrue($result['payload']['nodes'][str_repeat('b', 32)]['is_active']);
        self::assertSame('replacement', $result['payload']['nodes'][str_repeat('b', 32)]['widget_code']);
    }

    public function testStaleResourceAndParentCursorsDoNotCommitOrPublish(): void
    {
        foreach (['revision', 'parent', 'content_revision'] as $scenario) {
            $result = $this->scenario($scenario);
            self::assertFalse($result['response']['success']);
            self::assertStringContainsString('conflict', $result['response']['message']);
            self::assertNotContains('db.commit', $result['events']);
            self::assertNotContains('phtml.publish', $result['events']);
            self::assertSame($result['before'], $result['payload']);
        }
    }

    public function testPublicationFailureReturnsTheCommittedCursorWithoutSecondReplace(): void
    {
        $result = $this->scenario('bake_failure');
        self::assertFalse($result['response']['success']);
        self::assertSame(['replace.begin', 'db.commit', 'phtml.publish'], $result['events']);
        self::assertSame(0, $result['legacy_calls']);
        self::assertSame(true, $result['response']['saved_revision']['database_saved']);
        self::assertSame(false, $result['response']['saved_revision']['artifacts_saved']);
        self::assertSame(4, $result['response']['saved_revision']['actual_content_revision']);
        self::assertSame(5, $result['response']['saved_revision']['actual_revision']);
    }

    public function testLegacyBuilderAndCopySaveDoNotEncloseArtifactPublicationInAnOuterTransaction(): void
    {
        foreach (['compat_replace', 'compat_copy'] as $scenario) {
            $result = $this->scenario($scenario);
            self::assertTrue($result['response']['success']);
            self::assertSame(['replace.begin', 'db.commit', 'phtml.publish'], $result['events']);
            self::assertSame(0, $result['legacy_calls']);
        }
    }

    private function scenario(string $scenario): array
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/theme-editor-full-layout-save.php') . ' ' . escapeshellarg($scenario) . ' 2>&1', $lines, $exit);
        self::assertSame(0, $exit, implode("\n", $lines));
        return json_decode(implode("\n", $lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
