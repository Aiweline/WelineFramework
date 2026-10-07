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
                        'reason' => (string) ($match['conflict_reason'] ?? 'Same-topic opposing reusable rules matched above the conflict gate.'),
                        'score' => (float) ($match['conflict_score'] ?? $match['score'] ?? 0.0),
                        'topic_overlap' => (float) ($match['topic_overlap'] ?? 0.0),
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

        // Similarity search may still rank near-misses; only same-topic opposing
        // claims (or reconsidering rejected/deprecated knowledge) may contest.
        foreach ($existing as $match) {
            if (self::isActionableConflictMatch($match)) {
                return ['decision' => 'conflict', 'match' => $match];
            }
        }
        foreach ($project as $match) {
            if (self::isActionableConflictMatch($match)) {
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
        $conflictSimilarity = (float) $this->config->get(
            'analysis.automatic_learning.conflict_similarity',
            0.72,
        );
        $topicOverlapMin = (float) $this->config->get(
            'analysis.automatic_learning.conflict_topic_overlap',
            0.32,
        );
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
            $conflictJudgment = self::judgeExperienceConflict(
                $experience,
                $stored,
                $conflictScore,
                $conflictSimilarity,
                $topicOverlapMin,
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
                'topic_overlap' => (float) ($conflictJudgment['topic_overlap'] ?? 0.0),
                'conflict' => (bool) ($conflictJudgment['conflict'] ?? false),
                'conflict_reason' => (string) ($conflictJudgment['reason'] ?? ''),
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
                $topicOverlap = self::topicOverlap($query, $snippet);
                $similarityFloor = max(
                    0.8,
                    (float) $this->config->get('analysis.automatic_learning.project_duplicate_similarity', 0.9),
                );
                $topicMin = (float) $this->config->get(
                    'analysis.automatic_learning.conflict_topic_overlap',
                    0.32,
                );
                // Project-index: search/compare OK; contest only with shared topic tokens.
                $conflict = self::opposingPolarity($query, $snippet)
                    && $conflictScore >= $similarityFloor
                    && $topicOverlap >= $topicMin
                    && self::sharedContentTokenCount($query, $snippet) >= 3;
                $matches[] = [
                    'source' => 'project_index',
                    'path' => (string) ($item['relative_path'] ?? ''),
                    'kind' => (string) ($item['file_kind'] ?? ''),
                    'start_line' => (int) ($item['start_line'] ?? 0),
                    'end_line' => (int) ($item['end_line'] ?? 0),
                    'score' => round($score, 6),
                    'conflict_score' => round($conflictScore, 6),
                    'topic_overlap' => round($topicOverlap, 6),
                    'conflict' => $conflict,
                    'conflict_reason' => $conflict
                        ? 'project_index_same_topic_opposing_polarity'
                        : '',
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

    /**
     * Auto-conflict only when claims share a topic AND oppose each other.
     * Polarity + bag-of-words similarity alone produced unrelated false positives
     * (e.g. FPC debugging vs deprecated-brand guidance).
     *
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $stored
     * @return array{conflict:bool,reason:string,topic_overlap:float}
     */
    public static function judgeExperienceConflict(
        array $candidate,
        array $stored,
        float $conflictScore,
        float $conflictSimilarity,
        float $topicOverlapMin,
    ): array {
        $candidateRule = (string) ($candidate['reusable_rule'] ?? '');
        $storedRule = (string) ($stored['reusable_rule'] ?? '');
        $topicOverlap = self::topicOverlap(
            self::topicText($candidate),
            self::topicText($stored),
        );
        if ($conflictScore < $conflictSimilarity) {
            return [
                'conflict' => false,
                'reason' => 'below_conflict_similarity',
                'topic_overlap' => round($topicOverlap, 6),
            ];
        }
        if (!self::opposingPolarity($candidateRule, $storedRule)) {
            return [
                'conflict' => false,
                'reason' => 'same_polarity',
                'topic_overlap' => round($topicOverlap, 6),
            ];
        }
        $candidateCategory = trim((string) ($candidate['category'] ?? ''));
        $storedCategory = trim((string) ($stored['category'] ?? ''));
        if ($candidateCategory !== '' && $storedCategory !== '' && $candidateCategory !== $storedCategory) {
            return [
                'conflict' => false,
                'reason' => 'category_mismatch',
                'topic_overlap' => round($topicOverlap, 6),
            ];
        }
        if ($topicOverlap < $topicOverlapMin) {
            return [
                'conflict' => false,
                'reason' => 'topic_misaligned',
                'topic_overlap' => round($topicOverlap, 6),
            ];
        }
        if (self::sharedContentTokenCount(self::topicText($candidate), self::topicText($stored)) < 3) {
            return [
                'conflict' => false,
                'reason' => 'insufficient_shared_topic_tokens',
                'topic_overlap' => round($topicOverlap, 6),
            ];
        }

        return [
            'conflict' => true,
            'reason' => 'same_topic_opposing_polarity',
            'topic_overlap' => round($topicOverlap, 6),
        ];
    }

    /**
     * Fail-closed: lexical similarity alone must never become a contested mark.
     *
     * @param array<string, mixed> $match
     */
    public static function isActionableConflictMatch(array $match): bool
    {
        if (($match['conflict'] ?? false) !== true) {
            return false;
        }
        $reason = trim((string) ($match['conflict_reason'] ?? ''));

        return in_array($reason, [
            'same_topic_opposing_polarity',
            'project_index_same_topic_opposing_polarity',
            'matches previously rejected or deprecated knowledge',
        ], true);
    }

    /** @param array<string, mixed> $experience */
    private static function topicText(array $experience): string
    {
        return implode(' ', [
            $experience['title'] ?? '',
            $experience['problem_pattern'] ?? '',
            $experience['trigger'] ?? '',
            $experience['reusable_rule'] ?? '',
        ]);
    }

    public static function topicOverlap(string $left, string $right): float
    {
        $leftTokens = self::contentTokens($left);
        $rightTokens = self::contentTokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }
        $leftSet = array_fill_keys($leftTokens, true);
        $rightSet = array_fill_keys($rightTokens, true);
        $intersection = 0;
        foreach ($leftSet as $token => $_true) {
            if (isset($rightSet[$token])) {
                ++$intersection;
            }
        }
        $union = count($leftSet) + count($rightSet) - $intersection;

        return $union > 0 ? $intersection / $union : 0.0;
    }

    public static function sharedContentTokenCount(string $left, string $right): int
    {
        $leftSet = array_fill_keys(self::contentTokens($left), true);
        $rightSet = array_fill_keys(self::contentTokens($right), true);
        $shared = 0;
        foreach ($leftSet as $token => $_true) {
            if (isset($rightSet[$token])) {
                ++$shared;
            }
        }

        return $shared;
    }

    /**
     * Content tokens for topic gating. Strips negation and generic boilerplate so
     * "do not … framework path" does not fake-overlap unrelated project rules.
     *
     * @return list<string>
     */
    public static function contentTokens(string $value): array
    {
        $value = self::withoutNegation(mb_strtolower($value, 'UTF-8'));
        if (preg_match_all('/[a-z][a-z0-9_+-]{2,}|[\x{4e00}-\x{9fff}]{2,}/u', $value, $matches) < 1) {
            return [];
        }
        $stop = [
            'the', 'and', 'for', 'with', 'from', 'into', 'that', 'this', 'when', 'then',
            'before', 'after', 'as', 'into', 'onto', 'over', 'under', 'than', 'also',
            'project', 'framework', 'runtime', 'signal', 'treat', 'usage', 'recommendation',
            'recommended', 'guidance', 'instructions', 'rule', 'rules', 'path', 'paths',
            'should', 'must', 'will', 'can', 'may', 'only', 'same', 'other', 'elsewhere',
            'first', 'later', 'using', 'used', 'use', 'how', 'what', 'where', 'which',
            'ai', 'agent', 'session', 'knowledge', 'experience',
        ];
        $tokens = [];
        foreach ($matches[0] as $token) {
            if (in_array($token, $stop, true)) {
                continue;
            }
            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
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
            'start_line', 'end_line', 'score', 'conflict_score', 'topic_overlap',
            'conflict', 'conflict_reason',
        ]));
    }
}
