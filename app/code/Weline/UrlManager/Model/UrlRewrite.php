<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\UrlManager\Model;

use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'URL重写表')]
#[Index(name: 'idx_website_id', columns: ['website_id'])]
#[Index(name: 'UNQ_WEBSITE_URL_IDENTIFY', columns: ['website_id', 'url_identify'], type: 'UNIQUE')]
#[Index(name: 'idx_website_rewrite_latest', columns: ['website_id', 'rewrite', 'rewrite_id'])]
#[Index(name: 'idx_website_path_fingerprint_latest', columns: ['website_id', 'path_fingerprint', 'rewrite_id'])]
class UrlRewrite extends Model
{
    private const PATH_LOOKUP_BATCH_SIZE = 256;

    public const schema_table = 'url_rewrite';
    public const schema_primary_key = 'rewrite_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '重写ID')]
    public const schema_fields_ID = 'rewrite_id';
    #[Col('varchar', 255, comment: 'URL ID')]
    public const schema_fields_URL_ID = 'url_id';
    #[Col('varchar', 255, comment: 'URL 指纹')]
    public const schema_fields_URL_IDENTIFY = 'url_identify';
    #[Col('text', nullable: false, comment: 'URL路径')]
    public const schema_fields_PATH = 'path';
    #[Col('varchar', 64, nullable: true, comment: 'URL路径原始字节SHA-256指纹')]
    public const schema_fields_PATH_FINGERPRINT = 'path_fingerprint';
    #[Col('varchar', 255, nullable: false, comment: 'URL重写路径')]
    public const schema_fields_REWRITE = 'rewrite';
    #[Col('int', 11, nullable: false, default: 0, comment: '网站ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    public function save_before(): void
    {
        parent::save_before();

        $data = $this->getData();
        if (!\is_array($data) || !\array_key_exists(self::schema_fields_PATH, $data)) {
            // A partial update may not replace the derived value without its source path.
            if (\is_array($data) && \array_key_exists(self::schema_fields_PATH_FINGERPRINT, $data)) {
                $this->unsetModelData(self::schema_fields_PATH_FINGERPRINT);
                $this->unsetData(self::schema_fields_PATH_FINGERPRINT);
            }
            return;
        }

        $path = $data[self::schema_fields_PATH];
        if (!\is_string($path)) {
            throw new \InvalidArgumentException((string)__('路由重写路径必须是字符串。'));
        }

        $this->setData(self::schema_fields_PATH_FINGERPRINT, self::pathFingerprint($path));
    }

    public function save_after()
    {
        parent::save_after();
        self::invalidateSeoRewriteCaches();
    }

    public function delete_after(): void
    {
        parent::delete_after();
        self::invalidateSeoRewriteCaches();
    }

    public static function invalidateSeoRewriteCaches(): void
    {
        \Weline\UrlManager\Observer\SeoUrlGenerateRewrite::invalidateCaches();
    }

    public static function pathFingerprint(string $path): string
    {
        return \hash('sha256', $path);
    }

