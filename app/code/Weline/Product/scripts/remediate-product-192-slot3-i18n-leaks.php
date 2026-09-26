<?php

declare(strict_types=1);

/**
 * 产品优化槽③：#192 洛川 — 26 启用语尺码表/试穿区 EN dump + 标题「 Wear」渗漏修复
 *
 * php app/code/Weline/Product/scripts/remediate-product-192-slot3-i18n-leaks.php --dry-run
 * php app/code/Weline/Product/scripts/remediate-product-192-slot3-i18n-leaks.php --apply
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Product\LocalDescription;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\ProductStorefrontCacheInvalidator;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Websites\Model\Website;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'website:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));
$productId = 192;

$root = dirname(__DIR__, 5);
$env = include $root . '/app/etc/env.php';
$db = $env['db']['master'] ?? [];
$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        (string)($db['hostname'] ?? '127.0.0.1'),
        (string)($db['hostport'] ?? '5432'),
        (string)($db['database'] ?? ''),
    ),
    (string)($db['username'] ?? ''),
    (string)($db['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$om = ObjectManager::getInstance();
/** @var Website $ws */
$ws = $om->get(Website::class);
$ws->load($websiteId);
$enabled = $ws->getLanguageCodes();
$localePlan = ['' => (string)($ws->getDefaultLanguage() ?: 'zh_Hans_CN')];
foreach ($enabled as $code) {
    $localePlan[(string)$code] = (string)$code;
}

echo 'ENABLED_LOCALES=' . json_encode(array_keys($localePlan), JSON_UNESCAPED_UNICODE) . PHP_EOL;

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/**
 * Size-chart + try-on packs for locales with visible EN dump.
 * Keys: size_title, fabric, comfort, unit, th_size, th_len, th_band, th_bust, th_hem,
 *        try_title, l_model, l_height, l_weight, l_meas, l_sample
 *
 * @var array<string, array<string, string>>
 */
