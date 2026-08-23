<?php

declare(strict_types=1);

namespace LearningMcp;

use Throwable;

/**
 * Compare a session-derived experience with both the Experience store and the
 * persistent project index before it can become trusted project guidance.
 */
final class LearningNoveltyService
{
    private const TECHNICAL_CATEGORIES = [
        'project_fact', 'architecture_decision', 'debugging_strategy', 'anti_pattern',
        'workflow_rule', 'tool_usage', 'test_oracle', 'security_boundary',
    ];

    private const OUTCOME_EVIDENCE = [
        'test_result', 'build_result', 'lint_result', 'browser_result',
        'runtime_observation', 'user_confirmation', 'ci_result',
    ];

    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $experience
     * @return array<string, mixed>
     */
    public function persist(array $session, array $experience): array
    {
        $projectId = (string) $experience['project_id'];
        $existingMatches = $this->experienceMatches($projectId, $experience);
        $projectSearch = $this->projectMatches($session, $experience);
        $projectMatches = $projectSearch['matches'];
        $decision = $this->decide($experience, $existingMatches, $projectMatches);
        $match = is_array($decision['match'] ?? null) ? $decision['match'] : [];
        $kind = (string) $decision['decision'];

        $auditDetails = [
            'decision' => $kind,
            'candidate_fingerprint' => (string) $experience['fingerprint'],
            'experience_match' => $this->publicMatch($existingMatches[0] ?? []),
            'project_matches' => array_map($this->publicMatch(...), array_slice($projectMatches, 0, 5)),
            'index_revision' => (int) ($projectSearch['index_revision'] ?? 0),
        ];

        if ($kind === 'known_project_knowledge') {
            $this->store->writeAudit(
                'automatic-learning',
                'skip_known_project_knowledge',
                'session',
                (string) $session['id'],
                $auditDetails,
            );

            return [
                'decision' => $kind,
                'experience_id' => '',
                'status' => 'already_indexed',
                'auto_validated' => false,
                'match' => $this->publicMatch($match),
                'index_revision' => (int) ($projectSearch['index_revision'] ?? 0),
            ];
        }

        $experience['metadata'] = array_replace(
            is_array($experience['metadata'] ?? null) ? $experience['metadata'] : [],
            [
                'automatic_learning' => [
                    'decision' => $kind,
                    'decided_at' => Clock::now(),
                    'match' => $this->publicMatch($match),
                    'index_revision' => (int) ($projectSearch['index_revision'] ?? 0),
                ],
            ],
        );

        $conflictingExperienceId = '';
        if ($kind === 'duplicate_experience') {
            $experience['fingerprint'] = (string) $match['fingerprint'];
        } elseif ($kind === 'conflict') {
            $conflictingExperienceId = (string) ($match['experience_id'] ?? '');
            if (($match['status'] ?? '') === 'rejected' || ($match['status'] ?? '') === 'deprecated') {
                $experience['supersedes_id'] = $conflictingExperienceId;
                $experience['fingerprint'] = Ids::hash(
                    (string) $experience['fingerprint'] . "\nreconsideration\n" . (string) $session['id'],
                );
            }
        } elseif ($kind === 'enrichment' && isset($match['experience_id'])) {
            $experience['metadata']['automatic_learning']['related_experience_id'] = (string) $match['experience_id'];
        }

        $evidence = $this->store->evidence(
            Text::uniqueStrings(is_array($experience['evidence_ids'] ?? null) ? $experience['evidence_ids'] : []),
        );
        $eligible = $kind !== 'conflict' && $this->strongEvidence($experience, $evidence);
        if ($eligible && $this->config->get('analysis.automatic_learning.auto_validate', true) === true) {
            $minimum = (float) $this->config->get(
                'analysis.automatic_learning.minimum_validation_confidence',
                0.9,
            );
            $experience['confidence'] = max((float) $experience['confidence'], $minimum);
            $breakdown = is_array($experience['confidence_breakdown'] ?? null)
                ? $experience['confidence_breakdown']
                : [];
            $breakdown['automatic_evidence_gate'] = $minimum;
            $experience['confidence_breakdown'] = $breakdown;
        }

        $stored = $this->store->upsertExperience($experience);
        $storedExperience = $stored['experience'];
        if ($kind === 'new' && $stored['created'] === false) {
            $kind = 'duplicate_experience';
            $auditDetails['decision'] = $kind;
        }

        if ($kind === 'conflict') {
            if ($conflictingExperienceId !== ''
                && $conflictingExperienceId !== (string) $storedExperience['experience_id']) {
                $this->store->recordContradiction(
                    $conflictingExperienceId,
                    (string) $storedExperience['experience_id'],
                    [
                        'detected_by' => 'automatic-learning',
                        'reason' => 'Opposing reusable rules matched above the configured conflict threshold.',
                        'score' => (float) ($match['conflict_score'] ?? $match['score'] ?? 0.0),
                    ],
                );
            }
            try {
                $storedExperience = $this->store->markExperience(
                    (string) $storedExperience['experience_id'],
                    'contested',
                    'automatic-learning',
                    'Automatic novelty judgment found conflicting project knowledge.',
                );
            } catch (Throwable $exception) {
                $auditDetails['contest_error'] = Text::truncate($exception->getMessage(), 500);
            }
        }

        $autoValidated = false;
        $validationError = '';
        if ($kind !== 'conflict' && $eligible
            && $this->config->get('analysis.automatic_learning.auto_validate', true) === true) {
            if (in_array((string) $storedExperience['status'], ['validated', 'promotion_eligible', 'promoted'], true)) {
                $autoValidated = true;
            } elseif (!in_array((string) $storedExperience['status'], ['contested', 'rejected', 'deprecated'], true)) {
                try {
                    $storedExperience = $this->store->markExperience(
                        (string) $storedExperience['experience_id'],
                        'validated',
                        'automatic-learning',
                        'Verified user intent or successful non-model outcome passed automatic learning gates.',
                    );
                    $autoValidated = true;
                } catch (Throwable $exception) {
                    $validationError = Text::truncate($exception->getMessage(), 500);
                }
            }
        }

        $auditDetails += [
            'experience_id' => (string) $storedExperience['experience_id'],
            'created' => (bool) $stored['created'],
            'status' => (string) $storedExperience['status'],
            'auto_validation_eligible' => $eligible,
            'auto_validated' => $autoValidated,
            'validation_error' => $validationError,
        ];
        $this->store->writeAudit(
            'automatic-learning',
            'judge_session_learning',
            'experience',
            (string) $storedExperience['experience_id'],
            $auditDetails,
        );

        return [
            'decision' => $kind,
            'experience_id' => (string) $storedExperience['experience_id'],
            'status' => (string) $storedExperience['status'],
            'created' => (bool) $stored['created'],
            'auto_validated' => $autoValidated,
            'validation_error' => $validationError,
            'match' => $this->publicMatch($match),
            'index_revision' => (int) ($projectSearch['index_revision'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $experience
     *  @param list<array<string, mixed>> $existing
     *  @param list<array<string, mixed>> $project
     *  @return array<string, mixed>
     */
    private function decide(array $experience, array $existing, array $project): array
    {
        $duplicate = (float) $this->config->get('analysis.automatic_learning.duplicate_similarity', 0.86);
        $related = (float) $this->config->get('analysis.automatic_learning.related_similarity', 0.55);
        $projectDuplicate = (float) $this->config->get('analysis.automatic_learning.project_duplicate_similarity', 0.9);

        foreach ($existing as $match) {
            if (($match['conflict'] ?? false) === true) {
                return ['decision' => 'conflict', 'match' => $match];
            }
        }
        foreach ($project as $match) {
            if (($match['conflict'] ?? false) === true) {
                return ['decision' => 'conflict', 'match' => $match];
            }
        }
        foreach ($existing as $match) {
            if (($match['fingerprint'] ?? '') === ($experience['fingerprint'] ?? '')
                || ((float) ($match['score'] ?? 0.0) >= $duplicate
                    && ($match['category'] ?? '') === ($experience['category'] ?? ''))) {
                if (in_array((string) ($match['status'] ?? ''), ['rejected', 'deprecated'], true)) {
                    $match['conflict'] = true;
                    $match['conflict_reason'] = 'matches previously rejected or deprecated knowledge';
                    return ['decision' => 'conflict', 'match' => $match];
                }

                return ['decision' => 'duplicate_experience', 'match' => $match];
            }
        }
        foreach ($project as $match) {
            if ((float) ($match['score'] ?? 0.0) >= $projectDuplicate) {
                return ['decision' => 'known_project_knowledge', 'match' => $match];
            }
        }
        foreach ($existing as $match) {
            if ((float) ($match['score'] ?? 0.0) >= $related) {
                return ['decision' => 'enrichment', 'match' => $match];
            }
        }
        foreach ($project as $match) {
            if ((float) ($match['score'] ?? 0.0) >= $related) {
                return ['decision' => 'enrichment', 'match' => $match];
            }
        }

        return ['decision' => 'new', 'match' => []];
    }

    /** @param array<string, mixed> $experience
     *  @return list<array<string, mixed>>
     */
    private function experienceMatches(string $projectId, array $experience): array
    {
        $limit = (int) $this->config->get('analysis.automatic_learning.max_existing_experiences', 100);
        $result = $this->store->searchExperiences($projectId, '', [], [], [], $limit, 0);
        $candidateText = self::experienceText($experience);
        $candidateRule = (string) ($experience['reusable_rule'] ?? '');
        $matches = [];
        foreach ($result['experiences'] as $stored) {
            $storedRule = (string) ($stored['reusable_rule'] ?? '');
            $score = max(
                self::symmetricSimilarity($candidateRule, $storedRule),
                self::symmetricSimilarity($candidateText, self::experienceText($stored)),
            );
            $conflictScore = max(
                self::symmetricSimilarity(self::withoutNegation($candidateRule), self::withoutNegation($storedRule)),
                self::symmetricSimilarity(self::withoutNegation($candidateText), self::withoutNegation(self::experienceText($stored))),
            );
            $conflict = self::opposingPolarity($candidateRule, $storedRule)
                && $conflictScore >= (float) $this->config->get(
                    'analysis.automatic_learning.conflict_similarity',
                    0.62,
                );
            $matches[] = [
                'source' => 'experience',
                'experience_id' => (string) $stored['experience_id'],
                'fingerprint' => (string) $stored['fingerprint'],
                'title' => (string) $stored['title'],
                'category' => (string) $stored['category'],
                'status' => (string) $stored['status'],
                'score' => round($score, 6),
                'conflict_score' => round($conflictScore, 6),
                'conflict' => $conflict,
            ];
        }
        usort($matches, static function (array $left, array $right): int {
            if (($left['conflict'] ?? false) !== ($right['conflict'] ?? false)) {
                return ($right['conflict'] ?? false) <=> ($left['conflict'] ?? false);
            }

            return (float) $right['score'] <=> (float) $left['score'];
        });

        return $matches;
    }

    /** @param array<string, mixed> $session
     *  @param array<string, mixed> $experience
     *  @return array{matches:list<array<string,mixed>>,index_revision:int}
     */
    private function projectMatches(array $session, array $experience): array
    {
        if ($this->config->get('index.enabled', true) !== true) {
            return ['matches' => [], 'index_revision' => 0];
        }
        $index = null;
        try {
            $resolved = ProjectResolver::resolve((string) $session['cwd']);
            if (($resolved['project']['id'] ?? '') !== ($session['project_id'] ?? '')) {
                return ['matches' => [], 'index_revision' => 0];
            }
            $index = new ProjectIndex($this->config, $resolved);
            $status = $index->status();
            if ((int) ($status['counts']['chunks'] ?? 0) === 0) {
                return ['matches' => [], 'index_revision' => $index->revision()];
            }
            $query = Text::truncate((string) ($experience['reusable_rule'] ?? ''), 4_000);
            if (trim($query) === '') {
                return ['matches' => [], 'index_revision' => $index->revision()];
            }
            $limit = (int) $this->config->get('analysis.automatic_learning.max_project_matches', 12);
            $search = (new ProjectRetriever($index, new SparseVectorizer($this->config), $this->config))->search(
                $query,
                [
                    'kinds' => ['code', 'doc', 'rule', 'skill', 'config'],
                    'limit' => $limit,
                    'token_budget' => 4_000,
                    'per_result_token_budget' => 400,
                    'max_chunks_per_file' => 1,
                ],
            );
            $matches = [];
            foreach ($search['results'] as $item) {
                $snippet = (string) ($item['snippet'] ?? '');
                $score = Text::similarity($query, $snippet);
                $conflictScore = Text::similarity(self::withoutNegation($query), self::withoutNegation($snippet));
                $conflict = self::opposingPolarity($query, $snippet)
                    && $conflictScore >= max(
                        0.8,
                        (float) $this->config->get('analysis.automatic_learning.project_duplicate_similarity', 0.9),
                    );
                $matches[] = [
                    'source' => 'project_index',
                    'path' => (string) ($item['relative_path'] ?? ''),
                    'kind' => (string) ($item['file_kind'] ?? ''),
                    'start_line' => (int) ($item['start_line'] ?? 0),
                    'end_line' => (int) ($item['end_line'] ?? 0),
                    'score' => round($score, 6),
                    'conflict_score' => round($conflictScore, 6),
                    'conflict' => $conflict,
                ];
            }
            usort($matches, static function (array $left, array $right): int {
                if (($left['conflict'] ?? false) !== ($right['conflict'] ?? false)) {
                    return ($right['conflict'] ?? false) <=> ($left['conflict'] ?? false);
                }

                return (float) $right['score'] <=> (float) $left['score'];
            });

            return ['matches' => $matches, 'index_revision' => $index->revision()];
        } catch (Throwable $exception) {
            $this->store->writeAudit(
                'automatic-learning',
                'project_knowledge_lookup_failed',
                'session',
                (string) ($session['id'] ?? ''),
                ['reason' => Text::truncate($exception->getMessage(), 500)],
            );

            return ['matches' => [], 'index_revision' => 0];
        } finally {
            $index?->close();
        }
    }

    /** @param array<string, mixed> $experience
     *  @param list<array<string, mixed>> $evidence
     */
    private function strongEvidence(array $experience, array $evidence): bool
    {
        if (($experience['category'] ?? '') === 'temporary_context') {
            return false;
        }
        $metadata = is_array($experience['metadata'] ?? null) ? $experience['metadata'] : [];
        $classification = is_array($metadata['learning_classification'] ?? null)
            ? $metadata['learning_classification']
            : [];
        $knowledgeType = trim((string) ($classification['knowledge_type'] ?? ''));
        $surface = trim((string) ($classification['surface'] ?? ''));
        $environmentConstraints = Text::uniqueStrings(
            is_array($classification['environment_constraints'] ?? null)
                ? $classification['environment_constraints']
                : [],
        );
        $positiveExample = trim((string) ($classification['positive_example'] ?? ''));
        $negativeExample = trim((string) ($classification['negative_example'] ?? ''));
        $normalizeExample = static fn(string $value): string => mb_strtolower(
            preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value),
            'UTF-8',
        );
        if (!in_array($knowledgeType, [
            'global_rule', 'project_rule', 'skill_knowledge', 'operational_observation',
        ], true)
            || $positiveExample === ''
            || $negativeExample === ''
            || $normalizeExample($positiveExample) === $normalizeExample($negativeExample)) {
            return false;
        }
        if ($knowledgeType === 'global_rule') {
            return false;
        }
        if ($knowledgeType === 'operational_observation'
            && ($surface === '' || $environmentConstraints === [])) {
            return false;
        }

        $hasUserIntent = false;
        $hasOutcome = false;
        foreach ($evidence as $item) {
            if (($item['verified'] ?? false) !== true || ($item['polarity'] ?? '') !== 'supports') {
                continue;
            }
            $type = (string) ($item['evidence_type'] ?? '');
            $strength = (float) ($item['strength'] ?? 0.0);
            if ($type === 'user_intent' && $strength >= 0.9) {
                $hasUserIntent = true;
            }
            if (in_array($type, self::OUTCOME_EVIDENCE, true) && $strength >= 0.9) {
                $hasOutcome = true;
            }
        }
        if (in_array($knowledgeType, ['skill_knowledge', 'operational_observation'], true)) {
            return $hasOutcome;
        }

        return in_array((string) ($experience['category'] ?? ''), self::TECHNICAL_CATEGORIES, true)
            ? $hasOutcome
            : $hasUserIntent;
    }

    /** @param array<string, mixed> $experience */
    private static function experienceText(array $experience): string
    {
        $metadata = is_array($experience['metadata'] ?? null) ? $experience['metadata'] : [];
        $classification = is_array($metadata['learning_classification'] ?? null)
            ? $metadata['learning_classification']
            : [];

        return implode(' ', [
            $experience['title'] ?? '',
            $experience['problem_pattern'] ?? '',
            $experience['trigger'] ?? '',
            $experience['correct_approach'] ?? '',
            $experience['reusable_rule'] ?? '',
            $classification['knowledge_type'] ?? '',
            $classification['surface'] ?? '',
            $classification['positive_example'] ?? '',
            $classification['negative_example'] ?? '',
            implode(' ', is_array($classification['environment_constraints'] ?? null)
                ? $classification['environment_constraints']
                : []),
        ]);
    }

    private static function symmetricSimilarity(string $left, string $right): float
    {
        if (trim($left) === '' || trim($right) === '') {
            return 0.0;
        }

        return min(Text::similarity($left, $right), Text::similarity($right, $left));
    }

    private static function opposingPolarity(string $left, string $right): bool
    {
        return self::hasNegation($left) !== self::hasNegation($right);
    }

    private static function hasNegation(string $value): bool
    {
        $value = mb_strtolower($value, 'UTF-8');
        foreach (['禁止', '不得', '不要', '不能', '不应', '切勿', 'never ', 'must not', 'do not', "don't", 'cannot', "can't", 'should not'] as $marker) {
            if (str_contains($value, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function withoutNegation(string $value): string
    {
        return str_ireplace(
            ['禁止', '不得', '不要', '不能', '不应', '切勿', 'never', 'must not', 'do not', "don't", 'cannot', "can't", 'should not'],
            ' ',
            $value,
        );
    }

    /** @param array<string, mixed> $match
     *  @return array<string, mixed>
     */
    private function publicMatch(array $match): array
    {
        if ($match === []) {
            return [];
        }

        return array_intersect_key($match, array_flip([
            'source', 'experience_id', 'title', 'category', 'status', 'path', 'kind',
            'start_line', 'end_line', 'score', 'conflict_score', 'conflict', 'conflict_reason',
        ]));
    }
}
