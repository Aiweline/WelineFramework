<?php
/**
 * 401 Unauthorized
 */
$statusCode = $statusCode ?? 401;
$pageTitle = $pageTitle ?? '需要登录';
$pageLead = $pageLead ?? '当前页面需要有效身份才能访问。';
$pageHint = $pageHint ?? '请登录后再试，或返回首页。';
$accent = $accent ?? 'warn';
require __DIR__ . '/_shell.php';
