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
        $rows = $this->newQuery()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_PATH_FINGERPRINT, $fingerprint)
            ->order(self::schema_fields_ID, 'DESC')
            ->select()
            ->fetchArray();

        $exact = $this->findExactPathRow($rows, $path, $fingerprint, false);
        if ($exact !== null) {
            return $exact;
        }

        // Phase-1 migration compatibility: only rows with a missing derived
        // value may use the legacy path predicate. A non-empty mismatched
        // fingerprint fails closed instead of silently bypassing integrity.
        $legacyRows = $this->newQuery()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_PATH_FINGERPRINT, null, 'IS NULL')
            ->order(self::schema_fields_ID, 'DESC')
            ->select()
            ->fetchArray();
        $exact = $this->findExactPathRow($legacyRows, $path, $fingerprint, true);
        if ($exact !== null) {
            return $exact;
        }

        $legacyEmptyRows = $this->newQuery()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_PATH_FINGERPRINT, '')
            ->order(self::schema_fields_ID, 'DESC')
            ->select()
            ->fetchArray();

        return $this->findExactPathRow($legacyEmptyRows, $path, $fingerprint, true);
    }

    /**
     * @param mixed $rows
     * @return array<string, mixed>|null
     */
    private function findExactPathRow(mixed $rows, string $path, string $fingerprint, bool $missingFingerprintOnly): ?array
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
            if ($missingFingerprintOnly) {
                if ($storedFingerprint === null || $storedFingerprint === '') {
                    return $row;
                }
                continue;
            }
            if (\is_string($storedFingerprint) && \hash_equals($fingerprint, $storedFingerprint)) {
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
