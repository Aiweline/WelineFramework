<?php

namespace Shopify\Tools\Console;

use Weline\Framework\Console\CommandInterface;

class NoReviewProduct implements CommandInterface
{

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $review_file = __DIR__ . '/NoReviewProduct/reviews.csv';
        # 找出评论中有点Handle
        $review_handle = [];
        $handle = fopen($review_file, 'r');
        $i = 0;
        while ($review = fgetcsv($handle)) {
            # 删除第一个
            if($i == 0) {
                $i++;
                continue;
            }
            $review_handle[$review[0]] = $review[0];
        }

        # 读取产品Handle
        $product_file = __DIR__ . '/NoReviewProduct/products.csv';
        $product_handle = [];
        $handle = fopen($product_file, 'r');
        $i = 0;
        while ($product = fgetcsv($handle)) {
            # 删除第一个
            if($i == 0) {
                $i++;
                continue;
            }
            $product_handle[$product[0]] = $product[0];
        }
        # 取得差集
        $product_handle = array_diff($product_handle, $review_handle);

        # 写入文件
        $txt = fopen(__DIR__ . '/NoReviewProduct.txt', 'w');
        foreach ($product_handle as $handle) {
            fwrite($txt, $handle . "\n");
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '根据评论导出文件和导出的产品文件做对比，统计无评论的商品';
    }
}