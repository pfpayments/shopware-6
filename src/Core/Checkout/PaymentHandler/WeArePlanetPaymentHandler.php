<?php declare(strict_types=1);

namespace WeArePlanetPayment\Core\Checkout\PaymentHandler;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
    Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler,
    Checkout\Payment\Cart\AsyncPaymentTransactionStruct,
    Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface,
    Checkout\Payment\Exception\AsyncPaymentFinalizeException,
    Checkout\Payment\Exception\AsyncPaymentProcessException,
    Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException,
    Framework\Validation\DataBag\RequestDataBag,
    System\SalesChannel\SalesChannelContext
};
use Symfony\Component\{
    HttpFoundation\RedirectResponse,
    HttpFoundation\Request
};
use WeArePlanet\Sdk\Model\TransactionState;
use WeArePlanetPayment\Core\Api\Transaction\Service\TransactionService;


/**
 * Class WeArePlanetPaymentHandler
 *
 * @package WeArePlanetPayment\Core\Checkout\PaymentHandler
 */
class WeArePlanetPaymentHandler implements AsynchronousPaymentHandlerInterface
{

    /**
     * @var \WeArePlanetPayment\Core\Api\Transaction\Service\TransactionService
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

    /**
     * WeArePlanetPaymentHandler constructor.
     *
     * @param \WeArePlanetPayment\Core\Api\Transaction\Service\TransactionService $transactionService
     * @param \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler $orderTransactionStateHandler
     */
    public function __construct(TransactionService $transactionService, OrderTransactionStateHandler $orderTransactionStateHandler)
    {
        $this->transactionService = $transactionService;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
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
     * The pay function will be called after the customer completed the order.
     * Allows to process the order and store additional information.
     *
     * A redirect to the url will be performed
     *
     * @param \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct $transaction
     * @param \Shopware\Core\Framework\Validation\DataBag\RequestDataBag $dataBag
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag                $dataBag,
        SalesChannelContext           $salesChannelContext
    ): RedirectResponse
    {
        try {
            $redirectUrl = $transaction->getReturnUrl();
            if ($transaction->getOrder()->getAmountTotal() > 0) {
                $transactionId = $_SESSION['transactionId'] ?? null;
                if ($transactionId === null) {
                    $this->transactionService->createPendingTransaction($salesChannelContext);
                }
                $redirectUrl = $this->transactionService->create($transaction, $salesChannelContext);
            }
            return new RedirectResponse($redirectUrl);

        } catch (\Exception $e) {
            unset($_SESSION['transactionId']);
            $errorMessage = 'An error occurred during the communication with external payment gateway : ' . $e->getMessage();
            $this->logger->critical($errorMessage);
            throw new \Exception($transaction->getOrderTransaction()->getId() . ': ' . $errorMessage);
        }
    }

    /**
     * The finalize function will be called when the user is redirected back to shop from the payment gateway.
     *
     * Throw a @param \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct $transaction
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     * @throws \WeArePlanet\Sdk\ApiException
     * @throws \WeArePlanet\Sdk\Http\ConnectionException
     * @throws \WeArePlanet\Sdk\VersioningException
     * @see AsyncPaymentFinalizeException exception if an error ocurres while calling an external payment API
     * Throw a @see CustomerCanceledAsyncPaymentException exception if the customer canceled the payment process on
     * payment provider page
     *
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request                       $request,
        SalesChannelContext           $salesChannelContext
    ): void
    {
        if ($transaction->getOrder()->getAmountTotal() > 0) {
            $transactionEntity = $this->transactionService->getByOrderId(
                $transaction->getOrder()->getId(),
                $salesChannelContext->getContext()
            );

            $weArePlanetTransaction = $this->transactionService->read(
                $transactionEntity->getTransactionId(),
                $salesChannelContext->getSalesChannel()->getId()
            );

            if (in_array($weArePlanetTransaction->getState(), [TransactionState::FAILED])) {
                $errorMessage = strtr('Customer canceled payment for :orderId on SalesChannel :salesChannelName', [
                    ':orderId' => $transaction->getOrder()->getId(),
                    ':salesChannelName' => $salesChannelContext->getSalesChannel()->getName(),
                ]);
                unset($_SESSION['transactionId']);
                $this->logger->info($errorMessage);
                throw new \Exception($transaction->getOrder()->getId());
            }
        } else {
            $this->orderTransactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
        }
    }
}
