<?php

declare(strict_types=1);

namespace Weline\Visitor\Interface;

/**
 * 像素第三方事件供应商扩展点（对齐万能支付 ProviderInterface，域更窄）。
 * 模块实现放入 extends/module/Weline_Visitor/PixelEventVendor/。
 */
interface PixelEventVendorInterface
{
    public function getCode(): string;

    public function getDisplayName(): string;

    /**
     * @return array<string, mixed> schema fields: key => [type,label,required,default]
     */
    public function getConfigSchema(): array;

    /**
     * Default 搭接：weline_event => third_party_event（排除 skip_gtm_push 由壳过滤）。
     *
     * @return array<string, string>
     */
    public function getDefaultEventMap(): array;

    /** sandbox|inject */
    public function getDefaultMode(): string;

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array;

    /**
     * Browser CSP sources this vendor SDK needs (collected into Framework app defaults).
     * New vendors (Meta Pixel, TikTok Pixel, etc.) must declare hosts here — do not hardcode in Visitor shell.
     *
     * @return array<string, list<string>> directive => absolute https hosts / keywords
     */
    public function cspDirectives(): array;
}
