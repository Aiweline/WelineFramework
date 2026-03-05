<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '聊天消息表')]
#[Index(name: 'idx_session_id', columns: ['session_id'])]
#[Index(name: 'idx_sender_type', columns: ['sender_type'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
class ChatMessage extends Model
{
    public const schema_table = 'chat_message';
    public const schema_primary_key = 'message_id';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: '消息ID')]
    public const schema_fields_ID = 'message_id';
    #[Col('int', nullable: false, comment: '会话ID')]
    public const schema_fields_session_id = 'session_id';
    #[Col('varchar', 20, nullable: false, comment: '发送者类型')]
    public const schema_fields_sender_type = 'sender_type';
    #[Col('int', nullable: false, comment: '发送者ID')]
    public const schema_fields_sender_id = 'sender_id';
    #[Col('text', nullable: false, comment: '消息内容')]
    public const schema_fields_content = 'content';
    #[Col('text', comment: '翻译后内容')]
    public const schema_fields_translated_content = 'translated_content';
    #[Col('varchar', 20, nullable: false, comment: '源语言')]
    public const schema_fields_source_locale = 'source_locale';
    #[Col('varchar', 20, nullable: false, comment: '目标语言')]
    public const schema_fields_target_locale = 'target_locale';
    #[Col('int', 1, nullable: false, default: 0, comment: '是否已翻译')]
    public const schema_fields_is_translated = 'is_translated';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';

    public const SENDER_TYPE_CUSTOMER = 'customer';
    public const SENDER_TYPE_AGENT = 'agent';

    public function _init(): void
    {
        $this->_primary_key = self::schema_fields_ID;
        $this->_table = self::schema_table;
    }
public function getSessionId(): int
    {
        return (int)$this->getData(self::schema_fields_session_id);
    }

    public function setSessionId(int $sessionId): static
    {
        return $this->setData(self::schema_fields_session_id, $sessionId);
    }

    public function getSenderType(): string
    {
        return (string)$this->getData(self::schema_fields_sender_type);
    }

    public function setSenderType(string $senderType): static
    {
        return $this->setData(self::schema_fields_sender_type, $senderType);
    }

    public function getSenderId(): int
    {
        return (int)$this->getData(self::schema_fields_sender_id);
    }

    public function setSenderId(int $senderId): static
    {
        return $this->setData(self::schema_fields_sender_id, $senderId);
    }

    public function getContent(): string
    {
        return (string)$this->getData(self::schema_fields_content);
    }

    public function setContent(string $content): static
    {
        return $this->setData(self::schema_fields_content, $content);
    }

    public function getTranslatedContent(): ?string
    {
        return $this->getData(self::schema_fields_translated_content);
    }

    public function setTranslatedContent(?string $translatedContent): static
    {
        return $this->setData(self::schema_fields_translated_content, $translatedContent);
    }

    public function getSourceLocale(): string
    {
        return (string)$this->getData(self::schema_fields_source_locale);
    }

    public function setSourceLocale(string $sourceLocale): static
    {
        return $this->setData(self::schema_fields_source_locale, $sourceLocale);
    }

    public function getTargetLocale(): string
    {
        return (string)$this->getData(self::schema_fields_target_locale);
    }

    public function setTargetLocale(string $targetLocale): static
    {
        return $this->setData(self::schema_fields_target_locale, $targetLocale);
    }

    public function getIsTranslated(): bool
    {
        return (bool)$this->getData(self::schema_fields_is_translated);
    }

    public function setIsTranslated(bool $isTranslated): static
    {
        return $this->setData(self::schema_fields_is_translated, $isTranslated ? 1 : 0);
    }
}

