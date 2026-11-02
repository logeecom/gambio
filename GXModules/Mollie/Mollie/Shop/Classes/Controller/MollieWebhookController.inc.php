<?php

use Mollie\BusinessLogic\Http\Proxy;
use Mollie\BusinessLogic\OrderReference\Exceptions\MollieReferenceNotFoundException;
use Mollie\BusinessLogic\OrderReference\Model\OrderReference;
use Mollie\BusinessLogic\OrderReference\OrderReferenceService;
use Mollie\BusinessLogic\WebHook\WebHookContext;
use Mollie\BusinessLogic\WebHook\WebHookTransformer;
use Mollie\Gambio\Utility\DatabaseLock;
use Mollie\Infrastructure\Http\Exceptions\HttpCommunicationException;
use Mollie\Infrastructure\Logger\Logger;
use Mollie\Infrastructure\ServiceRegister;

include_once DIR_FS_CATALOG . '/GXModules/Mollie/Mollie/autoload.php';

/**
 * Class MollieWebhookController
 */
class MollieWebhookController extends HttpViewController
{
    /**
     * Seconds to wait for the customer return or another webhook that is processing the same order
     */
    const LOCK_TIMEOUT = 10;

    /**
     * @var WebHookTransformer
     */
    private $webhookTransformer;

    /**
     * Handles webhook action
     *
     * @return JsonHttpControllerResponse
     * @throws HttpCommunicationException
     */
    public function actionDefault()
    {
        try {
            $success = true;
            $orderReference = $this->_checkOrderReference();
            $rawRequest = file_get_contents('php://input');
            $webhookTransformer = $this->_getWebhookTransformer();
            $isHandled = DatabaseLock::forOrder($orderReference->getShopReference())->execute(
                static::LOCK_TIMEOUT,
                function () use ($webhookTransformer, $rawRequest) {
                    WebHookContext::start();
                    $webhookTransformer->handle($rawRequest);
                    WebHookContext::stop();
                }
            );
            if (!$isHandled) {
                throw new RuntimeException('Order is being processed: ' . $orderReference->getShopReference());
            }

        } catch (Exception $exception) {
            Logger::logError('Cannot process webhook:' . $exception->getMessage());
            $success = false;
            http_response_code(422);
        }

        return MainFactory::create(
            'JsonHttpControllerResponse',
            ['success' => $success]
        );
    }

    /**
     * @return OrderReference
     *
     * @throws MollieReferenceNotFoundException
     */
    protected function _checkOrderReference()
    {
        /** @var OrderReferenceService $orderReferenceService */
        $orderReferenceService = ServiceRegister::getService(OrderReferenceService::CLASS_NAME);
        $orderReference = $orderReferenceService->getByMollieReference($_POST['id']);

        if (!$orderReference) {
            $orderReference = $this->_getOrderReferenceFromPayment($orderReferenceService);
            if (!$orderReference) {
                throw new MollieReferenceNotFoundException('Mollie reference not found:' . $_POST['id']);
            }
        }

        return $orderReference;
    }

    /**
     * @return WebHookTransformer
     */
    protected function _getWebhookTransformer()
    {
        if ($this->webhookTransformer === null) {
            $this->webhookTransformer = ServiceRegister::getService(WebHookTransformer::CLASS_NAME);
        }

        return $this->webhookTransformer;
    }

    /**
     * @param OrderReferenceService $orderReferenceService
     *
     * @return OrderReference|null
     */
    protected function _getOrderReferenceFromPayment(OrderReferenceService $orderReferenceService)
    {
        /** @var Proxy $proxy */
        $proxy = ServiceRegister::getService(Proxy::CLASS_NAME);
        try {
            $payment = $proxy->getPayment($_POST['id']);
            if ($payment && $mollieId = $payment->getOrderId()) {
                return $orderReferenceService->getByMollieReference($mollieId);
            }
        } catch (\Exception $e) {
        }

        return null;
    }
}
