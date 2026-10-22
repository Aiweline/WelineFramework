<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * 把发票正文画成白纸 JPEG，再装进 PDF。中文依赖服务器上的现成字体文件。
 */
final class BuyerInvoicePdfRenderer
{
    private const PAGE_W = 1240;
    private const PAGE_H = 1754;
    private const LEFT = 80;
    private const RIGHT = 1160;

    /** @param list<string>|null $fontCandidates */
    public function __construct(private readonly ?array $fontCandidates = null)
    {
    }

    public function render(BuyerInvoiceDocument $document): string
    {
        if (!function_exists('imagettftext') || !function_exists('imagejpeg')) {
            throw new BuyerInvoiceException(
                'buyer_invoice_font_unavailable',
                500,
                (string)__('服务器缺少可用于发票的中文字体'),
            );
        }
        $body = $this->resolveFont();
        $title = $this->resolveTitleFont($body);
        $rows = $this->itemRows($document, $body);
        $pages = $this->packRows($rows);
        $jpegs = [];
        $pageCount = count($pages);
        foreach ($pages as $index => $slice) {
            $jpegs[] = $this->paint($document, $title, $body, $slice, $index, $pageCount);
        }

        return self::compose($jpegs);
    }

    /**
     * @param list<string> $jpegBinaries
     */
    public static function compose(array $jpegBinaries): string
    {
        if ($jpegBinaries === []) {
            throw new \InvalidArgumentException('buyer_invoice_pdf_empty');
        }
        $sizes = [];
        foreach ($jpegBinaries as $jpeg) {
            if (!is_string($jpeg) || $jpeg === '') {
                throw new \InvalidArgumentException('buyer_invoice_pdf_image_invalid');
            }
            $info = getimagesizefromstring($jpeg);
            if (!is_array($info) || (int)($info[2] ?? 0) !== IMAGETYPE_JPEG || (int)$info[0] < 1 || (int)$info[1] < 1) {
                throw new \InvalidArgumentException('buyer_invoice_pdf_image_invalid');
            }
            $sizes[] = [(int)$info[0], (int)$info[1]];
        }

        $pageCount = count($jpegBinaries);
        $total = 2 + ($pageCount * 3);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        $next = 1;
        $write = static function (string $body) use (&$pdf, &$offsets, &$next): void {
            $offsets[$next] = strlen($pdf);
            $pdf .= $next . " 0 obj\n" . $body . "\nendobj\n";
            $next++;
        };

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (5 + ($i * 3)) . ' 0 R';
        }
        $write('<< /Type /Catalog /Pages 2 0 R >>');
        $write('<< /Type /Pages /Count ' . $pageCount . ' /Kids [' . implode(' ', $kids) . '] >>');

        $draw = "q\n595.28 0 0 841.89 0 0 cm\n/Im0 Do\nQ";
        for ($i = 0; $i < $pageCount; $i++) {
            $jpeg = $jpegBinaries[$i];
            [$width, $height] = $sizes[$i];
            $contentId = 3 + ($i * 3);
            $imageId = 4 + ($i * 3);
            $write('<< /Length ' . strlen($draw) . " >>\nstream\n" . $draw . "\nendstream");
            $write(
                '<< /Type /XObject /Subtype /Image /Width ' . $width
                . ' /Height ' . $height
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '
                . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream"
            );
            $write(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Contents '
                . $contentId . ' 0 R /Resources << /XObject << /Im0 ' . $imageId . ' 0 R >> >> >>'
            );
        }
        if ($next !== $total + 1) {
            throw new \RuntimeException('buyer_invoice_pdf_object_count');
        }

        $startxref = strlen($pdf);
        $pdf .= 'xref' . "\n" . '0 ' . ($total + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $total; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($total + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $startxref . "\n%%EOF";

        return $pdf;
    }

    private function resolveFont(): string
    {
        $candidates = $this->fontCandidates ?? self::defaultFontCandidates();
        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && is_file($path) && is_readable($path)) {
                $box = imagettfbbox(16, 0, $path, '发票');
                if (is_array($box)) {
                    return $path;
                }
            }
        }

