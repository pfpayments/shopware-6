<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Api\Refund\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\{
  HttpFoundation\Request,
  HttpFoundation\Response,
  Routing\Attribute\Route,
};
use PostFinanceCheckoutPayment\Core\{
  Api\Refund\Service\RefundService,
  Api\Transaction\Service\TransactionService,
  Settings\Service\SettingsService,
  Util\Exception\RefundNotSupportedException
};

/**
 * Class RefundController
 *
 * @package PostFinanceCheckoutPayment\Core\Api\Refund\Controller
 *
 */
#[Package('sales-channel')]
#[Route(defaults: ['_routeScope' => ['api']])]
class RefundController extends AbstractController
{
    /**
     * @var \PostFinanceCheckoutPayment\Core\Api\Refund\Service\RefundService
     */
    protected $refundService;
    
    /**
     * @var \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService
     */
    protected $settingsService;
    
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService
     */
    protected $transactionService;

    /**
     * RefundController constructor.
     *
     * @param \PostFinanceCheckoutPayment\Core\Api\Refund\Service\RefundService $refundService
     * @param \PostFinanceCheckoutPayment\Core\Settings\Service\SettingsService $settingsService
     * @param \PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService $transactionService
     */
    public function __construct(RefundService $refundService, SettingsService $settingsService, TransactionService $transactionService)
    {
        $this->settingsService = $settingsService;
        $this->refundService = $refundService;
        $this->transactionService = $transactionService;
    }
    
    /**
     * @param \Psr\Log\LoggerInterface $logger
     * @internal
     * @required
     *
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
    
    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Shopware\Core\Framework\Context $context
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \PostFinanceCheckout\Sdk\ApiException
     * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
     * @throws \PostFinanceCheckout\Sdk\VersioningException
     */
    #[Route("/api/_action/postfinancecheckout/refund/create-refund/",
      name: "api.action.postfinancecheckout.refund.create-refund",
      methods: ['POST'])]
    public function createRefund(Request $request, Context $context): Response
    {
        $salesChannelId = $request->request->get('salesChannelId');
        $transactionId = $request->request->get('transactionId');
        $quantity = (int)$request->request->get('quantity');
        $lineItemId = $request->request->get('lineItemId');

        if ($quantity === null || $quantity <= 0) {
            return new Response('refundQuantityZero', Response::HTTP_BAD_REQUEST);
        }        

        $settings = $this->settingsService->getSettings($salesChannelId);
        $apiClient = $settings->getApiClient();
        
        $transaction = $apiClient->getTransactionService()->read($settings->getSpaceId(), $transactionId);

        $maxQuantity = $this->refundService->getMaxRefundableQuantity($transaction, $context, $lineItemId);

        if ($quantity > $maxQuantity) {
            return new Response('refundExceedsQuantity', Response::HTTP_BAD_REQUEST);
        }

        try {
            $refund = $this->refundService->create($transaction, $context, $lineItemId, $quantity);
        } catch (RefundNotSupportedException $exception) {
            $this->logger->info('Payment method does not support online refunds for transaction: ' . $transactionId);
            return new Response('methodDoesNotSupportRefund', Response::HTTP_BAD_REQUEST);
        }

        if ($refund === null) {
            return new Response('Refund was not created. Please check the refund amound or if the item was not refunded before', Response::HTTP_BAD_REQUEST);
        }
        
        return new Response(null, Response::HTTP_NO_CONTENT);
    }
    
    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Shopware\Core\Framework\Context $context
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \PostFinanceCheckout\Sdk\ApiException
     * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
     * @throws \PostFinanceCheckout\Sdk\VersioningException
     */
    #[Route("/api/_action/postfinancecheckout/refund/create-refund-by-amount/",
      name: "api.action.postfinancecheckout.refund.create.refund.by.amount",
      methods: ['POST'])]
    public function createRefundByAmount(Request $request, Context $context): Response
    {
        $salesChannelId = $request->request->get('salesChannelId');
        $transactionId = $request->request->get('transactionId');
        $refundableAmount = $request->request->get('refundableAmount');
        
        if ($refundableAmount === null || $refundableAmount <= 0.0) {
          return new Response('refundAmountZero', Response::HTTP_BAD_REQUEST);
        }

        $settings = $this->settingsService->getSettings($salesChannelId);
        $apiClient = $settings->getApiClient();
        
        $transaction = $apiClient->getTransactionService()->read($settings->getSpaceId(), $transactionId);
        
        $completed = (float) $transaction->getCompletedAmount();
        $refunded  = (float) $transaction->getRefundedAmount();
        $maxRefund = round($completed - $refunded, 2);

        if ($refundableAmount > $maxRefund) {
            return new Response('refundExceedsAmount', Response::HTTP_BAD_REQUEST);
        }
        
        try {
            $refund = $this->refundService->createRefundByAmount($transaction, $refundableAmount, $context);
        } catch (RefundNotSupportedException $exception) {
            $this->logger->info('Payment method does not support online refunds for transaction: ' . $transactionId);
            return new Response('methodDoesNotSupportRefund', Response::HTTP_BAD_REQUEST);
        }

        if ($refund === null) {
            return new Response(null, Response::HTTP_BAD_REQUEST);
        }
        
        return new Response(null, Response::HTTP_NO_CONTENT);
    }
    
    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Shopware\Core\Framework\Context $context
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \PostFinanceCheckout\Sdk\ApiException
     * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
     * @throws \PostFinanceCheckout\Sdk\VersioningException
     */
    #[Route("/api/_action/postfinancecheckout/refund/create-partial-refund/",
      name: "api.action.postfinancecheckout.refund.create.partial.refund",
      methods: ['POST'])]
    public function createPartialRefund(Request $request, Context $context): Response
    {
        $salesChannelId = $request->request->get('salesChannelId');
        $transactionId = $request->request->get('transactionId');
        $refundableAmount = $request->request->get('refundableAmount');
        $lineItemId = $request->request->get('lineItemId');
        
        $settings = $this->settingsService->getSettings($salesChannelId);
        $apiClient = $settings->getApiClient();
        
        $transaction = $apiClient->getTransactionService()->read($settings->getSpaceId(), $transactionId);
        
        try {
            $refund = $this->refundService->createPartialRefund($transaction, $context, $lineItemId, $refundableAmount);
        } catch (RefundNotSupportedException $exception) {
            $this->logger->info('Payment method does not support online refunds for transaction: ' . $transactionId);
            return new Response('methodDoesNotSupportRefund', Response::HTTP_BAD_REQUEST);
        }
        
        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
