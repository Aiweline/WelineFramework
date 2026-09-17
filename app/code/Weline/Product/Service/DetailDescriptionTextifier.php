<?php

declare(strict_types=1);

namespace Weline\Product\Service;

/**
 * Replace text-heavy detail images (size charts, care notes) with semantic HTML
 * that storefront sanitizer can render and TranslationService can localize.
 */
final class DetailDescriptionTextifier
{
    private const ALLOWED_TAGS = [
        'div', 'p', 'br', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'strong', 'em', 'span',
    ];

    /**
     * Replace the first img referencing asset://{assetId} with an HTML fragment.
     */
    public static function replaceAssetImageWithHtml(string $html, string $assetId, string $replacementHtml): string
    {
        $assetId = strtolower(trim($assetId));
        $replacementHtml = self::sanitizeFragment($replacementHtml);
        if ($assetId === '' || $replacementHtml === '' || $html === '') {
            return $html;
        }

        $pattern = '#<img\b[^>]*\bsrc=(["\'])asset://' . preg_quote($assetId, '#') . '\1[^>]*/?>#i';
        $count = 0;
        $updated = preg_replace($pattern, $replacementHtml, $html, 1, $count);
        if (!is_string($updated) || $count < 1) {
            return $html;
        }

        // Drop empty wrappers left behind after replacing a lone img.
        $updated = preg_replace('#<(p|div|span)\b[^>]*>\s*</\1>#i', '', $updated) ?? $updated;

        return $updated;
    }

    public static function sanitizeFragment(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!doctype html><html><head><meta charset="utf-8"></head><body>'
                . '<div id="weline-detail-text-root">' . $html . '</div>'
                . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return '';
        }

        $xpath = new \DOMXPath($document);
        $roots = $xpath->query('//*[@id="weline-detail-text-root"]');
        $root = $roots !== false ? $roots->item(0) : null;
        if (!$root instanceof \DOMElement) {
            return '';
        }

        self::sanitizeNode($root);
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= (string)$document->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * @param callable(string): string $translator
     */
    public static function mapTextNodes(string $html, callable $translator): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!doctype html><html><head><meta charset="utf-8"></head><body>'
                . '<div id="weline-detail-text-root">' . $html . '</div>'
                . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($document);
        $roots = $xpath->query('//*[@id="weline-detail-text-root"]');
        $root = $roots !== false ? $roots->item(0) : null;
        if (!$root instanceof \DOMElement) {
            return $html;
        }

