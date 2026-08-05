<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit;

use Fiber;
use PHPUnit\Framework\TestCase;
use Weline\DataTable\Helper\TableContext;
use Weline\DataTable\Taglib\Field;
use Weline\Framework\Context;

final class TableContextRequestIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        Context::leave();
        Context::enter(new Context());
        TableContext::clearAll();
    }

    protected function tearDown(): void
    {
        TableContext::clearAll();
        Context::leave();
        parent::tearDown();
    }

    public function testPeerFiberResetDoesNotClearTableRenderOrFieldState(): void
    {
        $observed = [];

        $fiberA = new Fiber(function () use (&$observed): void {
            Context::enter(new Context());
            try {
                self::seed('a');
                Fiber::suspend('a-ready');

                $observed['a_before_reset'] = self::snapshot('a');
                Field::resetRequestState();
                $observed['a_after_reset'] = self::snapshot('a');
                Fiber::suspend('a-reset');
            } finally {
                Field::resetRequestState();
                Context::leave();
            }
        });

        $fiberB = new Fiber(function () use (&$observed): void {
            Context::enter(new Context());
            try {
                self::seed('b');
                Fiber::suspend('b-ready');

                $observed['b_after_a_reset'] = self::snapshot('b');
                Fiber::suspend('b-verified');
            } finally {
                Field::resetRequestState();
                Context::leave();
            }
        });

        self::assertSame('a-ready', $fiberA->start());
        self::assertSame('b-ready', $fiberB->start());
        self::assertSame('a-reset', $fiberA->resume());
        self::assertSame('b-verified', $fiberB->resume());

        self::assertSame(self::expectedSnapshot('a'), $observed['a_before_reset']);
        self::assertSame(self::emptySnapshot(), $observed['a_after_reset']);
        self::assertSame(self::expectedSnapshot('b'), $observed['b_after_a_reset']);

        $fiberA->resume();
        $fiberB->resume();
        self::assertTrue($fiberA->isTerminated());
        self::assertTrue($fiberB->isTerminated());
    }

    private static function seed(string $marker): void
    {
        $scope = 'scope-' . $marker;
        TableContext::setTableContext($scope, [
            'scope' => $scope,
            'marker' => $marker,
        ]);
        TableContext::pushChildTag('t-header', $scope . '-header', ['marker' => $marker]);
        TableContext::recordTemplateField($scope, 't-header', 'field-' . $marker, [
            'label' => $marker,
        ]);
    }

    /** @return array<string, mixed> */
    private static function snapshot(string $marker): array
    {
        $scope = 'scope-' . $marker;
        return [
            'contexts' => TableContext::getAllTableContexts(),
            'current' => TableContext::getCurrentTableContext(),
            'headers' => TableContext::getRenderStack('t-header'),
            'fields' => TableContext::getTemplateFields($scope, 't-header'),
        ];
    }

    /** @return array<string, mixed> */
    private static function expectedSnapshot(string $marker): array
    {
        $scope = 'scope-' . $marker;
        return [
            'contexts' => [
                $scope => [
                    'scope' => $scope,
                    'marker' => $marker,
                ],
            ],
            'current' => [
                'scope' => $scope,
                'marker' => $marker,
            ],
            'headers' => [
                'type' => 't-header',
                'scope' => $scope . '-header',
                'attributes' => ['marker' => $marker],
            ],
            'fields' => [
                'field-' . $marker => [
                    'name' => 'field-' . $marker,
                    'belong' => 't-header',
                    'template_defined' => true,
                    'visible' => true,
                    'searchable' => true,
                    'sortable' => false,
                    'editable' => false,
                    'label' => $marker,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function emptySnapshot(): array
    {
        return [
            'contexts' => [],
            'current' => null,
            'headers' => [],
            'fields' => [],
        ];
    }
}
