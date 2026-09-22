<?php

declare(strict_types=1);

/**
 * One-shot builder: assemble faq-hub-all-locales.v1.php from catalog + compliance hub_0 + extra translations.
 * Run: php app/code/Weline/Faq/scripts/_build_faq_hub_all_locales.php
 */

require dirname(__DIR__, 4) . '/bootstrap.php';

use Weline\Faq\Service\FaqSeedCopyCatalog;
use Weline\Framework\App\Env;

$ref = new ReflectionClass(FaqSeedCopyCatalog::class);
$m = $ref->getMethod('hubDefinitions');
$m->setAccessible(true);
/** @var array<string, list<array{q:string,a:string}>> $catalog */
$catalog = $m->invoke(null);

/** @var array{hub_0: array<string, array{question:string, answer:string}>} $compliance */
$compliance = require __DIR__ . '/data/faq-compliance-returns-hub0.v1.php';
$hub0 = $compliance['hub_0'];

$db = (array)(Env::getInstance()->getConfig('db')['master'] ?? []);
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $db['hostname'] ?? '127.0.0.1', $db['hostport'] ?? '5432', $db['database'] ?? ''),
    (string)($db['username'] ?? ''),
    (string)($db['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$locales = array_values(array_filter(
    array_map('strval', $pdo->query(
        'SELECT language_code FROM w_weline_websites_website_language WHERE website_id = 0 ORDER BY language_code'
    )->fetchAll(PDO::FETCH_COLUMN) ?: []),
    static fn(string $c): bool => $c !== ''
));

/** Hubs 1–7 for locales not fully maintained in FaqSeedCopyCatalog. Index 1..7. */
$extra = require __DIR__ . '/data/_faq_hub_extra_locales.v1.php';

/** Near-clone maps: base catalog locale → variant locales (hubs 1–7). */
$clones = [
    'en_US' => ['en_GB'],
    'es_ES' => ['es_MX'],
    'fr_FR' => ['fr_CA'],
    'pt_BR' => ['pt_PT'],
];

$variantTweaks = [
    'es_MX' => static function (array $item): array {
        $item['a'] = str_replace('días laborables', 'días hábiles', $item['a']);
        $item['q'] = str_replace('laborables', 'hábiles', $item['q']);

        return $item;
    },
    'fr_CA' => static function (array $item): array {
        $item['a'] = str_replace('jours ouvrés', 'jours ouvrables', $item['a']);
        $item['q'] = str_replace('ouvrés', 'ouvrables', $item['q']);

        return $item;
    },
    'pt_PT' => static function (array $item): array {
        $mapQ = [
            'Como rastrear minha remessa?' => 'Como seguir a minha encomenda?',
            'Como o frete é calculado? Há frete grátis?' => 'Como são calculados os portes? Há portes grátis?',
            'Quais métodos de pagamento são aceitos?' => 'Que métodos de pagamento são aceites?',
            'Posso alterar ou cancelar meu pedido?' => 'Posso alterar ou cancelar a minha encomenda?',
            'Como meus dados pessoais são protegidos?' => 'Como são protegidos os meus dados pessoais?',
        ];
        $mapA = [
            'Meus pedidos' => 'As minhas encomendas',
            'frete' => 'portes',
            'Frete' => 'Portes',
            'aceitos' => 'aceites',
        ];
        $item['q'] = $mapQ[$item['q']] ?? $item['q'];
        foreach ($mapA as $from => $to) {
            $item['a'] = str_replace($from, $to, $item['a']);
        }

        return $item;
    },
];

$pack = [];
foreach ($locales as $locale) {
    $items = [];
    for ($i = 0; $i < 8; $i++) {
        if ($i === 0) {
            if (!isset($hub0[$locale])) {
                fwrite(STDERR, "missing hub_0 for {$locale}\n");
                exit(1);
            }
            $items[] = [
                'q' => $hub0[$locale]['question'],
                'a' => $hub0[$locale]['answer'],
            ];
            continue;
        }

        // Catalog maintained
        if (isset($catalog[$locale][$i])) {
            $items[] = ['q' => $catalog[$locale][$i]['q'], 'a' => $catalog[$locale][$i]['a']];
            continue;
        }

        // Explicit extra
        if (isset($extra[$locale][$i])) {
            $items[] = $extra[$locale][$i];
            continue;
        }

        // Clone from base
        $found = null;
        foreach ($clones as $base => $variants) {
            if (in_array($locale, $variants, true) && isset($catalog[$base][$i])) {
                $found = ['q' => $catalog[$base][$i]['q'], 'a' => $catalog[$base][$i]['a']];
                if (isset($variantTweaks[$locale]) && is_callable($variantTweaks[$locale])) {
                    $found = $variantTweaks[$locale]($found);
                }
                break;
            }
        }
        if ($found !== null) {
            $items[] = $found;
            continue;
        }

        fwrite(STDERR, "missing hub_{$i} for {$locale}\n");
        exit(1);
    }
    $pack[$locale] = $items;
}

// Sanity: no non-zh answer still Chinese source of hub questions
$zhAnswers = array_column($catalog['zh_Hans_CN'], 'a');
foreach ($pack as $locale => $items) {
    if ($locale === 'zh_Hans_CN') {
        continue;
    }
    foreach ($items as $idx => $item) {
        if (in_array($item['a'], $zhAnswers, true)) {
            fwrite(STDERR, "Chinese source answer leaked into {$locale} hub_{$idx}\n");
            exit(1);
        }
        if (str_contains($item['a'], 'How soon will my order ship') || str_contains($item['q'], 'How soon will my order ship')) {
            if (!str_starts_with($locale, 'en_')) {
                fwrite(STDERR, "English ship question leaked into {$locale}\n");
                exit(1);
            }
        }
    }
}

$outPath = __DIR__ . '/data/faq-hub-all-locales.v1.php';
$export = var_export($pack, true);
$php = <<<PHP
<?php

declare(strict_types=1);

/**
 * Default-website site hub FAQ copy (hub_0..hub_7) for every language_code.
 * hub_0 answers align with faq-compliance-returns-hub0.v1.php.
 *
 * @return array<string, list<array{q:string, a:string}>>
 */
return {$export};

PHP;

file_put_contents($outPath, $php);
echo "wrote {$outPath} locales=" . count($pack) . " hubs=8\n";
echo "ru_RU hub_0 q=" . $pack['ru_RU'][0]['q'] . "\n";
echo "ru_RU hub_1 q=" . $pack['ru_RU'][1]['q'] . "\n";
