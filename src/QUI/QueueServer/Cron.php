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
     * Execute next queue job in line
     */
    public static function execute()
    {
        Server::executeNextJob();
    }
}