$packs = [
    'de_DE' => [
        'size_title' => 'Hezi-Rock Größentabelle',
        'fabric' => 'Stoff: Polyester. Bedruckter und plissierter Rock, bestickter Rockbund, elastischer Rücken. Leicht und fließend, mit Anti-Durchscheinen-Futter.',
        'comfort' => 'Passform: locker. Griff: weich. Elastizität: keine. Dicke: mittel.',
        'unit' => 'Einheit: Zentimeter. Der Saum ist der Umfang. Handmaß kann um 1–3 cm abweichen.',
        'th_size' => 'Größe',
        'th_len' => 'Rocklänge (ohne Bund)',
        'th_band' => 'Rockbund',
        'th_bust' => 'Max. Oberweite',
        'th_hem' => 'Saum',
        'try_title' => 'Anprobe',
        'l_model' => 'Model',
        'l_height' => 'Körpergröße',
        'l_weight' => 'Gewicht',
        'l_meas' => 'Maße',
        'l_sample' => 'Probegröße',
    ],
    'it_IT' => [
        'size_title' => 'Tabella taglie gonna hezi',
        'fabric' => 'Tessuto: poliestere. Gonna stampata e plissettata, fascia ricamata, elastico sul retro. Leggera e fluida, con fodera anti-trasparenza.',
        'comfort' => 'Vestibilità: ampia. Mano: morbida. Elasticità: assente. Spessore: medio.',
        'unit' => 'Unità: centimetri. L’orlo è la circonferenza. La misura a mano può variare di 1–3 cm.',
        'th_size' => 'Taglia',
        'th_len' => 'Lunghezza gonna (senza fascia)',
        'th_band' => 'Fascia',
        'th_bust' => 'Busto max.',
        'th_hem' => 'Orlo',
        'try_title' => 'Prova indosso',
        'l_model' => 'Modella',
        'l_height' => 'Altezza',
        'l_weight' => 'Peso',
        'l_meas' => 'Misure',
        'l_sample' => 'Taglia provata',
    ],
    'nl_NL' => [
        'size_title' => 'Maattabel hezi-rok',
        'fabric' => 'Stof: polyester. Bedrukte en geplooide rok, geborduurde tailleband, elastische rug. Licht en vloeiend, met anti-doorschijnende voering.',
        'comfort' => 'Pasvorm: ruim. Hand: zacht. Rek: geen. Dikte: gemiddeld.',
        'unit' => 'Eenheid: centimeters. De zoom is de omtrek. Handmatige meting kan 1–3 cm afwijken.',
        'th_size' => 'Maat',
        'th_len' => 'Roklengte (zonder band)',
        'th_band' => 'Tailleband',
        'th_bust' => 'Max. borstomvang',
        'th_hem' => 'Zoom',
        'try_title' => 'Pasvorm',
        'l_model' => 'Model',
        'l_height' => 'Lengte',
        'l_weight' => 'Gewicht',
        'l_meas' => 'Maten',
        'l_sample' => 'Gepaste maat',
    ],
    'pl_PL' => [
        'size_title' => 'Tabela rozmiarów spódnicy hezi',
        'fabric' => 'Tkanina: poliester. Spódnica drukowana i plisowana, haftowany pas, elastyczny tył. Lekka i płynna, z podszewką antyprześwitową.',
        'comfort' => 'Krój: luźny. Dotyk: miękki. Elastyczność: brak. Grubość: średnia.',
        'unit' => 'Jednostka: centymetry. Rąbek to obwód. Pomiar ręczny może różnić się o 1–3 cm.',
        'th_size' => 'Rozmiar',
        'th_len' => 'Długość spódnicy (bez pasa)',
        'th_band' => 'Pas',
        'th_bust' => 'Max. biust',
        'th_hem' => 'Rąbek',
        'try_title' => 'Przymiarka',
        'l_model' => 'Modelka',
        'l_height' => 'Wzrost',
        'l_weight' => 'Waga',
        'l_meas' => 'Wymiary',
        'l_sample' => 'Rozmiar próbny',
    ],
    'ru_RU' => [
        'size_title' => 'Таблица размеров юбки hezi',
        'fabric' => 'Ткань: полиэстер. Юбка с принтом и плиссе, вышитый пояс, эластичная спинка. Лёгкая и струящаяся, с подкладкой против просвечивания.',
        'comfort' => 'Крой: свободный. На ощупь: мягкая. Эластичность: нет. Толщина: средняя.',
        'unit' => 'Единица: сантиметры. Подол — это обхват. Ручной замер может отличаться на 1–3 см.',
        'th_size' => 'Размер',
        'th_len' => 'Длина юбки (без пояса)',
        'th_band' => 'Пояс',
        'th_bust' => 'Макс. обхват груди',
        'th_hem' => 'Подол',
        'try_title' => 'Примерка',
        'l_model' => 'Модель',
        'l_height' => 'Рост',
        'l_weight' => 'Вес',
        'l_meas' => 'Мерки',
        'l_sample' => 'Примерный размер',
    ],
    'uk_UA' => [
        'size_title' => 'Таблиця розмірів спідниці hezi',
        'fabric' => 'Тканина: поліестер. Спідниця з принтом і плісе, вишитий пояс, еластична спинка. Легка й струменяста, з підкладкою проти просвічування.',
        'comfort' => 'Крій: вільний. На дотик: м’яка. Еластичність: немає. Товщина: середня.',
        'unit' => 'Одиниця: сантиметри. Поділ — це обхват. Ручний замір може відрізнятися на 1–3 см.',
        'th_size' => 'Розмір',
        'th_len' => 'Довжина спідниці (без пояса)',
        'th_band' => 'Пояс',
        'th_bust' => 'Макс. обхват грудей',
        'th_hem' => 'Поділ',
        'try_title' => 'Примірка',
        'l_model' => 'Модель',
        'l_height' => 'Зріст',
        'l_weight' => 'Вага',
        'l_meas' => 'Мірки',
        'l_sample' => 'Примірний розмір',
    ],
    'bg_BG' => [
        'size_title' => 'Таблица с размери на пола hezi',
        'fabric' => 'Плат: полиестер. Щампована и плисирана пола, бродиран колан, еластичен гръб. Лека и течаща, с подплата против прозиране.',
        'comfort' => 'Крой: свободен. На пипане: мека. Еластичност: няма. Дебелина: средна.',
        'unit' => 'Единица: сантиметри. Подгъвът е обиколката. Ръчното мерене може да се различава с 1–3 см.',
        'th_size' => 'Размер',
        'th_len' => 'Дължина на полата (без колан)',
        'th_band' => 'Колан',
        'th_bust' => 'Макс. обиколка на гърдите',
        'th_hem' => 'Подгъв',
        'try_title' => 'Пробване',
        'l_model' => 'Модел',
        'l_height' => 'Ръст',
        'l_weight' => 'Тегло',
        'l_meas' => 'Мерки',
        'l_sample' => 'Пробен размер',
    ],
    'el_GR' => [
        'size_title' => 'Πίνακας μεγεθών φούστας hezi',
        'fabric' => 'Ύφασμα: πολυεστέρας. Τυπωμένη και πλισέ φούστα, κεντημένη ζώνη, ελαστική πλάτη. Ελαφριά και ρευστή, με αντιδιαφανή φόδρα.',
        'comfort' => 'Γραμμή: άνετη. Αφή: μαλακή. Ελαστικότητα: καμία. Πάχος: μέτριο.',
        'unit' => 'Μονάδα: εκατοστά. Το στρίφωμα είναι η περίμετρος. Η χειροκίνητη μέτρηση μπορεί να αποκλίνει κατά 1–3 cm.',
        'th_size' => 'Μέγεθος',
        'th_len' => 'Μήκος φούστας (χωρίς ζώνη)',
        'th_band' => 'Ζώνη',
        'th_bust' => 'Μέγ. στήθος',
        'th_hem' => 'Στρίφωμα',
        'try_title' => 'Δοκιμή',
        'l_model' => 'Μοντέλο',
        'l_height' => 'Ύψος',
        'l_weight' => 'Βάρος',
        'l_meas' => 'Μετρήσεις',
        'l_sample' => 'Δοκιμαστικό μέγεθος',
    ],
    'cs_CZ' => [
        'size_title' => 'Tabulka velikostí sukně hezi',
        'fabric' => 'Látka: polyester. Potištěná a plisé sukně, vyšívaný pás, elastická záda. Lehká a splývavá, s podšívkou proti prosvítání.',
        'comfort' => 'Střih: volný. Omak: měkký. Elasticita: žádná. Tloušťka: střední.',
        'unit' => 'Jednotka: centimetry. Lem je obvod. Ruční měření se může lišit o 1–3 cm.',
        'th_size' => 'Velikost',
        'th_len' => 'Délka sukně (bez pásu)',
        'th_band' => 'Pás',
        'th_bust' => 'Max. poprsí',
        'th_hem' => 'Lem',
        'try_title' => 'Vyzkoušení',
        'l_model' => 'Modelka',
        'l_height' => 'Výška',
        'l_weight' => 'Váha',
        'l_meas' => 'Míry',
        'l_sample' => 'Zkušební velikost',
    ],
    'sk_SK' => [
        'size_title' => 'Tabuľka veľkostí sukne hezi',
        'fabric' => 'Látka: polyester. Potlačená a plisé sukňa, vyšívaný pás, elastický chrbát. Ľahká a splývavá, s podšívkou proti prežiareniu.',
        'comfort' => 'Strih: voľný. Omhmat: mäkký. Elasticita: žiadna. Hrúbka: stredná.',
        'unit' => 'Jednotka: centimetre. Lem je obvod. Ručné meranie sa môže líšiť o 1–3 cm.',
        'th_size' => 'Veľkosť',
        'th_len' => 'Dĺžka sukne (bez pásu)',
        'th_band' => 'Pás',
        'th_bust' => 'Max. poprsie',
        'th_hem' => 'Lem',
        'try_title' => 'Vyskúšanie',
        'l_model' => 'Modelka',
        'l_height' => 'Výška',
        'l_weight' => 'Váha',
        'l_meas' => 'Miery',
        'l_sample' => 'Skúšobná veľkosť',
    ],
    'sl_SI' => [
        'size_title' => 'Tabela velikosti krila hezi',
        'fabric' => 'Blago: poliester. Potiskano in plisirano krilo, vezen pas, elastičen hrbet. Lahko in tekoče, s podlogo proti prosojnosti.',
        'comfort' => 'Kroj: ohlapen. Otíp: mehak. Elastičnost: nobena. Debelina: srednja.',
        'unit' => 'Enota: centimetri. Rob je obseg. Ročno merjenje se lahko razlikuje za 1–3 cm.',
        'th_size' => 'Velikost',
        'th_len' => 'Dolžina krila (brez pasa)',
        'th_band' => 'Pas',
        'th_bust' => 'Maks. prsni obseg',
        'th_hem' => 'Rob',
        'try_title' => 'Pomerjanje',
        'l_model' => 'Model',
        'l_height' => 'Višina',
        'l_weight' => 'Teža',
        'l_meas' => 'Mere',
        'l_sample' => 'Poskusna velikost',
    ],
    'hr_HR' => [
        'size_title' => 'Tablica veličina suknje hezi',
        'fabric' => 'Tkanina: poliester. Bedrukana i plisirana suknja, vezeni pojas, elastična leđa. Laka i tečna, s podstavom protiv prozirnosti.',
        'comfort' => 'Kroj: labav. Na dodir: mekan. Elastičnost: nema. Debljina: srednja.',
        'unit' => 'Jedinica: centimetri. Rub je opseg. Ručno mjerenje može odstupati 1–3 cm.',
        'th_size' => 'Veličina',
        'th_len' => 'Duljina suknje (bez pojasa)',
        'th_band' => 'Pojas',
        'th_bust' => 'Maks. opseg prsa',
        'th_hem' => 'Rub',
        'try_title' => 'Probavanje',
        'l_model' => 'Model',
        'l_height' => 'Visina',
        'l_weight' => 'Težina',
        'l_meas' => 'Mjere',
        'l_sample' => 'Probna veličina',
    ],
    'hu_HU' => [
        'size_title' => 'Hezi szoknya mérettáblázat',
        'fabric' => 'Anyag: poliészter. Nyomott és pliszé szoknya, hímzett derékpánt, elasztikus hát. Könnyű és folyó, átlátszást gátló béléssel.',
        'comfort' => 'Szabás: laza. Tapintás: puha. Rugalmasság: nincs. Vastagság: közepes.',
        'unit' => 'Egység: centiméter. A szegély a kerület. Kézi mérés 1–3 cm-t eltérhet.',
        'th_size' => 'Méret',
        'th_len' => 'Szoknyahossz (pánt nélkül)',
        'th_band' => 'Derékpánt',
        'th_bust' => 'Max. mellbőség',
        'th_hem' => 'Szegély',
        'try_title' => 'Próba',
        'l_model' => 'Modell',
        'l_height' => 'Magasság',
        'l_weight' => 'Súly',
        'l_meas' => 'Méretek',
        'l_sample' => 'Próbaméret',
    ],
    'ro_RO' => [
        'size_title' => 'Tabel mărimi fustă hezi',
        'fabric' => 'Țesătură: poliester. Fustă imprimată și plisată, brâu brodat, spate elastic. Ușoară și fluidă, cu căptușeală anti-transparență.',
        'comfort' => 'Croială: lejeră. La atingere: moale. Elasticitate: deloc. Grosime: medie.',
        'unit' => 'Unitate: centimetri. Tivul este circumferința. Măsurarea manuală poate varia cu 1–3 cm.',
        'th_size' => 'Mărime',
        'th_len' => 'Lungime fustă (fără brâu)',
        'th_band' => 'Brâu',
        'th_bust' => 'Bust max.',
        'th_hem' => 'Tiv',
        'try_title' => 'Probă',
        'l_model' => 'Model',
        'l_height' => 'Înălțime',
        'l_weight' => 'Greutate',
        'l_meas' => 'Măsuri',
        'l_sample' => 'Mărime de probă',
    ],
    'tr_TR' => [
        'size_title' => 'Hezi etek beden tablosu',
        'fabric' => 'Kumaş: polyester. Baskılı ve pileli etek, nakışlı bel bandı, esnek sırt. Hafif ve akıcı, şeffaflık önleyici astarlı.',
        'comfort' => 'Kalıp: bol. Dokunuş: yumuşak. Esneklik: yok. Kalınlık: orta.',
        'unit' => 'Birim: santimetre. Etek ucu çevredir. Elle ölçüm 1–3 cm sapabilir.',
        'th_size' => 'Beden',
        'th_len' => 'Etek boyu (bel bandsız)',
        'th_band' => 'Bel bandı',
        'th_bust' => 'Maks. göğüs',
        'th_hem' => 'Etek ucu',
        'try_title' => 'Deneme',
        'l_model' => 'Manken',
        'l_height' => 'Boy',
        'l_weight' => 'Kilo',
        'l_meas' => 'Ölçüler',
        'l_sample' => 'Denenen beden',
    ],
    'sv_SE' => [
        'size_title' => 'Storlekstabell hezi-kjol',
        'fabric' => 'Tyg: polyester. Tryckt och plisserad kjol, broderat midjeband, elastisk rygg. Lätt och flödande, med anti-genomskinligt foder.',
        'comfort' => 'Passform: lös. Känsla: mjuk. Elasticitet: ingen. Tjocklek: medium.',
        'unit' => 'Enhet: centimeter. Fållen är omkretsen. Handmått kan variera 1–3 cm.',
        'th_size' => 'Storlek',
        'th_len' => 'Kjollängd (utan band)',
        'th_band' => 'Midjeband',
        'th_bust' => 'Max byst',
        'th_hem' => 'Fåll',
        'try_title' => 'Provning',
        'l_model' => 'Modell',
        'l_height' => 'Längd',
        'l_weight' => 'Vikt',
        'l_meas' => 'Mått',
        'l_sample' => 'Provstorlek',
    ],
    'nb_NO' => [
        'size_title' => 'Størrelsestabell hezi-skjørt',
        'fabric' => 'Stoff: polyester. Trykt og plissert skjørt, brodert livbånd, elastisk rygg. Lett og flytende, med anti-gjennomskinnelig fôr.',
        'comfort' => 'Passform: løs. Hånd: myk. Strekk: ingen. Tykkelse: middels.',
        'unit' => 'Enhet: centimeter. Falen er omkretsen. Håndmål kan variere 1–3 cm.',
        'th_size' => 'Størrelse',
        'th_len' => 'Skjørtlengde (uten bånd)',
        'th_band' => 'Livbånd',
        'th_bust' => 'Maks brystvidde',
        'th_hem' => 'Fal',
        'try_title' => 'Prøving',
        'l_model' => 'Modell',
        'l_height' => 'Høyde',
        'l_weight' => 'Vekt',
        'l_meas' => 'Mål',
        'l_sample' => 'Prøvestørrelse',
    ],
    'da_DK' => [
        'size_title' => 'Størrelsestabel hezi-nederdel',
        'fabric' => 'Stof: polyester. Trykt og plisseret nederdel, broderet linning, elastisk ryg. Let og flydende, med anti-gennemsigtigt foer.',
        'comfort' => 'Pasform: løs. Hånd: blød. Stræk: ingen. Tykkelse: medium.',
        'unit' => 'Enhed: centimeter. Sømkanten er omkredsen. Håndmål kan variere 1–3 cm.',
        'th_size' => 'Størrelse',
        'th_len' => 'Nederdelslængde (uden linning)',
        'th_band' => 'Linning',
        'th_bust' => 'Maks brystvidde',
        'th_hem' => 'Sømkant',
        'try_title' => 'Prøvning',
        'l_model' => 'Model',
        'l_height' => 'Højde',
        'l_weight' => 'Vægt',
        'l_meas' => 'Mål',
        'l_sample' => 'Prøvestørrelse',
    ],
    'fi_FI' => [
        'size_title' => 'Hezi-hameen kokotaulukko',
        'fabric' => 'Kangas: polyesteri. Painettu ja pliseerattu hame, kirjailtu vyötärö, joustava selkä. Kevyt ja valuva, läpikuultavuutta estävällä vuorella.',
        'comfort' => 'Malli: väljä. Tuntuma: pehmeä. Jousto: ei. Paksuus: keskitaso.',
        'unit' => 'Yksikkö: senttimetrit. Helma on ympärysmitta. Käsivarainen mittaus voi poiketa 1–3 cm.',
        'th_size' => 'Koko',
        'th_len' => 'Hameen pituus (ilman vyötäröä)',
        'th_band' => 'Vyötärö',
        'th_bust' => 'Maks. rinnanympärys',
        'th_hem' => 'Helma',
        'try_title' => 'Sovitus',
        'l_model' => 'Malli',
        'l_height' => 'Pituus',
        'l_weight' => 'Paino',
        'l_meas' => 'Mitat',
        'l_sample' => 'Sovituskoko',
    ],
    'et_EE' => [
        'size_title' => 'Hezi seeliku suurustabel',
        'fabric' => 'Kangas: polüester. Trükitud ja plisseeritud seelik, tikitud vöö, elastne selg. Kerge ja voolav, läbipaistvust takistava voodriga.',
        'comfort' => 'Lõige: lai. Tunne: pehme. Elastsus: puudub. Paksus: keskmine.',
        'unit' => 'Ühik: sentimeetrid. Ääris on ümbermõõt. Käsitsi mõõtmine võib erineda 1–3 cm.',
        'th_size' => 'Suurus',
        'th_len' => 'Seeliku pikkus (ilma vööta)',
        'th_band' => 'Vöö',
        'th_bust' => 'Maks. rinnaümbermõõt',
        'th_hem' => 'Ääris',
        'try_title' => 'Proovimine',
        'l_model' => 'Modell',
        'l_height' => 'Pikkus',
        'l_weight' => 'Kaal',
        'l_meas' => 'Mõõdud',
        'l_sample' => 'Proovisuurus',
    ],
    'lv_LV' => [
        'size_title' => 'Hezi svārku izmēru tabula',
        'fabric' => 'Audums: poliesters. Apdrukāti un plisēti svārki, izšūta josta, elastīga aizmugure. Viegli un plūstoši, ar pretcaurspīdīgumu oderi.',
        'comfort' => 'Piegriezums: brīvs. Ožas: mīksts. Elastība: nav. Biezums: vidējs.',
        'unit' => 'Vienība: centimetri. Apmale ir apkārtmērs. Manuālais mērījums var atšķirties par 1–3 cm.',
        'th_size' => 'Izmērs',
        'th_len' => 'Svārku garums (bez jostas)',
        'th_band' => 'Josta',
        'th_bust' => 'Maks. krūšu apkārtmērs',
        'th_hem' => 'Apmale',
        'try_title' => 'Piemērīšana',
        'l_model' => 'Modele',
        'l_height' => 'Augums',
        'l_weight' => 'Svars',
        'l_meas' => 'Mēri',
        'l_sample' => 'Piemērais izmērs',
    ],
    'lt_LT' => [
        'size_title' => 'Hezi sijono dydžių lentelė',
        'fabric' => 'Audinys: poliesteris. Spausdintas ir plisuotas sijonas, siuvinėta juosta, elastinga nugara. Lengvas ir tekantis, su peršviečiamumą stabdančiu pamušalu.',
        'comfort' => 'Kirpimas: laisvas. Pojūtis: minkštas. Elastingumas: nėra. Storis: vidutinis.',
        'unit' => 'Vienetas: centimetrai. Apačia – apimtis. Rankinis matavimas gali skirtis 1–3 cm.',
        'th_size' => 'Dydis',
        'th_len' => 'Sijono ilgis (be juostos)',
        'th_band' => 'Juosta',
        'th_bust' => 'Maks. krūtinės apimtis',
        'th_hem' => 'Apačia',
        'try_title' => 'Pasimatavimas',
        'l_model' => 'Modelis',
        'l_height' => 'Ūgis',
        'l_weight' => 'Svoris',
        'l_meas' => 'Matmenys',
        'l_sample' => 'Bandymo dydis',
    ],
    'is_IS' => [
        'size_title' => 'Stærðartafla hezi-pils',
        'fabric' => 'Efni: pólýester. Prentað og plisserað pilsi, útsaumað mittisband, teygjanleg bakhlið. Létt og flæðandi, með fóðri gegn gegnsæi.',
        'comfort' => 'Snið: laust. Tilfinning: mjúkt. Teygja: engin. Þykkt: miðlungs.',
        'unit' => 'Eining: sentimetrar. Faldurinn er ummál. Handmæling getur vikið um 1–3 cm.',
        'th_size' => 'Stærð',
        'th_len' => 'Pilslengd (án bands)',
        'th_band' => 'Mittisband',
        'th_bust' => 'Hámarks brjóstmál',
        'th_hem' => 'Faldur',
        'try_title' => 'Prófun',
        'l_model' => 'Fyrirsæta',
        'l_height' => 'Hæð',
        'l_weight' => 'Þyngd',
        'l_meas' => 'Mál',
        'l_sample' => 'Prófunarstærð',
    ],
    'mt_MT' => [
        'size_title' => 'Tabella tad-daqsijiet tad-dublett hezi',
        'fabric' => 'Drapp: poliester. Dublett stampat u plissettat, ċinturin irrakmat, dahar elastiku. Ħafif u fluwidu, b’inforra kontra t-trasparenza.',
        'comfort' => 'Qatgħa: wiesgħa. Mess: artab. Elastiċità: xejn. Ħxuna: medja.',
        'unit' => 'Unità: ċentimetri. Il-keffa hija ċ-ċirkonferenza. Kejl bl-idejn jista’ jvarja 1–3 cm.',
        'th_size' => 'Daqs',
        'th_len' => 'Tul tad-dublett (mingħajr ċinturin)',
        'th_band' => 'Ċinturin',
        'th_bust' => 'Sider massimu',
        'th_hem' => 'Keffa',
        'try_title' => 'Prova',
        'l_model' => 'Mudell',
        'l_height' => 'Għoli',
        'l_weight' => 'Piż',
        'l_meas' => 'Miżuri',
        'l_sample' => 'Daqs ippruvat',
    ],
    'ga_IE' => [
        'size_title' => 'Cairt mhéideanna sciorta hezi',
        'fabric' => 'Fabraic: poileistear. Sciorta clóbhuailte agus pleatáilte, banda bróidnithe, droim leaisteach. Éadrom agus sreabhach, le líneáil frith-thrédhearcach.',
        'comfort' => 'Oiriúint: scaoilte. Lámh: bog. Síneadh: níl. Tiús: meánach.',
        'unit' => 'Aonad: ceintiméadar. Is imlíne an imeall. Féadfaidh tomhas láimhe athrú 1–3 cm.',
        'th_size' => 'Méid',
        'th_len' => 'Fad sciorta (gan banda)',
        'th_band' => 'Banda',
        'th_bust' => 'Uasmhéid cliabhraigh',
        'th_hem' => 'Imeall',
        'try_title' => 'Triail',
        'l_model' => 'Samhail',
        'l_height' => 'Airde',
        'l_weight' => 'Meáchan',
        'l_meas' => 'Tomhais',
        'l_sample' => 'Méid thriail',
    ],
    'ca_ES' => [
        'size_title' => 'Taula de talles de la faldilla hezi',
        'fabric' => 'Teixit: polièster. Faldilla impresa i plissada, cintura brodada, esquena elàstica. Lleugera i fluida, amb folre antitrasparència.',
        'comfort' => 'Tall: ample. Tacte: suau. Elasticitat: cap. Gruix: mitjà.',
        'unit' => 'Unitat: centímetres. L’vorera és la circumferència. La mesura manual pot variar 1–3 cm.',
        'th_size' => 'Talla',
        'th_len' => 'Llargària de faldilla (sense cintura)',
        'th_band' => 'Cintura',
        'th_bust' => 'Pit màx.',
        'th_hem' => 'Vorera',
        'try_title' => 'Prova',
        'l_model' => 'Model',
        'l_height' => 'Alçada',
        'l_weight' => 'Pes',
        'l_meas' => 'Mesures',
        'l_sample' => 'Talla de prova',
    ],
];

