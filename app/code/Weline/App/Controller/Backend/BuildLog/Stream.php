<?php
declare(strict_types=1);

namespace Weline\App\Controller\Backend\BuildLog;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Sse\SseWriter;

#[Acl('Weline_App::build_stream', 'App打包日志流', 'circle', '实时查看打包日志')]
class Stream extends BackendController
{
    public function getIndex(): never
    {
        $sse = new SseWriter();
        $sse->start();

        $buildId = trim((string)$this->request->getParam('build_id', ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $buildId)) {
            $sse->sendEvent('error', ['message' => '日志不存在']);
            $sse->close();
            exit;
        }

        $file = VAR_DIR . 'app' . DS . 'build' . DS . $buildId . '.log';
        if (!is_file($file)) {
            $sse->sendEvent('error', ['message' => '日志不存在']);
            $sse->close();
            exit;
        }

        $offset = 0;
        $idleRounds = 0;
        $start = time();

        while (time() - $start < 300) {
            if (!is_file($file)) {
                $sse->sendEvent('error', ['message' => '日志不存在']);
                $sse->close();
                exit;
            }

            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                $sse->sendEvent('error', ['message' => '日志读取失败']);
                $sse->close();
                exit;
            }

            fseek($handle, $offset);
            $bytes = 0;
            while (($line = fgets($handle)) !== false) {
                $bytes += strlen($line);
                $line = rtrim($line, "\r\n");
                $sse->sendEvent('log', ['line' => $line]);
                if (str_contains($line, '[BUILD-DONE]')) {
                    fclose($handle);
                    $sse->sendEvent('done', [
                        'build_id' => $buildId,
                        'log_bytes' => filesize($file) ?: 0,
                    ]);
                    $sse->close();
                    exit;
                }
                if (str_contains($line, '[BUILD-FAIL]')) {
                    fclose($handle);
                    $sse->sendEvent('error', ['message' => $line]);
                    $sse->close();
                    exit;
                }
            }
            fclose($handle);

            if ($bytes > 0) {
                $offset += $bytes;
                $idleRounds = 0;
            } else {
                $idleRounds++;
                if ($idleRounds >= 3) {
                    $idleRounds = 0;
                    $sse->sendEvent('progress', ['stage' => 'build', 'idle' => true]);
                }
            }

            sleep(2);
        }

        $sse->sendEvent('error', ['message' => '日志流超时']);
        $sse->close();
        exit;
    }
}
