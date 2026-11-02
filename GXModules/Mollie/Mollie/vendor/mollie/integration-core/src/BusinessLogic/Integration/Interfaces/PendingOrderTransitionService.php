<?php

namespace Mollie\BusinessLogic\Integration\Interfaces;

/**
 * Interface PendingOrderTransitionService
 *
 * Optional extension of OrderTransitionService. Integrations that implement it are notified when a payment
 * becomes pending (e.g. Pay by Bank: the customer approved the payment, but the funds have not arrived yet).
 * Integrations that do not implement it are not affected.
 *
 * @package Mollie\BusinessLogic\Integration\Interfaces
 */
interface PendingOrderTransitionService
{
    /**
     * Puts integration order on hold until the payment is paid
     *
     * @param string $orderId Integration order unique identifier used during payment creation
     * @param array $metadata Metadata provided by integration during payment creation
     */
    public function pendingOrder($orderId, array $metadata);
}