$tableBody = <<<'HTML'
<tbody><tr><td>XS</td><td>106</td><td>19</td><td>80</td><td>406</td></tr><tr><td>S</td><td>109</td><td>19</td><td>84</td><td>410</td></tr><tr><td>M</td><td>112</td><td>19</td><td>88</td><td>414</td></tr><tr><td>L</td><td>115</td><td>19</td><td>92</td><td>418</td></tr><tr><td>XL</td><td>118</td><td>19</td><td>96</td><td>422</td></tr></tbody>
HTML;

$fetchDesc = static function (PDO $pdo, int $productId, string $locale): string {
    if ($locale === '') {
        $stmt = $pdo->prepare(
            "SELECT description FROM w_weline_product_local WHERE product_id=? AND (local_code='' OR local_code IS NULL) LIMIT 1"
        );
        $stmt->execute([$productId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT description FROM w_weline_product_local WHERE product_id=? AND local_code=? LIMIT 1'
        );
        $stmt->execute([$productId, $locale]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? (string)($row['description'] ?? '') : '';
};

$fetchName = static function (PDO $pdo, int $productId, string $locale): string {
    $stmt = $pdo->prepare(
        'SELECT name FROM w_weline_product_local WHERE product_id=? AND local_code=? LIMIT 1'
    );
    $stmt->execute([$productId, $locale]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? trim((string)($row['name'] ?? '')) : '';
};

$buildSizeBlock = static function (array $p) use ($h, $tableBody): string {
    return '<div class="weline-detail-text weline-detail-text--size-chart" data-weline-detail-text="measurement-chart">'
        . '<h3>' . $h($p['size_title']) . '</h3>'
        . '<p>' . $h($p['fabric']) . '</p>'
        . '<p>' . $h($p['comfort']) . '</p>'
        . '<p>' . $h($p['unit']) . '</p>'
        . '<table><thead><tr>'
        . '<th>' . $h($p['th_size']) . '</th>'
        . '<th>' . $h($p['th_len']) . '</th>'
        . '<th>' . $h($p['th_band']) . '</th>'
        . '<th>' . $h($p['th_bust']) . '</th>'
        . '<th>' . $h($p['th_hem']) . '</th>'
        . '</tr></thead>' . $tableBody . '</table></div>';
};

$buildTryBlock = static function (array $p) use ($h): string {
    return '<div class="weline-detail-text weline-detail-text--product-info" data-weline-detail-text="product-info">'
        . '<h3>' . $h($p['try_title']) . '</h3>'
        . '<table><tbody>'
        . '<tr><th>' . $h($p['l_model']) . '</th><td>Lizi</td></tr>'
        . '<tr><th>' . $h($p['l_height']) . '</th><td>161 cm</td></tr>'
        . '<tr><th>' . $h($p['l_weight']) . '</th><td>40 kg</td></tr>'
        . '<tr><th>' . $h($p['l_meas']) . '</th><td>72 / 63 / 80</td></tr>'
        . '<tr><th>' . $h($p['l_sample']) . '</th><td>M</td></tr>'
        . '</tbody></table></div>';
};

$remediate = static function (string $html, string $locale, string $cleanName, array $pack) use ($buildSizeBlock, $buildTryBlock): array {
    $changes = [];
    $out = $html;

    // 1) Replace mangled "... Wear" display title with clean product name
    if ($cleanName !== '' && preg_match('/<h3>([^<]* Wear)<\/h3>/', $out, $m)) {
        $bad = $m[1];
        $count = 0;
        $out = str_replace($bad, $cleanName, $out, $count);
        if ($count > 0) {
            $changes[] = 'wear_title×' . $count;
        }
    }

    // 2) Size chart block
    $sizeHtml = $buildSizeBlock($pack);
    $replaced = preg_replace(
        '/<div class="weline-detail-text weline-detail-text--size-chart" data-weline-detail-text="measurement-chart">[\s\S]*?<\/div>/',
        $sizeHtml,
        $out,
        1,
        $n
    );
    if (is_string($replaced) && $n > 0) {
        $out = $replaced;
        $changes[] = 'size_chart';
    }

    // 3) Try-on / product-info block (first occurrence is try-on table)
    $tryHtml = $buildTryBlock($pack);
    $replaced = preg_replace(
        '/<div class="weline-detail-text weline-detail-text--product-info" data-weline-detail-text="product-info">[\s\S]*?<\/div>/',
        $tryHtml,
        $out,
        1,
        $n
    );
    if (is_string($replaced) && $n > 0) {
        $out = $replaced;
        $changes[] = 'try_on';
    }

    // Fail gate leftovers
    $plain = html_entity_decode(strip_tags($out), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    foreach (['Wear', 'Try-on', 'elastic back', 'anti-exposure lining', 'Fit: loose', 'Hand: soft', 'Stretch: none', 'waistband', 'Measurements 72', 'Weight 40', 'Sample '] as $sig) {
        if (str_contains($plain, $sig) || (str_contains($plain, ' chart') && preg_match('/\b\w+ chart\b/u', $plain))) {
            // "chart" only fail if visible "... chart" pattern remains
        }
    }
    $fail = [];
    if (preg_match('/\bWear\b/', $plain)) {
        $fail[] = 'Wear';
    }
    if (preg_match('/\bTry-on\b/', $plain)) {
        $fail[] = 'Try-on';
    }
    if (preg_match('/elastic back|anti-exposure lining|Fit:\s*loose|Hand:\s*soft|Stretch:\s*none|Hand measurement may vary/i', $plain)) {
        $fail[] = 'size_en_dump';
    }
    if (preg_match('/\b\w+ chart\b/u', $plain)) {
        $fail[] = 'chart_title';
    }
    if ($fail !== []) {
        $changes[] = 'FAIL:' . implode(',', $fail);
    }

    return ['html' => $out, 'changes' => $changes];
};

/** @var AttributeValueRepository $attributes */
$attributes = $om->get(AttributeValueRepository::class);
$writes = [];

foreach (array_keys($packs) as $locale) {
    if (!isset($localePlan[$locale]) && $locale !== '') {
        echo "SKIP {$locale}: not in enabled plan" . PHP_EOL;
        continue;
    }
    $html = $fetchDesc($pdo, $productId, $locale);
    if ($html === '') {
        echo "SKIP {$locale}: empty description" . PHP_EOL;
        continue;
    }
    $cleanName = $fetchName($pdo, $productId, $locale);
    $result = $remediate($html, $locale, $cleanName, $packs[$locale]);
    $label = $locale;
    if ($result['changes'] === []) {
        echo "  {$label}: OK (no change)" . PHP_EOL;
        continue;
    }
    $hasFail = false;
    foreach ($result['changes'] as $c) {
        if (str_starts_with($c, 'FAIL:')) {
            $hasFail = true;
        }
    }
    echo '  ' . $label . ': FIX ' . implode(',', $result['changes'])
        . ' len ' . strlen($html) . '→' . strlen($result['html']) . PHP_EOL;
    if ($hasFail) {
        echo "  {$label}: FAIL gate — not queued" . PHP_EOL;
        continue;
    }
    $writes[] = [
        'locale' => $locale,
        'html' => $result['html'],
        'changes' => $result['changes'],
    ];
}

echo PHP_EOL . 'Pending writes: ' . count($writes) . PHP_EOL;

if (!$apply) {
    echo "Dry-run only. Pass --apply to write." . PHP_EOL;
    exit(0);
}

foreach ($writes as $w) {
    $locale = (string)$w['locale'];
    $html = (string)$w['html'];
    LocalDescription::upsertQuiet($productId, $locale, [
        LocalDescription::schema_fields_DESCRIPTION => $html,
    ]);
    $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', $locale, $html, true);
    echo "WROTE {$locale} len=" . strlen($html) . PHP_EOL;
}

$om->get(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
    $websiteId,
    'product_192_slot3_i18n_leaks',
    ['product_ids' => [$productId]],
);
$om->get(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('product_192_slot3_i18n_leaks');

echo "DONE applied=" . count($writes) . PHP_EOL;
