<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Checkout\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use PostFinanceCheckoutPayment\Core\Util\Payload\CustomProducts\CustomProductsLineItemTypes;

/**
 * This service handles the reconstruction of a Shopware cart from an existing order.
 * It ensures that line items, including those from complex plugins like 'Customized Products',
 * are correctly re-added to a fresh cart.
 */
class CartRecoveryService
{
    /**
     * @var CartService
     * Shopware service for cart-related operations.
     */
    private CartService $cartService;

    /**
     * @var LineItemFactoryRegistry
     * Registry to create Shopware LineItems from raw data.
     */
    private LineItemFactoryRegistry $lineItemFactoryRegistry;

    /**
     * @var EntityRepository
     * Repository for accessing order data.
     */
    private EntityRepository $orderRepository;

    /**
     * @var object|null
     * Optional route service for adding customized products to the cart.
     */
    private ?object $addCustomizedProductsRoute;

    /**
     * @param CartService $cartService
     * @param LineItemFactoryRegistry $lineItemFactoryRegistry
     * @param EntityRepository $orderRepository
     * @param object|null $addCustomizedProductsRoute
     */
    public function __construct(
        CartService $cartService,
        LineItemFactoryRegistry $lineItemFactoryRegistry,
        EntityRepository $orderRepository,
        ?object $addCustomizedProductsRoute = null
    ) {
        $this->cartService = $cartService;
        $this->lineItemFactoryRegistry = $lineItemFactoryRegistry;
        $this->orderRepository = $orderRepository;
        $this->addCustomizedProductsRoute = $addCustomizedProductsRoute;
    }

    /**
     * Recreates a cart based on the items in an existing order.
     *
     * @param OrderEntity $order The source order.
     * @param SalesChannelContext $salesChannelContext The current sales channel context.
     * @return Cart The newly created and populated cart.
     */
    public function recreateCartFromOrder(OrderEntity $order, SalesChannelContext $salesChannelContext): Cart
    {
        // Start with a clean slate by deleting any existing cart for the current session.
        $this->cartService->deleteCart($salesChannelContext);
        $cart = $this->cartService->createNew($salesChannelContext->getToken());

        $orderItems = $order->getLineItems();

        if ($orderItems === null) {
            return $cart;
        }

        // Special handling for Customized Products if the plugin logic is available.
        if ($this->hasCustomProducts($orderItems) && $this->addCustomizedProductsRoute) {
            $cart = $this->addCustomProducts($orderItems, $salesChannelContext, $cart);
        }

        /** @var OrderLineItemEntity $orderLineItemEntity */
        foreach ($orderItems as $orderLineItemEntity) {
            $type = (string)$orderLineItemEntity->getType();

            // Skip child items and complex types that should have been handled by specialized logic.
            if ($type !== CustomProductsLineItemTypes::LINE_ITEM_TYPE_PRODUCT || $orderLineItemEntity->getParentId() !== null) {
                continue;
            }

            // Create a standard product line item.
            $lineItem = $this->lineItemFactoryRegistry->create([
                'id'           => $orderLineItemEntity->getId(),
                'quantity'     => (int)$orderLineItemEntity->getQuantity(),
                'referencedId' => (string)$orderLineItemEntity->getReferencedId(),
                'type'         => $type,
            ], $salesChannelContext);

            // Preserve payload data to ensure product options and other metadata are carried over.
            $lineItemPayload = $orderLineItemEntity->getPayload();
            if (!empty($lineItemPayload)) {
                $lineItem->setPayload($lineItemPayload);
            }

            // Add the item to the cart.
            $cart = $this->cartService->add($cart, $lineItem, $salesChannelContext);
        }

        return $cart;
    }

    /**
     * Checks if the order contains any line items belonging to the Customized Products plugin.
     *
     * @param OrderLineItemCollection $orderItems The items in the order.
     * @return bool True if customized products are present.
     */
    private function hasCustomProducts(OrderLineItemCollection $orderItems): bool
    {
        /** @var OrderLineItemEntity $orderItem */
        foreach ($orderItems as $orderItem) {
            if ($orderItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS) {
                return true;
            }
        }
        return false;
    }

