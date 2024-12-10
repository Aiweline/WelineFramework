<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Observer;

use JSMin\JSMin;
use Weline\Framework\Event\Event;
use Weline\Framework\View\Template;

class Compiler implements \Weline\Framework\Event\ObserverInterface
{
    public const area            = 'backend';
    public const require_js_file = 'base' . DS . 'require.configs.js';
    public const require_js_type = 'require.config.js';

    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        $eventData = $event->getEvenData();
        $area      = $eventData->getArea();
        if (self::area === $area) {
            $type = $eventData->getType();
            switch ($type):
                case self::require_js_type:
                    $path = dirname(__DIR__) . DS . 'view' . DS . 'statics' . DS . self::require_js_file;
                    if (!is_dir(dirname($path))) {
                        mkdir(dirname($path), 755, true);
                    }
                    if (!is_file($path)) {
                        touch($path);
                    }
                    file_put_contents($path, JSMin::minify($eventData->getResources()));
                    break;
                default:
            endswitch;
        };
    }

    protected string $generate_content = '';

    public function generateRequireConfigJsContent(array $resources): string
    {
        p(json_encode($resources));
        foreach ($resources as $key => $resource) {
            if (is_numeric($key)) {
                $this->generate_content .= '"' . $resource . '",';
            } elseif (is_string($resource)) {
                $this->generate_content .= $key . ':"' . $resource . '",';
            } elseif (is_array($resource)) {
                $this->generate_content .= $key . ':[';
                $this->generate_content = $this->generateRequireConfigJsContent($resource) . ',';
                $this->generate_content .= '],';
                /*foreach ($resource as $r_key => $r_item) {
                    if (is_array($r_item)) {
                        $this->generate_content = $this->generateRequireConfigJsContent($r_item) . ',';
                    } elseif (is_numeric($r_key)) {
                        $this->generate_content .= '"' . $r_item . '",';
                    } else {
                        $this->generate_content .= $r_key . ':"' . $r_item . '",';
                    }
                }*/
//                $this->generate_content .= '],';
            }
        }
        return $this->generate_content;
    }
    /*protected ?Template $template=null;
    function getTemplate(){
        if(!$this->template){
            $this->template=Template::getInstance()->init();
        }
        return $this->template;
    }*/
}
