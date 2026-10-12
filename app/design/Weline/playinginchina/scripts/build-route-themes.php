<?php
/**
 * Build route-theme taxonomy + destination membership for Playing in China.
 * Overseas→China information packages: style packs + regional packs + flagship routes.
 *
 * Usage (from repo root):
 *   php app/design/Weline/playinginchina/scripts/build-route-themes.php
 *   php app/design/Weline/playinginchina/scripts/build-route-themes.php --report-untagged
 *
 * Region themes are uncapped so every destination gets a geographic home.
 * A second pass attaches still-untagged spots to best style match(es).
 */

declare(strict_types=1);

$reportUntagged = in_array('--report-untagged', $argv ?? [], true);

$dataDir = dirname(__DIR__) . '/data';
$destPath = $dataDir . '/destinations.json';
$routesPath = $dataDir . '/routes.json';

$destinations = json_decode((string) file_get_contents($destPath), true, 512, JSON_THROW_ON_ERROR);
$routes = json_decode((string) file_get_contents($routesPath), true, 512, JSON_THROW_ON_ERROR);

/** @var array<string, array<string, mixed>> $byId */
$byId = [];
foreach ($destinations as $d) {
    $id = (string) $d['id'];
    $d['id'] = $id;
    $byId[$id] = $d;
}

/**
 * Theme catalog: sellable information-package axes.
 * kind = style | region | flagship
 */
