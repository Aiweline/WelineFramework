<?php

declare(strict_types=1);

namespace LearningMcp;

final class PhpSymbolParser
{
    /**
     * Tolerant token-based PHP/PHTML parser. It deliberately reports lexical
     * references rather than claiming type-resolved call-graph precision.
     *
     * @return array{symbols:list<array<string,mixed>>,relations:list<array<string,mixed>>}
     */
    public function parse(string $content, string $relativePath): array
    {
        $tokens = $this->tokens($content);
        $symbols = [];
        $relations = [];
        $namespace = '';
        $imports = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token['id'] === T_NAMESPACE) {
                [$namespaceName] = $this->nameForward($tokens, $index + 1);
                $namespace = trim($namespaceName, '\\');
                $imports = [];
                continue;
            }

            if ($token['id'] === T_USE && $this->containingClass($symbols, $token['start_byte']) === null) {
                [$entries, $endIndex] = $this->useEntries($tokens, $index, $content);
                foreach ($entries as $entry) {
                    $target = $this->resolveName($entry['target'], $namespace, $imports);
                    $imports[strtolower($entry['alias'])] = $target;
                    $relations[] = $this->relation(null, $target, 'use', $token['line'], [
                        'scope' => 'import',
                        'alias' => $entry['alias'],
                    ]);
                }
                $index = max($index, $endIndex);
                continue;
            }

            if ($this->isClassToken($token['id'])) {
                $previous = $this->previousMeaningful($tokens, $index - 1);
                if (($previous['id'] ?? null) === T_NEW || ($previous['id'] ?? null) === T_DOUBLE_COLON) {
                    continue;
                }
                [$name, $nameEnd] = $this->nameForward($tokens, $index + 1);
                if ($name === '') {
                    continue;
                }
                $open = $this->findText($tokens, $nameEnd, '{');
                if ($open === null) {
                    continue;
                }
                $close = $this->matchingBrace($tokens, $open);
                if ($close === null) {
                    $close = $count - 1;
                }
                $kind = $this->classKind($token['id']);
                $fqName = $namespace === '' ? $name : $namespace . '\\' . $name;
                $declarationStart = $this->declarationStart($token, $content);
                $symbol = $this->symbol(
                    $relativePath,
                    $name,
                    $fqName,
                    $kind,
                    $namespace,
                    null,
                    $declarationStart,
                    $tokens[$close],
                    $this->slice($content, $declarationStart['start_byte'], $tokens[$close]['end_byte']),
                    $this->slice($content, $declarationStart['start_byte'], $tokens[$open]['start_byte'])
                );
                $symbol = $this->uniqueSymbolUid($symbol, $symbols);
                $symbols[] = $symbol;

                $relationMode = null;
                for ($cursor = $nameEnd; $cursor < $open; ++$cursor) {
                    $headerToken = $tokens[$cursor];
                    if ($headerToken['id'] === T_EXTENDS) {
                        $relationMode = 'extends';
                        continue;
                    }
                    if ($headerToken['id'] === T_IMPLEMENTS) {
                        $relationMode = 'implements';
                        continue;
                    }
                    if ($relationMode === null || !$this->isNameStart($headerToken)) {
                        continue;
                    }
                    [$target, $targetEnd] = $this->nameForward($tokens, $cursor);
                    if ($target !== '') {
                        $relations[] = $this->relation(
                            $symbol['symbol_uid'],
                            $this->resolveName($target, $namespace, $imports),
                            $relationMode,
                            $headerToken['line']
                        );
                    }
                    $cursor = max($cursor, $targetEnd - 1);
                }
                continue;
            }

