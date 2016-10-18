<?php

/**
 * Get list of queue server jobs
 *
 * @return array
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_queueserver_ajax_getJobList',
    function ($searchParams) {
        $searchParams = \QUI\Utils\Security\Orthos::clearArray(
            json_decode($searchParams, true)
        );

        return \QUI\QueueServer\Server::getJobList($searchParams);
    },
    array('searchParams'),
    'Permission::checkAdminUser'
);
