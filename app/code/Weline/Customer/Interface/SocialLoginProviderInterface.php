<?php

declare(strict_types=1);

namespace Weline\Customer\Interface;

/**
 * Storefront social-login provider contract.
 *
 * Third-party modules register implementations under:
 * `extends/module/Weline_Customer/SocialLoginProvider/{Code}Provider.php`
 * (see Customer `extends.php`). Do not use Hook for OAuth provider registration.
 *
 * Required surfaces mirror Payment providers + customer guides:
 * identity/OAuth, logo, and customer-facing guide/policy pages with sidebar navigation.
 */
interface SocialLoginProviderInterface
{
    /** Stable provider code used in routes, bindings, and SystemConfig keys. */
    public function getCode(): string;

    /** Human-readable label (already translated when possible). */
    public function getLabel(): string;

    /** Short listing blurb for the guide hub card. */
    public function getSummary(): string;

    /** Optional Taglib icon name fallback when SVG markup is empty. */
    public function getIcon(): string;

    /** CSS brand class for the storefront logo button. */
    public function getBrandClass(): string;

    /** Inline SVG markup for the circular logo button (preferred over getIcon). */
    public function getIconSvgMarkup(): string;

    /** Lower sorts first among discovered providers. */
    public function getSortOrder(): int;

    /**
     * CSP sources this social-login vendor needs (collected by Customer Extends into app defaults).
     *
     * Declare script/frame/connect hosts for GSI, OAuth authorize, or SDK prompts.
     *
     * @return array<string, list<string>> directive => absolute https hosts / keywords
     */
    public function cspDirectives(): array;

    /** Customer guide page title. */
    public function getGuideTitle(): string;

    /** Customer policy page title. */
    public function getPolicyTitle(): string;

    /**
     * Template basename under
     * `view/templates/Frontend/guide/social-login/{code}/` (without `.phtml`).
     */
    public function getGuideTemplateCode(): string;

    /**
     * Template basename under
     * `view/templates/Frontend/guide/social-login/{code}/` (without `.phtml`).
     */
    public function getPolicyTemplateCode(): string;

    /** Theme layout type for the guide page (e.g. help). */
    public function getGuideLayoutType(): string;

    /** Theme layout type for the policy page (e.g. policy). */
    public function getPolicyLayoutType(): string;

    /**
     * Storefront route path for the provider privacy/policy page
     * (Meta App「隐私政策网址」等控制台粘贴用；无开头斜杠).
     */
    public function getStorefrontPrivacyPolicyPath(): string;

    /**
     * Storefront route path for site terms of service
     * (Meta App「服务条款网址」；无开头斜杠).
     */
    public function getStorefrontTermsPath(): string;

    /**
     * Storefront route path for user-data deletion instructions
     * (Meta App「用户数据删除说明网址」；可含 #fragment).
     */
    public function getStorefrontDataDeletionPath(): string;

    /**
     * Build the absolute OAuth authorization URL (including query string).
     */
    public function buildAuthorizationUrl(string $clientId, string $redirectUri, string $state): string;

    /**
     * Exchange authorization code and return a normalized customer profile.
     *
     * @return array{subject:string,email:string,display_name:string,avatar_url:string}
     */
    public function exchangeAndFetchProfile(
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri
    ): array;
}
