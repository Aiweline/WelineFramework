<?php

declare(strict_types=1);

/**
 * Primary-source evidence registry for the Hanfu R3 editorial corpus.
 * A key identifies a bounded claim, not a general permission to make broader
 * historical, material, regional, or image-licensing claims.
 *
 * @return array<string,array{title:string,institution:string,url:string,claim_scope:string,language:string,accessed_at:string}>
 */
$records = [
    'consumer-listing-verification' => [
        'title' => 'Advertising and Marketing Basics',
        'institution' => 'U.S. Federal Trade Commission',
        'url' => 'https://www.ftc.gov/business-guidance/advertising-marketing',
        'claim_scope' => 'Supports the procedural principle that product and advertising claims require current, reviewable substantiation; it does not evaluate any named retailer or SKU.',
        'language' => 'en',
        'accessed_at' => '2026-09-04',
    ],
    'retailer-claim-boundary' => [
        'title' => 'Editorial current-state verification protocol',
        'institution' => 'Amayun Hanfu Editorial',
        'url' => 'https://www.amayun.com/',
        'claim_scope' => 'Defines a dated, SKU-level verification procedure for seller, fulfilment, sizing and return claims; it is not third-party evidence for any retailer.',
        'language' => 'en',
        'accessed_at' => '2026-09-04',
    ],
    'palace-ming-yuanling' => [
        'title' => '明代官员服饰研究',
        'institution' => '故宫博物院',
        'url' => 'https://www.dpm.org.cn/Uploads/File/2019/11/25/u5ddbb3f9e989e.pdf',
        'claim_scope' => 'Discusses Ming official dress and identifies round-collar robes as a major garment form; it does not establish every modern round-collar robe as a reconstruction.',
        'language' => 'zh-CN',
        'accessed_at' => '2026-09-03',
    ],
    'cns-mamian-skirt' => [
        'title' => '马面裙',
        'institution' => '中国丝绸博物馆',
        'url' => 'https://chinasilkmuseum.com/zggd/info_21.aspx?itemid=1974',
        'claim_scope' => 'Describes a two-piece mamian skirt whose joined waist creates front and back panels; it supports structural observation, not a universal dating claim.',
        'language' => 'zh-CN',
        'accessed_at' => '2026-09-03',
    ],
    'unesco-nanjing-yunjin' => [
        'title' => 'Craftsmanship of Nanjing Yunjin brocade',
        'institution' => 'UNESCO Intangible Cultural Heritage',
        'url' => 'https://ich.unesco.org/en/RL/craftsmanship-of-nanjing-yunjin-brocade-00200',
        'claim_scope' => 'Documents the cooperative loom practice, materials, and historical use of Yunjin; it is not fibre certification for a retail listing.',
        'language' => 'en',
        'accessed_at' => '2026-09-03',
    ],
    'unesco-sericulture-silk' => [
        'title' => 'Sericulture and silk craftsmanship of China',
        'institution' => 'UNESCO Intangible Cultural Heritage',
        'url' => 'https://ich.unesco.org/en/RL/sericulture-and-silk-craftsmanship-of-china-00197',
        'claim_scope' => 'Documents regional silk-making knowledge and processes; it does not prove the composition or making method of an individual garment.',
        'language' => 'en',
        'accessed_at' => '2026-09-03',
    ],
    'unesco-li-textile' => [
        'title' => 'Traditional Li textile techniques: spinning, dyeing, weaving and embroidering',
        'institution' => 'UNESCO Intangible Cultural Heritage',
        'url' => 'https://ich.unesco.org/en/RL/traditional-li-textile-techniques-spinning-dyeing-weaving-and-embroidering-02153',
        'claim_scope' => 'Documents Li women’s textile techniques in Hainan and their cultural transmission; it must not be used to classify unrelated photographed clothing as Li or Hanfu.',
        'language' => 'en',
        'accessed_at' => '2026-09-03',
    ],
    'met-skirt-open-view' => [
        'title' => 'Skirt (30.75.88), 19th century',
        'institution' => 'The Metropolitan Museum of Art',
        'url' => 'https://www.metmuseum.org/art/collection/search/68568',
        'claim_scope' => 'Identifies this nineteenth-century Chinese skirt, its silk and metallic-thread medium, and closed/open collection images. The open view supports observation of flat panels and grouped pleats, not modern fit, product identity or wedding use.',
        'language' => 'en',
        'accessed_at' => '2026-09-05',
    ],
    'met-chinese-textiles' => [
        'title' => 'Chinese Textiles: An Introduction to the Study of their History, Sources, Technique, Symbolism, and Use',
        'institution' => 'The Metropolitan Museum of Art',
        'url' => 'https://www.metmuseum.org/art/metpublications/Chinese_Textiles_An_Introduction',
        'claim_scope' => 'Provides a museum publication for studying Chinese textiles and object context; individual object records remain necessary for object-specific claims.',
        'language' => 'en',
        'accessed_at' => '2026-09-03',
    ],
    'met-chinese-textiles-exhibition' => [
        'title' => 'Chinese Textiles: Ten Centuries of Masterpieces from the Met Collection',
        'institution' => 'The Metropolitan Museum of Art',
        'url' => 'https://www.metmuseum.org/exhibitions/listings/2015/chinese-textiles',
        'claim_scope' => 'Exhibition context for Chinese silk, tapestry, embroidery and court textiles; it does not turn visual similarity into provenance.',
        'language' => 'en',
        'accessed_at' => '2026-09-03',
    ],
    'met-open-access-fabric' => [
        'title' => 'Fabric, Chinese, Qing Dynasty, Silk (1975.1.1935)',
        'institution' => 'The Metropolitan Museum of Art',
        'url' => 'https://www.metmuseum.org/art/collection/search/461250',
        'claim_scope' => 'Open collection record for a specified woven silk object; it supports its own medium and dimensions only.',
        'language' => 'en',
        'accessed_at' => '2026-09-03',
    ],
];

