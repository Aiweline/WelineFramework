<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

final class EventConfigTest extends TestCase
{
    public function testParserWordsRegisterIsNotBoundToRouteAfter(): void
    {
        /** @var array<string, mixed> $generated */
        $generated = include BP . 'generated/events.php';
        $routeAfterObservers = $generated['events']['Weline_Framework_Router::route_after']['observers'] ?? [];

        self::assertIsArray($routeAfterObservers);
        foreach ($routeAfterObservers as $observer) {
            self::assertNotSame(
                'Weline\\I18n\\Observer\\ParserWordsRegister',
                (string)($observer['instance'] ?? ''),
                'ParserWordsRegister should not run on the request route_after path.'
            );
        }
    }

    public function testSetupUpgradeStillCollectsTranslations(): void
    {
        /** @var array<string, mixed> $generated */
        $generated = include BP . 'generated/events.php';
        $upgradeObservers = $generated['events']['Weline_Framework_Setup::upgrade_after']['observers'] ?? [];

        $found = false;
        foreach ($upgradeObservers as $observer) {
            if ((string)($observer['instance'] ?? '') === 'Weline\\I18n\\Observer\\SetupUpgradeCollectTranslations') {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Setup upgrade should still trigger translation collection.');
    }
}
