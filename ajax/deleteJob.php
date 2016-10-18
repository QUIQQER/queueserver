<?php

/**
 * Cancel/abort single job
 *
 * @return bool - success
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_queueserver_ajax_deleteJob',
    function ($jobId) {
        try {
            \QUI\QueueServer\Server::deleteJob((int)$jobId);
        } catch (\Exception $Exception) {
            QUI::getMessagesHandler()->addError(
                QUI::getLocale()->get(
                    'quiqqer/queueserver',
                    'error.job.cancel.error',
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
                'success.job.cancel',
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
