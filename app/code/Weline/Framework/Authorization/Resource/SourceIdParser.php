<?php

declare(strict_types=1);

namespace Weline\Framework\Authorization\Resource;

/**
 * Parses Vendor_Module::tag1:tag2:...:code into tags + code.
 * Last colon-separated segment after :: is the code; preceding segments are tags.
 */
final class SourceIdParser
{
    private const MODULE_PATTERN = '/^([A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9]*)::(.*)$/D';
    private const SEGMENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D';

    /**
     * @return array{module:string,tags:list<string>,code:string}|null
     */
    public static function parse(string $sourceId): ?array
    {
        $sourceId = \trim($sourceId);
        if ($sourceId === '' || \preg_match(self::MODULE_PATTERN, $sourceId, $matches) !== 1) {
            return null;
        }
        $module = $matches[1];
        $rest = $matches[2];
        if ($rest === '') {
            return null;
        }
        if (!\str_contains($rest, ':')) {
            if (\preg_match(self::SEGMENT_PATTERN, $rest) !== 1) {
                return null;
            }
            return ['module' => $module, 'tags' => [], 'code' => $rest];
        }
        $parts = \explode(':', $rest);
        if (\count($parts) < 2) {
            return null;
        }
        foreach ($parts as $part) {
            if ($part === '' || \preg_match(self::SEGMENT_PATTERN, $part) !== 1) {
                return null;
            }
        }
        $code = \array_pop($parts);
        return ['module' => $module, 'tags' => \array_values($parts), 'code' => $code];
    }

    /**
     * @param list<string> $tags
     */
    public static function compose(string $module, array $tags, string $code): string
    {
        $module = \trim($module);
        $code = \trim($code);
        if ($module === '' || $code === '') {
            throw new \InvalidArgumentException('ACL source module and code are required.');
        }
        if (\preg_match('/^[A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9]*$/D', $module) !== 1) {
            throw new \InvalidArgumentException('ACL source module is invalid.');
        }
        if (\preg_match(self::SEGMENT_PATTERN, $code) !== 1) {
            throw new \InvalidArgumentException('ACL source code is invalid.');
        }
        $normalizedTags = [];
        foreach ($tags as $tag) {
            $tag = \trim((string)$tag);
            if ($tag === '') {
                continue;
            }
            if (\preg_match(self::SEGMENT_PATTERN, $tag) !== 1) {
                throw new \InvalidArgumentException('ACL source tag is invalid.');
            }
            if (!\in_array($tag, $normalizedTags, true)) {
                $normalizedTags[] = $tag;
            }
        }
        if ($normalizedTags === []) {
            return $module . '::' . $code;
        }
        return $module . '::' . \implode(':', $normalizedTags) . ':' . $code;
    }

    /**
     * @param list<string> $defaultTags
     * @param list<string> $operationTags
     * @return list<string>
     */
    public static function mergeTags(array $defaultTags, array $operationTags): array
    {
        $merged = [];
        foreach (\array_merge($defaultTags, $operationTags) as $tag) {
            $tag = \trim((string)$tag);
            if ($tag === '') {
                continue;
            }
            if (!\in_array($tag, $merged, true)) {
                $merged[] = $tag;
            }
        }
        return $merged;
    }
}
