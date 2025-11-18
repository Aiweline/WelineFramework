<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class StickerLog extends Model
{
    public const fields_LOG_ID = 'log_id';
    public const fields_LEVEL = 'level';
    public const fields_TARGET_MODULE = 'target_module';
    public const fields_TARGET_FILE = 'target_file';
    public const fields_SOURCE_MODULE = 'source_module';
    public const fields_STICKER_FILE = 'sticker_file';
    public const fields_MESSAGE = 'message';
    public const fields_DETAILS = 'details';
    public const fields_CREATED_AT = 'created_at';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('Sticker 操作日志表')
                ->addColumn(self::fields_LOG_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '日志ID')
                ->addColumn(self::fields_LEVEL, TableInterface::column_type_VARCHAR, 20, 'not null', '日志级别（error/warning/info）')
                ->addColumn(self::fields_TARGET_MODULE, TableInterface::column_type_VARCHAR, 255, 'not null', '目标模块名')
                ->addColumn(self::fields_TARGET_FILE, TableInterface::column_type_VARCHAR, 500, 'not null', '目标文件路径')
                ->addColumn(self::fields_SOURCE_MODULE, TableInterface::column_type_VARCHAR, 255, 'not null', 'Sticker 来源模块')
                ->addColumn(self::fields_STICKER_FILE, TableInterface::column_type_VARCHAR, 500, 'not null', 'Sticker 文件路径')
                ->addColumn(self::fields_MESSAGE, TableInterface::column_type_TEXT, null, 'not null', '错误或警告消息')
                ->addColumn(self::fields_DETAILS, TableInterface::column_type_TEXT, null, '', '详细信息（JSON格式）')
                ->addColumn(self::fields_CREATED_AT, TableInterface::column_type_DATETIME, null, 'not null', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_level', [self::fields_LEVEL], '日志级别索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_target_module', [self::fields_TARGET_MODULE], '目标模块索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_created_at', [self::fields_CREATED_AT], '创建时间索引')
                ->create();
        }
    }

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
        return $this->getData(self::fields_LEVEL) ?? '';
    }

    public function setLevel(string $level): static
    {
        $this->setData(self::fields_LEVEL, $level);
        return $this;
    }

    public function getTargetModule(): string
    {
        return $this->getData(self::fields_TARGET_MODULE) ?? '';
    }

    public function setTargetModule(string $targetModule): static
    {
        $this->setData(self::fields_TARGET_MODULE, $targetModule);
        return $this;
    }

    public function getTargetFile(): string
    {
        return $this->getData(self::fields_TARGET_FILE) ?? '';
    }

    public function setTargetFile(string $targetFile): static
    {
        $this->setData(self::fields_TARGET_FILE, $targetFile);
        return $this;
    }

    public function getSourceModule(): string
    {
        return $this->getData(self::fields_SOURCE_MODULE) ?? '';
    }

    public function setSourceModule(string $sourceModule): static
    {
        $this->setData(self::fields_SOURCE_MODULE, $sourceModule);
        return $this;
    }

    public function getStickerFile(): string
    {
        return $this->getData(self::fields_STICKER_FILE) ?? '';
    }

    public function setStickerFile(string $stickerFile): static
    {
        $this->setData(self::fields_STICKER_FILE, $stickerFile);
        return $this;
    }

    public function getMessage(): string
    {
        return $this->getData(self::fields_MESSAGE) ?? '';
    }

    public function setMessage(string $message): static
    {
        $this->setData(self::fields_MESSAGE, $message);
        return $this;
    }

    public function getDetails(): ?array
    {
        $details = $this->getData(self::fields_DETAILS);
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
        $this->setData(self::fields_DETAILS, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null);
        return $this;
    }

    public function getCreatedAt(): string
    {
        return $this->getData(self::fields_CREATED_AT) ?? '';
    }

    public function setCreatedAt(string $createdAt): static
    {
        $this->setData(self::fields_CREATED_AT, $createdAt);
        return $this;
    }
}

