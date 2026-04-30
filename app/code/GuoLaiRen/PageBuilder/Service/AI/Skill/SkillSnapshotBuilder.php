<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Skill;

final class SkillSnapshotBuilder
{
    public function __construct(
        private readonly ?SkillSelectionResolver $resolver = null,
        private readonly ?SkillNormalizer $normalizer = null
    ) {
    }

    /**
     * @param list<string> $selectedCodes
     * @return list<array{code:string,name:string,description:string,source:string,normalized_body:string,body_hash:string}>
     */
    public function buildSnapshots(array $selectedCodes): array
    {
        $snapshots = [];
        foreach ($this->resolver()->resolveSelectedSkills($selectedCodes) as $skill) {
            $normalizedBody = (string)($skill['normalized_body'] ?? '');
            if ($normalizedBody === '') {
                $normalizedBody = $this->normalizer()->normalizeBody((string)($skill['body'] ?? ''));
            }
            $hash = (string)($skill['body_hash'] ?? '');
            if ($hash === '') {
                $hash = $this->normalizer()->hashBody($normalizedBody);
            }
            $snapshots[] = [
                'code' => (string)$skill['code'],
                'name' => (string)($skill['name'] ?? $skill['code']),
                'description' => (string)($skill['description'] ?? ''),
                'source' => (string)($skill['source'] ?? ''),
                'normalized_body' => $normalizedBody,
                'body_hash' => $hash,
            ];
        }

        return $snapshots;
    }

    private function resolver(): SkillSelectionResolver
    {
        return $this->resolver ?? new SkillSelectionResolver();
    }

    private function normalizer(): SkillNormalizer
    {
        return $this->normalizer ?? new SkillNormalizer();
    }
}
