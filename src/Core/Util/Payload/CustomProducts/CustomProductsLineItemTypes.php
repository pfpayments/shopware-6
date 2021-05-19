<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\Util\Payload\CustomProducts;

class CustomProductsLineItemTypes {

	public const LINE_ITEM_TYPE_PRODUCT                    = 'product';
	public const LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS        = 'customized-products';
	public const LINE_ITEM_TYPE_CUSTOMIZED_PRODUCTS_OPTION = 'customized-products-option';

	public const PRODUCT_OPTION_TYPE_DATETIME     = 'datetime';
	public const PRODUCT_OPTION_TYPE_TIMESTAMP    = 'timestamp';
	public const PRODUCT_OPTION_TYPE_IMAGE_UPLOAD = 'imageupload';
	public const PRODUCT_OPTION_TYPE_IMAGE_SELECT = 'imageselect';
	public const PRODUCT_OPTION_TYPE_FILE_UPLOAD  = 'fileupload';
}