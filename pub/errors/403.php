<?php
/**
 * 403 Forbidden
 * @var int $statusCode
 * @var string $pageTitle
 * @var string $pageLead
 * @var string $pageHint
 * @var string $accent
 */
$statusCode = $statusCode ?? 403;
$pageTitle = $pageTitle ?? '无权访问';
$pageLead = $pageLead ?? '你没有权限查看此内容。';
$pageHint = $pageHint ?? '如需访问，请联系站点管理员。';
$accent = $accent ?? 'warn';
require __DIR__ . '/_shell.php';
