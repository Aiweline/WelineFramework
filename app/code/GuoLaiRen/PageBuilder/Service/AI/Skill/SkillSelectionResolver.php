<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Skill;

final class SkillSelectionResolver
{
    public const DEFAULT_SKILL_CODES = ['claude-design'];

    public function __construct(
        private readonly ?BuiltinSkillProvider $builtinProvider = null,
        private readonly ?CustomSkillProvider $customProvider = null
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function listAvailableSkills(): array
    {
        $builtin = $this->builtinProvider()->listSkills();
        $custom = $this->customProvider()->listSkills();

        foreach ($custom as $code => $skill) {
            if (isset($builtin[$code])) {
                continue;
            }
            $builtin[$code] = $skill;
        }
        \ksort($builtin);

        return $builtin;
    }

    /**
     * @param list<string> $selectedCodes
     * @return list<string>
     */
    public function resolveCodes(array $selectedCodes): array
    {
        $codes = $selectedCodes === [] ? self::DEFAULT_SKILL_CODES : $selectedCodes;
        $resolved = [];
        foreach ($codes as $code) {
            $code = \trim((string)$code);
            if ($code === '' || \in_array($code, $resolved, true)) {
                continue;
            }
            $resolved[] = $code;
        }

        return $resolved === [] ? self::DEFAULT_SKILL_CODES : $resolved;
    }

    /**
     * @param list<string> $selectedCodes
     * @return list<array<string, mixed>>
     */
    public function resolveSelectedSkills(array $selectedCodes): array
    {
        $available = $this->listAvailableSkills();
        $skills = [];
        foreach ($this->resolveCodes($selectedCodes) as $code) {
            $skill = $available[$code] ?? null;
            if (!\is_array($skill) || !($skill['exists'] ?? false)) {
                throw new \InvalidArgumentException('Skill "' . $code . '" does not exist.');
            }
            if ((string)($skill['status'] ?? 'active') !== 'active') {
                throw new \InvalidArgumentException('Skill "' . $code . '" is disabled and cannot be selected.');
            }
            $skills[] = $skill;
        }

        return $skills;
    }

    private function builtinProvider(): BuiltinSkillProvider
    {
        return $this->builtinProvider ?? new BuiltinSkillProvider();
    }

    private function customProvider(): CustomSkillProvider
    {
        return $this->customProvider ?? new CustomSkillProvider();
    }
}
