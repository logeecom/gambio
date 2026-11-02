<?php

namespace Mollie\Gambio\Update\v3_1_6;

use Mollie\Gambio\Entity\Repository\GambioConfigRepository;
use Mollie\Gambio\Entity\Repository\GambioStatusRepository;

/**
 * Class OrderStatusUpdate
 *
 * Creates order statuses that were introduced after the module had been installed (e.g. Pending (Mollie)).
 *
 * @package Mollie\Gambio\Update\v3_1_6
 */
class OrderStatusUpdate
{
    const LOCK_NAME = 'mollie_order_status_update';
    const LOCK_TIMEOUT = 10;

    /**
     * @var bool
     */
    private static $isExecuted = false;

    /**
     * Adds missing module order statuses if the module is already installed
     *
     * @return array Created status ids keyed by the module status key
     */
    public function addMissingStatuses()
    {
        if (static::$isExecuted || !defined(\MollieModuleCenterModule::MOLLIE_DEFAULT_STATUSES)) {
            return [];
        }

        static::$isExecuted = true;
        $installedStatuses = (array)json_decode(constant(\MollieModuleCenterModule::MOLLIE_DEFAULT_STATUSES), true);
        if (empty($installedStatuses)) {
            return [];
        }

        $missingStatuses = $this->getMissingStatuses($installedStatuses);
        if (empty($missingStatuses)) {
            return [];
        }

        // concurrent requests must not create the same status twice
        $db = \StaticGXCoreLoader::getDatabaseQueryBuilder();
        $lock = $db->query('SELECT GET_LOCK(?, ?) AS acquired', [static::LOCK_NAME, static::LOCK_TIMEOUT])->row_array();
        if (empty($lock['acquired'])) {
            return [];
        }

        $createdStatuses = $this->createMissingStatuses();
        $db->query('SELECT RELEASE_LOCK(?)', [static::LOCK_NAME]);

        return $createdStatuses;
    }

    /**
     * Creates missing statuses based on the stored configuration, the configuration constant is loaded at the
     * beginning of the request and can already be outdated
     *
     * @return array Created status ids keyed by the module status key
     */
    private function createMissingStatuses()
    {
        $configRepository = new GambioConfigRepository();
        $configKey = 'configuration/' . \MollieModuleCenterModule::MOLLIE_DEFAULT_STATUSES;
        $config = $configRepository->select($configKey, 'value');
        if (empty($config)) {
            return [];
        }

        $installedStatuses = (array)json_decode($config[0]['value'], true);
        $missingStatuses = $this->getMissingStatuses($installedStatuses);
        if (empty($missingStatuses)) {
            return [];
        }

        $createdStatuses = [];
        $statusRepository = new GambioStatusRepository();
        foreach ($missingStatuses as $statusKey => $status) {
            $createdStatuses[$statusKey] = $statusRepository->createStatus($status['names'], $status['color']);
        }

        $configRepository->update($configKey, ['value' => json_encode($installedStatuses + $createdStatuses)]);

        return $createdStatuses;
    }

    /**
     * @param array $installedStatuses
     *
     * @return array[]
     */
    private function getMissingStatuses(array $installedStatuses)
    {
        return array_diff_key(\MollieModuleCenterModule::getDefaultStatuses(), $installedStatuses);
    }
}
