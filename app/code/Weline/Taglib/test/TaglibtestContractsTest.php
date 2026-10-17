<?php

declare(strict_types=1);

namespace Weline\Taglib\test;

use PHPUnit\Framework\TestCase;
use Weline\Taglib\Taglib\Scope;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllTaglibtestContractsPass().
 */
final class TaglibtestContractsTest extends TestCase
{
    public function testAllTaglibtestContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ScopeTag::testScopePersistenceScriptDoesNotStormRetryOnLoadFailure' => function () {
            $this->assert_ScopeTag_testScopePersistenceScriptDoesNotStormRetryOnLoadFailure();
        },
            'ScopeTag::testLegacyPersistenceModeRemainsCompatible' => function () {
            $this->assert_ScopeTag_testLegacyPersistenceModeRemainsCompatible();
        },
            'ScopeTag::testDefaultModeEmitsBuildMarkupCallNotRawSelectorPhp' => function () {
            $this->assert_ScopeTag_testDefaultModeEmitsBuildMarkupCallNotRawSelectorPhp();
        },
            'ScopeTag::testRuntimeCallbackReturnsHtmlNotPhpSource' => function () {
            $this->assert_ScopeTag_testRuntimeCallbackReturnsHtmlNotPhpSource();
        },
        ];
    }

private function assert_ScopeTag_testScopePersistenceScriptDoesNotStormRetryOnLoadFailure(): void
{
    $source = \file_get_contents(BP . 'app/code/Weline/Taglib/view/statics/js/scope-persistence.js');
    self::assertIsString($source);
    self::assertStringContainsString('loadFailedScopes', $source);
    self::assertStringContainsString('scheduleDiscover', $source);
    self::assertStringContainsString('toastedScopes', $source);
    self::assertStringContainsString('失败后不再因 MutationObserver', $source);
    self::assertStringContainsString('data-catalog-category-select-value', $source);
    self::assertStringContainsString('setValue(normalized, { silent: true })', $source);
    self::assertStringContainsString('applyCategory', $source);
    self::assertStringContainsString('pendingCategoryById', $source);
    self::assertStringContainsString('weline:catalog-category-select-ready', $source);
}

private function assert_ScopeTag_testLegacyPersistenceModeRemainsCompatible(): void
{
    $html = Scope::callback()('scope', [], [], ['container-id' => 'profile', 'url' => '/save', 'event' => 'change click']);
    self::assertStringContainsString('data-w-component="scope-persistence"', $html);
    self::assertStringContainsString('data-w-scope-container="profile"', $html);
    self::assertStringContainsString('data-w-scope-events="change click"', $html);
    self::assertFalse((new \ReflectionClass(Scope::class))->isFinal());
}

private function assert_ScopeTag_testDefaultModeEmitsBuildMarkupCallNotRawSelectorPhp(): void
{
    $html = Scope::callback()('scope', [], [], ['id' => 'scopeSelect', 'name' => 'scope', 'value' => 'selected_scope']);
    self::assertStringContainsString('Scope::buildMarkup([', $html);
    self::assertStringContainsString("\$Taglib__value ?? 'default.default.default'", $html);
    self::assertStringContainsString('Weline_Taglib_resolve', $html);
    self::assertStringNotContainsString('w-scope-select-dropdown', $html);
    self::assertStringNotContainsString('\Weline\Framework\ObjectManager\ObjectManager', $html);
    self::assertStringNotContainsString('Weline\Websites\\', $html);
    $source = (string) \file_get_contents(BP . '/app/code/Weline/Taglib/Taglib/Scope.php');
    self::assertStringContainsString('FloatingDropdownEmitter::script()', $source);
    self::assertStringContainsString('ScopeSelectorCatalogInterface::class', $source);
    self::assertTrue(\method_exists(Scope::class, 'runtimeCallback'));
    self::assertTrue(\method_exists(Scope::class, 'buildMarkup'));
}

private function assert_ScopeTag_testRuntimeCallbackReturnsHtmlNotPhpSource(): void
{
    $template = $this->createStub(\Weline\Framework\View\Template::class);
    $template->method('getData')->willReturnMap([['selected_scope', 'default.default.default']]);
    $html = Scope::runtimeCallback()($template, 'tag-self-close-with-attrs', ['id' => 'shipping-work-scope', 'name' => 'target_scope', 'value' => 'selected_scope', 'placeholder' => '选择作用范围', 'search-placeholder' => '搜索作用范围', 'aria-label' => '选择作用范围', 'searchable' => 'true', 'default-expand-all' => 'true', 'required' => 'true', 'options' => \json_encode([['value' => 'default.default.default', 'label' => 'Global', 'display_label' => 'Global', 'title_label' => 'Global', 'kind' => 'global', 'children' => []]], JSON_UNESCAPED_UNICODE)], '');
    self::assertStringContainsString('data-component="scope-select"', $html);
    self::assertStringContainsString('w-scope-select-dropdown', $html);
    self::assertStringContainsString('role="tree"', $html);
    self::assertStringContainsString('data-w-scope-node', $html);
    self::assertStringContainsString('window.WelineScopeSelect', $html);
    self::assertStringContainsString("event.key === 'ArrowDown'", $html);
    self::assertStringNotContainsString('Weline_Taglib_resolve', $html);
    self::assertStringNotContainsString('$Taglib__required', $html);
    self::assertDoesNotMatchRegularExpression('/<\?php\b/', $html);
}
}
