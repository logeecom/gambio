<?php

namespace Mollie\Gambio\Services\Business;

use Mollie\BusinessLogic\Integration\Interfaces\OrderTransitionService as BaseService;
use Mollie\Gambio\OrderReset\OrderResetService;
use Mollie\Gambio\Utility\MollieTranslator;

/**
 * Class OrderTransitionService
 *
 * @package Mollie\Gambio\Services\Business
 */
class OrderTransitionService implements BaseService
{

    use StatusUpdate;

    /**
     * @inheritDoc
     */
    public function payOrder($orderId, array $metadata)
    {
        $this->updateStatus($orderId, 'mollie_paid');
    }

    /**
     * @inheritDoc
     */
    public function expireOrder($orderId, array $metadata)
    {
        $comment = $this->getComment('mollie_expired');
        $this->mapToCancelledStatus($orderId, $comment);
    }

    /**
     * @param string $orderId
     * @param array  $metadata
     *
     * @throws \Mollie\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
     */
    public function cancelOrder($orderId, array $metadata)
    {
        $comment = $this->getComment('mollie_canceled');
        $this->mapToCancelledStatus($orderId, $comment);
    }

    /**
     * @inheritDoc
     */
    public function failOrder($orderId, array $metadata)
    {
        $comment = $this->getComment('mollie_failed');
        $this->mapToCancelledStatus($orderId, $comment);
    }

    /**
     * @inheritDoc
     */
    public function completeOrder($orderId, array $metadata)
    {
        $this->payOrder($orderId, $metadata);
    }

    /**
     * @inheritDoc
     */
    public function authorizeOrder($orderId, array $metadata)
    {
        $this->updateStatus($orderId, 'mollie_authorized');
    }

    /**
     * @inheritDoc
     */
    public function refundOrder($orderId, array $metadata)
    {
        $this->updateStatus($orderId, 'mollie_refunded');
    }

    /**
     * Puts the order on hold until the payment is confirmed. Only orders that are still in
     * the created status are updated, so the status set by a later transition is never overwritten.
     *
     * @param string $orderId
     * @param array  $metadata
     *
     * @throws \Mollie\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
     */
    public function pendingOrder($orderId, array $metadata)
    {
        $statusMap = $this->getOrderStatusMapper()->getStatusMap();
        if (empty($statusMap['mollie_pending']) || (int)$statusMap['mollie_pending'] === (int)$statusMap['mollie_created']) {
            return;
        }

        if ($this->getCurrentStatusId($orderId) !== (int)$statusMap['mollie_created']) {
            return;
        }

        $this->updateStatus($orderId, 'mollie_pending', $this->getComment('mollie_pending'));
    }

    /**
     * Cancels the order and restores its stock. An order that is already canceled is left unchanged, so the
     * stock is restored only once, whether the webhook, the customer return or the merchant canceled it first.
     *
     * @param $orderId
     * @param $comment
     *
     * @throws \Mollie\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
     */
    private function mapToCancelledStatus($orderId, $comment)
    {
        $canceledStatusId = (int)$this->getOrderStatusMapper()->mapToGambioStatus('mollie_canceled');
        if ($this->getCurrentStatusId($orderId) === $canceledStatusId) {
            return;
        }

        $this->updateStatus($orderId, 'mollie_canceled', $comment);
        $orderResetService = new OrderResetService();
        $orderResetService->resetOrder($orderId);
    }

    /**
     * @param string $orderId
     *
     * @return int
     */
    private function getCurrentStatusId($orderId)
    {
        /** @var \OrderReadServiceInterface $orderReadService */
        $orderReadService = \StaticGXCoreLoader::getService('OrderRead');

        return (int)$orderReadService->getOrderById(new \IdType($orderId))->getStatusId();
    }

    /**
     * @param string $statusCode
     *
     * @return string
     */
    private function getComment($statusCode)
    {
        $translate = new MollieTranslator();

        return $translate->translate("{$statusCode}_comment");
    }
}