/** Direct NEAC 风俗文化/风俗习惯 URLs were resolved from each official gk.shtml page. */
$neacPaths = [
    'han'=>'hz/fswh','mongol'=>'mgz/fswh','hui'=>'huiz/fswh','tibetan'=>'zz/fsxg','uyghur'=>'wwez/fsxg','miao'=>'mz/fswh','yi'=>'yz/fsxg','zhuang'=>'zhz/fsxg','buyei'=>'byz/fsxg','korean'=>'cxz/fsxg','manchu'=>'manz/fsxg','dong'=>'dz/fsxg','yao'=>'yaoz/fsxg','bai'=>'bz/fsxg','tujia'=>'tjz/fsxg','hani'=>'hnz/fsxg','kazakh'=>'hskz/fsxg','dai'=>'daiz/fsxg','li'=>'lz/fsxg','lisu'=>'ssz/fsxg','wa'=>'wz/fsxg','she'=>'sz/fsxg','gaoshan'=>'gsz/fsxg','lahu'=>'lhz/fsxg','shui'=>'shuiz/fsxg','dongxiang'=>'dxz/fsxg','naxi'=>'nxz/fsxg','jingpo'=>'jpz/fsxg','kirgiz'=>'kekzz/fsxg','tu'=>'tz/fsxg','daur'=>'dwz/fsxg','mulao'=>'mlz/fsxg','qiang'=>'qz/fsxg','blang'=>'blz/fsxg','salar'=>'slz/fsxg','maonan'=>'mnz/fsxg','gelao'=>'glz/fsxg','xibe'=>'xbz/fsxg','achang'=>'acz/fsxg','pumi'=>'pmz/fsxg','tajik'=>'tjkz/fsxg','nu'=>'nz/fsxg','uzbek'=>'wzbkz/fsxg','russian'=>'elsz/fsxg','ewenki'=>'ewkz/fsxg','deang'=>'daz/fsxg','bonan'=>'baz/fsxg','yugur'=>'ygz/fsxg','gin'=>'jz/fsxg','tatar'=>'ttez/fsxg','derung'=>'dlz/fsxg','oroqen'=>'elcz/fsxg','hezhen'=>'hzz/fsxg','monba'=>'mbz/fsxg','lhoba'=>'lbz/fsxg','jino'=>'jnz/fsxg',
];
foreach (require __DIR__ . '/china-ethnic-groups.php' as $profile) {
    $code = (string)$profile['code'];
    $path = $neacPaths[$code] ?? null;
    if ($path === null) {
        throw new RuntimeException('hanfu_r3_neac_path_missing:' . $code);
    }
    $records['neac-' . $code] = [
        'title' => '国家民委：' . (string)$profile['zh'] . '风俗文化/风俗习惯',
        'institution' => '国家民族事务委员会',
        'url' => 'https://www.neac.gov.cn/seac/ztzl/' . $path . '.shtml',
        'claim_scope' => 'Supports the named ' . (string)$profile['zh'] . ' community’s regional, custom, dress, and documented use-context statements only; it does not identify a person from a photograph or grant image rights.',
        'language' => 'zh-CN',
        'accessed_at' => '2026-09-04',
    ];
    $records['neac-' . $code . '-overview'] = [
        'title' => '国家民委：' . (string)$profile['zh'] . '概况',
        'institution' => '国家民族事务委员会',
        'url' => 'https://www.neac.gov.cn/seac/ztzl/' . dirname($path) . '/gk.shtml',
        'claim_scope' => 'Supports bounded community and regional overview context for ' . (string)$profile['zh'] . ' only; it does not identify a person from a photograph or grant image rights.',
        'language' => 'zh-CN',
        'accessed_at' => '2026-09-04',
    ];
}

return $records;
