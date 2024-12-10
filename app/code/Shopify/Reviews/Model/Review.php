<?php

namespace Shopify\Reviews\Model;

use Aiweline\Ai\AiVendorModel;
use Aiweline\Ai\Cache\CacheFactory;
use Random\RandomException;
use React\Cache\CacheInterface;
use Weline\Cron\Helper\CronStatus;
use Weline\Framework\Manager\ObjectManager;

class Review
{
    static array $headers = [];
    static mixed $file='';
    static function generate(array $args): string
    {
        self::$headers = self::getReviewHeaders();
        $name = $args['name']??null;
        if (!$name) {
            $name = 'demo';
        }
        try {
            self::extracted($name);
        } catch (RandomException $e) {
            dd($e->getMessage());
        }

        list($handles, $headers) = self::getHandles();

        # 截取10个
//        $handles = array_slice($handles, 0, 5);

        # 上次处理位置
        $process_handle = self::getProcess($name,'handle');
        # 为每个handle分配评论
        $max = 8;
        $index = 0;
        $review_count = self::getProcess($name,'review_count');
        if(empty($review_count)){
            $review_count = 0;
        }
        $go = false;
        if(empty($process_handle)){
            $go = true;
        }
        if(self::getProcess($name,'ok')){
            echo '完成！';
        }
        while (!empty($handles)) {
            $index++;
            $handle = array_shift($handles);
            if (empty($handle)) {
                # 设置进度
                self::setProcess($name,[
                    'count'=>$index,
                ]);
                continue;
            }
            if(!$go and $process_handle){
                if($process_handle !== $handle['Handle']){
                    echo '跳过：'.$handle['Handle'].PHP_EOL;
                    continue;
                }else{
                    $go = true;
                }
            }
            if(!$go){
                continue;
            }
            # 设置进度
            self::setProcess($name,[
                'handle'=>$handle['Handle'],
                'count'=>$index,
            ]);
            # 随机生成评论
            $number = ceil(rand(1, $max));
            CronStatus::displayProgressBar('评论：（Handle:'.$handle['Handle'].')', $index, count($handles));
            $error_total = 0;
            for ($i = 0; $i < $number; $i++) {
                CronStatus::displayProgressBar(' N:'.$number.' 第' . ($i + 1) . '条评论',($i + 1), $number);
                $try_number = 8;
                $review = self::getHandleReviews($handle, $try_number);
                if($review){
                    $review_count++;
                    self::write($handle, $review);
                    self::setProcess($name,[
                        'handle'=>$handle['Handle'],
                        'count'=>$index,
                        'review_count'=>$review_count,
                    ]);
                }else{
                    $error_total++;
                    CronStatus::displayProgressBar(' N:'.$number.' 第' . ($i + 1) . '条评论 获取失败！',($i + 1), $number);
                }
                if($error_total>=2){
                    dd('接口可能出现限额，程序终止！');
                }
            }
        }
        $file = stream_get_meta_data(self::$file)['uri'];
        fclose(self::$file);
        self::setProcess($name,'ok',1);
        return $file;
    }

        /**
         * @return array
         */
    public  static function getHandles(): array
    {
        $base_dir = __DIR__ . '/Review';
        // 读取products_export_1 SJ.csv
        $file_handle = fopen(realpath($base_dir . '/reviews_products.csv'), 'r');
        // 提取产品handle
        $handles = [];
        $headers = [];
        while ($content = fgetcsv($file_handle)) {
            if ($content['0'] == 'handle' or $content['0'] == 'Handle') {
                $headers = $content;
                continue;
            }
            foreach ($headers as $key => $header) {
                $handles[$content['0']][$header] = $content[$key];
            }
        }
        fclose($file_handle);
        return array($handles, $headers);
    }

