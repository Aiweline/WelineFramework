<?php

return [
    "name" => 'Weline_I18n',
    "version" => '1.0.3',
    "requires" => [
        'Weline_Framework' => '*',
    ],
    "optional" => [
        'Weline_Acl' => '*',
        'Weline_Admin' => '*',
        'Weline_Backend' => '*',
        'Weline_CacheManager' => '*',
        'Weline_Queue' => '*',
        'Weline_SystemConfig' => '*',
    ],
    "provides" => [
        \Weline\Framework\Runtime\DictionaryWarmupProviderInterface::class => \Weline\I18n\Api\Runtime\DictionaryWarmupProvider::class,
        \Weline\Framework\App\Localization\LocaleNameProviderInterface::class => \Weline\I18n\Api\Localization\LocaleNameProvider::class,
        \Weline\Framework\Phrase\GlobalDictionaryProviderInterface::class => \Weline\I18n\Api\Localization\GlobalDictionaryProvider::class,
        \Weline\I18n\Api\Localization\LocaleCatalogInterface::class => \Weline\I18n\Api\Localization\InstalledLocaleCatalog::class,
        \Weline\I18n\Api\Localization\LocaleNameCatalogInterface::class => \Weline\I18n\Service\Repository\LocaleNameCatalog::class,
        \Weline\I18n\Api\Localization\LocaleRepositoryInterface::class => \Weline\I18n\Service\Repository\LocaleRepository::class,
        \Weline\I18n\Api\Localization\CountryRepositoryInterface::class => \Weline\I18n\Service\Repository\CountryRepository::class,
        \Weline\Admin\Api\Localization\BackendLocaleCatalogInterface::class => \Weline\I18n\Integration\Admin\BackendLocaleCatalogProvider::class,
        \Weline\I18n\Api\Translation\DictionaryRepositoryInterface::class => \Weline\I18n\Model\Locale\Dictionary::class,
        \Weline\I18n\Api\Translation\TranslationCollectorInterface::class => \Weline\I18n\Service\TranslationCollector::class,
        \Weline\I18n\Api\Translation\TranslationResolverInterface::class => \Weline\I18n\Service\TranslationResolver::class,
        'localization_provider.Weline_I18n' => \Weline\I18n\Api\Localization\LocalizationProvider::class,
        'process_cache_resetter.Weline_I18n' => \Weline\I18n\Api\Runtime\ProcessCacheResetter::class,
        'template_cache_policy.Weline_I18n' => \Weline\I18n\Api\View\TemplateCachePolicyProvider::class,
    ],
];
