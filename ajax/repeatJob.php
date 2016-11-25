<?php

/**
 * Cancel/abort single job
 *
 * @return bool - success
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_queueserver_ajax_repeatJob',
    function ($jobId) {
        try {
            \QUI\QueueManager\QueueManager::cloneJob((int)$jobId, 1);
        } catch (\Exception $Exception) {
            QUI::getMessagesHandler()->addError(
                QUI::getLocale()->get(
                    'quiqqer/queueserver',
                    'error.job.repeat.error',
                    array(
                        'error' => $Exception->getMessage()
                    )
                )
            );

            return false;
        }

        QUI::getMessagesHandler()->addSuccess(
            QUI::getLocale()->get(
                'quiqqer/queueserver',
                'success.job.repeat',
                array(
                    'jobId' => $jobId
                )
            )
        );

        return true;
    },
    array('jobId'),
    'Permission::checkAdminUser'
);