    /**
     * @return array
     */
    public static function getReviewHeaders(): array
    {
        # 读取评论demo格式
        $file = realpath(__DIR__ . '/Review/reviews_example.csv');
        $handle = fopen($file, "r");
        $headers = [];
        while (($data = fgetcsv($handle)) !== FALSE) {
            if ($data['0'] == 'product_handle') {
                $headers = $data;
                break;
            }
        }
        fclose($handle);
        return $headers;
    }

    private static function getHandleReviews(array $handle, int &$try_number): array
    {
        $try_number--;
        if ($try_number <= 0) {
            return [];
        }
        # 调用AI
        /**
         * @var AiVendorModel $chat
         */
//        $chat = ObjectManager::getInstance(AiVendorModel::class);
        $question = "你是一个评价达人，给定一个产品名为，【{$handle['Handle']}】，请生成英文评论
    建议：
    评论模拟真人
    大多数时候评论都比较短（1-3个词的评论占20%,3-10个词占40%,10-40个词占30%,40个词以上占10%），
    评论分数大多数在4-5分，少量出现3-4分，极少出现2-3分，没有1-2分。
    总共五分，评分越低，评论越负面。
    收货时间快，正品，非常优惠
    评论的作者模拟真实客户名字，评论内容要真实，
    评论时间从2022年到2024年之间。
    评论内容真实，看起来不像机器人生成的。
    保证json有效
    
    负面事项：
    不要连续出现相似评论，不要连续出现相似作者，不要连续出现相似标题，
    不要总是对产品质量，邮递速度，和客户自己的喜爱评价都评论。
    不要出现相似评论。
    不要总是用Emily Smith作为作者，
    不要对json字段做任何注释
    不要漏掉任何json字段
    评论时产品标题不能出现在评论中
    评论中不要出现产品名称，评论中不要出现产品链接，评论中不要出现产品图片链接，评论中不要出现产品价格，评论中不要出现产品规格，
    评论中不要出现产品颜色，评论中不要出现产品尺寸，评论中不要出现产品型号，评论中不要出现产品品牌，评论中不要出现产品描述，
    评论中不要出现产品功能，评论中不要出现产品参数，评论中不要出现产品缺点，
    
    格式如下：
    在你给出json内容前用---json-start---开始，并以---json-end---结束，格式示例如下：";
        $question .= '{
    "Title": "Amazing product",
    "Author": "John Doe",
    "Customer email": "john.doe@example.com", 
    "Rating": "5", 
    "Date Added (YYYY-MM-DD)": "2022-01-01", 
    "Text": "This product is amazing! It works perfectly and I\'m very happy with my purchase. I would highly recommend it to anyone.",
    "中文翻译":"直接翻译Text的内容"
    }';

