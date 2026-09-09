<?php

declare(strict_types=1);

// Isolate PROD/DEV constants without booting the app or writing compiled files.
define('DS', DIRECTORY_SEPARATOR);
define('PROD', ($argv[1] ?? '') === 'prod');
define('DEV', !PROD);
$framework = dirname(__DIR__, 4);
require $framework . '/View/Data/DataInterface.php';
require $framework . '/View/TraitTemplate.php';

final class TagSourceCountingCache
{
    public int $gets = 0;
    public int $sets = 0;
    public array $values = [];

    public function __construct(private bool $warm) {}

    public function get(string $key): mixed
    {
        ++$this->gets;
        if ($this->warm && !array_key_exists($key, $this->values)) {
            $this->values[$key] = __FILE__;
        }
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): bool
    {
        ++$this->sets;
        $this->values[$key] = $value;
        return true;
    }
}

final class TagSourceResolutionFixture
{
    use \Weline\Framework\View\TraitTemplate;

    public int $resolutions = 0;
    public string $compiledPath = __FILE__;

    public function __construct(private TagSourceCountingCache $viewCache) {}
    private function viewEnvironmentCacheSuffix(string $scope): string { return 'same-scope'; }
    public function processModuleSourceFilePath(string $type, string $source): array { return [$source, 'Weline_Cart']; }
    public function getFetchFile(string $fileName, ?string $moduleName = ''): string
    {
        ++$this->resolutions;
        return $this->compiledPath;
    }
}

$scenario = $argv[2] ?? 'warm';
$type = $argv[3] ?? 'hooks';
$cache = new TagSourceCountingCache($scenario !== 'cold');
$template = new TagSourceResolutionFixture($cache);
$sources = [
    'Weline_Cart::hooks/Weline_Theme/frontend/partials/product-card/add-to-cart.phtml',
    'Weline_Checkout::hooks/Weline_Theme/frontend/partials/product-card/buy-now.phtml',
];
$correct = true;
if ($scenario === 'changed') {
    $correct = $template->fetchTagSource($type, $sources[0]) === __FILE__;
    $template->compiledPath = $framework . '/View/TraitTemplate.php';
    $correct = ($template->fetchTagSource($type, $sources[0]) === $template->compiledPath) && $correct;
    $correct = ($template->fetchTagSource($type, $sources[0]) === $template->compiledPath) && $correct;
} else {
    for ($card = 0; $card < 31; ++$card) {
        foreach ($sources as $source) {
            $correct = ($template->fetchTagSource($type, $source) === __FILE__) && $correct;
        }
    }
}
echo json_encode([
    'gets' => $cache->gets,
    'sets' => $cache->sets,
    'keys' => count($cache->values),
    'resolutions' => $template->resolutions,
    'correct_paths' => $correct,
], JSON_THROW_ON_ERROR), PHP_EOL;
