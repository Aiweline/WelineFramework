<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

/**
 * Builds provider default_templates for default-website locales.
 * zh_Hans_CN: module disk files only; other locales: inline subject/body from seed catalog (DB-bound).
 */
final class MailTemplateDefaultLocales
{
    /**
     * @param string $dir Template folder / slug under view/email/ (e.g. password_reset)
     * @param string $pathStyle 'view' => view/email/{dir}/… ; 'short' => {dir}/…
     * @return list<array{locale:string,subject?:string,body?:string,subject_file?:string,body_file?:string}>
     */
    public static function fileEntries(string $dir, string $pathStyle = 'view'): array
    {
        return self::seedEntries($dir, $pathStyle);
    }

    /**
     * @param string $dir Template folder / slug under view/email/
     * @param string $pathStyle 'view' | 'short'
     * @return list<array{locale:string,subject?:string,body?:string,subject_file?:string,body_file?:string}>
     */
    public static function seedEntries(string $dir, string $pathStyle = 'view'): array
    {
        $dir = trim($dir, '/');
        if ($dir === '' || str_contains($dir, '..')) {
            return [];
        }
        $slug = $dir;
        $wanted = MailTemplateSeedLocaleResolver::forDefaultWebsite();
        $maintained = array_fill_keys(MailTemplateSeedCopyCatalog::maintainedLocales(), true);
        $out = [];
        foreach ($wanted as $locale) {
            if (!isset($maintained[$locale])) {
                continue;
            }
            if ($locale === MailTemplateSeedCopyCatalog::LOCALE_ZH) {
                if ($pathStyle === 'short') {
                    $subject = $dir . '/' . $locale . '.subject.txt';
                    $body = $dir . '/' . $locale . '.html';
                } else {
                    $subject = 'view/email/' . $dir . '/' . $locale . '.subject.txt';
                    $body = 'view/email/' . $dir . '/' . $locale . '.html';
                }
                $out[] = [
                    'locale' => $locale,
                    'subject_file' => $subject,
                    'body_file' => $body,
                ];
                continue;
            }
            $copy = MailTemplateSeedCopyCatalog::forSlug($slug, $locale);
            if ($copy === null) {
                continue;
            }
            $out[] = [
                'locale' => $locale,
                'subject' => $copy['subject'],
                'body' => $copy['body'],
            ];
        }

        return $out;
    }
}
