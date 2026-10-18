<?php

declare(strict_types=1);

/**
 * 汉服设计主题：默认站 SEO 主体种子（站/主题概念 — 由 HanfuSubjectSeedService 在 hanfu 主题激活时加载）。
 *
 * @return array{
 *   title:string,
 *   description:string,
 *   keywords:list<array{keyword:string,priority:int}>
 * }
 */
$brand = require __DIR__ . '/website-brand-identity.v1.php';

return [
    'title' => (string)($brand['name'] ?? '长安汉服'),
    'description' => (string)($brand['description'] ?? ''),
    'keywords' => [
        ['keyword' => '长安汉服', 'priority' => 100],
        ['keyword' => '汉服', 'priority' => 95],
        ['keyword' => '明制汉服', 'priority' => 90],
        ['keyword' => '宋制汉服', 'priority' => 85],
        ['keyword' => '唐制汉服', 'priority' => 85],
        ['keyword' => '马面裙', 'priority' => 80],
    ],
];