$themes = [
    // ── Style packs ──────────────────────────────────────────────
    [
        'id' => 'classic-first-china',
        'kind' => 'style',
        'name' => 'Classic First China',
        'name_zh' => '经典首访中国',
        'pitch' => 'The essential first trip: Wall, Forbidden City, Terracotta, Bund — one coherent decision pack.',
        'hubs' => ['Beijing', 'Xi\'an', 'Shanghai'],
        'days_hint' => '7–10',
        'route_ids' => ['r1'],
        'seed_dests' => [
            'great-wall', 'forbidden-city', 'temple-heaven', 'terracotta-army',
            'xian-city-wall', 'shanghai-bund', 'summer-palace', 'jingshan',
        ],
        'match' => [
            'types_any' => ['history', 'city', 'landmark'],
            'regions_any' => ['North China', 'East China'],
            'cities_any' => ['Beijing', 'Xian', 'Xi\'an', 'Shanghai'],
            'scenery_any' => ['heritage', 'city', 'mountains'],
        ],
        'priority_boost' => 40,
        'cap' => 80,
    ],
    [
        'id' => 'history-heritage',
        'kind' => 'style',
        'name' => 'History & Heritage',
        'name_zh' => '历史遗产线',
        'pitch' => 'Dynasties, grottoes, walls and living old cities — curated for depth, not checklist tourism.',
        'hubs' => ['Beijing', 'Xi\'an', 'Luoyang', 'Pingyao'],
        'days_hint' => '5–12',
        'route_ids' => ['r4', 'r7'],
        'seed_dests' => [
            'forbidden-city', 'terracotta-army', 'mogao-caves', 'longmen', 'yungang',
            'pingyao', 'shaolin', 'chengde', 'ming-tombs', 'qufu',
        ],
        'match' => [
            'types_any' => ['history', 'ancient', 'culture', 'religion'],
            'scenery_any' => ['heritage', 'ancient', 'temples'],
        ],
        'priority_boost' => 20,
        'cap' => 220,
    ],
    [
        'id' => 'nature-landscape',
        'kind' => 'style',
        'name' => 'Nature & Landscapes',
        'name_zh' => '自然风光线',
        'pitch' => 'Karst, turquoise lakes, pillar forests and sacred peaks — China\'s outdoor icons in pack form.',
        'hubs' => ['Chengdu', 'Zhangjiajie', 'Guilin', 'Huangshan'],
        'days_hint' => '6–10',
        'route_ids' => ['r2', 'r3'],
        'seed_dests' => [
            'jiuzhaigou', 'zhangjiajie', 'guilin-liriver', 'huangshan', 'leshan-buddha',
            'panda-base', 'west-lake',
        ],
        'match' => [
            'types_any' => ['nature', 'hiking', 'wildlife'],
            'scenery_any' => [
                'mountains', 'lakes', 'gorges', 'karst', 'forest', 'waterfalls',
                'danxia', 'terraces', 'grassland', 'wetlands',
            ],
        ],
        'priority_boost' => 10,
        'cap' => 280,
    ],
    [
        'id' => 'photography',
        'kind' => 'style',
        'name' => 'Photography Packs',
        'name_zh' => '摄影向线路',
        'pitch' => 'Golden-hour locations, mist, autumn color and dune light — timed for shootable windows.',
        'hubs' => ['Huangshan', 'Jiuzhaigou', 'Yangshuo', 'Dunhuang'],
        'days_hint' => '5–9',
        'route_ids' => ['r2', 'r3'],
        'seed_dests' => [
            'huangshan', 'jiuzhaigou', 'zhangjiajie', 'guilin-liriver', 'mogao-caves',
            'potala-palace', 'great-wall', 'west-lake',
        ],
        'match' => [
            'types_any' => ['photography', 'nature', 'hiking'],
            'scenery_any' => [
                'mountains', 'karst', 'lakes', 'gorges', 'danxia', 'desert',
                'terraces', 'coast', 'snow',
            ],
        ],
        'priority_boost' => 15,
        'cap' => 160,
    ],
    [
        'id' => 'family',
        'kind' => 'style',
        'name' => 'Family Travel',
        'name_zh' => '亲子家庭线',
        'pitch' => 'Shorter walks, cable cars, pandas, beaches and kid-safe pacing with HSR between hubs.',
        'hubs' => ['Chengdu', 'Shanghai', 'Beijing', 'Sanya'],
        'days_hint' => '6–10',
        'route_ids' => ['r1', 'r2', 'r6'],
        'seed_dests' => [
            'panda-base', 'great-wall', 'shanghai-bund', 'sanya', 'harbin-ice',
            'west-lake', 'temple-heaven',
        ],
        'match' => [
            'types_any' => ['family', 'wildlife', 'beach', 'city', 'nature'],
            'cities_any' => ['Chengdu', 'Shanghai', 'Beijing', 'Sanya', 'Harbin'],
            'scenery_any' => ['city', 'coast', 'islands', 'lakes', 'heritage'],
        ],
        'priority_boost' => 25,
        'cap' => 100,
    ],
    [
        'id' => 'hiking-adventure',
        'kind' => 'style',
        'name' => 'Hiking & Adventure',
        'name_zh' => '徒步探险线',
        'pitch' => 'Wall sections, gorge treks and granite ridges — fitness-aware day plans with cable-car options.',
        'hubs' => ['Beijing', 'Zhangjiajie', 'Lijiang', 'Huangshan'],
        'days_hint' => '4–8',
        'route_ids' => ['r3', 'r8'],
        'seed_dests' => [
            'great-wall', 'huangshan', 'zhangjiajie', 'tiger-leaping', 'huashan',
            'jinshanling', 'gubei',
        ],
        'match' => [
            'types_any' => ['hiking', 'nature'],
            'scenery_any' => ['mountains', 'gorges', 'karst', 'snow', 'forest'],
        ],
        'priority_boost' => 20,
        'cap' => 140,
    ],
    [
        'id' => 'ancient-jiangnan',
        'kind' => 'style',
        'name' => 'Jiangnan Ancient Towns',
        'name_zh' => '江南水乡古镇',
        'pitch' => 'Gardens, canals and West Lake poetry — refined East China linked by 30-minute trains.',
        'hubs' => ['Shanghai', 'Suzhou', 'Hangzhou', 'Wuzhen'],
        'days_hint' => '4–7',
        'route_ids' => ['r5'],
        'seed_dests' => [
            'shanghai-bund', 'suzhou-gardens', 'wuzhen', 'west-lake',
        ],
        'match' => [
            'types_any' => ['ancient', 'village', 'culture', 'history'],
            'regions_any' => ['East China'],
            'scenery_any' => ['ancient', 'gardens', 'lakes', 'heritage'],
            'provinces_any' => ['Zhejiang', 'Jiangsu', 'Shanghai', 'Anhui'],
        ],
        'priority_boost' => 30,
        'cap' => 120,
    ],
    [
        'id' => 'silk-road',
        'kind' => 'style',
        'name' => 'Silk Road Odyssey',
        'name_zh' => '丝路史诗线',
        'pitch' => 'From Terracotta to Mogao dunes to high plateaus — epic, permit-aware, once-in-a-lifetime.',
        'hubs' => ['Xi\'an', 'Dunhuang', 'Lhasa', 'Kashgar'],
        'days_hint' => '10–14',
        'route_ids' => ['r4'],
        'seed_dests' => [
            'terracotta-army', 'mogao-caves', 'potala-palace', 'xian-city-wall',
        ],
        'match' => [
            'types_any' => ['history', 'desert', 'religion', 'culture'],
            'regions_any' => ['Northwest China', 'Southwest China'],
            'scenery_any' => ['desert', 'heritage', 'grassland', 'snow', 'temples'],
            'provinces_any' => ['Shaanxi', 'Gansu', 'Xinjiang', 'Qinghai', 'Tibet', 'Ningxia'],
        ],
        'priority_boost' => 25,
        'cap' => 120,
    ],
    [
        'id' => 'beach-island',
        'kind' => 'style',
        'name' => 'Beach & Island Escape',
        'name_zh' => '海岛阳光线',
        'pitch' => 'Turquoise bays, island days and winter sun — contrast packs with highland or city openers.',
        'hubs' => ['Sanya', 'Xiamen', 'Zhuhai', 'Qingdao'],
        'days_hint' => '4–7',
        // No r6 route seed — that circuit starts in Lijiang and pollutes beach tags.
        'route_ids' => [],
        'seed_dests' => ['sanya'],
        'match' => [
            'types_any' => ['beach'],
            'scenery_any' => ['coast', 'islands'],
        ],
        'priority_boost' => 35,
        'cap' => 90,
    ],
    [
        'id' => 'food-culinary',
        'kind' => 'style',
        'name' => 'Food & Culinary',
        'name_zh' => '美食风味线',
        'pitch' => 'Street food, night markets and regional kitchens woven into walkable city days.',
        'hubs' => ['Chengdu', 'Xi\'an', 'Guangzhou', 'Shanghai', 'Chongqing'],
        'days_hint' => '4–8',
        'route_ids' => ['r1', 'r2'],
        'seed_dests' => [
            'panda-base', 'shanghai-bund', 'xian-city-wall', 'terracotta-army',
        ],
        'match' => [
            'types_any' => ['food', 'city', 'culture'],
            'cities_any' => [
                'Chengdu', 'Chongqing', 'Guangzhou', 'Shanghai', 'Xian', 'Xi\'an',
                'Beijing', 'Hangzhou', 'Changsha', 'Wuhan',
            ],
        ],
        'priority_boost' => 20,
        'cap' => 90,
    ],
    [
        'id' => 'luxury',
        'kind' => 'style',
        'name' => 'Luxury & Slow Travel',
        'name_zh' => '奢华慢旅行',
        'pitch' => 'Heritage hotels, private transfers, spa days and first-class rail between icons.',
        'hubs' => ['Beijing', 'Hangzhou', 'Lijiang', 'Sanya', 'Shanghai'],
        'days_hint' => '7–12',
        'route_ids' => ['r1', 'r5', 'r6'],
        'seed_dests' => [
            'sanya', 'potala-palace', 'shanghai-bund', 'west-lake', 'lijiang-old-town',
            'forbidden-city', 'great-wall',
        ],
        'match' => [
            'types_any' => ['luxury', 'city', 'beach', 'history'],
            'scenery_any' => ['city', 'coast', 'heritage', 'lakes', 'ancient'],
            'cities_any' => ['Beijing', 'Shanghai', 'Hangzhou', 'Lijiang', 'Sanya', 'Chengdu'],
        ],
        'priority_boost' => 15,
        'cap' => 70,
    ],
    [
        'id' => 'winter',
        'kind' => 'style',
        'name' => 'Winter Wonderland',
        'name_zh' => '冬季冰雪线',
        'pitch' => 'Ice festivals, ski resorts and crystal-cold city lights — peak Dec–Feb packs.',
        'hubs' => ['Harbin', 'Changchun', 'Chongli', 'Changbaishan'],
        'days_hint' => '4–7',
        'route_ids' => [],
        'seed_dests' => ['harbin-ice'],
        'match' => [
            'types_any' => ['winter', 'ski'],
            'scenery_any' => ['snow'],
            'regions_any' => ['Northeast China'],
        ],
        'priority_boost' => 40,
        'cap' => 80,
    ],
    [
        'id' => 'religion-pilgrimage',
        'kind' => 'style',
        'name' => 'Temples & Sacred Mountains',
        'name_zh' => '宗教朝圣线',
        'pitch' => 'Buddhist & Taoist holy mountains, monasteries and living ritual calendars.',
        'hubs' => ['Wutai', 'Emei', 'Putuo', 'Shaolin', 'Lhasa'],
        'days_hint' => '5–10',
        'route_ids' => ['r4', 'r7'],
        'seed_dests' => [
            'potala-palace', 'shaolin', 'emei', 'putuo', 'wutai', 'yonghe', 'leshan-buddha',
        ],
        'match' => [
            'types_any' => ['religion'],
            'scenery_any' => ['temples', 'mountains', 'heritage'],
        ],
        'priority_boost' => 25,
        'cap' => 100,
    ],
    [
        'id' => 'wildlife',
        'kind' => 'style',
        'name' => 'Wildlife & Pandas',
        'name_zh' => '熊猫与野生动物',
        'pitch' => 'Giant pandas, city zoos and safari parks — gentle days with strong photo and family overlap.',
        'hubs' => ['Chengdu', 'Beijing', 'Guangzhou', 'Wolong'],
        'days_hint' => '3–6',
        'route_ids' => ['r2'],
        // Flagship zoos + panda base so wildlife shelf is not nature-reserve-only.
        'seed_dests' => [
            'panda-base',
            'chimelong',
            'osm-way-29222967', // Beijing Zoo
            'hongshan-forest-zoo',
            'chongqing-zoo',
        ],
        'match' => [
            // Prefer typed wildlife; city zoos use scenery=city after reclassify.
            'types_any' => ['wildlife'],
            'scenery_any' => ['forest', 'wetlands', 'grassland', 'city', 'coast'],
        ],
        'priority_boost' => 40,
        'cap' => 200,
    ],
    [
        'id' => 'cycling-self-drive',
        'kind' => 'style',
        'name' => 'Cycling & Self-Drive',
        'name_zh' => '骑行与自驾',
        'pitch' => 'County roads, island loops and wall circuits — freedom packs with license caveats for foreigners.',
        'hubs' => ['Yangshuo', 'Dali', 'Sanya', 'Xi\'an'],
        'days_hint' => '4–10',
        'route_ids' => ['r3', 'r6', 'r8'],
        'seed_dests' => [
            'guilin-liriver', 'xian-city-wall', 'lijiang-old-town', 'west-lake', 'sanya',
            'jiuzhaigou', 'zhangjiajie', 'dali',
        ],
        'match' => [
            'types_any' => ['cycling', 'nature', 'beach', 'village'],
            'scenery_any' => ['karst', 'coast', 'lakes', 'grassland', 'ancient', 'mountains'],
        ],
        'priority_boost' => 10,
        'cap' => 100,
    ],
    [
        'id' => 'romantic',
        'kind' => 'style',
        'name' => 'Romantic Escape',
        'name_zh' => '浪漫之旅',
        'pitch' => 'Lakeside walks, lantern towns, sunset dunes and couple-paced days — soft light over hard logistics.',
        'hubs' => ['Hangzhou', 'Lijiang', 'Suzhou', 'Sanya', 'Yangshuo'],
        'days_hint' => '4–8',
        'route_ids' => ['r5', 'r6', 'r8'],
        'seed_dests' => [
            'west-lake', 'lijiang-old-town', 'suzhou-gardens', 'wuzhen', 'sanya',
            'guilin-liriver', 'shanghai-bund',
        ],
        'match' => [
            'types_any' => ['ancient', 'village', 'beach', 'night'],
            'scenery_any' => ['lakes', 'gardens', 'ancient', 'coast', 'islands', 'karst'],
            'cities_any' => ['Hangzhou', 'Lijiang', 'Suzhou', 'Sanya', 'Guilin', 'Shanghai'],
        ],
        'priority_boost' => 22,
        'cap' => 110,
    ],
    [
        'id' => 'free-independent',
        'kind' => 'style',
        'name' => 'Free & Independent',
        'name_zh' => '自由之旅',
        'pitch' => 'Flexible HSR hops, walkable hubs and DIY days — for travelers who want the plan without the tour bus.',
        'hubs' => ['Beijing', 'Shanghai', 'Chengdu', 'Xi\'an', 'Guangzhou'],
        'days_hint' => '5–12',
        'route_ids' => ['r1', 'r5'],
        'seed_dests' => [
            'great-wall', 'forbidden-city', 'shanghai-bund', 'west-lake', 'terracotta-army',
            'xian-city-wall', 'panda-base',
        ],
        'match' => [
            'types_any' => ['city', 'cycling', 'hiking', 'food', 'landmark'],
            'scenery_any' => ['city', 'ancient', 'heritage', 'lakes'],
            'cities_any' => [
                'Beijing', 'Shanghai', 'Chengdu', 'Xian', 'Xi\'an', 'Guangzhou',
                'Hangzhou', 'Nanjing', 'Shenzhen',
            ],
        ],
        'priority_boost' => 18,
        'cap' => 140,
    ],
    [
        'id' => 'otherworldly',
        'kind' => 'style',
        'name' => 'Otherworldly Landscapes',
        'name_zh' => '异星地貌',
        'pitch' => 'Avatar pillars, rainbow danxia, desert yardangs and surreal karst — landscapes that feel off-planet.',
        'hubs' => ['Zhangjiajie', 'Zhangye', 'Dunhuang', 'Guilin', 'Jiuzhaigou'],
        'days_hint' => '5–9',
        'route_ids' => ['r3', 'r2', 'r4'],
        'seed_dests' => [
            'zhangjiajie', 'guilin-liriver', 'jiuzhaigou', 'mogao-caves', 'huangshan',
        ],
        'match' => [
            'types_any' => ['nature', 'hiking', 'desert', 'photography'],
            'scenery_any' => ['karst', 'danxia', 'desert', 'gorges', 'mountains', 'waterfalls'],
        ],
        'priority_boost' => 28,
        'cap' => 120,
    ],
    [
        'id' => 'ancient-culture',
        'kind' => 'style',
        'name' => 'Ancient Culture',
        'name_zh' => '古文化',
        'pitch' => 'Dynastic museums, Confucian roots, grotto art and living ritual — culture-first itineraries.',
        'hubs' => ['Beijing', 'Xi\'an', 'Luoyang', 'Qufu', 'Dunhuang'],
        'days_hint' => '5–10',
        'route_ids' => ['r1', 'r4', 'r7'],
        'seed_dests' => [
            'forbidden-city', 'terracotta-army', 'mogao-caves', 'longmen', 'yungang',
            'qufu', 'national-museum', 'shaolin',
        ],
        'match' => [
            'types_any' => ['history', 'culture', 'religion', 'art'],
            'scenery_any' => ['heritage', 'temples'],
        ],
        'priority_boost' => 24,
        'cap' => 160,
    ],
    [
        'id' => 'ancient-town',
        'kind' => 'style',
        'name' => 'Ancient Towns',
        'name_zh' => '古镇',
        'pitch' => 'Water towns, ethnic villages and lantern lanes — stay overnight when the day-trippers leave.',
        'hubs' => ['Wuzhen', 'Lijiang', 'Dali', 'Fenghuang', 'Zhenyuan'],
        'days_hint' => '3–7',
        'route_ids' => ['r5', 'r8'],
        'seed_dests' => [
            'wuzhen', 'lijiang-old-town', 'dali', 'zhenyuan', 'pingyao',
        ],
        'match' => [
            'types_any' => ['ancient', 'village'],
            'scenery_any' => ['ancient'],
        ],
        'priority_boost' => 32,
        'cap' => 130,
    ],
    [
        'id' => 'ancient-city',
        'kind' => 'style',
        'name' => 'Ancient Cities',
        'name_zh' => '古城',
        'pitch' => 'Walled cities, imperial capitals and intact street grids — walk the ramparts, then the night market.',
        'hubs' => ['Xi\'an', 'Pingyao', 'Nanjing', 'Beijing', 'Kashgar'],
        'days_hint' => '3–6',
        'route_ids' => ['r1', 'r7'],
        'seed_dests' => [
            'xian-city-wall', 'pingyao', 'forbidden-city', 'terracotta-army',
            'lijiang-old-town', 'shanhaiguan',
        ],
        'match' => [
            'types_any' => ['history', 'ancient', 'city', 'landmark'],
            'scenery_any' => ['heritage', 'ancient', 'city'],
            'cities_any' => [
                'Xian', 'Xi\'an', 'Pingyao', 'Beijing', 'Nanjing', 'Datong', 'Kashgar',
            ],
        ],
        'priority_boost' => 26,
        'cap' => 120,
    ],
    [
        'id' => 'night-city',
        'kind' => 'style',
        'name' => 'Night City Lights',
        'name_zh' => '夜游都市',
        'pitch' => 'Skyline ferries, neon old towns and late food streets — after-dark decision packs.',
        'hubs' => ['Shanghai', 'Chongqing', 'Hong Kong', 'Guangzhou', 'Beijing'],
        'days_hint' => '2–5',
        'route_ids' => ['r1', 'r5'],
        'seed_dests' => [
            'shanghai-bund', 'shichahai', '798',
        ],
        'match' => [
            'types_any' => ['night', 'city', 'food'],
            'scenery_any' => ['city'],
            'cities_any' => [
                'Shanghai', 'Chongqing', 'Hong Kong', 'Guangzhou', 'Beijing', 'Shenzhen',
            ],
        ],
        'priority_boost' => 20,
        'cap' => 80,
    ],
    [
        'id' => 'ethnic-culture',
        'kind' => 'style',
        'name' => 'Ethnic Peoples',
        'name_zh' => '民族之旅',
        'pitch' => 'Naxi, Tibetan, Miao, Uyghur and other peoples — language, dress, monasteries and bazaars with altitude awareness.',
        'hubs' => ['Lijiang', 'Shangri-La', 'Lhasa', 'Kashgar', 'Guiyang'],
        'days_hint' => '5–10',
        'route_ids' => ['r8', 'r4'],
        'seed_dests' => [
            'lijiang-old-town', 'dali', 'shangrila', 'potala-palace', 'tiger-leaping',
        ],
        'match' => [
            'types_any' => ['culture', 'village', 'ancient', 'religion'],
            'regions_any' => ['Southwest China', 'Northwest China'],
            'scenery_any' => ['ancient', 'grassland', 'temples', 'snow'],
            'provinces_any' => ['Yunnan', 'Tibet', 'Guizhou', 'Xinjiang', 'Qinghai', 'Sichuan'],
        ],
        'priority_boost' => 22,
        'cap' => 120,
    ],
    [
        'id' => 'folkways',
        'kind' => 'style',
        'name' => 'Folkways & Local Life',
        'name_zh' => '民风民俗',
        'pitch' => 'Markets, festivals, craft streets and everyday lanes — how places feel when you slow down.',
        'hubs' => ['Xi\'an', 'Chengdu', 'Dali', 'Pingyao', 'Suzhou'],
        'days_hint' => '3–7',
        'route_ids' => ['r5', 'r7', 'r8'],
        'seed_dests' => [
            'xian-city-wall', 'pingyao', 'dali', 'wuzhen', 'lijiang-old-town',
            'shanghai-bund', 'panda-base',
        ],
        'match' => [
            'types_any' => ['culture', 'food', 'village', 'ancient', 'art'],
            'scenery_any' => ['ancient', 'city', 'gardens', 'heritage'],
        ],
        'priority_boost' => 20,
        'cap' => 130,
    ],
    [
        'id' => 'snow-mountain',
        'kind' => 'style',
        'name' => 'Snow Mountains',
        'name_zh' => '雪山圣境',
        'pitch' => 'Jade Dragon, Meili vistas, plateau light and oxygen-aware pacing — high peaks without guesswork.',
        'hubs' => ['Lijiang', 'Shangri-La', 'Lhasa', 'Kangding', 'Huangshan'],
        'days_hint' => '4–9',
        'route_ids' => ['r8', 'r4'],
        'seed_dests' => [
            'lijiang-old-town', 'shangrila', 'potala-palace', 'tiger-leaping', 'huangshan',
            'huashan',
        ],
        'match' => [
            'types_any' => ['hiking', 'nature', 'winter'],
            'scenery_any' => ['snow', 'mountains', 'gorges'],
            'provinces_any' => ['Yunnan', 'Tibet', 'Sichuan', 'Qinghai', 'Xinjiang', 'Gansu'],
        ],
        'priority_boost' => 30,
        'cap' => 100,
    ],
    [
        'id' => 'tropical',
        'kind' => 'style',
        'name' => 'Tropical China',
        'name_zh' => '热带风情',
        'pitch' => 'Hainan beaches, Xishuangbanna rainforest and Lingnan warmth — winter-sun and humid-green packs.',
        'hubs' => ['Sanya', 'Haikou', 'Xishuangbanna', 'Zhuhai'],
        'days_hint' => '4–8',
        'route_ids' => [],
        'seed_dests' => ['sanya'],
        'match' => [
            'types_any' => ['beach'],
            'scenery_any' => ['coast', 'islands'],
            'provinces_any' => ['Hainan'],
            'cities_any' => ['Sanya', 'Haikou', 'Xishuangbanna'],
        ],
        'priority_boost' => 28,
        'cap' => 90,
    ],
    [
        'id' => 'grassland',
        'kind' => 'style',
        'name' => 'Grassland & Steppe',
        'name_zh' => '草原牧歌',
        'pitch' => 'Hulunbuir horizons, horse culture and sky roads — summer green, autumn gold.',
        'hubs' => ['Hulunbuir', 'Xilinhot', 'Zhangjiakou', 'Qinghai Lake'],
        'days_hint' => '4–8',
        'route_ids' => [],
        'seed_dests' => ['hulunbuir'],
        'match' => [
            'scenery_any' => ['grassland'],
        ],
        'priority_boost' => 30,
        'cap' => 70,
    ],
    [
        'id' => 'karst-river',
        'kind' => 'style',
        'name' => 'Karst & River Poems',
        'name_zh' => '喀斯特与江河',
        'pitch' => 'Li River, Yulong rafts and limestone towers — classic Chinese-painting landscapes you can bike.',
        'hubs' => ['Guilin', 'Yangshuo', 'Xingyi', 'Libo'],
        'days_hint' => '3–6',
        'route_ids' => ['r3'],
        'seed_dests' => ['guilin-liriver', 'xiaoqikong'],
        'match' => [
            'types_any' => ['nature', 'cycling', 'hiking'],
            'scenery_any' => ['karst', 'lakes', 'gorges', 'waterfalls'],
            'provinces_any' => ['Guangxi', 'Guizhou', 'Yunnan'],
        ],
        'priority_boost' => 26,
        'cap' => 90,
    ],

    // ── Regional packs ───────────────────────────────────────────
    [
        'id' => 'region-north',
        'kind' => 'region',
        'name' => 'North China Hub',
        'name_zh' => '华北枢纽（北京·天津·河北·山西）',
        'pitch' => 'Imperial capitals, Wall sections and Jin merchant towns — the northern gateway pack.',
        'hubs' => ['Beijing', 'Tianjin', 'Datong', 'Pingyao'],
        'days_hint' => '4–8',
        // Region membership = destinations.region only (featured order via featured_pref).
        'route_ids' => [],
        'seed_dests' => [],
        'featured_pref' => [
            'great-wall', 'forbidden-city', 'temple-heaven', 'summer-palace', 'pingyao', 'yungang',
        ],
        'match' => [
            'regions_any' => ['North China'],
        ],
        'priority_boost' => 5,
        'cap' => 0,
    ],
    [
        'id' => 'region-east-jiangnan',
        'kind' => 'region',
        'name' => 'East China & Jiangnan',
        'name_zh' => '华东与江南',
        'pitch' => 'Shanghai skyline + gardens + water towns + West Lake — dense HSR mesh, high comfort.',
        'hubs' => ['Shanghai', 'Hangzhou', 'Suzhou', 'Nanjing', 'Huangshan'],
        'days_hint' => '5–9',
        'route_ids' => [],
        'seed_dests' => [],
        'featured_pref' => [
            'shanghai-bund', 'west-lake', 'suzhou-gardens', 'wuzhen', 'huangshan',
        ],
        'match' => [
            'regions_any' => ['East China'],
        ],
        'priority_boost' => 5,
        'cap' => 0,
    ],
    [
        'id' => 'region-southwest',
        'kind' => 'region',
        'name' => 'Southwest Highlands',
        'name_zh' => '西南高原（川渝云贵藏）',
        'pitch' => 'Pandas, karst, old towns and plateaus — China\'s most photogenic multi-climate region.',
        'hubs' => ['Chengdu', 'Chongqing', 'Lijiang', 'Guiyang', 'Lhasa'],
        'days_hint' => '7–14',
        'route_ids' => [],
        'seed_dests' => [],
        'featured_pref' => [
            'panda-base', 'jiuzhaigou', 'lijiang-old-town', 'dali', 'shangrila', 'potala-palace',
        ],
        'match' => [
            'regions_any' => ['Southwest China'],
        ],
        'priority_boost' => 5,
        'cap' => 0,
    ],
    [
        'id' => 'region-south',
        'kind' => 'region',
        'name' => 'South China & Tropics',
        'name_zh' => '华南与热带（粤桂琼港澳）',
        'pitch' => 'Cantonese cities, karst Guilin and Hainan beaches — warm-weather and food-forward.',
        'hubs' => ['Guangzhou', 'Shenzhen', 'Guilin', 'Sanya', 'Hong Kong', 'Macau'],
        'days_hint' => '5–10',
        'route_ids' => [],
        'seed_dests' => [],
        'featured_pref' => ['guilin-liriver', 'sanya'],
        'match' => [
            'regions_any' => ['South China'],
        ],
        'priority_boost' => 5,
        'cap' => 0,
    ],
    [
        'id' => 'region-northwest',
        'kind' => 'region',
        'name' => 'Northwest & Silk Road',
        'name_zh' => '西北丝路',
        'pitch' => 'Deserts, grottoes, grasslands and oasis cities — long distances, huge payoff.',
        'hubs' => ['Xi\'an', 'Dunhuang', 'Urumqi', 'Kashgar', 'Xining'],
        'days_hint' => '8–14',
        'route_ids' => [],
        'seed_dests' => [],
        'featured_pref' => ['mogao-caves'],
        'match' => [
            'regions_any' => ['Northwest China'],
        ],
        'priority_boost' => 5,
        'cap' => 0,
    ],
    [
        'id' => 'region-central',
        'kind' => 'region',
        'name' => 'Central Plains Capitals',
        'name_zh' => '中原古都',
        'pitch' => 'Luoyang, Kaifeng, Wuhan and mountain temples — civilization cradle with short hops.',
        'hubs' => ['Luoyang', 'Zhengzhou', 'Wuhan', 'Changsha', 'Zhangjiajie'],
        'days_hint' => '4–8',
        'route_ids' => [],
        'seed_dests' => [],
        'featured_pref' => ['longmen', 'shaolin', 'zhangjiajie'],
        'match' => [
            'regions_any' => ['Central China'],
        ],
        'priority_boost' => 5,
        'cap' => 0,
    ],
    [
        'id' => 'region-northeast',
        'kind' => 'region',
        'name' => 'Northeast China',
        'name_zh' => '东北冰雪与工业遗产',
        'pitch' => 'Ice cities, ski basins and border towns — winter peak plus summer cool escapes.',
        'hubs' => ['Harbin', 'Changchun', 'Shenyang', 'Dalian'],
        'days_hint' => '4–8',
        'route_ids' => [],
        'seed_dests' => [],
        'featured_pref' => ['harbin-ice'],
        'match' => [
            'regions_any' => ['Northeast China'],
        ],
        'priority_boost' => 5,
        'cap' => 0,
    ],

    // ── Flagship sellable packages (curated, smaller) ─────────────
    [
        'id' => 'pack-golden-triangle',
        'kind' => 'flagship',
        'name' => 'Package: Golden Triangle',
        'name_zh' => '套餐：黄金三角（京·西·沪）',
        'pitch' => 'Flagship information package — day-by-day decisions for the classic first-China circuit.',
        'hubs' => ['Beijing', 'Xi\'an', 'Shanghai'],
        'days_hint' => '8',
        'route_ids' => ['r1'],
        'seed_dests' => [
            'great-wall', 'forbidden-city', 'temple-heaven', 'terracotta-army',
            'xian-city-wall', 'shanghai-bund', 'summer-palace', 'jingshan',
            'beihai', 'suzhou-gardens', 'west-lake',
        ],
        'match' => null,
        'priority_boost' => 100,
        'cap' => 24,
    ],
    [
        'id' => 'pack-sichuan-nature',
        'kind' => 'flagship',
        'name' => 'Package: Sichuan Natural Wonders',
        'name_zh' => '套餐：四川自然奇迹',
        'pitch' => 'Pandas → Leshan → Jiuzhaigou with altitude and season windows baked in.',
        'hubs' => ['Chengdu', 'Leshan', 'Jiuzhaigou'],
        'days_hint' => '8',
        'route_ids' => ['r2'],
        'seed_dests' => ['panda-base', 'leshan-buddha', 'jiuzhaigou', 'emei'],
        'match' => null,
        'priority_boost' => 100,
        'cap' => 20,
    ],
    [
        'id' => 'pack-avatar-liriver',
        'kind' => 'flagship',
        'name' => 'Package: Avatar Peaks & Li River',
        'name_zh' => '套餐：张家界·漓江',
        'pitch' => 'Two surreal landscapes, one pacing plan — pillars then karst river.',
        'hubs' => ['Zhangjiajie', 'Guilin', 'Yangshuo'],
        'days_hint' => '6',
        'route_ids' => ['r3'],
        'seed_dests' => ['zhangjiajie', 'guilin-liriver'],
        'match' => null,
        'priority_boost' => 100,
        'cap' => 16,
    ],
    [
        'id' => 'pack-jiangnan-water',
        'kind' => 'flagship',
        'name' => 'Package: Jiangnan Water Towns',
        'name_zh' => '套餐：江南水乡',
        'pitch' => 'Shanghai → Suzhou → Wuzhen → Hangzhou with garden vs canal trade-offs explained.',
        'hubs' => ['Shanghai', 'Suzhou', 'Wuzhen', 'Hangzhou'],
        'days_hint' => '6',
        'route_ids' => ['r5'],
        'seed_dests' => [
            'shanghai-bund', 'suzhou-gardens', 'wuzhen', 'west-lake',
        ],
        'match' => null,
        'priority_boost' => 100,
        'cap' => 20,
    ],
    [
        'id' => 'pack-yunnan-highlands',
        'kind' => 'flagship',
        'name' => 'Package: Yunnan Highlands',
        'name_zh' => '套餐：云南高原',
        'pitch' => 'Dali → Lijiang → Shangri-La with altitude-aware pacing and gorge options.',
        'hubs' => ['Dali', 'Lijiang', 'Shangri-La'],
        'days_hint' => '6',
        'route_ids' => ['r8'],
        'seed_dests' => [
            'dali', 'lijiang-old-town', 'shangrila', 'tiger-leaping',
        ],
        'match' => null,
        'priority_boost' => 100,
        'cap' => 20,
    ],
    [
        'id' => 'pack-silk-tibet',
        'kind' => 'flagship',
        'name' => 'Package: Silk Road & Tibet',
        'name_zh' => '套餐：丝路与西藏',
        'pitch' => 'Permit-aware epic: Xi\'an → Dunhuang → Lhasa with acclimatization baked in.',
        'hubs' => ['Xi\'an', 'Dunhuang', 'Lhasa'],
        'days_hint' => '12',
        'route_ids' => ['r4'],
        'seed_dests' => [
            'terracotta-army', 'mogao-caves', 'potala-palace', 'xian-city-wall',
        ],
        'match' => null,
        'priority_boost' => 100,
        'cap' => 18,
    ],
];

