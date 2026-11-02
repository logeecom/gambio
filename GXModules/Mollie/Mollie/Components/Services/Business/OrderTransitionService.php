<?php

namespace Mollie\Gambio\Services\Business;

use Mollie\BusinessLogic\Integration\Interfaces\OrderTransitionService as BaseService;
use Mollie\BusinessLogic\Integration\Interfaces\PendingOrderTransitionService;
use Mollie\Gambio\Entity\Repository\GambioLanguageRepository;
use Mollie\Gambio\OrderReset\OrderResetService;
use Mollie\Gambio\Utility\MollieTranslator;

/**
 * Class OrderTransitionService
 *
 * @package Mollie\Gambio\Services\Business
 */
class OrderTransitionService implements BaseService, PendingOrderTransitionService
{

    use StatusUpdate;

    /**
     * Gambio cancel status used when the setting is not available
     */
    const DEFAULT_CANCEL_STATUS_ID = 99;

    /**
     * Payment modules whose orders are put on hold while the payment is pending (the funds have not arrived yet)
     *
     * @var string[]
     */
    private static $onHoldPaymentModules = ['mollie_paybybank'];

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
     * Puts the order on hold until the payment is confirmed. Only orders of payment methods that wait for the
     * funds (Pay by Bank) that are still in the created status are updated, so the status set by a later
     * transition is never overwritten.
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

        $order = $this->getOrder($orderId);
        if ((int)$order->getStatusId() !== (int)$statusMap['mollie_created']
            || !in_array($order->getPaymentType()->getModule(), static::$onHoldPaymentModules, true)
        ) {
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
        $canceledStatusIds = [
            (int)$this->getOrderStatusMapper()->mapToGambioStatus('mollie_canceled'),
            $this->getGambioCancelStatusId(),
        ];
        if (in_array((int)$this->getOrder($orderId)->getStatusId(), $canceledStatusIds, true)) {
            return;
        }

        $this->updateStatus($orderId, 'mollie_canceled', $comment);
        $orderResetService = new OrderResetService();
        $orderResetService->resetOrder($orderId);
    }

    /**
     * Returns the status Gambio sets when the merchant cancels an order (and optionally restocks it)
     *
     * @return int
     */
    private function getGambioCancelStatusId()
    {
        $statusId = function_exists('gm_get_conf') ? (int)gm_get_conf('GM_ORDER_STATUS_CANCEL_ID') : 0;

        return $statusId ?: static::DEFAULT_CANCEL_STATUS_ID;
    }

    /**
     * @param string $orderId
     *
     * @return \OrderInterface
     */
    private function getOrder($orderId)
    {
        /** @var \OrderReadServiceInterface $orderReadService */
        $orderReadService = \StaticGXCoreLoader::getService('OrderRead');

        return $orderReadService->getOrderById(new \IdType($orderId));
    }

    /**
     * @param string $statusCode
     *
     * @return string
     */
    private function getComment($statusCode)
    {
        // comments are written by the webhook and by the customer return, so the shop default language is used
        // instead of the language of the request
        $languageRepository = new GambioLanguageRepository();
        $defaultLanguage = defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : '';
        $translate = new MollieTranslator($languageRepository->getLanguageIdByCode($defaultLanguage));

        return $translate->translate("{$statusCode}_comment");
    }
}
