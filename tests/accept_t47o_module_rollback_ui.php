<?php

declare(strict_types=1);

/**
 * T47o 验收：ModuleManager 模块回滚 UI 已退役（B6 阶段 1）。
 *
 * 只做静态检查：文件删除、注册清理、模板脚本裁剪、无残留类引用。
 */

$root = \dirname(__DIR__);
$moduleRoot = $root . '/app/code/Weline/ModuleManager';
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$forbiddenStrings = ['ModuleRollback', 'moduleRollback', 'module-rollback-action'];

// 1. 5 个回滚 UI 文件必须已删除
$deletedFiles = [
    'Controller/Backend/Rollback.php',
    'Plugin/ModuleUpgradeExecuteAfterPlugin.php',
    'extends/module/Weline_Framework/Query/ModuleRollbackQueryProvider.php',
    'Observer/ModuleRollbackAuditObserver.php',
    'Observer/ModuleRollbackProjectionObserver.php',
];
foreach ($deletedFiles as $relative) {
    $check(!\file_exists($moduleRoot . '/' . $relative), '回滚文件仍存在：' . $relative);
}

// 2. ModuleManager 内不得残留回滚字符串
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($moduleRoot, FilesystemIterator::SKIP_DOTS),
);
$scanned = 0;
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }
    $contents = @\file_get_contents($file->getPathname());
    if (!\is_string($contents)) {
        continue;
    }
    ++$scanned;
    foreach ($forbiddenStrings as $needle) {
        if (\str_contains($contents, $needle)) {
            $failures[] = 'ModuleManager 仍含回滚引用：' . $file->getPathname() . ' => ' . $needle;
        }
    }
}
$check($scanned > 0, 'ModuleManager 扫描结果为空');

// 3. plugin.xml / event.xml 合法且注册已清理
\libxml_use_internal_errors(true);
$pluginXml = \simplexml_load_file($moduleRoot . '/etc/plugin.xml');
$check($pluginXml !== false, 'plugin.xml 不是合法 XML');
$check($pluginXml === false || \count($pluginXml->plugin) === 0, 'plugin.xml 仍注册 plugin');

$eventXml = \simplexml_load_file($moduleRoot . '/etc/event.xml');
$check($eventXml !== false, 'event.xml 不是合法 XML');
$check($eventXml === false || \count($eventXml->event) === 6, 'event.xml 事件数量应为 6（已移除 3 个回滚事件）');
if ($eventXml !== false) {
    foreach ($eventXml->event as $event) {
        $name = (string)$event['name'];
        $check(!\str_contains($name, 'ModuleRollback'), 'event.xml 仍含回滚事件：' . $name);
    }
}
$eventRaw = (string)\file_get_contents($moduleRoot . '/etc/event.xml');
$check(\str_contains($eventRaw, 'table_ddl_before'), 'event.xml 不应误删 table_ddl_before observer');
$check(\str_contains($eventRaw, 'table_ddl_after'), 'event.xml 不应误删 table_ddl_after observer');
$check(\str_contains($eventRaw, 'ModuleUpdateBefore'), 'event.xml 不应误删 module_upgrade_before observer');

// 4. Listing 模板：筛选 JS 保留、回滚按钮/脚本移除
$listingPath = $moduleRoot . '/view/templates/backend/Listing/index.phtml';
$listing = \is_file($listingPath) ? (string)\file_get_contents($listingPath) : '';
$check($listing !== '', 'Listing 模板缺失或为空');
$check(\str_contains($listing, 'module-manager-filters'), 'Listing 筛选表单被误删');
$check(\str_contains($listing, 'data-module-filter-auto'), 'Listing 筛选 JS 被误删');
$check(\substr_count($listing, '<script>') === 1, 'Listing 应只保留筛选 script（实际 '
    . \substr_count($listing, '<script>') . ' 个）');
$check(!\str_contains($listing, 'w-rollback-confirm'), 'Listing 仍残留回滚确认脚本');

// 5. 全仓 app/code 不得再引用已删除的 ModuleManager 回滚类
$danglingClasses = [
    'Weline\\ModuleManager\\Controller\\Backend\\Rollback',
    'Weline\\ModuleManager\\Plugin\\ModuleUpgradeExecuteAfterPlugin',
    'Weline\\ModuleManager\\extends\\module\\Weline_Framework\\Query\\ModuleRollbackQueryProvider',
    'Weline\\ModuleManager\\Observer\\ModuleRollbackAuditObserver',
    'Weline\\ModuleManager\\Observer\\ModuleRollbackProjectionObserver',
];
$appCode = $root . '/app/code';
$extensions = ['php', 'xml', 'phtml', 'js'];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($appCode, FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }
    if (!\in_array(\strtolower($file->getExtension()), $extensions, true)) {
        continue;
    }
    $contents = @\file_get_contents($file->getPathname());
    if (!\is_string($contents)) {
        continue;
    }
    foreach ($danglingClasses as $fqcn) {
        if (\str_contains($contents, $fqcn)) {
            $failures[] = '仍有已删除类的引用：' . $file->getPathname() . ' => ' . $fqcn;
        }
    }
}

if ($failures !== []) {
    \fwrite(STDERR, \implode(PHP_EOL, $failures) . PHP_EOL);
    echo 'T47O MODULE-ROLLBACK-UI FAIL' . PHP_EOL;
    exit(1);
}

echo 'OK 5 rollback UI files removed' . PHP_EOL;
echo 'OK ModuleManager has no rollback references (' . $scanned . ' files scanned)' . PHP_EOL;
echo 'OK plugin.xml/event.xml valid and cleaned' . PHP_EOL;
echo 'OK Listing filter JS retained; rollback script removed' . PHP_EOL;
echo 'OK no dangling deleted-class references in app/code' . PHP_EOL;
echo 'T47O MODULE-ROLLBACK-UI PASS' . PHP_EOL;
exit(0);
