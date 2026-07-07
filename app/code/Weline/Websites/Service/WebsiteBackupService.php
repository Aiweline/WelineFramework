<?php
declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Database\Model;
use Weline\Websites\Model\Website;

class WebsiteBackupService
{
    public const TYPE_FULL = 'full';
    public const TYPE_DATABASE = 'database';
    public const TYPE_FILES = 'files';

    private const BACKUP_DIR = 'var/backups/websites';
    private const WEBSITE_FIELD = 'website_id';
    private const SAFE_FILE_ROOTS = [
        'pub/media',
        'pub/source',
        'pub/sitemaps',
        'var/pagebuilder',
        'var/export',
    ];

    public function __construct(private Website $website)
    {
    }

    /**
     * @return array<string, string>
     */
    public function getTypeOptions(): array
    {
        return [
            self::TYPE_FULL => (string)__('完整备份'),
            self::TYPE_DATABASE => (string)__('仅站点数据'),
            self::TYPE_FILES => (string)__('仅关联文件'),
        ];
    }

    public function normalizeType(string $type): string
    {
        $type = \trim($type);
        if (!\array_key_exists($type, $this->getTypeOptions())) {
            throw new \InvalidArgumentException((string)__('不支持的备份类型：%{1}', $type));
        }
        return $type;
    }

    /**
     * @return array<string, mixed>
     */
    public function createBackup(int $websiteId, string $type, int $createdBy = 0): array
    {
        $type = $this->normalizeType($type);
        $website = $this->loadWebsite($websiteId);
        $database = $this->collectDatabase($websiteId);
        $files = \in_array($type, [self::TYPE_FULL, self::TYPE_FILES], true)
            ? $this->collectFiles($website, $database)
            : ['items' => [], 'missing' => []];

        $createdAt = \date('Y-m-d H:i:s');
        $stamp = \date('Ymd-His');
        $safeCode = $this->safeSegment((string)($website[Website::schema_fields_CODE] ?? 'website'));
        $filename = 'website-' . $websiteId . '-' . $safeCode . '-' . $type . '-' . $stamp . '-' . \bin2hex(\random_bytes(4)) . '.zip';
        $archivePath = $this->backupDir() . \DIRECTORY_SEPARATOR . $filename;

        $manifest = [
            'schema_version' => 1,
            'backup_kind' => 'website',
            'backup_type' => $type,
            'backup_type_label' => $this->getTypeOptions()[$type],
            'website' => $website,
            'created_at' => $createdAt,
            'created_by' => $createdBy,
            'database' => [
                'included' => \in_array($type, [self::TYPE_FULL, self::TYPE_DATABASE], true),
                'tables' => $database['tables'],
                'row_count' => $database['row_count'],
                'skipped' => $database['skipped'],
            ],
            'files' => [
                'included' => \in_array($type, [self::TYPE_FULL, self::TYPE_FILES], true),
                'file_count' => \count($files['items']),
                'missing_count' => \count($files['missing']),
                'missing' => $files['missing'],
            ],
            'restore_note' => (string)__('此归档用于站点级数据和文件审计备份；恢复前应先在测试环境校验 manifest、表清单和文件校验值。'),
        ];

        $checksums = [];
        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException((string)__('无法创建网站备份归档。'));
        }

        try {
            $this->addJson($zip, 'manifest.json', $manifest, $checksums);

            if (\in_array($type, [self::TYPE_FULL, self::TYPE_DATABASE], true)) {
                foreach ($database['exports'] as $entry) {
                    $this->addJson($zip, 'database/' . $entry['file'], $entry['payload'], $checksums);
                }
            }

            if (\in_array($type, [self::TYPE_FULL, self::TYPE_FILES], true)) {
                foreach ($files['items'] as $item) {
                    if (!$zip->addFile($item['path'], $item['archive_path'])) {
                        throw new \RuntimeException((string)__('无法写入备份文件：%{1}', $item['archive_path']));
                    }
                    $checksums[$item['archive_path']] = [
                        'sha256' => \hash_file('sha256', $item['path']),
                        'size' => \filesize($item['path']) ?: 0,
                    ];
                }
            }

            $checksumsJson = $this->json($checksums);
            if (!$zip->addFromString('checksums.json', $checksumsJson)) {
                throw new \RuntimeException((string)__('无法写入备份内容：%{1}', 'checksums.json'));
            }
        } catch (\Throwable $throwable) {
            $zip->close();
            @\unlink($archivePath);
            throw $throwable;
        }

        if (!$zip->close()) {
            @\unlink($archivePath);
            throw new \RuntimeException((string)__('无法完成网站备份归档写入。'));
        }

        $size = \is_file($archivePath) ? (\filesize($archivePath) ?: 0) : 0;
        $manifest['filename'] = $filename;
        $manifest['size'] = $size;
        $manifest['sha256'] = \hash_file('sha256', $archivePath);

