<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\CliLanguage;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locals;

/**
 * Append installed+active locales (with display names) to CLI language options.
 */
class CliLanguageOptions implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!\is_array($data)) {
            return;
        }

        $languages = (array)($data['languages'] ?? []);
        $byCode = [];
        foreach ($languages as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = CliLanguage::normalize((string)($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $byCode[$code] = [
                'code' => $code,
                'label' => (string)($row['label'] ?? $code),
                'source' => (string)($row['source'] ?? 'csv'),
            ];
        }

        /** @var Locals $locals */
        $locals = ObjectManager::getInstance(Locals::class);
        $rows = $locals->clear()
            ->where(Locals::schema_fields_IS_INSTALL, 1)
            ->where(Locals::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();

        $displayLocale = CliLanguage::resolveConfigured();
        /** @var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);

        foreach ((array)$rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = CliLanguage::normalize((string)($row[Locals::schema_fields_CODE] ?? ''));
            if ($code === '') {
                continue;
            }
            $label = '';
            try {
                $label = \trim((string)$i18n->getLocaleName($code, $displayLocale));
            } catch (\Throwable) {
            }
            if (!isset($byCode[$code])) {
                $byCode[$code] = [
                    'code' => $code,
                    'label' => $label !== '' ? $label : $code,
                    'source' => 'i18n',
                ];
                continue;
            }
            if ($label !== '' && ($byCode[$code]['label'] === '' || $byCode[$code]['label'] === $code)) {
                $byCode[$code]['label'] = $label;
            }
            if (($byCode[$code]['source'] ?? '') === 'csv') {
                $byCode[$code]['source'] = 'i18n';
            }
        }

        $data['languages'] = \array_values($byCode);
        $event->setData($data);
    }
}