        throw new BuyerInvoiceException(
            'buyer_invoice_font_unavailable',
            500,
            (string)__('服务器缺少可用于发票的中文字体'),
        );
    }

    /** @return list<string> */
    public static function defaultFontCandidates(): array
    {
        return [
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJKsc-Regular.otf',
            '/usr/share/fonts/truetype/noto/NotoSansCJKsc-Regular.otf',
            '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
            '/System/Library/Fonts/Hiragino Sans GB.ttc',
            '/System/Library/Fonts/Supplemental/Songti.ttc',
            '/Library/Fonts/Arial Unicode.ttf',
        ];
    }

    private function resolveTitleFont(string $bodyFont): string
    {
        foreach ([
            '/System/Library/Fonts/Supplemental/Songti.ttc',
            '/usr/share/fonts/opentype/noto/NotoSerifCJK-Regular.ttc',
            '/usr/share/fonts/truetype/noto/NotoSerifCJK-Regular.ttc',
        ] as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $box = imagettfbbox(16, 0, $path, '发票');
            if (is_array($box)) {
                return $path;
            }
        }

        return $bodyFont;
    }

    /**
     * @return list<array{name:string,qty:string,price:string,total:string,nameLines:list<string>,height:int}>
     */
    private function itemRows(BuyerInvoiceDocument $document, string $font): array
    {
        $source = $document->lines;
        if ($source === []) {
            $source = [[
                'name' => (string)__('订单货款'),
                'qty' => '1',
                'price' => $document->totalLabel,
                'total' => $document->totalLabel,
            ]];
        }
        $rows = [];
        foreach ($source as $line) {
            $nameLines = array_slice($this->wrap((string)$line['name'], $font, 24, 520), 0, 3);
            if ($nameLines === [] || $nameLines === ['']) {
                $nameLines = ['—'];
            }
            $sku = trim((string)($line['sku'] ?? ''));
            $rows[] = [
                'name' => (string)$line['name'],
                'qty' => (string)$line['qty'],
                'price' => (string)$line['price'],
                'total' => (string)$line['total'],
                'sku' => $sku,
                'nameLines' => $nameLines,
                'height' => max(64, 28 + (count($nameLines) * 32) + ($sku !== '' ? 26 : 0)),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{name:string,qty:string,price:string,total:string,nameLines:list<string>,height:int}> $rows
     * @return list<list<array{name:string,qty:string,price:string,total:string,nameLines:list<string>,height:int}>>
     */
    private function packRows(array $rows): array
    {
        $pages = [];
        $current = [];
        $used = 0;
        $limit = 280;
        foreach ($rows as $row) {
            if ($current !== [] && $used + $row['height'] > $limit) {
                $pages[] = $current;
                $current = [];
                $used = 0;
                $limit = 1120;
            }
            $current[] = $row;
            $used += $row['height'];
        }
        if ($current !== []) {
            $pages[] = $current;
        }

        return $pages === [] ? [[]] : $pages;
    }

    /**
     * @param list<array{name:string,qty:string,price:string,total:string,nameLines:list<string>,height:int}> $rows
     */
    private function paint(
        BuyerInvoiceDocument $document,
        string $titleFont,
        string $bodyFont,
        array $rows,
        int $pageIndex,
        int $pageCount,
    ): string {
        $image = imagecreatetruecolor(self::PAGE_W, self::PAGE_H);
        if ($image === false) {
            throw new \RuntimeException('buyer_invoice_pdf_canvas');
        }
        if (function_exists('imageantialias')) {
            imageantialias($image, true);
        }
        $paper = $this->color($image, 251, 249, 246);
        $ink = $this->color($image, 28, 25, 23);
        $muted = $this->color($image, 111, 106, 100);
        $line = $this->color($image, 214, 208, 198);
        $head = $this->color($image, 241, 236, 228);
        $white = $this->color($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, self::PAGE_W, self::PAGE_H, $paper);
        imagesetthickness($image, 2);
        imagerectangle($image, 40, 40, self::PAGE_W - 41, self::PAGE_H - 41, $ink);
        imagesetthickness($image, 1);
        imagerectangle($image, 48, 48, self::PAGE_W - 49, self::PAGE_H - 49, $line);

        $first = $pageIndex === 0;
        $last = $pageIndex === $pageCount - 1;
        if ($first) {
            $tableTop = $this->paintMasthead($image, $document, $titleFont, $bodyFont, $ink, $muted, $line);
        } else {
            $tableTop = $this->paintRunningHead($image, $document, $titleFont, $bodyFont, $ink, $muted, $line, $pageIndex, $pageCount);
        }
        $cursor = $this->paintTable($image, $rows, $tableTop, $bodyFont, $ink, $muted, $line, $head, $white);
        if ($last) {
            $this->paintTotals($image, $document, $cursor + 28, $bodyFont, $titleFont, $ink, $muted, $line, $head);
        } else {
            $this->label($image, 20, self::RIGHT, 1668, $muted, $bodyFont, (string)__('续下页'), true);
        }
        $this->paintFooter($image, $bodyFont, $muted, $pageIndex, $pageCount);

        return $this->jpeg($image);
    }

    private function paintMasthead(
        \GdImage $image,
        BuyerInvoiceDocument $document,
        string $titleFont,
        string $bodyFont,
        int $ink,
        int $muted,
        int $line,
    ): int {
        $this->label($image, 34, self::LEFT, 128, $ink, $titleFont, $document->sellerName, false);
        $docTitle = (string)__('订单发票');
        $this->label($image, 40, self::RIGHT, 124, $ink, $titleFont, $docTitle, true);
        if (preg_match('/\p{Han}/u', $docTitle) === 1) {
            $this->label($image, 16, self::RIGHT, 156, $muted, $bodyFont, 'ORDER INVOICE', true);
        }
        imagefilledrectangle($image, self::LEFT, 184, self::RIGHT, 188, $ink);
        imageline($image, self::LEFT, 196, self::RIGHT, 196, $line);

        $this->label($image, 16, self::LEFT, 230, $muted, $bodyFont, (string)__('发票号'), false);
        $this->label($image, 22, self::LEFT, 262, $ink, $bodyFont, $document->invoiceNumber, false);
        $this->metaCell($image, self::LEFT, 310, (string)__('订单号'), $document->orderNumber, $bodyFont, $ink, $muted);
        $this->metaCell($image, 470, 310, (string)__('币种'), strtoupper(trim($document->currency)) ?: 'CNY', $bodyFont, $ink, $muted);
        $this->metaCell($image, 760, 310, (string)__('开票日期'), $document->issuedAt, $bodyFont, $ink, $muted);

        $sellerLines = $this->partyLines([
            [(string)__('纳税人识别号'), $document->sellerTaxId],
            [(string)__('公司地址'), $document->sellerAddress],
            [(string)__('公司电话'), $document->sellerPhone],
            [(string)__('公司邮箱'), $document->sellerEmail],
        ]);
        $buyerLines = $this->partyLines([
            [(string)__('联系方式'), $document->buyerContact],
            [(string)__('电话'), $document->buyerPhone],
            [(string)__('公司'), $document->buyerCompany],
            [(string)__('税号'), $document->buyerTaxId],
        ]);
        $boxTop = 400;
        $boxHeight = 300;
        $gap = 24;
        $boxWidth = (int)((self::RIGHT - self::LEFT - $gap) / 2);
        $this->partyBox($image, self::LEFT, $boxTop, $boxWidth, $boxHeight, (string)__('销售方'), $document->sellerName, $sellerLines, $titleFont, $bodyFont, $ink, $muted, $line);
        $this->partyBox($image, self::LEFT + $boxWidth + $gap, $boxTop, $boxWidth, $boxHeight, (string)__('购买方'), $document->buyerName !== '' ? $document->buyerName : '—', $buyerLines, $titleFont, $bodyFont, $ink, $muted, $line);

        return $this->paintOrderFacts($image, $document, $bodyFont, $ink, $muted, $line, $boxTop + $boxHeight + 20);
    }

    private function paintOrderFacts(
        \GdImage $image,
        BuyerInvoiceDocument $document,
        string $font,
        int $ink,
        int $muted,
        int $line,
        int $top,
    ): int {
        $ship = trim($document->shipTo !== '' ? $document->shipTo : $document->buyerAddress);
        $bill = trim($document->billTo);
        $y = $top;
        $gap = 24;
        $full = self::RIGHT - self::LEFT;
        $half = (int)(($full - $gap) / 2);
        if ($ship !== '' || $bill !== '') {
            if ($bill !== '') {
                $this->factBox($image, self::LEFT, $y, $half, 120, (string)__('收货地址'), $ship !== '' ? $ship : '—', $font, $ink, $muted, $line);
                $this->factBox($image, self::LEFT + $half + $gap, $y, $half, 120, (string)__('账单地址'), $bill, $font, $ink, $muted, $line);
            } else {
                $this->factBox($image, self::LEFT, $y, $full, 120, (string)__('收货地址'), $ship, $font, $ink, $muted, $line);
            }
            $y += 136;
            if ($document->billSameAsShip) {
                $this->label($image, 16, self::LEFT, $y, $muted, $font, (string)__('账单地址与收货地址相同'), false);
                $y += 28;
            }
        }
        if ($document->paymentMethod !== '' || $document->paymentStatus !== '') {
            $this->label($image, 16, self::LEFT, $y + 8, $muted, $font, (string)__('支付方式'), false);
            $this->label($image, 20, self::LEFT + 110, $y + 8, $ink, $font, $document->paymentMethod !== '' ? $document->paymentMethod : '—', false);
            $this->label($image, 16, 700, $y + 8, $muted, $font, (string)__('支付状态'), false);
            $this->label($image, 20, 820, $y + 8, $ink, $font, $document->paymentStatus !== '' ? $document->paymentStatus : '—', false);
            $y += 40;
        }

        return $y + 12;
    }

    private function factBox(
        \GdImage $image,
        int $x,
        int $y,
        int $width,
        int $height,
        string $caption,
        string $text,
        string $font,
        int $ink,
        int $muted,
        int $line,
    ): void {
        imagefilledrectangle($image, $x, $y, $x + $width, $y + $height, $this->color($image, 255, 255, 255));
        imagerectangle($image, $x, $y, $x + $width, $y + $height, $line);
        $this->label($image, 16, $x + 20, $y + 32, $muted, $font, $caption, false);
        $cursor = $y + 64;
        foreach (array_slice($this->wrap($text, $font, 18, $width - 40), 0, 2) as $piece) {
            $this->label($image, 18, $x + 20, $cursor, $ink, $font, $piece, false);
            $cursor += 28;
        }
    }

    private function paintRunningHead(
        \GdImage $image,
        BuyerInvoiceDocument $document,
        string $titleFont,
        string $bodyFont,
        int $ink,
        int $muted,
        int $line,
        int $pageIndex,
        int $pageCount,
    ): int {
        $this->label($image, 28, self::LEFT, 110, $ink, $titleFont, (string)__('订单发票'), false);
        $this->label($image, 20, self::RIGHT, 108, $muted, $bodyFont, $document->invoiceNumber . '  ' . ($pageIndex + 1) . '/' . $pageCount, true);
        imagefilledrectangle($image, self::LEFT, 132, self::RIGHT, 135, $ink);

        return 180;
    }

    /**
     * @param list<array{label:string,value:string}> $lines
     */
    private function partyBox(
        \GdImage $image,
        int $x,
        int $y,
        int $width,
        int $height,
        string $caption,
        string $name,
        array $lines,
        string $titleFont,
        string $bodyFont,
        int $ink,
        int $muted,
        int $line,
    ): void {
        imagefilledrectangle($image, $x, $y, $x + $width, $y + $height, $this->color($image, 255, 255, 255));
        imagerectangle($image, $x, $y, $x + $width, $y + $height, $line);
        $this->label($image, 16, $x + 24, $y + 36, $muted, $bodyFont, $caption, false);
        $this->label($image, 26, $x + 24, $y + 78, $ink, $titleFont, $this->clip($name, $bodyFont, 26, $width - 48), false);
        $cursor = $y + 118;
        foreach (array_slice($lines, 0, 4) as $row) {
            $this->label($image, 16, $x + 24, $cursor, $muted, $bodyFont, $row['label'], false);
            $cursor += 26;
            foreach (array_slice($this->wrapSoft($row['value'], $bodyFont, 16, $width - 48), 0, 2) as $piece) {
                $this->label($image, 16, $x + 24, $cursor, $ink, $bodyFont, $piece, false);
                $cursor += 28;
            }
            $cursor += 6;
        }
    }

    /**
     * @param list<array{0:string,1:string}> $pairs
     * @return list<array{label:string,value:string}>
     */
    private function partyLines(array $pairs): array
    {
        $lines = [];
        foreach ($pairs as [$label, $value]) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $lines[] = ['label' => $label, 'value' => $value];
        }

        return $lines;
    }

    /**
     * @param list<array{name:string,qty:string,price:string,total:string,nameLines:list<string>,height:int}> $rows
     */
    private function paintTable(
        \GdImage $image,
        array $rows,
        int $top,
        string $font,
        int $ink,
        int $muted,
        int $line,
        int $head,
        int $white,
    ): int {
        $header = 52;
        imagefilledrectangle($image, self::LEFT, $top, self::RIGHT, $top + $header, $head);
        imagerectangle($image, self::LEFT, $top, self::RIGHT, $top + $header, $line);
        $baseline = $top + 34;
        $this->label($image, 18, 112, $baseline, $muted, $font, (string)__('序号'), false);
        $this->label($image, 18, 180, $baseline, $muted, $font, (string)__('商品'), false);
        $this->label($image, 18, 760, $baseline, $muted, $font, (string)__('数量'), true);
        $this->label($image, 18, 960, $baseline, $muted, $font, (string)__('单价'), true);
        $this->label($image, 18, self::RIGHT - 16, $baseline, $muted, $font, (string)__('金额'), true);

        $y = $top + $header;
        $index = 1;
        foreach ($rows as $row) {
            $bottom = $y + $row['height'];
            imagefilledrectangle($image, self::LEFT, $y, self::RIGHT, $bottom, $white);
            imageline($image, self::LEFT, $bottom, self::RIGHT, $bottom, $line);
            $textY = $y + 40;
            $this->label($image, 22, 124, $textY, $muted, $font, (string)$index, false);
            foreach ($row['nameLines'] as $pieceIndex => $piece) {
                $this->label($image, 24, 180, $textY + ($pieceIndex * 32), $ink, $font, $piece, false);
            }
            if (($row['sku'] ?? '') !== '') {
                $this->label($image, 16, 180, $textY + (count($row['nameLines']) * 32), $muted, $font, $row['sku'], false);
            }
            $this->label($image, 22, 760, $textY, $ink, $font, $row['qty'], true);
            $this->label($image, 22, 960, $textY, $ink, $font, $row['price'], true);
            $this->label($image, 22, self::RIGHT - 16, $textY, $ink, $font, $row['total'], true);
            $y = $bottom;
            $index++;
        }
        imagerectangle($image, self::LEFT, $top, self::RIGHT, $y, $line);

        return $y;
    }

    private function paintTotals(
        \GdImage $image,
        BuyerInvoiceDocument $document,
        int $top,
        string $bodyFont,
        string $titleFont,
        int $ink,
        int $muted,
        int $line,
        int $head,
    ): void {
        $x = 760;
        $cursor = $top;
        $rows = [
            [(string)__('小计'), $document->subtotalLabel !== '' ? $document->subtotalLabel : $document->totalLabel],
            [(string)__('运费'), $document->shippingLabel !== '' ? $document->shippingLabel : BuyerInvoiceDocument::money(0, $document->currency)],
        ];
        if ($document->discountLabel !== '') {
            $rows[] = [(string)__('优惠'), $document->discountLabel];
        }
        $rows[] = [(string)__('税额'), $document->taxLabel];
        foreach ($rows as $row) {
            $this->label($image, 20, $x, $cursor + 28, $muted, $bodyFont, $row[0], false);
            $this->label($image, 22, self::RIGHT - 16, $cursor + 28, $ink, $bodyFont, $row[1], true);
            $cursor += 36;
        }
        imageline($image, $x, $cursor + 8, self::RIGHT, $cursor + 8, $line);
        imagefilledrectangle($image, $x, $cursor + 20, self::RIGHT, $cursor + 88, $head);
        $this->label($image, 20, $x + 16, $cursor + 64, $muted, $bodyFont, (string)__('合计'), false);
        $this->label($image, 28, self::RIGHT - 16, $cursor + 66, $ink, $titleFont, $document->totalLabel, true);
    }

    private function paintFooter(\GdImage $image, string $font, int $muted, int $pageIndex, int $pageCount): void
    {
        $note = (string)__('此页为该订单已开具发票的阅读副本，不是增值税发票，金额以订单结算为准。');
        $this->label($image, 16, self::LEFT, 1664, $muted, $font, $note, false);
        if ($pageCount > 1) {
            $this->label($image, 16, self::RIGHT, 1664, $muted, $font, ($pageIndex + 1) . ' / ' . $pageCount, true);
        }
    }

    private function metaCell(
        \GdImage $image,
        int $x,
        int $y,
        string $caption,
        string $value,
        string $font,
        int $ink,
        int $muted,
    ): void {
        $this->label($image, 16, $x, $y, $muted, $font, $caption, false);
        $this->label($image, 22, $x, $y + 36, $ink, $font, $this->clip($value, $font, 22, 340), false);
    }

    private function label(
        \GdImage $image,
        int $size,
        int $x,
        int $baseline,
        int $color,
        string $font,
        string $text,
        bool $right,
    ): void {
        if ($text === '') {
            return;
        }
        if ($right) {
            $x -= $this->textWidth($text, $font, $size);
        }
        $written = imagettftext($image, $size, 0, $x, $baseline, $color, $font, $text);
        if ($written === false) {
            throw new BuyerInvoiceException(
                'buyer_invoice_font_unavailable',
                500,
                (string)__('服务器缺少可用于发票的中文字体'),
            );
        }
    }

    private function clip(string $text, string $font, int $size, int $maxWidth): string
    {
        $lines = $this->wrap($text, $font, $size, $maxWidth);

        return $lines[0] ?? '';
    }

    private function textWidth(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if (!is_array($box)) {
            return 0;
        }

        return abs((int)$box[2] - (int)$box[0]);
    }

    private function color(\GdImage $image, int $red, int $green, int $blue): int
    {
        $color = imagecolorallocate($image, $red, $green, $blue);
        if ($color === false) {
            throw new \RuntimeException('buyer_invoice_pdf_canvas');
        }

        return $color;
    }

    private function jpeg(\GdImage $image): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            imagedestroy($image);
            throw new \RuntimeException('buyer_invoice_pdf_canvas');
        }
        imagejpeg($image, $stream, 92);
        imagedestroy($image);
        rewind($stream);
        $jpeg = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($jpeg) || $jpeg === '') {
            throw new \RuntimeException('buyer_invoice_pdf_canvas');
        }

        return $jpeg;
    }

    /** @return list<string> */
    private function wrapSoft(string $text, string $font, int $size, int $maxWidth): array
    {
        $parts = preg_split('/(?=@)/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || count($parts) < 2) {
            return $this->wrap($text, $font, $size, $maxWidth);
        }
        $lines = [];
        $current = '';
        foreach ($parts as $part) {
            $try = $current . $part;
            if ($this->textWidth($try, $font, $size) > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = ltrim($part);
                continue;
            }
            $current = $try;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? $this->wrap($text, $font, $size, $maxWidth) : $lines;
    }

    /** @return list<string> */
    private function wrap(string $text, string $font, int $size, int $maxWidth): array
    {
        $text = trim($text);
        if ($text === '') {
            return [''];
        }
        $lines = [];
        $current = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            return [$text];
        }
        foreach ($chars as $char) {
            $try = $current . $char;
            $box = imagettfbbox($size, 0, $font, $try);
            $width = is_array($box) ? abs((int)$box[2] - (int)$box[0]) : $maxWidth + 1;
            if ($width > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $char;
                continue;
            }
            $current = $try;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }
}
