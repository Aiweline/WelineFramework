<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Weline\Backend\Api\Runtime\RequestResetter;
use Weline\Backend\Block\ThemeConfig;
use Weline\Backend\Service\BackendWarmupContext;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestResetException;

final class RequestResetterFailureAggregationTest extends TestCase
{
    private ?object $originalThemeConfig = null;

    protected function setUp(): void
    {
        Context::leave();
        Context::enter(new Context());
        $this->originalThemeConfig = ObjectManager::_getInstance(ThemeConfig::class);
    }

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(ThemeConfig::class);
        if ($this->originalThemeConfig !== null) {
            ObjectManager::setInstance(ThemeConfig::class, $this->originalThemeConfig);
        }
        Context::leave();
        parent::tearDown();
    }

    public function testThemeConfigRemovalFailureStillClearsWarmupContext(): void
    {
        RequestContext::set(BackendWarmupContext::USER_CONTEXT_KEY, new \stdClass());
        RequestContext::set(BackendWarmupContext::USER_ID_CONTEXT_KEY, 42);
        RequestContext::set(BackendWarmupContext::AUTH_CONTEXT_KEY, 'warmup-auth');
        $themeConfig = new \stdClass();
        ObjectManager::setInstance(ThemeConfig::class, $themeConfig);

        $parsedClasses = new ReflectionProperty(ObjectManager::class, 'parsedClasses');
        $original = $parsedClasses->getValue();
        $faulted = $original;
        $faulted[ThemeConfig::class] = [];
        $parsedClasses->setValue(null, $faulted);

        try {
            try {
                (new RequestResetter())->resetRequest();
                self::fail('Expected the ThemeConfig instance reset failure to be aggregated.');
            } catch (RequestResetException $exception) {
                self::assertSame('backend_request_resetter', $exception->boundary());
                self::assertSame(['theme_config_instance'], $exception->stages());
            }
        } finally {
            $parsedClasses->setValue(null, $original);
        }

        self::assertFalse(RequestContext::has(BackendWarmupContext::USER_CONTEXT_KEY));
        self::assertFalse(RequestContext::has(BackendWarmupContext::USER_ID_CONTEXT_KEY));
        self::assertFalse(RequestContext::has(BackendWarmupContext::AUTH_CONTEXT_KEY));
        self::assertNull(ObjectManager::_getInstance(ThemeConfig::class));
    }
}
