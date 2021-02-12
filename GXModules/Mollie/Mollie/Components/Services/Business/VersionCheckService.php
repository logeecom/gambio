<?php

namespace Mollie\Gambio\Services\Business;

use Mollie\Gambio\Utility\MollieTranslator;

/**
 * Class VersionCheckService
 *
 * @package Mollie\Gambio\Services\Business
 */
class VersionCheckService extends \Mollie\BusinessLogic\VersionCheck\VersionCheckService
{

    /**
     * @inheritDoc
     */
    protected function flashMessage()
    {
        $messageKey = 'mollie_version_outdated_message';
        $params = [
            '{versionNumber}' => $this->getConfigService()->getExtensionVersion(),
            '{downloadUrl}' => $this->getConfigService()->getExtensionDownloadUrl(),
        ];

        $lang       = new MollieTranslator();
        $message    = $lang->translate($messageKey, $params);

        $GLOBALS['messageStack']->add_session($message, 'info');
    }
}
