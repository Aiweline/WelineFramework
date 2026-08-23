<?php

declare(strict_types=1);

namespace {
    if (!function_exists('__')) {
        function __(string $text, array $arguments = []): string
        {
            foreach (array_values($arguments) as $index => $argument) {
                $text = str_replace('%{' . ($index + 1) . '}', (string)$argument, $text);
            }
            return $text;
        }
    }
}

namespace Weline\Review\Test\Unit\Service {

use PHPUnit\Framework\TestCase;
use Weline\Review\Service\ProductReviewTypeProvider;

final class ProductReviewTypeProviderTest extends TestCase
{
    public function testDefaultSchemaPublishesProductRatingsImageAndVideoFields(): void
    {
        $fields = (new ProductReviewTypeProvider())->fields();
        $types = array_column($fields, 'type');
        $ratingKeys = array_column(array_values(array_filter(
            $fields,
            static fn(array $field): bool => ($field['type'] ?? '') === 'rating'
        )), 'key');

        self::assertSame(['rating', 'quality_rating', 'delivery_rating', 'service_rating'], $ratingKeys);
        self::assertContains('image', $types);
        self::assertContains('video', $types);
    }

    public function testProductRatingsAreValidatedAndPersistedAsExtraValues(): void
    {
        $normalized = (new ProductReviewTypeProvider())->normalizeValues([
            'rating' => 5,
            'quality_rating' => 4,
            'delivery_rating' => 3,
            'service_rating' => 2,
            'content' => 'This review content is long enough.',
        ], null);

        self::assertSame(5, $normalized['rating']);
        self::assertSame([
            'quality_rating' => 4,
            'delivery_rating' => 3,
            'service_rating' => 2,
        ], $normalized['extra']);
    }

    public function testServerValidationRejectsMissingExtensionRating(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProductReviewTypeProvider())->normalizeValues([
            'rating' => 5,
            'quality_rating' => 5,
            'delivery_rating' => 5,
            'content' => 'This review content is long enough.',
        ], null);
    }

    public function testServerValidationRejectsOutOfRangeExtensionRating(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProductReviewTypeProvider())->normalizeValues([
            'rating' => 5,
            'quality_rating' => 6,
            'delivery_rating' => 5,
            'service_rating' => 5,
            'content' => 'This review content is long enough.',
        ], null);
    }

    public function testReviewListUsesPaginationTotalWithMappedItemFallback(): void
    {
        $service = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ReviewService.php');

        self::assertStringContainsString('$pagination = $review->getPaginationState();', $service);
        self::assertStringContainsString('$pagination[\'totalSize\'] ?? count($items)', $service);
        self::assertStringContainsString("'extra' => \$extra", $service);
        self::assertStringNotContainsString('getTotalCount()', $service);
    }

    public function testDefaultTemplateBuildsRatingFieldsFromProviderSchemaAsNativeStars(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/hooks/Weline_Review/frontend/layouts/product-reviews/content.phtml'
        );

        self::assertStringContainsString('schemaFields.map(fieldInput)', $template);
        self::assertStringContainsString("if(field.type==='rating')", $template);
        self::assertStringContainsString("input.type='radio'", $template);
        self::assertStringContainsString('input.name=field.key', $template);
        self::assertStringContainsString("role','radiogroup'", $template);
        self::assertStringContainsString("choice.addEventListener('keydown'", $template);
        self::assertStringContainsString('请选择所有必填评分。', $template);
        self::assertStringContainsString("field.type==='rating'&&field.key!=='rating'", $template);
        self::assertStringNotContainsString("field.type==='rating'){input=make('select')", $template);
    }

    public function testServerValidationRejectsShortContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProductReviewTypeProvider())->normalizeValues([
            'rating' => 5,
            'quality_rating' => 5,
            'delivery_rating' => 5,
            'service_rating' => 5,
            'content' => 'short',
        ], null);
    }
}
}
