<?php

$files = [
    'app/code/Weline/Frontend/Observer/Footer.php',
    'app/code/Weline/Frontend/Observer/Compiler.php',
    'app/code/Weline/Frontend/Observer/ResponseRedirectBefore.php',
    'app/code/Weline/Eav/Observer/UpgradeDefaultAttribute.php',
    'app/code/Weline/Currency/Observer/DetectCurrency.php',
    'app/code/Weline/CacheManager/Observer/UpgradeCache.php',
    'app/code/Weline/BackendActivity/Observer/BackendControllerRouteAfter.php',
    'app/code/Weline/BackendActivity/Observer/BackendControllerInit.php',
    'app/code/Weline/Backend/Observer/Header.php',
    'app/code/Weline/Backend/Observer/Footer.php',
    'app/code/Weline/Backend/Observer/Compiler.php',
    'app/code/Weline/Backend/Observer/ApiControllerInitBefore.php',
    'app/code/Weline/Backend/Observer/ResponseRedirectBefore.php',
    'app/code/Weline/Admin/Observer/RoleChecker.php',
    'app/code/Weline/Admin/Observer/ResponseRedirectBefore.php',
    'app/code/Weline/Admin/Observer/NoAccessRedirectBefore.php',
    'app/code/Weline/Admin/Observer/Maintenance.php',
    'app/code/Weline/Admin/Observer/BackendNoLoginRedirectUrl.php',
    'app/code/W到位ine/Admin/Observer/BackendControllerInitAfter.php',
    'app/code/Weline/Admin/Observer/AclController.php',
    'app/code/Weline/Acl/Observer/ControllerAttributes.php',
];

$fixed = [];
foreach ($files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        if (strpos($content, 'public function execute(Event &$event)') !== false && strpos($content, ': void') === false) {
            $content = str_replace(
                "public function execute(Event &\$event)\n    {",
                "public function execute(Event &\$event): void\n    {",
                $content
            );
            file_put_contents($file, $content);
            $fixed[] = $file;
            echo "✓ $file\n";
        }
    }
}

echo "\n修复了 " . count($fixed) . " 个文件\n";

