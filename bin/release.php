<?php declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

const COMPOSER_JSON_DEST = __DIR__ . '/../composer.json';
const SYS_TMP_DIR        = __DIR__ . '/../';
const TMP_DIR            = __DIR__ . '/../';

// Type of release you are making
const RELEASE_GIT_ENV    = 'GIT';
const RELEASE_SW_ENV     = 'SW';

$release_env = ($argv[1] == RELEASE_SW_ENV) ? RELEASE_SW_ENV : RELEASE_GIT_ENV;

$formatter        = new LineFormatter(null, null, false, true);
$logger           = (new Logger('release'))
	->pushHandler((new StreamHandler('php://stdout'))->setFormatter($formatter));

$composerJsonData = json_decode(file_get_contents(COMPOSER_JSON_DEST), true);
$composerJsonData['require']['shopware/core']       = '^6.2';
$composerJsonData['require']['shopware/storefront'] = '^6.2';

switch ($release_env) {
	case RELEASE_GIT_ENV:
		exec('composer require postfinancecheckout/sdk 2.1.1 -d /var/www/shopware.local');
		$composerJsonData['require']['postfinancecheckout/sdk'] = '2.1.1';
		break;
	case RELEASE_SW_ENV:
		exec('composer require postfinancecheckout/sdk 2.1.1 -d /var/www/shopware.local/custom/plugins/PostFinanceCheckoutPayment');
		break;
}

$logger->info('Adding shopware/core and shopware/storefront to the composer.json.');
file_put_contents(
	TMP_DIR . '/composer.json',
	json_encode($composerJsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
chdir(TMP_DIR);
exec('rm -fr composer.lock');