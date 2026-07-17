(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var app = document.getElementById('ba-cliq-history-app');
        if (!app) {
            return;
        }

        var records = Array.isArray(window.BA_CLIQ_HISTORY) ? window.BA_CLIQ_HISTORY : [];
        var state = {
            view: 'groups',
            selectedKey: '',
            filters: {
                batch: '',
                course: '',
                datefrom: '',
                dateto: ''
            },
            detailFilters: {
                recipient: '',
                datefrom: '',
                dateto: '',
                subject: '',
                status: ''
            },
            modalRecordId: null
        };

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function normalise(value) {
            return String(value == null ? '' : value).trim().toLowerCase();
        }

        function statusLabel(status) {
            var clean = String(status || '').trim();
            return clean ? clean.charAt(0).toUpperCase() + clean.slice(1) : '-';
        }

        function isSuccess(record) {
            return normalise(record.status) === 'success';
        }

        function groupKey(record) {
            return [record.batch || '-', record.courseid || record.course || '-'].join('::');
        }

        function inDateRange(record, from, to) {
            var datekey = record.datekey || '';
            if (from && (!datekey || datekey < from)) {
                return false;
            }
            if (to && (!datekey || datekey > to)) {
                return false;
            }
            return true;
        }

        function uniqueValues(field, source) {
            var seen = {};
            var values = [];
            source.forEach(function(record) {
                var value = String(record[field] || '').trim();
                if (value && !seen[value]) {
                    seen[value] = true;
                    values.push(value);
                }
            });
            return values.sort(function(a, b) {
                return a.localeCompare(b, undefined, {numeric: true, sensitivity: 'base'});
            });
        }

        function renderOptions(values, current, placeholder) {
            var html = '<option value="">' + escapeHtml(placeholder) + '</option>';
            values.forEach(function(value) {
                html += '<option value="' + escapeHtml(value) + '"' + (value === current ? ' selected' : '') + '>'
                    + escapeHtml(value) + '</option>';
            });
            return html;
        }

        function filteredRecordsForGroups() {
            return records.filter(function(record) {
                if (state.filters.batch && record.batch !== state.filters.batch) {
                    return false;
                }
                if (state.filters.course && record.course !== state.filters.course) {
                    return false;
                }
                return inDateRange(record, state.filters.datefrom, state.filters.dateto);
            });
        }

        function groupedRows() {
            var groups = {};
            filteredRecordsForGroups().forEach(function(record) {
                var key = groupKey(record);
                if (!groups[key]) {
                    groups[key] = {
                        key: key,
                        batch: record.batch || '-',
                        course: record.course || '-',
                        count: 0,
                        success: 0,
                        failed: 0,
                        latest: 0
                    };
                }
                groups[key].count += 1;
                if (isSuccess(record)) {
                    groups[key].success += 1;
                } else {
                    groups[key].failed += 1;
                }
                groups[key].latest = Math.max(groups[key].latest, Number(record.timecreated || 0));
            });
            return Object.keys(groups).map(function(key) {
                return groups[key];
            }).sort(function(a, b) {
                return b.latest - a.latest;
            });
        }

        function selectedBaseRecords() {
            return records.filter(function(record) {
                return groupKey(record) === state.selectedKey;
            });
        }

        function selectedFilteredRecords() {
            var filters = state.detailFilters;
            return selectedBaseRecords().filter(function(record) {
                var recipient = normalise((record.recipientname || '') + ' ' + (record.recipientemail || ''));
                if (filters.recipient && recipient.indexOf(normalise(filters.recipient)) === -1) {
                    return false;
                }
                if (filters.subject && normalise(record.subject).indexOf(normalise(filters.subject)) === -1) {
                    return false;
                }
                if (filters.status && normalise(record.status) !== normalise(filters.status)) {
                    return false;
                }
                return inDateRange(record, filters.datefrom, filters.dateto);
            });
        }

        function selectedGroupTitle() {
            var found = selectedBaseRecords()[0];
            if (!found) {
                return 'Message History';
            }
            return (found.batch || '-') + ' - ' + (found.course || '-');
        }

        function renderGroupFilters() {
            return '<div class="ba-cliq-filter-grid">'
                + '<label class="ba-cliq-filter-field"><span>Batch</span><select data-filter="batch">'
                + renderOptions(uniqueValues('batch', records), state.filters.batch, 'All batches')
                + '</select></label>'
                + '<label class="ba-cliq-filter-field"><span>Course</span><select data-filter="course">'
                + renderOptions(uniqueValues('course', records), state.filters.course, 'All courses')
                + '</select></label>'
                + '<label class="ba-cliq-filter-field"><span>Date From</span><input type="date" data-filter="datefrom" value="'
                + escapeHtml(state.filters.datefrom) + '"></label>'
                + '<label class="ba-cliq-filter-field"><span>Date To</span><input type="date" data-filter="dateto" value="'
                + escapeHtml(state.filters.dateto) + '"></label>'
                + '</div>';
        }

        function renderGroupTable() {
            var rows = groupedRows();
            if (!records.length) {
                return '<div class="ba-cliq-empty">No Cliq messages have been recorded yet.</div>';
            }
            if (!rows.length) {
                return '<div class="ba-cliq-empty">No messages match the selected filters.</div>';
            }
            var html = '<div class="ba-cliq-table-wrap"><table class="ba-cliq-table"><thead><tr>'
                + '<th>Batch</th><th>Course</th><th>Number of messages</th><th>Status</th><th>Action</th>'
                + '</tr></thead><tbody>';
            rows.forEach(function(row) {
                html += '<tr>'
                    + '<td>' + escapeHtml(row.batch) + '</td>'
                    + '<td>' + escapeHtml(row.course) + '</td>'
                    + '<td>' + row.count + '</td>'
                    + '<td><span class="ba-cliq-status-summary"><span class="ba-cliq-success">Success - ' + row.success
                    + '</span><span class="ba-cliq-separator">|</span><span class="ba-cliq-failed">Failed - ' + row.failed + '</span></span></td>'
                    + '<td><button type="button" class="ba-btn ba-btn-sm ba-btn-view" data-view-group="' + escapeHtml(row.key) + '">View History</button></td>'
                    + '</tr>';
            });
            html += '</tbody></table></div>';
            return html;
        }

        function renderGroupsView() {
            return '<section class="ba-cliq-history-panel">'
                + '<div class="ba-cliq-section-head"><div><h4>Cliq Message History</h4><p>Grouped by batch and course.</p></div></div>'
                + renderGroupFilters()
                + renderGroupTable()
                + '</section>';
        }

        function renderDetailFilters() {
            return '<div class="ba-cliq-filter-grid ba-cliq-filter-grid-detail">'
                + '<label class="ba-cliq-filter-field"><span>Sent to name</span><input type="search" data-detail-filter="recipient" value="'
                + escapeHtml(state.detailFilters.recipient) + '" placeholder="Name or email"></label>'
                + '<label class="ba-cliq-filter-field"><span>Date From</span><input type="date" data-detail-filter="datefrom" value="'
                + escapeHtml(state.detailFilters.datefrom) + '"></label>'
                + '<label class="ba-cliq-filter-field"><span>Date To</span><input type="date" data-detail-filter="dateto" value="'
                + escapeHtml(state.detailFilters.dateto) + '"></label>'
                + '<label class="ba-cliq-filter-field"><span>Subject</span><input type="search" data-detail-filter="subject" value="'
                + escapeHtml(state.detailFilters.subject) + '" placeholder="Subject"></label>'
                + '<label class="ba-cliq-filter-field"><span>Status</span><select data-detail-filter="status">'
                + renderOptions(uniqueValues('status', selectedBaseRecords()), state.detailFilters.status, 'All statuses')
                + '</select></label>'
                + '</div>';
        }

        function renderDetailTable() {
            var rows = selectedFilteredRecords();
            if (!rows.length) {
                return '<div class="ba-cliq-empty">No messages match the selected filters.</div>';
            }
            var html = '<div class="ba-cliq-table-wrap"><table class="ba-cliq-table"><thead><tr>'
                + '<th>Sent to name</th><th>Date</th><th>Subject</th><th>Status</th><th>Action</th>'
                + '</tr></thead><tbody>';
            rows.forEach(function(record) {
                var statusClass = isSuccess(record) ? 'ba-cliq-status-success' : 'ba-cliq-status-failed';
                html += '<tr>'
                    + '<td><div class="ba-cliq-recipient"><strong>' + escapeHtml(record.recipientname || '-')
                    + '</strong><span>' + escapeHtml(record.recipientemail || '') + '</span></div></td>'
                    + '<td>' + escapeHtml(record.date || '-') + '</td>'
                    + '<td>' + escapeHtml(record.subject || '-') + '</td>'
                    + '<td><span class="ba-cliq-status ' + statusClass + '">' + escapeHtml(statusLabel(record.status)) + '</span></td>'
                    + '<td><button type="button" class="ba-btn ba-btn-sm ba-btn-view" data-view-message="' + record.id + '">View Message</button></td>'
                    + '</tr>';
            });
            html += '</tbody></table></div>';
            return html;
        }

        function renderDetailView() {
            return '<section class="ba-cliq-history-panel">'
                + '<div class="ba-cliq-section-head">'
                + '<div><h4>' + escapeHtml(selectedGroupTitle()) + '</h4><p>Course message details.</p></div>'
                + '<button type="button" class="ba-btn ba-btn-sm" data-back-groups>Back</button>'
                + '</div>'
                + renderDetailFilters()
                + renderDetailTable()
                + '</section>';
        }

        function renderModal() {
            if (!state.modalRecordId) {
                return '';
            }
            var record = records.find(function(item) {
                return Number(item.id) === Number(state.modalRecordId);
            });
            if (!record) {
                return '';
            }
            var statusClass = isSuccess(record) ? 'ba-cliq-status-success' : 'ba-cliq-status-failed';
            return '<div class="ba-modal-overlay ba-cliq-modal-overlay" data-modal-close>'
                + '<div class="ba-modal-container ba-cliq-modal" role="dialog" aria-modal="true" aria-label="Cliq message" data-modal-panel>'
                + '<div class="ba-modal-header"><h3>View Message</h3>'
                + '<button type="button" class="ba-modal-close" aria-label="Close" data-modal-close>&times;</button></div>'
                + '<div class="ba-modal-body ba-cliq-modal-body">'
                + '<div class="ba-cliq-message-meta">'
                + '<div><span>Name</span><strong>' + escapeHtml(record.recipientname || '-') + '</strong></div>'
                + '<div><span>Email</span><strong>' + escapeHtml(record.recipientemail || '-') + '</strong></div>'
                + '<div><span>Date</span><strong>' + escapeHtml(record.date || '-') + '</strong></div>'
                + '<div><span>Status</span><strong class="ba-cliq-status ' + statusClass + '">' + escapeHtml(statusLabel(record.status)) + '</strong></div>'
                + '</div>'
                + '<div class="ba-cliq-message-section"><span>Subject</span><h4>' + escapeHtml(record.subject || '-') + '</h4></div>'
                + '<div class="ba-cliq-message-section"><span>Message Body</span><pre>' + escapeHtml(record.messagebody || '-') + '</pre></div>'
                + '</div></div></div>';
        }

        function render() {
            app.innerHTML = '<div class="ba-cliq-history">'
                + (state.view === 'details' ? renderDetailView() : renderGroupsView())
                + '</div>'
                + renderModal();
            bindEvents();
        }

        function bindEvents() {
            app.querySelectorAll('[data-filter]').forEach(function(input) {
                input.addEventListener('change', function() {
                    state.filters[input.getAttribute('data-filter')] = input.value;
                    render();
                });
            });
            app.querySelectorAll('[data-detail-filter]').forEach(function(input) {
                input.addEventListener('change', function() {
                    state.detailFilters[input.getAttribute('data-detail-filter')] = input.value;
                    render();
                });
            });
            app.querySelectorAll('[data-view-group]').forEach(function(button) {
                button.addEventListener('click', function() {
                    state.selectedKey = button.getAttribute('data-view-group');
                    state.view = 'details';
                    state.modalRecordId = null;
                    render();
                });
            });
            var backButton = app.querySelector('[data-back-groups]');
            if (backButton) {
                backButton.addEventListener('click', function() {
                    state.view = 'groups';
                    state.selectedKey = '';
                    state.modalRecordId = null;
                    render();
                });
            }
            app.querySelectorAll('[data-view-message]').forEach(function(button) {
                button.addEventListener('click', function() {
                    state.modalRecordId = Number(button.getAttribute('data-view-message'));
                    render();
                });
            });
            app.querySelectorAll('[data-modal-close]').forEach(function(element) {
                element.addEventListener('click', function(event) {
                    if (event.target.hasAttribute('data-modal-close')) {
                        state.modalRecordId = null;
                        render();
                    }
                });
            });
            var modalPanel = app.querySelector('[data-modal-panel]');
            if (modalPanel) {
                modalPanel.addEventListener('click', function(event) {
                    event.stopPropagation();
                });
            }
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && state.modalRecordId) {
                state.modalRecordId = null;
                render();
            }
        });

        render();
    });
})();