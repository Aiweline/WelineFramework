<?php
declare(strict_types=1);

namespace Weline\Shipping\Console\Shipping\AddressCatalog;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Shipping\Service\AddressCatalog\AddressCatalogImporter;

class Import extends CommandAbstract
{
    public function tip(): string
    {
        return 'Import Shipping address-catalog tsv.gz into DB (data only; schema via setup:upgrade). --country=AF or AF,SG,QA; --layer=cities';
    }

    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $wipe = $this->hasFlag($args, 'wipe');
        $country = $this->optionValue($args, 'country');
        $layer = $this->optionValue($args, 'layer');

        if ($wipe) {
            $printing->warning('wipe will clear address region ids, streets, postal, regions');
        }

        /** @var AddressCatalogImporter $importer */
        $importer = ObjectManager::getInstance(AddressCatalogImporter::class);
        $result = $importer->import(
            wipe: $wipe,
            onlyCountry: $country !== null && $country !== '' ? strtoupper((string)$country) : null,
            onlyLayer: $layer !== null && $layer !== '' ? (string)$layer : null,
        );

        $printing->success(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}');

        return 'OK';
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function hasFlag(array $args, string $name): bool
    {
        foreach ($args as $arg) {
            if (!is_string($arg)) {
                continue;
            }
            if ($arg === '--' . $name || $arg === '--' . $name . '=1' || $arg === '--' . $name . '=true') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function optionValue(array $args, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($args as $arg) {
            if (!is_string($arg)) {
                continue;
            }
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return null;
    }
}