        # 请求地址获取AI回答
        /**
         * @var CacheInterface $cache
         */
        $cache = ObjectManager::getInstance(CacheFactory::class);
        $key = 'completions';
//        $res = $cache->get($key);
//        if (!$res) {
//            $url = 'https://www.aiweline.com/en_US/ai/v1/chat/completions?q=' . urlencode($question);
//            $res = file_get_contents($url);
//            $cache->set($key, $res, 60 * 60 * 24);
//        }
        $url = 'https://www.aiweline.com/en_US/ai/v1/chat/completions?q=' . urlencode($question);
        $res = file_get_contents($url);
        $cache->set($key, $res, 60 * 60 * 24);
        # 将事件流解析出来
        $contents = explode("\n", $res);
        $answer = '';
        foreach ($contents as $content) {
            $content = str_replace('data: ', '', $content);
            if (empty($content)) {
                continue;
            }
            $data = json_decode($content);
            if ($data) {
                $answer .= $data->content;
            }
        }
        # 将json内容解析出来
        if (empty($answer)) {
            return self::getHandleReviews($handle, $try_number);
        }
        $answer = explode('---json-start---', $answer);
        if (empty(isset($answer[1]))) {
            return self::getHandleReviews($handle, $try_number);
        }
        $answer = explode('---json-end---', $answer[1]);
        if (empty($answer[0])) {
            return self::getHandleReviews($handle, $try_number);
        }
        $answer[0] = str_replace("\n", '', $answer[0]);
        $answer = json_decode($answer[0], true);
        if (!is_array($answer)) {
            return self::getHandleReviews($handle, $try_number);
        }
        return $answer;
    }

    /**
     * @param array $handle
     * @param array $review
     * @return void
     */
    public static function write(array $handle,array $review): void
    {
        # 生成文件
        $trim_fields = [
            'Title',
            'Text',
            'Author',
        ];
        foreach ($trim_fields as $field) {
            if (isset($review[$field])) {
                $review[$field] = trim($review[$field],'\'');
                $review[$field] = trim($review[$field],'"');
            }
        }
        fputcsv(self::$file, [
            'product_handle' => $handle['Handle'],
            'name' => $review['Author'],
            'email' => $review['Customer email'],
            'rating' => $review['Rating'],
            'customer_image' => '',
            'review_title' => $review['Title'],
            'review_body' => $review['Text'],
            'reply' => '',
            'date(yyyy-mm-dd)' => $review['Date Added (YYYY-MM-DD)'],
            'review_image1' => '',
            'review_image2' => '',
            'review_image3' => '',
            'review_image4' => '',
            'review_image5' => '',
        ]);
    }

    /**
     * @param mixed $name
     * @return void
     */
    public static function extracted(string $name): void
    {
        $process_file = self::getProcess($name,'file');
        if(!$process_file or !is_file($process_file)){
            if($process_file){
                $file = $process_file;
            }else{
                $file = __DIR__ . '/../reviews_' . $name . '_' . date('Y-m-d-H-i-s') . '.csv';
            }
            if(!is_dir(dirname($file))){
                mkdir(dirname($file),0777,true);
            }
        }else{
            $file = $process_file;
        }

        $handle_file = fopen($file, "a");
        self::$file = $handle_file;
        # 如果文件是空的追加标题，就不添加
        $file_content = file_get_contents($file);
        if (empty($file_content)) {
            fputcsv(self::$file, self::$headers);
        }
    }

    public static function getProcess(string $name,string $key=''): array|string
    {
        # 进度文件
        $file = __DIR__ . '/../progress_' . $name . '.json';
        if(!is_dir(dirname($file))){
            mkdir(dirname($file),0777,true);
        }
        if(!is_file($file)){
            file_put_contents($file,'');
        }
        $json = json_decode(file_get_contents($file)?:'',true);
        if (empty($json) or (($json['ok']??0)==1)) {
            $json =  [
                'file'=>'',
                'handle'=>'',
                'ok'=>0,
                'count'=>0,
            ];
            file_put_contents($file,json_encode($json));
        }
        if(is_resource(self::$file)){
            $json['file'] = stream_get_meta_data(self::$file)['uri'];
        }
        if (!empty($key)) {
            return $json[$key]??'';
        }
        return $json;
    }
    public static function setProcess(string $name,string|array $key,string $value=''): array
    {
        # 进度文件
        $file = __DIR__ . '/../progress_' . $name . '.json';
        if(!is_dir(dirname($file))){
            mkdir(dirname($file),0777,true);
        }
        if(!is_file($file)){
            file_put_contents($file,'');
        }
        $json = json_decode(file_get_contents($file)?:'',true);
        if (empty($json)) {
            $json =  [
                'file'=>'',
                'handle'=>'',
                'count'=>0,
            ];
        }
        if(empty($json['file']) and is_resource(self::$file)){
            $json['file'] = stream_get_meta_data(self::$file)['uri'];
        }
        if(is_array($key)){
            foreach ($key as $k=>$v){
                $json[$k] = $v;
            }
        }else{
            $json[$key] = $value;
        }
        file_put_contents($file,json_encode($json));
        # 如果完成就拷贝一份，并删除进度文件
        if(($json['ok']??0)==1){
            copy($file,str_replace('.json','_finished.json',$file));
            unlink($file);
        }
        return $json;
    }
}