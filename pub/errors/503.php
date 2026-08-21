<?php
/**
 * 503 Service Unavailable
 */
$statusCode = $statusCode ?? 503;
$pageTitle = $pageTitle ?? '服务暂不可用';
$pageLead = $pageLead ?? '服务正在维护或暂时过载。';
$pageHint = $pageHint ?? '请稍后再访问。';
$accent = $accent ?? 'warn';
require __DIR__ . '/_shell.php';
