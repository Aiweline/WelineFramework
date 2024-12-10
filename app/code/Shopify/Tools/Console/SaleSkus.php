<?php

namespace Shopify\Tools\Console;

use Weline\Framework\Console\CommandInterface;

class SaleSkus implements CommandInterface
{

    public function execute(array $args = [], array $data = []): void
    {
        # 扫描SaleSkus目录
        $base_dir = __DIR__ . '/SaleSkus';
        $file_handle = opendir($base_dir);
        $files = [];
        while (($file = readdir($file_handle)) !== false) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $files[$file] = $base_dir . '/' . $file;
        }
        # 循环统计SKU
        $sku_sales = [];
        $headers = [];
        foreach ($files as $file) {
            $file = fopen($file, 'r');
            while ($content = fgetcsv($file)) {
                if(!$headers and $content['0'] == 'Name') {
                    $headers = $content;
                    continue;
                }
                foreach ($headers as $key=>$header) {
                    $content[$header] = $content[$key];
                }
                $sku = $content['Lineitem sku'];
                $qty = $content['Lineitem quantity'];
                if(!isset($sku_sales[$sku])) {
                    $sku_sales[$sku] = [
                        'sku'=>$sku,
                        'qty'=>(int)$qty,
                        'name'=>$content['Lineitem name'],
                    ];
                }else{
                    $sku_sales[$sku]['qty'] += (int)$qty;
                }
            }
            fclose($file);
        }
        # 导出sku销量excel
        $excel = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $excel->getActiveSheet();
        $sheet->setTitle('sku销量');
        $sheet->setCellValue('A1', 'sku');
        $sheet->setCellValue('B1', 'qty');
        $sheet->setCellValue('C1', 'name');
        $index = 2;
        foreach ($sku_sales as $sku_sale) {
            $sheet->setCellValue('A'.$index, $sku_sale['sku']);
            $sheet->setCellValue('B'.$index, $sku_sale['qty']);
            $sheet->setCellValue('C'.$index, $sku_sale['name']);
            $index++;
        }
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($excel);
        $writer->save(__DIR__.'/sales.xlsx');
    }

    public function tip(): string
    {
        return '统计销售SKU';
    }
}