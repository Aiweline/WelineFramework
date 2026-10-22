<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

// 复现真实 CLI：ConsoleEncoding 会 setlocale(LC_CTYPE, 'C.UTF-8')。
\Weline\Framework\Console\ConsoleEncoding::initForCli();

$method = new \ReflectionMethod(\Weline\Framework\Console\CommandHelper::class, 'wrapText');
$method->setAccessible(true);

$input = '升级模块系统（数据库模型、路由等）。全量默认按源指纹跳过未变更工作：'
    . 'Schema/路由/菜单/404·维护静态页/收尾编译；路由跳扫须 seedBatchFromDisk 或全员跳过（禁止空 batch）；'
    . 'ACL orphan 须 touched_modules=重扫模块。冷跑无指纹接近全量；可用 --force-optimize 强制收尾。';
$output = (string) $method->invoke(null, $input, 4);

$failures = [];
if (!str_contains($output, '冷跑无指纹')) {
    $failures[] = 'wrapText lost CJK character: ' . bin2hex($output);
}
if (str_contains($output, "\xE6\x97\x20")) {
    $failures[] = 'wrapText emitted truncated UTF-8 sequence (E6 97 20)';
}
if (!mb_check_encoding($output, 'UTF-8')) {
    $failures[] = 'wrapText output is not valid UTF-8';
}

$emoji = (string) $method->invoke(null, '测试 😀 表情与中文 一同换行', 2);
if (!str_contains($emoji, '😀')) {
    $failures[] = 'wrapText lost emoji';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
