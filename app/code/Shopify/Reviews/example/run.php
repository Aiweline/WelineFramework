<?php
// 使用composer loader
require __DIR__.'/../../../../vendor/autoload.php';

# 读取评论数据 review-upload11.3.xls 使用composer phpoffice组件
$reviews = [];
$file = __DIR__.'/review-upload11.3.xls';
\PhpOffice\PhpSpreadsheet\IOFactory::registerReader('Xlsx', \PhpOffice\PhpSpreadsheet\Reader\Xlsx::class);
$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
$sheet = $spreadsheet->getSheet(0);
$highestRow = $sheet->getHighestRow();
$headers = $sheet->rangeToArray('A' . 1 . ':' . $sheet->getHighestColumn() . 1, NULL, TRUE, FALSE)[0];
for ($row = 2; $row <= $highestRow; $row++) {
    $data = $sheet->rangeToArray('A' . $row . ':' . $sheet->getHighestColumn() . $row, NULL, FALSE, true);
    if(empty($data[0])) {
        continue;
    }
    if(empty($data[0][6])) {
        continue;
    }
    $review_item = [];
    foreach ($headers as $key=>$header) {
        $review_item[$header] = $data[0][$key]??'';
    }
    $reviews[] = $review_item;
}
var_dump('评论：'.count($reviews));

# 读取订单
$file = __DIR__.'/TOP订单记录.csv';
$orders = [];

$handle = fopen($file, "r");
$orderHeaders = [];
while (($data = fgetcsv($handle)) !== FALSE) {
    if($data['0'] == 'Name') {
        $orderHeaders = $data;
        continue;
    }
    foreach ($orderHeaders as $key=>$header) {
        $data[$header] = $data[$key]??'';
    }
    $orders[$data['Name']] = $data;
}
fclose($handle);
var_dump('订单：'.count($orders));

# 计算每个SKU的购买数量
$skuSaleNumbers = [];
foreach ($orders as $order) {
    if(!isset($skuSaleNumbers[$order['Lineitem sku']])){
        $skuSaleNumbers[$order['Lineitem sku']] = [
            'sku'=>$order['Lineitem sku'],
            'qty'=>(int)($order['Lineitem quantity']),
            'name'=>$order['Lineitem name'],
            'price'=>$order['Lineitem price'],
            'buyer'=>$order['Email'],
            'total'=>$order['Total'],
            'paid_at'=>$order['Paid at'],
        ];
    }else{
        $skuSaleNumbers[$order['Lineitem sku']]['qty'] += (int)($order['Lineitem quantity']);
    }
}
# $skuSaleNumbers 导出sku销量excel 并计算 sku 销量
$headers = ['sku','qty','name','price','buyer','total','paid_at'];
$sku_sale_numbers[] = $headers;
$handle = fopen(__DIR__.'/sku_sale_numbers_SJ.csv', 'w');
fputcsv($handle, $headers);
foreach ($skuSaleNumbers as $skuSaleNumber) {
    $sku_sale_numbers[$skuSaleNumber['sku']] = $skuSaleNumber['qty'];
    fputcsv($handle, $skuSaleNumber);
}
fclose($handle);
var_dump('SKU购买数量：'.count($skuSaleNumbers));

$pr = 'SJ';
// 读取products_export_1 SJ.csv
$file_handle = fopen(__DIR__.'/products_export_'.$pr.'.csv', 'r');
// 提取产品handle
$handles = [];
$headers = [];
while ($content = fgetcsv($file_handle)) {
    if($content['0'] == 'handle' or $content['0'] == 'Handle') {
        $headers = $content;
        continue;
    }
    foreach ($headers as $key=>$header) {
        $handles[$content['0']][$header] = $content[$key];
    }
}
fclose($file_handle);
var_dump('产品：'.count($handles));

// 找出有销售的SKU的handle

$salesSkus = array_keys($sku_sale_numbers);
$handlesSaleQty = [];
foreach ($handles as $handle=>$handleData) {
    if(in_array($handleData['Variant SKU'], $salesSkus)) {
        $handlesSaleQty[$handle] = $sku_sale_numbers[$handleData['Variant SKU']];
    }else{
        $handlesSaleQty[$handle] = 0;
    }
}
//dd($handlesSaleQty);

# 为每个handle分配评论
$handleReviews = [];
$max = ceil(count($reviews)/count($handles));
while (!empty($reviews)) {
    $handle = array_shift($handles);
    if(isset($handlesSaleQty[$handle['Handle']])) {
        $max += $handlesSaleQty[$handle['Handle']];
    }
    # 随机截取几条评论
    $reviewsItems = array_slice($reviews, 0, rand(1,$max));
    $handle_reviews_item = [];
    foreach ($reviewsItems as $review) {
        $handle_reviews_item[] = $review;
        array_shift($reviews);
    }

    if(!empty($handle)) {
        $handleReviews[$handle['Handle']] = $handle_reviews_item;
    }
}

# 读取评论demo格式
$file = __DIR__.'/demo_reviews.csv';
$handle = fopen($file, "r");
$reviews = [];
$headers = [];
while (($data = fgetcsv($handle)) !== FALSE) {
    if($data['0'] == 'product_handle') {
        $headers = $data;
        break;
    }
}
fclose($handle);

# 为每个handle分配评论
$reviews_items = [];

$total = 0;
$batch = 500;
$index = 0;
foreach ($handleReviews as $handle=>$handleReview) {
    foreach ($handleReview as $item) {
        $total++;
        $file_index = ceil($total/$batch);
        $file = __DIR__.'/reviews_'.$pr.'_'.$file_index.'.csv';
        if(!isset($handle_file)) {
            $handle_file = fopen($file, "w");
        }
        fputcsv($handle_file,$headers);
//        dd($item);
        $defaultEmail = str_replace(' ','.',$item['Author']).'@example.com';
        fputcsv($handle_file,[
            'product_handle'=>$handle,
            'name'=>$item['Author'],
            'email'=>$item['Customer email']?:$defaultEmail,
            'rating'=>$item['Rating'],
            'customer_image'=>'',
            'review_title'=>substr($item['Text'],0,60),
            'review_body'=>$item['Text'],
            'reply'=>'',
            'date(yyyy-mm-dd)'=>$item['Date Added (YYYY-MM-DD)'],
            'review_image1'=>'',
            'review_image2'=>'',
            'review_image3'=>'',
            'review_image4'=>'',
            'review_image5'=>'',
        ]);

        if($index==0 or $file_index>$index) {
            fclose($handle_file);
            unset($handle_file);
            $index = $file_index;
        }
    }

}
# 为每一个sku分配
// products_export_top.csv

