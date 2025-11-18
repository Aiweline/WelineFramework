<?php
declare(strict_types=1);

/*
 * 获取指定词的翻译
 * 用于按需加载当前页面实际使用的翻译词
 */

namespace Weline\I18n\Controller\Frontend\Word;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Phrase\Parser;

class GetTranslations extends FrontendController
{
    /**
     * 获取指定词的翻译
     * POST 请求，JSON 格式：{"words": ["词1", "词2", ...]}
     */
    function post()
    {
        // 获取请求的翻译词列表
        $rawInput = file_get_contents('php://input');
        $words = [];
        
        if (!empty($rawInput)) {
            $jsonData = json_decode($rawInput, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                $words = $jsonData['words'] ?? [];
            } else {
                $words = $this->request->getPost('words', []);
            }
        } else {
            $words = $this->request->getPost('words', []);
        }
        
        if (empty($words) || !is_array($words)) {
            return $this->fetchJson($this->success('', ['translations' => []]));
        }
        
        // 获取当前语言的翻译
        $allWords = Parser::getWords();
        $translations = [];
        
        foreach ($words as $word) {
            if (is_string($word) && isset($allWords[$word])) {
                $translations[$word] = $allWords[$word];
            } else {
                // 如果没有翻译，使用原词
                $translations[$word] = $word;
            }
        }
        
        return $this->fetchJson($this->success('', ['translations' => $translations]));
    }
}

