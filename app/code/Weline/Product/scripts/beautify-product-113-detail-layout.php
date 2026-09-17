<?php

declare(strict_types=1);

/**
 * #113 长安忆 · §3.2‑B 诗侧栏删拼版 + 多样杂志排版强制重做
 *
 * 卖点表：
 * | 卖点 | 证据 | 原型 |
 * | 齐胸襦裙气韵 | 全身/半身实拍 | fullbleed / pair |
 * | 绣花胸襦 | 近景绣片 | macro |
 * | 团扇春园 | 裁切净实拍 | poem_aside |
 * | 唐制层次 | 领袖裙幅可读 | stack_caption / feature |
 *
 * 原型：editorial_lead → fullbleed → poem_aside → pair → stack_caption → macro
 *       → quiet → checklist → feature → wash → spec → size → close
 * detail-02 整张拼版不入 HTML；右栏裁切为净实拍。
 *
 * php app/code/Weline/Product/scripts/beautify-product-113-detail-layout.php --apply
 */

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Model\Product\LocalDescription;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\DetailDescriptionTextifier;
use Weline\Product\Service\ProductStorefrontCacheInvalidator;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Storage\Api\Data\StorageDiskCode;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'website:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));
$productId = 113;
$root = dirname(__DIR__, 5);
$mediaRoot = $root . '/pub/media';
$stamp = date('Ymd-His');
$workDir = '/tmp/p113-poem-crop-' . $stamp;
@mkdir($workDir, 0775, true);

$srcRel = 'catalog/hanfu/1688/factory-yueya/731150010223/detail-02-89008dbf3174.jpg';
$srcAbs = $mediaRoot . '/' . $srcRel;
if (!is_file($srcAbs)) {
    fwrite(STDERR, "Missing source collage: {$srcAbs}\n");
    exit(2);
}
$bak = $workDir . '/detail-02-source.bak.jpg';
if (!copy($srcAbs, $bak)) {
    fwrite(STDERR, "Backup failed\n");
    exit(2);
}
echo "backup={$bak}\n";

$im = @imagecreatefromjpeg($srcAbs);
if ($im === false) {
    fwrite(STDERR, "Cannot read collage JPEG\n");
    exit(2);
}
$sw = imagesx($im);
$sh = imagesy($im);
// Left ~28% cream poem column → keep right photo.
$x0 = (int)round($sw * 0.28);
$cw = max(1, $sw - $x0);
$crop = imagecreatetruecolor($cw, $sh);
imagecopy($crop, $im, 0, 0, $x0, 0, $cw, $sh);
imagedestroy($im);
$cropPath = $workDir . '/detail-02-photo-only.jpg';
imagejpeg($crop, $cropPath, 92);
$cw = imagesx($crop);
$ch = imagesy($crop);
imagedestroy($crop);
echo "crop={$cropPath} {$cw}x{$ch}\n";

/** @var FileAssetLibraryInterface $library */
$library = ObjectManager::getInstance(FileAssetLibraryInterface::class);
$access = new FileAccessContext(ScopeIdentity::global(), 'zh_Hans_CN', null, ['catalog_maintenance'], 'metadata_edit');
$disk = StorageDiskCode::BUILTIN_LOCAL_MEDIA;
$objectKey = 'catalog/hanfu/1688/factory-yueya/731150010223/detail-02-photo-only-' . $stamp . '.jpg';
$destAbs = $mediaRoot . '/' . $objectKey;
if (is_file($destAbs)) {
    fwrite(STDERR, "Dest exists, refuse overwrite: {$destAbs}\n");
    exit(2);
}

$poemAssetId = '';
if ($apply) {
    $stream = fopen($cropPath, 'rb');
    if ($stream === false) {
        fwrite(STDERR, "Cannot open crop stream\n");
        exit(2);
    }
    try {
        $desc = $library->upload(
            $disk,
            $objectKey,
            $stream,
            basename($objectKey),
            'image/jpeg',
            'zh_Hans_CN',
            $access,
            [
                'display_name' => '长安忆 · 春园净实拍',
                'default_alt' => '长安忆 · 春园团扇实拍（诗侧栏裁切）',
                'description' => '从 detail-02 文图拼版裁切右侧净实拍，供 poem-aside 楼层使用。',
            ],
            FileAssetLibraryInterface::VISIBILITY_PUBLIC,
            [
                'remediation' => [
                    'contract' => 'product.detail.poem_sidebar.crop.v1',
                    'source_object_key' => $srcRel,
                    'source_asset_id' => 'b6bded97-4a5e-4d3e-8da5-d8def7e61eed',
                    'cropped_at' => gmdate('c'),
                    'backup' => $bak,
                ],
            ],
            $cw,
            $ch,
        );
    } finally {
        fclose($stream);
    }
    $poemAssetId = strtolower((string)($desc['asset_id'] ?? $desc['id'] ?? ''));
    if ($poemAssetId === '') {
        fwrite(STDERR, "Upload returned empty asset_id: " . json_encode($desc) . "\n");
        exit(2);
    }
    echo "uploaded asset={$poemAssetId} key={$objectKey}\n";
} else {
    $poemAssetId = '00000000-0000-0000-0000-000000000113';
    echo "dry-run: skip upload, placeholder asset id\n";
}

$A = [
    'hero' => ['id' => '5ece56a9-f2d9-44b9-9a88-8e5ce47afd69', 'w' => 800, 'h' => 800],
    'look02' => ['id' => '776ee3a6-3e90-43a6-bfbe-6afaa7170853', 'w' => 800, 'h' => 800],
    'look04' => ['id' => '0bd6ac26-440e-4b7b-9e49-1a9763f77273', 'w' => 790, 'h' => 975],
    'look06' => ['id' => 'acbda2ee-bd6a-4a50-9029-64f2798f8958', 'w' => 790, 'h' => 904],
    'macro' => ['id' => 'fa117ea2-57e6-4f69-b076-0dbeab3dfdc5', 'w' => 724, 'h' => 1023],
    'flat' => ['id' => '4849fec3-3d59-4ed2-8058-1c049f802045', 'w' => 800, 'h' => 800],
    'main' => ['id' => '5241e6f7-b8cb-4a73-95be-c1a5b0b4b2cb', 'w' => 800, 'h' => 800],
    'poem' => ['id' => $poemAssetId, 'w' => $cw, 'h' => $ch],
];

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$img = static function (array $a, string $alt) use ($h): string {
    return '<img src="asset://' . $h((string)$a['id']) . '" alt="' . $h($alt)
        . '" loading="lazy" decoding="async" width="' . (int)$a['w'] . '" height="' . (int)$a['h'] . '">';
};

$feature = static function (string $mediaHtml, string $copyHtml, bool $reverse = false, string $mod = ''): string {
    $cls = 'weline-detail-feature' . ($reverse ? ' weline-detail-feature--reverse' : '')
        . ($mod !== '' ? ' ' . $mod : '');

    return '<div class="' . $cls . '">'
        . '<div class="weline-detail-feature__media">' . $mediaHtml . '</div>'
        . '<div class="weline-detail-feature__copy">' . $copyHtml . '</div>'
        . '</div>';
};