        return $this->normalizeListItem($filename, $archivePath, $manifest);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listBackups(): array
    {
        $dir = $this->backupDir();
        $items = [];
        foreach (\glob($dir . \DIRECTORY_SEPARATOR . '*.zip') ?: [] as $path) {
            if (!\is_file($path)) {
                continue;
            }
            $filename = \basename($path);
            $items[] = $this->normalizeListItem($filename, $path, $this->readManifest($path));
        }

        \usort(
            $items,
            static fn(array $a, array $b): int => \strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''))
        );

        return $items;
    }

    public function getBackupPath(string $filename): string
    {
        return $this->resolveBackupPath($filename);
    }

    public function deleteBackup(string $filename): bool
    {
        $path = $this->resolveBackupPath($filename);
        return \unlink($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadWebsite(int $websiteId): array
    {
        $rows = $this->website->reset()
            ->where(Website::schema_fields_ID, $websiteId)
            ->limit(1)
            ->select()
            ->fetchArray();

        $website = $rows[0] ?? null;
        if (!\is_array($website) || !\array_key_exists(Website::schema_fields_ID, $website)) {
            throw new \InvalidArgumentException((string)__('网站不存在：%{1}', (string)$websiteId));
        }

        return $website;
    }

    /**
     * @return array{exports:list<array{file:string,payload:array<string,mixed>}>, tables:list<array<string,mixed>>, skipped:list<array<string,string>>, row_count:int, rows:list<array<string,mixed>>}
     */
    private function collectDatabase(int $websiteId): array
    {
        $connector = $this->website->getConnection()->getConnector();
        $exports = [];
        $tables = [];
        $skipped = [];
        $allRows = [];
        $rowCount = 0;

        foreach ($this->discoverWebsiteScopedTables() as $definition) {
            $table = $definition['table'];
            try {
                if (!$connector->tableExist($table)) {
                    $skipped[] = ['table' => $table, 'reason' => (string)__('数据表不存在')];
                    continue;
                }

                $columns = $connector->getTableColumns($table);
                $columnNames = \array_map(static fn(array $column): string => (string)($column['name'] ?? ''), $columns);
                if (!\in_array(self::WEBSITE_FIELD, $columnNames, true)) {
                    $skipped[] = ['table' => $table, 'reason' => (string)__('缺少 website_id 字段')];
                    continue;
                }

                $rows = $connector->getQuery()
                    ->clearQuery()
                    ->table($table)
                    ->where(self::WEBSITE_FIELD, $websiteId)
                    ->select()
                    ->fetchArray();

                $payload = [
                    'table' => $table,
                    'model' => $definition['class'],
                    'website_field' => self::WEBSITE_FIELD,
                    'columns' => $columns,
                    'rows' => $rows,
                ];
                $exports[] = [
                    'file' => $this->safeSegment($table) . '.json',
                    'payload' => $payload,
                ];
                $tables[] = [
                    'table' => $table,
                    'model' => $definition['class'],
                    'row_count' => \count($rows),
                ];
                $rowCount += \count($rows);
                foreach ($rows as $row) {
                    if (\is_array($row)) {
                        $allRows[] = $row;
                    }
                }
            } catch (\Throwable $throwable) {
                $skipped[] = ['table' => $table, 'reason' => $throwable->getMessage()];
            }
        }

        return [
            'exports' => $exports,
            'tables' => $tables,
            'skipped' => $skipped,
            'row_count' => $rowCount,
            'rows' => $allRows,
        ];
    }

    /**
     * @return list<array{table:string,class:string}>
     */
    private function discoverWebsiteScopedTables(): array
    {
        $base = \defined('APP_CODE_PATH')
            ? \rtrim((string)APP_CODE_PATH, '\\/')
            : \rtrim((string)BP, '\\/') . \DIRECTORY_SEPARATOR . 'app' . \DIRECTORY_SEPARATOR . 'code';
        if (!\is_dir($base)) {
            return [[
                'table' => Website::schema_table,
                'class' => Website::class,
            ]];
        }

        $tables = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = \substr($file->getPathname(), \strlen($base) + 1);
            if (!\str_contains($relative, \DIRECTORY_SEPARATOR . 'Model' . \DIRECTORY_SEPARATOR)) {
                continue;
            }

            $class = \str_replace(\DIRECTORY_SEPARATOR, '\\', \substr($relative, 0, -4));
            if (!\class_exists($class)) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($class);
                if (!$reflection->isSubclassOf(Model::class)) {
                    continue;
                }

                $constants = $reflection->getConstants();
                $table = (string)($constants['schema_table'] ?? '');
                if ($table === '') {
                    continue;
                }

                $hasWebsiteField = false;
                foreach ($constants as $name => $value) {
                    if (\str_starts_with((string)$name, 'schema_fields_') && $value === self::WEBSITE_FIELD) {
                        $hasWebsiteField = true;
                        break;
                    }
                }

                if (!$hasWebsiteField) {
                    continue;
                }

                $tables[$table] = [
                    'table' => $table,
                    'class' => $class,
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        $tables[Website::schema_table] = [
            'table' => Website::schema_table,
            'class' => Website::class,
        ];
        \ksort($tables);

        return \array_values($tables);
    }

    /**
     * @param array<string, mixed> $website
     * @param array{rows:list<array<string,mixed>>} $database
     * @return array{items:list<array{path:string,archive_path:string}>, missing:list<string>}
     */
    private function collectFiles(array $website, array $database): array
    {
        $items = [];
        $missing = [];
        $seen = [];

        foreach ($this->wellKnownDirectories($website) as $directory) {
            $real = \realpath($this->absolutePath($directory));
            if ($real === false || !\is_dir($real)) {
                continue;
            }
            foreach ($this->iterateFiles($real) as $path) {
                $this->rememberFile($path, $items, $seen);
            }
        }

        foreach ($database['rows'] as $row) {
            foreach ($this->extractStrings($row) as $value) {
                foreach ($this->extractPotentialPaths($value) as $candidate) {
                    $resolved = $this->resolveSafeFile($candidate);
                    if ($resolved === null) {
                        continue;
                    }
                    if ($resolved['exists']) {
                        $this->rememberFile($resolved['path'], $items, $seen);
                    } else {
                        $missing[] = $candidate;
                    }
                }
            }
        }

        $missing = \array_values(\array_unique($missing));

        return ['items' => \array_values($items), 'missing' => $missing];
    }

    /**
     * @param array<string, mixed> $website
     * @return list<string>
     */
    private function wellKnownDirectories(array $website): array
    {
        $id = (string)($website[Website::schema_fields_ID] ?? '');
        $code = $this->safeSegment((string)($website[Website::schema_fields_CODE] ?? ''));
        $parts = \array_filter([$id, $code], static fn(string $part): bool => $part !== '');
        $dirs = [];

        foreach ($parts as $part) {
            $dirs[] = 'pub/media/website/' . $part;
            $dirs[] = 'pub/media/websites/' . $part;
            $dirs[] = 'pub/source/website/' . $part;
            $dirs[] = 'pub/sitemaps/' . $part;
            $dirs[] = 'var/pagebuilder/website/' . $part;
            $dirs[] = 'var/pagebuilder/websites/' . $part;
        }

        return $dirs;
    }

    /**
     * @return list<string>
     */
    private function iterateFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    /**
     * @param list<array{path:string,archive_path:string}> $items
     * @param array<string, bool> $seen
     */
    private function rememberFile(string $path, array &$items, array &$seen): void
    {
        $real = \realpath($path);
        if ($real === false || !\is_file($real) || isset($seen[$real])) {
            return;
        }

        $relative = $this->relativePath($real);
        if ($relative === null || !$this->isInSafeFileRoot($relative)) {
            return;
        }

        $seen[$real] = true;
        $items[] = [
            'path' => $real,
            'archive_path' => 'files/' . \str_replace('\\', '/', $relative),
        ];
    }

    /**
     * @return list<string>
     */
    private function extractStrings(mixed $value): array
    {
        if (\is_string($value)) {
            return [$value];
        }
        if (!\is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            $strings = \array_merge($strings, $this->extractStrings($item));
        }
        return $strings;
    }

    /**
     * @return list<string>
     */
    private function extractPotentialPaths(string $value): array
    {
        $decoded = \html_entity_decode(\trim($value), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        if ($decoded === '') {
            return [];
        }

        $matches = [];
        \preg_match_all(
            '#(?:https?://[^\s"\'<>]+|/?(?:pub/)?(?:media|source|sitemaps)/[^\s"\'<>]+|/?var/(?:pagebuilder|export)/[^\s"\'<>]+)#i',
            $decoded,
            $matches
        );

        return \array_values(\array_unique($matches[0] ?? []));
    }

    /**
     * @return array{exists:bool,path:string}|null
     */
    private function resolveSafeFile(string $candidate): ?array
    {
        $path = \trim($candidate);
        if ($path === '') {
            return null;
        }

        if (\preg_match('#^https?://#i', $path)) {
            $path = (string)(\parse_url($path, \PHP_URL_PATH) ?: '');
        }

        $path = \rawurldecode(\str_replace('\\', '/', $path));
        $path = \ltrim($path, '/');
        if (\str_starts_with($path, 'media/') || \str_starts_with($path, 'source/') || \str_starts_with($path, 'sitemaps/')) {
            $path = 'pub/' . $path;
        }

        if (!$this->isInSafeFileRoot($path)) {
            return null;
        }

        $absolute = $this->absolutePath($path);
        $real = \realpath($absolute);
        if ($real === false || !\is_file($real)) {
            return ['exists' => false, 'path' => $absolute];
        }

        if (!$this->pathWithin($real, (string)BP)) {
            return null;
        }

        return ['exists' => true, 'path' => $real];
    }

    private function isInSafeFileRoot(string $relative): bool
    {
        $relative = \str_replace('\\', '/', \ltrim($relative, '/'));
        foreach (self::SAFE_FILE_ROOTS as $root) {
            if ($relative === $root || \str_starts_with($relative, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $checksums
     */
    private function addJson(\ZipArchive $zip, string $name, mixed $payload, array &$checksums): void
    {
        $json = $this->json($payload);
        if (!$zip->addFromString($name, $json)) {
            throw new \RuntimeException((string)__('无法写入备份内容：%{1}', $name));
        }
        $checksums[$name] = [
            'sha256' => \hash('sha256', $json),
            'size' => \strlen($json),
        ];
    }

    private function readManifest(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }
        $json = $zip->getFromName('manifest.json');
        $zip->close();
        if (!\is_string($json) || $json === '') {
            return [];
        }
        $manifest = \json_decode($json, true);
        return \is_array($manifest) ? $manifest : [];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function normalizeListItem(string $filename, string $path, array $manifest): array
    {
        $website = \is_array($manifest['website'] ?? null) ? $manifest['website'] : [];
        $database = \is_array($manifest['database'] ?? null) ? $manifest['database'] : [];
        $files = \is_array($manifest['files'] ?? null) ? $manifest['files'] : [];

        return [
            'filename' => $filename,
            'website_id' => (int)($website[Website::schema_fields_ID] ?? 0),
            'website_name' => (string)($website[Website::schema_fields_NAME] ?? '-'),
            'website_code' => (string)($website[Website::schema_fields_CODE] ?? ''),
            'type' => (string)($manifest['backup_type'] ?? ''),
            'type_label' => (string)($manifest['backup_type_label'] ?? ($manifest['backup_type'] ?? '-')),
            'size' => \is_file($path) ? (\filesize($path) ?: 0) : 0,
            'created_at' => (string)($manifest['created_at'] ?? \date('Y-m-d H:i:s', (int)\filemtime($path))),
            'database_rows' => (int)($database['row_count'] ?? 0),
            'database_tables' => \is_array($database['tables'] ?? null) ? \count($database['tables']) : 0,
            'file_count' => (int)($files['file_count'] ?? 0),
            'missing_file_count' => (int)($files['missing_count'] ?? 0),
            'sha256' => \is_file($path) ? \hash_file('sha256', $path) : '',
        ];
    }

    private function resolveBackupPath(string $filename): string
    {
        $filename = \trim($filename);
        if ($filename === '' || $filename !== \basename($filename) || !\str_ends_with($filename, '.zip')) {
            throw new \InvalidArgumentException((string)__('无效的备份文件名。'));
        }

        $dir = $this->backupDir();
        $path = $dir . \DIRECTORY_SEPARATOR . $filename;
        $real = \realpath($path);
        $dirReal = \realpath($dir);
        if ($real === false || $dirReal === false || !\is_file($real) || !$this->pathWithin($real, $dirReal)) {
            throw new \RuntimeException((string)__('备份文件不存在。'));
        }

        return $real;
    }

    private function backupDir(): string
    {
        $dir = $this->absolutePath(self::BACKUP_DIR);
        if (!\is_dir($dir) && !\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new \RuntimeException((string)__('无法创建网站备份目录。'));
        }
        return $dir;
    }

    private function absolutePath(string $relative): string
    {
        return \rtrim((string)BP, '\\/') . \DIRECTORY_SEPARATOR . \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, \ltrim($relative, '/\\'));
    }

    private function relativePath(string $absolute): ?string
    {
        $bp = \rtrim((string)\realpath((string)BP), '\\/') . \DIRECTORY_SEPARATOR;
        $absolute = \str_replace('\\', \DIRECTORY_SEPARATOR, $absolute);
        if (!\str_starts_with($absolute, $bp)) {
            return null;
        }
        return \substr($absolute, \strlen($bp));
    }

    private function pathWithin(string $path, string $root): bool
    {
        $path = \rtrim(\str_replace('\\', '/', $path), '/');
        $root = \rtrim(\str_replace('\\', '/', $root), '/');
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    private function safeSegment(string $value): string
    {
        $value = \trim($value);
        $value = \preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $value) ?: 'site';
        return \trim($value, '-_.') ?: 'site';
    }

    private function json(mixed $payload): string
    {
        $json = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException((string)__('备份数据 JSON 编码失败。'));
        }
        return $json;
    }
}
