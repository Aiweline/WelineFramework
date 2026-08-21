<?php
/**
 * 500 Internal Server Error
 */
$statusCode = $statusCode ?? 500;
$pageTitle = $pageTitle ?? '服务器错误';
$pageLead = $pageLead ?? '处理请求时发生意外错误。';
$pageHint = $pageHint ?? '我们已记录问题，请稍后重试。';
$accent = $accent ?? 'danger';
require __DIR__ . '/_shell.php';
