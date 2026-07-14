<?php

declare(strict_types=1);

namespace LearningMcp;

use PDO;
use RuntimeException;
use Throwable;

final class ProjectIndexer
{
    private SparseVectorizer $vectorizer;
    private PhpSymbolParser $phpParser;

    public function __construct(
        private readonly ProjectIndex $index,
        private readonly Config $config,
        private readonly ?ProcessRunner $processRunner = null,
    ) {
        $this->vectorizer = new SparseVectorizer($config);
        $this->phpParser = new PhpSymbolParser();
    }

    /** @param array<string, mixed> $options
     *  @return array<string, mixed>
     */
    public function index(array $options = []): array
    {
        if (!(bool) $this->config->get('index.enabled', true)) {
            return ['enabled' => false, 'status' => $this->index->status()];
        }
        $mode = strtolower(trim((string) ($options['mode'] ?? 'incremental')));
        if (!in_array($mode, ['full', 'incremental'], true)) {
            throw new RuntimeException('Index mode must be full or incremental');
        }
        $requestedPaths = null;
        if (array_key_exists('paths', $options)) {
            if (!is_array($options['paths'])) {
                throw new RuntimeException('Index paths must be an array');
            }
            $requestedPaths = $this->normalizeRequestedPaths($options['paths']);
            if ($requestedPaths === []) {
                throw new RuntimeException('Index paths cannot be empty when provided');
            }
        }

        $lock = $this->acquireLock();
        $started = microtime(true);
        $startedAt = Clock::now();
        $revision = 0;
        try {
            $this->index->setState([
                'phase' => 'indexing',
                'freshness' => 'updating',
                'last_started_at' => $startedAt,
                'last_error' => null,
            ]);
            $discovered = $this->discover($requestedPaths);
            $existing = $this->existingFiles();
            $eligible = [];
            $removed = [];
            $warnings = [];
            $skipped = ['policy' => 0, 'missing' => 0, 'oversized' => 0, 'binary' => 0, 'unreadable' => 0];

            foreach ($discovered as $path) {
                if (!$this->pathAllowed($path)) {
                    ++$skipped['policy'];
                    $removed[$path] = true;
                    continue;
                }
                try {
                    $absolute = $this->index->absolutePath($path);
                } catch (Throwable $exception) {
                    ++$skipped['unreadable'];
                    $removed[$path] = true;
                    $warnings[] = $path . ': ' . $exception->getMessage();
                    continue;
                }
                if (!is_file($absolute)) {
                    ++$skipped['missing'];
                    $removed[$path] = true;
                    continue;
                }
                $stat = stat($absolute);
                if (!is_array($stat)) {
                    ++$skipped['unreadable'];
                    $removed[$path] = true;
                    continue;
                }
                $size = (int) ($stat['size'] ?? 0);
                if ($size > (int) $this->config->get('index.max_file_bytes', 524_288)) {
                    ++$skipped['oversized'];
                    $removed[$path] = true;
                    continue;
                }
                $eligible[$path] = [
                    'path' => $path,
                    'absolute' => $absolute,
                    'size' => $size,
                    'mtime' => (int) ($stat['mtime'] ?? 0),
                ];
            }

            $discoveredSet = array_fill_keys(array_keys($eligible), true);
            foreach ($existing as $path => $row) {
                if (!$this->inScope($path, $requestedPaths)) {
                    continue;
                }
                if (!isset($discoveredSet[$path]) || isset($removed[$path])) {
                    $removed[$path] = true;
                }
            }
            $deletePaths = array_values(array_filter(
                array_keys($removed),
                static fn (string $path): bool => isset($existing[$path])
            ));
            if ($deletePaths !== []) {
                $revision = $this->index->nextRevision();
            }
            $deleted = $this->deletePaths($deletePaths);
            $changedPaths = $deletePaths;

            $indexed = 0;
            $contentBackfilled = 0;
            $unchanged = 0;
            $errors = [];
            foreach (array_chunk(array_values($eligible), 40) as $batch) {
                $writes = [];
                $touches = [];
                $contentBackfills = [];
                foreach ($batch as $file) {
                    $previous = $existing[$file['path']] ?? null;
                    $explicit = $requestedPaths !== null;
                    $contentStored = $previous !== null && (int) ($previous['content_stored'] ?? 0) === 1;
                    if ($mode === 'incremental' && !$explicit && $previous !== null
                        && $contentStored
                        && (int) $previous['size_bytes'] === $file['size']
                        && (int) $previous['mtime'] === $file['mtime']) {
                        ++$unchanged;
                        continue;
                    }
                    $content = file_get_contents($file['absolute']);
                    if ($content === false) {
                        ++$skipped['unreadable'];
                        $errors[] = $file['path'] . ': unable to read';
                        continue;
                    }
                    if ($this->looksBinary($content) || !mb_check_encoding($content, 'UTF-8')) {
                        ++$skipped['binary'];
                        if ($previous !== null) {
                            if ($revision === 0) {
                                $revision = $this->index->nextRevision();
                            }
                            $this->deletePaths([$file['path']]);
                            ++$deleted;
                            $changedPaths[] = $file['path'];
                        }
                        continue;
                    }
                    $hash = 'sha256:' . hash('sha256', $content);
                    if ($mode === 'incremental' && $previous !== null
                        && hash_equals((string) $previous['content_hash'], $hash)) {
                        $touches[] = [
                            'path' => $file['path'],
                            'size' => $file['size'],
                            'mtime' => $file['mtime'],
                        ];
                        if (!$contentStored) {
                            $contentBackfills[] = [
                                'file_id' => (int) $previous['id'],
                                'path' => $file['path'],
                                'hash' => $hash,
                                'stored_content' => $this->prepareStoredContent($content),
                            ];
                        }
                        ++$unchanged;
                        continue;
                    }
                    try {
                        $writes[] = $this->prepareFile($file, $content, $hash, $revision);
                    } catch (Throwable $exception) {
                        $errors[] = $file['path'] . ': ' . $exception->getMessage();
                    }
                }
                if ($writes !== [] || $touches !== [] || $contentBackfills !== []) {
                    if (($writes !== [] || $contentBackfills !== []) && $revision === 0) {
                        $revision = $this->index->nextRevision();
                    }
                    $this->index->transaction(function (PDO $database) use (
                        $touches,
                        $contentBackfills,
                        $writes,
                        $revision,
                        &$errors,
                        &$indexed,
                        &$contentBackfilled,
                        &$changedPaths,
                    ): void {
                        foreach ($touches as $touch) {
                            $statement = $database->prepare(
                                'UPDATE indexed_files SET size_bytes = :size, mtime = :mtime, indexed_at = :indexed_at WHERE path = :path'
                            );
                            $statement->execute([
                                'size' => $touch['size'],
                                'mtime' => $touch['mtime'],
                                'indexed_at' => Clock::now(),
                                'path' => $touch['path'],
                            ]);
                        }
                        foreach ($contentBackfills as $backfill) {
                            try {
                                $this->writeStoredContent(
                                    $database,
                                    (int) $backfill['file_id'],
                                    (string) $backfill['hash'],
                                    (array) $backfill['stored_content'],
                                    $revision,
                                );
                                ++$contentBackfilled;
                            } catch (Throwable $exception) {
                                $errors[] = (string) $backfill['path'] . ': content backfill failed: ' . $exception->getMessage();
                            }
                        }
                        foreach ($writes as $write) {
                            try {
                                $this->index->transaction(function (PDO $nested) use ($write, $revision): void {
                                    $this->writeFile($nested, $write, $revision);
                                });
                            } catch (Throwable $exception) {
                                $errors[] = (string) $write['path'] . ': database write failed: ' . $exception->getMessage();
                                continue;
                            }
                            ++$indexed;
                            $changedPaths[] = (string) $write['path'];
                        }
                    });
                }
            }

            $changedPaths = array_values(array_unique($changedPaths));
            sort($changedPaths, SORT_STRING);

            if ($revision > 0) {
                $this->resolveRelationTargets();
            }
            $completedAt = Clock::now();
            $durationMs = (int) round((microtime(true) - $started) * 1_000);
            if ($revision > 0) {
                $this->writeKnowledgeState($revision, $completedAt);
            }
            $reportedRevision = $this->index->revision();
            $this->index->setState([
                'phase' => 'idle',
                'freshness' => $errors === [] ? 'current' : 'partial',
                'last_completed_at' => $completedAt,
                'last_error' => $errors === [] ? null : implode("\n", array_slice($errors, 0, 20)),
                'last_index' => [
                    'mode' => $mode,
                    'discovered' => count($discovered),
                    'eligible' => count($eligible),
                    'indexed' => $indexed,
                    'content_backfilled' => $contentBackfilled,
                    'unchanged' => $unchanged,
                    'deleted' => $deleted,
                    'changed' => count($changedPaths),
                    'errors' => count($errors),
                    'duration_ms' => $durationMs,
                ],
            ]);

            return [
                'enabled' => true,
                'project_id' => $this->index->projectId(),
                'root' => $this->index->root(),
                'index_db' => $this->index->path(),
                'mode' => $mode,
                'scope_paths' => $requestedPaths,
                'revision' => $reportedRevision,
                'freshness' => $errors === [] ? 'current' : 'partial',
                'discovered' => count($discovered),
                'eligible' => count($eligible),
                'indexed' => $indexed,
                'content_backfilled' => $contentBackfilled,
                'unchanged' => $unchanged,
                'deleted' => $deleted,
                'changed_paths' => $changedPaths,
                'skipped' => $skipped,
                'errors' => $errors,
                'warnings' => array_values(array_unique($warnings)),
                'duration_ms' => $durationMs,
            ];
        } catch (Throwable $exception) {
            $this->index->setState([
                'phase' => 'error',
                'freshness' => 'stale',
                'last_error' => $exception->getMessage(),
            ]);
            throw $exception;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @param array<int, mixed> $paths
     *  @return array<string, mixed>
     */
    public function indexPaths(array $paths): array
    {
        return $this->index(['mode' => 'incremental', 'paths' => $paths]);
    }

    /** @param array<int, mixed> $paths
     *  @return list<string>
     */
    private function normalizeRequestedPaths(array $paths): array
    {
        $result = [];
        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                throw new RuntimeException('Every index path must be a non-empty string');
            }
            $normalized = $this->index->normalizeRelativePath($path);
            if ($normalized === '') {
                throw new RuntimeException('Project root cannot be used as an explicit index path');
            }
            $result[$normalized] = true;
        }

        return array_keys($result);
    }