    /**
     * Resolve the newest row by exact raw path bytes.
     *
     * The digest is only an index key. The original path is always compared in
     * PHP so database collation and a theoretical SHA-256 collision cannot
     * change the public exact-match contract.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestByWebsiteAndPath(int $websiteId, string $path): ?array
    {
        $fingerprint = self::pathFingerprint($path);
        $query = $this->newQuery();
        // Preserve the AND/OR order: each candidate arm is website-scoped.
        // This disables framework condition reordering, not database indexes.
        $query->_index_sort_keys = [];
        $rows = $query
            ->where(self::schema_fields_WEBSITE_ID, $websiteId, '=', 'AND')
            ->where(self::schema_fields_PATH_FINGERPRINT, [$fingerprint, ''], 'IN', 'OR')
            ->where(self::schema_fields_WEBSITE_ID, $websiteId, '=', 'AND')
            ->where(self::schema_fields_PATH_FINGERPRINT, null, 'IS NULL')
            ->order(self::schema_fields_ID, 'DESC')
            ->select()
            ->fetchArray();

        // Keep migration precedence independent of the overall ID ordering:
        // matching fingerprint, then NULL legacy rows, then empty legacy rows.
        // Non-empty mismatched fingerprints never enter the legacy fallback.
        return $this->findExactPathRow($rows, $path, $fingerprint)
            ?? $this->findExactPathRow($rows, $path, null)
            ?? $this->findExactPathRow($rows, $path, '');
    }

    /**
     * 批量读取原始路径；各路径仍按匹配指纹、NULL 旧记录、空指纹旧记录选择最新项。
     *
     * @param list<string> $paths
     * @return array<string, array<string, mixed>|null>
     */
    public function findLatestByWebsiteAndPaths(int $websiteId, array $paths): array
    {
        $requested = [];
        $result = [];
        foreach ($paths as $path) {
            $requested[$path] = ['path' => $path, 'fingerprint' => self::pathFingerprint($path)];
            $result[$path] = null;
        }

        foreach (array_chunk($requested, self::PATH_LOOKUP_BATCH_SIZE, true) as $batch) {
            $fingerprints = array_values(array_unique(array_column($batch, 'fingerprint')));
            $fingerprints[] = '';
            $batchPaths = array_column($batch, 'path');
            $query = $this->newQuery();
            // 每个 OR 分支独立限定网站，避免条件重排将旧记录扩到其他网站。
            $query->_index_sort_keys = [];
            $rows = $query
                ->where(self::schema_fields_WEBSITE_ID, $websiteId, '=', 'AND')
                ->where(self::schema_fields_PATH, $batchPaths, 'IN', 'AND')
                ->where(self::schema_fields_PATH_FINGERPRINT, $fingerprints, 'IN', 'OR')
                ->where(self::schema_fields_WEBSITE_ID, $websiteId, '=', 'AND')
                ->where(self::schema_fields_PATH, $batchPaths, 'IN', 'AND')
                ->where(self::schema_fields_PATH_FINGERPRINT, null, 'IS NULL')
                ->order(self::schema_fields_ID, 'DESC')
                ->select()
                ->fetchArray();

            // 保留单条读取对关联数组形式的兼容，缺失结果仍按空列表处理。
            if (!is_array($rows)) {
                $rows = [];
            } elseif (array_key_exists(self::schema_fields_ID, $rows)) {
                $rows = [$rows];
            }
            // 先按原始路径分组，避免每个请求路径重复遍历网站的全部旧记录。
            $rowsByPath = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $path = $row[self::schema_fields_PATH] ?? null;
                if (is_string($path) && array_key_exists($path, $batch)) {
                    $rowsByPath[$path][] = $row;
                }
            }
            foreach ($batch as $key => $request) {
                $candidates = $rowsByPath[$key] ?? [];
                $result[$key] = $this->findExactPathRow($candidates, $request['path'], $request['fingerprint'])
                    ?? $this->findExactPathRow($candidates, $request['path'], null)
                    ?? $this->findExactPathRow($candidates, $request['path'], '');
            }
        }

        return $result;
    }

    /**
     * @param mixed $rows
     * @return array<string, mixed>|null
     */
    private function findExactPathRow(mixed $rows, string $path, ?string $fingerprint): ?array
    {
        if (!\is_array($rows)) {
            return null;
        }
        if (\array_key_exists(self::schema_fields_ID, $rows)) {
            $rows = [$rows];
        }

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $storedPath = $row[self::schema_fields_PATH] ?? null;
            if (!\is_string($storedPath) || $storedPath !== $path) {
                continue;
            }

            $storedFingerprint = $row[self::schema_fields_PATH_FINGERPRINT] ?? null;
            if ($fingerprint === null && $storedFingerprint === null) {
                return $row;
            }
            if ($fingerprint !== null && \is_string($storedFingerprint) && \hash_equals($fingerprint, $storedFingerprint)) {
                return $row;
            }
        }

        return null;
    }

/**
     * 获取当前请求的网站ID
     * 
     * @return int 网站ID，默认为0
     */
    public static function getCurrentWebsiteId(): int
    {
        $websiteId = WelineEnv::get('website_id', null);
        if ($websiteId === null || $websiteId === '') {
            $websiteId = WelineEnv::server('WELINE_WEBSITE_ID', '');
        }
        if ($websiteId === '' || $websiteId === null) {
            return 0;
        }
        return (int)$websiteId;
    }
}
