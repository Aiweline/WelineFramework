<?php
/**
 * 429 Too Many Requests
 */
$statusCode = $statusCode ?? 429;
$pageTitle = $pageTitle ?? '请求过于频繁';
$pageLead = $pageLead ?? '短时间内请求次数过多，已被限流。';
$pageHint = $pageHint ?? '请稍候再试。';
$accent = $accent ?? 'warn';
require __DIR__ . '/_shell.php';
