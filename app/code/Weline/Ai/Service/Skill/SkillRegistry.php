<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Skill;

use Weline\Ai\Interface\SkillProviderInterface;
use Weline\Ai\Model\AiSkill;
use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

final class SkillRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $moduleSkillCache = null;

    public function __construct(
        private readonly ?SkillRepository $repository = null,
        private readonly ?SkillNormalizer $normalizer = null
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function listAvailableSkills(bool $includeInactive = false): array
    {
        $skills = $this->collectModuleSkills();
        try {
            $dbSkills = $this->repository()->listByCode(true);
        } catch (\Throwable $throwable) {
            $dbSkills = [];
            if (\function_exists('w_log_error')) {
                w_log_error('AI skill DB catalog unavailable: ' . $throwable->getMessage());
            }
        }
        foreach ($dbSkills as $code => $skill) {
            if (isset($skills[$code])) {
                continue;
            }
            if (!$includeInactive && (string)($skill['status'] ?? '') !== AiSkill::STATUS_ACTIVE) {
                continue;
            }
            $skills[$code] = $skill;
        }

        \ksort($skills);
        return $skills;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSkill(string $code, bool $includeInactive = true): array
    {
        try {
            $code = $this->normalizer()->normalizeCode($code);
        } catch (\InvalidArgumentException) {
            $code = \trim($code);
        }

        $skills = $this->listAvailableSkills($includeInactive);
        if ($code !== '' && isset($skills[$code])) {
            return $skills[$code];
        }

        return [
            'code' => $code,
            'name' => $code,
            'description' => '',
            'body' => '',
            'normalized_body' => '',
            'body_hash' => '',
            'status' => 'missing',
            'source' => '',
            'source_type' => 'missing',
            'local_path' => '',
            'abs_path' => '',
            'exists' => false,
            'readonly' => true,
        ];
    }

    /**
     * @param list<string> $codes
     * @return list<array<string, mixed>>
     */
    public function resolveSelectedSkills(array $codes): array
    {
        $skills = $this->listAvailableSkills(false);
        $resolved = [];
        foreach ($this->normalizer()->normalizeCodeList($codes) as $code) {
            $skill = $skills[$code] ?? null;
            if (!\is_array($skill) || (string)($skill['status'] ?? '') !== AiSkill::STATUS_ACTIVE) {
                continue;
            }
            $resolved[] = $skill;
        }

        return $resolved;
    }

    /**
     * @param list<string> $codes
     * @return list<array<string, mixed>>
     */
    public function buildSkillSnapshots(array $codes): array
    {
        $snapshots = [];
        foreach ($this->resolveSelectedSkills($codes) as $skill) {
            $snapshots[] = [
                'code' => (string)($skill['code'] ?? ''),
                'name' => (string)($skill['name'] ?? $skill['code'] ?? ''),
                'description' => (string)($skill['description'] ?? ''),
                'source' => (string)($skill['source'] ?? $skill['source_type'] ?? ''),
                'normalized_body' => (string)($skill['normalized_body'] ?? $skill['body'] ?? ''),
                'body_hash' => (string)($skill['body_hash'] ?? ''),
            ];
        }

        return $snapshots;
    }

    public function isReservedCode(string $code): bool
    {
        try {
            $code = $this->normalizer()->normalizeCode($code);
        } catch (\InvalidArgumentException) {
            return false;
        }

        $moduleSkill = $this->collectModuleSkills()[$code] ?? null;
        if (\is_array($moduleSkill)) {
            return true;
        }

        try {
            $dbSkill = $this->repository()->findArrayByCode($code);
        } catch (\Throwable) {
            $dbSkill = null;
        }
        if (!\is_array($dbSkill)) {
            return false;
        }

        return \in_array((string)($dbSkill['source_type'] ?? ''), [AiSkill::SOURCE_SYSTEM, AiSkill::SOURCE_MODULE], true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function collectModuleSkills(): array
    {
        if ($this->moduleSkillCache !== null) {
            return $this->moduleSkillCache;
        }

        $skills = [];
        foreach ($this->collectProviderFiles() as $providerInfo) {
            $provider = $this->loadProvider((string)$providerInfo['file']);
            if (!$provider) {
                continue;
            }
            foreach ($provider->listSkills() as $rawSkill) {
                if (!\is_array($rawSkill)) {
                    continue;
                }
                try {
                    $skill = $this->normalizeProviderSkill($rawSkill, (string)$providerInfo['module']);
                } catch (\Throwable $throwable) {
                    if (\function_exists('w_log_error')) {
                        w_log_error('AI skill provider returned invalid skill: ' . $throwable->getMessage());
                    }
                    continue;
                }
                $code = (string)$skill['code'];
                if ($code !== '' && !isset($skills[$code])) {
                    $skills[$code] = $skill;
                }
            }
        }

        \ksort($skills);
        return $this->moduleSkillCache = $skills;
    }

    /**
     * @return list<array{file:string,module:string}>
     */
    private function collectProviderFiles(): array
    {
        $files = [];
        $seen = [];
        $moduleList = $this->getModuleList();
        $candidateModules = [];

        $extendedBy = [];
        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Ai') ?: [];
        } catch (\Throwable) {
            $extendedBy = [];
        }
        foreach (\array_keys($extendedBy) as $moduleName) {
            $candidateModules[$moduleName] = true;
        }
        foreach (\array_keys($moduleList) as $moduleName) {
            $candidateModules[$moduleName] = true;
        }

        foreach (\array_keys($candidateModules) as $moduleName) {
            $basePath = (string)($moduleList[$moduleName]['base_path'] ?? '');
            if ($basePath === '') {
                continue;
            }
            $dir = \rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR
                . 'extends' . DIRECTORY_SEPARATOR
                . 'module' . DIRECTORY_SEPARATOR
                . 'Weline_Ai' . DIRECTORY_SEPARATOR
                . 'Skill';
            if (!\is_dir($dir)) {
                continue;
            }
            foreach ((@\glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: []) as $file) {
                $real = (string)\realpath((string)$file);
                if ($real === '' || isset($seen[$real])) {
                    continue;
                }
                $seen[$real] = true;
                $files[] = ['file' => $real, 'module' => $moduleName];
            }
        }

        return $files;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getModuleList(): array
    {
        try {
            $modules = Env::getInstance()->getModuleList();
            return \is_array($modules) ? $modules : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadProvider(string $file): ?SkillProviderInterface
    {
        if (!\is_file($file)) {
            return null;
        }
        require_once $file;
        $content = (string)@\file_get_contents($file);
        if ($content === '') {
            return null;
        }
        if (\preg_match('/namespace\s+([^;]+);/m', $content, $namespaceMatches) !== 1
            || \preg_match('/(?:final\s+)?class\s+(\w+)/m', $content, $classMatches) !== 1) {
            return null;
        }

        $className = '\\' . \trim((string)$namespaceMatches[1]) . '\\' . \trim((string)$classMatches[1]);
        if (!\class_exists($className)) {
            return null;
        }

        try {
            $instance = ObjectManager::getInstance($className);
        } catch (\Throwable) {
            $instance = new $className();
        }

        return $instance instanceof SkillProviderInterface ? $instance : null;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeProviderSkill(array $raw, string $moduleName): array
    {
        $code = $this->normalizer()->normalizeCode((string)($raw['code'] ?? ''));
        $body = $this->normalizer()->normalizeBody((string)($raw['body'] ?? $raw['normalized_body'] ?? ''));
        $sourceType = (string)($raw['source_type'] ?? AiSkill::SOURCE_MODULE);
        if (!\in_array($sourceType, [AiSkill::SOURCE_SYSTEM, AiSkill::SOURCE_MODULE], true)) {
            $sourceType = AiSkill::SOURCE_MODULE;
        }

        return [
            'id' => 0,
            'code' => $code,
            'name' => \trim((string)($raw['name'] ?? $code)),
            'description' => \trim((string)($raw['description'] ?? '')),
            'body' => $body,
            'normalized_body' => $body,
            'body_hash' => (string)($raw['body_hash'] ?? $this->normalizer()->hashBody($body)),
            'status' => AiSkill::STATUS_ACTIVE,
            'source' => (string)($raw['source'] ?? ('module:' . $moduleName)),
            'source_type' => $sourceType,
            'source_module' => (string)($raw['source_module'] ?? $moduleName),
            'source_url' => (string)($raw['source_url'] ?? ''),
            'source_platform' => (string)($raw['source_platform'] ?? ''),
            'version' => (string)($raw['version'] ?? ''),
            'tags' => $this->normalizeTags($raw['tags'] ?? []),
            'local_path' => (string)($raw['local_path'] ?? ''),
            'abs_path' => (string)($raw['abs_path'] ?? ''),
            'exists' => true,
            'readonly' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeTags(mixed $raw): array
    {
        if (\is_string($raw)) {
            $raw = \preg_split('/[\s,;]+/', $raw) ?: [];
        }
        if (!\is_array($raw)) {
            return [];
        }

        $tags = [];
        foreach ($raw as $tag) {
            if (!\is_scalar($tag)) {
                continue;
            }
            $tag = \strtolower(\trim((string)$tag));
            $tag = (string)\preg_replace('/[^a-z0-9_-]+/', '-', $tag);
            $tag = \trim($tag, '-_');
            if ($tag !== '' && !\in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    private function repository(): SkillRepository
    {
        return $this->repository ?? ObjectManager::getInstance(SkillRepository::class);
    }

    private function normalizer(): SkillNormalizer
    {
        return $this->normalizer ?? new SkillNormalizer();
    }
}
