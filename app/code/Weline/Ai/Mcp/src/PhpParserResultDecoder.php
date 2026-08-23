<?php

declare(strict_types=1);

namespace LearningMcp;

use JsonException;
use RuntimeException;

final class PhpParserResultDecoder
{
    private const DECODE_MEMORY_MULTIPLIER = 32;
    private const MINIMUM_DECODE_FREE_BYTES = 64 * 1_024 * 1_024;
    private const MAX_JSON_CONTAINERS = 25_000;
    private const MAX_JSON_STRINGS = 180_000;
    private const MAX_JSON_DEPTH = 8;

    /** @return array{symbols:list<array<string,mixed>>,relations:list<array<string,mixed>>} */
    public function decode(string $payload): array
    {
        $this->assertLexicalComplexity($payload);
        $this->assertDecodeMemoryReserve(strlen($payload));

        try {
            $parsed = json_decode($payload, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('PHP parser worker returned malformed JSON', 0, $exception);
        }
        if (!is_array($parsed)
            || count($parsed) !== 2
            || !isset($parsed['symbols'], $parsed['relations'])
            || !is_array($parsed['symbols'])
            || !array_is_list($parsed['symbols'])
            || !is_array($parsed['relations'])
            || !array_is_list($parsed['relations'])) {
            throw new RuntimeException('PHP parser worker returned an invalid result envelope');
        }

        foreach ($parsed['symbols'] as $symbol) {
            $this->assertSymbol($symbol);
        }
        foreach ($parsed['relations'] as $relation) {
            $this->assertRelation($relation);
        }

        return ['symbols' => $parsed['symbols'], 'relations' => $parsed['relations']];
    }

    private function assertLexicalComplexity(string $payload): void
    {
        $containers = 0;
        $strings = 0;
        $depth = 0;
        $inString = false;
        $escaped = false;
        for ($offset = 0, $bytes = strlen($payload); $offset < $bytes; ++$offset) {
            $character = $payload[$offset];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($character === '"') {
                $inString = true;
                ++$strings;
                if ($strings > self::MAX_JSON_STRINGS) {
                    throw new RuntimeException('PHP parser worker JSON exceeds the bounded string-token limit');
                }
                continue;
            }
            if ($character === '{' || $character === '[') {
                ++$containers;
                ++$depth;
                if ($containers > self::MAX_JSON_CONTAINERS || $depth > self::MAX_JSON_DEPTH) {
                    throw new RuntimeException('PHP parser worker JSON exceeds the bounded structural limit');
                }
                continue;
            }
            if ($character === '}' || $character === ']') {
                --$depth;
                if ($depth < 0) {
                    throw new RuntimeException('PHP parser worker returned malformed JSON structure');
                }
            }
        }
        if ($inString || $escaped || $depth !== 0) {
            throw new RuntimeException('PHP parser worker returned malformed JSON structure');
        }
    }

    private function assertDecodeMemoryReserve(int $payloadBytes): void
    {
        if ($payloadBytes < 0 || $payloadBytes > intdiv(PHP_INT_MAX, self::DECODE_MEMORY_MULTIPLIER)) {
            throw new RuntimeException('PHP parser worker output exceeds the parent decode memory reserve');
        }
        $configured = ini_get('memory_limit');
        if (!is_string($configured) || $configured === '') {
            throw new RuntimeException('PHP parser worker output cannot be admitted without a memory limit');
        }
        $limit = ini_parse_quantity($configured);
        if ($limit === -1) {
            return;
        }
        $required = max(
            self::MINIMUM_DECODE_FREE_BYTES,
            $payloadBytes * self::DECODE_MEMORY_MULTIPLIER,
        );
        if ($limit <= 0 || $limit - memory_get_usage(true) < $required) {
            throw new RuntimeException('PHP parser worker output exceeds the parent decode memory reserve');
        }
    }

    private function assertSymbol(mixed $symbol): void
    {
        $required = [
            'symbol_uid', 'name', 'fq_name', 'kind', 'namespace', 'signature', 'parent_uid',
            'start_line', 'end_line', 'start_byte', 'end_byte', 'body_hash', 'metadata',
        ];
        if (!is_array($symbol) || !$this->hasExactKeys($symbol, $required)
            || !is_string($symbol['symbol_uid']) || preg_match('/^sym-[a-f0-9]{40}$/D', $symbol['symbol_uid']) !== 1
            || !is_string($symbol['name']) || $symbol['name'] === '' || strlen($symbol['name']) > 4_096
            || !is_string($symbol['fq_name']) || $symbol['fq_name'] === '' || strlen($symbol['fq_name']) > 8_192
            || !is_string($symbol['kind'])
            || !in_array($symbol['kind'], ['class', 'interface', 'trait', 'enum', 'function', 'method'], true)
            || !is_string($symbol['namespace']) || strlen($symbol['namespace']) > 8_192
            || !is_string($symbol['signature']) || strlen($symbol['signature']) > 4_096
            || (!is_null($symbol['parent_uid'])
                && (!is_string($symbol['parent_uid'])
                    || preg_match('/^sym-[a-f0-9]{40}$/D', $symbol['parent_uid']) !== 1))
            || !is_int($symbol['start_line']) || $symbol['start_line'] < 1
            || !is_int($symbol['end_line']) || $symbol['end_line'] < $symbol['start_line']
            || !is_int($symbol['start_byte']) || $symbol['start_byte'] < 0
            || !is_int($symbol['end_byte']) || $symbol['end_byte'] < $symbol['start_byte']
            || !is_string($symbol['body_hash']) || preg_match('/^sha256:[a-f0-9]{64}$/D', $symbol['body_hash']) !== 1
            || !$this->validMetadata($symbol['metadata'], ['parser', 'uid_collision'])
            || ($symbol['metadata']['parser'] ?? null) !== 'php-token-get-all-v1'
            || (isset($symbol['metadata']['uid_collision']) && !is_bool($symbol['metadata']['uid_collision']))) {
            throw new RuntimeException('PHP parser worker returned an invalid symbol record');
        }
    }

    private function assertRelation(mixed $relation): void
    {
        $required = ['source_symbol_uid', 'target_name', 'relation_kind', 'line', 'confidence', 'metadata'];
        $confidence = is_array($relation) ? ($relation['confidence'] ?? null) : null;
        if (!is_array($relation) || !$this->hasExactKeys($relation, $required)
            || (!is_null($relation['source_symbol_uid'])
                && (!is_string($relation['source_symbol_uid'])
                    || preg_match('/^sym-[a-f0-9]{40}$/D', $relation['source_symbol_uid']) !== 1))
            || !is_string($relation['target_name']) || $relation['target_name'] === ''
            || strlen($relation['target_name']) > 8_192
            || !is_string($relation['relation_kind'])
            || !in_array($relation['relation_kind'], [
                'use', 'extends', 'implements', 'new', 'static', 'static_call', 'method_call', 'function_call',
            ], true)
            || !is_int($relation['line']) || $relation['line'] < 1
            || (!is_int($confidence) && !is_float($confidence))
            || !is_finite((float) $confidence) || $confidence < 0 || $confidence > 1
            || !$this->validMetadata($relation['metadata'], ['resolution', 'scope', 'alias', 'receiver', 'operator'])
            || ($relation['metadata']['resolution'] ?? null) !== 'lexical') {
            throw new RuntimeException('PHP parser worker returned an invalid relation record');
        }
    }

    /** @param list<string> $keys */
    private function hasExactKeys(array $record, array $keys): bool
    {
        $actual = array_keys($record);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);

        return $actual === $keys;
    }

    /** @param list<string> $allowedKeys */
    private function validMetadata(mixed $metadata, array $allowedKeys): bool
    {
        if (!is_array($metadata) || array_is_list($metadata) || count($metadata) > count($allowedKeys)) {
            return false;
        }
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowedKeys, true)
                || (!is_string($value) && !is_bool($value))
                || ($key === 'uid_collision' && !is_bool($value))
                || ($key !== 'uid_collision' && !is_string($value))
                || (is_string($value) && strlen($value) > 8_192)) {
                return false;
            }
        }

        return true;
    }
}
