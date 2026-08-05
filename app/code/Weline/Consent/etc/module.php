<?php

return [
    'name' => 'Weline_Consent',
    'version' => '1.1.0',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Frontend' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Websites' => '*',
    ],
    'optional' => [],
    'provides' => [
        \Weline\Consent\Api\ConsentRepositoryInterface::class
            => \Weline\Consent\Service\OrmConsentRepository::class,
        \Weline\Consent\Api\ConsentRecordingPolicyInterface::class
            => \Weline\Consent\Service\SystemConfigConsentRecordingPolicy::class,
        \Weline\Consent\Api\ConsentVisitorIdentityInterface::class
            => \Weline\Consent\Service\ConsentVisitorIdentity::class,
    ],
];
