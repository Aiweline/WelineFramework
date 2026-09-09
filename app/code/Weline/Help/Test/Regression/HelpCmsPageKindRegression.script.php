<?php

declare(strict_types=1);

/**
 * Simple offline verification: CMS default kind unchanged; Help kind isolated.
 * Run: php app/code/Weline/Help/Test/Regression/HelpCmsPageKindRegression.script.php
 */

$weline = dirname(__DIR__, 3);
$failures = [];

$extends = include $weline . '/Cms/extends.php';
if (($extends['extends']['PageKind']['interface'] ?? '') !== 'Weline\Cms\Api\Kind\CmsPageKindInterface') {
    $failures[] = 'CMS extends.php missing PageKind';
}

$defaultKind = file_get_contents($weline . '/Cms/Kind/DefaultCmsPageKind.php');
if (!is_string($defaultKind) || !str_contains($defaultKind, "return 'cms'") || !str_contains($defaultKind, 'LAYOUT_TYPE')) {
    $failures[] = 'DefaultCmsPageKind must stay cms/cms_page';
}

$target = file_get_contents($weline . '/Cms/extends/module/Weline_Theme/TargetType/CmsPageTargetTypeProvider.php');
if (!is_string($target) || !str_contains($target, 'allLayoutTypes')) {
    $failures[] = 'CmsPageTargetTypeProvider must consult PageKind registry';
}

$pageService = file_get_contents($weline . '/Cms/Service/PageService.php');
if (!is_string($pageService) || !str_contains($pageService, 'resolvePageKindFromParams')) {
    $failures[] = 'PageService must resolve kind for drafts';
}
if (!is_string($pageService) || !str_contains($pageService, 'canUseLayoutTypeForPathGroup')) {
    $failures[] = 'PageService must enforce layout type by path_group';
}

$helpKind = file_get_contents($weline . '/Help/extends/module/Weline_Cms/PageKind/HelpPageKindProvider.php');
if (!is_string($helpKind) || !str_contains($helpKind, "return 'help'")) {
    $failures[] = 'Help PageKind missing';
}

$sitemap = file_get_contents($weline . '/Cms/extends/module/Weline_Seo/SitemapUrlProvider/CmsPageProvider.php');
if (!is_string($sitemap) || !str_contains($sitemap, "=== 'help'") || !str_contains($sitemap, 'Weline_Help')) {
    $failures[] = 'CMS sitemap must skip help path_group when Help enabled';
}

$themeRouter = file_get_contents($weline . '/Theme/Controller/Router.php');
if (!is_string($themeRouter) || str_contains($themeRouter, "'help' =>")) {
    $failures[] = 'Theme Router must hard-cut /help public route';
}
if (!is_string($themeRouter) || !str_contains($themeRouter, 'isFaqModuleEnabled')) {
    $failures[] = 'Theme Router must yield /faq to Faq module when enabled';
}

$hub = file_get_contents($weline . '/Help/Service/HelpHubContent.php');
if (!is_string($hub) || !str_contains($hub, 'class HelpHubContent')) {
    $failures[] = 'Weline_Help HelpHubContent must remain available';
}
$faqLayout = file_get_contents($weline . '/Theme/view/theme/frontend/layouts/faq/default.phtml');
if (!is_string($faqLayout) || !str_contains($faqLayout, 'FaqHubContent')) {
    $failures[] = 'Theme faq layout must load FaqHubContent after help→faq hard cut';
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PASS: CMS default kind preserved; Help kind/layout/SEO wiring present; Theme hub data-driven.\n";
exit(0);
