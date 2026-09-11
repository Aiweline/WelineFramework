<?php

declare(strict_types=1);

namespace Weline\Faq\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Faq\Api\FaqPageProviderInterface;
use Weline\Faq\Service\FaqPageProviderRegistry;

final class FaqPageProviderRegistryTest extends TestCase
{
    public function testFirstRegistrationWinsAndEnabledSorted(): void
    {
        $first = $this->provider('b2b-wholesale', 'b2b-wholesale', 20, true);
        $dup = $this->provider('b2b-wholesale', 'b2b-wholesale', 1, true);
        $other = $this->provider('demo-page', 'demo-page', 10, true);
        $disabled = $this->provider('off-page', 'off-page', 5, false);

        $reg = FaqPageProviderRegistry::forTesting($first, $dup, $other, $disabled);
        self::assertSame($first, $reg->findBySlug('b2b-wholesale'));
        self::assertNull($reg->findBySlug('off-page'));

        $enabled = $reg->enabledPages();
        self::assertCount(2, $enabled);
        self::assertSame('demo-page', $enabled[0]->pageCode());
        self::assertSame('b2b-wholesale', $enabled[1]->pageCode());
    }

    public function testExtendsSlotDeclared(): void
    {
        $extends = include dirname(__DIR__, 3) . '/extends.php';
        self::assertArrayHasKey('FaqPageProvider', $extends['extends'] ?? []);
        self::assertSame(
            'Weline\Faq\Api\FaqPageProviderInterface',
            $extends['extends']['FaqPageProvider']['interface'] ?? null,
        );
    }

    private function provider(string $code, string $slug, int $sort, bool $enabled): FaqPageProviderInterface
    {
        return new class ($code, $slug, $sort, $enabled) implements FaqPageProviderInterface {
            public function __construct(
                private readonly string $code,
                private readonly string $slug,
                private readonly int $sort,
                private readonly bool $enabled,
            ) {
            }

            public function pageCode(): string
            {
                return $this->code;
            }

            public function slug(): string
            {
                return $this->slug;
            }

            public function title(): string
            {
                return $this->code;
            }

            public function summary(): string
            {
                return 'summary';
            }

            public function sortOrder(): int
            {
                return $this->sort;
            }

            public function isEnabled(): bool
            {
                return $this->enabled;
            }

            public function template(): string
            {
                return 'Weline_Faq::templates/frontend/view.phtml';
            }

            public function group(): string
            {
                return 'test';
            }
        };
    }
}
