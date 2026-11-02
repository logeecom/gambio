<?php

namespace Mollie\Gambio\Utility;

/**
 * Class MollieText
 *
 * @package Mollie\Gambio\Utility
 */
class MollieTranslator
{
    /**
     * @var \LanguageTextManager used to retrieve phrases
     */
    protected $languageTextManager;

    /**
     * initializes for the given languages_id or session language.
     * Uses session language as a default if no languages_id is given.
     *
     * @param int|null $languageId
     */
    public function __construct($languageId = null)
    {
        $languageId = $languageId ?: $_SESSION['languages_id'];
        $this->languageTextManager = \MainFactory::create_object('LanguageTextManager', ['module_center_module', $languageId]);
    }

    /**
     * returns a single phrase
     *
     * @param string $placeholder phrase name
     * @param array  $params
     *
     * @return string phrase value
     */
    public function translate($placeholder, array $params = [])
    {
        $translated = $this->languageTextManager->get_text($placeholder);

        return strtr($translated, $params);
    }
}