            if ($token['id'] === T_FUNCTION) {
                $cursor = $this->nextMeaningfulIndex($tokens, $index + 1);
                if ($cursor === null) {
                    continue;
                }
                if ($tokens[$cursor]['text'] === '&') {
                    $cursor = $this->nextMeaningfulIndex($tokens, $cursor + 1);
                }
                if ($cursor === null || $tokens[$cursor]['id'] !== T_STRING) {
                    continue;
                }
                $name = $tokens[$cursor]['text'];
                $terminator = $this->findFunctionTerminator($tokens, $cursor + 1);
                if ($terminator === null) {
                    continue;
                }
                $end = $terminator;
                if ($tokens[$terminator]['text'] === '{') {
                    $end = $this->matchingBrace($tokens, $terminator) ?? ($count - 1);
                }
                $container = $this->containingClass($symbols, $token['start_byte']);
                $kind = $container === null ? 'function' : 'method';
                $fqName = $container === null
                    ? ($namespace === '' ? $name : $namespace . '\\' . $name)
                    : $container['fq_name'] . '::' . $name;
                $declarationStart = $this->declarationStart($token, $content);
                $body = $this->slice($content, $declarationStart['start_byte'], $tokens[$end]['end_byte']);
                $symbol = $this->symbol(
                    $relativePath,
                    $name,
                    $fqName,
                    $kind,
                    $namespace,
                    $container['symbol_uid'] ?? null,
                    $declarationStart,
                    $tokens[$end],
                    $body,
                    $this->slice($content, $declarationStart['start_byte'], $tokens[$terminator]['start_byte'])
                );
                $symbols[] = $this->uniqueSymbolUid($symbol, $symbols);
            }
        }

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            $source = $this->containingSymbol($symbols, $token['start_byte']);
            if ($token['id'] === T_USE && $source !== null && $source['kind'] !== 'method' && $source['kind'] !== 'function') {
                [$entries, $endIndex] = $this->useEntries($tokens, $index, $content);
                foreach ($entries as $entry) {
                    $relations[] = $this->relation(
                        $source['symbol_uid'],
                        $this->resolveName($entry['target'], (string) $source['namespace'], $imports),
                        'use',
                        $token['line'],
                        ['scope' => 'trait']
                    );
                }
                $index = max($index, $endIndex);
                continue;
            }
            if ($token['id'] === T_NEW) {
                [$target] = $this->nameForward($tokens, $index + 1);
                if ($target !== '' && strtolower($target) !== 'class') {
                    $relations[] = $this->relation(
                        $source['symbol_uid'] ?? null,
                        $this->resolveName($target, (string) ($source['namespace'] ?? $namespace), $imports),
                        'new',
                        $token['line']
                    );
                }
                continue;
            }
            if ($token['id'] === T_DOUBLE_COLON) {
                $target = $this->nameBackward($tokens, $index - 1);
                if ($target !== '') {
                    $relations[] = $this->relation(
                        $source['symbol_uid'] ?? null,
                        $this->resolveName($target, (string) ($source['namespace'] ?? $namespace), $imports),
                        'static',
                        $token['line']
                    );
                }
                $methodIndex = $this->nextMeaningfulIndex($tokens, $index + 1);
                $openIndex = $methodIndex === null ? null : $this->nextMeaningfulIndex($tokens, $methodIndex + 1);
                if ($methodIndex !== null && $tokens[$methodIndex]['id'] === T_STRING
                    && $openIndex !== null && $tokens[$openIndex]['text'] === '(') {
                    $relations[] = $this->relation(
                        $source['symbol_uid'] ?? null,
                        $tokens[$methodIndex]['text'],
                        'static_call',
                        $token['line'],
                        ['receiver' => $target]
                    );
                }
                continue;
            }
            $objectOperators = [T_OBJECT_OPERATOR];
            if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
                $objectOperators[] = constant('T_NULLSAFE_OBJECT_OPERATOR');
            }
            if (in_array($token['id'], $objectOperators, true)) {
                $methodIndex = $this->nextMeaningfulIndex($tokens, $index + 1);
                $openIndex = $methodIndex === null ? null : $this->nextMeaningfulIndex($tokens, $methodIndex + 1);
                if ($methodIndex !== null && $tokens[$methodIndex]['id'] === T_STRING
                    && $openIndex !== null && $tokens[$openIndex]['text'] === '(') {
                    $relations[] = $this->relation(
                        $source['symbol_uid'] ?? null,
                        $tokens[$methodIndex]['text'],
                        'method_call',
                        $token['line'],
                        ['operator' => $token['text']]
                    );
                }
                continue;
            }
            if ($token['id'] === T_STRING) {
                $openIndex = $this->nextMeaningfulIndex($tokens, $index + 1);
                $previous = $this->previousMeaningful($tokens, $index - 1);
                $blockedPrevious = [T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_DOUBLE_COLON];
                if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
                    $blockedPrevious[] = constant('T_NULLSAFE_OBJECT_OPERATOR');
                }
                if ($openIndex !== null && $tokens[$openIndex]['text'] === '('
                    && !in_array($previous['id'] ?? null, $blockedPrevious, true)) {
                    $relations[] = $this->relation(
                        $source['symbol_uid'] ?? null,
                        $this->resolveName($token['text'], (string) ($source['namespace'] ?? $namespace), $imports),
                        'function_call',
                        $token['line']
                    );
                }
            }
        }

        $relations = $this->deduplicateRelations($relations);

        return ['symbols' => $symbols, 'relations' => $relations];
    }

    /** @return list<array{id:int|null,text:string,line:int,start_byte:int,end_byte:int}> */
    private function tokens(string $content): array
    {
        $result = [];
        $offset = 0;
        $line = 1;
        foreach (token_get_all($content) as $raw) {
            if (is_array($raw)) {
                [$id, $text, $tokenLine] = $raw;
                $line = $tokenLine;
            } else {
                $id = null;
                $text = $raw;
            }
            $length = strlen($text);
            $result[] = [
                'id' => $id,
                'text' => $text,
                'line' => $line,
                'start_byte' => $offset,
                'end_byte' => $offset + $length,
            ];
            $offset += $length;
            $line += substr_count($text, "\n");
        }

        return $result;
    }

    private function isClassToken(?int $id): bool
    {
        return in_array($id, array_filter([
            T_CLASS,
            T_INTERFACE,
            T_TRAIT,
            defined('T_ENUM') ? constant('T_ENUM') : null,
        ]), true);
    }

    private function classKind(?int $id): string
    {
        if ($id === T_INTERFACE) {
            return 'interface';
        }
        if ($id === T_TRAIT) {
            return 'trait';
        }
        if (defined('T_ENUM') && $id === constant('T_ENUM')) {
            return 'enum';
        }

        return 'class';
    }

    /** @param list<array{id:int|null,text:string,line:int,start_byte:int,end_byte:int}> $tokens
     *  @return array{0:string,1:int}
     */
    private function nameForward(array $tokens, int $index): array
    {
        $index = $this->nextMeaningfulIndex($tokens, $index) ?? count($tokens);
        $name = '';
        $cursor = $index;
        while (isset($tokens[$cursor])) {
            $token = $tokens[$cursor];
            if ($this->isNameToken($token['id']) || $token['text'] === '\\') {
                $name .= $token['text'];
                ++$cursor;
                continue;
            }
            break;
        }

        return [trim($name), $cursor];
    }

    /** @param list<array{id:int|null,text:string,line:int,start_byte:int,end_byte:int}> $tokens */
    private function nameBackward(array $tokens, int $index): string
    {
        while ($index >= 0 && $this->isIgnorable($tokens[$index]['id'])) {
            --$index;
        }
        $parts = [];
        while ($index >= 0) {
            $token = $tokens[$index];
            if ($this->isNameToken($token['id']) || $token['text'] === '\\') {
                array_unshift($parts, $token['text']);
                --$index;
                continue;
            }
            break;
        }

        return trim(implode('', $parts));
    }

    private function isNameToken(?int $id): bool
    {
        $ids = [T_STRING, T_NS_SEPARATOR, T_STATIC];
        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
            if (defined($constant)) {
                $ids[] = constant($constant);
            }
        }

        return in_array($id, $ids, true);
    }

    /** @param array{id:int|null,text:string,line:int,start_byte:int,end_byte:int} $token */
    private function isNameStart(array $token): bool
    {
        return $this->isNameToken($token['id']) || $token['text'] === '\\';
    }

    private function isIgnorable(?int $id): bool
    {
        return in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /** @param list<array{id:int|null,text:string,line:int,start_byte:int,end_byte:int}> $tokens */
    private function nextMeaningfulIndex(array $tokens, int $index): ?int
    {
        while (isset($tokens[$index]) && $this->isIgnorable($tokens[$index]['id'])) {
            ++$index;
        }

        return isset($tokens[$index]) ? $index : null;
    }

    /** @param list<array{id:int|null,text:string,line:int,start_byte:int,end_byte:int}> $tokens
     *  @return array{id:int|null,text:string,line:int,start_byte:int,end_byte:int}|null
     */
    private function previousMeaningful(array $tokens, int $index): ?array
    {
        while ($index >= 0 && $this->isIgnorable($tokens[$index]['id'])) {
            --$index;
        }

        return $tokens[$index] ?? null;
    }

    /** @param list<array{id:int|null,text:string,line:int,start_byte:int,end_byte:int}> $tokens */
    private function findText(array $tokens, int $index, string $text): ?int
    {
        for ($count = count($tokens); $index < $count; ++$index) {
            if ($tokens[$index]['text'] === $text) {
                return $index;
            }
            if ($tokens[$index]['text'] === ';') {
                return null;
            }
        }

        return null;
    }

    /** @param list<array{id:int|null,text:string,line:int,start_byte:int,end_byte:int}> $tokens */
    private function matchingBrace(array $tokens, int $open): ?int
    {
        $depth = 0;
        for ($index = $open, $count = count($tokens); $index < $count; ++$index) {
            if ($tokens[$index]['text'] === '{') {
                ++$depth;
            } elseif ($tokens[$index]['text'] === '}') {
                --$depth;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /** @param list<array{id:int|null,text:string,line:int,start_byte:int,end_byte:int}> $tokens */
    private function findFunctionTerminator(array $tokens, int $index): ?int
    {
        $parentheses = 0;
        $brackets = 0;
        for ($count = count($tokens); $index < $count; ++$index) {
            $text = $tokens[$index]['text'];
            $parentheses += $text === '(' ? 1 : ($text === ')' ? -1 : 0);
            $brackets += $text === '[' ? 1 : ($text === ']' ? -1 : 0);
            if ($parentheses <= 0 && $brackets <= 0 && ($text === '{' || $text === ';')) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<array<string,mixed>> $symbols
     *  @return array<string,mixed>|null
     */
    private function containingClass(array $symbols, int $byte): ?array
    {
        $classes = array_values(array_filter(
            $symbols,
            static fn (array $symbol): bool => in_array($symbol['kind'], ['class', 'interface', 'trait', 'enum'], true)
                && $symbol['start_byte'] < $byte && $symbol['end_byte'] >= $byte
        ));
        usort($classes, static fn (array $left, array $right): int => $right['start_byte'] <=> $left['start_byte']);

        return $classes[0] ?? null;
    }

    /** @param list<array<string,mixed>> $symbols
     *  @return array<string,mixed>|null
     */
    private function containingSymbol(array $symbols, int $byte): ?array
    {
        $matches = array_values(array_filter(
            $symbols,
            static fn (array $symbol): bool => $symbol['start_byte'] <= $byte && $symbol['end_byte'] >= $byte
        ));
        usort($matches, static function (array $left, array $right): int {
            $leftSize = $left['end_byte'] - $left['start_byte'];
            $rightSize = $right['end_byte'] - $right['start_byte'];

            return $leftSize <=> $rightSize;
        });

        return $matches[0] ?? null;
    }

    /** @param array{id:int|null,text:string,line:int,start_byte:int,end_byte:int} $start
     *  @param array{id:int|null,text:string,line:int,start_byte:int,end_byte:int} $end
     *  @return array<string,mixed>
     */
    private function symbol(
        string $path,
        string $name,
        string $fqName,
        string $kind,
        string $namespace,
        ?string $parentUid,
        array $start,
        array $end,
        string $body,
        string $signature,
    ): array {
        $signature = preg_replace('/\s+/u', ' ', trim($signature)) ?? trim($signature);
        $signature = mb_substr($signature, 0, 1_000, 'UTF-8');

        return [
            'symbol_uid' => 'sym-' . substr(hash('sha256', $path . "\0" . $kind . "\0" . strtolower($fqName)), 0, 40),
            'name' => $name,
            'fq_name' => $fqName,
            'kind' => $kind,
            'namespace' => $namespace,
            'signature' => $signature,
            'parent_uid' => $parentUid,
            'start_line' => $start['line'],
            'end_line' => $start['line'] + substr_count($body, "\n"),
            'start_byte' => $start['start_byte'],
            'end_byte' => $end['end_byte'],
            'body_hash' => 'sha256:' . hash('sha256', $body),
            'metadata' => ['parser' => 'php-token-get-all-v1'],
        ];
    }

    /** @param array<string,mixed> $symbol
     *  @param list<array<string,mixed>> $existing
     *  @return array<string,mixed>
     */
    private function uniqueSymbolUid(array $symbol, array $existing): array
    {
        $uid = (string) $symbol['symbol_uid'];
        foreach ($existing as $candidate) {
            if (($candidate['symbol_uid'] ?? null) !== $uid) {
                continue;
            }
            $symbol['symbol_uid'] = 'sym-' . substr(hash('sha256', implode("\0", [
                $uid,
                (string) ($symbol['start_byte'] ?? 0),
                (string) ($symbol['end_byte'] ?? 0),
            ])), 0, 40);
            $symbol['metadata']['uid_collision'] = true;
            break;
        }

        return $symbol;
    }

    /** @return array<string,mixed> */
    private function relation(?string $source, string $target, string $kind, int $line, array $metadata = []): array
    {
        return [
            'source_symbol_uid' => $source,
            'target_name' => trim($target, '\\'),
            'relation_kind' => $kind,
            'line' => max(1, $line),
            'confidence' => match ($kind) {
                'extends', 'implements' => 0.95,
                'static_call' => 0.7,
                'function_call' => 0.65,
                'method_call' => 0.55,
                default => 0.75,
            },
            'metadata' => array_replace(['resolution' => 'lexical'], $metadata),
        ];
    }

    /** @param list<array{id:int|null,text:string,line:int,start_byte:int,end_byte:int}> $tokens
     *  @return array{0:list<array{target:string,alias:string}>,1:int}
     */
    private function useEntries(array $tokens, int $useIndex, string $content): array
    {
        $end = $useIndex;
        for ($count = count($tokens); $end < $count && $tokens[$end]['text'] !== ';'; ++$end) {
        }
        if (!isset($tokens[$end])) {
            return [[], $useIndex];
        }
        $body = $this->slice($content, $tokens[$useIndex]['end_byte'], $tokens[$end]['start_byte']);
        $body = preg_replace('/^\s*(?:function|const)\s+/i', '', trim($body)) ?? trim($body);
        $entries = [];
        $parts = [];
        if (preg_match('/^(.*?)\{(.*)\}$/s', $body, $group) === 1) {
            $prefix = rtrim(trim($group[1]), '\\') . '\\';
            foreach (explode(',', $group[2]) as $part) {
                $parts[] = $prefix . trim($part);
            }
        } else {
            $parts = explode(',', $body);
        }
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $alias = '';
            if (preg_match('/^(.*?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $part, $match) === 1) {
                $part = trim($match[1]);
                $alias = $match[2];
            }
            $target = trim(preg_replace('/^(?:function|const)\s+/i', '', $part) ?? $part, '\\ ');
            if ($target === '') {
                continue;
            }
            if ($alias === '') {
                $segments = explode('\\', $target);
                $alias = (string) end($segments);
            }
            $entries[] = ['target' => $target, 'alias' => $alias];
        }

        return [$entries, $end];
    }

    /** @param array<string,string> $imports */
    private function resolveName(string $name, string $namespace, array $imports): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $lower = strtolower($name);
        if (in_array($lower, ['self', 'static', 'parent'], true)) {
            return $lower;
        }
        $segments = explode('\\', $name);
        $first = strtolower($segments[0]);
        if (isset($imports[$first])) {
            array_shift($segments);

            return rtrim($imports[$first] . ($segments === [] ? '' : '\\' . implode('\\', $segments)), '\\');
        }

        return $namespace === '' ? trim($name, '\\') : $namespace . '\\' . trim($name, '\\');
    }

    private function slice(string $content, int $start, int $end): string
    {
        return substr($content, $start, max(0, $end - $start));
    }

    /**
     * Include indentation and same-line declaration modifiers in the editable
     * symbol range. This keeps replace-by-symbol operations from leaving an old
     * `public`, `static`, `final`, or `abstract` prefix behind.
     *
     * @param array{id:int|null,text:string,line:int,start_byte:int,end_byte:int} $token
     * @return array{id:int|null,text:string,line:int,start_byte:int,end_byte:int}
     */
    private function declarationStart(array $token, string $content): array
    {
        $prefix = substr($content, 0, $token['start_byte']);
        $newline = strrpos($prefix, "\n");
        $lineStart = $newline === false ? 0 : $newline + 1;
        $sameLine = substr($content, $lineStart, $token['start_byte'] - $lineStart);
        if (preg_match('/^\s*(?:(?:public|protected|private|static|final|abstract|readonly)\s+)*$/i', $sameLine) !== 1) {
            return $token;
        }
        $token['start_byte'] = $lineStart;

        return $token;
    }

    /** @param list<array<string,mixed>> $relations
     *  @return list<array<string,mixed>>
     */
    private function deduplicateRelations(array $relations): array
    {
        $seen = [];
        $result = [];
        foreach ($relations as $relation) {
            if ($relation['target_name'] === '') {
                continue;
            }
            $key = implode("\0", [
                (string) ($relation['source_symbol_uid'] ?? ''),
                (string) $relation['target_name'],
                (string) $relation['relation_kind'],
                (string) $relation['line'],
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $relation;
        }

        return $result;
    }
}
