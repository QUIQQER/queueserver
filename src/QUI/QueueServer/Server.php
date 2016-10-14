<?php

namespace QUI\QueueServer;

use QUI;
use QUI\QueueManager\Interfaces\IQueueServer;
use QUI\QueueManager\Exceptions\ServerException;
use QUI\QueueManager\QueueJob;

/**
 * Class QueueServer
 *
 * Quiqqer queue server (cronjob)
 *
 * @package QUI\QueueServer
 */
class Server implements IQueueServer
{
    /**
     * Adds a single job to the queue of a server
     *
     * @param QueueJob $QueueJob - The job to add to the queue
     * @return integer - unique Job ID
     *
     * @throws QUI\Exception
     */
    public static function queueJob(QueueJob $QueueJob)
    {
        try {
            QUI::getDataBase()->insert(
                'queueserver_jobs',
                array(
                    'jobData'        => json_encode($QueueJob->getData()),
                    'jobWorker'      => $QueueJob->getWorkerClass(),
                    'status'         => self::JOB_STATUS_QUEUED,
                    'priority'       => $QueueJob->getAttribute('priority') ?: 1,
                    'deleteOnFinish' => $QueueJob->getAttribute('deleteOnFinish') ? 1 : 0
                )
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::addError(
                self::class . ' -> queueJob() :: ' . $Exception->getMessage()
            );

            throw new QUI\Exception(array(
                'quiqqer/queueserver',
                'exception.queueserver.job.queue.error'
            ));
        }

        return QUI::getDataBase()->getPDO()->lastInsertId();
    }

    /**
     * Get status of a job
     *
     * @param integer $jobId
     * @return integer - Status ID
     */
    public static function getJobStatus($jobId)
    {
        $jobEntry = self::getJobData($jobId);
        return $jobEntry['status'];
    }

    /**
     * Get result of a specific job
     *
     * @param integer $jobId
     * @param bool $deleteJob (optional) - delete job from queue after return [default: true]
     * @return array
     *
     * @throws QUI\Exception
     */
    public static function getJobResult($jobId, $deleteJob = true)
    {
        switch (self::getJobStatus($jobId)) {
            case self::JOB_STATUS_QUEUED:
                throw new QUI\Exception(array(
                    'quiqqer/queueserver',
                    'exception.queueserver.result.job.queued',
                    array(
                        'jobId' => $jobId
                    )
                ));
                break;

            case self::JOB_STATUS_RUNNING:
                throw new QUI\Exception(array(
                    'quiqqer/queueserver',
                    'exception.queueserver.result.job.running',
                    array(
                        'jobId' => $jobId
                    )
                ));
                break;
        }

        $jobData = self::getJobData($jobId);

        if ($deleteJob) {
            self::deleteJob($jobId);
        }

        return array(
            'id'     => $jobId,
            'result' => $jobData['result'],
            'status' => $jobData['status']
        );
    }

    /**
     * Set result of a specific job
     *
     * @param integer $jobId
     * @param array|string $result
     * @return bool - success
     *
     * @throws QUI\Exception
     */
    public static function setJobResult($jobId, $result)
    {
        if (empty($result)) {
            return true;
        }

        $jobStatus = self::getJobStatus($jobId);

        switch ($jobStatus) {
            case self::JOB_STATUS_FINISHED:
                throw new QUI\Exception(array(
                    'quiqqer/queueserver',
                    'exception.queueserver.setjobersult.job.finished',
                    array(
                        'jobId' => $jobId
                    )
                ));
                break;

            case self::JOB_STATUS_ERROR:
                throw new QUI\Exception(array(
                    'quiqqer/queueserver',
                    'exception.queueserver.setjobersult.job.error',
                    array(
                        'jobId' => $jobId
                    )
                ));
                break;
        }

        if (is_array($result)) {
            $result = json_encode($result);
        }

        try {
            QUI::getDataBase()->update(
                'queueserver_jobs',
                array(
                    'resultData' => $result
                ),
                array(
                    'id' => $jobId
                )
            );
        } catch (\Exception $Exception) {
            // @todo Fehlermeldung und Log
            return false;
        }

        return true;
    }

    /**
     * Cancel a job
     *
     * @param integer - $jobId
     * @return bool - success
     *
     * @throws QUI\Exception
     */
    public static function deleteJob($jobId)
    {
        switch (self::getJobStatus($jobId)) {
            case self::JOB_STATUS_RUNNING:
                throw new QUI\Exception(array(
                    'quiqqer/queueserver',
                    'exception.queueserver.cancel.job.running',
                    array(
                        'jobId' => $jobId
                    )
                ));
                break;
        }

        QUI::getDataBase()->delete(
            'queueserver_jobs',
            array(
                'id' => $jobId
            )
        );
    }

    /**
     * Get Database entry for a job
     *
     * @param $jobId
     * @throws QUI\Exception
     */
    protected static function getJobData($jobId)
    {
        $result = QUI::getDataBase()->fetch(array(
            'from'  => 'queueserver_jobs',
            'where' => array(
                'id' => $jobId
            )
        ));

        if (empty($result)) {
            throw new QUI\Exception(array(
                'quiqqer/queueserver',
                'exception.queueserver.job.not.found',
                array(
                    'jobId' => $jobId
                )
            ), 404);
        }
    }

    /**
     * Set status of a job
     *
     * @param integer $jobId
     * @param integer $status
     * @return bool - success
     */
    public static function setJobStatus($jobId, $status)
    {
        $jobData = self::getJobData($jobId);

        if ($jobData['status'] == $status) {
            return true;
        }

        try {
            QUI::getDataBase()->update(
                'queueserver_jobs',
                array(
                    'status' => $status
                ),
                array(
                    'id' => $jobId
                )
            );
        } catch (\Exception $Exception) {
            return false;
        }

        return true;
    }

    /**
     * Execute the next job in the queue
     *
     * @throws ServerException
     */
    public static function executeNextJob()
    {
        $job = self::fetchNextJob();

        if (!$job) {
            return;
        }

        $jobWorkerClass = $job['jobWorker'];

        if (!class_exists($jobWorkerClass)) {
            throw new ServerException(array(
                'quiqqer/queueserver',
                'exception.queueserver.job.worker.not.found',
                array(
                    'jobWorkerClass' => $jobWorkerClass
                )
            ), 404);
        }

        /** @var QUI\QueueManager\Interfaces\IQueueWorker $Worker */
        $Worker = new $jobWorkerClass(json_decode($job['jobData'], true));

        $jobId = $job['id'];
        self::setJobStatus($jobId, IQueueServer::JOB_STATUS_RUNNING);

        try {
            $jobResult = $Worker->execute();
        } catch (\Exception $Exception) {
            self::setJobStatus($jobId, IQueueServer::JOB_STATUS_ERROR);
            return;
        }

        if ($job['deleteOnFinish']) {
            self::deleteJob($jobId);
            return;
        }

        self::setJobResult($jobId, $jobResult);
        self::setJobStatus($jobId, IQueueServer::JOB_STATUS_FINISHED);
    }

    /**
     * Fetch job data for next job in the queue (with highest priority)
     *
     * @return array|false
     */
    protected static function fetchNextJob()
    {
        $result = QUI::getDataBase()->fetch(array(
            'select' => array(
                'id',
                'jobData',
                'jobWorker',
                'deleteOnFinish'
            ),
            'from'   => 'queueserver_jobs',
            'where'  => array(
                'status' => self::JOB_STATUS_QUEUED
            ),
            'limit'  => 1,
            'order'  => array(
                'priority' => 'DESC'
            )
        ));

        if (empty($result)) {
            return false;
        }

        return current($result);
    }
}