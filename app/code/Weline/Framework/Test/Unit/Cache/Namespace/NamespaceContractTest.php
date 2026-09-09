<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache\Namespace;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Namespace\NamespaceGenerationSnapshot;
use Weline\Framework\Cache\Namespace\NamespaceKeyDecorator;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;

/** Plan coverage: CACHE01, CACHE02, CACHE03, FPC02. */
final class NamespaceContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setId('namespace-contract-request');
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
    }

    public function testCache01CanonicalPathExpandsParentsWithoutGlobalWebsiteRoot(): void
    {
        $paths = new NamespacePath();
        $leaf = $paths->website('default', ['catalog', 'product 7']);

        self::assertSame('website/default/catalog/product%207', $leaf);
        self::assertSame(
            [
                'website/default',
                'website/default/catalog',
                'website/default/catalog/product%207',
            ],
            $paths->ancestors($leaf),
        );
        self::assertSame(
            [
                'global/websites-registry',
                'website/default',
                'website/default/catalog',
                'website/default/catalog/product%207',
            ],
            $paths->expandAncestors([$leaf, 'global/websites-registry']),
        );
        self::assertNotContains('website', $paths->ancestors($leaf));
    }

    public function testCache01RejectsAmbiguousOrNonCanonicalPaths(): void
    {
        $paths = new NamespacePath();

        foreach (
            [
                '',
                '@clock',
                'website',
                '/website/default',
                'website//default',
                'website/default/',
                'catalog/default',
                'website/../catalog',
                'website/default/product%2f7',
            ] as $invalid
        ) {
            try {
                $paths->canonicalize($invalid);
                self::fail('Invalid namespace path must be rejected: ' . $invalid);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true, $invalid);
            }
        }
    }

    public function testCache02FingerprintIsOrderIndependentAndScopesKeysAndTags(): void
    {
        $decorator = new NamespaceKeyDecorator();
        $vector = [
            'website/default/catalog' => 4,
            'website/default' => 2,
        ];
        $reversed = array_reverse($vector, true);
        $fingerprint = $decorator->fingerprint($vector);

        self::assertSame($fingerprint, $decorator->fingerprint($reversed));
        self::assertNotSame(
            $fingerprint,
            $decorator->fingerprint([
                'website/default/catalog' => 5,
                'website/default' => 2,
            ]),
        );

        $key = $decorator->decorate('product/7', $fingerprint);
        $tag = $decorator->decorateTag('catalog', $fingerprint);
        self::assertSame('product/7', $decorator->undecorate($key, $fingerprint));
        self::assertSame('catalog', $decorator->undecorateTag($tag, $fingerprint));
        self::assertNull($decorator->undecorate($key, str_repeat('a', 64)));
    }

    public function testCache03RequestSnapshotFreezesVectorAndReusesReconciledProcessState(): void
    {
        $snapshot = new NamespaceGenerationSnapshot();
        $ancestors = ['website/default', 'website/default/catalog'];
        $calls = [];
        $loader = static function (array $namespaces) use (&$calls): array {
            $calls[] = $namespaces;
            if ($namespaces === [NamespacePath::AUTHORITY_CLOCK]) {
                return [NamespacePath::AUTHORITY_CLOCK => 7];
            }
            return [
                'website/default' => 2,
                'website/default/catalog' => 4,
            ];
        };

        self::assertSame(
            [
                'authority_clock' => 7,
                'generations' => [
                    'website/default' => 2,
                    'website/default/catalog' => 4,
                ],
            ],
            $snapshot->resolve($ancestors, $loader),
        );
        self::assertSame(
            [[NamespacePath::AUTHORITY_CLOCK], $ancestors],
            $calls,
        );

        $snapshot->resolve($ancestors, static function (): array {
            self::fail('Frozen request vector must not return to storage.');
        });

        $snapshot->beginRequest();
        $newRequestCalls = [];
        $resolved = $snapshot->resolve(
            $ancestors,
            static function (array $namespaces) use (&$newRequestCalls): array {
                $newRequestCalls[] = $namespaces;
                return [NamespacePath::AUTHORITY_CLOCK => 7];
            },
        );
        self::assertSame([[NamespacePath::AUTHORITY_CLOCK]], $newRequestCalls);
        self::assertSame(4, $resolved['generations']['website/default/catalog']);
    }

    public function testCache03ClockJumpDropsUnknownProcessVectorMembers(): void
    {
        $snapshot = new NamespaceGenerationSnapshot();
        $snapshot->replaceProcessSnapshot(3, [
            'website/default' => 2,
            'website/other' => 9,
        ]);

        $snapshot->advance(5, ['website/default' => 4]);

        self::assertSame(
            [
                'authority_clock' => 5,
                'generations' => ['website/default' => 4],
            ],
            $snapshot->processSnapshot(),
        );
    }

    public function testMissingLoadDoesNotOverwriteConcurrentInvalidation(): void
    {
        $snapshot = new NamespaceGenerationSnapshot();
        $snapshot->replaceProcessSnapshot(1, ['website/default' => 1]);
        $snapshot->resolve(['website/default'], static fn(): array => [NamespacePath::AUTHORITY_CLOCK => 1]);

        $resolved = $snapshot->resolve(['website/default/catalog'], static function () use ($snapshot): array {
            $writer = new \Fiber(static function () use ($snapshot): void {
                Context::enter(new Context());
                try {
                    RequestContext::setId('namespace-concurrent-writer');
                    $snapshot->advance(2, ['website/default/catalog' => 9]);
                } finally {
                    Context::leave();
                }
            });
            $writer->start();

            return ['website/default/catalog' => 1];
        });

        self::assertSame(1, $resolved['authority_clock']);
        self::assertSame(1, $resolved['generations']['website/default/catalog']);
        self::assertSame(2, $snapshot->processSnapshot()['authority_clock']);
        self::assertSame(9, $snapshot->processSnapshot()['generations']['website/default/catalog']);
    }

    public function testMissingLoadPreservesOtherNamespacesFilledDuringYield(): void
    {
        $snapshot = new NamespaceGenerationSnapshot();
        $snapshot->replaceProcessSnapshot(1, ['website/default' => 1]);
        $snapshot->resolve(['website/default'], static fn(): array => [NamespacePath::AUTHORITY_CLOCK => 1]);

        $snapshot->resolve(['website/default/catalog'], static function () use ($snapshot): array {
            $reader = new \Fiber(static function () use ($snapshot): void {
                Context::enter(new Context());
                try {
                    RequestContext::setId('namespace-concurrent-reader');
                    $snapshot->resolve(['website/default/price'], static fn(array $keys): array =>
                        $keys === [NamespacePath::AUTHORITY_CLOCK]
                            ? [NamespacePath::AUTHORITY_CLOCK => 1]
                            : ['website/default/price' => 4]);
                } finally {
                    Context::leave();
                }
            });
            $reader->start();

            return ['website/default/catalog' => 2];
        });

        self::assertSame([
            'website/default' => 1,
            'website/default/catalog' => 2,
            'website/default/price' => 4,
        ], $snapshot->processSnapshot()['generations']);
    }

    public function testSameClockMissingNamespacesReuseProcessThenBatchOnlyRemainder(): void
    {
        $snapshot = new NamespaceGenerationSnapshot();
        $snapshot->replaceProcessSnapshot(7, [
            'website/default' => 2,
            'website/default/catalog' => 4,
        ]);

        $calls = [];
        $loader = static function (array $namespaces) use (&$calls): array {
            $calls[] = $namespaces;
            if ($namespaces === [NamespacePath::AUTHORITY_CLOCK]) {
                return [NamespacePath::AUTHORITY_CLOCK => 7];
            }

            $out = [];
            foreach ($namespaces as $namespace) {
                $out[$namespace] = $namespace === 'website/default/price' ? 9 : 0;
            }

            return $out;
        };

        $first = $snapshot->resolve(['website/default'], $loader);
        self::assertSame(2, $first['generations']['website/default']);
        self::assertSame([[NamespacePath::AUTHORITY_CLOCK]], $calls);

        $second = $snapshot->resolve(
            ['website/default', 'website/default/catalog', 'website/default/price'],
            $loader,
        );
        self::assertSame(
            [
                'website/default' => 2,
                'website/default/catalog' => 4,
                'website/default/price' => 9,
            ],
            $second['generations'],
        );
        self::assertSame(
            [[NamespacePath::AUTHORITY_CLOCK], ['website/default/price']],
            $calls,
        );
        self::assertSame(9, $snapshot->processSnapshot()['generations']['website/default/price']);
    }
}
