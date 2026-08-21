<?php
/**
 * 400 Bad Request
 */
$statusCode = $statusCode ?? 400;
$pageTitle = $pageTitle ?? '请求无效';
$pageLead = $pageLead ?? '服务器无法理解本次请求。';
$pageHint = $pageHint ?? '请检查地址与参数后重试。';
$accent = $accent ?? 'warn';
require __DIR__ . '/_shell.php';
