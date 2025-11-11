<?php

declare(strict_types=1);

namespace Lunu\Widget\Service;

use Psr\Log\LoggerInterface;
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

/**
 * Lunu Payment Handler for Shopware 6
 * 
 * Handles cryptocurrency payments through the Lunu payment gateway.
 * Supports both production and sandbox modes.
 */
class LunuPayment implements AsynchronousPaymentHandlerInterface
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;
    private string $appId;
    private string $apiSecret;
    private string $apiUrl;
    private string $widgetVersion;
    private string $authToken;
    private string $widgetURL;

    /**
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param SystemConfigService $systemConfigService
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler, 
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;

        if (null === $this->systemConfigService->get('LunuWidget.config.appID') || null === $this->systemConfigService->get('LunuWidget.config.apiSecret')) {
            return;
        }
        
        $isSandboxEnabled = $this->systemConfigService->get('LunuWidget.config.sandboxMode');
        $this->appId = $this->systemConfigService->get('LunuWidget.config.appID');
        $this->apiSecret = $this->systemConfigService->get('LunuWidget.config.apiSecret');
        $this->widgetVersion = $isSandboxEnabled ? 'sandbox' : 'alpha';
        $this->apiUrl = 'https://' . ($isSandboxEnabled ? 'api.sandbox' : 'api') . '.lunupay.com/api/v1/payments/';
        $this->authToken = base64_encode($this->appId . ':' . $this->apiSecret);
        $this->widgetURL = 'https://widget' . ($isSandboxEnabled ? '.sandbox' : '') . '.lunupay.com/#/?';
    }

    /**
     * Initiates the payment process by creating a Lunu payment and redirecting to the payment widget
     * 
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return RedirectResponse
     * @throws AsyncPaymentProcessException
     */
    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $orderId = $transaction->getOrder()->getOrderNumber();
        $orderAmount = $transaction->getOrder()->getPrice()->getTotalPrice();
        $clientCurrency = $salesChannelContext->getCurrency()->getIsoCode();
        $callbackUrl = $transaction->getReturnUrl();
        $paymentDescription = 'Order #' . $orderId;
        $customerEmail = $salesChannelContext->getCustomer()->getEmail();

        $requestParams = [
            'shop_order_id' => $orderId,
            'email' => $customerEmail,
            'amount' => $orderAmount,
            'client_currency' => $clientCurrency,
            'description' => $paymentDescription,
            'expires' => date('c', time() + 3600) // 1 hour
        ];

        try {
            $data = $this->lunuRequest('create', $requestParams, $this->getHeaders($orderId));

            if (!is_array($data)) {
                $this->logger->error('Lunu payment creation failed: Invalid response format', [
                    'order_id' => $orderId
                ]);
                throw new AsyncPaymentProcessException(
                    $transaction->getOrderTransaction()->getId(),
                    'Payment creation failed: Invalid response format'
                );
            }

            if (isset($data['error']) && is_array($data['error'])) {
                $errorMessage = $data['error']['message'] ?? 'Unknown error';
                $errorCode = $data['error']['code'] ?? 'N/A';
                $this->logger->error('Lunu API error', [
                    'order_id' => $orderId,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage
                ]);
                throw new AsyncPaymentProcessException(
                    $transaction->getOrderTransaction()->getId(),
                    sprintf('Payment creation failed: %s (Code: %s)', $errorMessage, $errorCode)
                );
            }

            if (!isset($data['response']) || !is_array($data['response'])) {
                $this->logger->error('Lunu payment creation failed: Empty response', [
                    'order_id' => $orderId
                ]);
                throw new AsyncPaymentProcessException(
                    $transaction->getOrderTransaction()->getId(),
                    'Payment creation failed: Empty response from payment gateway'
                );
            }

            $response = $data['response'];
            $confirmationToken = $response['confirmation_token'] ?? null;
            
            if (empty($confirmationToken)) {
                $this->logger->error('Lunu payment creation failed: Missing confirmation token', [
                    'order_id' => $orderId
                ]);
                throw new AsyncPaymentProcessException(
                    $transaction->getOrderTransaction()->getId(),
                    'Payment creation failed: Missing confirmation token'
                );
            }

            // Redirect to external gateway
            $redirectUrl = $this->widgetURL . http_build_query([
                'action' => 'select',
                'token' => $confirmationToken,
                'success' => $callbackUrl . '&state=success&orderID=' . $response['id'],
                'cancel' => $callbackUrl . '&cancel=true'
            ]);

            return new RedirectResponse($redirectUrl);
        } catch (AsyncPaymentProcessException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error during Lunu payment creation', [
                'order_id' => $orderId,
                'exception' => $e->getMessage()
            ]);
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'Payment creation failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Finalizes the payment after customer returns from Lunu payment widget
     * 
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return void
     * @throws CustomerCanceledAsyncPaymentException
     * @throws AsyncPaymentProcessException
     */
    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $context = $salesChannelContext->getContext();
        $orderNumber = $transaction->getOrder()->getOrderNumber();

        // Check if the user has cancelled
        if ($request->query->getBoolean('cancel')) {
            $this->logger->info('Customer canceled Lunu payment', [
                'order_number' => $orderNumber,
                'transaction_id' => $transactionId
            ]);
            throw new CustomerCanceledAsyncPaymentException(
                $transactionId,
                'Customer canceled the payment on the Lunu page'
            );
        }

        $paymentState = $request->query->getAlpha('state');
        $lunuOrderId = $request->query->get('orderID');

        if ($paymentState === 'success') {
            try {
                if (empty($lunuOrderId)) {
                    throw new AsyncPaymentProcessException(
                        $transactionId,
                        'Payment verification failed: Missing order ID from payment gateway'
                    );
                }

                $data = $this->lunuRequest('get/' . $lunuOrderId, null, $this->getHeaders($orderNumber));

                if (!isset($data['response']) || !is_array($data['response'])) {
                    $this->logger->error('Lunu payment verification failed: Invalid response', [
                        'order_number' => $orderNumber,
                        'lunu_order_id' => $lunuOrderId
                    ]);
                    throw new AsyncPaymentProcessException(
                        $transactionId,
                        'Payment verification failed: Invalid response from payment gateway'
                    );
                }

                $response = $data['response'];

                if ($response['shop_order_id'] === $orderNumber) {
                    $this->transactionStateHandler->paid($transactionId, $context);
                    $this->logger->info('Lunu payment completed successfully', [
                        'order_number' => $orderNumber,
                        'lunu_order_id' => $lunuOrderId
                    ]);
                } else {
                    $this->logger->error('Lunu payment verification failed: Order ID mismatch', [
                        'expected_order_number' => $orderNumber,
                        'received_order_number' => $response['shop_order_id'] ?? 'null',
                        'lunu_order_id' => $lunuOrderId
                    ]);
                    throw new AsyncPaymentProcessException(
                        $transactionId,
                        'Payment verification failed: Order ID mismatch'
                    );
                }
            } catch (AsyncPaymentProcessException $e) {
                throw $e;
            } catch (\Exception $e) {
                $this->logger->error('Unexpected error during Lunu payment finalization', [
                    'order_number' => $orderNumber,
                    'exception' => $e->getMessage()
                ]);
                throw new AsyncPaymentProcessException(
                    $transactionId,
                    'Payment verification failed: ' . $e->getMessage()
                );
            }
        }
    }

    /**
     * Makes a request to the Lunu API
     * 
     * @param string $method API endpoint method
     * @param array|null $data Request payload
     * @param array $headers HTTP headers
     * @return array Response data
     * @throws AsyncPaymentProcessException
     */
    private function lunuRequest(string $method, ?array $data, array $headers): array
    {
        $url = $this->apiUrl . $method;
        $ch = curl_init($url);
        
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $responseBody = curl_exec($ch);
        $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($responseBody === false) {
            $this->logger->error('Lunu API request failed', [
                'url' => $url,
                'error' => $curlError
            ]);
            throw new AsyncPaymentProcessException(
                'unknown',
                'Failed to communicate with payment gateway: ' . $curlError
            );
        }
        
        if ($responseHttpCode !== 200) {
            $this->logger->error('Lunu API returned non-200 status code', [
                'url' => $url,
                'status_code' => $responseHttpCode,
                'response' => $responseBody
            ]);
            throw new AsyncPaymentProcessException(
                'unknown',
                sprintf('Payment gateway returned error status: %d', $responseHttpCode)
            );
        }
        
        $decodedResponse = json_decode($responseBody, true);
        
        if (!is_array($decodedResponse)) {
            $this->logger->error('Lunu API returned invalid JSON', [
                'url' => $url,
                'response' => $responseBody
            ]);
            throw new AsyncPaymentProcessException(
                'unknown',
                'Payment gateway returned invalid response format'
            );
        }
        
        return $decodedResponse;
    }

    /**
     * Generates HTTP headers for Lunu API requests
     * 
     * @param string $orderId Shop order ID for idempotency key
     * @return array HTTP headers
     */
    private function getHeaders(string $orderId): array
    {
        return [
            'Authorization: Basic ' . $this->authToken,
            'Idempotence-Key: sw6_' . time() . '_' . $orderId,
            'Content-Type: application/json'
        ];
    }
}