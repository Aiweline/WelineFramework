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
        $words = $this->request->getPost();
        foreach ($words as $key => $word) {
            unset($words[$key]);
            if($word)$words[] = [
                $this->dictionary::fields_WORD       => $key,
                $this->dictionary::fields_IS_BACKEND => $this->request->isBackend()?1:0,
                $this->dictionary::fields_MODULE     => $this->request->getModuleName(),
            ];
        }
        try {
            $this->dictionary->insert($words, [
                $this->dictionary::fields_ID,
                $this->dictionary::fields_IS_BACKEND,
                $this->dictionary::fields_MODULE,
            ])->fetch();
            $this->dictionary->commit();
            return $this->fetchJson($this->success(__('收集成功！一共收集更新词条：%1 个', count($this->request->getPost()))));
        } catch (\Exception $exception) {
            $this->dictionary->rollBack();
            return $this->fetchJson($this->error($exception->getMessage()));
        }
    }
}