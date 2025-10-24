<?php

namespace Weline\ElFinderFileManager\FileManager;

use Weline\FileManager\FileManager;
use Weline\Framework\Http\Request;

class ElFinder extends FileManager
{

    public static function name(): string
    {
        return 'elfinder';
    }

    public function getConnector(array $params = []):string
    {
        if(!$params){
            $params = $this->getData();
        }
        return $this->request->getUrlBuilder()->getBackendUrl('elfinder/backend/connector/manager', $params, true);
    }

    /**
     * @inheritDoc
     */
    public function render(): string
    {
        # 分配Block
        $this->setData('class', \Weline\ElFinderFileManager\Block\ElFinder::class);
        // 变量导入
        $attributes = $this->getData('attributes');
        $vars_string = '[';
        if (isset($attributes['vars'])) {
            $vars = explode('|', $attributes['vars']);
            foreach ($vars as $key => $var) {
                $var_name = trim($var);
                $var = '$' . $var_name;
                $vars_string .= "'$var_name'=>&$var,";
            }
        }
        $vars_string .= ']';
        return '<?php echo framework_view_process_block(' . w_var_export($this->getData(), true) . ',$vars=' . $vars_string . ');?>';
    }
}
