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
                self::getJobTable(),
                [
                    'jobData'        => json_encode($QueueJob->getData()),
                    'jobWorker'      => $QueueJob->getWorkerClass(),
                    'status'         => self::JOB_STATUS_QUEUED,
                    'priority'       => $QueueJob->getAttribute('priority') ?: 1,
                    'deleteOnFinish' => $QueueJob->getAttribute('deleteOnFinish') ? 1 : 0,
                    'createTime'     => time(),
                    'lastUpdateTime' => time()
                ]
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::addError(
                self::class.' -> queueJob() :: '.$Exception->getMessage()
            );

            throw new QUI\Exception([
                'quiqqer/queueserver',
                'exception.queueserver.job.queue.error'
            ]);
        }

        return QUI::getDataBase()->getPDO()->lastInsertId();
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
                throw new QUI\Exception([
                    'quiqqer/queueserver',
                    'exception.queueserver.result.job.queued',
                    [
                        'jobId' => $jobId
                    ]
                ]);
                break;

            case self::JOB_STATUS_RUNNING:
                throw new QUI\Exception([
                    'quiqqer/queueserver',
                    'exception.queueserver.result.job.running',
                    [
                        'jobId' => $jobId
                    ]
                ]);
                break;
        }

        $jobData = self::getJobData($jobId);

        if ($deleteJob) {
            self::deleteJob($jobId);
        }

        return $jobData['resultData'];
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
                throw new QUI\Exception([
                    'quiqqer/queueserver',
                    'exception.queueserver.setjobersult.job.finished',
                    [
                        'jobId' => $jobId
                    ]
                ]);
                break;

            case self::JOB_STATUS_ERROR:
                throw new QUI\Exception([
                    'quiqqer/queueserver',
                    'exception.queueserver.setjobersult.job.error',
                    [
                        'jobId' => $jobId
                    ]
                ]);
                break;
        }

        $result = json_encode($result);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::writeJobLogEntry(
                $jobId,
                QUI::getLocale()->get(
                    'quiqqer/queueserver',
                    'error.json.encode.job.result',
                    [
                        'error' => json_last_error_msg().' (JSON Error code: '.json_last_error().')'
                    ]
                )
            );

            return false;
        }

        try {
            QUI::getDataBase()->update(
                self::getJobTable(),
                [
                    'resultData' => $result
                ],
                [
                    'id' => $jobId
                ]
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
                throw new QUI\Exception([
                    'quiqqer/queueserver',
                    'exception.queueserver.cancel.job.running',
                    [
                        'jobId' => $jobId
                    ]
                ]);
                break;
        }

        QUI::getDataBase()->delete(
            self::getJobTable(),
            [
                'id' => $jobId
            ]
        );
    }

    /**
     * Get Database entry for a job
     *
     * @param $jobId
     * @return array
     *
     * @throws QUI\Exception
     */
    public static function getJobData($jobId)
    {
        $result = QUI::getDataBase()->fetch([
            'from'  => self::getJobTable(),
            'where' => [
                'id' => $jobId
            ]
        ]);

        if (empty($result)) {
            throw new QUI\Exception([
                'quiqqer/queueserver',
                'exception.queueserver.job.not.found',
                [
                    'jobId' => $jobId
                ]
            ], 404);
        }

        return current($result);
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
                self::getJobTable(),
                [
                    'status'         => $status,
                    'lastUpdateTime' => time()
                ],
                [
                    'id' => $jobId
                ]
            );
        } catch (\Exception $Exception) {
            return false;
        }

        return true;
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
            throw new ServerException([
                'quiqqer/queueserver',
                'exception.queueserver.job.worker.not.found',
                [
                    'jobWorkerClass' => $jobWorkerClass
                ]
            ], 404);
        }

        $jobId = $job['id'];

        /** @var QUI\QueueManager\Interfaces\IQueueWorker $Worker */
        $Worker = new $jobWorkerClass($jobId, json_decode($job['jobData'], true));

        self::setJobStatus($jobId, IQueueServer::JOB_STATUS_RUNNING);

        try {
            $jobResult = $Worker->execute();
        } catch (\Exception $Exception) {
            self::writeJobLogEntry($jobId, $Exception->getMessage());
            self::setJobStatus($jobId, IQueueServer::JOB_STATUS_ERROR);
            return;
        }

        if ($job['deleteOnFinish']) {
            self::deleteJob($jobId);
            return;
        }

        if (self::setJobResult($jobId, $jobResult)) {
            self::setJobStatus($jobId, self::JOB_STATUS_FINISHED);
        } else {
            self::setJobStatus($jobId, self::JOB_STATUS_ERROR);
        }
    }

    /**
     * Checks if there are still jobs in the queue that are not finished
     *
     * @return bool
     */
    public static function hasNextJob()
    {
        $result = QUI::getDataBase()->fetch([
            'count' => 1,
            'from'  => self::getJobTable(),
            'where' => [
                'status' => self::JOB_STATUS_QUEUED
            ]
        ]);

        $count = (int)current(current($result));

        return $count > 0;
    }

    /**
     * Fetch job data for next job in the queue (with highest priority)
     *
     * @return array|false
     */
    protected static function fetchNextJob()
    {
        $result = QUI::getDataBase()->fetch([
            'select' => [
                'id',
                'jobData',
                'jobWorker',
                'deleteOnFinish'
            ],
            'from'   => self::getJobTable(),
            'where'  => [
                'status' => self::JOB_STATUS_QUEUED
            ],
            'limit'  => 1,
            'order'  => [
                'id'       => 'ASC',
                'priority' => 'DESC'
            ]
        ]);

        if (empty($result)) {
            return false;
        }

        return current($result);
    }

    /**
     * Get list of jobs
     *
     * @return array
     */
    public static function getJobList($searchParams)
    {
        $Grid       = new QUI\Utils\Grid($searchParams);
        $gridParams = $Grid->parseDBParams($searchParams);

        $sortOn = 'id';
        $sortBy = 'ASC';

        if (isset($searchParams['sortOn'])
            && !empty($searchParams['sortOn'])
        ) {
            $sortOn = $searchParams['sortOn'];
        }

        if (isset($searchParams['sortBy'])
            && !empty($searchParams['sortBy'])
        ) {
            $sortBy = $searchParams['sortBy'];
        }

        $result = QUI::getDataBase()->fetch([
            'select' => [
                'id',
                'status',
                'jobWorker',
                'priority'
            ],
            'from'   => self::getJobTable(),
            'limit'  => $gridParams['limit'],
            'order'  => $sortOn.' '.$sortBy
        ]);

        $resultCount = QUI::getDataBase()->fetch([
            'count' => 1,
            'from'  => self::getJobTable()
        ]);

        return $Grid->parseResult(
            $result,
            current(current($resultCount))
        );
    }

    /**
     * Write log entry for a job
     *
     * @param integer $jobId
     * @param string $msg
     * @return bool - success
     */
    public static function writeJobLogEntry($jobId, $msg)
    {
        $jobLog   = self::getJobLog($jobId);
        $jobLog[] = [
            'time' => date('Y.m.d H:i:s'),
            'msg'  => $msg
        ];

        QUI::getDataBase()->update(
            self::getJobTable(),
            [
                'jobLog' => json_encode($jobLog)
            ],
            [
                'id' => $jobId
            ]
        );
    }

    /**
     * Get event log for specific job
     *
     * @param integer $jobId
     * @return array
     */
    public static function getJobLog($jobId)
    {
        $jobData = self::getJobData($jobId);
        $jobLog  = $jobData['jobLog'];

        if (empty($jobLog)) {
            return [];
        }

        return json_decode($jobLog, true);
    }

    /**
     * Delete all completed or failed jobs that are older than $days days
     *
     * @param integer $days
     * @return void
     */
    public static function cleanJobs($days)
    {
        $seconds = (int)$days * 24 * 60 * 60;
        $seconds = time() - $seconds;

        QUI::getDataBase()->delete(
            self::getJobTable(),
            [
                'lastUpdateTime' => [
                    'type'  => '<=',
                    'value' => $seconds
                ],
                'status'         => [
                    'type'  => 'IN',
                    'value' => [self::JOB_STATUS_FINISHED, self::JOB_STATUS_ERROR]
                ]
            ]
        );

        // OPTIMIZE
        QUI::getDataBase()->execSQL('OPTIMIZE TABLE `'.self::getJobTable().'`');
    }

    /**
     * Get table for jobs
     *
     * @return string
     */
    public static function getJobTable()
    {
        return QUI::getDBTableName('queueserver_jobs');
    }

    /**
     * Clone a job and queue it immediately
     *
     * @param integer $jobId - Job ID
     * @param integer $priority - (new) job priority
     * @return integer
     *
     * @throws QUI\Exception
     */
    public static function cloneJob($jobId, $priority)
    {
        $jobData             = self::getJobData($jobId);
        $jobData['priority'] = (int)$priority;

        $CloneJob = new QueueJob(
            $jobData['jobWorker'],
            json_decode($jobData['jobData'], true)
        );

        return self::queueJob($CloneJob);
    }

    /**
     * Close server connection
     */
    public static function closeConnection()
    {
        // nothing, there is no connection that needs to be closed
    }
}
