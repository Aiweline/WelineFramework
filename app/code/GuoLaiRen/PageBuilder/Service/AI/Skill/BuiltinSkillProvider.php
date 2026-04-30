<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Skill;

final class BuiltinSkillProvider
{
    public const SKILLS_RELATIVE_ROOT = 'app/code/GuoLaiRen/PageBuilder/skills';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $skillCache = null;

    public function __construct(
        private readonly ?SkillNormalizer $normalizer = null
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function listSkills(): array
    {
        if ($this->skillCache !== null) {
            return $this->skillCache;
        }

        $absRoot = $this->resolveSkillsAbsoluteRoot();
        $result = [];
        if ($absRoot !== '' && \is_dir($absRoot)) {
            $entries = @\scandir($absRoot) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $skillDir = $absRoot . DIRECTORY_SEPARATOR . $entry;
                if (!\is_dir($skillDir)) {
                    continue;
                }
                $skillFile = $skillDir . DIRECTORY_SEPARATOR . 'SKILL.md';
                if (!\is_file($skillFile)) {
                    continue;
                }
                $code = (string)$entry;
                $contents = (string)@\file_get_contents($skillFile);
                $front = $this->parseSkillFrontmatter($contents);
                $body = $this->extractSkillBody($contents);
                $normalizedBody = '';
                $bodyHash = '';
                if ($body !== '') {
                    try {
                        $normalizedBody = $this->normalizer()->normalizeBody($body);
                        $bodyHash = $this->normalizer()->hashBody($normalizedBody);
                    } catch (\InvalidArgumentException) {
                        $normalizedBody = \trim(\str_replace(["\r\n", "\r"], "\n", $body));
                    }
                }
                $localPath = self::SKILLS_RELATIVE_ROOT . '/' . $code . '/SKILL.md';
                $result[$code] = [
                    'code' => $code,
                    'name' => (string)($front['name'] ?? $code),
                    'description' => (string)($front['description'] ?? ''),
                    'body' => $body,
                    'normalized_body' => $normalizedBody,
                    'body_hash' => $bodyHash,
                    'status' => 'active',
                    'source' => 'builtin_file',
                    'local_path' => $localPath,
                    'abs_path' => $skillFile,
                    'exists' => true,
                ];
            }
        }
        \ksort($result);

        return $this->skillCache = $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSkill(string $code): ?array
    {
        $code = \trim($code);
        $skills = $this->listSkills();

        return $code !== '' && isset($skills[$code]) ? $skills[$code] : null;
    }

    private function normalizer(): SkillNormalizer
    {
        return $this->normalizer ?? new SkillNormalizer();
    }

    /**
     * @return array{name?:string,description?:string}
     */
    private function parseSkillFrontmatter(string $contents): array
    {
        if ($contents === '') {
            return [];
        }
        $contents = \ltrim($contents);
        if (!\str_starts_with($contents, '---')) {
            return [];
        }
        $end = \strpos($contents, "\n---", 3);
        if ($end === false) {
            return [];
        }
        $front = \substr($contents, 3, $end - 3);
        $front = \str_replace(["\r\n", "\r"], "\n", $front);
        $lines = \explode("\n", $front);

        $result = [];
        $currentKey = '';
        $accumulator = '';
        foreach ($lines as $rawLine) {
            $line = \rtrim($rawLine);
            if ($line === '') {
                continue;
            }
            if (\preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*)$/u', $line, $m) === 1) {
                if ($currentKey !== '') {
                    $result[$currentKey] = \trim($accumulator);
                }
                $currentKey = (string)$m[1];
                $value = (string)$m[2];
                $accumulator = \in_array($value, ['>-', '>', '|'], true) ? '' : $value;
            } else {
                $accumulator .= ' ' . \trim($line);
            }
        }
        if ($currentKey !== '') {
            $result[$currentKey] = \trim($accumulator);
        }

        $clean = [];
        if (isset($result['name'])) {
            $clean['name'] = $this->stripQuotes((string)$result['name']);
        }
        if (isset($result['description'])) {
            $clean['description'] = $this->stripQuotes((string)$result['description']);
        }

        return $clean;
    }

    private function extractSkillBody(string $contents): string
    {
        $contents = \str_replace(["\r\n", "\r"], "\n", $contents);
        $trimmed = \ltrim($contents);
        if (!\str_starts_with($trimmed, '---')) {
            return $contents;
        }
        $end = \strpos($trimmed, "\n---", 3);
        if ($end === false) {
            return $contents;
        }

        return \ltrim(\substr($trimmed, $end + 4));
    }

    private function stripQuotes(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        if ((\str_starts_with($value, '"') && \str_ends_with($value, '"'))
            || (\str_starts_with($value, "'") && \str_ends_with($value, "'"))) {
            return \trim(\substr($value, 1, -1));
        }

        return $value;
    }

    private function resolveSkillsAbsoluteRoot(): string
    {
        if (\defined('BP')) {
            $base = (string)\constant('BP');
            if ($base !== '') {
                return \rtrim($base, "\\/") . DIRECTORY_SEPARATOR
                    . \str_replace('/', DIRECTORY_SEPARATOR, self::SKILLS_RELATIVE_ROOT);
            }
        }

        return \dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'skills';
    }
}