/**
 * @param array<string, mixed> $dest
 * @param array<string, mixed>|null $match
 */
function scoreDestination(array $dest, ?array $match, int $boostIfSeed, bool $isSeed): int
{
    if ($isSeed) {
        return 1000 + $boostIfSeed;
    }
    if ($match === null) {
        return 0;
    }

    $score = 0;
    $types = array_map('strval', $dest['types'] ?? []);
    $scenery = (string) ($dest['scenery'] ?? '');
    $region = (string) ($dest['region'] ?? '');
    $city = (string) ($dest['city'] ?? '');
    $province = (string) ($dest['province'] ?? '');

    $hit = false;

    if (!empty($match['types_any'])) {
        foreach ($match['types_any'] as $t) {
            if (in_array((string) $t, $types, true)) {
                $score += 12;
                $hit = true;
            }
        }
    }
    if (!empty($match['scenery_any']) && in_array($scenery, array_map('strval', $match['scenery_any']), true)) {
        $score += 10;
        $hit = true;
    }
    if (!empty($match['regions_any']) && in_array($region, array_map('strval', $match['regions_any']), true)) {
        $score += 8;
        $hit = true;
    }
    if (!empty($match['cities_any']) && in_array($city, array_map('strval', $match['cities_any']), true)) {
        $score += 14;
        $hit = true;
    }
    if (!empty($match['provinces_any']) && in_array($province, array_map('strval', $match['provinces_any']), true)) {
        $score += 6;
        $hit = true;
    }

    if (!$hit) {
        return 0;
    }

    // Prefer destinations that already have executable plan/tips (package-ready).
    if (!empty($dest['plan'])) {
        $score += 8;
    }
    if (!empty($dest['tips']) && is_array($dest['tips']) && count($dest['tips']) >= 2) {
        $score += 4;
    }
    if (!empty($dest['how'])) {
        $score += 3;
    }
    if (isset($dest['tier']) && $dest['tier'] === 'under100') {
        $score += 1;
    }

    return $score + $boostIfSeed;
}

