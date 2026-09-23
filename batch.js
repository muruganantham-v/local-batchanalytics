/**
 * Client-side functionality for Batch Detail Screen in local_batchanalytics
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var container = document.getElementById('ba-batch-detail-container');
        if (!container) {
            return;
        }

        // Initialize state
        var performanceColumns = [];
        try {
            performanceColumns = JSON.parse(container.getAttribute('data-performance-columns') || '[]');
        } catch (e) {
            console.error('Error parsing performance columns:', e);
        }

        var performanceCustomGroups = [];
        try {
            performanceCustomGroups = JSON.parse(container.getAttribute('data-performance-custom-groups') || '[]');
        } catch (e) {
            console.error('Error parsing performance custom groups:', e);
        }
        function usesFixedGrade(column) {
            return !!(column.isattendance || column.ismaac);
        }

        function getCategoryValue(student, column) {
            var metric = student.categories && student.categories[column.key];
            if (!metric) return null;
            var useGrade = usesFixedGrade(column) || perfMode === 'grade';
            return useGrade ? metric.grade : metric.completion;
        }

        function formatPerformanceCell(student, column) {
            var value = getCategoryValue(student, column);
            if (value === null || value === undefined) return '<span class="muted">&mdash;</span>';
            var precision = column.ismaac ? 1 : 2;
            var suffix = column.ismaac ? '' : '%';
            return '<b>' + Number(value).toFixed(precision) + suffix + '</b>';
        }

        function csvPerformanceValue(student, column) {
            var value = getCategoryValue(student, column);
            if (value === null || value === undefined) return '-';
            return Number(value).toFixed(column.ismaac ? 1 : 2) + (column.ismaac ? '' : '%');
        }

        var perfMode = 'grade'; // 'grade' or 'percentile'
        var studentsData = [];

        try {
            var rawData = container.getAttribute('data-students');
            if (rawData) {
                studentsData = JSON.parse(rawData);
            }
        } catch (e) {
            console.error('Error parsing students data:', e);
        }

        // 1. Tab Switching
        var tabs = container.querySelectorAll('.ba-batch-tabs .tab');
        var panels = container.querySelectorAll('.ba-batch-panels .panel');

        // ===================== CRM Data State =====================
        var CRM_INDEX_URL = container.getAttribute('data-crm-index-url') || '';
        var CRM_SESSKEY  = container.getAttribute('data-sesskey') || '';
        var CRM_CAN_MANAGE = container.getAttribute('data-can-manage') === '1';
        var CRM_FIELDS = [];
        try {
            CRM_FIELDS = JSON.parse(container.getAttribute('data-crm-fields') || '[]');
        } catch(e) { CRM_FIELDS = []; }
        var CRM_RESTRICTED = CRM_FIELDS.filter(function(f){ return f.restricted; }).map(function(f){ return f.key; });
        var CRM_COLS = (CRM_CAN_MANAGE ? CRM_FIELDS : CRM_FIELDS.filter(function(f){ return !f.restricted; }))
            .map(function(f){ return { h: f.label, k: f.key, num: !!f.numeric, type: f.type || 'text' }; });

        var PTF_CACHE  = {};
        var PTF_PENDING = {};
        var PTF_FAILED  = {};
        var CRM_FAILURE_SHOWN = false;
        var CRM_PANEL_BUILT  = false;
        var CRM_CURRENT_PAGE = 1;
        var CRM_PAGE_SIZE = '10';
        // ==========================================================

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var targetId = this.getAttribute('data-tab');
                tabs.forEach(function(t) { t.classList.remove('active'); });
                panels.forEach(function(p) { p.classList.remove('active'); });

                this.classList.add('active');
                var activePanel = document.getElementById('panel-' + targetId);
                if (activePanel) {
                    activePanel.classList.add('active');
                }

                // Trigger CRM panel build on first open
                if (targetId === 'crm') {
                    baBatchCrm.render();
                }
            });
        });

        // 2. Performance Banding & Pagination Logic
        var tgGrade = document.getElementById('tg-grade');
        var tgPct = document.getElementById('tg-pct');
        var bandrow = document.getElementById('ba-bandrow');
        var stuBody = document.getElementById('ba-stuBody');
        var expBtn = document.getElementById('ba-btn-export');

        var currentPage = 1;
        var pageSize = 10;
        var sortColumn = '';
        var sortDirection = 'asc';

        var performanceCollapsedGroups = { module: false };
        var performanceTable = container.querySelector('#panel-students table');

        function groupToggle(label, key, collapsed) {
            return '<button type="button" class="ba-maac-group-toggle" data-performance-group-toggle="' + escapeHtml(key) + '" aria-expanded="' + (!collapsed) + '">' +
                '<span class="ba-maac-group-toggle-icon" aria-hidden="true">' + (collapsed ? '+' : '-') + '</span>' +
                '<span class="ba-maac-group-toggle-label">' + escapeHtml(label) + '</span></button>';
        }

        function trendVisual(value) {
            var key = String(value || '').trim().toLowerCase();
            if (key === 'improving' || key === 'up') return { label: 'Improving', icon: '+', className: 'ba-trend-badge-up' };
            if (key === 'declining' || key === 'down') return { label: 'Declining', icon: '-', className: 'ba-trend-badge-down' };
            return { label: 'Stable', icon: '=', className: 'ba-trend-badge-stable' };
        }

        function renderTrendBadge(student, value) {
            if (value === undefined || value === null || value === '') return '<span class="muted">&mdash;</span>';
            var visual = trendVisual(value);
            var badge = '<span class="ba-trend-badge ' + visual.className + '"><span>' + visual.icon + '</span><span>' + visual.label + '</span></span>';
            return '<button type="button" class="ba-trend-badge-btn" data-performance-trend-userid="' + Number(student.userid || 0) + '" aria-label="View trend details for ' + escapeHtml(student.name || 'student') + '">' + badge + '</button>';
        }

        function trendMetric(label, points) {
            points = Array.isArray(points) ? points : [];
            if (!points.length) return '<div class="trend-stats"><div><span>' + escapeHtml(label) + '</span><strong>No data</strong></div></div>';
            var latest = points[points.length - 1] || {};
            var value = latest.grade !== undefined ? Number(latest.grade).toFixed(2) + '%' : (latest.status || 'Recorded');
            return '<div class="trend-stats"><div><span>Recent records</span><strong>' + points.length + '</strong></div><div><span>Latest</span><strong>' + escapeHtml(value) + '</strong></div></div>';
        }

        function openTrendModal(student) {
            var records = Array.isArray(student.trend_details) ? student.trend_details : [];
            var existing = document.getElementById('ba-student-performance-trend-modal');
            if (existing) existing.remove();
            var cards = records.map(function(record) {
                var details = record.details || {};
                return '<div class="trend-card"><div class="trend-title">' + escapeHtml(record.course || 'Course') + '</div>' + trendMetric('Assignments', details.assignments) + trendMetric('Quizzes', details.quizzes) + trendMetric('Projects', details.projects) + trendMetric('Attendance', details.attendance) + '</div>';
            }).join('');
            var modal = document.createElement('div');
            modal.className = 'trends-modal';
            modal.id = 'ba-student-performance-trend-modal';
            modal.innerHTML = '<div class="trends-content"><div class="trends-header"><h3>' + escapeHtml(student.name || 'Student') + ' - Grade Trends</h3><button type="button" class="close-trends" aria-label="Close">x</button></div><div class="trends-grid">' + (cards || '<div class="trend-card"><div class="trend-title">Trend</div><div class="trend-stats"><div>No trend detail is available for this student yet.</div></div></div>') + '</div></div>';
            modal.addEventListener('click', function(event) { if (event.target === modal || event.target.closest('.close-trends')) modal.remove(); });
            document.body.appendChild(modal);
        }
        function customGroupCells(student) {
            return performanceCustomGroups.map(function(group) {
                if (performanceCollapsedGroups[group.key]) {
                    return '<td class="ba-maac-collapsed-col ba-performance-group-collapsed"></td>';
                }
                return (group.columns || []).map(function(column) {
                    var value = student.custom && student.custom[column.key];
                    var display = column.key === 'trend' ? renderTrendBadge(student, value) : (value === undefined || value === null || value === '' ? '<span class="muted">&mdash;</span>' : escapeHtml(String(value)));
                    var trendClass = column.key === 'trend' ? ' ba-performance-custom-trend' : '';
                    return '<td class="ba-performance-custom-col ba-performance-group-' + escapeHtml(group.key) + trendClass + '">' + display + '</td>';
                }).join('');
            }).join('');
        }

        function getVisiblePerformanceColumnCount() {
            var count = 3;
            count += performanceCollapsedGroups.module ? 1 : (1 + performanceColumns.length);
            performanceCustomGroups.forEach(function(group) {
                count += performanceCollapsedGroups[group.key] ? 1 : (group.columns || []).length;
            });
            return count;
        }

        function renderPerformanceHeaders() {
            if (!performanceTable) return;
            var thead = performanceTable.querySelector('thead');
            if (!thead) return;
            var moduleCollapsed = !!performanceCollapsedGroups.module;
            var firstRow = '<tr><th class="sortable" data-sort="band" title="Sort by Band" rowspan="2">Band</th>' +
                '<th class="sortable ba-performance-student-head" data-sort="student" title="Sort by Student Name" rowspan="2">Student</th>';
            var secondRow = '<tr>';
            if (moduleCollapsed) {
                firstRow += '<th class="ba-maac-group-head ba-performance-group-collapsed" rowspan="2">' + groupToggle('Module Performance', 'module', true) + '</th>';
            } else {
                firstRow += '<th class="ba-maac-group-head" colspan="' + (1 + performanceColumns.length) + '">' + groupToggle('Module Performance', 'module', false) + '</th>';
                secondRow += '<th class="sortable ba-performance-overall-head" data-sort="grade" title="Sort by Grade">Grade</th>';
                performanceColumns.forEach(function(column) {
                    secondRow += '<th class="sortable ba-performance-group-module" data-sort="category:' + escapeHtml(column.key) + '" title="Sort by ' + escapeHtml(column.label) + '">' + escapeHtml(column.label) + '</th>';
                });
            }
            performanceCustomGroups.forEach(function(group) {
                var columns = group.columns || [];
                if (!columns.length) return;
                if (performanceCollapsedGroups[group.key]) {
                    firstRow += '<th class="ba-maac-group-head ba-performance-group-collapsed" rowspan="2">' + groupToggle(group.label, group.key, true) + '</th>';
                    return;
                }
                firstRow += '<th class="ba-maac-group-head" colspan="' + columns.length + '">' + groupToggle(group.label, group.key, false) + '</th>';
                columns.forEach(function(column) {
                    secondRow += '<th class="ba-performance-custom-col' + (column.key === 'trend' ? ' ba-performance-custom-trend' : '') + '" data-custom-key="' + escapeHtml(column.key) + '">' + escapeHtml(column.label) + '</th>';
                });
            });
            firstRow += '<th class="sortable" data-sort="merit" title="Sort by Merit" rowspan="2">Merit</th></tr>';
            secondRow += '</tr>';
            thead.innerHTML = firstRow + secondRow;
        }

        function togglePerformanceGroup(key) {
            var tableWrap = container.querySelector('#panel-students .tablecard');
            var scrollLeft = tableWrap ? tableWrap.scrollLeft : 0;
            performanceCollapsedGroups[key] = !performanceCollapsedGroups[key];
            renderPerformanceHeaders();
            renderPerformance();
            if (tableWrap) tableWrap.scrollLeft = scrollLeft;
        }
        // Tag initial order for stable tie-breaking
        studentsData.forEach(function(s, idx) {
            if (s._origIdx === undefined) {
                s._origIdx = idx;
            }
        });

        function applySort() {
            if (!sortColumn) return;

            studentsData.sort(function(a, b) {
                var mult = (sortDirection === 'desc') ? -1 : 1;
                var res = 0;

                if (sortColumn.indexOf('category:') === 0) {
                    var categoryKey = sortColumn.substring('category:'.length);
                    var categoryColumn = performanceColumns.find(function(column) { return column.key === categoryKey; }) || {};
                    var categoryA = getCategoryValue(a, categoryColumn);
                    var categoryB = getCategoryValue(b, categoryColumn);
                    res = (categoryA === null || categoryA === undefined ? -1 : Number(categoryA)) - (categoryB === null || categoryB === undefined ? -1 : Number(categoryB));
                } else {
                switch (sortColumn) {
                    case 'student':
                    case 'name':
                        var nameA = (a.name || '').trim().toLowerCase();
                        var nameB = (b.name || '').trim().toLowerCase();
                        res = nameA.localeCompare(nameB);
                        break;
                    case 'grade':
                        var grA = Number(a.grade) || 0;
                        var grB = Number(b.grade) || 0;
                        res = grA - grB;
                        break;
                    case 'attendance':
                        var attA = parseFloat(a.attendance) || 0;
                        var attB = parseFloat(b.attendance) || 0;
                        res = attA - attB;
                        break;
                    case 'assignments':
                        var asgA = parseFloat(a.assignments) || 0;
                        var asgB = parseFloat(b.assignments) || 0;
                        res = asgA - asgB;
                        break;
                    case 'projects':
                        var prjA = (a.projects && a.projects !== '—') ? (parseFloat(a.projects) || 0) : -1;
                        var prjB = (b.projects && b.projects !== '—') ? (parseFloat(b.projects) || 0) : -1;
                        res = prjA - prjB;
                        break;
                    case 'tests':
                        var tstA = parseFloat(a.tests) || 0;
                        var tstB = parseFloat(b.tests) || 0;
                        res = tstA - tstB;
                        break;
                    case 'band':
                        var bA = Number(a.grade) || 0;
                        var bB = Number(b.grade) || 0;
                        res = bA - bB;
                        break;
                    case 'merit':
                        var scoreA = (a.spot ? 10 : 0) + (a.pt === 'sel' ? 5 : (a.pt === 'nom' ? 2 : 0));
                        var scoreB = (b.spot ? 10 : 0) + (b.pt === 'sel' ? 5 : (b.pt === 'nom' ? 2 : 0));
                        res = scoreA - scoreB;
                        break;
                    default:
                        res = 0;
                }
                }

                if (res !== 0) {
                    return res * mult;
                }
                var fallback = (a.name || '').localeCompare(b.name || '');
                if (fallback !== 0) return fallback;
                return (a._origIdx - b._origIdx);
            });
        }

        function updateSortHeaders() {
            var ths = container.querySelectorAll('#panel-students th.sortable');
            ths.forEach(function(th) {
                var col = th.getAttribute('data-sort');
                th.classList.remove('sort-asc', 'sort-desc');
                th.removeAttribute('aria-sort');
                if (col === sortColumn) {
                    th.classList.add(sortDirection === 'asc' ? 'sort-asc' : 'sort-desc');
                    th.setAttribute('aria-sort', sortDirection === 'asc' ? 'ascending' : 'descending');
                }
            });
        }

        var stuPageSizeSelect = document.getElementById('ba-page-size');
        var paginationInfo = document.getElementById('ba-pagination-info');
        var paginationBtns = document.getElementById('ba-pagination-btns');

        function getPercentileValues(students) {
            var ordered = students.slice().sort(function(a, b) {
                return (Number(b.grade) || 0) - (Number(a.grade) || 0);
            });
            var values = {};
            var total = ordered.length;
            var previousGrade = null;
            var previousPercentile = null;
            ordered.forEach(function(student, index) {
                var score = Number(student.grade) || 0;
                var percentile = total <= 1 ? 100 : Math.round(((total - index - 1) / (total - 1)) * 100);
                if (previousGrade !== null && score === previousGrade) percentile = previousPercentile;
                values[student._origIdx] = percentile;
                previousGrade = score;
                previousPercentile = percentile;
            });
            return values;
        }

        function getOverallDisplayValue(student, percentiles) {
            if (perfMode === 'percentile') {
                var percentile = percentiles[student._origIdx];
                return percentile === undefined ? '&mdash;' : percentile + '%';
            }
            return student.grade === null || student.grade === undefined ? '&mdash;' : escapeHtml(Number(student.grade).toFixed(2) + '%');
        }

        function updateOverallHeading() {
            var heading = container.querySelector('.ba-performance-overall-head');
            if (heading) {
                heading.textContent = perfMode === 'grade' ? 'Grade' : 'Percentile';
                heading.title = perfMode === 'grade' ? 'Sort by Grade' : 'Sort by Percentile';
            }
        }

        function computeBands(students, mode) {
            var n = students.length;
            if (n === 0) return [];

            if (mode === 'grade') {
                return students.map(function(s) {
                    var g = Number(s.grade) || 0;
                    if (g > 70) return 'top';
                    if (g >= 40) return 'mid';
                    return 'bot';
                });
            }

            // Percentile mode: Top 15%, Middle 70%, Bottom 15% by score rank
            var indexed = students.map(function(s, idx) {
                return { idx: idx, grade: Number(s.grade) || 0 };
            });

            indexed.sort(function(a, b) {
                return b.grade - a.grade;
            });

            var topN = Math.max(1, Math.round(n * 0.15));
            var botN = Math.max(1, Math.round(n * 0.15));
            var bands = new Array(n);
            for (var i = 0; i < n; i++) {
                bands[i] = 'mid';
            }

            indexed.forEach(function(item, rank) {
                if (rank < topN) {
                    bands[item.idx] = 'top';
                } else if (rank >= (n - botN)) {
                    bands[item.idx] = 'bot';
                }
            });

            return bands;
        }

        function renderPerformance() {
            if (!studentsData || studentsData.length === 0) {
                if (stuBody) {
                    stuBody.innerHTML = '<tr><td colspan="' + getVisiblePerformanceColumnCount() + '" style="text-align:center; padding:32px; color:#64748b;">No students found for this batch.</td></tr>';
                }
                if (paginationInfo) paginationInfo.textContent = 'No students to display';
                if (paginationBtns) paginationBtns.innerHTML = '';
                return;
            }

            var totalItems = studentsData.length;
            var bands = computeBands(studentsData, perfMode);
            var percentileValues = getPercentileValues(studentsData);
            updateOverallHeading();
            var counts = { top: 0, mid: 0, bot: 0 };
            bands.forEach(function(b) {
                if (counts[b] !== undefined) counts[b]++;
            });

            var labels = perfMode === 'grade' ? {
                top: ['Top Performers', 'grade &gt; 70'],
                mid: ['Middle Performers', 'grade 40–70'],
                bot: ['Low Performers', 'grade &lt; 40']
            } : {
                top: ['Top 15%', 'by rank'],
                mid: ['Middle 70%', 'by rank'],
                bot: ['Bottom 15%', 'by rank']
            };

            if (bandrow) {
                bandrow.innerHTML =
                    '<div class="band top"><div class="pct">' + counts.top + '</div><div class="lbl">' + labels.top[0] + '</div><div class="cnt">' + labels.top[1] + '</div></div>' +
                    '<div class="band mid"><div class="pct">' + counts.mid + '</div><div class="lbl">' + labels.mid[0] + '</div><div class="cnt">' + labels.mid[1] + '</div></div>' +
                    '<div class="band bot"><div class="pct">' + counts.bot + '</div><div class="lbl">' + labels.bot[0] + '</div><div class="cnt">' + labels.bot[1] + '</div></div>';
            }

            // Compute Pagination Bounds
            var isAll = (pageSize === 'all');
            var sizeNum = isAll ? (totalItems || 1) : parseInt(pageSize, 10);
            var totalPages = Math.max(1, Math.ceil(totalItems / sizeNum));

            if (currentPage > totalPages) {
                currentPage = totalPages;
            }
            if (currentPage < 1) {
                currentPage = 1;
            }

            var startIdx = totalItems === 0 ? 0 : (currentPage - 1) * sizeNum + 1;
            var endIdx = isAll ? totalItems : Math.min(currentPage * sizeNum, totalItems);

            if (paginationInfo) {
                paginationInfo.textContent = totalItems === 0
                    ? 'No students to display'
                    : ('Showing ' + startIdx + '–' + endIdx + ' of ' + totalItems + ' students');
            }

            // Slice and Render Student Rows
            var pagedStudents = isAll
                ? studentsData
                : studentsData.slice((currentPage - 1) * sizeNum, currentPage * sizeNum);

            var html = '';
            var dotColors = {
                top: 'var(--green, #1e8e4e)',
                mid: 'var(--amber, #c77a0a)',
                bot: 'var(--red, #c0392b)'
            };

            pagedStudents.forEach(function(s, pageIdx) {
                var globalIdx = isAll ? pageIdx : ((currentPage - 1) * sizeNum + pageIdx);
                var b = bands[globalIdx] || 'mid';

                var meritHtml = '';
                if (s.spot) meritHtml += '<span class="m m-spot">★ Spot</span> ';
                if (s.pt === 'nom') meritHtml += '<span class="m m-ptnom">PT-Nom</span> ';
                else if (s.pt === 'sel') meritHtml += '<span class="m m-ptsel">PT-Sel</span>';
                if (!meritHtml) meritHtml = '<span class="muted">—</span>';

                var avatarHtml = '';
                var studentInitials = escapeHtml(s.initials || (s.name ? s.name.charAt(0).toUpperCase() : '?'));
                if (s.profileimageurl) {
                    avatarHtml = '<div class="ba-avatar-wrap">' +
                        '<img src="' + escapeHtml(s.profileimageurl) + '" class="ba-student-avatar" alt="' + escapeHtml(s.name) + '" onerror="this.onerror=null;this.parentElement.innerHTML=\'<span class=\\\'ba-avatar-initials\\\'>' + studentInitials + '</span>\';">' +
                    '</div>';
                } else {
                    avatarHtml = '<div class="ba-avatar-wrap"><span class="ba-avatar-initials">' + studentInitials + '</span></div>';
                }

                html += '<tr>' +
                    '<td><span class="bdot" style="background:' + dotColors[b] + '" title="' + b.toUpperCase() + ' Band"></span></td>' +
                    '<td class="ba-performance-student-cell">' +
                        '<div class="ba-student-cell">' +
                            avatarHtml +
                            '<div class="ba-student-info">' +
                                '<span class="sname">' + escapeHtml(s.name) + '</span>' +
                                '<span class="sid">' + escapeHtml(s.id) + '</span>' +
                            '</div>' +
                        '</div>' +
                    '</td>' +
                    (performanceCollapsedGroups.module
                        ? '<td class="ba-maac-collapsed-col ba-performance-group-collapsed"></td>'
                        : ('<td><b>' + getOverallDisplayValue(s, percentileValues) + '</b></td>' +
                            performanceColumns.map(function(column) {
                                return '<td class="ba-performance-group-module">' + formatPerformanceCell(s, column) + '</td>';
                            }).join(''))) +
                    customGroupCells(s) +
                    '<td><span class="merit">' + meritHtml + '</span></td>' +
                    '</tr>';
            });

            if (pagedStudents.length === 0) {
                html = '<tr><td colspan="' + getVisiblePerformanceColumnCount() + '" style="text-align:center; padding:32px; color:#64748b;">No students found for this batch.</td></tr>';
            }

            if (stuBody) {
                stuBody.innerHTML = html;
            }

            // Update sort indicators on table headers
            updateSortHeaders();

            // Render Pagination Buttons
            renderPaginationButtons(totalPages);
        }

        function renderPaginationButtons(totalPages) {
            if (!paginationBtns) return;

            if (totalPages <= 1 || pageSize === 'all') {
                paginationBtns.innerHTML = '';
                return;
            }

            var btnsHtml = '';

            // Previous Button
            btnsHtml += '<button type="button" class="ba-pg-btn" id="ba-pg-prev" ' +
                (currentPage <= 1 ? 'disabled' : '') + ' aria-label="Previous page">‹ Prev</button>';

            // Page numbers with smart ellipsis
            var pages = [];
            if (totalPages <= 7) {
                for (var i = 1; i <= totalPages; i++) {
                    pages.push(i);
                }
            } else {
                pages.push(1);
                if (currentPage > 3) {
                    pages.push('...');
                }
                var startP = Math.max(2, currentPage - 1);
                var endP = Math.min(totalPages - 1, currentPage + 1);
                for (var j = startP; j <= endP; j++) {
                    pages.push(j);
                }
                if (currentPage < totalPages - 2) {
                    pages.push('...');
                }
                pages.push(totalPages);
            }

            pages.forEach(function(p) {
                if (p === '...') {
                    btnsHtml += '<span class="ba-pg-ellipsis">…</span>';
                } else {
                    var isActive = (p === currentPage);
                    btnsHtml += '<button type="button" class="ba-pg-btn ' + (isActive ? 'active' : '') + '" data-page="' + p + '">' + p + '</button>';
                }
            });

            // Next Button
            btnsHtml += '<button type="button" class="ba-pg-btn" id="ba-pg-next" ' +
                (currentPage >= totalPages ? 'disabled' : '') + ' aria-label="Next page">Next ›</button>';

            paginationBtns.innerHTML = btnsHtml;

            // Attach event listeners
            var prevBtn = document.getElementById('ba-pg-prev');
            if (prevBtn && !prevBtn.disabled) {
                prevBtn.addEventListener('click', function() {
                    if (currentPage > 1) {
                        currentPage--;
                        renderPerformance();
                    }
                });
            }

            var nextBtn = document.getElementById('ba-pg-next');
            if (nextBtn && !nextBtn.disabled) {
                nextBtn.addEventListener('click', function() {
                    if (currentPage < totalPages) {
                        currentPage++;
                        renderPerformance();
                    }
                });
            }

            paginationBtns.querySelectorAll('button[data-page]').forEach(function(pageBtn) {
                pageBtn.addEventListener('click', function() {
                    var p = parseInt(this.getAttribute('data-page'), 10);
                    if (p && p !== currentPage) {
                        currentPage = p;
                        renderPerformance();
                    }
                });
            });
        }

        if (stuPageSizeSelect) {
            stuPageSizeSelect.addEventListener('change', function() {
                pageSize = this.value;
                currentPage = 1;
                renderPerformance();
            });
        }

        // Student Performance header sorting and Advanced Filter-style group toggles.
        if (performanceTable) {
            performanceTable.addEventListener('click', function(event) {
                var trendButton = event.target.closest('[data-performance-trend-userid]');
                if (trendButton) {
                    event.preventDefault();
                    var trendUserId = Number(trendButton.getAttribute('data-performance-trend-userid'));
                    var trendStudent = studentsData.find(function(student) { return Number(student.userid) === trendUserId; });
                    if (trendStudent) openTrendModal(trendStudent);
                    return;
                }
                var toggle = event.target.closest('[data-performance-group-toggle]');
                if (toggle) {
                    event.preventDefault();
                    event.stopPropagation();
                    togglePerformanceGroup(toggle.getAttribute('data-performance-group-toggle'));
                    return;
                }
                var th = event.target.closest('th.sortable');
                if (!th || !performanceTable.contains(th)) return;
                var col = th.getAttribute('data-sort');
                if (!col) return;
                if (sortColumn === col) {
                    sortDirection = (sortDirection === 'asc') ? 'desc' : 'asc';
                } else {
                    sortColumn = col;
                    sortDirection = (col === 'student' || col === 'name') ? 'asc' : 'desc';
                }
                applySort();
                currentPage = 1;
                renderPerformance();
            });
        }
        if (tgGrade && tgPct) {
            tgGrade.addEventListener('click', function() {
                perfMode = 'grade';
                tgGrade.classList.add('on');
                tgPct.classList.remove('on');
                currentPage = 1;
                renderPerformance();
            });

            tgPct.addEventListener('click', function() {
                perfMode = 'percentile';
                tgPct.classList.add('on');
                tgGrade.classList.remove('on');
                currentPage = 1;
                renderPerformance();
            });
        }

        // Export Filtered Students to CSV
        if (expBtn && stuBody) {
            expBtn.addEventListener('click', function() {
                var csvHeaders = ['Band', 'Student Name', 'Student ID', perfMode === 'grade' ? 'Grade' : 'Percentile'];
                if (!performanceCollapsedGroups.module) {
                    performanceColumns.forEach(function(column) {
                        var suffix = usesFixedGrade(column) || perfMode === 'grade' ? ' Grade' : ' Completion';
                        csvHeaders.push(column.label + suffix);
                    });
                }
                performanceCustomGroups.forEach(function(group) {
                    if (!performanceCollapsedGroups[group.key]) {
                        (group.columns || []).forEach(function(column) { csvHeaders.push(column.label); });
                    }
                });
                csvHeaders.push('Merit');
                var csv = [csvHeaders.map(function(value) { return '"' + value.replace(/"/g, '""') + '"'; }).join(',')];
                var bands = computeBands(studentsData, perfMode);
                var percentileValues = getPercentileValues(studentsData);

                studentsData.forEach(function(s, idx) {
                    var band = bands[idx] ? bands[idx].toUpperCase() : 'MID';
                    var name = (s.name || '').replace(/"/g, '""');
                    var id = (s.id || '').replace(/"/g, '""');
                    var grade = getOverallDisplayValue(s, percentileValues).replace(/&mdash;/g, '-');
                    var merit = (s.merit_text || '').replace(/"/g, '""');
                    var row = [band, name, id, grade];
                    if (!performanceCollapsedGroups.module) {
                        performanceColumns.forEach(function(column) { row.push(csvPerformanceValue(s, column)); });
                    }
                    performanceCustomGroups.forEach(function(group) {
                        if (!performanceCollapsedGroups[group.key]) {
                            (group.columns || []).forEach(function(column) {
                                row.push((s.custom && s.custom[column.key]) || '-');
                            });
                        }
                    });
                    row.push(merit);
                    csv.push(row.map(function(value) { return '"' + String(value).replace(/"/g, '""') + '"'; }).join(','));
                });

                var blob = new Blob([csv.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
                var link = document.createElement('a');
                var url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', 'batch_students_performance.csv');
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            });
        }

        // 3. Review Notes Section
        var noteBtn = document.getElementById('ba-btn-addnote');
        var noteText = document.getElementById('ba-note-text');
        var noteList = document.getElementById('ba-note-list');
        var batchContainer = document.getElementById('ba-batch-detail-container');

        if (noteBtn && noteText && noteList) {
            noteBtn.addEventListener('click', function() {
                var text = noteText.value.trim();
                if (!text) {
                    noteText.focus();
                    return;
                }

                var author = noteBtn.getAttribute('data-author') || 'Program Manager';
                var batchId = (batchContainer && batchContainer.getAttribute('data-batchid')) || '1';
                var sessKey = (batchContainer && batchContainer.getAttribute('data-sesskey')) || '';

                if (!sessKey && typeof M !== 'undefined' && M.cfg && M.cfg.sesskey) {
                    sessKey = M.cfg.sesskey;
                }

                var originalBtnText = noteBtn.textContent;
                noteBtn.disabled = true;
                noteBtn.textContent = 'Saving…';

                var formData = new FormData();
                formData.append('action', 'addnote');
                formData.append('batchid', batchId);
                formData.append('sesskey', sessKey);
                formData.append('author', author);
                formData.append('note', text);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) {
                    if (!res.ok) {
                        throw new Error('Server returned ' + res.status);
                    }
                    return res.json();
                })
                .then(function(data) {
                    noteBtn.disabled = false;
                    noteBtn.textContent = originalBtnText;

                    if (data && data.success && data.note) {
                        // Remove demo notes if this is the first real note
                        var demoNotes = noteList.querySelectorAll('.note-item-demo');
                        demoNotes.forEach(function(el) {
                            el.remove();
                        });

                        var noteItem = document.createElement('div');
                        noteItem.className = 'note-item';
                        noteItem.innerHTML = '<div class="meta"><b>' + escapeHtml(data.note.who) + '</b> · ' + escapeHtml(data.note.date) + '</div>' +
                            '<div class="body">' + escapeHtml(data.note.body).replace(/\n/g, '<br>') + '</div>';

                        noteList.insertBefore(noteItem, noteList.firstChild);
                        noteText.value = '';
                    } else {
                        alert((data && data.message) ? data.message : 'Failed to save review note.');
                    }
                })
                .catch(function(err) {
                    console.error('Error saving note:', err);
                    noteBtn.disabled = false;
                    noteBtn.textContent = originalBtnText;
                    alert('Network error while saving review note. Please try again.');
                });
            });
        }

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // ===================== CRM DATA TAB =====================
        /**
         * baBatchCrm — self-contained CRM Data tab controller for batch.php.
         * Mirrors renderPtf() / fetchUnifiedData() from simple.js but scoped
         * to the students already loaded on this batch page.
         */
        var baBatchCrm = (function() {

            function _formatCrmDate(val) {
                if (!val || val === '-') return '-';
                var d = new Date(val);
                return isNaN(d.getTime()) ? val : d.toLocaleDateString();
            }

            function _uid(username) {
                return (username || '').replace(/[^a-z0-9]/gi, '');
            }

            // Build the table header once
            function _buildHeader() {
                var thead = document.getElementById('ba-crm-thead');
                if (!thead) return;
                var html = '<tr><th style="position:sticky;left:0;background:#f1f5f9;z-index:2;border-right:2px solid #e2e8f0;">Student</th>';
                CRM_COLS.forEach(function(c) {
                    html += '<th>' + escapeHtml(c.h) + '</th>';
                });
                html += '</tr>';
                thead.innerHTML = html;
            }

            // Build all student rows (or rebuild after filter reset)
            function _buildRows() {
                var tbody = document.getElementById('ba-crm-tbody');
                if (!tbody) return;

                if (!studentsData.length) {
                    tbody.innerHTML = '<tr><td colspan="' + (CRM_COLS.length + 1) + '" style="text-align:center;padding:24px;color:#94a3b8;">No students found for this batch.</td></tr>';
                    return;
                }

                var toFetch = [];
                var html = '';
                studentsData.forEach(function(s) {
                    var u = s.username || '';
                    var uid = _uid(u);
                    html += '<tr id="ba-crm-row-' + escapeHtml(uid) + '">';
                    html += '<td style="position:sticky;left:0;background:#fff;z-index:1;border-right:1px solid #eee;min-width:160px;">' +
                            '<b>' + escapeHtml(s.name) + '</b><br>' +
                            '<small style="color:#888;">' + escapeHtml(u || s.id || '-') + '</small></td>';

                    if (PTF_FAILED[u]) {
                        CRM_COLS.forEach(function() { html += '<td style="color:#e53e3e;font-size:12px;">CRM unavailable</td>'; });
                    } else if (PTF_CACHE[u]) {
                        // Will be filled after innerHTML, see _fillCachedRows()
                        CRM_COLS.forEach(function() { html += '<td class="ba-crm-loading">...</td>'; });
                    } else {
                        CRM_COLS.forEach(function() { html += '<td class="ba-crm-loading">...</td>'; });
                        if (u) toFetch.push(u);
                    }
                    html += '</tr>';
                });
                tbody.innerHTML = html;

                // Fill already-cached rows immediately
                studentsData.forEach(function(s) {
                    var u = s.username || '';
                    if (PTF_CACHE[u]) {
                        var tr = document.getElementById('ba-crm-row-' + _uid(u));
                        if (tr) _fillRow(tr, PTF_CACHE[u]);
                    }
                });

                _updateFilterOptions();
                _applyFilters();
                if (toFetch.length) _fetch(toFetch);
            }

            // Fill a single row with CRM data
            function _fillRow(tr, data) {
                var company = data.placed_company || 'Not Placed';
                var isPlaced = company !== 'Not Placed' && company !== 'Checking...' && company !== 'Error';
                var statusHtml = isPlaced
                    ? '<span class="st st-g">Placed</span>'
                    : '<span class="st st-r">Not Placed</span>';

                // Remove all cells after the sticky student cell
                while (tr.children.length > 1) tr.removeChild(tr.lastChild);

                CRM_COLS.forEach(function(c) {
                    var td = document.createElement('td');
                    if (c.k === 'CALC_STATUS') {
                        td.innerHTML = statusHtml;
                    } else if (c.type === 'lookup') {
                        td.textContent = (data[c.k] && data[c.k].name) ? data[c.k].name : (data[c.k] || '-');
                    } else if (c.type === 'date') {
                        td.textContent = data[c.k] ? _formatCrmDate(data[c.k]) : '-';
                    } else {
                        td.textContent = data[c.k] || '-';
                    }
                    tr.appendChild(td);
                });
            }

            // Fetch CRM data in chunks of 10 from index.php
            function _fetch(users) {
                var chunkSize = 10;
                var uncached = users.filter(function(u) {
                    return u && !PTF_CACHE[u] && !PTF_PENDING[u] && !PTF_FAILED[u];
                });
                if (!uncached.length) return;

                for (var i = 0; i < uncached.length; i += chunkSize) {
                    (function(chunk) {
                        var body = 'sesskey=' + encodeURIComponent(CRM_SESSKEY) +
                                   '&payload=' + encodeURIComponent(JSON.stringify({ usernames: chunk }));

                        var p = fetch(CRM_INDEX_URL + '?action=getptfdata', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: body
                        })
                        .then(function(res) { return res.json(); })
                        .then(function(result) {
                            if (!result || result.error) {
                                _markFailed(chunk, result ? result.error : 'CRM data unavailable');
                                return;
                            }
                            if (!result.students) {
                                _markFailed(chunk, 'CRM returned an invalid response');
                                return;
                            }
                            result.students.forEach(function(d) {
                                var u = d.username;
                                PTF_CACHE[u] = d.data
                                    ? Object.assign({}, d.data, { placed_company: d.placed_company, CTC: d.CTC })
                                    : d;
                                var tr = document.getElementById('ba-crm-row-' + _uid(u));
                                if (tr) _fillRow(tr, PTF_CACHE[u]);
                            });
                            _updateFilterOptions();
                            _applyFilters();
                        })
                        .catch(function(e) {
                            _markFailed(chunk, e.message || 'CRM request failed');
                        })
                        .finally(function() {
                            chunk.forEach(function(u) { delete PTF_PENDING[u]; });
                        });

                        chunk.forEach(function(u) { PTF_PENDING[u] = p; });
                    })(uncached.slice(i, i + chunkSize));
                }
            }

            function _markFailed(chunk, msg) {
                chunk.forEach(function(u) { PTF_FAILED[u] = msg || 'CRM unavailable'; });
                if (!CRM_FAILURE_SHOWN) {
                    CRM_FAILURE_SHOWN = true;
                    var retryBtn = document.getElementById('ba-crm-retry-btn');
                    if (retryBtn) { retryBtn.style.display = ''; retryBtn.classList.remove('disabled'); }
                }
                // Refresh failed row cells
                chunk.forEach(function(u) {
                    var tr = document.getElementById('ba-crm-row-' + _uid(u));
                    if (!tr) return;
                    while (tr.children.length > 1) tr.removeChild(tr.lastChild);
                    CRM_COLS.forEach(function() {
                        var td = document.createElement('td');
                        td.style.cssText = 'color:#e53e3e;font-size:12px;';
                        td.textContent = 'CRM unavailable';
                        tr.appendChild(td);
                    });
                });
            }

            function _updateFilterOptions() {
                var yopSet = new Set();
                var stateSet = new Set();
                var petSet = new Set();

                Object.values(PTF_CACHE).forEach(function(d) {
                    if (d.BE_BTech_YoP && d.BE_BTech_YoP !== '-') yopSet.add(d.BE_BTech_YoP);
                    if (d.ME_MTech_YoP && d.ME_MTech_YoP !== '-') yopSet.add(d.ME_MTech_YoP);
                    if (d.Home_State && d.Home_State !== '-') stateSet.add(d.Home_State);
                    if (d.Placement_Eli && d.Placement_Eli !== '-') petSet.add(d.Placement_Eli);
                });

                var renderCbList = function(id, items) {
                    var c = document.getElementById(id);
                    if (!c) return;
                    var checked = Array.from(c.querySelectorAll('input:checked')).map(function(cb){ return cb.value; });
                    if (!items.length) { c.innerHTML = '<span style="color:#94a3b8;">No data yet</span>'; return; }
                    var html = '';
                    Array.from(items).sort().forEach(function(item) {
                        var chk = checked.indexOf(item) !== -1 ? 'checked' : '';
                        html += '<label style="display:flex;gap:8px;align-items:center;cursor:pointer;margin-bottom:5px;font-size:12px;">'
                              + '<input type="checkbox" value="' + escapeHtml(item) + '" class="' + id.replace('ba-crm-f-', 'ba-crm-cb-') + '" onchange="baBatchCrm.applyFilters()" ' + chk + '> '
                              + escapeHtml(item) + '</label>';
                    });
                    c.innerHTML = html;
                };

                renderCbList('ba-crm-f-yop-list', yopSet);
                renderCbList('ba-crm-f-state-list', stateSet);

                // PET dropdown
                var petSel = document.getElementById('ba-crm-f-pet');
                if (petSel) {
                    var cur = petSel.value;
                    var opts = '<option value="">All</option>';
                    Array.from(petSet).sort().forEach(function(p) {
                        opts += '<option value="' + escapeHtml(p) + '">' + escapeHtml(p) + '</option>';
                    });
                    petSel.innerHTML = opts;
                    petSel.value = cur;
                }
            }

            function _applyFilters() {
                var searchEl   = document.getElementById('ba-crm-f-search');
                var placEl     = document.getElementById('ba-crm-f-placement');
                var petEl      = document.getElementById('ba-crm-f-pet');
                var maacMinEl  = document.getElementById('ba-crm-f-maac-min');
                var maacMaxEl  = document.getElementById('ba-crm-f-maac-max');
                var advcMinEl  = document.getElementById('ba-crm-f-advc-min');
                var advcMaxEl  = document.getElementById('ba-crm-f-advc-max');

                var search    = searchEl  ? searchEl.value.toLowerCase() : '';
                var placement = placEl    ? placEl.value : '';
                var pet       = petEl     ? petEl.value : '';
                var maacMin   = maacMinEl ? (parseFloat(maacMinEl.value) || 0)  : 0;
                var maacMax   = maacMaxEl ? (parseFloat(maacMaxEl.value) || 10) : 10;
                var advcMin   = advcMinEl ? (parseFloat(advcMinEl.value) || 0)  : 0;
                var advcMax   = advcMaxEl ? (parseFloat(advcMaxEl.value) || 100): 100;

                var yopChecked   = Array.from(document.querySelectorAll('.ba-crm-cb-yop-list:checked')).map(function(cb){ return cb.value; });
                var stateChecked = Array.from(document.querySelectorAll('.ba-crm-cb-state-list:checked')).map(function(cb){ return cb.value; });

                var matchingRows = [];
                studentsData.forEach(function(s) {
                    var uid = _uid(s.username || '');
                    var tr  = document.getElementById('ba-crm-row-' + uid);
                    if (!tr) return;

                    var show = true;
                    var name = (s.name || '').toLowerCase();
                    var uname = (s.username || '').toLowerCase();
                    if (search && name.indexOf(search) === -1 && uname.indexOf(search) === -1) show = false;

                    var data = s.username ? PTF_CACHE[s.username] : null;
                    if (show && data) {
                        var company = data.placed_company || 'Not Placed';
                        var isPlaced = company !== 'Not Placed' && company !== 'Checking...' && company !== 'Error';
                        if (placement && placement !== (isPlaced ? 'Placed' : 'Not Placed')) show = false;
                        if (show && pet && data.Placement_Eli !== pet) show = false;
                        var maac = parseFloat(data.MAAC_Rating) || 0;
                        if (show && (maac < maacMin || maac > maacMax)) show = false;
                        var advc = parseFloat(data.Advanced_C_Score) || 0;
                        if (show && (advc < advcMin || advc > advcMax)) show = false;
                        if (show && yopChecked.length) {
                            if (yopChecked.indexOf(data.BE_BTech_YoP) === -1 && yopChecked.indexOf(data.ME_MTech_YoP) === -1) show = false;
                        }
                        if (show && stateChecked.length) {
                            if (stateChecked.indexOf(data.Home_State) === -1) show = false;
                        }
                    } else if (show && !data) {
                        // While loading, hide from strict filter pass
                        if (placement || pet || yopChecked.length || stateChecked.length || maacMin > 0 || advcMin > 0) show = false;
                    }

                    tr.setAttribute('data-crm-filter-match', show ? '1' : '0');
                    if (show) matchingRows.push(tr);
                });

                var matchCount = matchingRows.length;
                var isAll = (CRM_PAGE_SIZE === 'all');
                var sizeNum = isAll ? (matchCount || 1) : parseInt(CRM_PAGE_SIZE, 10);
                var totalPages = Math.max(1, Math.ceil(matchCount / sizeNum));
                CRM_CURRENT_PAGE = Math.min(Math.max(CRM_CURRENT_PAGE, 1), totalPages);

                var startIdx = matchCount === 0 ? 0 : (CRM_CURRENT_PAGE - 1) * sizeNum;
                var endIdx = isAll ? matchCount : Math.min(startIdx + sizeNum, matchCount);
                studentsData.forEach(function(s) {
                    var tr = document.getElementById('ba-crm-row-' + _uid(s.username || ''));
                    if (tr) tr.style.display = 'none';
                });
                matchingRows.slice(startIdx, endIdx).forEach(function(tr) {
                    tr.style.display = '';
                });

                var summary = matchCount === 0
                    ? 'No students to display'
                    : ('Showing ' + (startIdx + 1) + '–' + endIdx + ' of ' + matchCount + ' students');
                var countEl = document.getElementById('ba-crm-count');
                if (countEl) countEl.textContent = summary;
                var paginationInfo = document.getElementById('ba-crm-pagination-info');
                if (paginationInfo) paginationInfo.textContent = summary;
                _renderPaginationButtons(totalPages);
            }

            function _renderPaginationButtons(totalPages) {
                var paginationBtns = document.getElementById('ba-crm-pagination-btns');
                if (!paginationBtns) return;

                if (totalPages <= 1 || CRM_PAGE_SIZE === 'all') {
                    paginationBtns.innerHTML = '';
                    return;
                }

                var btnsHtml = '<button type="button" class="ba-pg-btn" id="ba-crm-pg-prev" ' +
                    (CRM_CURRENT_PAGE <= 1 ? 'disabled' : '') + ' aria-label="Previous page">‹ Prev</button>';
                var pages = [];
                if (totalPages <= 7) {
                    for (var i = 1; i <= totalPages; i++) pages.push(i);
                } else {
                    pages.push(1);
                    if (CRM_CURRENT_PAGE > 3) pages.push('...');
                    var startP = Math.max(2, CRM_CURRENT_PAGE - 1);
                    var endP = Math.min(totalPages - 1, CRM_CURRENT_PAGE + 1);
                    for (var j = startP; j <= endP; j++) pages.push(j);
                    if (CRM_CURRENT_PAGE < totalPages - 2) pages.push('...');
                    pages.push(totalPages);
                }

                pages.forEach(function(p) {
                    if (p === '...') {
                        btnsHtml += '<span class="ba-pg-ellipsis">…</span>';
                    } else {
                        btnsHtml += '<button type="button" class="ba-pg-btn ' + (p === CRM_CURRENT_PAGE ? 'active' : '') +
                            '" data-crm-page="' + p + '">' + p + '</button>';
                    }
                });
                btnsHtml += '<button type="button" class="ba-pg-btn" id="ba-crm-pg-next" ' +
                    (CRM_CURRENT_PAGE >= totalPages ? 'disabled' : '') + ' aria-label="Next page">Next ›</button>';
                paginationBtns.innerHTML = btnsHtml;

                var prevBtn = document.getElementById('ba-crm-pg-prev');
                if (prevBtn && !prevBtn.disabled) prevBtn.addEventListener('click', function() {
                    CRM_CURRENT_PAGE--;
                    _applyFilters();
                });
                var nextBtn = document.getElementById('ba-crm-pg-next');
                if (nextBtn && !nextBtn.disabled) nextBtn.addEventListener('click', function() {
                    CRM_CURRENT_PAGE++;
                    _applyFilters();
                });
                paginationBtns.querySelectorAll('button[data-crm-page]').forEach(function(pageBtn) {
                    pageBtn.addEventListener('click', function() {
                        CRM_CURRENT_PAGE = parseInt(this.getAttribute('data-crm-page'), 10);
                        _applyFilters();
                    });
                });
            }

            // -------- Public API --------
            return {
                render: function() {
                    if (!CRM_PANEL_BUILT) {
                        CRM_PANEL_BUILT = true;
                        _buildHeader();
                        _buildRows();
                    } else {
                        _applyFilters();
                    }
                },

                applyFilters: function() {
                    CRM_CURRENT_PAGE = 1;
                    _applyFilters();
                },

                resetFilters: function() {
                    var ids = ['ba-crm-f-search', 'ba-crm-f-placement', 'ba-crm-f-pet'];
                    ids.forEach(function(id) {
                        var el = document.getElementById(id);
                        if (el) el.value = '';
                    });
                    var ranges = {
                        'ba-crm-f-maac-min': 0,  'ba-crm-f-maac-max': 10,
                        'ba-crm-f-advc-min': 0,  'ba-crm-f-advc-max': 100
                    };
                    Object.keys(ranges).forEach(function(id) {
                        var el = document.getElementById(id);
                        if (el) el.value = ranges[id];
                    });
                    document.querySelectorAll('.ba-crm-cb-yop-list, .ba-crm-cb-state-list').forEach(function(cb){ cb.checked = false; });
                    CRM_CURRENT_PAGE = 1;
                    _applyFilters();
                },

                retryCrm: function() {
                    var failed = Object.keys(PTF_FAILED);
                    if (!failed.length) return;
                    PTF_FAILED = {};
                    CRM_FAILURE_SHOWN = false;
                    var retryBtn = document.getElementById('ba-crm-retry-btn');
                    if (retryBtn) retryBtn.style.display = 'none';
                    CRM_CURRENT_PAGE = 1;
                    _buildRows();
                },

                exportCsv: function() {
                    var headers = ['Student Name', 'Username'];
                    CRM_COLS.forEach(function(c) { headers.push(c.h); });
                    var rows = [headers.map(function(h){ return '"' + h.replace(/"/g, '""') + '"'; }).join(',')];

                    studentsData.forEach(function(s) {
                        var uid = _uid(s.username || '');
                        var tr = document.getElementById('ba-crm-row-' + uid);
                        if (tr && tr.getAttribute('data-crm-filter-match') !== '1') return;

                        var row = [
                            '"' + (s.name || '').replace(/"/g, '""') + '"',
                            '"' + (s.username || '').replace(/"/g, '""') + '"'
                        ];
                        var data = s.username ? PTF_CACHE[s.username] : null;
                        CRM_COLS.forEach(function(c) {
                            var val = '-';
                            if (data) {
                                if (c.type === 'lookup') val = (data[c.k] && data[c.k].name) ? data[c.k].name : (data[c.k] || '-');
                                else if (c.type === 'date') val = data[c.k] ? _formatCrmDate(data[c.k]) : '-';
                                else if (c.k === 'CALC_STATUS') val = (data.placed_company && data.placed_company !== 'Not Placed') ? 'Placed' : 'Not Placed';
                                else val = data[c.k] || '-';
                            }
                            row.push('"' + String(val).replace(/"/g, '""') + '"');
                        });
                        rows.push(row.join(','));
                    });

                    var blob = new Blob([rows.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
                    var link = document.createElement('a');
                    link.setAttribute('href', URL.createObjectURL(blob));
                    link.setAttribute('download', 'batch_crm_data.csv');
                    link.style.display = 'none';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }
            };
        })();

        var crmPageSizeSelect = document.getElementById('ba-crm-page-size');
        if (crmPageSizeSelect) {
            crmPageSizeSelect.addEventListener('change', function() {
                CRM_PAGE_SIZE = this.value;
                CRM_CURRENT_PAGE = 1;
                baBatchCrm.render();
            });
        }

        // Expose to global scope so inline onclick handlers in the CRM panel can reach it
        window.baBatchCrm = baBatchCrm;
        // ===========================================================

        // Initial render
        renderPerformanceHeaders();
        renderPerformance();
    });
})();