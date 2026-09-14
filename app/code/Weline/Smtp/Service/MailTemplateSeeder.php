<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Model\SmtpMailTemplate;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * 从 Extends default_templates 种子 DB；use_default=1 且 hash 变化才刷新；custom 不覆盖。
 */
class MailTemplateSeeder
{
    private MailChannelCollector $collector;

    public function __construct(?MailChannelCollector $collector = null)
    {
        $this->collector = $collector ?? ObjectManager::getInstance(MailChannelCollector::class);
    }

    /**
     * @return array{inserted:int, updated:int, skipped:int}
     */
    public function syncAll(string $storageScope = SystemConfig::SCOPE_GLOBAL): array
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($this->collector->collect() as $channel) {
            $stats = $this->syncChannel($channel, $storageScope);
            $inserted += $stats['inserted'];
            $updated += $stats['updated'];
            $skipped += $stats['skipped'];
        }
        return compact('inserted', 'updated', 'skipped');
    }

    /**
     * @param array{code:string,module:string,default_templates:list} $channel
     * @return array{inserted:int, updated:int, skipped:int}
     */
    public function syncChannel(array $channel, string $storageScope = SystemConfig::SCOPE_GLOBAL): array
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $code = (string)($channel['code'] ?? '');
        $module = (string)($channel['module'] ?? '');
        if ($code === '') {
            return compact('inserted', 'updated', 'skipped');
        }
        foreach ($channel['default_templates'] ?? [] as $tpl) {
            if (!is_array($tpl)) {
                continue;
            }
            $locale = trim((string)($tpl['locale'] ?? ''));
            if ($locale === '' || $locale === 'default') {
                ++$skipped;
                continue;
            }
            $subject = $this->resolveText($tpl, 'subject', 'subject_file', $module);
            $bodyHtml = $this->resolveText($tpl, 'body', 'body_file', $module);
            $bodyText = $this->resolveText($tpl, 'body_text', 'body_text_file', $module);
            /** @var MailTemplateShellComposer $shell */
            $shell = ObjectManager::getInstance(MailTemplateShellComposer::class);
            $bodyHtml = $shell->extractBodyFragment($bodyHtml);
            if ($subject === '' && $bodyHtml === '') {
                ++$skipped;
                continue;
            }
            $hash = hash('sha256', $locale . "\0" . $subject . "\0" . $bodyHtml . "\0" . $bodyText);
            $result = $this->upsertSeedRow($code, $storageScope, $locale, $subject, $bodyHtml, $bodyText, $hash);
            if ($result === 'inserted') {
                ++$inserted;
            } elseif ($result === 'updated') {
                ++$updated;
            } else {
                ++$skipped;
            }
        }
        return compact('inserted', 'updated', 'skipped');
    }

    private function upsertSeedRow(
        string $channel,
        string $scope,
        string $locale,
        string $subject,
        string $bodyHtml,
        string $bodyText,
        string $hash,
    ): string {
        /** @var SmtpMailTemplate $model */
        $model = ObjectManager::getInstance(SmtpMailTemplate::class);
        $existing = $model->clear()
            ->where(SmtpMailTemplate::schema_fields_CHANNEL_CODE, $channel)
            ->where(SmtpMailTemplate::schema_fields_STORAGE_SCOPE, $scope)
            ->where(SmtpMailTemplate::schema_fields_LOCALE, $locale)
            ->find()
            ->fetch();

        if ($existing && $existing->getId()) {
            $useDefault = (int)$existing->getData(SmtpMailTemplate::schema_fields_USE_DEFAULT) === 1;
            $source = (string)$existing->getData(SmtpMailTemplate::schema_fields_SOURCE);
            if (!$useDefault || $source === SmtpMailTemplate::SOURCE_CUSTOM) {
                return 'skipped';
            }
            $oldHash = (string)$existing->getData(SmtpMailTemplate::schema_fields_SEED_HASH);
            if ($oldHash === $hash) {
                return 'skipped';
            }
            $existing->setData(SmtpMailTemplate::schema_fields_SUBJECT, $subject)
                ->setData(SmtpMailTemplate::schema_fields_BODY_HTML, $bodyHtml)
                ->setData(SmtpMailTemplate::schema_fields_BODY_TEXT, $bodyText)
                ->setData(SmtpMailTemplate::schema_fields_SEED_HASH, $hash)
                ->setData(SmtpMailTemplate::schema_fields_SOURCE, SmtpMailTemplate::SOURCE_MODULE)
                ->setData(SmtpMailTemplate::schema_fields_USE_DEFAULT, 1)
                ->setData(SmtpMailTemplate::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->save();
            return 'updated';
        }

        /** @var SmtpMailTemplate $create */
        $create = ObjectManager::getInstance(SmtpMailTemplate::class);
        $create->clearData()
            ->setData(SmtpMailTemplate::schema_fields_CHANNEL_CODE, $channel)
            ->setData(SmtpMailTemplate::schema_fields_STORAGE_SCOPE, $scope)
            ->setData(SmtpMailTemplate::schema_fields_LOCALE, $locale)
            ->setData(SmtpMailTemplate::schema_fields_SUBJECT, $subject)
            ->setData(SmtpMailTemplate::schema_fields_BODY_HTML, $bodyHtml)
            ->setData(SmtpMailTemplate::schema_fields_BODY_TEXT, $bodyText)
            ->setData(SmtpMailTemplate::schema_fields_SOURCE, SmtpMailTemplate::SOURCE_MODULE)
            ->setData(SmtpMailTemplate::schema_fields_USE_DEFAULT, 1)
            ->setData(SmtpMailTemplate::schema_fields_SEED_HASH, $hash)
            ->setData(SmtpMailTemplate::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();
        return 'inserted';
    }

    /** @param array<string, mixed> $tpl */
    private function resolveText(array $tpl, string $inlineKey, string $fileKey, string $module): string
    {
        $inline = trim((string)($tpl[$inlineKey] ?? ''));
        if ($inline !== '') {
            return $inline;
        }
        $rel = trim((string)($tpl[$fileKey] ?? ''));
        if ($rel === '') {
            return '';
        }
        return $this->readModuleEmailFile($module, $rel);
    }

    private function readModuleEmailFile(string $moduleName, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            return '';
        }
        if (!preg_match('#^view/email/[A-Za-z0-9_./-]+$#', $relative)
            && !preg_match('#^[A-Za-z0-9_./-]+$#', $relative)) {
            return '';
        }
        $base = $this->moduleBasePath($moduleName);
        if ($base === '') {
            return '';
        }
        $path = str_starts_with($relative, 'view/')
            ? $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)
            : $base . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'email'
                . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $realBase = realpath($base . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'email');
        $realFile = realpath($path);
        if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase)) {
            return '';
        }
        $content = @file_get_contents($realFile);
        return is_string($content) ? $content : '';
    }

    private function moduleBasePath(string $moduleName): string
    {
        foreach (Env::getInstance()->getModuleList() as $module) {
            if (($module['name'] ?? '') === $moduleName) {
                return rtrim((string)($module['base_path'] ?? ''), DIRECTORY_SEPARATOR);
            }
        }
        return '';
    }
}