$routeDestMap = [];
foreach ($routes as $r) {
    $routeDestMap[(string) $r['id']] = array_map('strval', $r['dests'] ?? []);
}

$builtThemes = [];
$indexByTheme = [];
$destToThemes = [];

foreach ($themes as $theme) {
    $seed = array_values(array_unique(array_map('strval', $theme['seed_dests'] ?? [])));
    // Pull related route dests into seed for style/flagship only.
    // Region themes must stay geography-pure (regions_any), or Silk Road hubs leak across regions.
    if (($theme['kind'] ?? '') !== 'region') {
        foreach ($theme['route_ids'] ?? [] as $rid) {
            foreach ($routeDestMap[(string) $rid] ?? [] as $did) {
                $seed[] = $did;
            }
        }
        $seed = array_values(array_unique($seed));
    }
    $seedSet = array_fill_keys($seed, true);

    $scored = [];
    foreach ($byId as $id => $dest) {
        $isSeed = isset($seedSet[$id]);
        $s = scoreDestination($dest, $theme['match'] ?? null, (int) $theme['priority_boost'], $isSeed);
        if ($s <= 0) {
            continue;
        }
        $scored[] = ['id' => (string) $id, 'score' => $s];
    }

    usort($scored, static function (array $a, array $b): int {
        if ($a['score'] === $b['score']) {
            return strcmp((string) $a['id'], (string) $b['id']);
        }
        return $b['score'] <=> $a['score'];
    });

    // cap=0 → uncapped (used for region themes so every spot has a geographic home)
    $cap = (int) ($theme['cap'] ?? 100);
    $uncapped = ($cap <= 0) || (($theme['kind'] ?? '') === 'region');
    $picked = $uncapped ? $scored : array_slice($scored, 0, $cap);
    $destIds = array_column($picked, 'id');

    // Ensure all seeds that exist in the catalog appear first (even if over soft ranking).
    $ordered = [];
    foreach ($seed as $sid) {
        if (isset($byId[$sid]) && !in_array($sid, $ordered, true)) {
            $ordered[] = $sid;
        }
    }
    foreach ($destIds as $did) {
        if (!in_array($did, $ordered, true)) {
            $ordered[] = $did;
        }
    }
    if (!$uncapped) {
        $ordered = array_slice($ordered, 0, $cap);
    }

    foreach ($ordered as $did) {
        $destToThemes[$did][] = $theme['id'];
    }

    $featured = [];
    foreach ($theme['featured_pref'] ?? [] as $fid) {
        $fid = (string) $fid;
        if (in_array($fid, $ordered, true) && !in_array($fid, $featured, true)) {
            $featured[] = $fid;
        }
    }
    foreach ($ordered as $oid) {
        if (count($featured) >= 12) {
            break;
        }
        if (!in_array($oid, $featured, true)) {
            $featured[] = $oid;
        }
    }

    $builtThemes[] = [
        'id' => $theme['id'],
        'kind' => $theme['kind'],
        'name' => $theme['name'],
        'name_zh' => $theme['name_zh'],
        'pitch' => $theme['pitch'],
        'hubs' => $theme['hubs'],
        'days_hint' => $theme['days_hint'],
        'route_ids' => $theme['route_ids'],
        'dest_count' => count($ordered),
        'dests' => $ordered,
        'featured' => $featured,
    ];
    $indexByTheme[$theme['id']] = $ordered;
}

