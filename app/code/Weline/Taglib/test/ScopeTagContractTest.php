<?php

declare(strict_types=1);

namespace Weline\Taglib\test;

use PHPUnit\Framework\TestCase;
use Weline\Taglib\Taglib\Scope;

\defined('BP') || \define('BP', \dirname(__DIR__, 5) . \DIRECTORY_SEPARATOR);
require_once BP . 'app/code/Weline/Taglib/Taglib/Scope.php';

final class ScopeTagContractTest extends TestCase
{
    public function testScopePersistenceScriptDoesNotStormRetryOnLoadFailure(): void
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

    public function testLegacyPersistenceModeRemainsCompatible(): void
    {
        $html = (Scope::callback())('scope', [], [], [
            'container-id' => 'profile',
            'url' => '/save',
            'event' => 'change click',
        ]);

        self::assertStringContainsString('data-w-component="scope-persistence"', $html);
        self::assertStringContainsString('data-w-scope-container="profile"', $html);
        self::assertStringContainsString('data-w-scope-events="change click"', $html);
        self::assertFalse((new \ReflectionClass(Scope::class))->isFinal());
    }

    public function testDefaultModeEmitsThePublicSystemTreeSelector(): void
    {
        $html = (Scope::callback())('scope', [], [], [
            'id' => 'scopeSelect',
            'name' => 'scope',
            'value' => 'selected_scope',
        ]);

        self::assertStringContainsString('ScopeSelectorCatalogInterface::class', $html);
        self::assertStringContainsString('\\Weline\\Framework\\Manager\\ObjectManager::getInstance(', $html);
        self::assertStringNotContainsString('\\Weline\\Framework\\ObjectManager\\ObjectManager', $html);
        self::assertStringContainsString('w-scope-select-dropdown', $html);
        self::assertStringContainsString('FloatingDropdownEmitter::script()', file_get_contents(
            BP . '/app/code/Weline/Taglib/Taglib/Scope.php'
        ));
        self::assertStringContainsString('id="<?= $__wscope_escape($__wscope_id) ?>"', $html);
        self::assertStringContainsString('role="tree"', $html);
        self::assertStringContainsString('data-w-scope-node', $html);
        self::assertStringContainsString("event.key === 'ArrowDown'", $html);
        self::assertStringContainsString('window.WelineScopeSelect', $html);
        self::assertStringNotContainsString('Weline\\Websites\\', $html);
    }
}
