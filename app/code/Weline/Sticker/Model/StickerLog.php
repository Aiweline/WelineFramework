<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'Sticker 操作日志表')]
#[Index(name: 'idx_level', columns: ['level'])]
#[Index(name: 'idx_target_module', columns: ['target_module'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
class StickerLog extends Model
{

    public const schema_table = 'sticker_log';
    public const schema_primary_key = 'log_id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '日志ID')]
    public const schema_fields_LOG_ID = 'log_id';
    #[Col('varchar', 20, nullable: false, comment: '日志级别')]
    public const schema_fields_LEVEL = 'level';
    #[Col('varchar', 255, nullable: false, comment: '目标模块名')]
    public const schema_fields_TARGET_MODULE = 'target_module';
    #[Col('varchar', 500, nullable: false, comment: '目标文件路径')]
    public const schema_fields_TARGET_FILE = 'target_file';
    #[Col('varchar', 255, nullable: false, comment: 'Sticker 来源模块')]
    public const schema_fields_SOURCE_MODULE = 'source_module';
    #[Col('varchar', 500, nullable: false, comment: 'Sticker 文件路径')]
    public const schema_fields_STICKER_FILE = 'sticker_file';
    #[Col('text', nullable: false, comment: '错误或警告消息')]
    public const schema_fields_MESSAGE = 'message';
    #[Col('text', comment: '详细信息JSON')]
    public const schema_fields_DETAILS = 'details';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
/**
     * 记录日志
     *
     * @param string $level 日志级别
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件
     * @param string $sourceModule 来源模块
     * @param string $stickerFile Sticker 文件
     * @param string $message 消息
     * @param array|null $details 详细信息
     * @return static
     */
    public function log(
        string $level,
        string $targetModule,
        string $targetFile,
        string $sourceModule,
        string $stickerFile,
        string $message,
        ?array $details = null
    ): static {
        $this->setLevel($level)
            ->setTargetModule($targetModule)
            ->setTargetFile($targetFile)
            ->setSourceModule($sourceModule)
            ->setStickerFile($stickerFile)
            ->setMessage($message)
            ->setDetails($details)
            ->setCreatedAt(date('Y-m-d H:i:s'))
            ->save();
        return $this;
    }

    public function getLevel(): string
    {
        return $this->getData(self::schema_fields_LEVEL) ?? '';
    }

    public function setLevel(string $level): static
    {
        $this->setData(self::schema_fields_LEVEL, $level);
        return $this;
    }

    public function getTargetModule(): string
    {
        return $this->getData(self::schema_fields_TARGET_MODULE) ?? '';
    }

    public function setTargetModule(string $targetModule): static
    {
        $this->setData(self::schema_fields_TARGET_MODULE, $targetModule);
        return $this;
    }

    public function getTargetFile(): string
    {
        return $this->getData(self::schema_fields_TARGET_FILE) ?? '';
    }

    public function setTargetFile(string $targetFile): static
    {
        $this->setData(self::schema_fields_TARGET_FILE, $targetFile);
        return $this;
    }

    public function getSourceModule(): string
    {
        return $this->getData(self::schema_fields_SOURCE_MODULE) ?? '';
    }

    public function setSourceModule(string $sourceModule): static
    {
        $this->setData(self::schema_fields_SOURCE_MODULE, $sourceModule);
        return $this;
    }

    public function getStickerFile(): string
    {
        return $this->getData(self::schema_fields_STICKER_FILE) ?? '';
    }

    public function setStickerFile(string $stickerFile): static
    {
        $this->setData(self::schema_fields_STICKER_FILE, $stickerFile);
        return $this;
    }

    public function getMessage(): string
    {
        return $this->getData(self::schema_fields_MESSAGE) ?? '';
    }

    public function setMessage(string $message): static
    {
        $this->setData(self::schema_fields_MESSAGE, $message);
        return $this;
    }

    public function getDetails(): ?array
    {
        $details = $this->getData(self::schema_fields_DETAILS);
        if (empty($details)) {
            return null;
        }
        if (is_string($details)) {
            return json_decode($details, true);
        }
        return $details;
    }

    public function setDetails(?array $details): static
    {
        $this->setData(self::schema_fields_DETAILS, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null);
        return $this;
    }

    public function getCreatedAt(): string
    {
        return $this->getData(self::schema_fields_CREATED_AT) ?? '';
    }

    public function setCreatedAt(string $createdAt): static
    {
        $this->setData(self::schema_fields_CREATED_AT, $createdAt);
        return $this;
    }
}


