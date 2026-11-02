<?php

namespace Mollie\Gambio\Entity\Repository;

/**
 * Class GambioLanguageRepository
 *
 * @package Mollie\Gambio\Entity\Repository
 */
class GambioLanguageRepository extends GambioBaseRepository
{

    CONST TABLE_NAME = 'languages';

    /**
     * Fetched all languages from store
     *
     * @return array
     */
    public function getLanguages()
    {
        return $this->queryBuilder->select()
            ->select('languages_id AS id')
            ->order_by('sort_order ASC')
            ->get('languages')
            ->result_array();
    }

    /**
     * Returns the id of the language with the given code, or null if the language does not exist
     *
     * @param string $code
     *
     * @return int|null
     */
    public function getLanguageIdByCode($code)
    {
        $language = $this->queryBuilder->select('languages_id AS id')
            ->where('code', (string)$code)
            ->get('languages')
            ->row_array();

        return empty($language) ? null : (int)$language['id'];
    }

    /**
     * @inheritDoc
     */
    protected function _getTableName()
    {
        return static::TABLE_NAME;
    }

    /**
     * @inheritDoc
     */
    protected function _getIdentifierKey()
    {
        return 'languages_id';
    }
}