    /**
     * Specialized logic to re-add customized products to the cart via the plugin's own route.
     *
     * @param OrderLineItemCollection $orderItems All order items.
     * @param SalesChannelContext $salesChannelContext Context.
     * @param Cart $cart Current cart.
     * @return Cart Updated cart.
     */
    private function addCustomProducts(OrderLineItemCollection $orderItems, SalesChannelContext $salesChannelContext, Cart $cart): Cart
    {
        if (!$this->addCustomizedProductsRoute) {
            return $cart;
        }

        /** @var OrderLineItemEntity $orderItem */
        foreach ($orderItems as $orderItem) {
            if ($orderItem->getType() !== CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS) {
                continue;
            }

            // Find the main product associated with this customized product container.
            $product = $this->getCustomProduct($orderItems, (string)$orderItem->getId());
            if (!$product) continue;

            // Gather the chosen options and their values.
            $productOptions = $this->getCustomProductOptions($orderItems, (string)$orderItem->getId());
            $optionValues = $this->getOptionValues($productOptions);

            // Prepare the data bag for the specialized add-to-cart route.
            $params = new RequestDataBag([
                'customized-products-template' => new RequestDataBag([
                    'id'      => (string)$orderItem->getReferencedId(),
                    'options' => new RequestDataBag($optionValues),
                ]),
            ]);

            $request = new Request([], [
                'lineItems' => [
                    (string)$product->getReferencedId() => [
                        'quantity' => (int)$orderItem->getQuantity(),
                        'id'           => (string)$product->getReferencedId(),
                        'type'         => CustomProductsLineItemTypes::LINE_ITEM_TYPE_PRODUCT,
                        'referencedId' => (string)$product->getReferencedId(),
                        'stackable'    => (bool)$orderItem->getStackable(),
                        'removable'    => (bool)$orderItem->getRemovable(),
                    ]
                ]
            ]);

            // Call the Customized Products plugin's internal logic to add the item with its complex configuration.
            $this->addCustomizedProductsRoute->add($params, $request, $salesChannelContext, $cart);

            // Re-fetch the cart to reflect changes made by the plugin route.
            $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        }

        return $cart;
    }

    /**
     * Finds the main product item within a customized product structure.
     *
     * @param OrderLineItemCollection $orderItems All order items.
     * @param string $parentId The ID of the customized product container.
     * @return OrderLineItemEntity|null The product line item entity.
     */
    private function getCustomProduct(OrderLineItemCollection $orderItems, string $parentId): ?OrderLineItemEntity
    {
        /** @var OrderLineItemEntity $orderItem */
        foreach ($orderItems as $orderItem) {
            if ($orderItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_PRODUCT && $orderItem->getParentId() === $parentId) {
                return $orderItem;
            }
        }
        return null;
    }

    /**
     * Gathers all option items for a customized product.
     *
     * @param OrderLineItemCollection $orderItems All items.
     * @param string $parentId The ID of the customized product container.
     * @return OrderLineItemEntity[] List of option entities.
     */
    private function getCustomProductOptions(OrderLineItemCollection $orderItems, string $parentId): array
    {
        $options = [];
        /** @var OrderLineItemEntity $orderItem */
        foreach ($orderItems as $orderItem) {
            if ($orderItem->getType() === CustomProductsLineItemTypes::LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS_OPTION && $orderItem->getParentId() === $parentId) {
                $options[] = $orderItem;
            }
        }
        return $options;
    }

    /**
     * Converts a list of options into a data structure suitable for the Customized Products logic.
     *
     * @param OrderLineItemEntity[] $productOptions List of option entities.
     * @return array Formatted option values.
     */
    private function getOptionValues(array $productOptions): array
    {
        $optionValues = [];
        foreach ($productOptions as $productOption) {
            $payload = (array)$productOption->getPayload();
            $optionType = (string)($payload['type'] ?? '');

            switch ($optionType) {
                case CustomProductsLineItemTypes::PRODUCT_OPTION_TYPE_IMAGE_UPLOAD:
                case CustomProductsLineItemTypes::PRODUCT_OPTION_TYPE_FILE_UPLOAD:
                    $media = (array)($payload['media'] ?? []);
                    foreach ($media as $mediaItem) {
                        $optionValues[(string)$productOption->getReferencedId()] = new RequestDataBag([
                            'media' => new RequestDataBag([
                                (string)$mediaItem['filename'] => new RequestDataBag([
                                    'id'       => (string)$mediaItem['mediaId'],
                                    'filename' => (string)$mediaItem['filename'],
                                ]),
                            ]),
                        ]);
                    }
                    break;
                default:
                    $optionValues[(string)$productOption->getReferencedId()] = new RequestDataBag([
                        'value' => (string)($payload['value'] ?? ''),
                    ]);
            }
        }
        return $optionValues;
    }

    /**
     * Fetches a full order entity with necessary associations for recovery or display.
     *
     * @param string $orderId The ID of the order.
     * @param Context $context The current system context.
     * @return OrderEntity The order entity.
     * @throws \Exception If the order is not found.
     */
    public function getOrderEntity(string $orderId, Context $context): OrderEntity
    {
        $criteria = (new Criteria([$orderId]))
            ->addAssociation('lineItems.cover')
            ->addAssociation('transactions.paymentMethod')
            ->addAssociation('deliveries.shippingMethod');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->get($orderId);

        if (!$order) {
            throw new \Exception('Order not found');
        }

        return $order;
    }
}
