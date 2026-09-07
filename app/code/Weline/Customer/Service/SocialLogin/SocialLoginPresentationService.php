<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

/**
 * Resolve which social login buttons should render for the account-social-login widget.
 */
final class SocialLoginPresentationService
{
    public function __construct(
        private readonly SocialLoginProviderCatalog $catalog,
        private readonly SocialLoginConfig $config,
        private readonly SocialLoginOAuthService $oauth,
    ) {
    }

    /**
     * @param array<string, mixed> $widgetConfig
     * @return list<array{code:string,label:string,icon:string,brand_class:string,icon_svg:string,href:string}>
     */
    public function enabledButtons(array $widgetConfig, string $returnUrl = ''): array
    {
        $buttons = [];
        foreach ($this->catalog->providers() as $provider) {
            $code = $provider->getCode();
            if (!$this->isEnabled($widgetConfig, $code)) {
                continue;
            }
            // SystemConfig: must be configured AND activated before storefront render.
            if (!$this->config->isActive($code)) {
                continue;
            }
            $buttons[] = [
                'code' => $code,
                'label' => $provider->getLabel(),
                'icon' => $provider->getIcon(),
                'brand_class' => $provider->getBrandClass(),
                'icon_svg' => $provider->getIconSvgMarkup(),
                'href' => $this->oauth->startUrl($code, $returnUrl),
            ];
        }

        return $buttons;
    }

    /**
     * @param array<string, mixed> $widgetConfig
     * @return array{
     *   logged_in:bool,
     *   return_url:string,
     *   endpoints:array{google:string,facebook:string},
     *   oauth:array{facebook:string,google:string},
     *   google:?array{client_id:string},
     *   facebook:?array{app_id:string}
     * }
     */
    public function quickPromptBootstrap(array $widgetConfig, string $returnUrl = '', bool $loggedIn = false): array
    {
        $bootstrap = [
            'logged_in' => $loggedIn,
            'return_url' => $returnUrl,
            'endpoints' => [
                'google' => $this->oauth->quickGoogleUrl(),
                'facebook' => $this->oauth->quickFacebookUrl(),
            ],
            // Same redirect_uri as Logo OAuth (Valid OAuth Redirect URIs). Do not use FB.login.
            'oauth' => [
                'facebook' => $this->oauth->startUrl('facebook', $returnUrl),
                'google' => $this->oauth->startUrl('google', $returnUrl),
            ],
            'google' => null,
            'facebook' => null,
        ];
        if ($loggedIn) {
            return $bootstrap;
        }
        if ($this->isEnabled($widgetConfig, 'google') && $this->config->isQuickPromptEnabled('google')) {
            $clientId = $this->config->clientId('google');
            if ($clientId !== '') {
                $bootstrap['google'] = ['client_id' => $clientId];
            }
        }
        if ($this->isEnabled($widgetConfig, 'facebook') && $this->config->isQuickPromptEnabled('facebook')) {
            $appId = $this->config->clientId('facebook');
            if ($appId !== '') {
                $bootstrap['facebook'] = ['app_id' => $appId];
            }
        }

        return $bootstrap;
    }

    /** @param array<string, mixed> $widgetConfig */
    public function isEnabled(array $widgetConfig, string $provider): bool
    {
        $key = 'enable_' . $provider;
        if (!array_key_exists($key, $widgetConfig)) {
            return true;
        }
        $value = $widgetConfig[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        $normalized = strtolower(trim((string) $value));

        return !in_array($normalized, ['0', 'false', 'off', 'no', ''], true);
    }
}
