<?php

declare(strict_types=1);

namespace LearningMcp;

use RuntimeException;
use Throwable;

interface ModelAnalyzer
{
    /** @param array<string, mixed> $bundle
     *  @return array<string, mixed>
     */
    public function extract(array $bundle): array;

    /** @param array<string, mixed> $draft
     *  @param list<array<string, mixed>> $evidence
     *  @return array<string, mixed>
     */
    public function verify(array $draft, array $evidence): array;

    /** @return array<string, mixed> */
    public function metadata(): array;
}

final class CodexModelAnalyzer implements ModelAnalyzer
{
    private readonly CodexInvoker $invoker;

    public function __construct(private readonly Config $config)
    {
        $this->invoker = new CodexInvoker($config, new ProcessRunner());
    }

    public function extract(array $bundle): array
    {
        return $this->invoker->extractSessionLearning($this->boundedPayload($bundle));
    }

    public function verify(array $draft, array $evidence): array
    {
        $verifiedIds = [];
        $hasUserIntent = false;
        $hasUserClaim = false;
        $hasOutcome = false;
        $hasContradiction = false;
        $confidence = 0.0;
        foreach ($evidence as $item) {
            if (($item['verified'] ?? false) !== true) {
                continue;
            }
            $type = (string) ($item['evidence_type'] ?? '');
            $polarity = (string) ($item['polarity'] ?? '');
            $strength = (float) ($item['strength'] ?? 0.0);
            if ($polarity === 'supports') {
                $verifiedIds[] = (string) $item['evidence_id'];
                $confidence = max($confidence, $strength);
                $hasUserIntent = $hasUserIntent || $type === 'user_intent';
                $hasUserClaim = $hasUserClaim || $type === 'user_technical_claim';
                $hasOutcome = $hasOutcome || in_array($type, [
                    'test_result', 'build_result', 'lint_result', 'browser_result',
                    'runtime_observation', 'user_confirmation', 'ci_result',
                ], true);
            } elseif ($polarity === 'contradicts') {
                $hasContradiction = true;
            }
        }
        $verifiedIds = Text::uniqueStrings($verifiedIds);
        $category = (string) ($draft['category'] ?? '');
        $knowledgeType = trim((string) ($draft['knowledge_type'] ?? ''));
        $surface = trim((string) ($draft['surface'] ?? ''));
        $environmentConstraints = Text::uniqueStrings(
            is_array($draft['environment_constraints'] ?? null) ? $draft['environment_constraints'] : [],
        );
        $positiveExample = trim((string) ($draft['positive_example'] ?? ''));
        $negativeExample = trim((string) ($draft['negative_example'] ?? ''));
        $normalizeExample = static fn(string $value): string => mb_strtolower(
            preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value),
            'UTF-8',
        );
        $examplesComplete = $positiveExample !== ''
            && $negativeExample !== ''
            && $normalizeExample($positiveExample) !== $normalizeExample($negativeExample);
        $technical = in_array($category, [
            'project_fact', 'architecture_decision', 'debugging_strategy', 'anti_pattern',
            'workflow_rule', 'tool_usage', 'test_oracle', 'security_boundary',
        ], true);
        $decision = 'unsupported';
        $problems = [];
        if (!in_array($knowledgeType, [
            'global_rule', 'project_rule', 'skill_knowledge', 'operational_observation',
        ], true)) {
            $problems[] = 'The learning scope classification is missing or invalid.';
        } elseif ($category === 'temporary_context') {
            $problems[] = 'Temporary context is not durable project learning.';
        } elseif ($verifiedIds === []) {
            $decision = $hasContradiction ? 'contradicted' : 'missing_evidence';
            $problems[] = 'No verified supporting evidence remains.';
        } elseif ($technical && $hasOutcome) {
            $decision = 'supported';
            $confidence = max($confidence, 0.9);
        } elseif ($technical && $hasUserClaim) {
            $decision = 'partially_supported';
            $confidence = min(max($confidence, 0.6), 0.77);
            $problems[] = 'The user-reported technical claim still needs an outcome check before automatic validation.';
        } elseif (!$technical && $hasUserIntent) {
            $decision = 'supported';
            $confidence = max($confidence, 0.9);
        } else {
            $decision = 'unsupported';
            $problems[] = 'The evidence type does not support this learning category.';
        }
        if (!$examplesComplete && in_array($decision, ['supported', 'partially_supported'], true)) {
            $decision = 'partially_supported';
            $confidence = min($confidence, 0.77);
            $problems[] = 'A distinct positive example and negative example are both required before automatic validation.';
        }
        if ($knowledgeType === 'operational_observation'
            && ($surface === '' || $environmentConstraints === [])
            && in_array($decision, ['supported', 'partially_supported'], true)) {
            $decision = 'partially_supported';
            $confidence = min($confidence, 0.77);
            $problems[] = 'Operational observations require a named product surface and explicit environment constraints.';
        }
        if ($knowledgeType === 'global_rule') {
            $problems[] = 'Global rules always require manual scope review and promotion.';
        }
        if ($hasContradiction && in_array($decision, ['supported', 'partially_supported'], true)) {
            $problems[] = 'Earlier contradicting evidence exists; only the verified supporting outcome is retained.';
        }

