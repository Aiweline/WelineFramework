<?php

declare(strict_types=1);

namespace Weline\Smtp\Api;

/**
 * 业务模块向 SMTP 注册「发信渠道」（Extends 多实现）。
 * 渠道 code 由模块在代码中声明，禁止后台人工编造业务代号。
 * SMTP 配置页只把渠道绑定到传输账户。
 */
interface MailChannelProviderInterface
{
    /**
     * @return list<array{
     *   code: string,
     *   name: string,
     *   description?: string,
     *   module?: string
     * }>
     */
    public function getChannels(): array;
}
