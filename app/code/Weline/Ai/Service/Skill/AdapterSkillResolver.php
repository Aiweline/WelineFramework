<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Skill;

use Weline\Ai\Interface\AdapterSkillBindingInterface;
use Weline\Ai\Model\AiSkill;
use Weline\Ai\Service\AdapterScanner;
use Weline\Framework\Manager\ObjectManager;

final class AdapterSkillResolver
{
    public function __construct(
        private readonly ?AdapterScanner $adapterScanner = null,
        private readonly ?SkillRegistry $skillRegistry = null,
        private readonly ?AdapterSkillRepository $bindingRepository = null,
        private readonly ?SkillNormalizer $normalizer = null
    ) {
    }

    /**
     * @return list<string>
     */
    public function getLockedSkillCodes(string $adapterCode): array
    {
        $adapter = $this->adapterScanner()->getAdapter($adapterCode);
        if (!$adapter instanceof AdapterSkillBindingInterface) {
            return [];
        }

        return $this->normalizer()->normalizeCodeList($adapter->getDefaultSkillCodes());
    }

    /**
     * @param list<string> $temporarySkillCodes
     * @return array{codes:list<string>,items:list<array<string,mixed>>,warnings:list<string>}
     */
    public function resolveSkillBindings(string $adapterCode, array $temporarySkillCodes = []): array
    {
        $skills = $this->skillRegistry()->listAvailableSkills(false);
        $lockedCodes = $this->getLockedSkillCodes($adapterCode);
        $manualCodes = $this->bindingRepository()->listActiveSkillCodes($adapterCode);
        $temporaryCodes = $this->normalizer()->normalizeCodeList($temporarySkillCodes);

        $itemsByCode = [];
        $warnings = [];

        foreach ($lockedCodes as $code) {
            $skill = $skills[$code] ?? null;
            if (!\is_array($skill) || (string)($skill['status'] ?? '') !== AiSkill::STATUS_ACTIVE) {
                throw new \RuntimeException('Locked adapter skill "' . $code . '" is missing or inactive for adapter "' . $adapterCode . '".');
            }
            $itemsByCode[$code] = $this->decorate($skill, true, false, false, 'locked');
        }

        foreach ($manualCodes as $code) {
            $skill = $skills[$code] ?? null;
            if (!\is_array($skill) || (string)($skill['status'] ?? '') !== AiSkill::STATUS_ACTIVE) {
                $warnings[] = 'Manual adapter skill "' . $code . '" is missing or inactive and was skipped.';
                continue;
            }
            $existing = $itemsByCode[$code] ?? $skill;
            $itemsByCode[$code] = $this->decorate($existing, !empty($existing['locked']), true, !empty($existing['temporary']), !empty($existing['locked']) ? 'locked' : 'manual');
        }

        foreach ($temporaryCodes as $code) {
            $skill = $skills[$code] ?? null;
            if (!\is_array($skill) || (string)($skill['status'] ?? '') !== AiSkill::STATUS_ACTIVE) {
                $warnings[] = 'Temporary skill "' . $code . '" is missing or inactive and was skipped.';
                continue;
            }
            $existing = $itemsByCode[$code] ?? $skill;
            $source = !empty($existing['locked']) ? 'locked' : (!empty($existing['manual']) ? 'manual' : 'temporary');
            $itemsByCode[$code] = $this->decorate($existing, !empty($existing['locked']), !empty($existing['manual']), true, $source);
        }

        return [
            'codes' => \array_values(\array_keys($itemsByCode)),
            'items' => \array_values($itemsByCode),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param list<string> $temporarySkillCodes
     * @return array{items:list<array<string,mixed>>,default_skill_codes:list<string>,warnings:list<string>}
     */
    public function buildSkillCatalog(string $adapterCode, array $temporarySkillCodes = [], bool $includeInactive = false): array
    {
        $skills = $this->skillRegistry()->listAvailableSkills($includeInactive);
        $lockedCodes = $this->getLockedSkillCodes($adapterCode);
        $manualCodes = $this->bindingRepository()->listActiveSkillCodes($adapterCode);
        $temporaryCodes = $this->normalizer()->normalizeCodeList($temporarySkillCodes);
        $warnings = [];
        $itemsByCode = [];

        foreach ($skills as $code => $skill) {
            $locked = \in_array($code, $lockedCodes, true);
            $manual = \in_array($code, $manualCodes, true);
            $temporary = \in_array($code, $temporaryCodes, true);
            $source = $locked ? 'locked' : ($manual ? 'manual' : ($temporary ? 'temporary' : ''));
            $itemsByCode[$code] = $this->decorate($skill, $locked, $manual, $temporary, $source);
        }

        foreach ($lockedCodes as $code) {
            if (isset($itemsByCode[$code])) {
                continue;
            }
            $itemsByCode[$code] = $this->decorate([
                'code' => $code,
                'name' => $code,
                'description' => '',
                'status' => 'missing',
                'source' => '',
                'source_type' => 'missing',
                'body_hash' => '',
                'local_path' => '',
                'exists' => false,
                'readonly' => true,
            ], true, false, false, 'locked');
            $warnings[] = 'Locked adapter skill "' . $code . '" is missing.';
        }

        \ksort($itemsByCode);
        return [
            'items' => \array_values($itemsByCode),
            'default_skill_codes' => $lockedCodes,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    public function buildPromptGuideText(array $items): string
    {
        $lines = [];
        $lines[] = '';
        $lines[] = 'AI ADAPTER SKILL CAPABILITY:';
        $lines[] = '- The following AI skills are active for this adapter call. Apply them as higher-priority operating rules for planning, coding, and HTML generation.';
        foreach ($items as $item) {
            $code = \trim((string)($item['code'] ?? ''));
            $body = \trim((string)($item['normalized_body'] ?? $item['body'] ?? ''));
            if ($code === '' || $body === '') {
                continue;
            }
            $kind = !empty($item['locked']) ? 'locked' : (!empty($item['manual']) ? 'manual' : (!empty($item['temporary']) ? 'temporary' : 'skill'));
            $lines[] = '';
            $lines[] = 'ADAPTER SKILL RULES BEGIN code=' . $code . ' binding=' . $kind;
            $lines[] = '- Skill name: ' . \trim((string)($item['name'] ?? $code));
            $description = \trim((string)($item['description'] ?? ''));
            if ($description !== '') {
                $lines[] = '- Skill summary: ' . $this->compact($description, 360);
            }
            $hash = \trim((string)($item['body_hash'] ?? ''));
            if ($hash !== '') {
                $lines[] = '- Skill body hash: ' . $hash;
            }
            $lines[] = $this->compact($body, 6000);
            $lines[] = 'ADAPTER SKILL RULES END code=' . $code;
        }

        return \trim(\implode("\n", $lines));
    }

    /**
     * @param array<string,mixed> $skill
     * @return array<string,mixed>
     */
    private function decorate(array $skill, bool $locked, bool $manual, bool $temporary, string $bindingSource): array
    {
        $status = (string)($skill['status'] ?? 'active');
        $exists = !empty($skill['exists']);
        $skill['locked'] = $locked;
        $skill['manual'] = $manual;
        $skill['temporary'] = $temporary;
        $skill['binding_source'] = $bindingSource;
        $skill['selectable'] = $exists && $status === AiSkill::STATUS_ACTIVE && !$locked && !$manual;
        $skill['readonly'] = !empty($skill['readonly']) || $locked || \in_array((string)($skill['source_type'] ?? ''), [AiSkill::SOURCE_SYSTEM, AiSkill::SOURCE_MODULE], true);

        return $skill;
    }

    private function compact(string $text, int $max): string
    {
        $text = \trim((string)\preg_replace('/\R/u', "\n", $text));
        if (\function_exists('mb_strlen') && \mb_strlen($text) > $max) {
            return \mb_substr($text, 0, $max - 3) . '...';
        }
        if (\strlen($text) > $max) {
            return \substr($text, 0, $max - 3) . '...';
        }

        return $text;
    }

    private function adapterScanner(): AdapterScanner
    {
        return $this->adapterScanner ?? ObjectManager::getInstance(AdapterScanner::class);
    }

    private function skillRegistry(): SkillRegistry
    {
        return $this->skillRegistry ?? ObjectManager::getInstance(SkillRegistry::class);
    }

    private function bindingRepository(): AdapterSkillRepository
    {
        return $this->bindingRepository ?? ObjectManager::getInstance(AdapterSkillRepository::class);
    }

    private function normalizer(): SkillNormalizer
    {
        return $this->normalizer ?? new SkillNormalizer();
    }
}
