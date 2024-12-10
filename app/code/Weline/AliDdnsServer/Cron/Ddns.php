<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/5/10 22:35:25
 */

namespace Aiweline\AliDdnsServer\Cron;

use Aiweline\AliDdnsServer\Model\Config;
use Aiweline\AliDdnsServer\Model\DdnsDomains;
use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;
use GuzzleHttp\Client;
use Weline\Framework\Output\Cli\Printing;

class Ddns implements \Weline\Cron\CronTaskInterface
{

    const accessKeyId  = 'accessKeyId';
    const accessSecret = 'accessSecret';

    /**
     * @var \Aiweline\AliDdnsServer\Model\DdnsDomains
     */
    private DdnsDomains $ddnsDomains;
    /**
     * @var \Aiweline\AliDdnsServer\Model\Config
     */
    private Config $config;
    /**
     * @var \Weline\Framework\Output\Cli\Printing
     */
    private Printing $printing;

    function __construct(DdnsDomains $ddnsDomains, Config $config, Printing $printing)
    {
        $this->ddnsDomains = $ddnsDomains;
        $this->config      = $config;
        $this->printing    = $printing;
    }

    /**
     * @inheritDoc
     */
    function name(): string
    {
        return 'AliDdnsServer';
    }


    function execute_name(): string
    {
        return 'aliddns';
    }

    /**
     * @inheritDoc
     */
    function tip(): string
    {
        return '定时动态解析域名，支持ipv4和ipv6的域名解析任务。';
    }

    /**
     * @inheritDoc
     */
    function cron_time(): string
    {
        return '*/30 * * * *';
    }

    /**
     * @DESC          # 获取阿里云指定二级域名的当前解析记录 获取域名Ip地址
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/5/10 23:37
     * 参数区：
     *
     * @param string $domain  要查询的域名
     * @param string $RRKey   要查询的主机名
     * @param string $version ip版本：ipv4或ipv6
     *
     * @return array|void
     * @throws \AlibabaCloud\Client\Exception\ClientException
     */
    function GetAlibabaIP(string $domain, string $RRKey, string $version = 'ipv4')
    {
        AlibabaCloud::accessKeyClient($this->config->get(self::accessKeyId), $this->config->get(self::accessSecret))
                    ->regionId('cn-hangzhou') // replace regionId as you need
                    ->asDefaultClient();
        try {
            $result = AlibabaCloud::rpc()
                                  ->product('Alidns')
                // ->scheme('https') // https | http
                                  ->version('2015-01-09')
                                  ->action('DescribeDomainRecords')
                                  ->method('POST')
                                  ->options([
                                                'query' => [
                                                    'DomainName'  => $domain,
                                                    'PageNumber'  => '1',
                                                    'RRKeyWord'   => $RRKey,
                                                    'TypeKeyWord' => $version === 'ipv4' ? 'A' : 'AAAA',
                                                ],
                                            ])
                                  ->request();
            return $result->toArray();
            //return $arr["DomainRecords"]["Record"][0]["Value"];
        } catch (ClientException|ServerException $e) {
            echo $e->getErrorMessage() . PHP_EOL;
        }
    }

    /**
     * @DESC          # 获取公网IP主方法，随机使用接口获取IP，直到获取到为止
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/5/10 23:47
     * 参数区：
     *
     * @param string $version ip版本：ipv4 或 ipv6
     *
     * @return string
     */
    function GetIP(string $version = 'ipv4'): string
    {
        if ($version === 'ipv4') {
            $ip = $this->getPublicIPByUrl('https://api-ipv4.ip.sb/ip');
            if ($ip) {
                return trim($ip);
            }
            //URL接口数组
            $urlArr = array(
                'http://checkip.dyndns.com/',
                'ns1.dnspod.net:6666',
                'http://ip.chinaz.com/getip.aspx',
                'http://www.net.cn/static/customercare/yourip.asp',
                'https://ip.cn/',
                'http://www.ip168.com/json.do?view=myipaddress',
                'http://pv.sohu.com/cityjson',
                'http://pv.sohu.com/cityjson',
                'http://ip.taobao.com/service/getIpInfo.php?ip=myip',
                'http://2018.ip138.com/ic.asp'
            );
            //随机用一个接口，直到获取到返回值为止
            while (empty($ip)) {
                $ip = $this->getPublicIPByUrl($urlArr[rand(0, 9)]);
            }
            //提取出接口返回的字符串中的IP地址
            $preg    = '/(\d{1,3}\.){3}\d{1,3}/';
            $matches = null;
            preg_match($preg, $ip, $matches);
            //返回最终的公网IP地址
            return $matches[0];
        }
        return $this->getPublicIPByUrl('https://api-ipv6.ip.sb/ip');
    }

