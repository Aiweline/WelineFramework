<?php

declare(strict_types=1);

namespace Weline\Backend\Api;

interface NotificationTopicProviderInterface
{
    /**
     * 返回消息主题列表
     *
     * @return array[] 主题数组，每个主题包含：
     *   - code: string 主题标识（必须）
     *   - name: string 主题显示名称（必须）
     *   - group: string 分组标识（可选，默认空）
     *   - group_name: string 分组显示名称（可选，默认空）
     *   - description: string 描述（可选）
     *   - icon: string Remix Icon 类名（可选，默认 ri-notification-line）
     *   - color: string 主题色 HEX 值（可选，默认 #50a5f1）
     *   - default_channels: array 默认渠道列表（可选，默认 ['backend']）
     */
    public function getTopics(): array;
}
