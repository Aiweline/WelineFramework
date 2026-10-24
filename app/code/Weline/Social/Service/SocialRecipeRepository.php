<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use InvalidArgumentException;
use JsonException;

/**
 * 社媒发布配方仓储：只从仓库 recipes/*.json 读取，不依赖 ObjectManager / DB。
 */
final class SocialRecipeRepository
{
    public const KINDS = ['text', 'image', 'video'];

    private string $recipeDir;

    public function __construct(?string $recipeDir = null)
    {
        $this->recipeDir = \rtrim($recipeDir ?? \dirname(__DIR__, 5) . '/recipes', '/\\');
    }

    public function recipeDir(): string
    {
        return $this->recipeDir;
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $platform, string $kind): array
    {
        $platform = \strtolower(\trim($platform));
        $kind = \strtolower(\trim($kind));
        if (\preg_match('/^[a-z0-9_-]+$/', $platform) !== 1 || !\in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('recipe_invalid');
        }

        return $this->loadFile($this->recipeDir . '/' . $platform . '.' . $kind . '.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function loadFile(string $path): array
    {
        if (!\is_file($path) || !\is_readable($path)) {
            throw new InvalidArgumentException('recipe_invalid');
        }

        try {
            $recipe = \json_decode((string)\file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('recipe_invalid');
        }

        if (!\is_array($recipe)) {
            throw new InvalidArgumentException('recipe_invalid');
        }

        return $this->validate($recipe);
    }

    /**
     * @param array<string, mixed> $recipe
     * @return array<string, mixed>
     */
    public function validate(array $recipe): array
    {
        if (!$this->isValid($recipe)) {
            throw new InvalidArgumentException('recipe_invalid');
        }

        return $recipe;
    }

    /**
     * @param array<string, mixed> $recipe
     */
    public function isValid(array $recipe): bool
    {
        $platform = $recipe['platform'] ?? null;
        if (!\is_string($platform) || \trim($platform) === '') {
            return false;
        }

        $kind = $recipe['kind'] ?? null;
        if (!\is_string($kind) || !\in_array($kind, self::KINDS, true)) {
            return false;
        }

        $version = $recipe['version'] ?? null;
        if (!\is_int($version) || $version < 1) {
            return false;
        }

        $entry = $recipe['entry'] ?? null;
        if (!\is_string($entry) || !$this->isHttpsEntry($entry)) {
            return false;
        }

        $fields = $recipe['fields'] ?? null;
        if (!\is_array($fields) || !\array_is_list($fields)) {
            return false;
        }
        foreach ($fields as $field) {
            if (!\is_array($field) || \array_is_list($field)) {
                return false;
            }
            if (!\is_string($field['key'] ?? null) || \trim($field['key']) === '') {
                return false;
            }
            if (!\is_string($field['role'] ?? null) || \trim($field['role']) === '') {
                return false;
            }
            if (isset($field['anchor']) && (!\is_array($field['anchor']) || !\array_is_list($field['anchor']))) {
                return false;
            }
        }

        $verify = $recipe['verify'] ?? null;
        if (!\is_array($verify) || \array_is_list($verify)) {
            return false;
        }
        $urlMatches = $verify['url_matches'] ?? null;
        $textAny = $verify['text_any'] ?? null;
        $hasUrl = \is_string($urlMatches) && \trim($urlMatches) !== '';
        $hasText = \is_array($textAny) && \array_is_list($textAny) && $textAny !== [];
        if ($hasText) {
            foreach ($textAny as $text) {
                if (!\is_string($text) || \trim($text) === '') {
                    $hasText = false;
                    break;
                }
            }
        }
        if (!$hasUrl && !$hasText) {
            return false;
        }

        $giveUp = $recipe['give_up'] ?? null;
        if (!\is_array($giveUp) || !\array_is_list($giveUp)) {
            return false;
        }
        foreach ($giveUp as $reason) {
            if (!\is_string($reason) || \trim($reason) === '') {
                return false;
            }
        }

        if (isset($recipe['media'])) {
            $media = $recipe['media'];
            if (!\is_array($media) || \array_is_list($media)) {
                return false;
            }
            $accept = $media['accept'] ?? [];
            if (!\is_array($accept) || !\array_is_list($accept)) {
                return false;
            }
            $max = $media['max'] ?? 0;
            if (!\is_int($max) || $max < 0) {
                return false;
            }
        }

        if (isset($recipe['branches'])) {
            $branches = $recipe['branches'];
            if (!\is_array($branches) || !\array_is_list($branches)) {
                return false;
            }
            foreach ($branches as $branch) {
                if (!\is_array($branch) || \array_is_list($branch)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $files = \glob($this->recipeDir . '/*.json');
        if ($files === false) {
            return [];
        }
        \sort($files);

        $recipes = [];
        foreach ($files as $file) {
            $recipes[] = $this->loadFile($file);
        }

        return $recipes;
    }

    private function isHttpsEntry(string $entry): bool
    {
        $entry = \trim($entry);
        if ($entry === '' || \preg_match('/\s/', $entry) === 1) {
            return false;
        }

        $parts = \parse_url($entry);

        return \is_array($parts)
            && \strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && \trim((string)($parts['host'] ?? '')) !== '';
    }
}
