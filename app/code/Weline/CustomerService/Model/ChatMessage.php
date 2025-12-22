<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class ChatMessage extends Model
{
    public const table = 'chat_message';
    public const fields_ID = 'message_id';
    public const fields_session_id = 'session_id';
    public const fields_sender_type = 'sender_type';
    public const fields_sender_id = 'sender_id';
    public const fields_content = 'content';
    public const fields_translated_content = 'translated_content';
    public const fields_source_locale = 'source_locale';
    public const fields_target_locale = 'target_locale';
    public const fields_is_translated = 'is_translated';
    public const fields_created_at = 'created_at';

    public const SENDER_TYPE_CUSTOMER = 'customer';
    public const SENDER_TYPE_AGENT = 'agent';

    public function _init(): void
    {
        $this->_primary_key = self::fields_ID;
        $this->_table = self::table;
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('聊天消息表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '消息ID')
                ->addColumn(self::fields_session_id, TableInterface::column_type_INTEGER, 0, 'not null', '会话ID')
                ->addColumn(self::fields_sender_type, TableInterface::column_type_VARCHAR, 20, 'not null', '发送者类型')
                ->addColumn(self::fields_sender_id, TableInterface::column_type_INTEGER, 0, 'not null', '发送者ID')
                ->addColumn(self::fields_content, TableInterface::column_type_TEXT, 0, 'not null', '消息内容（原始语言）')
                ->addColumn(self::fields_translated_content, TableInterface::column_type_TEXT, 0, '', '翻译后内容')
                ->addColumn(self::fields_source_locale, TableInterface::column_type_VARCHAR, 20, 'not null', '源语言')
                ->addColumn(self::fields_target_locale, TableInterface::column_type_VARCHAR, 20, 'not null', '目标语言')
                ->addColumn(self::fields_is_translated, TableInterface::column_type_INTEGER, 1, 'not null default 0', '是否已翻译')
                ->addColumn(self::fields_created_at, TableInterface::column_type_DATETIME, 0, '', '创建时间')
                ->addIndex(TableInterface::index_type_KEY, 'idx_session_id', self::fields_session_id, '会话ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_sender_type', self::fields_sender_type, '发送者类型索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_created_at', self::fields_created_at, '创建时间索引')
                ->create();
        }
    }

    public function getSessionId(): int
    {
        return (int)$this->getData(self::fields_session_id);
    }

    public function setSessionId(int $sessionId): static
    {
        return $this->setData(self::fields_session_id, $sessionId);
    }

    public function getSenderType(): string
    {
        return (string)$this->getData(self::fields_sender_type);
    }

    public function setSenderType(string $senderType): static
    {
        return $this->setData(self::fields_sender_type, $senderType);
    }

    public function getSenderId(): int
    {
        return (int)$this->getData(self::fields_sender_id);
    }

    public function setSenderId(int $senderId): static
    {
        return $this->setData(self::fields_sender_id, $senderId);
    }

    public function getContent(): string
    {
        return (string)$this->getData(self::fields_content);
    }

    public function setContent(string $content): static
    {
        return $this->setData(self::fields_content, $content);
    }

    public function getTranslatedContent(): ?string
    {
        return $this->getData(self::fields_translated_content);
    }

    public function setTranslatedContent(?string $translatedContent): static
    {
        return $this->setData(self::fields_translated_content, $translatedContent);
    }

    public function getSourceLocale(): string
    {
        return (string)$this->getData(self::fields_source_locale);
    }

    public function setSourceLocale(string $sourceLocale): static
    {
        return $this->setData(self::fields_source_locale, $sourceLocale);
    }

    public function getTargetLocale(): string
    {
        return (string)$this->getData(self::fields_target_locale);
    }

    public function setTargetLocale(string $targetLocale): static
    {
        return $this->setData(self::fields_target_locale, $targetLocale);
    }

    public function getIsTranslated(): bool
    {
        return (bool)$this->getData(self::fields_is_translated);
    }

    public function setIsTranslated(bool $isTranslated): static
    {
        return $this->setData(self::fields_is_translated, $isTranslated ? 1 : 0);
    }
}

