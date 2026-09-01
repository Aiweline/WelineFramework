<?php

declare(strict_types=1);

namespace Weline\MediaManager\FileManager;

use Weline\FileManager\Api\FileManager;

class WelineMedia extends FileManager
{
    public static function name(): string
    {
        return 'weline_media';
    }

    public function getConnector(array $params = []): string
    {
        if (!$params) {
            $params = $this->getData();
        }
        // Taglib/Block picker iframe must open the manager UI (not the JSON connector).
        return $this->request->getUrlBuilder()->getBackendUrl('media/backend/manager/iframe', $params, true);
    }

    public function render(): string
    {
        $this->setData('class', \Weline\MediaManager\Block\WelineMedia::class);
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
