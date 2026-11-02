<?php


namespace Mollie\Gambio\MollieRedirect;

use Mollie\BusinessLogic\OrderReference\OrderReferenceService;
use Mollie\BusinessLogic\WebHook\WebHookTransformer;
use Mollie\Gambio\Mappers\OrderStatusMapper;
use Mollie\Gambio\Utility\DatabaseLock;
use Mollie\Gambio\Utility\MollieTranslator;
use Mollie\Gambio\Utility\UrlProvider;
use Mollie\Infrastructure\Logger\Logger;
use Mollie\Infrastructure\ServiceRegister;

/**
 * Class MollieRedirectProvider
 *
 * @package Mollie\Gambio\MollieRedirect
 */
class MollieRedirectProvider
{
    /**
     * Seconds to wait for a webhook that is processing the same order
     */
    const LOCK_TIMEOUT = 5;

    /**
     * @var \OrderReadServiceInterface
     */
    private $orderReadService;
    /**
     * @var OrderStatusMapper
     */
    private $statusMapper;

    /**
     * PaymentLinkProvider constructor.
     */
    public function __construct()
    {
        $this->orderReadService = \StaticGXCoreLoader::getService('OrderRead');
        $this->statusMapper = new OrderStatusMapper();
    }

    /**
     * Returns success or payment selection link, depending on the payment status
     *
     * @param int $orderId
     *
     * @return string
     * @throws \Mollie\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
     */
    public function getRedirectUrl($orderId)
    {
        $orderId = (int)$orderId;
        $order = $this->_getOrder($orderId);
        if ($order === null || !$this->_isCustomerOrder($order)) {
            return UrlProvider::generateShopUrl('account_history.php');
        }

        $this->_synchronizePaymentStatus($order);
        $order = $this->_getOrder($orderId);
        $statusMapping = $this->statusMapper->getStatusMap();
        if ($order !== null && (int)$order->getStatusId() === (int)$statusMapping['mollie_canceled']) {
            return $this->_getCanceledPaymentUrl($order);
        }

        $redirectPage = $this->_isCheckoutOrder($orderId) ? 'checkout_process.php' : 'checkout_success.php';

        return UrlProvider::generateShopUrl($redirectPage);
    }

    /**
     * Applies the current Mollie payment status to the order, the same way as the webhook does. The customer
     * usually returns before the webhook is processed, so the order status alone is not reliable.
     *
     * @param \OrderInterface $order
     */
    private function _synchronizePaymentStatus(\OrderInterface $order)
    {
        $statusMapping = $this->statusMapper->getStatusMap();
        $unconfirmedStatuses = [(int)$statusMapping['mollie_created'], (int)$statusMapping['mollie_pending']];
        if (!in_array((int)$order->getStatusId(), $unconfirmedStatuses, true)) {
            return;
        }

        /** @var OrderReferenceService $orderReferenceService */
        $orderReferenceService = ServiceRegister::getService(OrderReferenceService::CLASS_NAME);
        $orderReference = $orderReferenceService->getByShopReference((string)$order->getOrderId());
        if ($orderReference === null || !$orderReference->getMollieReference()) {
            return;
        }

        /** @var WebHookTransformer $webHookTransformer */
        $webHookTransformer = ServiceRegister::getService(WebHookTransformer::CLASS_NAME);
        $rawRequest = http_build_query(['id' => $orderReference->getMollieReference()]);
        try {
            DatabaseLock::forOrder($order->getOrderId())->execute(
                static::LOCK_TIMEOUT,
                function () use ($webHookTransformer, $rawRequest) {
                    $webHookTransformer->handle($rawRequest);
                }
            );
        } catch (\Exception $exception) {
            Logger::logWarning(
                "Payment status for order {$order->getOrderId()} could not be synchronized: {$exception->getMessage()}",
                'Integration'
            );
        }
    }

    /**
     * @param int $orderId
     *
     * @return \OrderInterface|null
     */
    private function _getOrder($orderId)
    {
        try {
            return $this->orderReadService->getOrderById(new \IdType($orderId));
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * @param \OrderInterface $order
     *
     * @return bool
     */
    private function _isCustomerOrder(\OrderInterface $order)
    {
        return isset($_SESSION['customer_id']) && (int)$order->getCustomerId() === (int)$_SESSION['customer_id'];
    }

    /**
     * Checks whether the order is the one being placed in the current checkout
     *
     * @param int $orderId
     *
     * @return bool
     */
    private function _isCheckoutOrder($orderId)
    {
        return isset($_SESSION['tmp_oID']) && (int)$_SESSION['tmp_oID'] === (int)$orderId;
    }

    /**
     * @param \OrderInterface $order
     *
     * @return string
     */
    private function _getCanceledPaymentUrl(\OrderInterface $order)
    {
        if (!$this->_isCheckoutOrder($order->getOrderId())) {
            return UrlProvider::generateShopUrl('account_history_info.php', '', ['order_id' => $order->getOrderId()]);
        }

        $lang = new MollieTranslator();
        $paymentClass = $order->getPaymentType()->getModule();
        $_SESSION[$paymentClass . '_error'] = $lang->translate('mollie.payment.redirect.payment_not_completed.description');

        return UrlProvider::generateShopUrl('checkout_payment.php', '', ['payment_error' => $paymentClass]);
    }
}