$figureStack = static function (array $imgs, string $mod = '') use ($h): string {
    $rows = '';
    $n = count($imgs);
    for ($i = 0; $i < $n; $i += 2) {
        if ($i + 1 < $n) {
            $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--pair">'
                . '<div class="weline-detail-figure">' . $imgs[$i] . '</div>'
                . '<div class="weline-detail-figure">' . $imgs[$i + 1] . '</div>'
                . '</div>';
        } else {
            $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--solo">'
                . '<div class="weline-detail-figure">' . $imgs[$i] . '</div>'
                . '</div>';
        }
    }
    $cls = 'weline-detail-figure-stack' . ($mod !== '' ? ' ' . $mod : '');

    return '<div class="' . $h($cls) . '">' . $rows . '</div>';
};

$copy = [
    'zh_Hans_CN' => [
        'intro_title' => '长安忆',
        'intro_body' => '唐制齐胸襦裙：绣花胸襦、浅色外衫与褶裙相叠。春园团扇实拍可读衣长与层次；形制以图为证。',
        'inspire_title' => '设计心源',
        'inspire_lines' => ['衣上春色，心记长安。', '扇底花影，步生微风。'],
        'inspire_note' => '取「长安忆」点题——仅为形制与春日气韵，非货盘说辞。',
        'poem_title' => '相思',
        'poem_lines' => ['入我相思门，知我相思苦。', '长相思兮长相忆，短相思兮无穷极。'],
        'look_title' => '通身气韵',
        'look_body' => '全身与半身实拍交叉铺陈，裙幅、袖袂与领缘层次可读。',
        'macro_title' => '细处可辨',
        'macro_body' => '近景可见绣线、褶影与面料质感；以图为证，不编造参数。',
        'sleeve_title' => '袖袂与扇面',
        'sleeve_body' => '广袖轻透，团扇绣花与胸襦纹样相呼；走动时褶影随步起伏。',
        'quiet_line' => '花在枝头，衣在春风。',
        'checklist_title' => '衣袂可记',
        'checklist' => ['唐制齐胸襦裙层次可读', '绣花胸襦与团扇相映', '春园实拍衣长可见', '以图为准，不编造参数'],
        'wash_title' => '护衣小笺',
        'wash_lines' => ['建议手洗，分色洗涤，不可漂白。', '悬挂晾干，避暴晒；低温熨烫，垫布为佳。'],
        'info_brand' => '悦雅霓裳',
        'info_name' => '长安忆',
        'info_color' => '如图',
        'info_style' => '唐制',
        'info_size' => '见规格轴',
        'info_fabric' => '精选面料（如图）',
        'info_parts' => '齐胸、襦裙',
        'info_title' => '形制一览',
        'info_basics' => '基本',
        'info_comfort' => '穿着感受',
        'label_brand' => '品牌',
        'label_name' => '品名',
        'label_color' => '颜色',
        'label_style' => '制式',
        'label_size' => '尺码',
        'label_fabric' => '面料',
        'label_parts' => '部件',
        'size_title' => '尺码参照',
        'size_body' => '请按规格轴、胸围与身高挑选；手工测量或有一至三厘米出入。',
        'original_title' => '原创心迹',
        'original_body' => '敬请珍惜衣冠、尊重匠心；设计以实拍为准。',
        'close_caption' => '长安忆 · 唐制齐胸',
        'alt_hero' => '长安忆 · 套装',
        'alt_look' => '长安忆 · 着装',
        'alt_macro' => '长安忆 · 细部',
        'alt_poem' => '长安忆 · 春园',
        'c_thick' => '厚度',
        'c_thick_opts' => ['薄', '适中', '厚'],
        'c_thick_sel' => '适中',
        'c_fit' => '版型',
        'c_fit_opts' => ['修身', '合身', '宽松'],
        'c_fit_sel' => '合身',
        'c_soft' => '手感',
        'c_soft_opts' => ['偏软', '适中', '偏硬'],
        'c_soft_sel' => '适中',
        'c_stretch' => '弹性',
        'c_stretch_opts' => ['无弹', '微弹', '高弹'],
        'c_stretch_sel' => '无弹',
    ],
];

