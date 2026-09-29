<?php
declare(strict_types=1);
namespace Weline\Cdn\Observer;
use Weline\Cdn\Service\FpcPolicyManagementService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
final class CollectFpcPolicies implements ObserverInterface
{
    public function __construct(private readonly FpcPolicyManagementService $policies) {}
    public function execute(Event &$event):void
    {
        $result=$this->policies->collectDeclarations();
        $event->setData('fpc_policy_collection',$result);
        if(!($result['success']??false)){w_log_error('FPC policy collection: '.$result['message']);}
    }
}
