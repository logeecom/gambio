<?php


namespace Mollie\Gambio\Entity\Repository;

/**
 * Class GambioStatusRepository
 *
 * @package Mollie\Gambio\Entity\Repository
 */
class GambioStatusRepository extends GambioBaseRepository
{

    /**
     * @param array $insertPayload
     *
     * @return string
     */
    public function insertStatus($insertPayload)
    {
        $id = $this->_getNextStatusID();
        foreach ($insertPayload as $row) {
            $row[$this->_getIdentifierKey()] = $id;
            $this->queryBuilder->insert($this->_getTableName(), $row);
        }

        return $id;
    }

    /**
     * Creates order status with a name for every shop language, english name is used as a fallback
     *
     * @param array  $names Status names keyed by the language code
     * @param string $color
     *
     * @return int
     */
    public function createStatus(array $names, $color)
    {
        $languageRepository = new GambioLanguageRepository();
        $insertPayload = [];
        foreach ($languageRepository->getLanguages() as $language) {
            $insertPayload[] = [
                'language_id' => (int)$language['id'],
                'orders_status_name' => isset($names[$language['code']]) ? $names[$language['code']] : $names['en'],
                'color' => $color,
            ];
        }

        return $this->insertStatus($insertPayload);
    }

    /**
     * @return int
     */
    private function _getNextStatusID()
    {
        $result = $this->queryBuilder->select('max(orders_status_id) as freeId')->get($this->_getTableName())->first_row('array');
        if (empty($result['freeId'])) {
            return 1;
        }

        return (int)$result['freeId'] + 1;
    }

    /**
     * @inheritDoc
     */
    protected function _getTableName()
    {
        return TABLE_ORDERS_STATUS;
    }

    /**
     * @inheritDoc
     */
    protected function _getIdentifierKey()
    {
        return 'orders_status_id';
    }
}
