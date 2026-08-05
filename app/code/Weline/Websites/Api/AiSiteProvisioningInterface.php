<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

/**
 * AI 建站跨模块域名准备请求契约。
 *
 * 调用方只能请求异步准备并读取结果；本地测试域名会在 Start 的明确
 * 需求确认中先完成 hosts/证书准备，然后由 Websites Queue 绑定站点。
 * 真实域名购买和购买后的站点绑定仍全部由 Websites Queue 执行。
 */
interface AiSiteProvisioningInterface
{
    /**
     * The optional rearm_failed flag is an explicit operator intent. Omitting it
     * keeps ordinary Start/request replay read-only for an existing failed attempt.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    public function requestBinding(array $command): array;

    /**
     * Register the materialized PageBuilder home page as the bound site's root entry.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    public function configureStartPage(array $command): array;

    /**
     * @param array<string, mixed> $lookup
     * @return array<string, mixed>|null
     */
    public function getStatus(array $lookup): ?array;

    /**
     * Explicit publish-bypass binding for local test domains when hosts/certificate
     * preparation cannot complete. Creates or reuses the Website and domain
     * binding without rewriting /etc/hosts. Purchase mode is rejected.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    public function forceBindIgnoringLocalHosts(array $command): array;
}