    /**
     * @DESC          # 使用接口读取公网IP
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/5/10 23:47
     * 参数区：
     *
     * @param $url
     *
     * @return bool|string|null
     */
    function getPublicIPByUrl($url): bool|string|null
    {
        try {
            $my_curl = curl_init();
            curl_setopt($my_curl, CURLOPT_URL, $url);
            curl_setopt($my_curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($my_curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($my_curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($my_curl, CURLOPT_TIMEOUT, 20);
            $ip = curl_exec($my_curl);
            curl_close($my_curl);
            if (!isset($ip) || empty($ip)) {
                return null;
            }
            return $ip;
        } catch (\Throwable $th) {
            return null;
        }
    }

    /**
     * @DESC          # 判断指定IP是否和上次修改的解析地址一样，并记录修改后的IP,以便下一次检测匹配
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/5/10 23:49
     * 参数区：
     *
     * @param string $domain     域名
     * @param string $host_name  主机名
     * @param string $ip         ip地址：ipv4地址或者ipv6地址
     * @param string $ip_version ip版本号
     *
     * @return bool
     */
    function IsSame(string $domain, string $host_name, string $ip, string $ip_version = 'ipv4'): bool
    {
        $ddns = $this->ddnsDomains->where($this->ddnsDomains::fields_DOMAIN, $domain)
                                  ->where($this->ddnsDomains::fields_HOST_NAME, $host_name)
                                  ->find()
                                  ->fetch();
        if ($ddns->getData(strtolower($ip_version)) === $ip) {
            return true;
        }
        return false;
    }

    /**
     * @DESC          # 调用阿里云API修改解析记录
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/5/11 0:01
     * 参数区：
     *
     * @param string $RRKey      主机记录
     * @param string $ip         ip地址：ipv4地址或者ipv6地址
     * @param string $RecordId   阿里云记录ID
     * @param string $ip_version ip版本：ipv4或者ipv6
     *
     * @return array|void
     * @throws \AlibabaCloud\Client\Exception\ClientException
     */
    function APIIP(string $RRKey, string $ip, string $RecordId, string $ip_version = 'ipv4')
    {
        AlibabaCloud::accessKeyClient($this->config->get(self::accessKeyId), $this->config->get(self::accessSecret))
                    ->regionId('cn-hangzhou') // replace regionId as you need
                    ->asDefaultClient();

        try {
            $result = AlibabaCloud::rpc()
                                  ->product('Alidns')
                // ->scheme('https') // https | http
                                  ->version('2015-01-09')
                                  ->action('UpdateDomainRecord')
                                  ->method('POST')
                                  ->options([
                                                'query' => [
                                                    'RecordId' => $RecordId,
                                                    'RR'       => $RRKey,
                                                    'Type'     => $ip_version === 'ipv4' ? 'A' : 'AAAA',
                                                    'Value'    => $ip,
                                                ],
                                            ])
                                  ->request();
            return $result->toArray();
        } catch (ClientException|ServerException $e) {
            echo $e->getErrorMessage() . PHP_EOL;
        }
    }

    /**
     * @inheritDoc
     */
    function execute(): string
    {
        // 先判断配置
        $accessKeyId  = $this->config->get('accessKeyId');
        $accessSecret = $this->config->get('accessSecret');
        if ($accessKeyId == '' || $accessSecret == '') {
            // 如果配置不正确，则直接停止执行
            echo 'accessKeyId或者accessSecret配置不正确，则直接停止执行' . PHP_EOL;
            return '配置不正确，无需执行任务。';
        }

        // 本机公网IP
        $ipv4 = $this->GetIP();
        $ipv6 = $this->GetIP('ipv6');

        // 获取所有已绑定的要解析的域名
        $domains = $this->ddnsDomains->select()->fetch()->getItems();

        // 循环检查所有已绑定的域名是否正确
        /**@var DdnsDomains $domain */
        foreach ($domains as $domain) {
            // 获取域名
            $domain_name = $domain->getDomain();
            // 获取ipv4和ipv6
            $ipv4_flag = $domain->getIpv4Flag();            # 是否开启ipv4 ddns解析,1为开启，0为关闭
            $ipv6_flag = $domain->getIpv6Flag();            # 是否开启ipv6 ddns解析,1为开启，0为关闭

            // name_ipv4 和 name_ipv6
            $name_ipv4 = $domain->getHostName();            # 要进行ipv4 ddns解析的主机记录，即前缀
            $name_ipv6 = $domain->getHostName();            # 要进行ipv6 ddns解析的主机记录，即前缀 [此处对应的是阿里云解析中的 主机记录 应填写 www 或 @ 等。填写www解析后的域名为www.xxx.com；填写@解析后为主域名xxx.com]
            $this->printing->success('正在解析域名：' . $name_ipv4 . '.' . $domain_name);
            // 创建阿里巴巴请求
            if ($ipv4_flag) {
                if ($this->IsSame($domain_name, $name_ipv4, $ipv4)) {
                    echo '当前公网IPv4和上次修改的解析地址一样' . PHP_EOL;
                    $domain->setIpv4($ipv4)->save(true);
                } else {
                    echo '...当前公网IPv4和记录解析地址不一样...' . PHP_EOL;
                    //公网IP和上次修改的解析地址不一致，再进行获取当前解析地址
                    $alibabaArr      = $this->GetAlibabaIP($domain_name, $name_ipv4);                                        //获取当前解析记录
                    $alibabaIP       = $alibabaArr['DomainRecords']['Record'][0]['Value'];                                   //当前解析IP
                    $alibabaRecordId = $alibabaArr['DomainRecords']['Record'][0]['RecordId'];                                //当前解析RecordId
                    //公网IP和当前解析地址的比较
                    if ($alibabaIP == $ipv4) {
                        echo '...公网IPv4和当前解析地址一样...' . PHP_EOL;
                        $domain->setIpv4($ipv4)->save(true);
                    } else {
                        echo '...公网IPv4和当前解析地址不一样，开始解析IPv4...' . PHP_EOL;
                        //如果公网IP和当前解析不一样，则修改解析记录
                        $this->APIIP($name_ipv4, $ipv4, $alibabaRecordId);
                        $domain->setIpv4($ipv4)->save(true);
                    }
                }
            }
            if ($ipv6_flag) {
                if ($this->IsSame($domain_name, $name_ipv6, $ipv6, 'ipv6')) {
                    echo '当前公网IPv6和上次修改的解析地址一样' . PHP_EOL;
                    $domain->setIpv6($ipv6)->save(true);
                } else {
                    echo '...当前公网IPv6和上次修改的解析地址不一样...' . PHP_EOL;
                    //公网IP和上次修改的解析地址不一致，再进行获取当前解析地址
                    $alibabaArr = $this->GetAlibabaIP($domain_name, $name_ipv6, 'ipv6');
                    //获取当前解析记录
                    $alibabaIP       = $alibabaArr['DomainRecords']['Record'][0]['Value'] ?? '';                                           //当前解析IP
                    $alibabaRecordId = $alibabaArr['DomainRecords']['Record'][0]['RecordId'] ?? '';                                        //当前解析RecordId
                    //公网IP和当前解析地址的比较
                    if ($alibabaIP == $ipv6) {
                        echo '...公网IPv6和当前解析地址一样...' . PHP_EOL;
                        $domain->setIpv6($ipv6)->save(true);
                    } else {
                        echo '...公网IPv6和当前解析地址不一样，开始解析IPv6...' . PHP_EOL;
                        //如果公网IP和当前解析不一样，则修改解析记录
                        $this->APIIP($name_ipv6, $ipv6, $alibabaRecordId, 'ipv6');
                        $domain->setIpv6($ipv6)->save(true);
                    }
                }

            }
        }
        return '成功解析完毕！';
    }

    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 10;
    }

}