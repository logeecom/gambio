<?php

namespace Mollie\Gambio\WebHook;

use Mollie\BusinessLogic\Integration\Interfaces\OrderTransitionService;
use Mollie\BusinessLogic\WebHook\PaymentChangedWebHookEvent;
use Mollie\Infrastructure\ServiceRegister;

/**
 * Class PendingPaymentWebHookHandler
 *
 * Puts the order on hold when the payment of an asynchronous payment method becomes pending.
 * The pending status is not handled by the integration core.
 *
 * @package Mollie\Gambio\WebHook
 */
class PendingPaymentWebHookHandler
{
    const STATUS_PENDING = 'pending';

    /**
     * Mollie payment methods whose orders are put on hold while the payment is pending
     *
     * @var string[]
     */
    private static $onHoldMethods = ['paybybank'];

    /**
     * @param PaymentChangedWebHookEvent $event
     */
    public function handle(PaymentChangedWebHookEvent $event)
    {
        $newPayment = $event->getNewPayment();
        if ($newPayment->getStatus() !== static::STATUS_PENDING
            || $event->getCurrentPayment()->getStatus() === static::STATUS_PENDING
            || !array_intersect((array)$newPayment->getMethods(), static::$onHoldMethods)
        ) {
            return;
        }

        /** @var \Mollie\Gambio\Services\Business\OrderTransitionService $orderTransitionService */
        $orderTransitionService = ServiceRegister::getService(OrderTransitionService::CLASS_NAME);
        $orderTransitionService->pendingOrder($event->getOrderReference()->getShopReference(), $newPayment->getMetadata());
    }
}
