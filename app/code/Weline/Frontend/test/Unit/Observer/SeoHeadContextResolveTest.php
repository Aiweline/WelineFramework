<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\View\Head\HeadContextProviderInterface;
use Weline\Frontend\Observer\SeoHeadContextResolve;
use Weline\Frontend\Service\Head\HeadProviderRegistry;

final class SeoHeadContextResolveTest extends TestCase
{
    public function testProvidersComposeWithExistingContextAndFailuresAreIsolated(): void
    {
        $template = new \stdClass();
        $calls = [];

        $providers = [
            new CallbackHeadContextProvider(
                function ($actualTemplate, array $context) use ($template, &$calls): array {
                    self::assertSame($template, $actualTemplate);
                    self::assertSame(7, $context['website_id']);
                    self::assertSame('Hanfu Atelier', $context['site_name']);
                    $calls[] = 'international';

                    return [
                        'canonical_url' => 'https://shop.example.test/en_US/products',
                        'alternates' => [
                            'en_US' => 'https://shop.example.test/en_US/products',
                            'x-default' => 'https://shop.example.test/products',
                        ],
                        'organization' => ['name' => 'Hanfu Atelier'],
                    ];
                },
            ),
            new CallbackHeadContextProvider(
                static function (): array {
                    throw new \RuntimeException('A broken extension must not hide healthy providers.');
                },
            ),
            new CallbackHeadContextProvider(
                function ($actualTemplate, array $context) use ($template, &$calls): array {
                    self::assertSame($template, $actualTemplate);
                    self::assertSame(
                        'https://shop.example.test/en_US/products',
                        $context['canonical_url'],
                    );
                    self::assertSame(
                        'https://shop.example.test/products',
                        $context['alternates']['x-default'],
                    );
                    $calls[] = 'following-provider';

                    return [
                        'alternates' => [
                            'zh_Hans_CN' => 'https://shop.example.test/products',
                        ],
                        'robots' => 'index,follow',
                    ];
                },
            ),
        ];

        $event = new Event([
            'data' => new DataObject([
                'template' => $template,
                'context' => [
                    'website_id' => 7,
                    'locale' => 'en_US',
                ],
                'head_context' => [
                    'site_name' => 'Hanfu Atelier',
                    'organization' => [
                        'url' => 'https://shop.example.test',
                    ],
                ],
            ]),
        ]);

        (new SeoHeadContextResolve(new TestHeadProviderRegistry($providers)))->execute($event);

        $resolved = $event->getData('head_context');
        self::assertIsArray($resolved);
        self::assertSame(['international', 'following-provider'], $calls);
        self::assertSame('Hanfu Atelier', $resolved['site_name']);
        self::assertSame('https://shop.example.test', $resolved['organization']['url']);
        self::assertSame('Hanfu Atelier', $resolved['organization']['name']);
        self::assertSame(
            'https://shop.example.test/en_US/products',
            $resolved['alternates']['en_US'],
        );
        self::assertSame(
            'https://shop.example.test/products',
            $resolved['alternates']['x-default'],
        );
        self::assertSame(
            'https://shop.example.test/products',
            $resolved['alternates']['zh_Hans_CN'],
        );
        self::assertSame('index,follow', $resolved['robots']);
    }
}

/**
 * @internal Test double with no ObjectManager dependency.
 */
final class TestHeadProviderRegistry extends HeadProviderRegistry
{
    /**
     * @param HeadContextProviderInterface[] $providers
     */
    public function __construct(
        private readonly array $providers,
    ) {
    }

    /**
     * @return HeadContextProviderInterface[]
     */
    public function getContextProviders(bool $forceReload = false): array
    {
        return $this->providers;
    }
}

final class CallbackHeadContextProvider implements HeadContextProviderInterface
{
    public function __construct(
        private readonly \Closure $callback,
    ) {
    }

    public function provide($template, array $context): array
    {
        return ($this->callback)($template, $context);
    }
}
