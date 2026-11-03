<?php


namespace Mollie\Gambio\MollieRedirect;

use Mollie\BusinessLogic\Http\DTO\Payment;
use Mollie\BusinessLogic\OrderReference\OrderReferenceService;
use Mollie\BusinessLogic\PaymentMethod\Model\PaymentMethodConfig;
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
     * Session key of the order whose customer returned from Mollie and whose checkout may be finished
     */
    const SESSION_RETURNED_ORDER = 'mollie_returned_order';

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

        $statusMapping = $this->statusMapper->getStatusMap();
        if ($this->_synchronizePaymentStatus($order, $statusMapping)) {
            $order = $this->_getOrder($orderId);
        }

        if ($order !== null && (int)$order->getStatusId() === (int)$statusMapping['mollie_canceled']) {
            return $this->_getCanceledPaymentUrl($order);
        }

        if (!$this->_isCheckoutOrder($orderId)) {
            return UrlProvider::generateShopUrl('checkout_success.php');
        }

        $_SESSION[static::SESSION_RETURNED_ORDER] = $orderId;

        return UrlProvider::generateShopUrl('checkout_process.php');
    }

    /**
     * Checks whether the customer returned from Mollie for the given order, so its checkout may be finished.
     * The mark is consumed, so a later submit of the checkout for the same order is not treated as a return.
     *
     * @param int $orderId
     *
     * @return bool
     */
    public static function consumeReturnedOrder($orderId)
    {
        $isReturned = isset($_SESSION[static::SESSION_RETURNED_ORDER])
            && (int)$_SESSION[static::SESSION_RETURNED_ORDER] === (int)$orderId;
        unset($_SESSION[static::SESSION_RETURNED_ORDER]);

        return $isReturned;
    }

    /**
     * Returns the URL where the customer continues the payment of the order: the Mollie checkout while the
     * payment can still be paid, otherwise the return URL, which decides by the payment status
     *
     * @param int $orderId
     *
     * @return string
     */
    public function getPaymentContinuationUrl($orderId)
    {
        $checkoutUrl = $this->_getCheckoutUrl($orderId);
        if ($checkoutUrl) {
            return $checkoutUrl;
        }

        return UrlProvider::generateShopUrl('shop.php', 'MollieCheckoutRedirect', ['order_id' => (int)$orderId]);
    }

    /**
     * @param int $orderId
     *
     * @return string Mollie checkout URL of the order payment, or an empty string if the payment has none
     */
    private function _getCheckoutUrl($orderId)
    {
        /** @var OrderReferenceService $orderReferenceService */
        $orderReferenceService = ServiceRegister::getService(OrderReferenceService::CLASS_NAME);
        $orderReference = $orderReferenceService->getByShopReference((string)$orderId);
        if ($orderReference === null || $orderReference->getApiMethod() !== PaymentMethodConfig::API_METHOD_PAYMENT) {
            return '';
        }

        $checkoutLink = Payment::fromArray((array)$orderReference->getPayload())->getLink('checkout');

        return $checkoutLink ? $checkoutLink->getHref() : '';
    }

    /**
     * Applies the current Mollie payment status to the order, the same way as the webhook does. The customer
     * usually returns before the webhook is processed, so the order status alone is not reliable.
     *
     * @param \OrderInterface $order
     * @param array           $statusMapping
     *
     * @return bool True if the synchronization was attempted, so the order status may have changed
     */
    private function _synchronizePaymentStatus(\OrderInterface $order, array $statusMapping)
    {
        $unconfirmedStatuses = [(int)$statusMapping['mollie_created'], (int)$statusMapping['mollie_pending']];
        if (!in_array((int)$order->getStatusId(), $unconfirmedStatuses, true)) {
            return false;
        }

        /** @var OrderReferenceService $orderReferenceService */
        $orderReferenceService = ServiceRegister::getService(OrderReferenceService::CLASS_NAME);
        $orderReference = $orderReferenceService->getByShopReference((string)$order->getOrderId());
        if ($orderReference === null || !$orderReference->getMollieReference()) {
            return false;
        }

        /** @var WebHookTransformer $webHookTransformer */
        $webHookTransformer = ServiceRegister::getService(WebHookTransformer::CLASS_NAME);
        $rawRequest = http_build_query(['id' => $orderReference->getMollieReference()]);
        try {
            $isSynchronized = DatabaseLock::forOrder($order->getOrderId())->execute(
                static::LOCK_TIMEOUT,
                function () use ($webHookTransformer, $rawRequest) {
                    $webHookTransformer->handle($rawRequest);
                }
            );
            if (!$isSynchronized) {
                $this->_logSynchronizationFailure($order->getOrderId(), 'the order is being processed by the webhook');
            }
        } catch (\Exception $exception) {
            $this->_logSynchronizationFailure($order->getOrderId(), $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->_logSynchronizationFailure($order->getOrderId(), $exception->getMessage());
        }

        return true;
    }

    /**
     * @param int    $orderId
     * @param string $reason
     */
    private function _logSynchronizationFailure($orderId, $reason)
    {
        Logger::logWarning("Payment status for order {$orderId} could not be synchronized: {$reason}", 'Integration');
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
