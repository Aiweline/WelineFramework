<?php
/**
 * AiAcquisition lead entry_channel 批次静态验收（T1-T4）：
 * Model/Normalizer/Service/Tool/Provider/Bridge/Controller/模板/CSS/i18n 全部落点断言。
 * Prints "ACCEPT PASS" / "ACCEPT FAIL: <reason>", exit 0/1.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/autoload.php';

$base = dirname(__DIR__) . '/app/code/Weline';
$fail = static function (string $r): void { echo "ACCEPT FAIL: $r\n"; exit(1); };
$has = static function (string $file, string ...$needles) use ($base, $fail): void {
    $path = $base . '/' . $file;
    if (!is_file($path)) { $fail("missing file: $file"); }
    $src = (string)file_get_contents($path);
    foreach ($needles as $n) {
        if (!str_contains($src, $n)) { $fail("missing `$n` in $file"); }
    }
};
$miss = static function (string $file, string ...$needles) use ($base, $fail): void {
    $path = $base . '/' . $file;
    if (!is_file($path)) { $fail("missing file: $file"); }
    $src = (string)file_get_contents($path);
    foreach ($needles as $n) {
        if (str_contains($src, $n)) { $fail("unexpected `$n` in $file"); }
    }
};

// ── T1 Model / Upgrade / version ────────────────────────────────
$has('AiAcquisition/Model/Lead.php',
    "const schema_fields_ENTRY_CHANNEL = 'entry_channel'",
    "const ENTRY_CHANNEL_DEFAULT = 'default'",
    "const ENTRY_CHANNEL_BROWSER_MODEL = 'browser_model'",
    "const ENTRY_CHANNEL_AI_SELECT = 'ai_select'",
    "const ENTRY_CHANNEL_SKILL = 'skill'",
    'ENTRY_CHANNELS',
    'getEntryChannelLabel',
    "idx_ai_acquisition_lead_entry_channel",
    "#[Col('varchar', 32, default: 'default', comment: '录入渠道')]",
);
$has('AiAcquisition/Setup/Upgrade.php',
    "'entry_channel'",
    'columnExist',
    'addColumn',
);
$moduleXml = (string)file_get_contents($base . '/AiAcquisition/etc/module.xml');
if (!str_contains($moduleXml, 'setup_version="1.0.2"')) { $fail('module.xml setup_version 未升到 1.0.2'); }
$modulePhp = (string)file_get_contents($base . '/AiAcquisition/etc/module.php');
if (!str_contains($modulePhp, "'version' => '1.0.2'")) { $fail('module.php version 未升到 1.0.2'); }

// ── T2 Normalizer / Service / Tool ──────────────────────────────
$has('AiAcquisition/Service/Lead/LeadNormalizer.php',
    'entry_channel',
    'ENTRY_CHANNELS',
    'ENTRY_CHANNEL_DEFAULT',
);
$has('AiAcquisition/Service/Lead/LeadService.php',
    'entry_channel',
    'ENTRY_CHANNEL',
    'ENTRY_CHANNEL_DEFAULT',
);
$has('AiAcquisition/Service/Tool/SaveLeadTool.php',
    'entry_channel',
    'ENTRY_CHANNEL_AI_SELECT',
);
$has('AiAcquisition/Test/Unit/Lead/LeadNormalizerTest.php',
    'entry_channel',
    'ENTRY_CHANNEL',
);

// ── T3 Provider / Bridge / Controller ───────────────────────────
$has('AiAcquisition/extends/module/Weline_Framework/Query/AcquisitionQueryProvider.php',
    'entry_channel',
    'ENTRY_CHANNEL_DEFAULT',
);
$has('AiAcquisition/Controller/Frontend/Bridge.php',
    'entry_channel',
    'ENTRY_CHANNEL_BROWSER_MODEL',
    'model_mode',
);
$has('AiAcquisition/Controller/Backend/Lead.php',
    'entryChannelLabels',
    'ENTRY_CHANNELS',
);

// ── T4 模板 / CSS / i18n ────────────────────────────────────────
$has('AiAcquisition/view/templates/backend/Lead/index.phtml',
    'entryChannelLabels',
    'entry-channel',
    'filter-entry',
    'lead-entry-channel',
    'acq-entry',
);
$has('AiAcquisition/view/statics/css/backend/acquisition.css',
    'acq-entry',
    'acq-entry--browser_model',
    'acq-entry--ai_select',
    'acq-entry--skill',
);
$has('AiAcquisition/i18n/zh_Hans_CN.csv', '录入方式');
$has('AiAcquisition/i18n/en_US.csv', 'Entry channel');

// 前端强调预算：录入徽章只允许三种语义色（browser=info / ai=primary / skill=success），禁第四种
$css = (string)file_get_contents($base . '/AiAcquisition/view/statics/css/backend/acquisition.css');
if (str_contains($css, 'acq-entry--default')) { $fail('default 不应渲染彩色徽章（强调预算）'); }

echo "ACCEPT PASS\n";
