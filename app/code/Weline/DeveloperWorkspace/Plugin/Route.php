<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：22/3/2024 13:20:43
 */

namespace Weline\DeveloperWorkspace\Plugin;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Core;

class Route extends Core
{
    function afterRoute(Core $core, &$result)
    {
        if (SANDBOX) {
            $title         = __('开发助手');
            $sandbox_title = __('关闭沙盒环境');
            $sure          = __('确定');
            /**
             * @var Request $request
             */
            $request = ObjectManager::getInstance(Request::class);
            $url     = $request->getUrlBuilder()->getUrl('/dev/tool/sandbox/close');
            $html    = <<<HTML
<div class="position-fixed" style="top: 50%; transform: translateY(-50%); right: 0;">
  <div class="card">
    <div class="card-body">
      <h5 class="card-title">$title</h5>
      <form action="$url" id="sandbox-form">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="sandbox" name="close" value="on">
        <label class="form-check-label" for="sandbox">$sandbox_title</label>
      </div>
      <script >
        document.querySelector('#sandbox').addEventListener('change', function () {
          if (this.checked) {
            document.querySelector('#sandbox-form').submit();
          }
        })
</script>
      </form>
    </div>
  </div>
</div>
HTML;
            $result  = str_replace('</body>', $html . '</body>', $result);
        }
        return $result;
    }
}