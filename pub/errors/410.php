<?php
/**
 * 410 Gone
 */
$statusCode = $statusCode ?? 410;
$pageTitle = $pageTitle ?? '资源已下线';
$pageLead = $pageLead ?? '该地址对应的内容已永久移除。';
$pageHint = $pageHint ?? '请返回首页查找可用入口。';
$accent = $accent ?? 'neutral';
require __DIR__ . '/_shell.php';
