<?php
/**
 * 404 Not Found
 * @var int $statusCode
 * @var string $pageTitle
 * @var string $pageLead
 * @var string $pageHint
 * @var string $accent
 */
$statusCode = $statusCode ?? 404;
$pageTitle = $pageTitle ?? '页面不存在';
$pageLead = $pageLead ?? '找不到你请求的页面或资源。';
$pageHint = $pageHint ?? '链接可能已失效，或地址输入有误。';
$accent = $accent ?? 'neutral';
require __DIR__ . '/_shell.php';
