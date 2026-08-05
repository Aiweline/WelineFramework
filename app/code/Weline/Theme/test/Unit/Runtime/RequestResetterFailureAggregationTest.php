<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestResetException;
use Weline\Theme\Api\Runtime\RequestResetter;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Taglib\Slot;

final class RequestResetterFailureAggregationTest extends TestCase
{
    private const PREVIEW_STATE_KEY = 'theme.preview_token.state.v1';

    private const REGISTERED_SLOTS_KEY = 'theme.taglib.registered_slots.v1';

    private ?object $originalSlotRenderer = null;

    protected function setUp(): void
    {
        Context::leave();
        $this->originalSlotRenderer = ObjectManager::_getInstance(SlotRendererService::class);
    }

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(SlotRendererService::class);
        if ($this->originalSlotRenderer !== null) {
            ObjectManager::setInstance(SlotRendererService::class, $this->originalSlotRenderer);
        }
        Context::leave();
        parent::tearDown();
    }

    public function testPreviewFailureStillClearsRegisteredSlots(): void
    {
        $slotRenderer = new \stdClass();
        ObjectManager::setInstance(SlotRendererService::class, $slotRenderer);
        Context::enter(new ThemeRequestResetFaultContext(
            [
                'runtime' => [
                    'request_context' => [
                        'storage' => [
                            self::PREVIEW_STATE_KEY => [
                                'detected' => true,
                                'preview_data' => ['token' => 'preview-a'],
                            ],
                            self::REGISTERED_SLOTS_KEY => [
                                'slot-a' => 'theme-a.phtml:1',
                            ],
                        ],
                    ],
                ],
            ],
            self::PREVIEW_STATE_KEY,
        ));

        try {
            (new RequestResetter())->resetRequest();
            self::fail('Expected the preview token reset failure to be aggregated.');
        } catch (RequestResetException $exception) {
            self::assertSame('theme_request_resetter', $exception->boundary());
            self::assertSame(['preview_token'], $exception->stages());
        }

        self::assertTrue(RequestContext::has(self::PREVIEW_STATE_KEY));
        self::assertSame([], Slot::getRegisteredSlots());
        self::assertNull(ObjectManager::_getInstance(SlotRendererService::class));
    }
}

final class ThemeRequestResetFaultContext extends Context
{
    private bool $failureRaised = false;

    public function __construct(array $data, private readonly string $failWhenRemovedKey)
    {
        parent::__construct($data);
    }

    public function set(string $path, mixed $value): void
    {
        if (
            !$this->failureRaised
            && $path === 'runtime.request_context.storage'
            && is_array($value)
            && !array_key_exists($this->failWhenRemovedKey, $value)
        ) {
            $this->failureRaised = true;
            throw new \RuntimeException('theme-preview-state-reset-failure');
        }

        parent::set($path, $value);
    }
}
