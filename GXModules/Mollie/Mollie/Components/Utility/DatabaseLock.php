<?php

namespace Mollie\Gambio\Utility;

/**
 * Class DatabaseLock
 *
 * MySQL named lock scoped to the shop database. A request holds at most one lock at a time,
 * because MySQL before 5.7 releases the held lock when another one is acquired.
 *
 * @package Mollie\Gambio\Utility
 */
class DatabaseLock
{
    /**
     * @var string
     */
    private $name;

    /**
     * DatabaseLock constructor.
     *
     * @param string $name
     */
    public function __construct($name)
    {
        $database = defined('DB_DATABASE') ? DB_DATABASE : '';
        $this->name = 'mollie_' . substr(md5($database), 0, 8) . '_' . $name;
    }

    /**
     * Returns the lock for status changes of the given order
     *
     * @param int $orderId
     *
     * @return static
     */
    public static function forOrder($orderId)
    {
        return new static('order_' . (int)$orderId);
    }

    /**
     * Runs the callback while the lock is held
     *
     * @param int      $timeout Seconds to wait for the lock
     * @param callable $callback
     *
     * @return bool False if the lock was not acquired and the callback was not run
     *
     * @throws \Exception|\Throwable Rethrown from the callback after the lock is released
     */
    public function execute($timeout, $callback)
    {
        if (!$this->acquire($timeout)) {
            return false;
        }

        try {
            call_user_func($callback);
        } catch (\Exception $exception) {
            $this->release();
            throw $exception;
        } catch (\Throwable $exception) {
            $this->release();
            throw $exception;
        }

        $this->release();

        return true;
    }

    /**
     * @param int $timeout
     *
     * @return bool
     */
    private function acquire($timeout)
    {
        $result = $this->getQueryBuilder()
            ->query('SELECT GET_LOCK(?, ?) AS acquired', [$this->name, (int)$timeout])
            ->row_array();

        return !empty($result['acquired']);
    }

    /**
     * Releases the lock
     */
    private function release()
    {
        $this->getQueryBuilder()->query('SELECT RELEASE_LOCK(?)', [$this->name]);
    }

    /**
     * @return \CI_DB_query_builder
     */
    private function getQueryBuilder()
    {
        return \StaticGXCoreLoader::getDatabaseQueryBuilder();
    }
}
