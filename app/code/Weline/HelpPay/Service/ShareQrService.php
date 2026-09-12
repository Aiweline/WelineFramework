<?php

declare(strict_types=1);

namespace Weline\HelpPay\Service;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/** Builds PNG bytes for share URLs (QR payload = URL only, never shipping PII). */
final class ShareQrService
{
    public function pngDataUri(string $url, int $size = 240): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('helppay_qr_url_invalid');
        }
        $qrCode = new QrCode(
            data: $url,
            size: $size,
            margin: 8,
        );
        $result = (new PngWriter())->write($qrCode);

        return 'data:image/png;base64,' . base64_encode($result->getString());
    }
}
