<?php

namespace QUI\QueueServer;

use QUI;

/**
 * Class Cron
 *
 * Quiqqer queue server cronjob for repeatedly fetching and executing queue jobs
 *
 * @package QUI\QueueServer
 */
class Cron
{
    /**
     * Execute all jobs in queue
     */
    public static function execute()
    {
        while (Server::hasNextJob()) {
            try {
                Server::executeNextJob();
            } catch (\Exception $Exception) {
                QUI\System\Log::addError(
                    self::class . ' -> execute :: ' . $Exception->getMessage()
                );
            }
        }
    }

    /**
     * Clean all jobs older than X days
     *
     * @param array $params
     */
    public static function cleanJobs($params)
    {
        Server::cleanJobs($params['days']);
    }
}