    /** @param list<string>|null $requestedPaths
     *  @return list<string>
     */
    private function discover(?array $requestedPaths): array
    {
        $paths = [];
        $batches = $requestedPaths === null ? [null] : array_chunk($requestedPaths, 200);
        foreach ($batches as $batch) {
            $command = ['git', '-C', $this->index->root(), 'ls-files', '-co', '--exclude-standard', '-z'];
            if (is_array($batch)) {
                $command[] = '--';
                foreach ($batch as $path) {
                    $command[] = ':(literal)' . $path;
                }
            }
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            // Fixed Git subcommands and argv-mode proc_open avoid shell interpretation.
            $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]); // nosemgrep: php.lang.security.exec-use.exec-use
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start git ls-files');
            }
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== 0 || $output === false) {
                throw new RuntimeException('git ls-files failed: ' . trim((string) $error));
            }
            foreach (explode("\0", $output) as $path) {
                if ($path === '') {
                    continue;
                }
                $normalized = $this->index->normalizeRelativePath($path);
                if ($normalized !== '') {
                    $paths[$normalized] = true;
                }
            }
        }
        $result = array_keys($paths);
        sort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, array<string,mixed>> */
    private function existingFiles(): array
    {
        $rows = $this->index->pdo()->query(
            'SELECT f.id, f.path, f.size_bytes, f.mtime, f.content_hash, f.revision,
                    CASE WHEN c.file_id IS NULL THEN 0 ELSE 1 END AS content_stored
               FROM indexed_files AS f
          LEFT JOIN indexed_file_contents AS c ON c.file_id = f.id'
        )->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['path']] = $row;
        }

        return $result;
    }

    private function pathAllowed(string $path): bool
    {
        $lower = strtolower($path);
        $basename = strtolower(basename($path));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($this->isSecretPath($lower, $basename) || $this->isBinaryExtension($extension)
            || preg_match('/(?:^|\/)[^\/]+\.min\.[^\/]+$/i', $path) === 1
            || str_ends_with($lower, '.map')) {
            return false;
        }
        if ($this->isRetainedKnowledgePath($path)) {
            return $this->extensionAllowed($extension);
        }
        if (!(bool) $this->config->get('index.include_tests', false)
            && preg_match('~(?:^|/)(?:test|tests|Test|Tests)(?:/|$)~', $path) === 1) {
            return false;
        }
        foreach ([
            '~^\.git(?:/|$)~',
            '~^\.gitnexus(?:/|$)~',
            '~^\.codex/code-intelligence(?:/|$)~',
            '~(?:^|/)vendor(?:/|$)~i',
            '~(?:^|/)node_modules(?:/|$)~i',
            '~^generated(?:/|$)~i',
            '~^var(?:/|$)~i',
            '~^pub/(?:static|media)(?:/|$)~i',
            '~(?:^|/)view/tpl(?:/|$)~i',
            '~(?:^|/)static/libs(?:/|$)~i',
        ] as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return false;
            }
        }
        foreach ((array) $this->config->get('index.excluded_paths', []) as $glob) {
            if (!is_string($glob)) {
                continue;
            }
            if ((bool) $this->config->get('index.include_tests', false)
                && preg_match('/(?:^|\/)(?:test|tests)(?:\/|$)/i', $glob) === 1) {
                continue;
            }
            if ($this->globMatches($glob, $path)) {
                return false;
            }
        }

        return $this->extensionAllowed($extension);
    }

    private function isRetainedKnowledgePath(string $path): bool
    {
        return preg_match('~(?:^|/)AGENTS\.md$~i', $path) === 1
            || in_array($path, ['AI-ENTRY.md', 'AI-README.md', 'dev/ai/AI-RULES-PACK.md', 'dev/ai/global-constraints.md'], true)
            || str_starts_with($path, 'dev/ai/skills/')
            || str_starts_with($path, 'dev/ai/diagrams/')
            || preg_match('~^app/code/[^/]+/[^/]+/doc(?:/|$)~', $path) === 1;
    }

    private function isSecretPath(string $path, string $basename): bool
    {
        return $path === 'app/etc/env.php'
            || $basename === '.env'
            || str_starts_with($basename, '.env.')
            || in_array($basename, ['auth.json', 'credentials.json', 'secrets.json'], true)
            || preg_match('/\.(?:pem|key|p12|pfx|keystore|jks)$/i', $basename) === 1;
    }

    private function isBinaryExtension(string $extension): bool
    {
        return in_array($extension, [
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'tif', 'tiff',
            'pdf', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'tar', 'phar',
            'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp3', 'mp4', 'mov', 'avi', 'wav',
            'so', 'dylib', 'dll', 'exe', 'bin', 'sqlite', 'sqlite3', 'db', 'class', 'jar',
        ], true);
    }

    private function extensionAllowed(string $extension): bool
    {
        if ($extension === '') {
            return false;
        }
        $allowed = array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value, '. ')),
            (array) $this->config->get('index.allowed_extensions', [])
        );

        return in_array($extension, $allowed, true);
    }

    private function globMatches(string $glob, string $path): bool
    {
        $quoted = preg_quote(str_replace('\\', '/', trim($glob)), '~');
        $quoted = str_replace(['\*\*', '\*', '\?'], ['.*', '[^/]*', '[^/]'], $quoted);

        return preg_match('~^' . $quoted . '$~i', $path) === 1;
    }

    private function looksBinary(string $content): bool
    {
        return str_contains(substr($content, 0, 8_192), "\0");
    }

    /** @param array<string,mixed> $file
     *  @return array<string,mixed>
     */
    private function prepareFile(array $file, string $content, string $hash, int $revision): array
    {
        $path = (string) $file['path'];
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $module = $this->index->moduleForPath($path);
        $kind = $this->fileKind($path, $extension);
        $language = $this->language($extension);
        $parsed = ['symbols' => [], 'relations' => []];
        if (in_array($extension, ['php', 'phtml'], true)) {
            $parsed = $this->phpParser->parse($content, $path);
        }
        $chunks = $this->chunks($path, $kind, $extension, $content, $hash, $parsed['symbols']);

        return [
            'path' => $path,
            'kind' => $kind,
            'language' => $language,
            'module_vendor' => $module['vendor'] ?? '',
            'module_name' => $module['module'] ?? '',
            'size' => $file['size'],
            'mtime' => $file['mtime'],
            'hash' => $hash,
            'revision' => $revision,
            'stored_content' => $this->prepareStoredContent($content),
            'chunks' => $chunks,
            'symbols' => $parsed['symbols'],
            'relations' => $parsed['relations'],
            'skill' => $kind === 'skill' ? $this->skillMetadata($path, $content, $hash, $module) : null,
        ];
    }

    /** @return array{blob:string,encoding:string,original_bytes:int,stored_bytes:int} */
    private function prepareStoredContent(string $content): array
    {
        $contentBlob = gzencode($content, 6);
        $encoding = 'gzip';
        if (!is_string($contentBlob)) {
            $contentBlob = $content;
            $encoding = 'raw';
        }

        return [
            'blob' => $contentBlob,
            'encoding' => $encoding,
            'original_bytes' => strlen($content),
            'stored_bytes' => strlen($contentBlob),
        ];
    }

    private function fileKind(string $path, string $extension): string
    {
        if (preg_match('~(?:^|/)AGENTS\.md$~i', $path) === 1
            || in_array($path, ['AI-ENTRY.md', 'AI-README.md', 'dev/ai/AI-RULES-PACK.md', 'dev/ai/global-constraints.md'], true)) {
            return 'rule';
        }
        if (strcasecmp(basename($path), 'SKILL.md') === 0
            && (str_starts_with($path, 'dev/ai/skills/')
                || preg_match('~^app/code/[^/]+/[^/]+/doc/ai/skills/~', $path) === 1)) {
            return 'skill';
        }
        if (preg_match('~^app/code/[^/]+/[^/]+/doc(?:/|$)~', $path) === 1
            || str_starts_with($path, 'dev/ai/diagrams/')
            || in_array($extension, ['md', 'markdown', 'txt'], true)) {
            return 'doc';
        }
        if (in_array($extension, ['json', 'yaml', 'yml', 'xml', 'toml', 'ini'], true)) {
            return 'config';
        }

        return 'code';
    }

    private function language(string $extension): string
    {
        return match ($extension) {
            'php', 'phtml' => 'php',
            'md', 'markdown' => 'markdown',
            'js', 'jsx' => 'javascript',
            'ts', 'tsx' => 'typescript',
            'yml', 'yaml' => 'yaml',
            'html', 'htm' => 'html',
            default => $extension,
        };
    }

    /** @param list<array<string,mixed>> $symbols
     *  @return list<array<string,mixed>>
     */
    private function chunks(string $path, string $fileKind, string $extension, string $content, string $fileHash, array $symbols): array
    {
        $maximum = (int) $this->config->get('index.max_chunk_chars', 6_000);
        $chunks = [];
        if (in_array($extension, ['md', 'markdown'], true)) {
            $chunks = $this->markdownChunks($path, $content, $maximum);
        } else {
            $chunks = $this->splitSegment($path, $fileKind, $path, null, $content, 1, 0, $maximum);
        }
        if (in_array($extension, ['php', 'phtml'], true)) {
            foreach ($symbols as $symbol) {
                if (in_array($symbol['kind'], ['class', 'interface', 'trait', 'enum'], true)) {
                    $symbolContent = (string) $symbol['signature'];
                } else {
                    $symbolContent = substr(
                        $content,
                        (int) $symbol['start_byte'],
                        max(0, (int) $symbol['end_byte'] - (int) $symbol['start_byte'])
                    );
                }
                $chunks = array_merge($chunks, $this->splitSegment(
                    $path,
                    'symbol',
                    (string) $symbol['fq_name'],
                    (string) $symbol['symbol_uid'],
                    $symbolContent,
                    (int) $symbol['start_line'],
                    (int) $symbol['start_byte'],
                    $maximum,
                ));
            }
        }
        foreach ($chunks as $ordinal => &$chunk) {
            $chunk['chunk_id'] = 'chunk-' . substr(hash('sha256', implode("\0", [
                $path,
                $fileHash,
                (string) $chunk['kind'],
                (string) $chunk['title'],
                (string) ($chunk['symbol_uid'] ?? ''),
                (string) $chunk['start_line'],
                (string) $ordinal,
            ])), 0, 48);
            $chunk['content_hash'] = 'sha256:' . hash('sha256', (string) $chunk['content']);
            $chunk['token_estimate'] = max(1, (int) ceil(mb_strlen((string) $chunk['content'], 'UTF-8') / 4));
            $chunk['metadata'] = ['ordinal' => $ordinal, 'chunker' => 'line-budget-v1'];
        }
        unset($chunk);

        return $chunks;
    }

    /** @return list<array<string,mixed>> */
    private function markdownChunks(string $path, string $content, int $maximum): array
    {
        $lines = preg_split('/(?<=\n)/', $content) ?: [$content];
        $sections = [];
        $section = ['title' => $path, 'content' => '', 'line' => 1, 'byte' => 0];
        $lineNumber = 1;
        $byte = 0;
        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*(?:\R)?$/u', $line, $match) === 1
                && $section['content'] !== '') {
                $sections[] = $section;
                $section = [
                    'title' => trim($match[2]),
                    'content' => '',
                    'line' => $lineNumber,
                    'byte' => $byte,
                ];
            } elseif ($section['content'] === ''
                && preg_match('/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*(?:\R)?$/u', $line, $match) === 1) {
                $section['title'] = trim($match[2]);
            }
            $section['content'] .= $line;
            $lineNumber += substr_count($line, "\n") ?: 1;
            $byte += strlen($line);
        }
        if ($section['content'] !== '') {
            $sections[] = $section;
        }
        $chunks = [];
        foreach ($sections as $item) {
            $chunks = array_merge($chunks, $this->splitSegment(
                $path,
                'heading',
                (string) $item['title'],
                null,
                (string) $item['content'],
                (int) $item['line'],
                (int) $item['byte'],
                $maximum,
            ));
        }

        return $chunks;
    }

    /** @return list<array<string,mixed>> */
    private function splitSegment(
        string $path,
        string $kind,
        string $title,
        ?string $symbolUid,
        string $content,
        int $startLine,
        int $startByte,
        int $maximum,
    ): array {
        if ($content === '') {
            return [];
        }
        $pieces = [];
        $lines = preg_split('/(?<=\n)/', $content) ?: [$content];
        $buffer = '';
        $line = $startLine;
        $byte = $startByte;
        $bufferLine = $line;
        $bufferByte = $byte;
        $flush = static function () use (&$pieces, &$buffer, &$bufferLine, &$bufferByte, &$line, &$byte, $kind, $title, $symbolUid): void {
            if ($buffer === '') {
                return;
            }
            $pieces[] = [
                'kind' => $kind,
                'title' => $title,
                'symbol_uid' => $symbolUid,
                'start_line' => $bufferLine,
                'end_line' => max($bufferLine, $line - ($buffer !== '' && !str_ends_with($buffer, "\n") ? 0 : 1)),
                'start_byte' => $bufferByte,
                'end_byte' => $byte,
                'content' => $buffer,
            ];
            $buffer = '';
            $bufferLine = $line;
            $bufferByte = $byte;
        };
        foreach ($lines as $textLine) {
            while (mb_strlen($textLine, 'UTF-8') > $maximum) {
                if ($buffer !== '') {
                    $flush();
                }
                $part = mb_substr($textLine, 0, $maximum, 'UTF-8');
                $partBytes = strlen($part);
                $buffer = $part;
                $byte += $partBytes;
                $textLine = substr($textLine, $partBytes);
                $flush();
            }
            if ($buffer !== '' && mb_strlen($buffer . $textLine, 'UTF-8') > $maximum) {
                $flush();
            }
            if ($buffer === '') {
                $bufferLine = $line;
                $bufferByte = $byte;
            }
            $buffer .= $textLine;
            $byte += strlen($textLine);
            $line += substr_count($textLine, "\n") ?: 1;
        }
        $flush();

        return $pieces;
    }

    /** @param array<string,mixed>|null $module
     *  @return array<string,mixed>
     */
    private function skillMetadata(string $path, string $content, string $hash, ?array $module): array
    {
        $name = basename(dirname($path));
        $nameFromFrontmatter = false;
        $description = '';
        $triggers = [];
        if (preg_match('/^---\s*\R(.*?)\R---\s*\R/s', $content, $frontmatter) === 1) {
            if (preg_match('/^name:\s*["\']?(.+?)["\']?\s*$/mi', $frontmatter[1], $match) === 1) {
                $name = trim($match[1]);
                $nameFromFrontmatter = true;
            }
            if (preg_match('/^description:\s*["\']?(.+?)["\']?\s*$/mi', $frontmatter[1], $match) === 1) {
                $description = trim($match[1]);
            }
            if (preg_match('/^triggers?:\s*\[(.*?)\]\s*$/mi', $frontmatter[1], $match) === 1) {
                $triggers = array_values(array_filter(array_map(
                    static fn (string $value): string => trim($value, " \t\n\r\0\x0B\"'"),
                    explode(',', $match[1])
                )));
            }
        }
        if (!$nameFromFrontmatter && preg_match('/^#\s+(.+)$/mu', $content, $heading) === 1) {
            $name = trim($heading[1]);
        }
        if ($description === '' && preg_match('/\R\s*([^#\s][^\r\n]{20,})/u', $content, $paragraph) === 1) {
            $description = trim($paragraph[1]);
        }

        return [
            'skill_id' => 'skill-' . substr(hash('sha256', $path), 0, 40),
            'path' => $path,
            'name' => mb_substr($name, 0, 240, 'UTF-8'),
            'description' => mb_substr($description, 0, 2_000, 'UTF-8'),
            'module_vendor' => $module['vendor'] ?? '',
            'module_name' => $module['module'] ?? '',
            'triggers' => $triggers,
            'status' => str_starts_with($path, 'dev/ai/skills/') ? 'canonical' : 'candidate',
            'source_hash' => $hash,
            'metadata' => ['source' => 'repository', 'actionable' => str_starts_with($path, 'dev/ai/skills/')],
        ];
    }

    /** @param array<string,mixed> $write */
    private function writeFile(PDO $database, array $write, int $revision): void
    {
        $statement = $database->prepare(
            'INSERT INTO indexed_files(path, kind, language, module_vendor, module_name, size_bytes, mtime,
                                       content_hash, git_blob, revision, indexed_at, metadata_json)
             VALUES(:path, :kind, :language, :module_vendor, :module_name, :size, :mtime,
                    :content_hash, :git_blob, :revision, :indexed_at, :metadata_json)
             ON CONFLICT(path) DO UPDATE SET kind = excluded.kind, language = excluded.language,
                 module_vendor = excluded.module_vendor, module_name = excluded.module_name,
                 size_bytes = excluded.size_bytes, mtime = excluded.mtime, content_hash = excluded.content_hash,
                 git_blob = excluded.git_blob, revision = excluded.revision, indexed_at = excluded.indexed_at,
                 metadata_json = excluded.metadata_json'
        );
        $statement->execute([
            'path' => $write['path'],
            'kind' => $write['kind'],
            'language' => $write['language'],
            'module_vendor' => $write['module_vendor'],
            'module_name' => $write['module_name'],
            'size' => $write['size'],
            'mtime' => $write['mtime'],
            'content_hash' => $write['hash'],
            'git_blob' => '',
            'revision' => $revision,
            'indexed_at' => Clock::now(),
            'metadata_json' => Json::encode(['discovery' => 'git-ls-files', 'parser_version' => 1]),
        ]);
        $lookup = $database->prepare('SELECT id FROM indexed_files WHERE path = :path');
        $lookup->execute(['path' => $write['path']]);
        $fileId = (int) $lookup->fetchColumn();
        $storedContent = is_array($write['stored_content'] ?? null) ? $write['stored_content'] : [];
        $this->writeStoredContent($database, $fileId, (string) $write['hash'], $storedContent, $revision);
        foreach (['relations', 'skills', 'symbols', 'chunks'] as $table) {
            $delete = $database->prepare('DELETE FROM ' . $table . ' WHERE file_id = :file_id');
            $delete->execute(['file_id' => $fileId]);
        }

        $symbolChunks = [];
        foreach ($write['chunks'] as $chunk) {
            $insert = $database->prepare(
                'INSERT INTO chunks(chunk_id, file_id, kind, title, symbol_uid, start_line, end_line, start_byte,
                                    end_byte, content, content_hash, token_estimate, revision, metadata_json)
                 VALUES(:chunk_id, :file_id, :kind, :title, :symbol_uid, :start_line, :end_line, :start_byte,
                        :end_byte, :content, :content_hash, :token_estimate, :revision, :metadata_json)'
            );
            $insert->execute([
                'chunk_id' => $chunk['chunk_id'],
                'file_id' => $fileId,
                'kind' => $chunk['kind'],
                'title' => $chunk['title'],
                'symbol_uid' => $chunk['symbol_uid'],
                'start_line' => $chunk['start_line'],
                'end_line' => $chunk['end_line'],
                'start_byte' => $chunk['start_byte'],
                'end_byte' => $chunk['end_byte'],
                'content' => $chunk['content'],
                'content_hash' => $chunk['content_hash'],
                'token_estimate' => $chunk['token_estimate'],
                'revision' => $revision,
                'metadata_json' => Json::encode($chunk['metadata']),
            ]);
            if (is_string($chunk['symbol_uid']) && $chunk['symbol_uid'] !== '' && !isset($symbolChunks[$chunk['symbol_uid']])) {
                $symbolChunks[$chunk['symbol_uid']] = $chunk['chunk_id'];
            }
            $vectorStatement = $database->prepare(
                'INSERT INTO chunk_vector_terms(chunk_id, term_hash, weight) VALUES(:chunk_id, :term_hash, :weight)'
            );
            foreach ($this->vectorizer->vectorize(
                $chunk['title'] . "\n" . $write['path'] . "\n" . $chunk['content']
            ) as $termHash => $weight) {
                $vectorStatement->execute([
                    'chunk_id' => $chunk['chunk_id'],
                    'term_hash' => $termHash,
                    'weight' => $weight,
                ]);
            }
        }

        foreach ($write['symbols'] as $symbol) {
            $insert = $database->prepare(
                'INSERT INTO symbols(symbol_uid, file_id, chunk_id, name, fq_name, kind, namespace, signature,
                                     parent_uid, start_line, end_line, start_byte, end_byte, body_hash, revision, metadata_json)
                 VALUES(:symbol_uid, :file_id, :chunk_id, :name, :fq_name, :kind, :namespace, :signature,
                        :parent_uid, :start_line, :end_line, :start_byte, :end_byte, :body_hash, :revision, :metadata_json)'
            );
            $insert->execute([
                'symbol_uid' => $symbol['symbol_uid'],
                'file_id' => $fileId,
                'chunk_id' => $symbolChunks[$symbol['symbol_uid']] ?? null,
                'name' => $symbol['name'],
                'fq_name' => $symbol['fq_name'],
                'kind' => $symbol['kind'],
                'namespace' => $symbol['namespace'],
                'signature' => $symbol['signature'],
                'parent_uid' => $symbol['parent_uid'],
                'start_line' => $symbol['start_line'],
                'end_line' => $symbol['end_line'],
                'start_byte' => $symbol['start_byte'],
                'end_byte' => $symbol['end_byte'],
                'body_hash' => $symbol['body_hash'],
                'revision' => $revision,
                'metadata_json' => Json::encode($symbol['metadata']),
            ]);
        }
        foreach ($write['relations'] as $relation) {
            $insert = $database->prepare(
                'INSERT INTO relations(file_id, source_symbol_uid, target_name, target_symbol_uid, relation_kind,
                                       line, confidence, revision, metadata_json)
                 VALUES(:file_id, :source_symbol_uid, :target_name, NULL, :relation_kind,
                        :line, :confidence, :revision, :metadata_json)'
            );
            $insert->execute([
                'file_id' => $fileId,
                'source_symbol_uid' => $relation['source_symbol_uid'],
                'target_name' => $relation['target_name'],
                'relation_kind' => $relation['relation_kind'],
                'line' => $relation['line'],
                'confidence' => $relation['confidence'],
                'revision' => $revision,
                'metadata_json' => Json::encode($relation['metadata']),
            ]);
        }
        if (is_array($write['skill'])) {
            $skill = $write['skill'];
            $insert = $database->prepare(
                'INSERT INTO skills(skill_id, path, file_id, name, description, module_vendor, module_name,
                                    triggers_json, status, source_hash, revision, metadata_json)
                 VALUES(:skill_id, :path, :file_id, :name, :description, :module_vendor, :module_name,
                        :triggers_json, :status, :source_hash, :revision, :metadata_json)'
            );
            $insert->execute([
                'skill_id' => $skill['skill_id'],
                'path' => $skill['path'],
                'file_id' => $fileId,
                'name' => $skill['name'],
                'description' => $skill['description'],
                'module_vendor' => $skill['module_vendor'],
                'module_name' => $skill['module_name'],
                'triggers_json' => Json::encode($skill['triggers']),
                'status' => $skill['status'],
                'source_hash' => $skill['source_hash'],
                'revision' => $revision,
                'metadata_json' => Json::encode($skill['metadata']),
            ]);
        }
    }

    /** @param array<string,mixed> $storedContent */
    private function writeStoredContent(
        PDO $database,
        int $fileId,
        string $contentHash,
        array $storedContent,
        int $revision,
    ): void {
        $statement = $database->prepare(
            'INSERT INTO indexed_file_contents(file_id, content_blob, encoding, content_hash, original_bytes,
                                               stored_bytes, revision, indexed_at)
             VALUES(:file_id, :content_blob, :encoding, :content_hash, :original_bytes,
                    :stored_bytes, :revision, :indexed_at)
             ON CONFLICT(file_id) DO UPDATE SET content_blob = excluded.content_blob,
                 encoding = excluded.encoding, content_hash = excluded.content_hash,
                 original_bytes = excluded.original_bytes, stored_bytes = excluded.stored_bytes,
                 revision = excluded.revision, indexed_at = excluded.indexed_at'
        );
        $statement->bindValue(':file_id', $fileId, PDO::PARAM_INT);
        $statement->bindValue(':content_blob', (string) ($storedContent['blob'] ?? ''), PDO::PARAM_LOB);
        $statement->bindValue(':encoding', (string) ($storedContent['encoding'] ?? 'raw'));
        $statement->bindValue(':content_hash', $contentHash);
        $statement->bindValue(':original_bytes', (int) ($storedContent['original_bytes'] ?? 0), PDO::PARAM_INT);
        $statement->bindValue(':stored_bytes', (int) ($storedContent['stored_bytes'] ?? 0), PDO::PARAM_INT);
        $statement->bindValue(':revision', $revision, PDO::PARAM_INT);
        $statement->bindValue(':indexed_at', Clock::now());
        $statement->execute();
    }

    /** @param list<string> $paths */
    private function deletePaths(array $paths): int
    {
        $paths = array_values(array_unique(array_filter($paths, static fn (string $path): bool => $path !== '')));
        if ($paths === []) {
            return 0;
        }

        return $this->index->transaction(static function (PDO $database) use ($paths): int {
            $statement = $database->prepare('DELETE FROM indexed_files WHERE path = :path');
            $deleted = 0;
            foreach ($paths as $path) {
                $statement->execute(['path' => $path]);
                $deleted += $statement->rowCount();
            }

            return $deleted;
        });
    }

    private function resolveRelationTargets(): void
    {
        $this->index->transaction(static function (PDO $database): void {
            $database->exec('DROP TABLE IF EXISTS temp.symbol_lookup');
            $database->exec(
                'CREATE TEMP TABLE symbol_lookup (
                    lookup_name TEXT COLLATE NOCASE PRIMARY KEY,
                    symbol_uid TEXT NOT NULL
                ) WITHOUT ROWID'
            );
            $database->exec(
                "INSERT OR IGNORE INTO symbol_lookup(lookup_name, symbol_uid)
                 SELECT fq_name, symbol_uid FROM symbols WHERE fq_name <> ''
                 ORDER BY length(fq_name), fq_name, symbol_uid"
            );
            $database->exec(
                "INSERT OR IGNORE INTO symbol_lookup(lookup_name, symbol_uid)
                 SELECT name, symbol_uid FROM symbols WHERE name <> ''
                 ORDER BY name, symbol_uid"
            );
            $database->exec(
                "UPDATE relations
                    SET target_symbol_uid = (
                        SELECT lookup.symbol_uid FROM symbol_lookup AS lookup
                         WHERE lookup.lookup_name = relations.target_name
                    )
                  WHERE target_symbol_uid IS NULL
                    AND EXISTS (
                        SELECT 1 FROM symbol_lookup AS lookup
                         WHERE lookup.lookup_name = relations.target_name
                    )"
            );
            $database->exec('DROP TABLE temp.symbol_lookup');
        });
    }

    private function writeKnowledgeState(int $revision, string $completedAt): void
    {
        $this->index->transaction(static function (PDO $database) use ($revision, $completedAt): void {
            foreach (['documents' => ['doc', 'rule'], 'skills' => ['skill']] as $key => $kinds) {
                $placeholders = implode(',', array_fill(0, count($kinds), '?'));
                $statement = $database->prepare(
                    'SELECT COUNT(*) FROM indexed_files WHERE kind IN (' . $placeholders . ')'
                );
                $statement->execute($kinds);
                $value = [
                    'count' => (int) $statement->fetchColumn(),
                    'revision' => $revision,
                    'indexed_at' => $completedAt,
                ];
                $upsert = $database->prepare(
                    'INSERT INTO knowledge_state(state_key, value_json, revision, updated_at)
                     VALUES(:key, :value, :revision, :updated_at)
                     ON CONFLICT(state_key) DO UPDATE SET value_json = excluded.value_json,
                         revision = excluded.revision, updated_at = excluded.updated_at'
                );
                $upsert->execute([
                    'key' => $key,
                    'value' => Json::encode($value),
                    'revision' => $revision,
                    'updated_at' => $completedAt,
                ]);
            }
        });
    }

    /** @param list<string>|null $requested */
    private function inScope(string $path, ?array $requested): bool
    {
        if ($requested === null) {
            return true;
        }
        foreach ($requested as $scope) {
            if ($path === $scope || str_starts_with($path, rtrim($scope, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return resource */
    private function acquireLock()
    {
        $path = $this->index->path() . '.index.lock';
        $lock = fopen($path, 'c');
        if (!is_resource($lock)) {
            throw new RuntimeException('Unable to create project index lock');
        }
        chmod($path, 0600);
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Unable to acquire project index lock');
        }

        return $lock;
    }

    /** @param resource $lock */
    private function releaseLock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
