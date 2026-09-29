<?php

declare(strict_types=1);

use Weline\Framework\App\State;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Service\ScopeSelectorCatalog;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

require dirname(__DIR__, 6) . '/app/bootstrap.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
});

Runtime::setMode(RuntimeInterface::MODE_WLS);
RequestContext::init();
Parser::clearWorkerCaches();
$request = ObjectManager::getInstance(Request::class);
$service = new ScopeSelectorCatalog(
    new SystemConfigScopeResolver(),
    ObjectManager::getInstance(ScopeIdentityCatalogInterface::class),
);

// The public catalog owns these words even when a foreign page is the caller.
// Warm Phrase using only the consumer module before entering the real service.
foreach ([
    ['en_US', 'Global (all websites, stores, and channels)'],
    ['zh_Hans_CN', 'Global（全部网站、店铺和渠道）'],
    ['en_US', 'Global (all websites, stores, and channels)'],
] as [$locale, $expected]) {
    State::setRequestLanguageOverride($locale);
    $request->setModules(['Weline_Cdn']);
    (string)__('Global（全部网站、店铺和渠道）');
    $result = $service->build('default.default.default', []);
    foreach (['selected_label', 'selected_title'] as $field) {
        if ($result[$field] !== $expected) {
            throw new RuntimeException($locale . ' ' . $field . ': expected ' . $expected . '; got ' . $result[$field]);
        }
    }
    if ($result['tree_options'][0]['label'] !== $expected || $result['options'][0]['label'] !== $expected) {
        throw new RuntimeException($locale . ' tree/flat labels must use the owning dictionary');
    }
    if (!in_array('Weline_Cdn', $request->getModules(), true)) {
        throw new RuntimeException('catalog must preserve the calling module context');
    }
}
State::setRequestLanguageOverride('');
echo "PASS Scope catalog localization: foreign caller, warmed Phrase, en/zh/en labels and titles\n";
