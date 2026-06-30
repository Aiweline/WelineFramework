<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Helper\ThemeData;

final class ThemeDataConnectionScopeTest extends TestCase
{
    protected function tearDown(): void
    {
        ThemeData::clearCache();
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        parent::tearDown();
    }

    public function testThemeDataStateIsScopedByConnectionId(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setConnectionId('conn-theme-a');
        ThemeData::setCurrentArea('frontend');

        RequestContext::setConnectionId('conn-theme-b');
        ThemeData::setCurrentArea('backend');
        self::assertSame('backend', ThemeData::getCurrentArea());

        RequestContext::setConnectionId('conn-theme-a');
        self::assertSame('frontend', ThemeData::getCurrentArea());
    }

    public function testThemeDataResetOnlyClearsCurrentConnectionScope(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setConnectionId('conn-theme-a');
        ThemeData::setCurrentArea('frontend');

        RequestContext::setConnectionId('conn-theme-b');
        ThemeData::setCurrentArea('backend');
        ThemeData::clearCache();

        RequestContext::setConnectionId('conn-theme-a');
        self::assertSame('frontend', ThemeData::getCurrentArea());
    }

    public function testArrayParamValuesDecodeStoredJsonStrings(): void
    {
        self::assertSame([], ThemeData::normalizeParamValueForDefinition('[]', ['default' => []]));
        self::assertSame(
            [['label' => '保存']],
            ThemeData::normalizeParamValueForDefinition('[{"label":"保存"}]', ['type' => 'array', 'default' => []])
        );
        self::assertSame('not-json', ThemeData::normalizeParamValueForDefinition('not-json', ['default' => []]));
    }
}