        $texts = $xpath->query('.//text()[normalize-space(.) != ""]', $root);
        if ($texts !== false) {
            /** @var \DOMText $node */
            foreach ($texts as $node) {
                $parent = $node->parentNode;
                if ($parent instanceof \DOMElement && strtolower($parent->tagName) === 'img') {
                    continue;
                }
                $original = $node->nodeValue ?? '';
                if (trim($original) === '' || preg_match('#^asset://#i', trim($original)) === 1) {
                    continue;
                }
                $translated = trim((string)$translator($original));
                if ($translated !== '') {
                    $node->nodeValue = $translated;
                }
            }
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= (string)$document->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * Measurement size reference chart (cm) for garment parts (zh).
     *
     * @param list<array{title:string,headers:list<string>,rows:list<list<string>>}> $tables
     */
    public static function buildMeasurementSizeChartZh(
        array $tables,
        string $title = '尺码参考表',
        string $note = '单位：厘米（cm）。手工测量可能存在 1–3 cm 误差。',
    ): string {
        return self::sanitizeFragment(
            '<div class="weline-detail-text weline-detail-text--size-chart" data-weline-detail-text="measurement-chart">'
            . '<h3>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
            . '<p class="weline-detail-text__note">' . htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<div class="weline-detail-text__columns">'
            . self::measurementColumns($tables)
            . '</div>'
            . '</div>'
        );
    }

    /**
     * @param list<array{title:string,headers:list<string>,rows:list<list<string>>}> $tables
     */
    public static function buildMeasurementSizeChartEn(
        array $tables,
        string $title = 'Size reference chart',
        string $note = 'Unit: centimeters (cm). Hand measurements may vary by 1–3 cm.',
    ): string {
        return self::sanitizeFragment(
            '<div class="weline-detail-text weline-detail-text--size-chart" data-weline-detail-text="measurement-chart">'
            . '<h3>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
            . '<p class="weline-detail-text__note">' . htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<div class="weline-detail-text__columns">'
            . self::measurementColumns($tables)
            . '</div>'
            . '</div>'
        );
    }

    /**
     * Product information + comfort scales baked from listing graphics (zh).
     *
     * @param array<string, string> $basics
     * @param list<array{label:string,options:list<string>,selected:string}> $comfort
     */
    public static function buildProductInfoPanelZh(
        array $basics,
        array $comfort,
        string $title = '产品信息',
        string $basicsTitle = '基本信息',
        string $comfortTitle = '舒适度信息',
    ): string {
        return self::sanitizeFragment(
            '<div class="weline-detail-text weline-detail-text--product-info" data-weline-detail-text="product-info">'
            . '<h3>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
            . '<h4>' . htmlspecialchars($basicsTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h4>'
            . self::definitionList($basics)
            . '<h4>' . htmlspecialchars($comfortTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h4>'
            . self::comfortScales($comfort)
            . '</div>'
        );
    }

    /**
     * @param array<string, string> $basics
     * @param list<array{label:string,options:list<string>,selected:string}> $comfort
     */
    public static function buildProductInfoPanelEn(
        array $basics,
        array $comfort,
        string $title = 'Product information',
        string $basicsTitle = 'Basics',
        string $comfortTitle = 'Comfort & fit',
    ): string {
        return self::sanitizeFragment(
            '<div class="weline-detail-text weline-detail-text--product-info" data-weline-detail-text="product-info">'
            . '<h3>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
            . '<h4>' . htmlspecialchars($basicsTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h4>'
            . self::definitionList($basics)
            . '<h4>' . htmlspecialchars($comfortTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h4>'
            . self::comfortScales($comfort)
            . '</div>'
        );
    }

    public static function buildSectionHeading(string $heading, string $marker = 'section-heading'): string
    {
        $marker = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($marker))) ?: 'section-heading';

        return self::sanitizeFragment(
            '<div class="weline-detail-text weline-detail-text--section" data-weline-detail-text="'
            . htmlspecialchars($marker, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . '<h3>' . htmlspecialchars(trim($heading), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
            . '</div>'
        );
    }

    /**
     * Couples / gender-split weight-based size suggestion chart (zh).
     *
     * @param list<array{label:string,min_jin:int,max_jin:int,size:string}> $women
     * @param list<array{label:string,min_jin:int,max_jin:int,size:string}> $men
     */
    public static function buildGenderWeightSizeChartZh(
        array $women,
        array $men,
        string $note = '此款为男女分码款，可按以下尺码放心选择。',
        string $footer = '建议按体重选购。',
    ): string {
        return self::sanitizeFragment(
            '<div class="weline-detail-text weline-detail-text--size-chart" data-weline-detail-text="size-chart">'
            . '<h3>尺码建议表</h3>'
            . '<p class="weline-detail-text__note">' . htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<div class="weline-detail-text__columns">'
            . self::genderColumnZh('女款', $women)
            . self::genderColumnZh('男款', $men)
            . '</div>'
            . '<p class="weline-detail-text__foot">' . htmlspecialchars($footer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '</div>'
        );
    }

    /**
     * @param list<array{label:string,min_jin:int,max_jin:int,size:string}> $women
     * @param list<array{label:string,min_jin:int,max_jin:int,size:string}> $men
     */
    public static function buildGenderWeightSizeChartEn(
        array $women,
        array $men,
        string $note = 'This style uses separate sizing for women and men. Choose by the weight ranges below.',
        string $footer = 'Order by body weight.',
    ): string {
        return self::sanitizeFragment(
            '<div class="weline-detail-text weline-detail-text--size-chart" data-weline-detail-text="size-chart">'
            . '<h3>Size suggestion chart</h3>'
            . '<p class="weline-detail-text__note">' . htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<div class="weline-detail-text__columns">'
            . self::genderColumnEn('Women', $women)
            . self::genderColumnEn('Men', $men)
            . '</div>'
            . '<p class="weline-detail-text__foot">' . htmlspecialchars($footer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '</div>'
        );
    }

    /**
     * @param list<array{title:string,headers:list<string>,rows:list<list<string>>}> $tables
     */
    private static function measurementColumns(array $tables): string
    {
        $html = '';
        foreach ($tables as $table) {
            $title = trim((string)($table['title'] ?? ''));
            $headers = is_array($table['headers'] ?? null) ? $table['headers'] : [];
            $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
            $headCells = '';
            foreach ($headers as $header) {
                $headCells .= '<th>' . htmlspecialchars((string)$header, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
            }
            $body = '';
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $body .= '<tr>';
                foreach ($row as $i => $cell) {
                    $tag = $i === 0 ? 'th' : 'td';
                    $body .= '<' . $tag . '>' . htmlspecialchars((string)$cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</' . $tag . '>';
                }
                $body .= '</tr>';
            }
            $html .= '<div class="weline-detail-text__col">'
                . ($title !== '' ? '<h4>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h4>' : '')
                . '<table><thead><tr>' . $headCells . '</tr></thead><tbody>' . $body . '</tbody></table>'
                . '</div>';
        }

        return $html;
    }

    /**
     * @param array<string, string> $items
     */
    private static function definitionList(array $items): string
    {
        $rows = '';
        foreach ($items as $label => $value) {
            $rows .= '<tr><th>' . htmlspecialchars((string)$label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</th><td>' . htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</td></tr>';
        }

        return '<table class="weline-detail-text__defs"><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @param list<array{label:string,options:list<string>,selected:string}> $scales
     */
    private static function comfortScales(array $scales): string
    {
        $html = '<ul class="weline-detail-text__scales">';
        foreach ($scales as $scale) {
            $label = trim((string)($scale['label'] ?? ''));
            $selected = trim((string)($scale['selected'] ?? ''));
            $options = is_array($scale['options'] ?? null) ? $scale['options'] : [];
            $chips = '';
            foreach ($options as $option) {
                $option = trim((string)$option);
                $class = $option !== '' && $option === $selected
                    ? ' class="weline-detail-text__scale-option weline-detail-text__scale-option--selected"'
                    : ' class="weline-detail-text__scale-option"';
                $chips .= '<li' . $class . '>' . htmlspecialchars($option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
            }
            $html .= '<li class="weline-detail-text__scale">'
                . '<strong>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>'
                . '<ul class="weline-detail-text__scale-options">' . $chips . '</ul>'
                . '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * @param list<array{label:string,min_jin:int,max_jin:int,size:string}> $rows
     */
    private static function genderColumnZh(string $title, array $rows): string
    {
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr><td>' . htmlspecialchars(self::formatJinRangeZh((int)$row['min_jin'], (int)$row['max_jin']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</td><td><strong>' . htmlspecialchars((string)$row['size'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</strong></td></tr>';
        }

        return '<div class="weline-detail-text__col">'
            . '<h4>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h4>'
            . '<table><tbody>' . $body . '</tbody></table>'
            . '</div>';
    }

    /**
     * @param list<array{label:string,min_jin:int,max_jin:int,size:string}> $rows
     */
    private static function genderColumnEn(string $title, array $rows): string
    {
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr><td>' . htmlspecialchars(self::formatJinRangeEn((int)$row['min_jin'], (int)$row['max_jin']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</td><td><strong>' . htmlspecialchars((string)$row['size'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</strong></td></tr>';
        }

        return '<div class="weline-detail-text__col">'
            . '<h4>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h4>'
            . '<table><tbody>' . $body . '</tbody></table>'
            . '</div>';
    }

    /**
     * Detect the common 男女分码 weight chart from OCR and return structured rows.
     *
     * @return null|array{women:list<array{label:string,min_jin:int,max_jin:int,size:string}>,men:list<array{label:string,min_jin:int,max_jin:int,size:string}>}
     */
    public static function parseGenderWeightChartFromOcr(string $ocr): ?array
    {
        $text = trim($ocr);
        if ($text === '' || (mb_strpos($text, '尺码建议') === false && mb_strpos($text, '建议体重') === false)) {
            return null;
        }

        $women = self::matchGenderWeightRows($text, '女款|女装|女士', '女款');
        $men = self::matchGenderWeightRows($text, '男款|男装|男士', '男款');

        // OCR often drops the gender word on later lines; fall back to section split.
        if (($women === [] || $men === []) && preg_match('/女款尺码([\s\S]*?)男款尺码([\s\S]*)$/u', $text, $sections) === 1) {
            if ($women === []) {
                $women = self::matchGenderWeightRows('女款 ' . $sections[1], '女款|女装|女士|建议体重', '女款');
            }
            if ($men === []) {
                $men = self::matchGenderWeightRows('男款 ' . $sections[2], '男款|男装|男士|建议体重', '男款');
            }
        }

        if ($women === [] || $men === []) {
            return null;
        }

        return [
            'women' => self::uniqueWeightRows($women),
            'men' => self::uniqueWeightRows($men),
        ];
    }

    /**
     * @return list<array{label:string,min_jin:int,max_jin:int,size:string}>
     */
    private static function matchGenderWeightRows(string $text, string $genderAlt, string $label): array
    {
        $rows = [];
        if (preg_match_all(
            '/(?:' . $genderAlt . ')[^\n]{0,24}?(\d{2,3})\s*[-~—–]\s*(\d{2,3})\s*斤[^\n]{0,24}?选?\s*([SMLX0-9]{1,4})\s*码/u',
            $text,
            $matches,
            PREG_SET_ORDER,
        ) !== false) {
            foreach ($matches as $match) {
                $rows[] = [
                    'label' => $label,
                    'min_jin' => (int)$match[1],
                    'max_jin' => (int)$match[2],
                    'size' => strtoupper($match[3]),
                ];
            }
        }

        return $rows;
    }

    public static function formatJinRangeZh(int $minJin, int $maxJin): string
    {
        $minKg = $minJin / 2;
        $maxKg = $maxJin / 2;

        return sprintf(
            '%d-%d 斤（%s-%s kg）',
            $minJin,
            $maxJin,
            self::formatKg($minKg),
            self::formatKg($maxKg),
        );
    }

    public static function formatJinRangeEn(int $minJin, int $maxJin): string
    {
        $minKg = $minJin / 2;
        $maxKg = $maxJin / 2;

        return sprintf(
            '%d-%d jin (%s-%s kg)',
            $minJin,
            $maxJin,
            self::formatKg($minKg),
            self::formatKg($maxKg),
        );
    }

    private static function formatKg(float $kg): string
    {
        $rounded = round($kg, 1);
        if (abs($rounded - (int)$rounded) < 0.05) {
            return (string)(int)$rounded;
        }

        return rtrim(rtrim(number_format($rounded, 1, '.', ''), '0'), '.');
    }

    /**
     * @param list<array{label:string,min_jin:int,max_jin:int,size:string}> $rows
     * @return list<array{label:string,min_jin:int,max_jin:int,size:string}>
     */
    private static function uniqueWeightRows(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $key = $row['min_jin'] . '-' . $row['max_jin'] . '-' . $row['size'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    private static function sanitizeNode(\DOMNode $parent): void
    {
        for ($child = $parent->firstChild; $child !== null; $child = $next) {
            $next = $child->nextSibling;
            if ($child instanceof \DOMComment) {
                $parent->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'b') {
                $strong = $child->ownerDocument?->createElement('strong');
                if ($strong instanceof \DOMElement) {
                    while ($child->firstChild !== null) {
                        $strong->appendChild($child->firstChild);
                    }
                    $parent->replaceChild($strong, $child);
                    $child = $strong;
                    $tag = 'strong';
                }
            }
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                self::sanitizeNode($child);
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }
            self::retainSafeDetailTextAttributes($child);
            self::sanitizeNode($child);
        }
    }

    private static function retainSafeDetailTextAttributes(\DOMElement $element): void
    {
        $class = trim($element->getAttribute('class'));
        $marker = trim($element->getAttribute('data-weline-detail-text'));
        while ($element->attributes->length > 0) {
            $attribute = $element->attributes->item(0);
            if ($attribute === null) {
                break;
            }
            $element->removeAttributeNode($attribute);
        }
        $safeClass = self::safeDetailTextClass($class);
        if ($safeClass !== '') {
            $element->setAttribute('class', $safeClass);
        }
        if ($marker !== '' && preg_match('/^[a-z0-9_-]{1,40}$/D', $marker) === 1) {
            $element->setAttribute('data-weline-detail-text', $marker);
        }
    }

    private static function safeDetailTextClass(string $class): string
    {
        $tokens = preg_split('/\s+/', trim($class)) ?: [];
        $kept = [];
        foreach ($tokens as $token) {
            if (preg_match(
                '/^weline-detail-(?:text|prose|feature|figure|quiet|bento)(?:-[a-z0-9]+)*(?:__[a-z0-9-]+)?(?:--[a-z0-9-]+)?$/D',
                $token,
            ) === 1) {
                $kept[] = $token;
            }
        }

        return implode(' ', array_values(array_unique($kept)));
    }
}
