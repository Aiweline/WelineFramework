<?php

declare(strict_types=1);

namespace Weline\Smtp\Api;

/**
 * 业务模块向 SMTP 注册「发信渠道」（Extends 多实现）。
 * 渠道 code 由模块在代码中声明，禁止后台人工编造业务代号。
 * SMTP 配置页只把渠道绑定到传输账户；模板由 SmtpMailTemplate 持久化。
 */
interface MailChannelProviderInterface
{
    /**
     * @return list<array{
     *   code: string,
     *   name: string,
     *   description?: string,
     *   module?: string,
     *   variables?: list<array{code: string, label?: string, sample?: string}>,
     *   default_templates?: list<array{
     *     locale: string,
     *     subject?: string,
     *     subject_file?: string,
     *     body?: string,
     *     body_file?: string,
     *     body_text?: string,
     *     body_text_file?: string
     *   }>
     * }>
     */
    public function getChannels(): array;
}