        return [
            'decision' => $decision,
            'confidence' => round(max(0.0, min(1.0, $confidence)), 3),
            'verified_evidence_ids' => $verifiedIds,
            'problems' => $problems,
            'narrowed_rule' => trim((string) ($draft['reusable_rule'] ?? '')),
            'scope_paths' => Text::uniqueStrings(is_array($draft['paths'] ?? null) ? $draft['paths'] : []),
            'exceptions' => Text::uniqueStrings(is_array($draft['exceptions'] ?? null) ? $draft['exceptions'] : []),
            'knowledge_type' => $knowledgeType,
            'surface' => $surface,
            'examples_complete' => $examplesComplete,
        ];
    }

    public function metadata(): array
    {
        return [
            'provider' => 'codex',
            'transport' => 'local_codex_cli',
            'extractor_prompt' => 'session-learning.v2',
            'verifier' => 'deterministic-evidence-gate.v2',
            'codex' => $this->invoker->metadata(),
        ];
    }

    /** @param array<string, mixed> $bundle
     *  @return array<string, mixed>
     */
    private function boundedPayload(array $bundle): array
    {
        $session = is_array($bundle['session'] ?? null) ? $bundle['session'] : [];
        $events = [];
        foreach (array_slice(is_array($bundle['events'] ?? null) ? $bundle['events'] : [], -60) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $context = is_array($event['context'] ?? null) ? $event['context'] : [];
            $events[] = [
                'event_id' => (string) ($event['event_id'] ?? ''),
                'type' => (string) ($event['type'] ?? ''),
                'role' => (string) ($event['role'] ?? ''),
                'observed_at' => (string) ($event['observed_at'] ?? ''),
                'tool_name' => (string) ($context['tool_name'] ?? ''),
                'content_redacted' => Text::truncate((string) ($event['content_redacted'] ?? ''), 1_200),
            ];
        }
        $evidence = [];
        $bundleEvidence = is_array($bundle['evidence'] ?? null) ? $bundle['evidence'] : [];
        foreach (array_slice($bundleEvidence, -40) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $evidence[] = [
                'evidence_id' => (string) ($item['evidence_id'] ?? ''),
                'evidence_type' => (string) ($item['evidence_type'] ?? ''),
                'source_event_id' => (string) ($item['source_event_id'] ?? ''),
                'claim' => Text::truncate((string) ($item['claim'] ?? ''), 800),
                'polarity' => (string) ($item['polarity'] ?? ''),
                'strength' => (float) ($item['strength'] ?? 0.0),
                'verified' => (bool) ($item['verified'] ?? false),
            ];
        }
        $signals = [];
        foreach (array_slice(is_array($bundle['signals'] ?? null) ? $bundle['signals'] : [], -60) as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $signals[] = [
                'type' => (string) ($signal['type'] ?? ''),
                'event_id' => (string) ($signal['event_id'] ?? ''),
                'evidence_id' => (string) ($signal['evidence_id'] ?? ''),
                'summary' => Text::truncate((string) ($signal['summary'] ?? ''), 500),
            ];
        }
        $payload = [
            'repository_root' => (string) ($session['cwd'] ?? ''),
            'max_candidates' => (int) $this->config->get('analysis.automatic_learning.max_candidates', 6),
            'allowed_evidence_ids' => Text::uniqueStrings(array_column($evidence, 'evidence_id')),
            'session' => [
                'id' => (string) ($session['id'] ?? ''),
                'project_id' => (string) ($session['project_id'] ?? ''),
                'cwd' => (string) ($session['cwd'] ?? ''),
                'outcome' => (string) ($session['outcome'] ?? ''),
            ],
            'events' => $events,
            'evidence' => $evidence,
            'signals' => $signals,
        ];
        $maximum = max(4_096, (int) $this->config->get('knowledge.codex.max_context_chars', 60_000) - 4_096);
        while (count($payload['events']) > 1 && strlen(Json::encode($payload)) > $maximum) {
            array_shift($payload['events']);
        }
        while (count($payload['signals']) > 1 && strlen(Json::encode($payload)) > $maximum) {
            array_shift($payload['signals']);
        }
        while (count($payload['evidence']) > 1 && strlen(Json::encode($payload)) > $maximum) {
            array_shift($payload['evidence']);
            $payload['allowed_evidence_ids'] = Text::uniqueStrings(array_column($payload['evidence'], 'evidence_id'));
        }

        return $payload;
    }
}

