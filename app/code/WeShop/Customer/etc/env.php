<?php

return [
    'router' => 'weshop_customer',
    /** FQCN discovered by {@see \Weline\Customer\Api\CustomerLoginChallengeHandlerInterfaceFactory} */
    'weline_customer_login_challenge_handler' => \WeShop\Customer\Service\WeShopCustomerLoginChallengeHandler::class,
];
