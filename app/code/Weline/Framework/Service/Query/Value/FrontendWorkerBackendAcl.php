<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Value;

use Weline\Framework\Authorization\Resource\SourceIdParser;

/** Immutable descriptor parser for backend Worker ACL policy. */
final class FrontendWorkerBackendAcl
{
    public const KIND_SOURCE = 'source';
    public const KIND_PARAM_MAP = 'param_map';
    public const KIND_SELF = 'self';

    private const SOURCE_PATTERN = '/^[A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9]*::[A-Za-z0-9][A-Za-z0-9_.:-]{0,126}$/D';
    private const PARAM_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/D';
    private const FORBIDDEN_SELF_PARAMS = [
        'actor_id', 'backend_user_id', 'principal', 'role_id', 'session_id', 'user_id', 'website_id',
    ];

    /**
     * @param mixed $policy
     * @param mixed $paramsDescriptor
     * @param list<string> $defaultTags
     * @return array{kind:string,source_id?:string,param?:string,map?:array<string,string>}
     */
    public static function normalize(
        mixed $policy,
        mixed $paramsDescriptor = [],
        string $defaultModule = '',
        array $defaultTags = [],
    ): array {
        if (!\is_array($policy) || \array_is_list($policy)) {
            throw new \InvalidArgumentException('Backend ACL policy must be an object map.');
        }
        $kind = $policy['kind'] ?? null;
        if (!\is_string($kind)) {
            throw new \InvalidArgumentException('Backend ACL policy kind is invalid.');
        }

        if ($kind === self::KIND_SOURCE) {
            return self::normalizeSource($policy, $defaultModule, $defaultTags);
        }

        if ($kind === self::KIND_PARAM_MAP) {
            return self::normalizeParamMap($policy, $paramsDescriptor, $defaultModule, $defaultTags);
        }

        if ($kind === self::KIND_SELF) {
            self::assertExactKeys($policy, ['kind']);
            $paramNames = self::paramNames($paramsDescriptor);
            if (\array_intersect(self::FORBIDDEN_SELF_PARAMS, $paramNames) !== []) {
                throw new \InvalidArgumentException('Backend self ACL operation exposes a subject selector.');
            }
            foreach ($paramNames as $paramName) {
                if (\preg_match(
                    '/(?:^|_)(?:actor|backend_user|owner|principal|role|session|subject|user|website)(?:_id)?(?:_|$)/D',
                    $paramName,
                ) === 1) {
                    throw new \InvalidArgumentException('Backend self ACL operation exposes a subject selector.');
                }
            }
            return ['kind' => $kind];
        }

        throw new \InvalidArgumentException('Backend ACL policy kind is unsupported.');
    }

    /** @param array<string,mixed> $policy @param array<string,mixed> $params */
    public static function resolveSourceId(array $policy, array $params): ?string
    {
        $selector = $policy['param'] ?? null;
        $paramsDescriptor = \is_string($selector) ? [$selector => []] : [];
        // Runtime policies are already normalized to string source_id / string map.
        $normalized = self::normalize($policy, $paramsDescriptor);
        if ($normalized['kind'] === self::KIND_SELF) {
            return null;
        }
        if ($normalized['kind'] === self::KIND_SOURCE) {
            return $normalized['source_id'];
        }

        $param = $normalized['param'];
        $value = $params[$param] ?? null;
        if (!\is_string($value) && !\is_int($value)) {
            throw new \InvalidArgumentException('Backend ACL selector parameter is invalid.');
        }
        $sourceId = $normalized['map'][(string)$value] ?? null;
        if (!\is_string($sourceId)) {
            throw new \InvalidArgumentException('Backend ACL selector is not allowlisted.');
        }
        return $sourceId;
    }

    public static function isValidSourceId(string $sourceId): bool
    {
        return \strlen($sourceId) <= 127 && \preg_match(self::SOURCE_PATTERN, $sourceId) === 1;
    }

