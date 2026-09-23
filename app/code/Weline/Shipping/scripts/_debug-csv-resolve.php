<?php
require dirname(__DIR__, 5) . '/app/bootstrap.php';

use Weline\Framework\App\Env;
use Weline\I18n\Api\Translation\DictionaryEntryInterface;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\I18n\Service\TranslationResolver;

$info = Env::getInstance()->getModuleInfo('Weline_Shipping');
$csv = (string)($info['base_path'] ?? '') . '/i18n/en_US.csv';
echo 'base=' . ($info['base_path'] ?? '') . PHP_EOL;
echo 'exists=' . (is_file($csv) ? 'yes' : 'no') . PHP_EOL;

$stub = new class implements DictionaryRepositoryInterface {
    public function getEntry(string $word, string $localeCode): ?DictionaryEntryInterface
    {
        return null;
    }
};
$r = new TranslationResolver(dictionaryRepository: $stub);
$key = '1.1 本店退换政策：因商品质量问题（破损、做工瑕疵、污渍、与描述明显不符）、错发或漏发，可申请退货或换货。因个人喜好、选错颜色/尺码、轻微试穿后不想要等非质量原因，原则上不支持退换。跨境寄回成本高，请下单前确认尺码与款式。';
echo 'tr_help=' . $r->translate('返回帮助中心', 'en_US', ['Weline_Shipping']) . PHP_EOL;
echo 'tr_key=' . mb_substr($r->translate($key, 'en_US', ['Weline_Shipping']), 0, 80) . PHP_EOL;

$h = fopen($csv, 'r');
$n = 0;
$hit = false;
while (($d = fgetcsv($h, 100000, ',', '"', '\\')) !== false) {
    ++$n;
    if (($d[0] ?? '') === $key) {
        $hit = true;
        echo 'hit=' . mb_substr((string)($d[1] ?? ''), 0, 60) . PHP_EOL;
        break;
    }
}
fclose($h);
echo "rows=$n hit=" . ($hit ? 'Y' : 'N') . PHP_EOL;
