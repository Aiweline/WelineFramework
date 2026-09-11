<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Config\XmlReader;
use Weline\Framework\Event\EventRegistry;
use Weline\Framework\Event\EventScanner;

if (!defined('BP')) {
    define('BP', dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
}
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('CLI')) {
    define('CLI', true);
}
if (!defined('PROD')) {
    define('PROD', false);
}
require_once BP . 'app/autoload.php';
require_once BP . 'app/code/Weline/Framework/func_log.php';

final class EventRegistryIncrementalObserverPreservationTest extends TestCase
{
    public function testOwnerRefreshPreservesForeignObserversForExistingEventAndPattern(): void
    {
        $scanner = $this->createMock(EventScanner::class);
        $scanner->expects(self::once())
            ->method('scanModules')
            ->with(['Weline_Framework'])
            ->willReturn($this->ownerSpecs());

        $reader = $this->createMock(XmlReader::class);
        $reader->expects(self::once())
            ->method('readForModules')
            ->with(['Weline_Framework'])
            ->willReturn([]);

        $registry = new EventRegistryIncrementalFixture($scanner, $reader, $this->initialRegistry());

        self::assertTrue($registry->refreshForModules(['Weline_Framework']));

        $eventObservers = $registry->saved['events']['Weline_Framework_Test::owned']['observers'] ?? [];
        self::assertSame(
            ['foreign-fast', 'foreign-slow'],
            array_column($eventObservers, 'name'),
        );

        $patternObservers = $registry->saved['dynamic_patterns']['Weline_Framework_Test::{scope}']['observers'] ?? [];
        self::assertSame(['foreign-pattern'], array_column($patternObservers, 'name'));
    }

    public function testOwnerRefreshDoesNotRestoreObserversWhenEventWasRemoved(): void
    {
        $scanner = $this->createMock(EventScanner::class);
        $scanner->method('scanModules')->willReturn(['Weline_Framework' => []]);

        $reader = $this->createMock(XmlReader::class);
        $reader->method('readForModules')->willReturn([]);

        $registry = new EventRegistryIncrementalFixture($scanner, $reader, $this->initialRegistry());

        self::assertTrue($registry->refreshForModules(['Weline_Framework']));
        self::assertArrayNotHasKey('Weline_Framework_Test::owned', $registry->saved['events']);
        self::assertArrayNotHasKey(
            'Weline_Framework_Test::{scope}',
            $registry->saved['dynamic_patterns'],
        );
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function ownerSpecs(): array
    {
        return [
            'Weline_Framework' => [
                'Weline_Framework_Test::owned' => [
                    'name' => 'Owned event',
                    'has_spec' => true,
                    'has_doc' => true,
                ],
                'Weline_Framework_Test::{scope}' => [
                    'name' => 'Owned pattern',
                    'has_spec' => true,
                    'has_doc' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function initialRegistry(): array
    {
        return [
            'events' => [
                'Weline_Framework_Test::owned' => [
                    'module' => 'Weline_Framework',
                    'modules' => ['Weline_Framework' => ['module' => 'Weline_Framework']],
                    'observers' => [
                        $this->observer('Weline_Seo', 'foreign-slow', 20),
                        $this->observer('Weline_Event', 'foreign-fast', 10),
                        $this->observer('Weline_Framework', 'owner-observer', 0),
                    ],
                ],
            ],
            'event_to_module' => [
                'Weline_Framework_Test::owned' => 'Weline_Framework',
            ],
            'dynamic_patterns' => [
                'Weline_Framework_Test::{scope}' => [
                    'module' => 'Weline_Framework',
                    'pattern' => 'Weline_Framework_Test::{scope}',
                    'observers' => [
                        $this->observer('Weline_Event', 'foreign-pattern', 15),
                        $this->observer('Weline_Framework', 'owner-pattern', 0),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function observer(string $module, string $name, int $sort): array
    {
        return [
            'module' => $module,
            'module_status' => true,
            'instance' => self::class,
            'name' => $name,
            'observer_key' => $module . '::' . $name,
            'sort' => $sort,
            'disabled' => 'false',
        ];
    }
}

final class EventRegistryIncrementalFixture extends EventRegistry
{
    /** @var array<string, mixed> */
    public array $saved = [];

    /**
     * @param array<string, mixed> $initial
     */
    public function __construct(
        EventScanner $scanner,
        XmlReader $reader,
        private readonly array $initial,
    ) {
        parent::__construct($scanner, $reader);
    }

    public function getRegistry(bool $forceReload = false): array
    {
        return $this->initial;
    }

    public function saveRegistry(array $registry): bool
    {
        $this->saved = $registry;
        return true;
    }
}
