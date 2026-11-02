<?php


namespace Mollie\Gambio\MollieRedirect;

use Mollie\BusinessLogic\Http\DTO\Payment;
use Mollie\BusinessLogic\Payments\PaymentService;
use Mollie\Gambio\Mappers\OrderStatusMapper;
use Mollie\Gambio\OrderReset\OrderResetService;
use Mollie\Gambio\Utility\MollieTranslator;
use Mollie\Gambio\Utility\UrlProvider;
use Mollie\Gambio\WebHook\PendingPaymentWebHookHandler;
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
     * @var \OrderReadServiceInterface
     */
    private $orderReadService;
    /**
     * @var OrderStatusMapper
     */
    private $statusMapper;
    /**
     * @var OrderResetService
     */
    private $orderResetService;

    /**
     * PaymentLinkProvider constructor.
     */
    public function __construct()
    {
        $this->orderReadService = \StaticGXCoreLoader::getService('OrderRead');
        $this->orderResetService = new OrderResetService();
        $this->statusMapper = new OrderStatusMapper();
    }

    /**
     * Returns success or payment selection link, depending on order status
     *
     * @param int $orderId
     *
     * @return string
     * @throws \Mollie\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException
     */
    public function getRedirectUrl($orderId)
    {
        /** @var \OrderInterface $order */
        $order = $this->orderReadService->getOrderById(new \IdType($orderId));
        if ($order) {
            $this->_holdPendingPayment($order);
            $statusMapping = $this->statusMapper->getStatusMap();
            if ((int)$order->getStatusId() === (int)$statusMapping['mollie_canceled']) {
                $this->orderResetService->resetOrder($orderId);

                return $this->_getCanceledPaymentUrl($order);
            }
        }

        /** @var \shoppingCart_ORIGIN $cart */
        $cart = $_SESSION['cart'];
        $redirectPage = !empty($cart->contents) ? 'checkout_process.php' : 'checkout_success.php';

        return UrlProvider::generateShopUrl($redirectPage);
    }

    /**
     * Puts the order on hold when the customer returns with a pending payment. The customer usually returns
     * before the pending webhook is processed, and in test mode Mollie does not send it at all.
     *
     * @param \OrderInterface $order
     */
    private function _holdPendingPayment(\OrderInterface $order)
    {
        if (!PendingPaymentWebHookHandler::isOnHoldPaymentModule($order->getPaymentType()->getModule())) {
            return;
        }

        $statusMapping = $this->statusMapper->getStatusMap();
        if ((int)$order->getStatusId() !== (int)$statusMapping['mollie_created']) {
            return;
        }

        $payment = $this->_getPayment($order->getOrderId());
        if ($payment !== null) {
            PendingPaymentWebHookHandler::holdOrder($order->getOrderId(), $payment);
        }
    }

    /**
     * @param int $orderId
     *
     * @return Payment|null
     */
    private function _getPayment($orderId)
    {
        try {
            /** @var PaymentService $paymentService */
            $paymentService = ServiceRegister::getService(PaymentService::CLASS_NAME);

            return $paymentService->getPayment((string)$orderId);
        } catch (\Exception $exception) {
            Logger::logWarning(
                "Payment status for order {$orderId} could not be fetched: {$exception->getMessage()}",
                'Integration'
            );

            return null;
        }
    }

    /**
     * @param \OrderInterface $order
     *
     * @return string
     */
    private function _getCanceledPaymentUrl(\OrderInterface $order)
    {
        /** @var \shoppingCart_ORIGIN $cart */
        $cart = $_SESSION['cart'];
        if (!empty($cart->contents)) {
            $lang = new MollieTranslator();
            $paymentClass = $order->getPaymentType()->getModule();
            $_SESSION[$paymentClass . '_error'] = $lang->translate('mollie.payment.webhook.notification.order_cancel_error.description');

            return UrlProvider::generateShopUrl('checkout_payment.php', '', ['payment_error' => $paymentClass]);
        }

        return UrlProvider::generateShopUrl('account_history_info.php', '', ['order_id' => $order->getOrderId()]);
    }
}