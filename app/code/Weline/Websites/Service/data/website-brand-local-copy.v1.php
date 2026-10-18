<?php

declare(strict_types=1);

/**
 * 官方模块中性品牌译包（无站专属）。
 * 汉服站名/简介见 app/design/Weline/hanfu/data/website-brand-local-copy.v1.php
 * （WebsiteBrandIdentitySeedService 在 hanfu 主题激活时优先加载）。
 *
 * @return array{
 *   name: array<string, string>,
 *   description: array<string, string>,
 *   service_hours: array<string, string>,
 *   topics_label: array<string, string>
 * }
 */
return [
    'name' => [],
    'description' => [],
    'service_hours' => [
        'en_US' => 'Monday–Friday 9:00–18:00 (excluding public holidays)',
        'en_GB' => 'Monday–Friday 9:00–18:00 (excluding public holidays)',
        'de_DE' => 'Montag–Freitag 9:00–18:00 (außer an gesetzlichen Feiertagen)',
        'fr_FR' => 'Lundi–vendredi 9h00–18h00 (hors jours fériés)',
        'es_ES' => 'Lunes–viernes 9:00–18:00 (excepto festivos)',
        'pt_BR' => 'Segunda–sexta 9:00–18:00 (exceto feriados)',
        'ru_RU' => 'Пн–пт 9:00–18:00 (кроме праздников)',
        'ar_SA' => 'الاثنين–الجمعة 9:00–18:00 (باستثناء العطل الرسمية)',
        'hi_IN' => 'सोमवार–शुक्रवार 9:00–18:00 (सार्वजनिक अवकाश छोड़कर)',
        'id_ID' => 'Senin–Jumat 09.00–18.00 (kecuali hari libur nasional)',
        'bn_BD' => 'সোম–শুক্র ৯:০০–১৮:০০ (সরকারি ছুটি ব্যতীত)',
        'ur_PK' => 'پیر–جمعہ 9:00–18:00 (سرکاری تعطیلات کے سوا)',
    ],
    'topics_label' => [
        'en_US' => 'Offers / New arrivals',
        'en_GB' => 'Offers / New arrivals',
        'de_DE' => 'Angebote / Neuheiten',
        'fr_FR' => 'Offres / Nouveautés',
        'es_ES' => 'Ofertas / Novedades',
        'pt_BR' => 'Ofertas / Novidades',
        'ru_RU' => 'Акции / Новинки',
        'ar_SA' => 'عروض / وصل حديثاً',
        'hi_IN' => 'ऑफ़र / नई आवक',
        'id_ID' => 'Penawaran / Produk baru',
        'bn_BD' => 'অফার / নতুন আগমন',
        'ur_PK' => 'آفرز / نئی آمد',
    ],
];