/** @var array<string, array<string, mixed>> $themeById */
$themeById = [];
foreach ($themes as $theme) {
    $themeById[(string) $theme['id']] = $theme;
}

// Second pass: destinations with zero tags OR only region tags get best style match appended.
$styleThemeDefs = array_values(array_filter(
    $themes,
    static fn (array $t): bool => ($t['kind'] ?? '') === 'style'
));
$regionToTheme = [
    'North China' => 'region-north',
    'East China' => 'region-east-jiangnan',
    'Southwest China' => 'region-southwest',
    'South China' => 'region-south',
    'Northwest China' => 'region-northwest',
    'Central China' => 'region-central',
    'Northeast China' => 'region-northeast',
];

$rescued = 0;
foreach ($byId as $id => $dest) {
    $tags = $destToThemes[$id] ?? [];
    $hasStyle = false;
    foreach ($tags as $tid) {
        if (($themeById[$tid]['kind'] ?? '') === 'style') {
            $hasStyle = true;
            break;
        }
    }

    if ($tags === []) {
        $region = (string) ($dest['region'] ?? '');
        $rid = $regionToTheme[$region] ?? null;
        if ($rid !== null) {
            $destToThemes[$id][] = $rid;
            if (!in_array($id, $indexByTheme[$rid] ?? [], true)) {
                $indexByTheme[$rid][] = $id;
            }
            $rescued++;
        }
    }

    if ($hasStyle) {
        continue;
    }

    $bestId = null;
    $bestScore = 0;
    foreach ($styleThemeDefs as $st) {
        $s = scoreDestination($dest, $st['match'] ?? null, 0, false);
        if ($s > $bestScore) {
            $bestScore = $s;
            $bestId = (string) $st['id'];
        }
    }
    if ($bestId === null || $bestScore <= 0) {
        // Fallback style by scenery / types heuristics
        $types = array_map('strval', $dest['types'] ?? []);
        $scenery = (string) ($dest['scenery'] ?? '');
        if (in_array('beach', $types, true) || in_array($scenery, ['coast', 'islands'], true)) {
            $bestId = 'tropical';
        } elseif ($scenery === 'snow') {
            $bestId = 'snow-mountain';
        } elseif ($scenery === 'grassland') {
            $bestId = 'grassland';
        } elseif ($scenery === 'karst') {
            $bestId = 'karst-river';
        } elseif (in_array($scenery, ['danxia', 'desert'], true)) {
            $bestId = 'otherworldly';
        } elseif (in_array('village', $types, true) || ($scenery === 'ancient' && in_array('ancient', $types, true))) {
            $bestId = 'ancient-town';
        } elseif (in_array('culture', $types, true) && in_array('food', $types, true)) {
            $bestId = 'folkways';
        } elseif (in_array('ancient', $types, true) || $scenery === 'ancient') {
            $bestId = 'ancient-city';
        } elseif (in_array('history', $types, true) || $scenery === 'heritage') {
            $bestId = 'ancient-culture';
        } elseif (in_array('religion', $types, true) || $scenery === 'temples') {
            $bestId = 'religion-pilgrimage';
        } else {
            $bestId = 'nature-landscape';
        }
    }

    $destToThemes[$id][] = $bestId;
    if (!in_array($id, $indexByTheme[$bestId] ?? [], true)) {
        $indexByTheme[$bestId][] = $id;
    }
    $rescued++;
}

