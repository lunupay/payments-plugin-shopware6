<?php

declare(strict_types=1);

namespace Lunu\Widget\Service;

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class LunuPayment implements AsynchronousPaymentHandlerInterface
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private string $appId;
    private string $apiSecret;
    private string $apiUrl;
    private string $widgetVersion;
    private string $auth_token;
    private string $widgetURL;

    public function __construct(OrderTransactionStateHandler $transactionStateHandler, SystemConfigService $systemConfigService)
    {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->systemConfigService = $systemConfigService;

        if(null === $this->systemConfigService->get('LunuWidget.config.appID') || null === $this->systemConfigService->get('LunuWidget.config.apiSecret')) {
            return;
        }
        
        $is_sandbox_enabled = $this->systemConfigService->get("LunuWidget.config.sandboxMode");
        $this->appId = $this->systemConfigService->get('LunuWidget.config.appID');
        $this->apiSecret = $this->systemConfigService->get('LunuWidget.config.apiSecret');
        $this->widgetVersion = $is_sandbox_enabled ? 'testing' : 'alpha';
        $this->apiURL = 'https://' . ($is_sandbox_enabled ? 'api.testing' : 'api') . '.lunu.io/api/v1/payments/';
        $this->auth_token = base64_encode($this->appId . ':' . $this->apiSecret);
        $this->widgetURL = 'https://widget' . ($is_sandbox_enabled ? '.testing' : '') . '.lunu.io/#/?';
    }

    /**
     * @throws AsyncPaymentProcessException
     */
    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $order_id = $transaction->getOrder()->getOrderNumber();
        $order_amount = $transaction->getOrder()->getPrice()->getTotalPrice();
        $client_currency = $salesChannelContext->getCurrency()->getIsoCode();
        $callback_url = $transaction->getReturnUrl();
        $payment_description = 'Order #' . $order_id;
        $customer_email = $salesChannelContext->getCustomer()->getEmail();
        /*$idempotence_key = 'sw6_' . time() . '_' . $order_id;
        $lunu_pay_url_create = 'https://' . $lunu_processing_version . '.lunu.io/api/v1/payments/create';

        $ch = curl_init($lunu_pay_url_create);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
            'shop_order_id' => $order_id,
            'email' => $customer_email,
            'amount' => $order_amount, // client_amount is not accepted in testing?
        // in testing, cannot send client_amount and client_currency, get the error:
        // {"error":{"code":99,"message":"Only one of `amount` or `client_amount` should be given at a time"}}
        
        // if sending only "client_amount", the following error is returned by Lunu API;
        // "code":99,"message":"`client_currency` should be provided with `client_amount`"
            'client_currency' => $client_currency,
            'description' => $payment_description,
            'expires' => date("c", time() + 3600) // 1 hour
        )));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Basic ' . $auth_token,
            'Idempotence-Key: ' . $idempotence_key,
            'Content-Type: application/json'
        ));
        $responseBody = curl_exec($ch);
        $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($responseHttpCode !== 200) {
            echo "Response code is invalid<br/>";
            var_dump(array(
                'url' => $lunu_pay_url_create,
                'code' => $responseHttpCode,
                'body' => $responseBody
            ));
            exit;
        }
        $data = json_decode($responseBody, true);*/


        $requestParams = array(
            'shop_order_id' => $order_id,
            'email' => $customer_email,
            'amount' => $order_amount,
            'client_currency' => $client_currency,
            'description' => $payment_description,
            'expires' => date("c", time() + 3600) // 1 hour
        );

        $data = $this->lunuRequest("create", $requestParams, $this->getHeaders($order_id));

        if (!is_array($data)) {
            echo "Response body is invalid<br/>";
            var_dump(array(
                'url' => $lunu_pay_url_create,
                'code' => $responseHttpCode,
                'body' => $responseBody
            ));
            exit;
        }
        if (isset($data['error']) && is_array($data['error'])) { // "is_array" alone causes error
            echo "Processing error:<br/>";
            var_dump(array(
                'url' => $lunu_pay_url_create,
                'code' => $responseHttpCode,
                'body' => $responseBody,
                'error' => $data['error'],
            ));
            exit;
        }
        if (!is_array($data['response'])) {
            echo "Response is empty<br/>";
            var_dump(array(
                'url' => $lunu_pay_url_create,
                'code' => $responseHttpCode,
                'body' => $responseBody
            ));
            exit;
        }
        $response = $data['response'];
        $confirmation_token = $response['confirmation_token'];
        if (empty($confirmation_token)) {
            echo "confirmation_token is empty<br/>";
            var_dump(array(
                'url' => $lunu_pay_url_create,
                'code' => $responseHttpCode,
                'body' => $responseBody
            ));
            exit;
        }

        // Redirect to external gateway
        $redirectUrl = ($this->widgetURL .
            http_build_query(array(
                'action' => 'select',
                'token' => $confirmation_token,
                'success' => $callback_url . '&state=success&orderID=' . $response['id'],
                'cancel' => $callback_url . '&cancel=true'
            )));

        return new RedirectResponse($redirectUrl);
    }

    /**
     * @throws CustomerCanceledAsyncPaymentException
     */
    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $context = $salesChannelContext->getContext();

        // Check if the user has cancelled.
        if ($request->query->getBoolean('cancel')) {
            throw new CustomerCanceledAsyncPaymentException(
                $transactionId,
                'Customer canceled the payment on the Lunu page'
            );
        }

        $paymentState = $request->query->getAlpha('state');

        if ($paymentState === 'success') {
            $data = $this->lunuRequest("get/" . $request->query->get('orderID'), null, $this->getHeaders($transaction->getOrder()->getOrderNumber()));
            $response = $data['response'];

            if($response['shop_order_id'] === $transaction->getOrder()->getOrderNumber()) {
                $this->transactionStateHandler->paid($transactionId, $context);
            }
        }
    }

    private function lunuRequest($method, $data, $headers) {
        $ch = curl_init($this->apiURL . $method);
        if(!empty($data)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $responseBody = curl_exec($ch);
        $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($responseHttpCode !== 200) {
            throw new AsyncPaymentProcessException('TransactionId', 'Invalid HTTP response code: ' . $responseHttpCode);
        }
        return json_decode($responseBody, true);
    }

    private function getHeaders($order_id) {
        return array(
            'Authorization: Basic ' . $this->auth_token,
            'Idempotence-Key: ' . 'sw6_' . time() . '_' . $order_id,
            'Content-Type: application/json'
        );
    }
}