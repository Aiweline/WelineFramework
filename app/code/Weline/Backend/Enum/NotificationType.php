<?php

declare(strict_types=1);

namespace Weline\Backend\Enum;

enum NotificationType: string
{
    case INFO = 'info';
    case SUCCESS = 'success';
    case WARNING = 'warning';
    case ERROR = 'error';
    case URGENT = 'urgent';

    public function getColor(): string
    {
        return match ($this) {
            self::INFO => 'var(--backend-color-info)',
            self::SUCCESS => 'var(--backend-color-success)',
            self::WARNING => 'var(--backend-color-warning)',
            self::ERROR => 'var(--backend-color-danger)',
            self::URGENT => 'var(--backend-color-danger)',
        };
    }

    public function getHexColor(): string
    {
        return match ($this) {
            self::INFO => '#50a5f1',
            self::SUCCESS => '#34c38f',
            self::WARNING => '#f1b44c',
            self::ERROR => '#f46a6a',
            self::URGENT => '#c92a2a',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::INFO => __('信息'),
            self::SUCCESS => __('成功'),
            self::WARNING => __('警告'),
            self::ERROR => __('错误'),
            self::URGENT => __('紧急'),
        };
    }

    public function getPriority(): int
    {
        return match ($this) {
            self::INFO => 1,
            self::SUCCESS => 2,
            self::WARNING => 5,
            self::ERROR => 8,
            self::URGENT => 10,
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::INFO => 'ri-information-line',
            self::SUCCESS => 'ri-checkbox-circle-line',
            self::WARNING => 'ri-alert-line',
            self::ERROR => 'ri-error-warning-line',
            self::URGENT => 'ri-alarm-warning-line',
        };
    }

    public static function fromString(string $type): self
    {
        return match (strtolower($type)) {
            'info' => self::INFO,
            'success' => self::SUCCESS,
            'warning' => self::WARNING,
            'error' => self::ERROR,
            'urgent' => self::URGENT,
            default => self::INFO,
        };
    }

    public static function meetsMinimumType(string $messageType, string $minType): bool
    {
        $messagePriority = self::fromString($messageType)->getPriority();
        $minPriority = self::fromString($minType)->getPriority();
        return $messagePriority >= $minPriority;
    }

    public static function getAllTypes(): array
    {
        return [
            self::INFO->value,
            self::SUCCESS->value,
            self::WARNING->value,
            self::ERROR->value,
            self::URGENT->value,
        ];
    }

    public static function getTypeOptions(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }
        return $options;
    }
}