// Rebuild theme dest lists / counts from indexes after rescue pass.
foreach ($builtThemes as &$bt) {
    $tid = (string) $bt['id'];
    $list = array_values(array_unique(array_map('strval', $indexByTheme[$tid] ?? [])));
    $featured = [];
    foreach ($themeById[$tid]['featured_pref'] ?? [] as $fid) {
        $fid = (string) $fid;
        if (in_array($fid, $list, true) && !in_array($fid, $featured, true)) {
            $featured[] = $fid;
        }
    }
    foreach ($list as $oid) {
        if (count($featured) >= 12) {
            break;
        }
        if (!in_array($oid, $featured, true)) {
            $featured[] = $oid;
        }
    }
    $bt['dests'] = $list;
    $bt['dest_count'] = count($list);
    $bt['featured'] = $featured;
    $indexByTheme[$tid] = $list;
}
unset($bt);

// Compact reverse index: destination → theme ids
ksort($destToThemes);
$destThemeIndex = [];
foreach ($destToThemes as $did => $tids) {
    $destThemeIndex[$did] = array_values(array_unique($tids));
}

$untagged = [];
foreach ($byId as $id => $_) {
    if (!isset($destThemeIndex[$id]) || $destThemeIndex[$id] === []) {
        $untagged[] = $id;
    }
}

