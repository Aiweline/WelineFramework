<?php

declare(strict_types=1);

namespace Weline\Cdn\Api;

/**
 * CDN Provider 一键授权能力。
 *
 * 仅「支持第三方同意流」的供应商实现本接口；不支持的适配器（如本机 wls_memory）不要实现。
 * 授权逻辑、PKCE/state、换票与凭据落库全部由 Provider 自行负责。
 */
interface OauthCapableProviderInterface
{
    /**
     * 是否提供一键授权入口（恒为 true；列表侧用 instanceof 过滤亦可）。
     */
    public function supportsOneClickOauth(): bool;

    /**
     * OAuth 应用凭据是否已配置到可发起授权。
     */
    public function isOauthConfigured(): bool;

    /**
     * 连接按钮文案。
     */
    public function getOauthConnectLabel(): string;

    /**
     * 去配置 / 修正凭据的后台引导 URL（可为空）。
     */
    public function getOauthConfigUrl(): string;

    /**
     * 展示给运营的回调 URL（须与授权请求 redirect_uri 一致）。
     */
    public function getOauthCallbackUrl(): string;

    /**
     * 授权请求将携带的 API scopes（空格分隔；不含协议 scope）。
     */
    public function getOauthRequestedScopesLabel(): string;

    /**
     * 凭据误填等运营提示（identical / misplaced / empty）；无则可空。
     *
     * @return array{
     *   tone?: string,
     *   title?: string,
     *   body?: string,
     *   action_label?: string,
     *   action_url?: string
     * }
     */
    public function getOauthCredentialHints(): array;

    /**
     * 发起授权，返回供应商授权页 URL。
     */
    public function startOauthAuthorization(string $callbackUrl, string $returnRoute): string;

    /**
     * 供应商回调成功：换票并写入 CDN 账户。
     *
     * @return array{account_id: int, return_route: string}
     */
    public function completeOauthAuthorization(string $code, string $state, string $callbackUrl): array;

    /**
     * 供应商回调带 error= 时消费 state，取回 return_route。
     *
     * @return array{return_route: string}
     */
    public function consumeOauthFailureState(string $state, string $callbackUrl): array;
}
