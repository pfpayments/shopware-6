<?php declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

const COMPOSER_JSON_DEST = __DIR__ . '/../composer.json';
const SYS_TMP_DIR        = __DIR__ . '/../';
const TMP_DIR            = __DIR__ . '/../';
const SHOPWARE_VERSIONS  = '6.6.*';

// Type of release you are making
const RELEASE_GIT_ENV = 'GIT';
const RELEASE_SW_ENV  = 'SW';

$release_env = ($argv[1] == RELEASE_SW_ENV) ? RELEASE_SW_ENV : RELEASE_GIT_ENV;

$formatter = new LineFormatter(null, null, false, true);
$logger    = (new Logger('release'))
			->pushHandler((new StreamHandler('php://stdout'))->setFormatter($formatter));

$composerJsonData                                   = json_decode(file_get_contents(COMPOSER_JSON_DEST), true);
$composerJsonData['require']['shopware/core']       = SHOPWARE_VERSIONS;
$composerJsonData['require']['shopware/storefront'] = SHOPWARE_VERSIONS;

switch ($release_env) {
	case RELEASE_GIT_ENV:
		exec('composer require postfinancecheckout/sdk 4.4.0 -d /var/www/html');
		$composerJsonData['require']['postfinancecheckout/sdk'] = '4.4.0';
		break;
	case RELEASE_SW_ENV:
		exec('composer require postfinancecheckout/sdk 4.4.0 -d /var/www/html/custom/plugins/PostFinanceCheckoutPayment');
		break;
}

$composerJsonData['version'] = '6.1.7';

$logger->info('Adding shopware/core and shopware/storefront to the composer.json.');
file_put_contents(
	TMP_DIR . '/composer.json',
	json_encode($composerJsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
chdir(TMP_DIR);
exec('rm -fr composer.lock');
exec('rm -fr bin');