$localeOverrides = [
    'en_US' => [
        'intro_title' => 'Chang’an Memory',
        'intro_body' => 'Tang-style high-waist ruqun: embroidered bodice, sheer outer layer, and pleated skirt. Garden fan shots keep hem and layers readable—evidence from photos only.',
        'inspire_title' => 'Design wellspring',
        'inspire_lines' => ['Spring on the robe; Chang’an in mind.', 'Flower-shadow on the fan; a soft breeze at each step.'],
        'inspire_note' => 'Named “Chang’an Memory”—cut and spring air only, not marketplace pitch.',
        'poem_title' => 'Longing',
        'look_title' => 'Full look',
        'look_body' => 'Full and mid shots stack so hem, sleeves, and collar layers stay readable.',
        'macro_title' => 'Close looking',
        'macro_body' => 'Near views show stitch, pleat, and fabric—evidence only, no invented specs.',
        'sleeve_title' => 'Sleeves & fan',
        'sleeve_body' => 'Sheer wide sleeves meet an embroidered fan that echoes the bodice motifs; pleats move as she walks.',
        'quiet_line' => 'Blossoms on the branch; cloth in the spring wind.',
        'checklist_title' => 'Worth noting',
        'checklist' => ['Tang high-waist ruqun layers readable', 'Embroidered bodice with matching fan', 'Garden shots show garment length', 'Trust the photos—no invented specs'],
        'wash_title' => 'Care',
        'wash_lines' => ['Hand wash separately; no bleach.', 'Hang dry, avoid hard sun; low heat with a press cloth.'],
        'info_brand' => 'Yueya Nishang',
        'info_name' => 'Chang’an Memory',
        'info_color' => 'As shown',
        'info_style' => 'Tang-style',
        'info_size' => 'See variant axis',
        'info_fabric' => 'Selected fabric (as shown)',
        'info_parts' => 'High-waist, ruqun',
        'info_title' => 'At a glance',
        'info_basics' => 'Basics',
        'info_comfort' => 'Hand feel',
        'label_brand' => 'Brand',
        'label_name' => 'Name',
        'label_color' => 'Color',
        'label_style' => 'Style',
        'label_size' => 'Size',
        'label_fabric' => 'Fabric',
        'label_parts' => 'Parts',
        'size_title' => 'Size guide',
        'size_body' => 'Choose by the size axis, bust, and height; hand measure may vary by 1–3 cm.',
        'original_title' => 'Original craft',
        'original_body' => 'Honor the craft; design follows the photos.',
        'close_caption' => 'Chang’an Memory · Tang ruqun',
        'alt_hero' => 'Chang’an Memory · set',
        'alt_look' => 'Chang’an Memory · worn',
        'alt_macro' => 'Chang’an Memory · detail',
        'alt_poem' => 'Chang’an Memory · garden',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Thin', 'Medium', 'Thick'],
        'c_thick_sel' => 'Medium',
        'c_fit' => 'Fit',
        'c_fit_opts' => ['Slim', 'Regular', 'Relaxed'],
        'c_fit_sel' => 'Regular',
        'c_soft' => 'Hand',
        'c_soft_opts' => ['Soft', 'Medium', 'Firm'],
        'c_soft_sel' => 'Medium',
        'c_stretch' => 'Stretch',
        'c_stretch_opts' => ['None', 'Slight', 'High'],
        'c_stretch_sel' => 'None',
    ],
    'es_ES' => [
        'intro_title' => 'Memoria de Chang’an',
        'intro_body' => 'Ruqun de talle alto estilo Tang: corpiño bordado, capa ligera y falda plisada. Las fotos con abanico muestran largo y capas.',
        'inspire_title' => 'Fuente del diseño',
        'inspire_lines' => ['Primavera en la ropa; Chang’an en la mente.', 'Sombra de flor en el abanico; brisa al caminar.'],
        'inspire_note' => '«Memoria de Chang’an» nombra el corte y el aire primaveral, no un discurso de mercado.',
        'poem_title' => 'Añoranza',
        'look_title' => 'Silueta completa',
        'look_body' => 'Planos enteros y medios para leer bajo, mangas y cuello.',
        'macro_title' => 'De cerca',
        'macro_body' => 'Primeros planos de hilo, pliegue y tela—sin inventar datos.',
        'sleeve_title' => 'Mangas y abanico',
        'sleeve_body' => 'Mangas anchas transparentes y abanico bordado que dialoga con el corpiño.',
        'quiet_line' => 'Flor en la rama; tela en el viento.',
        'checklist_title' => 'Para recordar',
        'checklist' => ['Capas de ruqun Tang legibles', 'Corpiño bordado y abanico', 'Largo visible en jardín', 'Confíe en las fotos'],
        'wash_title' => 'Cuidado',
        'wash_lines' => ['Lavar a mano por separado; sin lejía.', 'Colgar a la sombra; plancha baja con paño.'],
        'info_brand' => 'Yueya Nishang',
        'info_name' => 'Memoria de Chang’an',
        'info_color' => 'Según fotos',
        'info_style' => 'estilo Tang',
        'info_size' => 'Ver eje de variantes',
        'info_fabric' => 'Tejido seleccionado',
        'info_parts' => 'cintura alta, ruqun',
        'info_title' => 'De un vistazo',
        'info_basics' => 'Básico',
        'info_comfort' => 'Sensación',
        'label_brand' => 'Marca',
        'label_name' => 'Nombre',
        'label_color' => 'Color',
        'label_style' => 'Estilo',
        'label_size' => 'Talla',
        'label_fabric' => 'Tela',
        'label_parts' => 'Partes',
        'size_title' => 'Guía de tallas',
        'size_body' => 'Elija por el eje, busto y altura; puede haber 1–3 cm de diferencia.',
        'original_title' => 'Oficio original',
        'original_body' => 'Respete el oficio; el diseño sigue las fotos.',
        'close_caption' => 'Memoria de Chang’an · Tang',
        'alt_hero' => 'Memoria de Chang’an · set',
        'alt_look' => 'Memoria de Chang’an · look',
        'alt_macro' => 'Memoria de Chang’an · detalle',
        'alt_poem' => 'Memoria de Chang’an · jardín',
        'c_thick' => 'Grosor',
        'c_thick_opts' => ['Fino', 'Medio', 'Grueso'],
        'c_thick_sel' => 'Medio',
        'c_fit' => 'Corte',
        'c_fit_opts' => ['Ajustado', 'Regular', 'Holgado'],
        'c_fit_sel' => 'Regular',
        'c_soft' => 'Tacto',
        'c_soft_opts' => ['Suave', 'Medio', 'Firme'],
        'c_soft_sel' => 'Medio',
        'c_stretch' => 'Elasticidad',
        'c_stretch_opts' => ['Nula', 'Ligera', 'Alta'],
        'c_stretch_sel' => 'Nula',
    ],
    'fr_FR' => [
        'intro_title' => 'Mémoire de Chang’an',
        'intro_body' => 'Ruqun taille haute style Tang : corsage brodé, voile léger et jupe plissée. Les photos au éventail montrent longueur et couches.',
        'inspire_title' => 'Source du dessin',
        'inspire_lines' => ['Printemps sur le tissu ; Chang’an en mémoire.', 'Ombre de fleur sur l’éventail ; brise à chaque pas.'],
        'inspire_note' => '« Mémoire de Chang’an » nomme la coupe et l’air printanier, pas un discours de marché.',
        'poem_title' => 'Langueur',
        'look_title' => 'Silhouette',
        'look_body' => 'Plans entiers et moyens pour lire ourlet, manches et col.',
        'macro_title' => 'De près',
        'macro_body' => 'Gros plans de fil, pli et tissu—sans inventer de chiffres.',
        'sleeve_title' => 'Manches et éventail',
        'sleeve_body' => 'Manches amples transparentes et éventail brodé en écho au corsage.',
        'quiet_line' => 'Fleur sur la branche ; tissu dans le vent.',
        'checklist_title' => 'À retenir',
        'checklist' => ['Couches de ruqun Tang lisibles', 'Corsage brodé et éventail', 'Longueur visible au jardin', 'Faites confiance aux photos'],
        'wash_title' => 'Entretien',
        'wash_lines' => ['Lavage à la main séparément ; pas d’eau de Javel.', 'Sécher à l’ombre ; fer doux avec un linge.'],
        'info_brand' => 'Yueya Nishang',
        'info_name' => 'Mémoire de Chang’an',
        'info_color' => 'Selon photos',
        'info_style' => 'style Tang',
        'info_size' => 'Voir l’axe des variantes',
        'info_fabric' => 'Tissu sélectionné',
        'info_parts' => 'taille haute, ruqun',
        'info_title' => 'En un coup d’œil',
        'info_basics' => 'Base',
        'info_comfort' => 'Toucher',
        'label_brand' => 'Marque',
        'label_name' => 'Nom',
        'label_color' => 'Couleur',
        'label_style' => 'Style',
        'label_size' => 'Taille',
        'label_fabric' => 'Tissu',
        'label_parts' => 'Pièces',
        'size_title' => 'Guide des tailles',
        'size_body' => 'Choisissez selon l’axe, le buste et la taille ; écart possible de 1–3 cm.',
        'original_title' => 'Savoir-faire original',
        'original_body' => 'Honorez le métier ; le design suit les photos.',
        'close_caption' => 'Mémoire de Chang’an · Tang',
        'alt_hero' => 'Mémoire de Chang’an · set',
        'alt_look' => 'Mémoire de Chang’an · porté',
        'alt_macro' => 'Mémoire de Chang’an · détail',
        'alt_poem' => 'Mémoire de Chang’an · jardin',
        'c_thick' => 'Épaisseur',
        'c_thick_opts' => ['Fin', 'Moyen', 'Épais'],
        'c_thick_sel' => 'Moyen',
        'c_fit' => 'Coupe',
        'c_fit_opts' => ['Ajusté', 'Régulier', 'Ample'],
        'c_fit_sel' => 'Régulier',
        'c_soft' => 'Toucher',
        'c_soft_opts' => ['Doux', 'Moyen', 'Ferme'],
        'c_soft_sel' => 'Moyen',
        'c_stretch' => 'Élasticité',
        'c_stretch_opts' => ['Nulle', 'Légère', 'Forte'],
        'c_stretch_sel' => 'Nulle',
    ],
    'pt_BR' => [
        'intro_title' => 'Memória de Chang’an',
        'intro_body' => 'Ruqun de cintura alta estilo Tang: corpete bordado, capa leve e saia plissada. Fotos com leque mostram comprimento e camadas.',
        'inspire_title' => 'Fonte do desenho',
        'inspire_lines' => ['Primavera na roupa; Chang’an na mente.', 'Sombra de flor no leque; brisa a cada passo.'],
        'inspire_note' => '«Memória de Chang’an» nomeia o corte e o ar de primavera, não discurso de mercado.',
        'poem_title' => 'Saudade',
        'look_title' => 'Silhueta',
        'look_body' => 'Planos inteiros e médios para ler barra, mangas e gola.',
        'macro_title' => 'De perto',
        'macro_body' => 'Planos fechados de fio, prega e tecido—sem inventar números.',
        'sleeve_title' => 'Mangas e leque',
        'sleeve_body' => 'Mangas amplas transparentes e leque bordado em eco ao corpete.',
        'quiet_line' => 'Flor no galho; tecido no vento.',
        'checklist_title' => 'Para lembrar',
        'checklist' => ['Camadas de ruqun Tang legíveis', 'Corpete bordado e leque', 'Comprimento visível no jardim', 'Confie nas fotos'],
        'wash_title' => 'Cuidados',
        'wash_lines' => ['Lavar à mão separadamente; sem alvejante.', 'Secar à sombra; ferro baixo com pano.'],
        'info_brand' => 'Yueya Nishang',
        'info_name' => 'Memória de Chang’an',
        'info_color' => 'Conforme fotos',
        'info_style' => 'estilo Tang',
        'info_size' => 'Ver eixo de variantes',
        'info_fabric' => 'Tecido selecionado',
        'info_parts' => 'cintura alta, ruqun',
        'info_title' => 'À primeira vista',
        'info_basics' => 'Básico',
        'info_comfort' => 'Toque',
        'label_brand' => 'Marca',
        'label_name' => 'Nome',
        'label_color' => 'Cor',
        'label_style' => 'Estilo',
        'label_size' => 'Tamanho',
        'label_fabric' => 'Tecido',
        'label_parts' => 'Peças',
        'size_title' => 'Guia de tamanhos',
        'size_body' => 'Escolha pelo eixo, busto e altura; pode haver 1–3 cm de diferença.',
        'original_title' => 'Ofício original',
        'original_body' => 'Honre o ofício; o design segue as fotos.',
        'close_caption' => 'Memória de Chang’an · Tang',
        'alt_hero' => 'Memória de Chang’an · set',
        'alt_look' => 'Memória de Chang’an · look',
        'alt_macro' => 'Memória de Chang’an · detalhe',
        'alt_poem' => 'Memória de Chang’an · jardim',
        'c_thick' => 'Espessura',
        'c_thick_opts' => ['Fino', 'Médio', 'Grosso'],
        'c_thick_sel' => 'Médio',
        'c_fit' => 'Caimento',
        'c_fit_opts' => ['Ajustado', 'Regular', 'Folgado'],
        'c_fit_sel' => 'Regular',
        'c_soft' => 'Toque',
        'c_soft_opts' => ['Macio', 'Médio', 'Firme'],
        'c_soft_sel' => 'Médio',
        'c_stretch' => 'Elasticidade',
        'c_stretch_opts' => ['Nenhuma', 'Leve', 'Alta'],
        'c_stretch_sel' => 'Nenhuma',
    ],
    'id_ID' => [
        'intro_title' => 'Kenangan Chang’an',
        'intro_body' => 'Ruqun pinggang tinggi gaya Tang: korset sulam, lapisan tipis, dan rok lipit. Foto kipas menunjukkan panjang dan lapisan.',
        'inspire_title' => 'Sumber desain',
        'inspire_lines' => ['Musim semi di kain; Chang’an di hati.', 'Bayangan bunga di kipas; angin di setiap langkah.'],
        'inspire_note' => '«Kenangan Chang’an» menamai potongan dan udara musim semi, bukan bahasa pasar.',
        'poem_title' => 'Kerinduan',
        'look_title' => 'Tampilan penuh',
        'look_body' => 'Foto penuh dan setengah badan agar hem, lengan, dan kerah terbaca.',
        'macro_title' => 'Dari dekat',
        'macro_body' => 'Makro jahitan, lipatan, dan kain—tanpa mengarang angka.',
        'sleeve_title' => 'Lengan & kipas',
        'sleeve_body' => 'Lengan lebar transparan dan kipas sulam yang menyahut motif korset.',
        'quiet_line' => 'Bunga di ranting; kain di angin.',
        'checklist_title' => 'Perlu diingat',
        'checklist' => ['Lapisan ruqun Tang terbaca', 'Korset sulam dan kipas', 'Panjang terlihat di taman', 'Percayai foto'],
        'wash_title' => 'Perawatan',
        'wash_lines' => ['Cuci tangan terpisah; tanpa pemutih.', 'Jemur teduh; setrika rendah dengan kain.'],
        'info_brand' => 'Yueya Nishang',
        'info_name' => 'Kenangan Chang’an',
        'info_color' => 'Sesuai foto',
        'info_style' => 'gaya Tang',
        'info_size' => 'Lihat sumbu varian',
        'info_fabric' => 'Kain pilihan',
        'info_parts' => 'pinggang tinggi, ruqun',
        'info_title' => 'Sekilas',
        'info_basics' => 'Dasar',
        'info_comfort' => 'Sentuhan',
        'label_brand' => 'Merek',
        'label_name' => 'Nama',
        'label_color' => 'Warna',
        'label_style' => 'Gaya',
        'label_size' => 'Ukuran',
        'label_fabric' => 'Kain',
        'label_parts' => 'Bagian',
        'size_title' => 'Panduan ukuran',
        'size_body' => 'Pilih menurut sumbu, dada, dan tinggi; boleh beda 1–3 cm.',
        'original_title' => 'Kerajinan asli',
        'original_body' => 'Hormati kerajinan; desain mengikuti foto.',
        'close_caption' => 'Kenangan Chang’an · Tang',
        'alt_hero' => 'Kenangan Chang’an · set',
        'alt_look' => 'Kenangan Chang’an · dipakai',
        'alt_macro' => 'Kenangan Chang’an · detail',
        'alt_poem' => 'Kenangan Chang’an · taman',
        'c_thick' => 'Ketebalan',
        'c_thick_opts' => ['Tipis', 'Sedang', 'Tebal'],
        'c_thick_sel' => 'Sedang',
        'c_fit' => 'Potongan',
        'c_fit_opts' => ['Slim', 'Regular', 'Longgar'],
        'c_fit_sel' => 'Regular',
        'c_soft' => 'Sentuhan',
        'c_soft_opts' => ['Lembut', 'Sedang', 'Kaku'],
        'c_soft_sel' => 'Sedang',
        'c_stretch' => 'Elastisitas',
        'c_stretch_opts' => ['Tidak', 'Sedikit', 'Tinggi'],
        'c_stretch_sel' => 'Tidak',
    ],
    'ar_SA' => [
        'intro_title' => 'ذكرى تشانغآن',
        'intro_body' => 'روقون بخصر عالٍ بطراز تانغ: صدر مطرّز وطبقة شفافة وتنورة مطوية. لقطات المروحة تُظهر الطول والطبقات.',
        'inspire_title' => 'منبع التصميم',
        'inspire_lines' => ['ربيع على الثوب؛ تشانغآن في البال.', 'ظل زهرة على المروحة؛ نسيم مع كل خطوة.'],
        'inspire_note' => '«ذكرى تشانغآن» تسمي القصّة وهواء الربيع لا خطاب السوق.',
        'poem_title' => 'شوق',
        'look_title' => 'إطلالة كاملة',
        'look_body' => 'لقطات كاملة ومتوسطة لقراءة الذيل والأكمام والياقة.',
        'macro_title' => 'من قرب',
        'macro_body' => 'لقطات قريبة للخيط والطيات والقماش—دون اختراع أرقام.',
        'sleeve_title' => 'الأكمام والمروحة',
        'sleeve_body' => 'أكمام واسعة شفافة ومروحة مطرّزة تردّد نقش الصدر.',
        'quiet_line' => 'زهرة على الغصن؛ قماش في الريح.',
        'checklist_title' => 'للتذكّر',
        'checklist' => ['طبقات روقون تانغ مقروءة', 'صدر مطرّز ومروحة', 'الطول يظهر في الحديقة', 'ثق بالصور'],
        'wash_title' => 'العناية',
        'wash_lines' => ['اغسل يدويًا منفصلًا؛ بلا مبيّض.', 'علّق في الظل؛ كوي خفيف بقماش.'],
        'info_brand' => 'يويا نيشانغ',
        'info_name' => 'ذكرى تشانغآن',
        'info_color' => 'حسب الصور',
        'info_style' => 'طراز تانغ',
        'info_size' => 'انظر محور المقاسات',
        'info_fabric' => 'قماش مختار',
        'info_parts' => 'خصر عالٍ، روقون',
        'info_title' => 'لمحة',
        'info_basics' => 'أساسي',
        'info_comfort' => 'الملمس',
        'label_brand' => 'العلامة',
        'label_name' => 'الاسم',
        'label_color' => 'اللون',
        'label_style' => 'الطراز',
        'label_size' => 'المقاس',
        'label_fabric' => 'القماش',
        'label_parts' => 'الأجزاء',
        'size_title' => 'دليل المقاسات',
        'size_body' => 'اختر حسب المحور والصدر والطول؛ قد يختلف ١–٣ سم.',
        'original_title' => 'حرفة أصيلة',
        'original_body' => 'أكرم الحرفة؛ التصميم يتبع الصور.',
        'close_caption' => 'ذكرى تشانغآن · تانغ',
        'alt_hero' => 'ذكرى تشانغآن · طقم',
        'alt_look' => 'ذكرى تشانغآن · ارتداء',
        'alt_macro' => 'ذكرى تشانغآن · تفصيل',
        'alt_poem' => 'ذكرى تشانغآن · حديقة',
        'c_thick' => 'السماكة',
        'c_thick_opts' => ['خفيف', 'متوسط', 'سميك'],
        'c_thick_sel' => 'متوسط',
        'c_fit' => 'القصة',
        'c_fit_opts' => ['ضيق', 'عادي', 'واسع'],
        'c_fit_sel' => 'عادي',
        'c_soft' => 'الملمس',
        'c_soft_opts' => ['ناعم', 'متوسط', 'قاسي'],
        'c_soft_sel' => 'متوسط',
        'c_stretch' => 'المرونة',
        'c_stretch_opts' => ['لا', 'خفيف', 'عالٍ'],
        'c_stretch_sel' => 'لا',
    ],
    'bn_BD' => [
        'intro_title' => 'চাংআন স্মৃতি',
        'intro_body' => 'তাং শৈলী উচ্চ কোমর রুচুন: সূচিকর্ম বডিস, পাতলা স্তর ও প্লিটেড স্কার্ট। পাখার ছবিতে দৈর্ঘ্য ও স্তর পড়া যায়।',
        'inspire_title' => 'ডিজাইনের উৎস',
        'inspire_lines' => ['পোশাকে বসন্ত; মনে চাংআন।', 'পাখায় ফুলের ছায়া; প্রতি পায়ে হাওয়া।'],
        'inspire_note' => 'নাম «চাংআন স্মৃতি»—কাট ও বসন্তের আবহ, বাজারের বুলি নয়।',
        'poem_title' => 'প্রণয়বেদনা',
        'look_title' => 'পুরো সাজ',
        'look_body' => 'পুরো ও মাঝারি শট সাজিয়ে হেম, হাতা ও কলারের স্তর পড়া যায়।',
        'macro_title' => 'কাছ থেকে',
        'macro_body' => 'কাছের দৃশ্যে সেলাই, ভাঁজ ও কাপড়ের অনুভূতি—শুধু প্রমাণ, কাল্পনিক স্পেক নয়।',
        'sleeve_title' => 'হাতা ও পাখা',
        'sleeve_body' => 'প্রশস্ত স্বচ্ছ হাতা ও সূচিকর্ম পাখা বডিসের মোটিফের প্রতিধ্বনি।',
        'quiet_line' => 'ডালে ফুল; বাতাসে কাপড়।',
        'checklist_title' => 'মনে রাখার মতো',
        'checklist' => ['তাং রুচুনের স্তর পড়া যায়', 'সূচিকর্ম বডিস ও পাখা', 'বাগানে দৈর্ঘ্য দেখা যায়', 'ছবিতে বিশ্বাস রাখুন'],
        'wash_title' => 'যত্ন',
        'wash_lines' => ['আলাদা হাত ধোয়া; ব্লিচ নয়।', 'ঝুলিয়ে শুকান; নিম্ন তাপে কাপড় দিয়ে ইস্ত্রি।'],
        'info_brand' => 'ইউইয়া নিশাং',
        'info_name' => 'চাংআন স্মৃতি',
        'info_color' => 'ছবি অনুসারে',
        'info_style' => 'তাং শৈলী',
        'info_size' => 'ভ্যারিয়েন্ট অক্ষ দেখুন',
        'info_fabric' => 'নির্বাচিত কাপড়',
        'info_parts' => 'উচ্চ কোমর, রুচুন',
        'info_title' => 'এক নজরে',
        'info_basics' => 'মূল তথ্য',
        'info_comfort' => 'পরা অনুভূতি',
        'label_brand' => 'ব্র্যান্ড',
        'label_name' => 'নাম',
        'label_color' => 'রঙ',
        'label_style' => 'শৈলী',
        'label_size' => 'সাইজ',
        'label_fabric' => 'কাপড়',
        'label_parts' => 'অংশ',
        'size_title' => 'সাইজ নির্দেশিকা',
        'size_body' => 'সাইজ অক্ষ, বুক ও উচ্চতা অনুযায়ী বেছে নিন; হাতের মাপে ১–৩ সেমি ফারাক হতে পারে।',
        'original_title' => 'মূল কারুকাজ',
        'original_body' => 'কারুকে সম্মান করুন; নকশা আমাদের ছবি অনুসারে।',
        'close_caption' => 'চাংআন স্মৃতি · তাং',
        'alt_hero' => 'চাংআন স্মৃতি · সেট',
        'alt_look' => 'চাংআন স্মৃতি · পরা',
        'alt_macro' => 'চাংআন স্মৃতি · বিস্তারিত',
        'alt_poem' => 'চাংআন স্মৃতি · বাগান',
        'c_thick' => 'পুরুত্ব',
        'c_thick_opts' => ['পাতলা', 'মাঝারি', 'মোটা'],
        'c_thick_sel' => 'মাঝারি',
        'c_fit' => 'ফিট',
        'c_fit_opts' => ['স্লিম', 'রেগুলার', 'আরাম'],
        'c_fit_sel' => 'রেগুলার',
        'c_soft' => 'স্পর্শ',
        'c_soft_opts' => ['নরম', 'মাঝারি', 'শক্ত'],
        'c_soft_sel' => 'মাঝারি',
        'c_stretch' => 'ইলাস্টিসিটি',
        'c_stretch_opts' => ['নেই', 'সামান্য', 'বেশি'],
        'c_stretch_sel' => 'নেই',
    ],
    'hi_IN' => [
        'intro_title' => 'चांगआन स्मृति',
        'intro_body' => 'तांग शैली ऊँची कमर रुचुन: कढ़ाई बॉडीस, हल्की परत और प्लीटेड स्कर्ट। पंखे वाले शॉट में लंबाई और परतें पढ़ी जा सकती हैं।',
        'inspire_title' => 'डिज़ाइन स्रोत',
        'inspire_lines' => ['वस्त्र पर वसंत; मन में चांगआन।', 'पंखा पर फूल की छाया; हर कदम पर हवा।'],
        'inspire_note' => 'नाम «चांगआन स्मृति»—कट और वसंत भाव; बाज़ार की भाषा नहीं।',
        'poem_title' => 'विरह',
        'look_title' => 'पूरा लुक',
        'look_body' => 'पूर्ण और मध्य शॉट से हेम, आस्तीन और कॉलर की परतें पढ़ी जा सकती हैं।',
        'macro_title' => 'नज़दीक से',
        'macro_body' => 'नज़दीकी दृश्य में टांका, प्लीट और कपड़ा—केवल साक्ष्य, गढ़े हुए आंकड़े नहीं।',
        'sleeve_title' => 'आस्तीन और पंखा',
        'sleeve_body' => 'चौड़ी पारदर्शी आस्तीन और कढ़ाई पंखा बॉडीस मोटिफ से गूँजता है।',
        'quiet_line' => 'डाली पर फूल; हवा में कपड़ा।',
        'checklist_title' => 'याद रखने योग्य',
        'checklist' => ['तांग रुचुन परतें पढ़ी जा सकती हैं', 'कढ़ाई बॉडीस और पंखा', 'बगीचे में लंबाई दिखती है', 'फ़ोटो पर भरोसा करें'],
        'wash_title' => 'देखभाल',
        'wash_lines' => ['अलग से हाथ धोएँ; ब्लीच नहीं।', 'लटकाकर सुखाएँ; कम ताप पर कपड़े से इस्त्री।'],
        'info_brand' => 'युएया निशांग',
        'info_name' => 'चांगआन स्मृति',
        'info_color' => 'फ़ोटो के अनुसार',
        'info_style' => 'तांग शैली',
        'info_size' => 'वैरिएंट अक्ष देखें',
        'info_fabric' => 'चयनित कपड़ा',
        'info_parts' => 'ऊँची कमर, रुचुन',
        'info_title' => 'एक नज़र में',
        'info_basics' => 'मूल',
        'info_comfort' => 'स्पर्श',
        'label_brand' => 'ब्रांड',
        'label_name' => 'नाम',
        'label_color' => 'रंग',
        'label_style' => 'शैली',
        'label_size' => 'साइज़',
        'label_fabric' => 'कपड़ा',
        'label_parts' => 'भाग',
        'size_title' => 'साइज़ मार्गदर्शिका',
        'size_body' => 'अक्ष, छाती और ऊँचाई से चुनें; हाथ माप में १–३ सेमी अंतर हो सकता है।',
        'original_title' => 'मूल शिल्प',
        'original_body' => 'शिल्प का सम्मान करें; डिज़ाइन फ़ोटो के अनुसार।',
        'close_caption' => 'चांगआन स्मृति · तांग',
        'alt_hero' => 'चांगआन स्मृति · सेट',
        'alt_look' => 'चांगआन स्मृति · पहना',
        'alt_macro' => 'चांगआन स्मृति · विवरण',
        'alt_poem' => 'चांगआन स्मृति · बगीचा',
        'c_thick' => 'मोटाई',
        'c_thick_opts' => ['पतला', 'मध्यम', 'मोटा'],
        'c_thick_sel' => 'मध्यम',
        'c_fit' => 'फिट',
        'c_fit_opts' => ['स्लिम', 'नियमित', 'ढीला'],
        'c_fit_sel' => 'नियमित',
        'c_soft' => 'स्पर्श',
        'c_soft_opts' => ['नरम', 'मध्यम', 'सख्त'],
        'c_soft_sel' => 'मध्यम',
        'c_stretch' => 'लचीलापन',
        'c_stretch_opts' => ['नहीं', 'हल्का', 'अधिक'],
        'c_stretch_sel' => 'नहीं',
    ],
    'ur_PK' => [
        'intro_title' => 'چانگ آن یاد',
        'intro_body' => 'تانگ طرز اونچی کمر روچون: کڑھائی باڈیس، ہلکی تہ اور پلیٹڈ اسکرٹ۔ پنکھے والی تصاویر میں لمبائی اور تہیں پڑھی جا سکتی ہیں۔',
        'inspire_title' => 'ڈیزائن کا منبع',
        'inspire_lines' => ['کپڑے پر بہار؛ دل میں چانگ آن۔', 'پنکھے پر پھول کا سایہ؛ ہر قدم پر ہوا۔'],
        'inspire_note' => 'نام «چانگ آن یاد»—کٹ اور بہار کا حال؛ بازار کی زبان نہیں۔',
        'poem_title' => 'اشتیاق',
        'look_title' => 'پورا لک',
        'look_body' => 'مکمل اور درمیانی شاٹس سے ہیَم، آستین اور کالر کی تہیں پڑھی جا سکتی ہیں۔',
        'macro_title' => 'قریب سے',
        'macro_body' => 'قریبی نظارے میں ٹانکا، پلیٹ اور کپڑا—صرف ثبوت، بناوٹی پیمائش نہیں۔',
        'sleeve_title' => 'آستین اور پنکھا',
        'sleeve_body' => 'چوڑی شفاف آستین اور کڑھائی پنکھا باڈیس نقوش کی گونج ہے۔',
        'quiet_line' => 'شاخ پر پھول؛ ہوا میں کپڑا۔',
        'checklist_title' => 'یاد رکھیں',
        'checklist' => ['تانگ روچون کی تہیں پڑھنے کے قابل', 'کڑھائی باڈیس اور پنکھا', 'باغ میں لمبائی نظر آتی ہے', 'تصاویر پر بھروسہ کریں'],
        'wash_title' => 'دیکھ بھال',
        'wash_lines' => ['علیحدہ ہاتھ دھوئیں؛ بلیچ نہیں۔', 'لٹکا کر خشک کریں؛ کم حرارت پر کپڑے سے استری۔'],
        'info_brand' => 'یویا نیشانگ',
        'info_name' => 'چانگ آن یاد',
        'info_color' => 'تصویر کے مطابق',
        'info_style' => 'تانگ طرز',
        'info_size' => 'ویرینٹ محور دیکھیں',
        'info_fabric' => 'منتخب کپڑا',
        'info_parts' => 'اونچی کمر، روچون',
        'info_title' => 'ایک نظر میں',
        'info_basics' => 'بنیادی',
        'info_comfort' => 'لمس',
        'label_brand' => 'برانڈ',
        'label_name' => 'نام',
        'label_color' => 'رنگ',
        'label_style' => 'طرز',
        'label_size' => 'سائز',
        'label_fabric' => 'کپڑا',
        'label_parts' => 'حصے',
        'size_title' => 'سائز رہنما',
        'size_body' => 'محور، سینہ اور قد سے چنیں؛ ہاتھ کی پیمائش میں ۱–۳ سینٹی میٹر فرق ہو سکتا ہے۔',
        'original_title' => 'اصل دستکاری',
        'original_body' => 'دستکاری کا احترام کریں؛ ڈیزائن تصاویر کے مطابق۔',
        'close_caption' => 'چانگ آن یاد · تانگ',
        'alt_hero' => 'چانگ آن یاد · سیٹ',
        'alt_look' => 'چانگ آن یاد · پہنا',
        'alt_macro' => 'چانگ آن یاد · تفصیل',
        'alt_poem' => 'چانگ آن یاد · باغ',
        'c_thick' => 'موٹائی',
        'c_thick_opts' => ['پتلا', 'درمیانہ', 'موٹا'],
        'c_thick_sel' => 'درمیانہ',
        'c_fit' => 'فٹ',
        'c_fit_opts' => ['سلِم', 'عام', 'ڈھیلا'],
        'c_fit_sel' => 'عام',
        'c_soft' => 'لمس',
        'c_soft_opts' => ['نرم', 'درمیانہ', 'سخت'],
        'c_soft_sel' => 'درمیانہ',
        'c_stretch' => 'کھینچاؤ',
        'c_stretch_opts' => ['نہیں', 'ہلکا', 'زیادہ'],
        'c_stretch_sel' => 'نہیں',
    ],
];

