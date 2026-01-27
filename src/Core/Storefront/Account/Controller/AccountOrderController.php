<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Storefront\Account\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
  Checkout\Cart\Exception\CustomerNotLoggedInException,
  Checkout\Customer\CustomerEntity,
  PlatformRequest,
  System\SalesChannel\SalesChannelContext
};
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\{
  HttpFoundation\HeaderUtils,
  HttpFoundation\RequestStack,
  HttpFoundation\Response,
  Routing\Attribute\Route,
  Security\Core\Exception\AccessDeniedException
};
use PostFinanceCheckoutPayment\Core\{
  Api\Transaction\Service\TransactionService,
  Checkout\Service\InvoiceService
};

#[Package('storefront')]
#[Route(defaults: ['_routeScope' => ['storefront']])]
/**
 * This controller handles account-related actions for orders, specifically
 * allowing customers to download their invoice documents.
 */
class AccountOrderController extends StorefrontController
{
  /**
   * @var LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * @var TransactionService
   * Local transaction service for order data retrieval.
   */
  protected TransactionService $transactionService;

  /**
   * @var RequestStack
   * Symfony service to access the current request context.
   */
  protected RequestStack $requestStack;

  /**
   * @var InvoiceService
   * Service to fetch invoice documents from WhitelabelMachineName.
   */
  private InvoiceService $invoiceService;

  /**
   * @param TransactionService $transactionService
   * @param RequestStack $requestStack
   * @param InvoiceService $invoiceService
   */
  public function __construct(
    TransactionService $transactionService,
    RequestStack $requestStack,
    InvoiceService $invoiceService
  ) {
    $this->transactionService = $transactionService;
    $this->requestStack = $requestStack;
    $this->invoiceService = $invoiceService;
  }

  /**
   * @param LoggerInterface $logger
   */
  public function setLogger(LoggerInterface $logger): void
  {
    $this->logger = $logger;
  }

  /**
   * Downloads an invoice document for a specific order.
   *
   * @param string $orderId The ID of the order.
   * @param SalesChannelContext $salesChannelContext The context.
   * @return Response The PDF document as a download response.
   */
  #[Route(
    path: "/postfinancecheckout/account/order/download/invoice/document/{orderId}",
    name: "frontend.postfinancecheckout.account.order.download.invoice.document",
    methods: ['GET']
  )]
  public function downloadInvoiceDocument(string $orderId, SalesChannelContext $salesChannelContext): Response
  {
    try {
      // Ensure the user is logged in.
      $customer = $this->getLoggedInCustomer();

      // Fetch the transaction entity to verify ownership.
      $transactionEntity = $this->transactionService->getByOrderId($orderId, $salesChannelContext->getContext());

      // Security check: ensure the document belongs to the logged-in customer.
      if (strcasecmp((string)$customer->getCustomerNumber(), (string)$transactionEntity->getData()['customerId']) != 0) {
        throw new AccessDeniedException();
      }

      // Retrieve the invoice document metadata and content.
      /** @var object $invoiceDocument */
      $invoiceDocument = $this->invoiceService->getInvoiceDocument($orderId, $salesChannelContext);

      // Sanitize the filename for the download.
      $filename = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '_', (string)$invoiceDocument->getTitle()) . '.pdf';
      $disposition = HeaderUtils::makeDisposition(
        HeaderUtils::DISPOSITION_ATTACHMENT,
        $filename,
        $filename
      );

      // Create the response with the PDF content (base64 decoded).
      $response = new Response(base64_decode((string)$invoiceDocument->getData()));
      $response->headers->set('Content-Type', (string)$invoiceDocument->getMimeType());
      $response->headers->set('Content-Disposition', $disposition);

      return $response;
    } catch (\Exception $e) {
      $this->logger->error($e->getMessage());
      return $this->redirectToRoute('frontend.home.page');
    }
  }

  /**
   * Helper to retrieve the currently logged-in customer.
   *
   * @return CustomerEntity
   * @throws CustomerNotLoggedInException
   */
  protected function getLoggedInCustomer(): CustomerEntity
  {
    $request = $this->requestStack->getCurrentRequest();

    if (!$request) {
      throw new CustomerNotLoggedInException();
    }

    /** @var SalesChannelContext|null $context */
    $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

    if ($context && $context->getCustomer() && $context->getCustomer()->getGuest() === false) {
      return $context->getCustomer();
    }

    throw new CustomerNotLoggedInException();
  }
}