    /**
     * @param array<string,mixed> $policy
     * @param list<string> $defaultTags
     * @return array{kind:string,source_id:string}
     */
    private static function normalizeSource(array $policy, string $defaultModule, array $defaultTags): array
    {
        $hasSourceId = \array_key_exists('source_id', $policy);
        $hasCode = \array_key_exists('code', $policy);
        $allowed = ['kind'];
        if ($hasSourceId) {
            $allowed[] = 'source_id';
        }
        if ($hasCode) {
            $allowed[] = 'code';
        }
        if (\array_key_exists('tags', $policy)) {
            $allowed[] = 'tags';
        }
        if (\array_key_exists('module', $policy)) {
            $allowed[] = 'module';
        }
        self::assertExactKeys($policy, $allowed);

        if ($hasSourceId && !$hasCode) {
            $sourceId = $policy['source_id'] ?? null;
            if (!\is_string($sourceId) || !self::isValidSourceId($sourceId)) {
                throw new \InvalidArgumentException('Backend ACL source_id is invalid.');
            }
            if (\array_key_exists('tags', $policy) || \array_key_exists('module', $policy)) {
                throw new \InvalidArgumentException(
                    'Backend ACL source_id cannot be combined with tags/module without code.',
                );
            }
            return ['kind' => self::KIND_SOURCE, 'source_id' => $sourceId];
        }

        if ($hasCode) {
            $code = $policy['code'] ?? null;
            if (!\is_string($code) || \trim($code) === '') {
                throw new \InvalidArgumentException('Backend ACL code is invalid.');
            }
            $module = \trim((string)($policy['module'] ?? $defaultModule));
            $tags = SourceIdParser::mergeTags(
                $defaultTags,
                self::normalizeTagList($policy['tags'] ?? null),
            );
            $composed = SourceIdParser::compose($module, $tags, \trim($code));
            if (!self::isValidSourceId($composed)) {
                throw new \InvalidArgumentException('Backend ACL composed source_id is invalid or too long.');
            }
            if ($hasSourceId) {
                $explicit = $policy['source_id'] ?? null;
                if (!\is_string($explicit) || $explicit !== $composed) {
                    throw new \InvalidArgumentException(
                        'Backend ACL source_id does not match tags+code composition.',
                    );
                }
            }
            return ['kind' => self::KIND_SOURCE, 'source_id' => $composed];
        }

        throw new \InvalidArgumentException('Backend ACL source policy requires source_id or code.');
    }

    /**
     * @param array<string,mixed> $policy
     * @param mixed $paramsDescriptor
     * @param list<string> $defaultTags
     * @return array{kind:string,param:string,map:array<string,string>}
     */
    private static function normalizeParamMap(
        array $policy,
        mixed $paramsDescriptor,
        string $defaultModule,
        array $defaultTags,
    ): array {
        self::assertExactKeys($policy, ['kind', 'map', 'param']);
        $param = $policy['param'] ?? null;
        $map = $policy['map'] ?? null;
        if (!\is_string($param)
            || \preg_match(self::PARAM_PATTERN, $param) !== 1
            || !\is_array($map)
            || $map === []
            || \count($map) > 256) {
            throw new \InvalidArgumentException('Backend ACL param_map is invalid.');
        }
        $normalizedMap = [];
        foreach ($map as $value => $entry) {
            $value = (string)$value;
            if ($value === '' || \strlen($value) > 128) {
                throw new \InvalidArgumentException('Backend ACL param_map entry is invalid.');
            }
            $sourceId = self::normalizeMapEntry($entry, $defaultModule, $defaultTags);
            $normalizedMap[$value] = $sourceId;
        }
        if (!\in_array($param, self::paramNames($paramsDescriptor), true)) {
            throw new \InvalidArgumentException('Backend ACL param_map references an undeclared parameter.');
        }
        \ksort($normalizedMap, SORT_STRING);
        return ['kind' => self::KIND_PARAM_MAP, 'param' => $param, 'map' => $normalizedMap];
    }

    /**
     * @param mixed $entry
     * @param list<string> $defaultTags
     */
    private static function normalizeMapEntry(mixed $entry, string $defaultModule, array $defaultTags): string
    {
        if (\is_string($entry)) {
            if (!self::isValidSourceId($entry)) {
                throw new \InvalidArgumentException('Backend ACL param_map entry is invalid.');
            }
            return $entry;
        }
        if (!\is_array($entry) || \array_is_list($entry)) {
            throw new \InvalidArgumentException('Backend ACL param_map entry is invalid.');
        }
        $normalized = self::normalizeSource(
            \array_merge(['kind' => self::KIND_SOURCE], $entry),
            $defaultModule,
            $defaultTags,
        );
        return $normalized['source_id'];
    }

    /** @return list<string> */
    private static function normalizeTagList(mixed $tags): array
    {
        if ($tags === null) {
            return [];
        }
        if (!\is_array($tags)) {
            throw new \InvalidArgumentException('Backend ACL tags must be a list.');
        }
        $out = [];
        foreach ($tags as $tag) {
            if (!\is_string($tag) || \trim($tag) === '') {
                throw new \InvalidArgumentException('Backend ACL tag is invalid.');
            }
            $out[] = \trim($tag);
        }
        return $out;
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected): void
    {
        $actual = \array_keys($value);
        \sort($actual, SORT_STRING);
        \sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('Backend ACL policy fields are incomplete or unknown.');
        }
    }

    /** @return list<string> */
    private static function paramNames(mixed $descriptor): array
    {
        if (!\is_array($descriptor)) {
            return [];
        }
        $names = [];
        foreach ($descriptor as $key => $rule) {
            if (!\is_array($rule)) {
                continue;
            }
            $name = \is_string($key) ? $key : ($rule['name'] ?? null);
            if (\is_string($name) && \preg_match(self::PARAM_PATTERN, $name) === 1) {
                $names[] = $name;
            }
        }
        return \array_values(\array_unique($names));
    }
}
