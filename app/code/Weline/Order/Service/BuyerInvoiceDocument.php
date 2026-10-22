<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * 一张已开具发票的可读投影。不另存发票。
 */
final class BuyerInvoiceDocument
{
    /**
     * @param list<array{name:string,qty:string,price:string,total:string}> $lines
     */
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly string $issuedAt,
        public readonly string $orderNumber,
        public readonly string $currency,
        public readonly string $sellerName,
        public readonly string $sellerTaxId,
        public readonly string $sellerAddress,
        public readonly string $sellerPhone,
        public readonly string $sellerEmail,
        public readonly string $buyerName,
        public readonly string $buyerContact,
        public readonly string $buyerAddress,
        public readonly array $lines,
        public readonly string $taxLabel,
        public readonly string $totalLabel,
        public readonly string $buyerPhone = '',
        public readonly string $buyerCompany = '',
        public readonly string $buyerTaxId = '',
        public readonly string $shipTo = '',
        public readonly string $billTo = '',
        public readonly bool $billSameAsShip = false,
        public readonly string $subtotalLabel = '',
        public readonly string $shippingLabel = '',
        public readonly string $discountLabel = '',
        public readonly string $paymentMethod = '',
        public readonly string $paymentStatus = '',
    ) {
    }

    /** @return list<string> */
    public function plainLines(): array
    {
        $rows = [
            (string)__('订单发票'),
            (string)__('发票号') . ' ' . $this->invoiceNumber,
            (string)__('订单号') . ' ' . $this->orderNumber,
            (string)__('开票日期') . ' ' . $this->issuedAt,
            (string)__('销售方') . ' ' . $this->sellerName,
        ];
        if ($this->sellerTaxId !== '') {
            $rows[] = (string)__('纳税人识别号') . ' ' . $this->sellerTaxId;
        }
        if ($this->sellerAddress !== '') {
            $rows[] = (string)__('公司地址') . ' ' . $this->sellerAddress;
        }
        if ($this->sellerPhone !== '') {
            $rows[] = (string)__('公司电话') . ' ' . $this->sellerPhone;
        }
        if ($this->sellerEmail !== '') {
            $rows[] = (string)__('公司邮箱') . ' ' . $this->sellerEmail;
        }
        $rows[] = (string)__('购买方') . ' ' . $this->buyerName;
        if ($this->buyerContact !== '') {
            $rows[] = $this->buyerContact;
        }
        if ($this->buyerAddress !== '') {
            $rows[] = $this->buyerAddress;
        }
        $rows[] = (string)__('商品') . '  ' . (string)__('数量') . '  ' . (string)__('单价') . '  ' . (string)__('金额');
        foreach ($this->lines as $line) {
            $rows[] = $line['name'] . '  ' . $line['qty'] . '  ' . $line['price'] . '  ' . $line['total'];
        }
        if ($this->subtotalLabel !== '') {
            $rows[] = (string)__('小计') . ' ' . $this->subtotalLabel;
        }
        if ($this->shippingLabel !== '') {
            $rows[] = (string)__('运费') . ' ' . $this->shippingLabel;
        }
        if ($this->discountLabel !== '') {
            $rows[] = (string)__('优惠') . ' ' . $this->discountLabel;
        }
        $rows[] = (string)__('税额') . ' ' . $this->taxLabel;
        $rows[] = (string)__('合计') . ' ' . $this->totalLabel;

        return $rows;
    }

    public static function money(int $minor, string $currency): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);
        $code = strtoupper(trim($currency)) ?: 'CNY';

        return $sign . $code . ' ' . number_format($minor / 100, 2, '.', '');
    }
}