foreach ($localeOverrides as $loc => $over) {
    $copy[$loc] = array_replace($copy['zh_Hans_CN'], $over);
    // Keep classical poem lines in Chinese for all locales (专名/古典引用).
    $copy[$loc]['poem_lines'] = $copy['zh_Hans_CN']['poem_lines'];
}

$assemble = static function (array $t) use ($A, $img, $feature, $figureStack, $h): string {
    $intro = '<div class="weline-detail-prose weline-detail-prose--lead"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';

    $hero = $figureStack([
        $img($A['hero'], (string)$t['alt_hero']),
    ], 'weline-detail-figure-stack--fullbleed weline-detail-orient--squareish');

    $inspire = '<div class="weline-detail-prose weline-detail-prose--verse"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ((array)$t['inspire_lines'] as $line) {
        $inspire .= '<p>' . $h((string)$line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    $poemCopy = '<div class="weline-detail-prose weline-detail-prose--verse-vertical" lang="zh-Hans">'
        . '<h3 class="weline-detail-prose__eyebrow">' . $h((string)$t['poem_title']) . '</h3>';
    foreach ((array)$t['poem_lines'] as $line) {
        $poemCopy .= '<p>' . $h((string)$line) . '</p>';
    }
    $poemCopy .= '</div>';
    $poemAside = '<div class="weline-detail-feature weline-detail-feature--poem-aside">'
        . '<div class="weline-detail-feature__copy">' . $poemCopy . '</div>'
        . '<div class="weline-detail-feature__media">' . $img($A['poem'], (string)$t['alt_poem']) . '</div>'
        . '</div>';

    $pair = $figureStack([
        $img($A['look02'], (string)$t['alt_look'] . ' 1'),
        $img($A['look04'], (string)$t['alt_look'] . ' 2'),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
        . $h((string)$t['look_body']) . '</p></div>';

    $stack = $figureStack([
        $img($A['look06'], (string)$t['alt_look'] . ' 3'),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['sleeve_title']) . '</h3><p>'
        . $h((string)$t['sleeve_body']) . '</p></div>';

    $macro = $figureStack([
        $img($A['macro'], (string)$t['alt_macro']),
        $img($A['flat'], (string)$t['alt_look'] . ' 4'),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['macro_title']) . '</h3><p>'
        . $h((string)$t['macro_body']) . '</p></div>';

    $quiet = '<div class="weline-detail-quiet-spacer" aria-hidden="true"></div>';
    $check = '<div class="weline-detail-prose weline-detail-prose--checklist"><h3>' . $h((string)$t['checklist_title']) . '</h3><ul>';
    foreach ((array)$t['checklist'] as $item) {
        $check .= '<li>' . $h((string)$item) . '</li>';
    }
    $check .= '</ul></div>';

    $feat = $feature(
        $img($A['main'], (string)$t['alt_look'] . ' 5'),
        '<h3>' . $h((string)$t['look_title']) . '</h3><p>' . $h((string)$t['look_body']) . '</p>',
        true,
    );

    $quietLine = '<div class="weline-detail-prose weline-detail-prose--quiet"><p>' . $h((string)$t['quiet_line']) . '</p></div>';
    $wash = '<div class="weline-detail-prose weline-detail-prose--checklist"><h3>' . $h((string)$t['wash_title']) . '</h3><ul>';
    foreach ((array)$t['wash_lines'] as $line) {
        $wash .= '<li>' . $h((string)$line) . '</li>';
    }
    $wash .= '</ul></div>';

    $info = DetailDescriptionTextifier::buildProductInfoPanelZh(
        [
            (string)$t['label_brand'] => (string)$t['info_brand'],
            (string)$t['label_name'] => (string)$t['info_name'],
            (string)$t['label_color'] => (string)$t['info_color'],
            (string)$t['label_style'] => (string)$t['info_style'],
            (string)$t['label_size'] => (string)$t['info_size'],
            (string)$t['label_fabric'] => (string)$t['info_fabric'],
            (string)$t['label_parts'] => (string)$t['info_parts'],
        ],
        [
            ['label' => (string)$t['c_thick'], 'options' => (array)$t['c_thick_opts'], 'selected' => (string)$t['c_thick_sel']],
            ['label' => (string)$t['c_fit'], 'options' => (array)$t['c_fit_opts'], 'selected' => (string)$t['c_fit_sel']],
            ['label' => (string)$t['c_soft'], 'options' => (array)$t['c_soft_opts'], 'selected' => (string)$t['c_soft_sel']],
            ['label' => (string)$t['c_stretch'], 'options' => (array)$t['c_stretch_opts'], 'selected' => (string)$t['c_stretch_sel']],
        ],
        (string)$t['info_title'],
        (string)$t['info_basics'],
        (string)$t['info_comfort'],
    );
    $size = '<div class="weline-detail-prose"><h3>' . $h((string)$t['size_title']) . '</h3><p>'
        . $h((string)$t['size_body']) . '</p></div>';
    $original = '<div class="weline-detail-prose"><h3>' . $h((string)$t['original_title']) . '</h3><p>'
        . $h((string)$t['original_body']) . '</p></div>';
    $close = '<div class="weline-detail-prose weline-detail-prose--quiet"><p class="weline-detail-feature__note">'
        . $h((string)$t['close_caption']) . '</p></div>';

    return '<div data-weline-product-description="1688" data-weds="xq">'
        . '<!--weds:xq--><span data-weds="xq" hidden aria-hidden="true"></span>'
        . $intro . $hero . $inspire . $poemAside . $pair . $stack . $macro
        . $quiet . $check . $feat . $quietLine . $wash . $info . $size . $original . $close
        . '</div>';
};

$localePlan = [
    'zh_Hans_CN' => 'zh_Hans_CN',
    '' => 'zh_Hans_CN',
    'en_US' => 'en_US',
    'es_ES' => 'es_ES',
    'fr_FR' => 'fr_FR',
    'pt_BR' => 'pt_BR',
    'id_ID' => 'id_ID',
    'ar_SA' => 'ar_SA',
    'bn_BD' => 'bn_BD',
    'hi_IN' => 'hi_IN',
    'ur_PK' => 'ur_PK',
];

$enLeakMarkers = [
    'Design wellspring', 'Original craft', 'Worth noting', 'At a glance', 'Close looking', 'Size guide',
    'Full and mid shots', 'Near views show', 'Hand wash separately',
];
$banned = ['一件代发', '混批', '厂家直销', '旺旺', '货源', '阿里巴巴', '淘宝', '天猫', '拼多多', 'dropship', 'Dropship'];
$bannedAssets = [
    'b6bded97-4a5e-4d3e-8da5-d8def7e61eed', // detail-02 full poem sidebar
    'cad856e5-6b77-46fb-91c5-4778014f9e08', // detail-14 poem sidebar
    '11eda711-b726-46c9-b466-429de9f756a1', // detail-05 circle caption board
];

$writes = [];
foreach ($localePlan as $locale => $baseKey) {
    if (!isset($copy[$baseKey])) {
        fwrite(STDERR, "Missing locale pack: {$baseKey}\n");
        exit(2);
    }
    $html = $assemble($copy[$baseKey]);
    foreach ($banned as $b) {
        if (str_contains($html, $b)) {
            fwrite(STDERR, "Banned phrase in {$locale}: {$b}\n");
            exit(2);
        }
    }
    foreach ($bannedAssets as $aid) {
        if (str_contains($html, $aid)) {
            fwrite(STDERR, "Banned poem-sidebar asset in {$locale}: {$aid}\n");
            exit(2);
        }
    }
    if (!str_contains($html, 'weline-detail-feature--poem-aside')
        || !str_contains($html, 'weline-detail-prose--verse-vertical')) {
        fwrite(STDERR, "Missing poem-aside / verse-vertical in {$locale}\n");
        exit(2);
    }
    if ($baseKey !== 'en_US') {
        foreach ($enLeakMarkers as $marker) {
            if (str_contains($html, $marker)) {
                fwrite(STDERR, "EN dump into {$locale}: {$marker}\n");
                exit(2);
            }
        }
    }
    $writes[] = [
        'locale' => $locale,
        'html' => $html,
        'len' => strlen($html),
        'features' => substr_count($html, 'weline-detail-feature'),
        'poem' => substr_count($html, 'poem-aside'),
        'imgs' => substr_count($html, '<img '),
        'weds' => str_contains($html, 'data-weds="xq"') ? 1 : 0,
    ];
}

foreach ($writes as $w) {
    echo sprintf(
        "%s\t%d\tfeature=%d\tpoem=%d\timgs=%d\tweds=%d\n",
        $w['locale'] === '' ? '(empty)' : $w['locale'],
        $w['len'],
        $w['features'],
        $w['poem'],
        $w['imgs'],
        $w['weds'],
    );
}

if (!$apply) {
    echo "Dry-run. Pass --apply to upload crop + write descriptions.\n";
    exit(0);
}

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
foreach ($writes as $w) {
    $locale = (string)$w['locale'];
    $html = (string)$w['html'];
    if ($locale !== '') {
        LocalDescription::upsertQuiet($productId, $locale, [
            LocalDescription::schema_fields_DESCRIPTION => $html,
        ]);
    }
    $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', $locale, $html, true);
}

ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
    $websiteId,
    'detail_poem_aside_113',
    ['product_ids' => [$productId]],
);
ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('detail_poem_aside_113');

echo 'Applied #113 poem-aside layout: ' . count($writes) . " locales; crop_asset={$poemAssetId}\n";
