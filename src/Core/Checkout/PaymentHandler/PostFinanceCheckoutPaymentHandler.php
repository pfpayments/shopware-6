<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\PaymentHandler;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
    Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity,
    Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler,
    Checkout\Payment\Cart\PaymentTransactionStruct,
    Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler,
    Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType,
    Checkout\Payment\PaymentException,
    Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException,
    Framework\App\AppException,
    Framework\Api\Context\SalesChannelApiSource,
    Framework\Context,
    Framework\DataAbstractionLayer\EntityRepository,
    Framework\DataAbstractionLayer\Search\Criteria,
    Framework\DataAbstractionLayer\Search\Sorting\FieldSorting,
    Framework\Struct\Struct,
    Framework\Validation\DataBag\RequestDataBag,
    System\SalesChannel\Context\SalesChannelContextService,
    System\SalesChannel\Context\SalesChannelContextServiceParameters
};
use Shopware\Core\Framework\Util\Random;
use PostFinanceCheckoutPayment\Core\Checkout\Cart\CustomCartPersister;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

use Symfony\Component\{
    HttpFoundation\RedirectResponse,
    HttpFoundation\Request
};
use PostFinanceCheckout\Sdk\Model\TransactionState;
use PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService;


/**
 * Class PostFinanceCheckoutPaymentHandler
 *
 * @package PostFinanceCheckoutPayment\Core\Checkout\PaymentHandler
 */
class PostFinanceCheckoutPaymentHandler extends AbstractPaymentHandler
{

    /**
     * @var CustomCartPersister
     */
    private CustomCartPersister $cartPersister;

    /**
     * @var \PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService
     */
    protected $transactionService;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;
    /**
     * @var \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;

    protected SalesChannelContextService $salesChannelContextService;

    protected EntityRepository $orderTransactionRepository;

    /**
     * PostFinanceCheckoutPaymentHandler constructor.
     *
     * @param \PostFinanceCheckoutPayment\Core\Api\Transaction\Service\TransactionService $transactionService
     * @param \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler $orderTransactionStateHandler
     * @param SalesChannelContextService $salesChannelContextService
     * @param EntityRepository $orderTransactionRepository
     */
    public function __construct(
        CustomCartPersister $cartPersister,
        TransactionService $transactionService,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        SalesChannelContextService $salesChannelContextService,
        EntityRepository $orderTransactionRepository
    ) {
        $this->cartPersister = $cartPersister;
        $this->transactionService = $transactionService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->salesChannelContextService = $salesChannelContextService;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    /**
     * @param \Psr\Log\LoggerInterface $logger
     *
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * The pay function will be called after the customer completed the order.
     * Allows to process the order and store additional information.
     *
     * A redirect to the url will be performed
     *
     * @param \Symfony\Component\HttpFoundation\Request
     * @param \Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct $transaction
     * @param \Shopware\Core\Framework\Context $context
     * @param \Shopware\Core\Framework\Struct\Struct $validateStruct
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context           $context,
        ?Struct $validateStruct
    ): RedirectResponse
    {
        try {
            $orderTransactionId = $transaction->getOrderTransactionId();
            $orderTransaction = $this->orderTransactionRepository->search(
                (new Criteria([$orderTransactionId]))
                    ->addAssociation('order'), $context
            )->getEntities()->first();

            $contextSource = $context->getSource();
            if ($contextSource instanceof SalesChannelApiSource) {
                $salesChannelContextId = $contextSource->getSalesChannelId();
            }

            $parameters = new SalesChannelContextServiceParameters($salesChannelContextId, $request->getSession()->get("sw-context-token", Random::getAlphanumericString(32)), originalContext: $context);
            $salesChannelContext = $this->salesChannelContextService->get($parameters);
            $redirectUrl = $transaction->getReturnUrl();

            if ($orderTransaction->getOrder()->getAmountTotal() > 0) {
                $transactionId = $_SESSION['transactionId'] ?? null;
                if ($transactionId === null) {
                    $this->transactionService->createPendingTransaction($transaction, $salesChannelContext);
                }
                $redirectUrl = $this->transactionService->create($transaction, $salesChannelContext);
            }
            return new RedirectResponse($redirectUrl);

        } catch (\Throwable $e) {
            unset($_SESSION['transactionId']);
            $errorMessage = 'An error occurred during the communication with external payment gateway : ' . $e->getMessage();
            $this->logger->critical($errorMessage);
            throw PaymentException::customerCanceled($transaction->getOrderTransaction()->getId(), $errorMessage);
        }
    }

    /**
     * The finalize function will be called when the user is redirected back to shop from the payment gateway.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct $transaction
     * @param \Shopware\Core\Framework\Context $context
     * @throws \PostFinanceCheckout\Sdk\ApiException
     * @throws \PostFinanceCheckout\Sdk\Http\ConnectionException
     * @throws \PostFinanceCheckout\Sdk\VersioningException
     * @throws \Exception when the payment was canceled by the customer
     */
    public function finalize(
        Request                  $request,
        PaymentTransactionStruct $transaction,
        Context                  $context
    ): void
    {
        $orderTransactionId = $transaction->getOrderTransactionId();
        $orderTransaction = $this->orderTransactionRepository->search(
            (new Criteria([$orderTransactionId]))
                ->addAssociation('order'), $context
        )->getEntities()->first();

        if ($orderTransaction->getOrder()->getAmountTotal() > 0) {
            $transactionEntity = $this->transactionService->getByOrderId(
                $orderTransaction->getOrder()->getId(),
                $context
            );

            $postFinanceCheckoutTransaction = $this->transactionService->read(
                $transactionEntity->getTransactionId(),
                $transactionEntity->getSalesChannelId()
            );

            if (in_array($postFinanceCheckoutTransaction->getState(), [TransactionState::FAILED])) {
                $errorMessage = strtr('Customer canceled payment for :orderId on SalesChannel :salesChannelName', [
                    ':orderId' => $orderTransaction->getOrder()->getId(),
                    ':salesChannelName' => $transactionEntity->getSalesChannelId(),
                ]);
                unset($_SESSION['transactionId']);
                $this->logger->info($errorMessage);
                throw PaymentException::customerCanceled($transaction->getOrderTransaction()->getId(), $errorMessage);
            }
        } else {
            $this->orderTransactionStateHandler->paid($orderTransaction->getId(), $context);
        }

        $token = $request->getSession()->get('sw-context-token');
        if ($token) {
            $salesChannelId = $transactionEntity->getSalesChannelId();
            $parameters = new SalesChannelContextServiceParameters($salesChannelId, $token, originalContext: $context);
            $salesChannelContext = $this->salesChannelContextService->get($parameters);

            $salesChannelContext->getContext()->addState('do-cart-delete');
            $this->logger->info('Clearing cart with token: ' . $token);
            $this->cartPersister->delete($salesChannelContext->getToken(), $salesChannelContext);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supports(
        PaymentHandlerType $type,
        string $paymentMethodId,
        Context $context
        ): bool {
        if ($type === PaymentHandlerType::RECURRING) {
            return false;
        }
        return true;
    }

}
