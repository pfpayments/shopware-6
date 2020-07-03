<?php declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Resources\app\storefront\src\snippets\de_DE;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class SnippetFile_de_DE implements SnippetFileInterface {
	public function getName(): string
	{
		return 'postfinancecheckout.de-DE';
	}

	public function getPath(): string
	{
		return __DIR__ . '/postfinancecheckout.de-DE.json';
	}

	public function getIso(): string
	{
		return 'de-DE';
	}

	public function getAuthor(): string
	{
		return 'customweb GmbH';
	}

	public function isBase(): bool
	{
		return false;
	}
}
