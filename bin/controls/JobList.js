/**
 * Queue server job listing
 *
 * @module package/quiqqer/queueserver/bin/controls/JobList
 * @author www.pcsg.de (Patrick Müller)
 *
 * @require qui/QUI
 * @require qui/controls/Control
 * @require qui/controls/buttons/Button
 * @requrie Ajax
 * @require Locale
 * @require css!package/quiqqer/queueserver/bin/controls/JobList.css
 *
 */
define('package/quiqqer/queueserver/bin/controls/JobList', [

    'qui/controls/desktop/Panel',
    'qui/controls/buttons/Button',
    'controls/grid/Grid',

    'Ajax',
    'Locale',

    'css!package/quiqqer/queueserver/bin/controls/JobList.css'

], function (QUIPanel, QUIButton, Grid, QUIAjax, QUILocale) {
    "use strict";

    var lg = 'quiqqer/queueserver';

    return new Class({

        Extends: QUIPanel,
        Type   : 'package/quiqqer/queueserver/bin/controls/JobList',

        Binds: [
            '$onInject',
            '$onRefresh',
            '$onCreate',
            '$onResize',
            'refresh',
            'createPassword',
            'viewPassword',
            'showSearch',
            '$listRefresh',
            '$addRemoveSearchBtn'
        ],

        options: {
            title: QUILocale.get(lg, 'joblist.panel.title')
        },

        initialize: function (options) {
            this.parent(options);

            this.addEvents({
                onInject : this.$onInject,
                onRefresh: this.$onRefresh,
                onCreate : this.$onCreate,
                onResize : this.$onResize
            });

            this.$GridContainer = null;
            this.$Grid          = null;
            this.$SearchParams  = {};
            this.$removeBtn     = false;
        },

        /**
         * event on DOMElement creation
         */
        $onCreate: function () {
            var self    = this,
                Content = this.getContent();

            Content.setStyles({
                padding: 0
            });

            // buttons
            this.addButton({
                name     : 'repeat',
                text     : QUILocale.get(lg, 'controls.joblist.btn.repeat'),
                textimage: 'fa fa-repeat',
                events   : {
                    onClick: function () {
                        self.Loader.show();

                        self.$repeatJob(
                            self.$Grid.getSelectedData()[0].id
                        ).then(function (success) {
                            self.Loader.hide();
                            self.refresh();
                        });
                    }
                }
            });

            this.addButton({
                name     : 'cancel',
                text     : QUILocale.get(lg, 'controls.joblist.btn.cancel'),
                textimage: 'fa fa-ban',
                events   : {
                    onClick: function () {
                        self.Loader.show();

                        self.$deleteJob(
                            self.$Grid.getSelectedData()[0].id
                        ).then(function (success) {
                            self.Loader.hide();
                            self.refresh();
                        });
                    }
                }
            });

            this.addButton({
                name     : 'details',
                text     : QUILocale.get(lg, 'controls.joblist.btn.details'),
                textimage: 'fa fa-file-text-o',
                events   : {
                    onClick: function () {
                        self.showJobDetails(
                            self.$Grid.getSelectedData()[0].id
                        );
                    }
                }
            });

            // content
            this.$GridContainer = new Element('div', {
                'class': 'queueserver-joblist-panel-container'
            }).inject(Content);

            this.$GridFX = moofx(this.$GridContainer);

            var GridContainer = new Element('div', {
                'class': 'queueserver-joblist-panel-grid'
            }).inject(this.$GridContainer);

            this.$Grid = new Grid(GridContainer, {
                pagination : true,
                serverSort : true,
                columnModel: [{
                    header   : QUILocale.get('quiqqer/system', 'id'),
                    dataIndex: 'id',
                    dataType : 'number',
                    width    : 50
                }, {
                    header   : QUILocale.get(lg, 'controls.joblist.panel.tbl.header.worker'),
                    dataIndex: 'jobWorker',
                    dataType : 'text',
                    width    : 500
                }, {
                    header   : QUILocale.get(lg, 'controls.joblist.panel.tbl.header.priority'),
                    dataIndex: 'priority',
                    dataType : 'number',
                    width    : 50
                }, {
                    header   : QUILocale.get(lg, 'controls.joblist.panel.tbl.header.status'),
                    dataIndex: 'statusText',
                    dataType : 'node',
                    width    : 150
                }, {
                    dataIndex: 'status',
                    hidden   : true
                }]
            });

            this.$Grid.addEvents({
                onDblClick: function () {
                    self.showJobDetails(
                        self.$Grid.getSelectedData()[0].id
                    );
                },
                onClick   : function () {
                    self.getButtons('details').enable();
                    self.getButtons('cancel').enable();
                },
                onRefresh : this.$listRefresh
            });
        },

        $onInject: function () {
            this.resize();
            this.refresh();
        },

        $onRefresh: function () {
            this.refresh();
        },

        $onResize: function () {
            var size = this.$GridContainer.getSize();

            this.$Grid.setHeight(size.y);
            this.$Grid.resize();
        },

        /**
         * refresh grid
         *
         * @param {Object} Grid
         */
        $listRefresh: function (Grid) {
            var self = this;

            this.Loader.show();

            var sortOn = Grid.getAttribute('sortOn');

            switch (sortOn) {
                case 'statusText':
                    sortOn = 'status';
                    break;
            }

            var GridParams = {
                sortOn : sortOn,
                sortBy : Grid.getAttribute('sortBy'),
                perPage: Grid.getAttribute('perPage'),
                page   : Grid.getAttribute('page')
            };

            this.$getJobList(GridParams).then(function (GridData) {
                self.Loader.hide();
                self.$setGridData(GridData);
            });
        },

        /**
         * refresh the password list
         */
        refresh: function () {
            this.$Grid.refresh();
        },

        $setGridData: function (GridData) {
            var Row;

            this.getButtons('cancel').disable();
            this.getButtons('details').disable();

            for (var i = 0, len = GridData.data.length; i < len; i++) {
                var Data = GridData.data[i];

                Row            = Data;
                Row.statusText = new Element('span', {
                    'class': 'queueserver-joblist-jobstatus-' + Row.status,
                    html   : QUILocale.get(lg, 'controls.joblist.status.' + Row.status)
                });
            }

            this.$Grid.setData(GridData);
        },

        /**
         * Shows details for a job
         */
        showJobDetails: function (jobId) {
            var self = this;

            this.Loader.show();

            this.$getJobDetails(jobId).then(function (Details) {
                self.Loader.hide();
                self.createSheet({
                    title : QUILocale.get(lg, 'controls.joblist.details.title', {jobId: jobId}),
                    events: {
                        onShow : function (Sheet) {
                            var SheetContent = Sheet.getContent();

                            var jobData = Details.jobData;

                            if (!jobData) {
                                jobData = {};
                            }

                            var logData = Details.jobLog;

                            if (!logData) {
                                logData = [];
                            }

                            var jobResult = Details.resultData;

                            if (!jobResult) {
                                jobResult = {};
                            }

                            SheetContent.setStyle('padding', 20);
                            SheetContent.set(
                                'html',
                                '<div class="queueserver-jobslist-job-data">' +
                                '<div class="queueserver-jobslist-job-data-input">' +
                                '<h3>' + QUILocale.get(lg, 'controls.joblist.details.input') + '</h3>' +
                                '<pre>' +
                                JSON.stringify(jobData, null, 4) +
                                '</pre>' +
                                '</div>' +
                                '<h3>' + QUILocale.get(lg, 'controls.joblist.details.output') + '</h3>' +
                                '<div class="queueserver-jobslist-job-data-output">' +
                                '<pre>' +
                                JSON.stringify(jobResult, null, 4) +
                                '</pre>' +
                                '</div>' +
                                '</div>' +
                                '<div class="queueserver-jobslist-job-log">' +
                                '<h3>' + QUILocale.get(lg, 'controls.joblist.details.log') + '</h3>' +
                                '<div class="queueserver-jobslist-job-dates">' +
                                QUILocale.get(lg, 'controls.joblist.details.date.create', {
                                    date: Details.createTime
                                }) + '<br/>' +
                                QUILocale.get(lg, 'controls.joblist.details.date.update', {
                                    date: Details.lastUpdateTime
                                }) +
                                '</div>' +
                                '</div>'
                            );

                            var LogElm = SheetContent.getElement('.queueserver-jobslist-job-log');

                            for (var i = 0, len = logData.length; i < len; i++) {
                                var LogEntry = logData[i];

                                new Element('div', {
                                    'class': 'queueserver-joblist-job-log-entry',
                                    html   : '<span class="queueserver-joblist-job-log-entry-date">' +
                                    LogEntry.time +
                                    '</span>' +
                                    '<p class="queueserver-joblist-job-log-entry-msg">' +
                                    LogEntry.msg +
                                    '</p>'
                                }).inject(LogElm);
                            }
                        },
                        onClose: function (Sheet) {
                            Sheet.destroy();
                        }
                    }
                }).show();
            });
        },

        /**
         * Get list of queue server jobs
         *
         * @param {Object} SearchParams
         * @returns {Promise}
         */
        $getJobList: function (SearchParams) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_queueserver_ajax_getJobList', resolve, {
                    'package'   : 'quiqqer/queueserver',
                    searchParams: JSON.encode(SearchParams),
                    onError     : reject
                });
            });
        },

        /**
         * Get details of a specific job
         *
         * @param {number} jobId
         * @returns {Promise}
         */
        $getJobDetails: function (jobId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_queueserver_ajax_getJobDetails', resolve, {
                    'package': 'quiqqer/queueserver',
                    jobId    : jobId,
                    onError  : reject
                });
            });
        },

        /**
         * Cancel/delete a job
         *
         * @param {number} jobId
         * @returns {Promise}
         */
        $deleteJob: function (jobId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_queueserver_ajax_deleteJob', resolve, {
                    'package': 'quiqqer/queueserver',
                    jobId    : jobId,
                    onError  : reject
                });
            });
        },

        /**
         * Repeat execution of a job
         *
         * @param {number} jobId
         * @returns {Promise}
         */
        $repeatJob: function (jobId) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_queueserver_ajax_repeatJob', resolve, {
                    'package': 'quiqqer/queueserver',
                    jobId    : jobId,
                    onError  : reject
                });
            });
        }
    });
});