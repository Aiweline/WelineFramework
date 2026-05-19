<?php
require 'e:/WelineFramework/DEV-workspace/app/bootstrap.php';
\Weline\Framework\Http\Cookie::setLang('en_US');
$template = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\View\Template::class);
$path = $template->getFetchFile('WeShop_Subscription::hooks/account.sidebar.content.phtml');
echo $path, PHP_EOL;
echo str_contains((string)file_get_contents($path), 'subscription-smoke') ? 'HAS_SMOKE' : 'NO_SMOKE', PHP_EOL;
