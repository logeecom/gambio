<?php

namespace Mollie\Gambio\WebHook;

use Mollie\BusinessLogic\Http\DTO\Payment;
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
    const PAYMENT_MODULE_PREFIX = 'mollie_';

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
        if ($event->getCurrentPayment()->getStatus() === static::STATUS_PENDING) {
            return;
        }

        static::holdOrder($event->getOrderReference()->getShopReference(), $event->getNewPayment());
    }

    /**
     * Puts the order on hold if the payment is pending and its payment method waits for the funds
     *
     * @param string  $shopReference
     * @param Payment $payment
     */
    public static function holdOrder($shopReference, Payment $payment)
    {
        if ($payment->getStatus() !== static::STATUS_PENDING
            || !array_intersect((array)$payment->getMethods(), static::$onHoldMethods)
        ) {
            return;
        }

        /** @var \Mollie\Gambio\Services\Business\OrderTransitionService $orderTransitionService */
        $orderTransitionService = ServiceRegister::getService(OrderTransitionService::CLASS_NAME);
        $orderTransitionService->pendingOrder((string)$shopReference, $payment->getMetadata());
    }

    /**
     * Checks whether orders of the given Gambio payment module are put on hold while the payment is pending
     *
     * @param string $paymentModule Gambio payment module code, e.g. mollie_paybybank
     *
     * @return bool
     */
    public static function isOnHoldPaymentModule($paymentModule)
    {
        $prefixLength = strlen(static::PAYMENT_MODULE_PREFIX);

        return strncmp($paymentModule, static::PAYMENT_MODULE_PREFIX, $prefixLength) === 0
            && in_array(substr($paymentModule, $prefixLength), static::$onHoldMethods, true);
    }
}
