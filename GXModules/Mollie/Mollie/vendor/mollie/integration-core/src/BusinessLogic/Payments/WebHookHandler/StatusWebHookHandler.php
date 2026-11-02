<?php

namespace Mollie\BusinessLogic\Payments\WebHookHandler;

use Mollie\BusinessLogic\Integration\Interfaces\OrderTransitionService;
use Mollie\BusinessLogic\Integration\Interfaces\PendingOrderTransitionService;
use Mollie\BusinessLogic\WebHook\PaymentChangedWebHookEvent;
use Mollie\Infrastructure\ServiceRegister;

/**
 * Class StatusWebHookHandler
 *
 * @package Mollie\BusinessLogic\Payments\WebHookHandler
 */
class StatusWebHookHandler
{
    /**
     * @var string[]
     */
    private static $STATUS_TO_SERVICE_METHOD = array(
        'paid' => 'payOrder',
        'expired' => 'expireOrder',
        'canceled' => 'cancelOrder',
        'authorized' => 'authorizeOrder',
        'failed' => 'failOrder',
        'pending' => 'pendingOrder',
    );

    /**
     * @param PaymentChangedWebHookEvent $event
     */
    public function handle(PaymentChangedWebHookEvent $event)
    {
        if ($event->getCurrentPayment()->getStatus() === $event->getNewPayment()->getStatus()) {
            return;
        }

        $serviceMethod = $this->getServiceMethodFor($event->getNewPayment()->getStatus());
        if (!$serviceMethod) {
            return;
        }

        // the pending transition is optional, it is called only for integrations that opt in
        $orderTransitionService = $this->getOrderTransitionService();
        if ($serviceMethod === 'pendingOrder' && !$orderTransitionService instanceof PendingOrderTransitionService) {
            return;
        }

        call_user_func(
            array($orderTransitionService, $serviceMethod),
            $event->getOrderReference()->getShopReference(),
            $event->getNewPayment()->getMetadata()
        );
    }

    /**
     * @param string $status
     *
     * @return string|null
     */
    protected function getServiceMethodFor($status)
    {
        return array_key_exists($status, static::$STATUS_TO_SERVICE_METHOD) ? static::$STATUS_TO_SERVICE_METHOD[$status] : null;
    }

    /**
     * @return OrderTransitionService
     */
    protected function getOrderTransitionService()
    {
        /** @var OrderTransitionService $orderTransitionService */
        $orderTransitionService = ServiceRegister::getService(OrderTransitionService::CLASS_NAME);
        return $orderTransitionService;
    }
}
