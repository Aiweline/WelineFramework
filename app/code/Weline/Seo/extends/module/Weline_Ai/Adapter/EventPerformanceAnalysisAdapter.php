<?php

declare(strict_types=1);

namespace Weline\Seo\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Interface\AdapterModelBindingInterface;
use Weline\Ai\Interface\ScenarioAdapterInterface;
use Weline\Seo\Service\EventPerformanceAnalysisService;

final class EventPerformanceAnalysisAdapter implements ScenarioAdapterInterface, AdapterModelBindingInterface
{
    public function getDefaultModelBindings(): array { return ['text2text' => 'deepseek-v4-flash']; }
    public function getCode(): string { return 'seo_event_performance_analysis'; }
    public function getName(): string { return 'SEO Event Performance Analysis'; }
    public function getDescription(): string { return 'Converts aggregated Visitor/Search evidence into one bounded structured recommendation.'; }
    public function getVersion(): string { return '1.0.2'; }
    public function getSupportedModelTypes(): array { return ['text2text']; }

    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $observableGuardrails = \implode(
            ', ',
            EventPerformanceAnalysisService::OBSERVABLE_GUARDRAIL_METRICS
        );

        return \trim($prompt) . "\n\nHARD CONTRACT:\n"
            . "Return one JSON object only with exactly: target, objective, allowed_paths, instruction, primary_metric, guardrails, confidence.\n"
            . "target has page_type and block_key only. Copy allowed_paths only from target_snapshot.allowed_paths.\n"
            . "objective must be lowercase snake_case matching ^[a-z][a-z0-9_]{2,127}$. Copy primary_metric exactly from target_snapshot.primary_metric.\n"
            . "guardrails must contain at most 5 unique entries, may contain only these server-observable metrics: {$observableGuardrails}, and must exclude primary_metric. confidence is a JSON number between 0 and 1.\n"
            . "Calibrate confidence from evidence quality, sample sufficiency, and observed signal; do not use a generic conservative default. Define material positive movement as at least a 20% relative rise in the target_snapshot.primary_metric between the current and comparison evidence. When matching_owner is empty and that definition plus the eligibility/no-reason conditions all hold, return confidence from 0.80 to 1.00; otherwise return confidence below 0.80.\n"
            . "When aggregated_evidence.search_queries.matching_owner is non-empty, treat query heat as sufficient signal: return confidence from 0.40 to 1.00, and use 0.80+ when heat>=50 or impressions>=500. Prefer rewriting copy that already contains those query terms. Use heat, clicks, impressions, and average_position to decide which term to reinforce. instruction must mention the hottest matched query when matching_owner is non-empty.\n"
            . "When aggregated_evidence.visitor_events show a weak CTA or conversion relative to page_views, tighten that block's CTA copy.\n"
            . "Do not return page bodies, full Owners, HTML, CSS, JavaScript, URLs, routes, canonical values, prices, legal text, raw events, personal data, or plan_json.\n"
            . "Use aggregated evidence only. No prose and no markdown fences.";
    }

    public function processResponse(string $response, array $params = []): string
    {
        $payload = \trim($response);
        if (\preg_match('/\A```(?:json)?\s*(\{.*\})\s*```\z/is', $payload, $match) === 1) {
            $payload = \trim((string)$match[1]);
        }
        $decoded = \json_decode($payload, true, 32, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || \array_is_list($decoded)) {
            throw new \UnexpectedValueException('Invalid SEO event analysis response.');
        }
        $keys = \array_keys($decoded);
        \sort($keys);
        $expected = ['allowed_paths', 'confidence', 'guardrails', 'instruction', 'objective', 'primary_metric', 'target'];
        if ($keys !== $expected) {
            throw new \UnexpectedValueException('Invalid SEO event analysis keys.');
        }
        return (string)\json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public function validateParams(array $params = []): array { return []; }
    public function getParamTemplate(): array { return ['aggregated_evidence' => [], 'target_snapshot' => []]; }
    public function getExamples(): array { return [['expected_output' => '{"target":{"page_type":"home_page","block_key":"hero"},"objective":"increase_hero_cta_conversion","allowed_paths":["seo.heading_text"],"instruction":"Clarify search intent","primary_metric":"hero_cta_click_rate","guardrails":["lead_submit_rate"],"confidence":0.82}']]; }
    public function supportsModel(string $modelCode): bool { return \trim($modelCode) !== ''; }
}
