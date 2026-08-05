<?php

declare(strict_types=1);

namespace Weline\Framework\View\Taglib\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Taglib\Support\FloatingDropdownEmitter;

final class FloatingDropdownEmitterContractTest extends TestCase
{
    public function testScriptDefinesTaglibOwnedGlobalWithHoverBridge(): void
    {
        $script = FloatingDropdownEmitter::script();
        $js = FloatingDropdownEmitter::javaScript();

        self::assertStringContainsString('<script', $script);
        self::assertStringContainsString(FloatingDropdownEmitter::SCRIPT_MARKER, $script);
        self::assertStringContainsString(FloatingDropdownEmitter::GLOBAL_NAME, $js);
        self::assertStringContainsString(FloatingDropdownEmitter::HOVER_BRIDGE_ATTR, $js);
        self::assertStringContainsString('hoverBridge', $js);
        self::assertStringContainsString('function place(', $js);
        self::assertStringContainsString('function mount(', $js);
        self::assertStringContainsString('function unmount(', $js);
        self::assertStringNotContainsString('WelineSmartDropdown', $js);
    }
}