final class Analyzer
{
    private ?ModelAnalyzer $model = null;
    private readonly LearningNoveltyService $novelty;

    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
    ) {
        $this->novelty = new LearningNoveltyService($store, $config);
        if ($config->get('analysis.provider') === 'codex'
            && $config->get('analysis.automatic_learning.enabled', true) === true) {
            $this->model = new CodexModelAnalyzer($config);
        } elseif ($config->get('analysis.provider') === 'openai') {
            $this->model = new OpenAIAnalyzer($config);
        }
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->model?->metadata() ?? ['provider' => 'none', 'mode' => 'deterministic'];
    }

    /** @param array<string, mixed> $job
     *  @return array<string, mixed>
     */
    public function processJob(array $job): array
    {
        return match ($job['job_type']) {
            'analyze_session' => $this->analyzeSession(self::required($job, 'session_id')),
            'review_feedback' => [
                'decision' => 'review_queued',
                'experience_ids' => [],
                'evidence_ids' => [],
                'signals' => [],
                'analyzer' => 'deterministic.php.v1',
            ],
            default => throw new RuntimeException('Unsupported job type: ' . $job['job_type']),
        };
    }

    /** @return array<string, mixed> */
    public function analyzeSession(string $sessionId): array
    {
        $session = $this->store->getSession($sessionId);
        if (($session['consent']['allow_learning'] ?? true) !== true) {
            return $this->emptyResult('no_learning');
        }
        $events = $this->store->listEvents($sessionId);
        [$corrections, $evidenceSignals, $signals] = $this->detectSignals($events);
        $evidenceIds = [];
        foreach ($evidenceSignals as $signal) {
            $stored = $this->store->putEvidence($signal['evidence']);
            $evidenceIds[] = $stored['id'];
        }
        $modelSignal = $this->model !== null
            && $this->config->get('analysis.automatic_learning.enabled', true) === true
            && ($corrections !== [] || $this->hasModelLearningSignal($evidenceSignals));
        if ($modelSignal) {
            try {
                $modelResult = $this->analyzeWithModel($session, $events, $evidenceSignals, $signals);
                if (($modelResult['decision'] ?? 'no_learning') !== 'no_learning' || $corrections === []) {
                    return $modelResult;
                }
            } catch (Throwable $exception) {
                $this->store->writeAudit('learningd', 'model_learning_failed', 'session', $sessionId, [
                    'provider' => $this->model?->metadata()['provider'] ?? 'unknown',
                    'reason' => Text::truncate($exception->getMessage(), 800),
                    'fallback' => $corrections === [] ? 'no_learning' : 'deterministic_corrections',
                ]);
            }
        }
        if ($corrections === []) {
            $this->store->writeAudit('learningd', 'no_learning', 'session', $sessionId, [
                'reason' => $modelSignal
                    ? 'automatic extractor found no durable evidence-backed learning'
                    : 'no correction, retraction, or successful high-signal outcome',
                'event_count' => count($events),
                'signals' => $signals,
            ]);
            return [
                'decision' => 'no_learning',
                'experience_ids' => [],
                'evidence_ids' => Text::uniqueStrings($evidenceIds),
                'signals' => $signals,
                'analyzer' => 'deterministic.php.v1',
            ];
        }
        $experienceIds = [];
        $judgments = [];
        foreach ($corrections as $correction) {
            $experience = $this->deterministicExperience($session, $correction, $evidenceSignals);
            $judgment = $this->novelty->persist($session, $experience);
            $judgments[] = $judgment;
            if (($judgment['experience_id'] ?? '') !== '') {
                $experienceIds[] = (string) $judgment['experience_id'];
            }
        }

        return [
            'decision' => self::resultDecision($judgments),
            'experience_ids' => Text::uniqueStrings($experienceIds),
            'evidence_ids' => Text::uniqueStrings($evidenceIds),
            'signals' => $signals,
            'learning_judgments' => $judgments,
            'analyzer' => 'deterministic.php.v1',
        ];
    }

    /** @param array<string, mixed> $session
     *  @param list<array<string, mixed>> $events
     *  @param list<array<string, mixed>> $evidenceSignals
     *  @param list<array<string, mixed>> $signals
     *  @return array<string, mixed>
     */
    private function analyzeWithModel(array $session, array $events, array $evidenceSignals, array $signals): array
    {
        $evidence = array_column($evidenceSignals, 'evidence');
        $allowed = [];
        foreach ($evidence as $item) {
            $allowed[$item['evidence_id']] = $item;
        }
        $bundle = [
            'session' => $session,
            'events' => $this->limitEvents($events),
            'evidence' => $evidence,
            'signals' => $signals,
        ];
        $extraction = $this->model?->extract($bundle) ?? ['decision' => 'no_learning', 'experiences' => []];
        $result = [
            'decision' => $extraction['decision'] ?? 'no_learning',
            'experience_ids' => [],
            'evidence_ids' => array_keys($allowed),
            'signals' => $signals,
            'learning_judgments' => [],
            'analyzer' => 'model.php.v3',
        ];
        if (in_array($result['decision'], ['no_learning', 'discard'], true)) {
            $result['decision'] = 'no_learning';
            return $result;
        }
        foreach (($extraction['experiences'] ?? []) as $draft) {
            if (!is_array($draft)) {
                continue;
            }
            $draftIds = Text::uniqueStrings(is_array($draft['evidence_ids'] ?? null) ? $draft['evidence_ids'] : []);
            $resolved = [];
            $unknown = [];
            foreach ($draftIds as $id) {
                if (!isset($allowed[$id])) {
                    $unknown[] = $id;
                } else {
                    $resolved[] = $allowed[$id];
                }
            }
            if ($unknown !== [] || $resolved === []) {
                $this->store->writeAudit('learningd', 'reject_model_draft', 'session', $session['id'], [
                    'reason' => 'unknown or missing evidence IDs',
                    'unknown_evidence_ids' => $unknown,
                    'title' => $draft['title'] ?? '',
                ]);
                continue;
            }
            $assessment = $this->model?->verify($draft, $resolved) ?? [];
            if (!in_array($assessment['decision'] ?? '', ['supported', 'partially_supported'], true)) {
                $this->store->writeAudit('learningd', 'reject_model_draft', 'session', $session['id'], [
                    'decision' => $assessment['decision'] ?? 'invalid',
                    'problems' => $assessment['problems'] ?? [],
                    'title' => $draft['title'] ?? '',
                ]);
                continue;
            }
            $verifiedIds = Text::uniqueStrings(is_array($assessment['verified_evidence_ids'] ?? null) ? $assessment['verified_evidence_ids'] : []);
            if ($verifiedIds === []
                || array_diff($verifiedIds, array_keys($allowed)) !== []
                || array_diff($verifiedIds, $draftIds) !== []) {
                continue;
            }
            $experience = $this->experienceFromDraft($session, $draft, $assessment, $verifiedIds);
            $judgment = $this->novelty->persist($session, $experience);
            $result['learning_judgments'][] = $judgment;
            if (($judgment['experience_id'] ?? '') !== '') {
                $result['experience_ids'][] = (string) $judgment['experience_id'];
            }
        }
        $result['experience_ids'] = Text::uniqueStrings($result['experience_ids']);
        if ($result['learning_judgments'] === []) {
            $result['decision'] = 'no_learning';
        } else {
            $result['decision'] = self::resultDecision($result['learning_judgments']);
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $events
     *  @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:list<array<string,mixed>>}
     */
    private function detectSignals(array $events): array
    {
        $corrections = [];
        $evidenceSignals = [];
        $signals = [];
        $previousAction = '';
        $hasPriorAgentActivity = false;
        foreach ($events as $event) {
            $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
            $content = (string) ($event['content_redacted'] ?? '');
            if (($metadata['quarantined'] ?? false) === true || Redactor::looksLikeInjection($content)) {
                $signals[] = ['type' => 'injection_suspected', 'event_id' => $event['event_id'], 'summary' => 'event quarantined from learning'];
                continue;
            }
            if ($event['type'] === 'tool_call') {
                $previousAction = trim((string) ($event['context']['tool_name'] ?? '') . ' ' . Text::truncate($content, 240));
                $hasPriorAgentActivity = true;
            }
            $assistantEvent = $event['role'] === 'assistant' || in_array($event['type'], ['assistant_message', 'assistant_revision'], true);
            $stopIncludesAssistantMessage = array_key_exists('last_assistant_message', $metadata);
            if ($assistantEvent) {
                $hasPriorAgentActivity = true;
            }
            if (($assistantEvent || $stopIncludesAssistantMessage) && self::isRetraction($content)) {
                $evidenceId = self::evidenceId((string) $event['event_id'], 'assistant_retraction');
                $evidence = $this->newEvidence($event, $evidenceId, 'assistant_retraction', Text::truncate($content, 1_200), 'contradicts', 0.6, true);
                $correction = [
                    'source' => 'assistant',
                    'kind' => 'technical_claim',
                    'summary' => $evidence['claim'],
                    'event_ids' => [$event['event_id']],
                ];
                $corrections[] = ['correction' => $correction, 'evidence_id' => $evidenceId, 'event' => $event, 'wrong' => null];
                $evidenceSignals[] = ['evidence' => $evidence, 'event' => $event, 'result' => 'retracted'];
                $signals[] = ['type' => 'assistant_retraction', 'event_id' => $event['event_id'], 'summary' => $evidence['claim'], 'evidence_id' => $evidenceId];
            }
            if ($event['type'] === 'user_message') {
                $kind = self::correctionKind($content, $hasPriorAgentActivity);
                if ($kind !== null) {
                    $evidenceId = self::evidenceId((string) $event['event_id'], $kind);
                    $intent = in_array($kind, ['intent_correction', 'preference_correction', 'acceptance_criteria'], true);
                    $evidence = $this->newEvidence(
                        $event,
                        $evidenceId,
                        $intent ? 'user_intent' : 'user_technical_claim',
                        Text::truncate($content, 1_600),
                        'supports',
                        $intent ? 1.0 : 0.6,
                        true,
                        ['kind' => $kind],
                    );
                    $wrong = $previousAction === '' ? null : [
                        'approach' => $previousAction,
                        'assumption' => "The preceding observable action satisfied the user's requirement.",
                        'status' => 'refuted',
                        'evidence_ids' => [$evidenceId],
                    ];
                    $correction = [
                        'source' => 'user',
                        'kind' => $kind,
                        'summary' => $evidence['claim'],
                        'event_ids' => [$event['event_id']],
                    ];
                    $corrections[] = ['correction' => $correction, 'evidence_id' => $evidenceId, 'event' => $event, 'wrong' => $wrong];
                    $evidenceSignals[] = ['evidence' => $evidence, 'event' => $event, 'result' => 'correction'];
                    $signals[] = ['type' => 'user_correction', 'event_id' => $event['event_id'], 'kind' => $kind, 'summary' => $evidence['claim'], 'evidence_id' => $evidenceId];
                }
            }
            if (in_array($event['type'], [
                'tool_result', 'command_result', 'test_result', 'build_result', 'lint_result',
                'browser_result', 'runtime_observation',
            ], true)) {
                $status = self::observableResult($event);
                if ($status !== null) {
                    $type = $event['type'];
                    if ($type === 'tool_result' && ($event['context']['tool_name'] ?? '') === 'Bash') {
                        $type = 'command_result';
                    }
                    $evidenceId = self::evidenceId((string) $event['event_id'], $type . ':' . $status);
                    $evidence = $this->newEvidence(
                        $event,
                        $evidenceId,
                        $type,
                        $status . ': ' . Text::truncate($content, 1_200),
                        $status === 'failed' ? 'contradicts' : 'supports',
                        self::resultStrength($type),
                        true,
                        ['tool_name' => $event['context']['tool_name'] ?? ''],
                    );
                    $evidenceSignals[] = ['evidence' => $evidence, 'event' => $event, 'result' => $status];
                    $signals[] = [
                        'type' => $status === 'failed' ? 'failed_outcome' : 'successful_outcome',
                        'event_id' => $event['event_id'],
                        'summary' => $evidence['claim'],
                        'evidence_id' => $evidenceId,
                    ];
                }
            }
        }
        $deduplicated = [];
        foreach ($evidenceSignals as $signal) {
            $deduplicated[$signal['evidence']['evidence_id']] = $signal;
        }

        return [$corrections, array_values($deduplicated), $signals];
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $correction
     *  @param list<array<string, mixed>> $evidenceSignals
     *  @return array<string, mixed>
     */
    private function deterministicExperience(array $session, array $correction, array $evidenceSignals): array
    {
        $kind = $correction['correction']['kind'];
        $category = match ($kind) {
            'preference_correction' => 'user_preference',
            'intent_correction', 'acceptance_criteria' => 'project_constraint',
            'project_fact' => 'project_fact',
            default => 'debugging_strategy',
        };
        $directive = (string) $correction['correction']['summary'];
        if ($kind === 'technical_claim') {
            $directive = 'Verify this user-reported technical correction against code or runtime evidence before acting: ' . $directive;
        }
        $evidenceIds = [$correction['evidence_id']];
        $verification = [];
        $lastFailure = null;
        $successfulOutcome = false;
        foreach ($evidenceSignals as $item) {
            if ($item['event']['observed_at'] < $correction['event']['observed_at'] && $item['result'] === 'failed') {
                $lastFailure = $item;
            }
            if ($item['event']['observed_at'] >= $correction['event']['observed_at'] && $item['result'] === 'passed') {
                $evidenceIds[] = $item['evidence']['evidence_id'];
                $verification[] = ['evidence_id' => $item['evidence']['evidence_id'], 'result' => 'passed'];
                $successfulOutcome = true;
            }
        }
        $wrongApproaches = [];
        if (is_array($correction['wrong'])) {
            $wrong = $correction['wrong'];
            if ($lastFailure !== null) {
                $wrong['evidence_ids'][] = $lastFailure['evidence']['evidence_id'];
                $evidenceIds[] = $lastFailure['evidence']['evidence_id'];
            }
            $wrong['evidence_ids'] = Text::uniqueStrings($wrong['evidence_ids']);
            $wrongApproaches[] = $wrong;
        } elseif ($lastFailure !== null) {
            $wrongApproaches[] = [
                'approach' => Text::truncate((string) ($lastFailure['event']['context']['tool_name'] ?? '') . ' ' . $lastFailure['event']['content_redacted'], 500),
                'assumption' => '',
                'status' => 'refuted',
                'evidence_ids' => [$lastFailure['evidence']['evidence_id']],
            ];
            $evidenceIds[] = $lastFailure['evidence']['evidence_id'];
        }
        $knowledgeType = match ($kind) {
            'preference_correction', 'intent_correction', 'acceptance_criteria', 'project_fact' => 'project_rule',
            default => 'skill_knowledge',
        };
        $surface = $kind === 'technical_claim' ? 'project_runtime' : 'project_workflow';
        $positiveExample = Text::truncate($directive, 1_600);
        $negativeExample = $wrongApproaches === []
            ? ''
            : Text::truncate((string) ($wrongApproaches[0]['approach'] ?? ''), 1_600);
        $breakdown = self::confidenceForCorrection($kind, $wrongApproaches !== [], $successfulOutcome);
        $now = Clock::now();

        return [
            'experience_id' => Ids::make('exp'),
            'project_id' => $session['project_id'],
            'schema_version' => 'experience.v1',
            'version' => 1,
            'fingerprint' => self::fingerprint(
                $session['project_id'],
                $category . ':' . $knowledgeType . ':' . $surface,
                $directive,
            ),
            'title' => Text::truncate((string) $correction['correction']['summary'], 100),
            'category' => $category,
            'problem_pattern' => $correction['correction']['summary'],
            'trigger' => 'A task matches the same project-scoped correction.',
            'root_cause' => '',
            'correct_approach' => $directive,
            'reusable_rule' => $directive,
            'wrong_approaches' => $wrongApproaches,
            'corrections' => [$correction['correction']],
            'verification' => $verification,
            'scope' => ['project_ids' => [$session['project_id']]],
            'exceptions' => [],
            'confidence' => self::confidenceScore($breakdown),
            'confidence_breakdown' => $breakdown,
            'status' => 'candidate',
            'source_session_ids' => [$session['id']],
            'evidence_ids' => Text::uniqueStrings($evidenceIds),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'metadata' => [
                'analyzer' => 'deterministic.php.v2',
                'learning_classification' => [
                    'knowledge_type' => $knowledgeType,
                    'surface' => $surface,
                    'environment_constraints' => [],
                    'positive_example' => $positiveExample,
                    'negative_example' => $negativeExample,
                    'examples_complete' => $positiveExample !== '' && $negativeExample !== '',
                    'classified_at' => $now,
                ],
            ],
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $draft
     *  @param array<string, mixed> $assessment
     *  @param list<string> $verifiedIds
     *  @return array<string, mixed>
     */
    private function experienceFromDraft(array $session, array $draft, array $assessment, array $verifiedIds): array
    {
        $rule = trim((string) ($assessment['narrowed_rule'] ?? '')) ?: (string) ($draft['reusable_rule'] ?? '');
        $paths = !empty($assessment['scope_paths']) ? $assessment['scope_paths'] : ($draft['paths'] ?? []);
        $exceptions = Text::uniqueStrings(array_merge($draft['exceptions'] ?? [], $assessment['exceptions'] ?? []));
        $wrong = [];
        foreach (($draft['wrong_approaches'] ?? []) as $approach) {
            $wrong[] = ['approach' => (string) $approach, 'assumption' => '', 'status' => 'refuted', 'evidence_ids' => $verifiedIds];
        }
        $breakdown = [
            'source_authority' => 0.7,
            'evidence_quality' => min((float) ($assessment['confidence'] ?? 0.0), 0.9),
            'chain_completeness' => 0.75,
            'outcome_validation' => 0.8,
            'recurrence' => 0.2,
            'scope_clarity' => $paths === [] ? 0.7 : 1.0,
            'temporal_relevance' => 1.0,
            'penalties' => ($assessment['decision'] ?? '') === 'partially_supported' ? 0.1 : 0.0,
        ];
        $now = Clock::now();
        $metadata = $this->model?->metadata() ?? [];
        $metadata['learning_classification'] = [
            'knowledge_type' => (string) ($draft['knowledge_type'] ?? ''),
            'surface' => (string) ($draft['surface'] ?? ''),
            'environment_constraints' => Text::uniqueStrings(
                is_array($draft['environment_constraints'] ?? null) ? $draft['environment_constraints'] : [],
            ),
            'positive_example' => (string) ($draft['positive_example'] ?? ''),
            'negative_example' => (string) ($draft['negative_example'] ?? ''),
            'examples_complete' => (bool) ($assessment['examples_complete'] ?? (
                trim((string) ($draft['positive_example'] ?? '')) !== ''
                && trim((string) ($draft['negative_example'] ?? '')) !== ''
            )),
            'classified_at' => $now,
        ];

        return [
            'experience_id' => Ids::make('exp'),
            'project_id' => $session['project_id'],
            'schema_version' => 'experience.v1',
            'version' => 1,
            'fingerprint' => self::fingerprint(
                $session['project_id'],
                (string) $draft['category'] . ':' . (string) ($draft['knowledge_type'] ?? '')
                    . ':' . (string) ($draft['surface'] ?? ''),
                $rule,
            ),
            'title' => (string) $draft['title'],
            'category' => (string) $draft['category'],
            'problem_pattern' => (string) $draft['problem_pattern'],
            'trigger' => (string) ($draft['trigger'] ?? ''),
            'root_cause' => (string) ($draft['root_cause'] ?? ''),
            'correct_approach' => (string) $draft['correct_approach'],
            'reusable_rule' => $rule,
            'wrong_approaches' => $wrong,
            'corrections' => [],
            'verification' => array_map(static fn(string $id): array => ['evidence_id' => $id, 'result' => 'supported'], $verifiedIds),
            'scope' => [
                'project_ids' => [$session['project_id']],
                'paths' => Text::uniqueStrings($paths),
                'languages' => Text::uniqueStrings($draft['languages'] ?? []),
            ],
            'exceptions' => $exceptions,
            'confidence' => self::confidenceScore($breakdown),
            'confidence_breakdown' => $breakdown,
            'status' => 'candidate',
            'source_session_ids' => [$session['id']],
            'evidence_ids' => $verifiedIds,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'metadata' => $metadata,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param array<string, mixed> $event
     *  @param array<string, mixed> $extraLocator
     *  @return array<string, mixed>
     */
    private function newEvidence(
        array $event,
        string $id,
        string $type,
        string $claim,
        string $polarity,
        float $strength,
        bool $verified,
        array $extraLocator = [],
    ): array {
        return [
            'evidence_id' => $id,
            'project_id' => $event['project_id'],
            'session_id' => $event['session_id'],
            'evidence_type' => $type,
            'source_event_id' => $event['event_id'],
            'claim' => $claim,
            'polarity' => $polarity,
            'strength' => $strength,
            'locator' => array_merge(['event_id' => $event['event_id']], $extraLocator),
            'verified' => $verified,
            'created_at' => $event['observed_at'],
        ];
    }

    private static function correctionKind(string $content, bool $hasPrior): ?string
    {
        $lower = mb_strtolower($content, 'UTF-8');
        $explicit = self::containsAny($lower, ['不对', '不是这个意思', '还是有问题', '你看错了', '不要这样做', 'still broken', 'not what i meant', "that's wrong", 'that is wrong', 'regression']);
        $implicit = self::containsAny($lower, ['应该是', '实际上', '我需要的是', '原来要求是', 'expected', 'what i need is']);
        if (!$explicit && !($implicit && $hasPrior)) {
            return null;
        }
        return match (true) {
            self::containsAny($lower, ['偏好', '习惯', 'prefer']) => 'preference_correction',
            self::containsAny($lower, ['不是这个意思', '我需要的是', '我要的是', '不要', 'not what i meant', 'what i need']) => 'intent_correction',
            self::containsAny($lower, ['验收', '算完成', '必须看到', 'acceptance', 'expected']) => 'acceptance_criteria',
            self::containsAny($lower, ['项目约定', '仓库规定', 'project fact', 'in this repository']) => 'project_fact',
            default => 'technical_claim',
        };
    }

    private static function isRetraction(string $content): bool
    {
        return self::containsAny(mb_strtolower($content, 'UTF-8'), [
            '我之前的判断是错', '我刚才看错', '撤回之前', 'i was wrong',
            'my previous assumption was wrong', 'i retract',
        ]);
    }

    /** @param array<string, mixed> $event */
    private static function observableResult(array $event): ?string
    {
        $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
        $exitCode = Text::findRecursive($metadata, 'exit_code');
        if (is_int($exitCode) || is_float($exitCode) || (is_string($exitCode) && is_numeric($exitCode))) {
            return (int) $exitCode === 0 ? 'passed' : 'failed';
        }
        $status = Text::findRecursive($metadata, 'status');
        if (is_string($status)) {
            $status = strtolower($status);
            if (in_array($status, ['passed', 'success', 'succeeded', 'completed', 'ok'], true)) {
                return 'passed';
            }
            if (in_array($status, ['failed', 'failure', 'error', 'timed_out', 'timeout'], true)) {
                return 'failed';
            }
        }
        $content = strtolower((string) ($event['content_redacted'] ?? ''));
        if (preg_match('/"exit_code"\s*:\s*0/', $content) === 1 || str_contains($content, 'status: passed')) {
            return 'passed';
        }
        if (preg_match('/"exit_code"\s*:\s*[1-9][0-9]*/', $content) === 1 || str_contains($content, 'status: failed')) {
            return 'failed';
        }

        return null;
    }

    /** @return array<string, float> */
    private static function confidenceForCorrection(string $kind, bool $hasWrongPath, bool $successfulOutcome): array
    {
        $source = in_array($kind, ['intent_correction', 'preference_correction', 'acceptance_criteria'], true) ? 1.0 : 0.6;
        return [
            'source_authority' => $source,
            'evidence_quality' => 0.9,
            'chain_completeness' => $hasWrongPath ? 0.8 : 0.4,
            'outcome_validation' => $successfulOutcome ? 1.0 : 0.2,
            'recurrence' => 0.2,
            'scope_clarity' => 1.0,
            'temporal_relevance' => 1.0,
            'penalties' => $kind === 'technical_claim' && !$successfulOutcome ? 0.2 : 0.0,
        ];
    }

    /** @param array<string, float|int> $value */
    private static function confidenceScore(array $value): float
    {
        $score = 0.15 * $value['source_authority']
            + 0.20 * $value['evidence_quality']
            + 0.15 * $value['chain_completeness']
            + 0.20 * $value['outcome_validation']
            + 0.10 * $value['recurrence']
            + 0.10 * $value['scope_clarity']
            + 0.10 * $value['temporal_relevance']
            - $value['penalties'];

        return round(max(0.0, min(1.0, $score)), 3);
    }

    private static function resultStrength(string $type): float
    {
        return match ($type) {
            'test_result', 'build_result', 'lint_result' => 0.95,
            'browser_result', 'runtime_observation' => 0.9,
            default => 0.8,
        };
    }

    /** @param list<array<string, mixed>> $evidenceSignals */
    private function hasModelLearningSignal(array $evidenceSignals): bool
    {
        foreach ($evidenceSignals as $signal) {
            $evidence = is_array($signal['evidence'] ?? null) ? $signal['evidence'] : [];
            if (($signal['result'] ?? '') === 'passed'
                && in_array((string) ($evidence['evidence_type'] ?? ''), [
                    'test_result', 'build_result', 'lint_result', 'browser_result',
                    'runtime_observation', 'user_confirmation', 'ci_result',
                ], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $judgments */
    private static function resultDecision(array $judgments): string
    {
        if ($judgments === []) {
            return 'no_learning';
        }
        foreach ($judgments as $judgment) {
            if (($judgment['decision'] ?? '') === 'conflict' || ($judgment['status'] ?? '') === 'contested') {
                return 'contested';
            }
        }
        foreach ($judgments as $judgment) {
            if (($judgment['auto_validated'] ?? false) === true
                || in_array((string) ($judgment['status'] ?? ''), ['validated', 'promotion_eligible', 'promoted'], true)) {
                return 'validated';
            }
        }
        foreach (['enrichment', 'new', 'duplicate_experience', 'known_project_knowledge'] as $decision) {
            foreach ($judgments as $judgment) {
                if (($judgment['decision'] ?? '') === $decision) {
                    return $decision === 'new' ? 'candidate' : $decision;
                }
            }
        }

        return 'candidate';
    }

    private static function evidenceId(string $eventId, string $kind): string
    {
        return Ids::deterministic('ev', $eventId . "\n" . $kind);
    }

    private static function fingerprint(string $projectId, string $category, string $rule): string
    {
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($rule)) ?? trim($rule), 'UTF-8');
        return Ids::hash($projectId . "\n" . $category . "\n" . $normalized);
    }

    /** @param list<string> $candidates */
    private static function containsAny(string $value, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (str_contains($value, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $events
     *  @return list<array<string, mixed>>
     */
    private function limitEvents(array $events): array
    {
        $budget = (int) $this->config->get('analysis.max_session_tokens', 50_000) * 4;
        $events = array_reverse(array_slice($events, -200));
        $result = [];
        $used = 0;
        foreach ($events as $event) {
            $event['content_redacted'] = Text::truncate((string) ($event['content_redacted'] ?? ''), 5_000);
            $event['raw_ref'] = '';
            $encoded = Json::encode($event);
            if ($used + strlen($encoded) > $budget) {
                break;
            }
            $used += strlen($encoded);
            $result[] = $event;
        }

        return array_reverse($result);
    }

    /** @return array<string, mixed> */
    private function emptyResult(string $decision): array
    {
        return [
            'decision' => $decision,
            'experience_ids' => [],
            'evidence_ids' => [],
            'signals' => [],
            'analyzer' => 'deterministic.php.v1',
        ];
    }

    /** @param array<string, mixed> $value */
    private static function required(array $value, string $key): string
    {
        $text = trim((string) ($value[$key] ?? ''));
        if ($text === '') {
            throw new RuntimeException($key . ' is required');
        }

        return $text;
    }
}
