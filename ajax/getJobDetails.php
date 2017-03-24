<?php

/**
 * Get list of queue server jobs
 *
 * @return array
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_queueserver_ajax_getJobDetails',
    function ($jobId) {
        $details = \QUI\QueueServer\Server::getJobData((int)$jobId);

        if (!empty($details['jobData'])) {
            $details['jobData'] = json_decode($details['jobData'], true);
        }

        $details['resultData']     = json_decode($details['resultData'], true);
    //        $details['jobLog']         = json_decode($details['jobLog'], true);
        $details['createTime']     = date('Y.m.d H:i:s', $details['createTime']);
        $details['lastUpdateTime'] = date('Y.m.d H:i:s', $details['lastUpdateTime']);

        return $details;
    },
    array('jobId'),
    'Permission::checkAdminUser'
);
