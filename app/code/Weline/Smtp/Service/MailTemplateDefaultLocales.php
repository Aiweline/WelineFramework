<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

/**
 * Builds provider default_templates entries for maintained / default-website locales.
 */
final class MailTemplateDefaultLocales
{
    /**
     * @param string $dir Template folder name under view/email/ (e.g. password_reset)
     * @param string $pathStyle 'view' => view/email/{dir}/… ; 'short' => {dir}/…
     * @return list<array{locale:string,subject_file:string,body_file:string}>
     */
    public static function fileEntries(string $dir, string $pathStyle = 'view'): array
    {
        $dir = trim($dir, '/');
        if ($dir === '' || str_contains($dir, '..')) {
            return [];
        }
        $wanted = MailTemplateSeedLocaleResolver::forDefaultWebsite();
        $maintained = array_fill_keys(MailTemplateSeedCopyCatalog::maintainedLocales(), true);
        $out = [];
        foreach ($wanted as $locale) {
            if (!isset($maintained[$locale])) {
                continue;
            }
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
        }

        return $out;
    }
}
