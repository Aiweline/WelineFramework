<?php

namespace Shopify\Reviews\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\ObjectManager;

class AiGenerateReview implements CronTaskInterface
{

    /**
     * @inheritDoc
     */
    function name(): string
    {
        return 'AI生成评论';
    }

    /**
     * @inheritDoc
     */
    function execute_name(): string
    {
        return 'shopify_ai_generate_review';
    }

    /**
     * @inheritDoc
     */
    function tip(): string
    {
        return 'Shopify：每天午夜AI生成评论，供下载到Shopify导入。';
    }

    /**
     * @inheritDoc
     */
    function cron_time(): string
    {
        return '0 0 * * *';
    }

    /**
     * @inheritDoc
     */
    function execute(): string
    {
        return '不执行！';
        $url = 'https://www.aiweline.com/zh_Hans_CN/ai/v1/chat/completions?q=';
        $q = "
        生成邮票产品英文评论，评论中可以不出现邮票字样，可以简短可以最长达到60词。随机美国英语昵称。
        回答的结果中不需要引导语，直接给出json示例，以---json-start---开始，以---json-end---结束。
        正面要求：
        生成准确的json格式示例。
        
        负面案例：
        禁止对json字段进行注释
        
        例子：
        ---json-start---
        {
            \"name\": \"Lisa\",
            \"content\": \"I will buy a backpack and a hoodie for my trip.\"
        }
        ---json-end---
        ";
        $q = urlencode($q);
        $url .= $q;
        /**@var CacheInterface $cache */
        $cache = ObjectManager::getInstance(\Shopify\Reviews\Cache\Cache::class . 'Factory');
        $cache->setStatus(true);
        $key = 'shopify_ai_generate_review';
//        dd($cache->get($key));
        if (!$cache->get($key)) {
            $res = $cache->get($key);
        } else {
            $res = file_get_contents($url);
            $cache->set($key, $res);
        }
        $res = $this->getRes($res);
        if (empty($res)) {
            d('重试');
            $res = $this->execute();
        }
        dd($res);
        $res = $res['choices'][0]['message']['content'];
        return $res;
    }

    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 180;
    }

    /**
     * @param mixed $res
     * @return mixed
     */
    public function getRes(mixed $res): mixed
    {
        $exps = explode("\n", $res);
        $res = [];
        foreach ($exps as $exp) {
            $exp = str_replace("data: ", '', $exp);
            $exp = json_decode($exp, true);
            if (empty($exp)) {
                continue;
            }
            $exp = trim($exp['content']);
            if (empty($exp)) {
                continue;
            }
            $res[] = $exp;
        }
        $res = implode('', $res);
        # ---json-start---
        $res = explode('---json-start---', $res);
        $res = $res[1] ?? '';
        if (empty($res)) {
            return null;
        }
        $res = explode('---json-end---', $res);
        $res = $res[0] ?? "";
        if (empty($res)) {
            return null;
        }
        $res = str_replace("\t", '', $res);
        $res = json_decode($res, true);
        if ($res) {
            # 随机三天前的日期，区间在5天以内
            $res['date'] = date('Y-m-d', strtotime('-' . mt_rand(3, 8) . ' days'));
            # 写一段随机整数，随机评分，4-5分，5分概率90%，4分概率5%，3分概率1%，2分概率1%，1分概率1%
            $res['rating'] = $this->generateRandomRating();
        }
        return $res;
    }

    // 生成随机评分
    function generateRandomRating():int
    {
        $randomNumber = mt_rand(1, 100); // 生成1到100之间的随机数
        if ($randomNumber <= 90) {
            return 5; // 90%的概率返回5分
        } elseif ($randomNumber <= 95) {
            return 4; // 5%的概率返回4分
        } elseif ($randomNumber <= 96) {
            return 3; // 1%的概率返回3分
        } elseif ($randomNumber <= 97) {
            return 2; // 1%的概率返回2分
        } else {
            return 1; // 1%的概率返回1分
        }
    }
}