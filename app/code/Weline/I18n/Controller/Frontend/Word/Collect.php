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
                $this->dictionary::fields_WORD => $key,
                $this->dictionary::fields_IS_BACKEND => $this->request->isBackend() ? 1 : 0,
                $this->dictionary::fields_MODULE => $this->request->getModuleName(),
            ];
        }
        
        if (empty($insertData)) {
            $this->dictionary->rollBack();
            return $this->fetchJson($this->success(__('没有需要收集的翻译词')));
        }
        
        try {
            // Dictionary 表的主键是 word，使用 INSERT ... ON DUPLICATE KEY UPDATE
            // 第二个参数是 update_where_fields（判断记录是否存在的条件），第三个参数是 update_fields（要更新的字段）
            // 由于 word 是主键，如果 word 已存在，则更新 is_backend 和 module；否则插入新记录
            // 修复：需要先 reset() 清除之前的查询状态
            $this->dictionary->reset()
                ->insert($insertData, [
                    $this->dictionary::fields_ID,  // word 是主键，用于判断记录是否存在
                ], implode(',', [
                    $this->dictionary::fields_IS_BACKEND,
                    $this->dictionary::fields_MODULE,
                ]))
                ->fetch();  // 必须调用 fetch() 才会真正执行插入操作
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