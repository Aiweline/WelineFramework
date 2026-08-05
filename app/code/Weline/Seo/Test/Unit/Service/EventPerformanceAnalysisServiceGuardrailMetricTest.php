<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Ai\Api\AiModel;
use Weline\Ai\Api\AiRuntimeInterface;
use Weline\Ai\Api\Configuration\ProviderAvailability;
use Weline\Ai\Api\Configuration\ScenarioConfigurationInterface;
use Weline\Ai\Api\Configuration\ScenarioRecord;
use Weline\Seo\Service\EventPerformanceAnalysisService;

final class EventPerformanceAnalysisServiceGuardrailMetricTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function observableGuardrailProvider(): iterable
    {
        foreach ([
            'organic_ctr',
            'hero_cta_click_rate',
            'pricing_cta_click_rate',
            'lead_submit_rate',
            'signup_click_rate',
            'contact_click_rate',
            'download_click_rate',
            'booking_click_rate',
            'demo_request_click_rate',
            'add_to_cart_rate',
            'buy_now_rate',
            'begin_checkout_rate',
            'route_click_rate',
            'view_item_rate',
            'proof_badge_interaction_rate',
        ] as $metric) {
            yield $metric => [$metric];
        }
    }

    #[DataProvider('observableGuardrailProvider')]
    public function testAcceptsEveryServerObservableGuardrailMetric(string $guardrail): void
    {
        $primaryMetric = $guardrail === 'hero_cta_click_rate'
            ? 'lead_submit_rate'
            : 'hero_cta_click_rate';
        $recommendation = $this->recommendation([
            'primary_metric' => $primaryMetric,
            'guardrails' => [$guardrail],
        ]);

        $result = $this->service($recommendation)->recommend(
            $this->evidence(),
            $this->target($primaryMetric),
        );

        self::assertSame([$guardrail], $result['guardrails']);
        self::assertSame($primaryMetric, $result['primary_metric']);
    }

    public function testRejectsUnsupportedGuardrailMetric(): void
    {
        $service = $this->service($this->recommendation([
            'guardrails' => ['bounce_rate'],
        ]), 3);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('SEO analysis guardrail metric is invalid.');

        $service->recommend($this->evidence(), $this->target());
    }

    public function testRejectsMixedObservableAndUnsupportedGuardrailsFailClosed(): void
    {
        $service = $this->service($this->recommendation([
            'guardrails' => ['lead_submit_rate', 'bounce_rate'],
        ]), 3);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('SEO analysis guardrail metric is invalid.');

        $service->recommend($this->evidence(), $this->target());
    }

    public function testKeepsPrimaryMetricExactMatchRequirement(): void
    {
        $service = $this->service($this->recommendation([
            'primary_metric' => 'lead_submit_rate',
            'guardrails' => [],
        ]), 3);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('SEO analysis changed the primary metric.');

        $service->recommend($this->evidence(), $this->target('hero_cta_click_rate'));
    }

    public function testExcludesPrimaryMetricAndDeduplicatesNormalizedGuardrails(): void
    {
        $service = $this->service($this->recommendation([
            'guardrails' => [
                'hero_cta_click_rate',
                'LEAD_SUBMIT_RATE',
                'lead_submit_rate',
                '',
            ],
        ]));

        $result = $service->recommend($this->evidence(), $this->target());

        self::assertSame(['lead_submit_rate'], $result['guardrails']);
    }

    public function testRetriesMalformedJsonWithTheSameBoundModel(): void
    {
        $valid = (string)\json_encode(
            $this->recommendation(),
            \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );
        $service = $this->serviceFromResponses(['{"target":', $valid]);

        $result = $service->recommend($this->evidence(), $this->target());

        self::assertSame('increase_hero_cta_conversion', $result['objective']);
    }

    public function testRetriesTooManyGuardrailsAndKeepsTheStrictLimit(): void
    {
        $tooMany = $this->recommendation([
            'guardrails' => [
                'organic_ctr',
                'lead_submit_rate',
                'signup_click_rate',
                'contact_click_rate',
                'download_click_rate',
                'booking_click_rate',
            ],
        ]);
        $valid = $this->recommendation([
            'guardrails' => ['lead_submit_rate', 'signup_click_rate'],
        ]);
        $service = $this->serviceFromResponses([
            (string)\json_encode($tooMany, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
            (string)\json_encode($valid, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        ]);

        $result = $service->recommend($this->evidence(), $this->target());

        self::assertSame(['lead_submit_rate', 'signup_click_rate'], $result['guardrails']);
    }

    public function testFailsClosedAfterThreeSemanticContractFailures(): void
    {
        $service = $this->service($this->recommendation([
            'guardrails' => [
                'organic_ctr',
                'lead_submit_rate',
                'signup_click_rate',
                'contact_click_rate',
                'download_click_rate',
                'booking_click_rate',
            ],
        ]), 3);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('SEO analysis returned too many guardrails.');

        $service->recommend($this->evidence(), $this->target());
    }

    /** @param array<string,mixed> $recommendation */
    private function service(array $recommendation, int $responseCount = 1): EventPerformanceAnalysisService
    {
        $response = (string)\json_encode(
            $recommendation,
            \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );

        return $this->serviceFromResponses(\array_fill(0, $responseCount, $response));
    }

    /** @param list<string> $responses */
    private function serviceFromResponses(array $responses): EventPerformanceAnalysisService
    {
        $runtime = $this->createMock(AiRuntimeInterface::class);
        $runtime->expects(self::exactly(\count($responses)))
            ->method('generate')
            ->willReturnOnConsecutiveCalls(...$responses);

        $configuration = $this->createMock(ScenarioConfigurationInterface::class);
        $configuration->method('scenario')->willReturn(new ScenarioRecord(
            1,
            EventPerformanceAnalysisService::SCENARIO_CODE,
            'SEO event performance',
            '1.0.0',
            true,
            'test-model',
            [AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT => 'test-model'],
        ));
        $configuration->method('model')->willReturn(AiModel::fromArray([
            'id' => 1,
            'model_code' => 'test-model',
            'primary_modality' => AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT,
            'is_active' => true,
        ]));
        $configuration->method('providerAvailability')
            ->willReturn(new ProviderAvailability('test-provider', true));

        return new EventPerformanceAnalysisService($runtime, $configuration);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function recommendation(array $overrides = []): array
    {
        return \array_replace([
            'target' => ['page_type' => 'home_page', 'block_key' => 'hero'],
            'objective' => 'increase_hero_cta_conversion',
            'allowed_paths' => ['fields.content.title'],
            'instruction' => 'Clarify the primary action while preserving factual claims.',
            'primary_metric' => 'hero_cta_click_rate',
            'guardrails' => ['lead_submit_rate'],
            'confidence' => 0.9,
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        return [
            'owner' => [
                'current_values' => [
                    'fields.content.title' => 'Play with confidence',
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function target(string $primaryMetric = 'hero_cta_click_rate'): array
    {
        return [
            'page_type' => 'home_page',
            'block_key' => 'hero',
            'allowed_paths' => ['fields.content.title'],
            'primary_metric' => $primaryMetric,
            'current_values' => [
                'fields.content.title' => 'Play with confidence',
            ],
        ];
    }
}
