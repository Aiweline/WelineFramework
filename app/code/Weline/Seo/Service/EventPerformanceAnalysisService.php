<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Ai\Api\AiModel;
use Weline\Ai\Api\AiRuntimeInterface;
use Weline\Ai\Api\Configuration\ScenarioConfigurationInterface;

/** Strict fail-closed AI analysis. It never calls SuggestionService. */
final class EventPerformanceAnalysisService
{
    public const SCENARIO_CODE = 'seo_event_performance_analysis';
    private const MAX_RESPONSE_ATTEMPTS = 3;

    /**
     * Metrics that can be calculated from canonical server-side Search/Visitor evidence.
     *
     * @var list<string>
     */
    public const OBSERVABLE_GUARDRAIL_METRICS = [
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
    ];

    public function __construct(
        private readonly AiRuntimeInterface $aiRuntime,
        private readonly ScenarioConfigurationInterface $scenarioConfiguration,
    ) {
    }

    /** @param array<string,mixed> $evidence @param array<string,mixed> $target @return array<string,mixed> */
    public function recommend(array $evidence, array $target): array
    {
        $scenario = $this->scenarioConfiguration->scenario(self::SCENARIO_CODE, false);
        if ($scenario === null || !$scenario->active) {
            throw new \RuntimeException('SEO event performance scenario is unavailable.');
        }
        $modelCode = $scenario->getModelBinding(AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT) ?? \trim($scenario->defaultModel);
        $model = $this->scenarioConfiguration->model($modelCode, true, AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT);
        if ($modelCode === '' || $model === null || !$model->isActive()) {
            throw new \RuntimeException('SEO event performance model binding is unavailable.');
        }
        if (!$this->scenarioConfiguration->providerAvailability($modelCode)->available) {
            throw new \RuntimeException('SEO event performance provider is unavailable.');
        }
        $targetValues = \is_array($target['current_values'] ?? null) ? $target['current_values'] : [];
        $ownerValues = \is_array($evidence['owner']['current_values'] ?? null) ? $evidence['owner']['current_values'] : [];
        if ($this->containsSensitiveValue($targetValues) || $this->containsSensitiveValue($ownerValues)) {
            throw new \UnexpectedValueException('SEO analysis input contains sensitive data.');
        }

        $input = ['target_snapshot' => $target, 'aggregated_evidence' => $evidence];
        $prompt = "Analyze the aggregated SEO/CRO evidence and propose one bounded optimization.\nINPUT:\n"
            . \json_encode($input, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $recommendation = null;
        $contractHint = '';
        for ($attempt = 1; $attempt <= self::MAX_RESPONSE_ATTEMPTS; $attempt++) {
            $attemptPrompt = $prompt;
            if ($attempt > 1) {
                $attemptPrompt .= "\n\nRETRY CONTRACT CORRECTION:\n"
                    . 'The previous response failed strict machine validation. '
                    . 'Return one complete JSON object only; do not use markdown fences, prose, comments, or trailing text.'
                    . ($contractHint !== '' ? "\nCorrection required: {$contractHint}" : '');
            }
            try {
                $response = $this->aiRuntime->generate($attemptPrompt, $modelCode, self::SCENARIO_CODE, null, [
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'disable_conversation_history' => true,
                    'disable_conversation_persist' => true,
                    'disable_style_prompt_injection' => true,
                ], null, true);
                $recommendation = $this->validateRecommendation(
                    $this->decodeRecommendation($response),
                    $target,
                );
                break;
            } catch (\JsonException | \UnexpectedValueException $throwable) {
                if ($attempt >= self::MAX_RESPONSE_ATTEMPTS) {
                    throw $throwable;
                }
                $contractHint = $this->retryContractHint($throwable);
            }
        }
        if (!\is_array($recommendation)) {
            throw new \UnexpectedValueException('SEO analysis response is invalid.');
        }

        return $recommendation;
    }

    /**
     * @param array<string,mixed> $recommendation
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function validateRecommendation(array $recommendation, array $target): array
    {
        $required = ['target', 'objective', 'allowed_paths', 'instruction', 'primary_metric', 'guardrails', 'confidence'];
        $keys = \array_keys($recommendation);
        \sort($keys);
        $expected = $required;
        \sort($expected);
        if ($keys !== $expected || !\is_array($recommendation['target']) || !\is_array($recommendation['allowed_paths']) || !\is_array($recommendation['guardrails'])) {
            throw new \UnexpectedValueException('SEO analysis response contract is invalid.');
        }
        $targetKeys = \array_keys($recommendation['target']);
        \sort($targetKeys);
        if ($targetKeys !== ['block_key', 'page_type']) {
            throw new \UnexpectedValueException('SEO analysis target contract is invalid.');
        }

        if ((string)($recommendation['target']['page_type'] ?? '') !== (string)($target['page_type'] ?? '')
            || (string)($recommendation['target']['block_key'] ?? '') !== (string)($target['block_key'] ?? '')
        ) {
            throw new \UnexpectedValueException('SEO analysis changed the target.');
        }
        $availablePaths = \is_array($target['allowed_paths'] ?? null) ? \array_map('strval', $target['allowed_paths']) : [];
        $paths = [];
        foreach ($recommendation['allowed_paths'] as $path) {
            $path = \trim((string)$path);
            if ($path === '' || !\in_array($path, $availablePaths, true)) {
                throw new \UnexpectedValueException('SEO analysis requested a forbidden path.');
            }
            $paths[] = $path;
        }
        if ($paths === []) {
            throw new \UnexpectedValueException('SEO analysis returned no editable path.');
        }
        $objective = \strtolower(\trim((string)$recommendation['objective']));
        if (\preg_match('/^[a-z][a-z0-9_]{2,127}$/D', $objective) !== 1) {
            throw new \UnexpectedValueException('SEO analysis objective is invalid.');
        }
        $instruction = \trim((string)$recommendation['instruction']);
        if ($instruction === ''
            || \mb_strlen($instruction, 'UTF-8') > 2000
            || \preg_match('/<\/?(?:script|style|html|iframe)/i', $instruction) === 1
            || $this->containsSensitiveValue($instruction)) {
            throw new \UnexpectedValueException('SEO analysis instruction is invalid.');
        }
        $primaryMetric = \strtolower(\trim((string)$recommendation['primary_metric']));
        if ($primaryMetric === '' || !\hash_equals((string)($target['primary_metric'] ?? ''), $primaryMetric)) {
            throw new \UnexpectedValueException('SEO analysis changed the primary metric.');
        }
        $guardrails = [];
        foreach ($recommendation['guardrails'] as $guardrail) {
            $guardrail = \strtolower(\trim((string)$guardrail));
            if ($guardrail === '' || $guardrail === $primaryMetric) {
                continue;
            }
            if (!\in_array($guardrail, self::OBSERVABLE_GUARDRAIL_METRICS, true)) {
                throw new \UnexpectedValueException('SEO analysis guardrail metric is invalid.');
            }
            $guardrails[$guardrail] = $guardrail;
            if (\count($guardrails) > 5) {
                throw new \UnexpectedValueException('SEO analysis returned too many guardrails.');
            }
        }
        $confidence = (float)$recommendation['confidence'];
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \UnexpectedValueException('SEO analysis confidence is invalid.');
        }
        $recommendation['allowed_paths'] = \array_values(\array_unique($paths));
        $recommendation['objective'] = $objective;
        $recommendation['primary_metric'] = $primaryMetric;
        $recommendation['guardrails'] = \array_values($guardrails);
        $recommendation['confidence'] = $confidence;
        return $recommendation;
    }

    private function retryContractHint(\Throwable $throwable): string
    {
        if ($throwable instanceof \JsonException) {
            return 'Return valid JSON with no truncation or trailing content.';
        }

        $message = $throwable->getMessage();
        if (\str_contains($message, 'too many guardrails')) {
            return 'Return at most 5 unique guardrails and exclude the primary metric.';
        }
        if (\str_contains($message, 'guardrail metric is invalid')) {
            return 'Use only the server-observable guardrail metrics listed in the hard contract.';
        }
        if (\str_contains($message, 'target')) {
            return 'Copy page_type and block_key from target_snapshot exactly.';
        }
        if (\str_contains($message, 'path')) {
            return 'Copy one or more allowed_paths from target_snapshot.allowed_paths exactly.';
        }
        if (\str_contains($message, 'primary metric')) {
            return 'Copy target_snapshot.primary_metric exactly.';
        }
        if (\str_contains($message, 'confidence')) {
            return 'Return confidence as a JSON number between 0 and 1.';
        }

        return 'Conform every field to the hard contract exactly.';
    }

    /** @return array<string,mixed> */
    private function decodeRecommendation(string $response): array
    {
        $payload = \trim($response);
        if (\preg_match('/\A```(?:json)?\s*(\{.*\})\s*```\z/is', $payload, $match) === 1) {
            $payload = \trim((string)$match[1]);
        }
        $decoded = \json_decode($payload, true, 32, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || \array_is_list($decoded)) {
            throw new \UnexpectedValueException('SEO analysis response is invalid.');
        }

        return $decoded;
    }

    private function containsSensitiveValue(mixed $value): bool
    {
        if (\is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsSensitiveValue($item)) {
                    return true;
                }
            }
            return false;
        }
        if (!\is_string($value)) {
            return false;
        }
        if (\preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $value) === 1
            || \preg_match('/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/', $value) === 1
            || \preg_match('/\b(?:https?:\/\/|www\.)[^\s<>"\']+\?[^\s<>"\']+/i', $value) === 1
        ) {
            return true;
        }
        if (\preg_match_all('/(?<![\pL\pN])\+?[0-9][0-9\s().-]{5,}[0-9](?![\pL\pN])/u', $value, $matches) === false) {
            return false;
        }
        foreach ($matches[0] as $candidate) {
            $candidate = \trim((string)$candidate);
            if (\preg_match('/^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}(?:[ T][0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?)?$/D', $candidate) === 1) {
                continue;
            }
            $digits = \preg_replace('/\D+/', '', $candidate) ?? '';
            if (\strlen($digits) >= 7 && \strlen($digits) <= 15) {
                return true;
            }
        }
        return false;
    }
}
