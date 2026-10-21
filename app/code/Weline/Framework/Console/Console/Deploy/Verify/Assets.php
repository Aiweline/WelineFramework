<?php

namespace Weline\Framework\Console\Console\Deploy\Verify;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Deploy\StaticAssetVerifier;

final class Assets extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $url = trim((string)($args['url'] ?? ''));
        if ($url === '') {
            $this->printer->error(__('缺少 --url=https://...'));
            return 2;
        }
        $insecure = !empty($args['insecure']);
        try {
            $response = $this->request($url, $insecure, false);
        } catch (\Throwable $exception) {
            $this->printer->error(__('页面获取失败：') . $exception->getMessage());
            return 2;
        }
        if ($response['status'] !== 0 && $response['status'] >= 400) {
            $this->printer->error(__('页面获取失败：HTTP %{1}', [$response['status']]));
            return 2;
        }
        $urls = StaticAssetVerifier::extractAssetUrls($response['body']);
        if (!$urls) {
            $this->printer->warning(__('页面未发现本站 /static 资源。'));
            return 0;
        }
        $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
        $scheme = (string)(parse_url($url, PHP_URL_SCHEME) ?? 'https');
        $port = parse_url($url, PHP_URL_PORT);
        if ($host === '') {
            $this->printer->error(__('无效 --url：') . $url);
            return 2;
        }
        $base = $scheme . '://' . $host . ($port ? ':' . $port : '');
        $result = StaticAssetVerifier::verify($urls, fn (string $path): int => $this->headStatus($base . $path, $insecure));
        foreach ($result['unversioned'] as $path) {
            $this->printer->warning(__('未版本化：') . $path);
        }
        foreach ($result['failures'] as $failure) {
            $this->printer->error(__('失败：') . $failure['url'] . ' (HTTP ' . $failure['status'] . ')');
        }
        if ($result['ok']) {
            $this->printer->success(__('全部资源校验通过。'));
            return 0;
        }
        return 1;
    }

    private function request(string $url, bool $insecure, bool $head): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $head ? 'HEAD' : 'GET',
                'follow_location' => 1,
                'ignore_errors' => true,
                'timeout' => 20,
                'header' => "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n"
                    . "Accept-Language: zh-CN,zh;q=0.9,en;q=0.8\r\n"
                    . "Upgrade-Insecure-Requests: 1\r\n"
                    . "Sec-Fetch-Dest: document\r\n"
                    . "Sec-Fetch-Mode: navigate\r\n"
                    . "Sec-Fetch-Site: none\r\n"
                    . "Sec-Fetch-User: ?1\r\n"
                    . "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36\r\n",
            ],
            'ssl' => [
                'verify_peer' => !$insecure,
                'verify_peer_name' => !$insecure,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $body = '';
        }
        $status = 0;
        if (!empty($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        return ['status' => $status, 'body' => (string)$body];
    }

    private function headStatus(string $url, bool $insecure): int
    {
        return $this->request($url, $insecure, true)['status'];
    }

    public function tip(): string
    {
        return '发布后校验页面里的本站 /static 资源：200 且已版本化（12 位构建段或 32~64 位内容哈希）。';
    }
}
