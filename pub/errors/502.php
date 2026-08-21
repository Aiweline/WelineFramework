<?php
/**
 * 502 Bad Gateway
 */
$statusCode = $statusCode ?? 502;
$pageTitle = $pageTitle ?? '网关错误';
$pageLead = $pageLead ?? '上游服务返回了无效响应。';
$pageHint = $pageHint ?? '请稍后重试；若持续出现请联系运维。';
$accent = $accent ?? 'danger';
require __DIR__ . '/_shell.php';
