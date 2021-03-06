<?php

use Mollie\Gambio\Entity\Repository\GambioConfigRepository;
use Mollie\Gambio\Utility\PathProvider;
use Mollie\Infrastructure\Configuration\Configuration;
use Mollie\Infrastructure\ServiceRegister;

require_once __DIR__ . '/mollie/mollie.php';

/**
 * Class mollie_ccard
 */
class mollie_creditcard extends mollie
{
    public $title = 'Credit card';

    public function __construct()
    {
        parent::__construct();

        $componentsKey = $this->_formatKey('COMPONENTS_STATUS');
        $useComponents = @constant($componentsKey);
        if (empty($useComponents) && $this->_isInstalled()) {
            $this->setInitialMollieComponentsUsage($this->_formatKey('COMPONENTS_STATUS', true));
            define($componentsKey, 'true');
        }
    }

    /**
     * @inheritDoc
     * @return string[][]
     */
    public function _configuration()
    {
        $config = parent::_configuration();
        $config['COMPONENTS_STATUS'] = $this->getComponentsConfig();

        return $config;
    }

    /**
     * @return array|bool
     * @throws \Mollie\Infrastructure\Http\Exceptions\HttpAuthenticationException
     * @throws \Mollie\Infrastructure\Http\Exceptions\HttpCommunicationException
     */
    public function selection()
    {
        $selection = parent::selection();
        if (!$selection) {
            return false;
        }

        $configKey = $this->_formatKey('COMPONENTS_STATUS');
        if (@constant($configKey) === 'true') {
            $selection['fields'] = [
                [
                    'title' => $this->_renderCreditCardInfo(),
                    'field' => '',
                ]
            ];
        }

        return $selection;
    }

    /**
     * @inheritDoc
     * @return string
     */
    public function process_button()
    {
        if (!empty($_POST['mollieCardToken'])) {
            $_SESSION['mollie_card_token'] = $_POST['mollieCardToken'];
        }

        return parent::process_button();
    }

    /**
     * @return string|string[]
     * @throws Exception
     */
    private function _renderCreditCardInfo()
    {
        /** @var \Mollie\Gambio\Services\Business\ConfigurationService $configService */
        $configService = ServiceRegister::getService(Configuration::CLASS_NAME);
        $template = PathProvider::getShopTemplatePath('mollie_credit_card.html');

        $currentLanguage = $_SESSION['language_code'];
        $countryCode = $currentLanguage === 'en' ? 'US' : strtoupper($currentLanguage);
        $lang = $currentLanguage . '_' . $countryCode;
        $profileId = $configService->getWebsiteProfile() ? $configService->getWebsiteProfile()->getId() : null;

        return mollie_render_template(
            $template,
            [
                'profile_id' => $profileId,
                'test_mode' => $configService->isTestMode(),
                'lang' => $lang,
                'payment_method' => $this->code
            ]
        );
    }

    /**
     * @param $key
     */
    private function setInitialMollieComponentsUsage($key)
    {
        $repository = new GambioConfigRepository();
        $insert = $this->getComponentsConfig();
        $insert['key'] = $key;
        $insert['legacy_group_id'] = 6;
        $insert['sort_order'] = 0;

        $repository->insert($insert);
    }

    /**
     * @return string[]
     */
    private function getComponentsConfig()
    {
        return [
            'value' => 'true',
            'type' => 'switcher',
        ];
    }
}
