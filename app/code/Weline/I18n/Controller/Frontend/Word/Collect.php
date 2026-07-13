<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/2 00:22:16
 */

namespace Weline\I18n\Controller\Frontend\Word;

use Weline\Framework\App\Env;
use Weline\I18n\Model\Dictionary;

class Collect extends \Weline\Framework\App\Controller\FrontendController
{
    /**
     * @var \Weline\I18n\Model\Dictionary
     */
    private Dictionary $dictionary;

    function __construct(Dictionary $dictionary)
    {
        $this->dictionary = $dictionary;
    }

    function post()
    {
        $this->dictionary->beginTransaction();
        
        // 尝试从 JSON 格式获取数据（避免超过 max_input_vars 限制）
        $rawInput = file_get_contents('php://input');
        $words = [];
        
        if (!empty($rawInput)) {
            // 尝试解析 JSON
            $jsonData = json_decode($rawInput, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                // 使用 JSON 数据
                $words = $jsonData;
            } else {
                // 如果不是 JSON，尝试从 POST 获取（兼容旧方式）
                $words = $this->request->getPost();
            }
        } else {
            // 如果没有原始输入，从 POST 获取
            $words = $this->request->getPost();
        }
        
        // 转换数据格式
        $insertData = [];
        foreach ($words as $key => $word) {
            // 跳过非字符串键（如数字索引）
            if (!is_string($key) || empty($key)) {
                continue;
            }
            // word 可以是字符串或任意值，只要 key 存在就注册
            $insertData[] = [
                $this->dictionary::schema_fields_WORD => $key,
                $this->dictionary::schema_fields_IS_BACKEND => $this->request->isBackend() ? 1 : 0,
                $this->dictionary::schema_fields_MODULE => $this->request->getModuleName(),
            ];
        }
        
        if (empty($insertData)) {
            $this->dictionary->rollBack();
            return $this->fetchJson($this->success(__('没有需要收集的翻译词')));
        }
        
        try {
            // 逐词检查后再保存，兼容历史数据库中 word 尚未建立唯一约束的表。
            foreach ($insertData as $item) {
                $word = $item[$this->dictionary::schema_fields_WORD];
                $this->dictionary->reset()->load($this->dictionary::schema_fields_WORD, $word);
                if ($this->dictionary->getId()) {
                    $this->dictionary
                        ->setData($this->dictionary::schema_fields_IS_BACKEND, $item[$this->dictionary::schema_fields_IS_BACKEND])
                        ->setData($this->dictionary::schema_fields_MODULE, $item[$this->dictionary::schema_fields_MODULE])
                        ->save()
                        ->fetch();
                    continue;
                }
                $this->dictionary->reset()
                    ->insert($item, $this->dictionary::schema_fields_WORD)
                    ->fetch();
            }
            $this->dictionary->commit();
            return $this->fetchJson($this->success(__('收集成功！一共收集更新词条：%{1} 个', count($insertData))));
        } catch (\Exception $exception) {
            $this->dictionary->rollBack();
            // 记录详细错误信息，方便调试
            w_log_error('翻译词收集失败: ' . $exception->getMessage() . PHP_EOL . $exception->getTraceAsString(), [], 'i18n');
            return $this->fetchJson($this->error($exception->getMessage()));
        }
    }
}
