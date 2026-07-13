<?php

return [
    "name" => 'Weline_TwoFactorAuth',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Customer' => '*',
        'Weline_Framework' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        'customer.account_i18n_module.Weline_TwoFactorAuth' => \Weline\TwoFactorAuth\Integration\Customer\AccountI18nModuleProvider::class,
    ],
];
