<?php

declare(strict_types=1);

namespace WeShop\Analytics\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\RequestLifecycleTrace;

class AnalyticsConfigService
{
    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_FACEBOOK = 'facebook';
    public const PROVIDER_TIKTOK = 'tiktok';
    public const PROVIDER_BING = 'bing';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getProviderDefinitions(): array
    {
        return [
            self::PROVIDER_GOOGLE => [
                'code' => self::PROVIDER_GOOGLE,
                'label' => 'Google Analytics',
                'description' => 'GA4 measurement protocol events for storefront commerce flows.',
                'env_path' => 'analytics.google',
                'fields' => [
                    [
                        'name' => 'measurement_id',
                        'label' => 'Measurement ID',
                        'required' => true,
                        'input_type' => 'text',
                        'sensitive' => false,
                        'placeholder' => 'G-XXXXXXXXXX',
                        'help_text' => 'Copy the Measurement ID from your GA4 web data stream.',
                    ],
                    [
                        'name' => 'api_secret',
                        'label' => 'API Secret',
                        'required' => true,
                        'input_type' => 'password',
                        'sensitive' => true,
                        'placeholder' => 'Paste your Measurement Protocol API secret',
                        'help_text' => 'Create this under Data Streams > Measurement Protocol API secrets.',
                    ],
                ],
                'setup' => [
                    'integration_method' => 'GA4 Measurement Protocol',
                    'summary' => 'Use your Google Analytics 4 web data stream Measurement ID and a Measurement Protocol API secret.',
                    'steps' => [
                        'Open Google Analytics and choose the target GA4 property for this storefront.',
                        'Go to Admin > Data Streams > Web and open the web stream that receives storefront traffic.',
                        'Copy the Measurement ID and paste it into the field below.',
                        'Create a Measurement Protocol API secret for the same stream and paste it into API Secret.',
                        'Save the provider config, enable it, then verify storefront pixel events are reaching Google.',
                    ],
                    'quick_links' => [
                        [
                            'label' => 'Open Google Analytics',
                            'url' => 'https://analytics.google.com/analytics/web/',
                            'style' => 'primary',
                        ],
                        [
                            'label' => 'Measurement ID Help',
                            'url' => 'https://support.google.com/analytics/answer/12270356',
                            'style' => 'outline-secondary',
                        ],
                        [
                            'label' => 'Measurement Protocol Guide',
                            'url' => 'https://developers.google.com/analytics/devguides/collection/protocol/ga4',
                            'style' => 'outline-secondary',
                        ],
                    ],
                ],
                'defaults' => [
                    'enabled' => false,
                    'measurement_id' => '',
                    'api_secret' => '',
                    'event_mapping' => $this->getDefaultEventMapping(),
                ],
            ],
            self::PROVIDER_FACEBOOK => [
                'code' => self::PROVIDER_FACEBOOK,
                'label' => 'Facebook Pixel',
                'description' => 'Meta Pixel and Conversions API tracking for storefront commerce flows.',
                'env_path' => 'analytics.facebook',
                'fields' => [
                    [
                        'name' => 'pixel_id',
                        'label' => 'Pixel ID',
                        'required' => true,
                        'input_type' => 'text',
                        'sensitive' => false,
                        'placeholder' => 'Paste your Pixel ID',
                    ],
                    [
                        'name' => 'access_token',
                        'label' => 'Access Token',
                        'required' => true,
                        'input_type' => 'password',
                        'sensitive' => true,
                        'placeholder' => 'Paste your Conversions API access token',
                    ],
                    [
                        'name' => 'test_event_code',
                        'label' => 'Test Event Code',
                        'required' => false,
                        'input_type' => 'text',
                        'sensitive' => false,
                        'placeholder' => 'Optional test event code',
                    ],
                ],
                'setup' => [
                    'integration_method' => 'Meta Pixel + Conversions API',
                    'summary' => 'Use your Events Manager Pixel ID plus a server-side Conversions API access token.',
                    'steps' => [
                        'Open Events Manager and choose the pixel for this storefront.',
                        'Copy the Pixel ID and paste it into the field below.',
                        'Create or paste a Conversions API access token.',
                        'Optionally add a test event code when validating events in Meta.',
                    ],
                    'quick_links' => [],
                ],
                'defaults' => [
                    'enabled' => false,
                    'pixel_id' => '',
                    'access_token' => '',
                    'test_event_code' => '',
                    'event_mapping' => $this->getDefaultEventMapping(),
                ],
            ],
            self::PROVIDER_TIKTOK => [
                'code' => self::PROVIDER_TIKTOK,
                'label' => 'TikTok Pixel',
                'description' => 'TikTok web pixel plus server-side conversion API tracking for storefront acquisition and conversion events.',
                'env_path' => 'analytics.tiktok',
                'fields' => [
                    [
                        'name' => 'pixel_id',
                        'label' => 'Pixel ID',
                        'required' => true,
                        'input_type' => 'text',
                        'sensitive' => false,
                        'placeholder' => 'Paste your TikTok Pixel ID',
                    ],
                    [
                        'name' => 'access_token',
                        'label' => 'Access Token',
                        'required' => true,
                        'input_type' => 'password',
                        'sensitive' => true,
                        'placeholder' => 'Paste your TikTok Events API access token',
                    ],
                    [
                        'name' => 'test_event_code',
                        'label' => 'Test Event Code',
                        'required' => false,
                        'input_type' => 'text',
                        'sensitive' => false,
                        'placeholder' => 'Optional test event code',
                    ],
                ],
                'setup' => [
                    'integration_method' => 'TikTok Pixel + Events API',
                    'summary' => 'Use your TikTok Pixel ID and Events API access token for browser + server conversion tracking.',
                    'steps' => [
                        'Open TikTok Events Manager and select the target web pixel.',
                        'Copy the Pixel ID and paste it into the field below.',
                        'Generate or copy the Events API access token and paste it into Access Token.',
                        'Optionally use Test Event Code while validating events.',
                        'Save the provider config and enable it.',
                    ],
                    'quick_links' => [
                        [
                            'label' => 'Open TikTok Events Manager',
                            'url' => 'https://ads.tiktok.com/events',
                            'style' => 'primary',
                        ],
                    ],
                ],
                'defaults' => [
                    'enabled' => false,
                    'pixel_id' => '',
                    'access_token' => '',
                    'test_event_code' => '',
                    'event_mapping' => $this->getDefaultEventMapping(),
                ],
            ],
            self::PROVIDER_BING => [
                'code' => self::PROVIDER_BING,
                'label' => 'Bing Ads (UET)',
                'description' => 'Microsoft Advertising UET tag with server-side Conversion API support.',
                'env_path' => 'analytics.bing',
                'fields' => [
                    [
                        'name' => 'uet_tag_id',
                        'label' => 'UET Tag ID',
                        'required' => true,
                        'input_type' => 'text',
                        'sensitive' => false,
                        'placeholder' => 'Paste your UET Tag ID',
                    ],
                    [
                        'name' => 'api_token',
                        'label' => 'API Token',
                        'required' => true,
                        'input_type' => 'password',
                        'sensitive' => true,
                        'placeholder' => 'Paste your Microsoft Advertising Conversion API bearer token',
                    ],
                ],
                'setup' => [
                    'integration_method' => 'Microsoft UET + Conversion API',
                    'summary' => 'Use your UET tag ID and Conversion API token to enable browser + server attribution.',
                    'steps' => [
                        'Open Microsoft Advertising and navigate to UET tags.',
                        'Copy the UET Tag ID and paste it into the field below.',
                        'Generate/collect a Conversion API bearer token and paste it into API Token.',
                        'Save the provider config and enable it.',
                    ],
                    'quick_links' => [
                        [
                            'label' => 'Open Microsoft Advertising',
                            'url' => 'https://ads.microsoft.com/',
                            'style' => 'primary',
                        ],
                    ],
                ],
                'defaults' => [
                    'enabled' => false,
                    'uet_tag_id' => '',
                    'api_token' => '',
                    'event_mapping' => $this->getDefaultEventMapping(),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getProviderConfig(string $providerCode): array
    {
        $traceEnabled = RequestLifecycleTrace::isEnabled();
        $traceName = 'analytics::AnalyticsConfigService::getProviderConfig::' . $providerCode;
        $traceStart = $this->tracePush($traceEnabled, $traceName);

        try {
            $definition = $this->getProviderDefinition($providerCode);
            $stored = $this->readEnvConfig((string) $definition['env_path'], []);
            if (!is_array($stored)) {
                $stored = [];
            }

            $config = array_replace($definition['defaults'] ?? [], $stored);
            $config['enabled'] = !empty($config['enabled']);
            $config['event_mapping'] = is_array($config['event_mapping'] ?? null)
                ? $config['event_mapping']
                : $this->getDefaultEventMapping();

            foreach ($definition['fields'] ?? [] as $field) {
                $name = (string) ($field['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $config[$name] = trim((string) ($config[$name] ?? ''));
            }

            return $config;
        } finally {
            $this->tracePop($traceEnabled, $traceName, $traceStart);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProviderStatuses(): array
    {
        $statuses = [];

        foreach ($this->getProviderDefinitions() as $providerCode => $definition) {
            $config = $this->getProviderConfig($providerCode);
            $statuses[] = [
                'code' => $providerCode,
                'label' => (string) ($definition['label'] ?? $providerCode),
                'description' => (string) ($definition['description'] ?? ''),
                'enabled' => !empty($config['enabled']),
                'ready' => $this->isProviderReady($providerCode, $config),
                'config' => $config,
                'fields' => $definition['fields'] ?? [],
            ];
        }

        return $statuses;
    }

    /**
     * @param array<string, mixed>|null $config
     * @return array<int, string>
     */
    public function getMissingRequiredFieldLabels(string $providerCode, ?array $config = null): array
    {
        $definition = $this->getProviderDefinition($providerCode);
        $config = $config ?? $this->getProviderConfig($providerCode);
        $missingLabels = [];

        foreach ($definition['fields'] ?? [] as $field) {
            if (empty($field['required'])) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            if ($name !== '' && trim((string) ($config[$name] ?? '')) === '') {
                $missingLabels[] = (string) ($field['label'] ?? $name);
            }
        }

        return $missingLabels;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveProviderConfig(array $payload): array
    {
        $providerCode = trim((string) ($payload['provider'] ?? ''));
        $definition = $this->getProviderDefinition($providerCode);
        $defaults = is_array($definition['defaults'] ?? null) ? $definition['defaults'] : [];
        $config = array_replace($defaults, $this->getProviderConfig($providerCode));
        $config['enabled'] = !empty($payload['enabled']);

        foreach ($definition['fields'] ?? [] as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $value = trim((string) ($payload[$name] ?? $config[$name] ?? ''));
            if (!empty($field['sensitive']) && $value === '' && trim((string) ($config[$name] ?? '')) !== '') {
                continue;
            }

            $config[$name] = $value;
        }

        if ($config['enabled']) {
            $missingLabels = $this->getMissingRequiredFieldLabels($providerCode, $config);
            if ($missingLabels !== []) {
                throw new \InvalidArgumentException(
                    (string) __('Missing required analytics fields: %{1}', [implode(', ', $missingLabels)])
                );
            }
        }

        $config['event_mapping'] = $this->getDefaultEventMapping();

        if (!$this->writeEnvConfig((string) $definition['env_path'], $config)) {
            throw new \RuntimeException((string) __('Failed to persist analytics provider config.'));
        }

        return $config;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getTrackedEvents(): array
    {
        return [
            ['code' => 'page_view', 'label' => 'Page View'],
            ['code' => 'view_item', 'label' => 'View Item'],
            ['code' => 'add_to_cart', 'label' => 'Add To Cart'],
            ['code' => 'add_to_wishlist', 'label' => 'Add To Wishlist'],
            ['code' => 'begin_checkout', 'label' => 'Begin Checkout'],
            ['code' => 'purchase', 'label' => 'Purchase'],
            ['code' => 'login', 'label' => 'Login'],
            ['code' => 'register', 'label' => 'Register'],
        ];
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public function isProviderReady(string $providerCode, ?array $config = null): bool
    {
        $definition = $this->getProviderDefinition($providerCode);
        $config = $config ?? $this->getProviderConfig($providerCode);
        if (empty($config['enabled'])) {
            return false;
        }

        foreach ($definition['fields'] ?? [] as $field) {
            if (empty($field['required'])) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            if ($name !== '' && trim((string) ($config[$name] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function getDefaultEventMapping(): array
    {
        return [
            'page_view' => 'page_view',
            'view_item' => 'view_item',
            'add_to_cart' => 'add_to_cart',
            'add_to_wishlist' => 'add_to_wishlist',
            'begin_checkout' => 'begin_checkout',
            'purchase' => 'purchase',
            'login' => 'login',
            'register' => 'register',
        ];
    }

    protected function readEnvConfig(string $path, mixed $default = []): mixed
    {
        return Env::getInstance()->getConfig($path, $default);
    }

    protected function writeEnvConfig(string $path, mixed $value): bool
    {
        return Env::getInstance()->setConfig($path, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function getProviderDefinition(string $providerCode): array
    {
        $definition = $this->getProviderDefinitions()[$providerCode] ?? null;
        if (!is_array($definition)) {
            throw new \InvalidArgumentException((string) __('Unsupported analytics provider: %{1}', [$providerCode]));
        }

        return $definition;
    }

    private function tracePush(bool $traceEnabled, string $name): float
    {
        if (!$traceEnabled) {
            return 0.0;
        }

        RequestLifecycleTrace::pushCurrentParent($name);

        return microtime(true);
    }

    private function tracePop(bool $traceEnabled, string $name, float $start, string $category = 'analytics'): void
    {
        if (!$traceEnabled) {
            return;
        }

        RequestLifecycleTrace::popCurrentParent();
        RequestLifecycleTrace::recordSpan(
            $name,
            (microtime(true) - $start) * 1000,
            $category
        );
    }
}
