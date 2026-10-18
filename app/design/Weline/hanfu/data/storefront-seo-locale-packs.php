<?php

declare(strict_types=1);

/**
 * Theme-only storefront SEO packs for SERP CTR alignment (OPC A1/A2/A3).
 * Consumed by homepage / shop layouts — never hardcodes into app/code.
 *
 * @return array{
 *     home: array<string, array{meta_title:string,meta_description:string,h1:string,visible_signal?:string}>,
 *     shop: array<string, array{meta_title:string,meta_description:string,h1:string}>
 * }
 */
return [
    'home' => [
        'en_US' => [
            'meta_title' => 'Buy Chinese Hanfu Online | Ming Tang Song Dress Shop',
            'meta_description' => 'Shop Chinese Hanfu & hanfu chinese dress styles—Ming, Tang, Song mamian skirts. Buy authentic sets with worldwide shipping from Chang\'an Hanfu.',
            'h1' => 'Shop Chinese Hanfu · Ming, Tang & Song Dresses',
            'visible_signal' => 'Buy Chinese Hanfu online — Ming, Tang & Song styles with worldwide shipping.',
        ],
        'en' => [
            'meta_title' => 'Buy Chinese Hanfu Online | Ming Tang Song Dress Shop',
            'meta_description' => 'Shop Chinese Hanfu & hanfu chinese dress styles—Ming, Tang, Song mamian skirts. Buy authentic sets with worldwide shipping from Chang\'an Hanfu.',
            'h1' => 'Shop Chinese Hanfu · Ming, Tang & Song Dresses',
            'visible_signal' => 'Buy Chinese Hanfu online — Ming, Tang & Song styles with worldwide shipping.',
        ],
        'bn_BD' => [
            'meta_title' => 'Hanfu Dress Price Bangladesh (BDT ৳) | Shipping to BD',
            'meta_description' => 'Hanfu dress price in Bangladesh with BDT ৳ tags. Shop Chinese Hanfu online — shipping to Bangladesh from Chang\'an Hanfu.',
            'h1' => 'Hanfu Dress Price in Bangladesh · BDT ৳ & Shipping to BD',
            'visible_signal' => 'Price in BDT (৳) · Shipping to Bangladesh available on Hanfu dresses.',
        ],
        'ar_SA' => [
            'meta_title' => 'الهانفو | Hanfu — متجر تشانغآن للملابس التقليدية',
            'meta_description' => 'تسوق الهانفو (Hanfu) الصينية — أنماط مينغ وتانغ وسونغ مع شحن عالمي من Chang\'an Hanfu.',
            'h1' => 'الهانفو · Hanfu — تسوق الأزياء الصينية التقليدية',
            'visible_signal' => 'الهانفو (Hanfu) — أزياء صينية تقليدية للشحن العالمي.',
        ],
    ],
    'shop' => [
        'en_US' => [
            'meta_title' => 'Shop Chinese Hanfu | Buy Ming Tang Song Dresses',
            'meta_description' => 'Shop Chinese Hanfu forms—Ming, Tang, Song. Buy hanfu chinese dress sets with worldwide shipping.',
            'h1' => 'Shop Chinese Hanfu',
        ],
        'en' => [
            'meta_title' => 'Shop Chinese Hanfu | Buy Ming Tang Song Dresses',
            'meta_description' => 'Shop Chinese Hanfu forms—Ming, Tang, Song. Buy hanfu chinese dress sets with worldwide shipping.',
            'h1' => 'Shop Chinese Hanfu',
        ],
        'bn_BD' => [
            'meta_title' => 'Shop Hanfu · Price BDT ৳ | Shipping to Bangladesh',
            'meta_description' => 'Browse Hanfu with price signals in BDT ৳ and shipping to Bangladesh.',
            'h1' => 'Shop Hanfu · BDT ৳ & Shipping to BD',
        ],
        'ar_SA' => [
            'meta_title' => 'تسوق الهانفو | Hanfu Shop',
            'meta_description' => 'تسوق الهانفو (Hanfu) — أشكال مينغ وتانغ وسونغ.',
            'h1' => 'تسوق الهانفو · Hanfu',
        ],
    ],
];
