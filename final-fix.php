<?php

$files = [
    'app/code/Weline/UrlManager/Observer/RouterRewrite.php',
    'app/code/Weline/Admin/Observer/BackendWhitelistUrl.php',
    'app/code/Weline/Acl/Observer/RouteBefore.php',
    'app/code/Weline/Websites/Observer/DetectWebsite.php',
    'app/code/Weline/Theme/Observer/UpgradeCompiler.php',
    'app/code/Weline/Theme/Observer/CompileResource.php',
    'app/code/Weline/Theme/Observer/Register.php',
    'app/code/Weline/Theme/Observer/TemplateFetchFile.php',
    'app/code/Weline/Taglib/Observer/TagParser.php',
    'app/code/Weline/Queue/Observer/QueueCollect.php',
    'app/code/Weline/ModuleRouter/Observer/ProcessUrlBefore.php',
    'app/code/Weline/Indexer/Observer/ReindexListing.php',
    'app/code/Weline/Indexer/Observer/ReindexCollector.php',
    'app/code/Weline/Indexer/Observer/Reindex.php',
    'app/code/Weline/I18n/Observer/Register.php',
    'app/code/Weline/I18n/Observer/ParserWordsRegister.php',
    'app/code/Weline/I18n/Observer/I18nLocalsUpgrade.php',
    'app/code/Weline/I18n/Observer/DetectLanguage.php',
    'app/code/Weline/Frontend/Observer/Header.php',
    'app/code/Weline/Frontend/Observer/Maintenance.php',
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

