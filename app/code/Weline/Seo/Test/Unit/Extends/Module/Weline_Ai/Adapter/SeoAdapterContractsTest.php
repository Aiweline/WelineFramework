<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Extends\Module\Weline_Ai\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Extends\Module\Weline_Ai\Adapter\EventPerformanceAnalysisAdapter;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoAdapterContractsPass().
 */
final class SeoAdapterContractsTest extends TestCase
{
    public function testAllSeoAdapterContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'EventPerformanceAnalysisAdapterConfidence::testPromptRequiresHighConfidenceForMaterialEligibleEvidence' => function () {
            $this->assert_EventPerformanceAnalysisAdapterConfidence_testPromptRequiresHighConfidenceForMaterialEligibleEvidence();
        },
            'EventPerformanceAnalysisAdapterConfidence::testPromptRestrictsGuardrailsToServerObservableMetrics' => function () {
            $this->assert_EventPerformanceAnalysisAdapterConfidence_testPromptRestrictsGuardrailsToServerObservableMetrics();
        },
            'EventPerformanceAnalysisAdapterConfidence::testResponseAcceptsOnlyAnExactJsonMarkdownFenceWrapper' => function () {
            $this->assert_EventPerformanceAnalysisAdapterConfidence_testResponseAcceptsOnlyAnExactJsonMarkdownFenceWrapper();
        },
        ];
    }

private function assert_EventPerformanceAnalysisAdapterConfidence_testPromptRequiresHighConfidenceForMaterialEligibleEvidence(): void
{
    $prompt = (new EventPerformanceAnalysisAdapter())->adaptPrompt('INPUT: {}');
    self::assertStringContainsString('Calibrate confidence from evidence quality, sample sufficiency, and observed signal', $prompt);
    self::assertStringContainsString('Define material positive movement as at least a 20% relative rise in the target_snapshot.primary_metric between the current and comparison evidence.', $prompt);
    self::assertStringContainsString('When matching_owner is empty and that definition plus the eligibility/no-reason conditions all hold, return confidence from 0.80 to 1.00; otherwise return confidence below 0.80.', $prompt);
    self::assertStringContainsString('treat query heat as sufficient signal', $prompt);
    self::assertStringContainsString('aggregated_evidence.search_queries.matching_owner', $prompt);
    self::assertStringContainsString('hottest matched query', $prompt);
    self::assertStringContainsString('visitor_events', $prompt);
}

private function assert_EventPerformanceAnalysisAdapterConfidence_testPromptRestrictsGuardrailsToServerObservableMetrics(): void
{
    $prompt = (new EventPerformanceAnalysisAdapter())->adaptPrompt('INPUT: {}');
    self::assertStringContainsString('guardrails must contain at most 5 unique entries', $prompt);
    self::assertStringContainsString('may contain only these server-observable metrics', $prompt);
    foreach (['organic_ctr', 'hero_cta_click_rate', 'pricing_cta_click_rate', 'lead_submit_rate', 'signup_click_rate', 'contact_click_rate', 'download_click_rate', 'booking_click_rate', 'demo_request_click_rate', 'add_to_cart_rate', 'buy_now_rate', 'begin_checkout_rate', 'route_click_rate', 'view_item_rate', 'proof_badge_interaction_rate'] as $metric) {
        self::assertStringContainsString($metric, $prompt);
    }
}

private function assert_EventPerformanceAnalysisAdapterConfidence_testResponseAcceptsOnlyAnExactJsonMarkdownFenceWrapper(): void
{
    $adapter = new EventPerformanceAnalysisAdapter();
    $json = (string) \json_encode(['target' => ['page_type' => 'home_page', 'block_key' => 'hero'], 'objective' => 'increase_hero_cta_conversion', 'allowed_paths' => ['fields.content.title'], 'instruction' => 'Clarify the primary action.', 'primary_metric' => 'hero_cta_click_rate', 'guardrails' => ['lead_submit_rate'], 'confidence' => 0.9], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    self::assertSame($json, $adapter->processResponse("```json\n{$json}\n```"));
    $this->expectException(\JsonException::class);
    $adapter->processResponse("Here is the result:\n{$json}");
}
}
