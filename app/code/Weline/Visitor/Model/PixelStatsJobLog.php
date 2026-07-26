<?php

declare(strict_types=1);

namespace Weline\Visitor\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * G03：像素聚合任务日志（仅 schema；不跑 Cron，写入方为 G04/G05）。
 *
 * 用途（§2.5）：
 * - 每个聚合桶一行：唯一键 `(job_type, bucket, website_id)`，重跑覆盖同一行；
 * - `tz` 记录该桶实际使用的站点时区（无则 UTC），防时区错桶无法追溯；
 * - `check_json` 存 G05 校验摘要（日表 events 与热表同日 COUNT 的
 *   相对误差 ≤ 2% 或绝对差 ≤ 5 的比对明细）；
 * - `status=success` 是 G08 Retention 迁冷/删热的**唯一放行凭据**：
 *   失败或缺行的日期一律跳过，绝不因日志缺失默认放行。
 */
#[Table(comment: '像素聚合任务日志 G03')]
#[Index(name: 'uk_pixel_stats_job_log_bucket', columns: ['job_type', 'bucket', 'website_id'], type: 'UNIQUE')]
#[Index(name: 'idx_pixel_stats_job_log_gate', columns: ['job_type', 'status', 'bucket'])]
#[Index(name: 'idx_pixel_stats_job_log_site', columns: ['website_id', 'bucket'])]
class PixelStatsJobLog extends Model
{
    public const schema_table = 'pixel_stats_job_log';
    public const schema_primary_key = 'pixel_stats_job_log_id';

    public const JOB_HOURLY = 'hourly';
    public const JOB_DAILY = 'daily';

    /** @var list<string> */
    public const JOB_TYPES = [self::JOB_HOURLY, self::JOB_DAILY];

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
    ];

    #[Col('bigint', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '主键')]
    public const schema_fields_ID = 'pixel_stats_job_log_id';

    #[Col('varchar', 16, nullable: false, comment: '任务类型 hourly|daily')]
    public const schema_fields_JOB_TYPE = 'job_type';

    #[Col('datetime', nullable: false, comment: '聚合桶（hourly=整点；daily=当日 00:00:00，站点时区）')]
    public const schema_fields_BUCKET = 'bucket';

    #[Col('int', 0, nullable: false, default: 0, comment: '站点ID；0=系统默认站点')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 64, nullable: false, default: 'UTC', comment: '该桶所用时区')]
    public const schema_fields_TZ = 'tz';

    #[Col('varchar', 16, nullable: false, default: self::STATUS_PENDING, comment: '状态 pending|running|success|failed')]
    public const schema_fields_STATUS = 'status';

    #[Col('int', 0, nullable: false, default: 0, comment: '累计执行次数（含重跑）')]
    public const schema_fields_ATTEMPTS = 'attempts';

    #[Col('text', comment: 'G05 校验摘要 JSON（expected/actual/rel_error/abs_diff 等）')]
    public const schema_fields_CHECK_JSON = 'check_json';

    #[Col('varchar', 512, comment: '失败原因摘要')]
    public const schema_fields_MESSAGE = 'message';

    #[Col('datetime', comment: '本次开始时间')]
    public const schema_fields_STARTED_AT = 'started_at';

    #[Col('datetime', comment: '本次结束时间')]
    public const schema_fields_FINISHED_AT = 'finished_at';

    #[Col('datetime', comment: '首次写入时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', comment: '最后重跑覆盖时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public static function isValidJobType(string $jobType): bool
    {
        return \in_array($jobType, self::JOB_TYPES, true);
    }

    public static function isValidStatus(string $status): bool
    {
        return \in_array($status, self::STATUSES, true);
    }

    public function getPixelStatsJobLogId(): int
    {
        return (int)$this->getData(self::schema_fields_ID);
    }

    public function getJobType(): string
    {
        return (string)$this->getData(self::schema_fields_JOB_TYPE);
    }

    public function setJobType(string $jobType): static
    {
        return $this->setData(self::schema_fields_JOB_TYPE, $jobType);
    }

    public function getBucket(): string
    {
        return (string)$this->getData(self::schema_fields_BUCKET);
    }

    public function setBucket(string $bucket): static
    {
        return $this->setData(self::schema_fields_BUCKET, $bucket);
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData(self::schema_fields_WEBSITE_ID);
    }

    public function setWebsiteId(int $websiteId): static
    {
        return $this->setData(self::schema_fields_WEBSITE_ID, $websiteId);
    }

    public function getTz(): string
    {
        return (string)$this->getData(self::schema_fields_TZ);
    }

    public function setTz(string $tz): static
    {
        return $this->setData(self::schema_fields_TZ, $tz);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::schema_fields_STATUS);
    }

    public function setStatus(string $status): static
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    /**
     * G08 门禁：只有显式 success 才放行；缺行/其他状态一律不放行。
     */
    public function isSuccess(): bool
    {
        return $this->getStatus() === self::STATUS_SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->getStatus() === self::STATUS_FAILED;
    }
}