$catalog = [
    'schema' => 'playinginchina.route-themes.v1',
    'updated' => gmdate('Y-m-d\TH:i:s\Z'),
    'purpose' => 'Overseas→China information packages: classify destinations by travel style, region, and flagship sellable packs.',
    'axes' => [
        'style' => 'Travel intent / vibe packs (history, nature, family, photo, silk-road…)',
        'region' => 'Geographic hubs matching China tourism regions (uncapped)',
        'flagship' => 'Curated purchasable route packages (smaller, decision-complete)',
    ],
    'stats' => [
        'destinations_total' => count($byId),
        'themes_total' => count($builtThemes),
        'destinations_tagged' => count($destThemeIndex),
        'destinations_untagged' => count($untagged),
        'rescued_this_run' => $rescued,
    ],
    'themes' => $builtThemes,
];

$write = static function (string $path, mixed $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('json_encode failed for ' . $path);
    }
    if (file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('write failed: ' . $path);
    }
};

$write($dataDir . '/route-themes.json', $catalog);
$write($dataDir . '/index-by-theme.json', $indexByTheme);
$write($dataDir . '/index-destination-themes.json', $destThemeIndex);

echo "OK themes=" . count($builtThemes)
    . " tagged=" . count($destThemeIndex)
    . " untagged=" . count($untagged)
    . " rescued=" . $rescued
    . " dests=" . count($byId) . PHP_EOL;
foreach ($builtThemes as $t) {
    echo sprintf(
        "  %-28s %-10s dests=%-4d featured=%s\n",
        $t['id'],
        $t['kind'],
        $t['dest_count'],
        implode(',', array_slice($t['featured'], 0, 5))
    );
}

if ($reportUntagged || $untagged !== []) {
    echo "UNTAGGED=" . count($untagged) . PHP_EOL;
    foreach ($untagged as $uid) {
        $d = $byId[$uid] ?? [];
        echo '  ' . $uid
            . '|' . (string) ($d['name'] ?? '')
            . '|' . (string) ($d['region'] ?? '')
            . '|' . implode(',', array_map('strval', $d['types'] ?? []))
            . PHP_EOL;
    }
}
