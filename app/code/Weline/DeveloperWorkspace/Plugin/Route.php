<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Plugin;

use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Core;
use Weline\Framework\Ui\FormKey;

class Route extends Core
{
    function afterRoute(Core $core, &$result)
    {
        if (SANDBOX) {
            $title = __('开发助手');
            $sandboxTitle = __('关闭沙盒环境');
            $sandboxKeyLabel = __('沙盒启动Key');

            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $url = $request->getUrlBuilder()->getUrl('/dev/tool/sandbox/close');
            $formKey = ObjectManager::getInstance(FormKey::class)->getKey($url);

            $safeUrl = \htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $safeTitle = \htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');
            $safeSandboxTitle = \htmlspecialchars((string)$sandboxTitle, ENT_QUOTES, 'UTF-8');
            $safeSandboxKeyLabel = \htmlspecialchars((string)$sandboxKeyLabel, ENT_QUOTES, 'UTF-8');
            $safeFormKey = \htmlspecialchars($formKey, ENT_QUOTES, 'UTF-8');

            $html = <<<HTML
<div class="position-fixed" style="top: 50%; transform: translateY(-50%); right: 0;">
  <div class="card">
    <div class="card-body">
      <h5 class="card-title">$safeTitle</h5>
      <form action="$safeUrl" method="post" id="sandbox-form">
        <input type="hidden" name="form_key" value="$safeFormKey">
        <input type="hidden" name="close" value="on">
        <label class="form-label small mb-1" for="sandbox-key">$safeSandboxKeyLabel</label>
        <input class="form-control form-control-sm mb-2" type="password" name="key" id="sandbox-key" autocomplete="off" required>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="sandbox" value="on">
          <label class="form-check-label" for="sandbox">$safeSandboxTitle</label>
        </div>
        <script>
          document.querySelector('#sandbox').addEventListener('change', function () {
            if (this.checked) {
              var form = document.querySelector('#sandbox-form');
              if (form.reportValidity()) {
                form.submit();
              }
            }
          });
        </script>
      </form>
    </div>
  </div>
</div>
HTML;
            if ($result instanceof Response) {
                $result->setBody(str_replace('</body>', $html . '</body>', $result->getBody()));
            } elseif (is_string($result)) {
                $result = str_replace('</body>', $html . '</body>', $result);
            }
        }
        return $result;
    }
}
