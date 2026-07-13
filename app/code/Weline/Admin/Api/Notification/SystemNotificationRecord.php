<?php

declare(strict_types=1);

namespace Weline\Admin\Api\Notification;

/** Immutable notification projection for UI modules. */
final readonly class SystemNotificationRecord
{
    public function __construct(
        public int $id,
        public string $title,
        public string $content,
        public bool $read,
        public int $isImage,
        public int $isIcon,
        public string $avatar,
        public string $createdAt,
    ) {
    }

    public function getTitle(): string { return $this->title; }
    public function getContent(): string { return $this->content; }
    public function isRead(): bool { return $this->read; }
    public function getIsImg(): int { return $this->isImage; }
    public function getIsIcon(): int { return $this->isIcon; }
    public function getAvatar(): string { return $this->avatar; }
    public function getCreateTime(): string { return $this->createdAt; }
}
