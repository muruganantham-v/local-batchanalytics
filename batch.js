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
            if (value === null || value === undefined || value === '') return '<span class="muted">&mdash;</span>';
            var num = parseFloat(value);
            if (isNaN(num)) return '<span class="muted">&mdash;</span>';
            var precision = column.ismaac ? 1 : 2;
            var suffix = column.ismaac ? '' : '%';
            return '<b>' + num.toFixed(precision) + suffix + '</b>';
        }

        function csvPerformanceValue(student, column) {
            var value = getCategoryValue(student, column);
            if (value === null || value === undefined || value === '') return '-';
            var num = parseFloat(value);
            if (isNaN(num)) return '-';
            return num.toFixed(column.ismaac ? 1 : 2) + (column.ismaac ? '' : '%');
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
        var selectedBand = null;

        var performanceTable = container.querySelector('#panel-students table');

        function groupToggle(label, key, collapsed) {
            return '<button type="button" class="ba-maac-group-toggle" data-performance-group-toggle="' + escapeHtml(key) + '" aria-expanded="' + (!collapsed) + '">' +
                '<span class="ba-maac-group-toggle-icon" aria-hidden="true">' + (collapsed ? '+' : '-') + '</span>' +
                '<span class="ba-maac-group-toggle-label">' + escapeHtml(label) + '</span></button>';
        }

        function normalizeTrendKey(value) {
            var normalized = String(value || '').trim().toLowerCase();
            if (!normalized || normalized === '-') return '';
            if (normalized === 'up' || normalized === 'improving') return 'up';
            if (normalized === 'down' || normalized === 'declining') return 'down';
            return 'stable';
        }

        function getTrendVisual(value) {
            var key = normalizeTrendKey(value);
            if (!key) return null;
            if (key === 'up') {
                return { key: 'up', label: 'Improving', emoji: '\uD83D\uDCC8', className: 'ba-trend-badge-up' };
            }
            if (key === 'down') {
                return { key: 'down', label: 'Declining', emoji: '\uD83D\uDCC9', className: 'ba-trend-badge-down' };
            }
            return { key: 'stable', label: 'Stable', emoji: '\u27A1\uFE0F', className: 'ba-trend-badge-stable' };
        }

        function renderTrendBadge(student, value) {
            if (value === undefined || value === null || value === '') return '<span class="muted">&mdash;</span>';
            var visual = getTrendVisual(value) || { key: 'stable', label: String(value), emoji: '\u27A1\uFE0F', className: 'ba-trend-badge-stable' };
            var badge = '<span class="ba-trend-badge ' + visual.className + '"><span>' + visual.emoji + '</span><span>' + escapeHtml(visual.label) + '</span></span>';
            return '<button type="button" class="ba-trend-badge-btn" data-performance-trend-userid="' + Number(student.userid || 0) + '" aria-label="View trend details for ' + escapeHtml(student.name || student.fullname || 'student') + '">' + badge + '</button>';
        }

        function getStudentTrendRecords(student) {
            if (!student) return [];
            var detailsList = student.trend_details;
            if (Array.isArray(detailsList) && detailsList.length > 0) {
                if (detailsList[0] && detailsList[0].details) {
                    return detailsList;
                }
                if (detailsList[0] && (detailsList[0].assignments || detailsList[0].attendance || detailsList[0].quizzes)) {
                    return [{ course: 'Course', details: detailsList[0] }];
                }
            } else if (detailsList && typeof detailsList === 'object' && (detailsList.assignments || detailsList.attendance || detailsList.quizzes)) {
                return [{ course: 'Course', details: detailsList }];
            }
            return [];
        }

        function getRecordTrendDetails(details) {
            details = details || {};
            return {
                assignments: Array.isArray(details.assignments) ? details.assignments : [],
                quizzes: Array.isArray(details.quizzes) ? details.quizzes : [],
                projects: Array.isArray(details.projects) ? details.projects : [],
                attendance: Array.isArray(details.attendance) ? details.attendance : [],
                overall_trend: details.overall_trend || '',
                trend_window: Math.max(1, Number(details.trend_window) || 20),
                attendance_window: Math.max(2, Number(details.attendance_window) || 5)
            };
        }

        function calculateCourseTrendComponent(points) {
            if (!Array.isArray(points) || !points.length) return null;
            if (points.length === 1) {
                var grade = parseFloat(points[0].grade);
                if (isNaN(grade)) return null;
                var rounded = Math.round(grade * 10) / 10;
                return { start: rounded, latest: rounded, change: 0 };
            }
            if (points.length < 4) {
                var firstGrade = parseFloat(points[0].grade);
                var lastGrade = parseFloat(points[points.length - 1].grade);
                if (isNaN(firstGrade) || isNaN(lastGrade)) return null;
                return {
                    start: Math.round(firstGrade * 10) / 10,
                    latest: Math.round(lastGrade * 10) / 10,
                    change: Math.round((lastGrade - firstGrade) * 10) / 10
                };
            }
            var split = Math.floor(points.length / 2);
            var olderGrades = points.slice(0, split).map(function(item) { return parseFloat(item.grade); }).filter(function(g) { return !isNaN(g); });
            var recentGrades = points.slice(split).map(function(item) { return parseFloat(item.grade); }).filter(function(g) { return !isNaN(g); });
            if (!olderGrades.length || !recentGrades.length) return null;
            var olderAverage = olderGrades.reduce(function(sum, g) { return sum + g; }, 0) / olderGrades.length;
            var recentAverage = recentGrades.reduce(function(sum, g) { return sum + g; }, 0) / recentGrades.length;
            return {
                start: Math.round(olderAverage * 10) / 10,
                latest: Math.round(recentAverage * 10) / 10,
                change: Math.round((recentAverage - olderAverage) * 10) / 10
            };
        }

        function calculateCourseAttendanceSummary(attendancePoints, windowSize) {
            if (!Array.isArray(attendancePoints) || !attendancePoints.length) return null;
            var size = windowSize || 5;
            function countPresent(points) {
                return points.reduce(function(sum, item) {
                    var status = item.status ? String(item.status).toUpperCase() : '';
                    var isPres = Number(item.present) === 1 || status === 'P' || status === 'E';
                    return sum + (isPres ? 1 : 0);
                }, 0);
            }
            if (attendancePoints.length < 2) {
                var onlyCount = countPresent(attendancePoints);
                return {
                    windowSize: attendancePoints.length,
                    currentCount: onlyCount,
                    currentRate: attendancePoints.length ? Math.round((onlyCount / attendancePoints.length) * 100) : 0,
                    previousWindowSize: 0,
                    previousCount: 0,
                    previousRate: 0,
                    delta: null,
                    comparisonMode: 'insufficient',
                    comparisonLabel: 'Need at least 2 sessions for comparison'
                };
            }
            var recent = attendancePoints.slice(-size);
            var currentCount = countPresent(recent);
            var currentTotal = recent.length;
            var previous = attendancePoints.slice(Math.max(0, attendancePoints.length - size * 2), attendancePoints.length - size);
            var previousCount = countPresent(previous);
            var previousTotal = previous.length;
            var minComparablePrevious = Math.max(2, Math.ceil(currentTotal / 2));
            if (!previousTotal || previousTotal < minComparablePrevious) {
                var split = Math.floor(attendancePoints.length / 2);
                var older = attendancePoints.slice(0, split);
                var newer = attendancePoints.slice(split);
                if (older.length && newer.length) {
                    var olderCount = countPresent(older);
                    var newerCount = countPresent(newer);
                    return {
                        windowSize: newer.length,
                        currentCount: newerCount,
                        currentRate: Math.round((newerCount / newer.length) * 100),
                        previousWindowSize: older.length,
                        previousCount: olderCount,
                        previousRate: Math.round((olderCount / older.length) * 100),
                        delta: newerCount - olderCount,
                        comparisonMode: 'adaptive-half',
                        comparisonLabel: 'Compared recent half vs earlier half'
                    };
                }
            }
            return {
                windowSize: currentTotal,
                currentCount: currentCount,
                currentRate: currentTotal ? Math.round((currentCount / currentTotal) * 100) : 0,
                previousWindowSize: previousTotal,
                previousCount: previousCount,
                previousRate: previousTotal ? Math.round((previousCount / previousTotal) * 100) : 0,
                delta: previousTotal > 0 ? currentCount - previousCount : null,
                comparisonMode: 'window',
                comparisonLabel: previousTotal > 0 ? ('Compared last ' + currentTotal + ' vs previous ' + previousTotal) : ''
            };
        }

        function calculateCourseOverallTrend(details) {
            var trendWindow = Math.max(1, Number(details.trend_window) || 20);
            var attendanceWindow = Math.max(2, Math.min(Number(details.attendance_window) || 5, trendWindow));
            var changes = [];
            ['assignments', 'quizzes', 'projects'].forEach(function(key) {
                var points = Array.isArray(details[key]) ? details[key].slice(-trendWindow) : [];
                var summary = calculateCourseTrendComponent(points);
                if (summary && typeof summary.change === 'number' && !isNaN(summary.change)) {
                    changes.push(summary.change);
                }
            });
            var attendanceSummary = calculateCourseAttendanceSummary(
                Array.isArray(details.attendance) ? details.attendance.slice(-trendWindow) : [],
                attendanceWindow
            );
            if (attendanceSummary && attendanceSummary.previousWindowSize > 0) {
                var attendanceChange = attendanceSummary.currentRate - attendanceSummary.previousRate;
                if (!isNaN(attendanceChange)) {
                    changes.push(Math.round(attendanceChange * 10) / 10);
                }
            }
            if (!changes.length) {
                return normalizeTrendKey(details.overall_trend) || 'stable';
            }
            var averageChange = changes.reduce(function(sum, val) { return sum + val; }, 0) / changes.length;
            if (averageChange > 5) return 'up';
            if (averageChange < -5) return 'down';
            return 'stable';
        }

        function calculateCourseCurrentLevel(details) {
            var trendWindow = Math.max(1, Number(details.trend_window) || 20);
            var attendanceWindow = Math.max(2, Math.min(Number(details.attendance_window) || 5, trendWindow));
            var values = [];
            ['assignments', 'quizzes', 'projects'].forEach(function(key) {
                var points = Array.isArray(details[key]) ? details[key].slice(-trendWindow) : [];
                if (!points.length) return;
                var latest = parseFloat(points[points.length - 1].grade);
                if (!isNaN(latest)) values.push(latest);
            });
            var attendanceSummary = calculateCourseAttendanceSummary(
                Array.isArray(details.attendance) ? details.attendance.slice(-trendWindow) : [],
                attendanceWindow
            );
            if (attendanceSummary && typeof attendanceSummary.currentRate === 'number' && !isNaN(attendanceSummary.currentRate)) {
                values.push(attendanceSummary.currentRate);
            }
            if (!values.length) return { label: 'Limited Data', score: null };
            var average = values.reduce(function(sum, val) { return sum + val; }, 0) / values.length;
            var rounded = Math.round(average);
            if (average >= 75) return { label: 'Strong', score: rounded };
            if (average >= 50) return { label: 'Moderate', score: rounded };
            return { label: 'Low', score: rounded };
        }

        function renderCourseTrendMiniChart(points) {
            if (!Array.isArray(points) || !points.length) return '';
            var safePoints = points.map(function(point, index) {
                return {
                    label: point.name || ('Item ' + (index + 1)),
                    value: Math.max(0, Math.min(100, parseFloat(point.grade) || 0))
                };
            });
            if (!safePoints.length) return '';
            var width = 320;
            var height = 80;
            var leftPadding = 28;
            var rightPadding = 10;
            var topPadding = 10;
            var bottomPadding = 10;
            var drawableWidth = width - leftPadding - rightPadding;
            var drawableHeight = height - topPadding - bottomPadding;
            var step = safePoints.length === 1 ? 0 : drawableWidth / (safePoints.length - 1);
            var coords = safePoints.map(function(point, index) {
                var x = leftPadding + step * index;
                var y = topPadding + ((100 - point.value) / 100) * drawableHeight;
                return { label: point.label, value: point.value, x: x, y: y };
            });
            var path = coords.map(function(p, i) {
                return (i === 0 ? 'M' : 'L') + ' ' + p.x.toFixed(2) + ' ' + p.y.toFixed(2);
            }).join(' ');
            var area = path + ' L ' + coords[coords.length - 1].x.toFixed(2) + ' ' + (height - bottomPadding).toFixed(2) + ' L ' + coords[0].x.toFixed(2) + ' ' + (height - bottomPadding).toFixed(2) + ' Z';
            var circles = coords.map(function(p) {
                var color = p.value >= 75 ? '#667eea' : (p.value >= 60 ? '#fbbf24' : '#ef4444');
                return '<circle cx="' + p.x.toFixed(2) + '" cy="' + p.y.toFixed(2) + '" r="3.5" fill="' + color + '" stroke="' + color + '" stroke-width="1">' +
                    '<title>' + escapeHtml(p.label) + ': ' + escapeHtml(String(Math.round(p.value * 10) / 10)) + '%</title></circle>';
            }).join('');
            var axisLabels = [
                { value: 100, y: topPadding },
                { value: 50, y: topPadding + drawableHeight / 2 },
                { value: 0, y: height - bottomPadding }
            ].map(function(tick) {
                return '<g><line x1="' + leftPadding + '" y1="' + tick.y.toFixed(2) + '" x2="' + (width - rightPadding).toFixed(2) + '" y2="' + tick.y.toFixed(2) + '" stroke="#e5e7eb" stroke-width="1"></line>' +
                    '<text x="' + (leftPadding - 6).toFixed(2) + '" y="' + (tick.y + 4).toFixed(2) + '" text-anchor="end" font-size="10" fill="#94a3b8">' + tick.value + '</text></g>';
            }).join('');
            var gradId = 'ba-trend-fill-' + Math.random().toString(36).substr(2, 9);
            return '<div class="chart-container" style="position: relative; height: 80px; width: 100%;">' +
                '<svg viewBox="0 0 ' + width + ' ' + height + '" width="100%" height="80" role="img" aria-label="Grade trend chart">' +
                '<defs><linearGradient id="' + gradId + '" x1="0" x2="0" y1="0" y2="1">' +
                '<stop offset="0%" stop-color="rgba(102, 126, 234, 0.28)"></stop>' +
                '<stop offset="100%" stop-color="rgba(102, 126, 234, 0.04)"></stop>' +
                '</linearGradient></defs>' +
                axisLabels +
                '<path d="' + area + '" fill="url(#' + gradId + ')"></path>' +
                '<path d="' + path + '" fill="none" stroke="rgba(102, 126, 234, 1)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>' +
                circles +
                '</svg></div>';
        }

        function buildCourseTrendCards(details, trendWindow) {
            function renderComponentCard(title, icon, points) {
                var recentPoints = Array.isArray(points) ? points.slice(-trendWindow) : [];
                if (!recentPoints.length) return '';
                var summary = calculateCourseTrendComponent(recentPoints);
                if (!summary) return '';
                var deltaBg = summary.change >= 0 ? '#f0fdf4' : '#fef2f2';
                var deltaColor = summary.change >= 0 ? '#10b981' : '#ef4444';
                return '<div class="trend-card">' +
                    '<div class="trend-title">' + icon + ' ' + escapeHtml(title) + '</div>' +
                    '<div class="trend-chart">' + renderCourseTrendMiniChart(recentPoints) + '</div>' +
                    '<div class="trend-stats">' +
                    '<div><span style="color: #6b7280;">Start:</span> <strong>' + escapeHtml(String(summary.start)) + '%</strong></div>' +
                    '<div><span style="color: #6b7280;">Latest:</span> <strong>' + escapeHtml(String(summary.latest)) + '%</strong></div>' +
                    '<div style="margin-top: 8px; padding: 8px; background: ' + deltaBg + '; border-radius: 6px;">' +
                    '<strong style="color: ' + deltaColor + '">' + (summary.change >= 0 ? '+' : '') + escapeHtml(String(summary.change)) + '%</strong>' +
                    '</div></div></div>';
            }
            var attendancePoints = Array.isArray(details.attendance) ? details.attendance : [];
            var attendanceCard = '';
            if (attendancePoints.length) {
                var attendanceWindow = Math.max(2, Math.min(Number(details.attendance_window) || 5, trendWindow));
                var summary = calculateCourseAttendanceSummary(attendancePoints, attendanceWindow);
                if (summary) {
                    var deltaColor = '#6b7280';
                    var deltaBg = '#f3f4f6';
                    var deltaText = summary.comparisonLabel || 'Need more sessions for comparison';
                    if (summary.delta !== null) {
                        if (summary.delta > 0) { deltaColor = '#10b981'; deltaBg = '#f0fdf4'; }
                        else if (summary.delta < 0) { deltaColor = '#ef4444'; deltaBg = '#fef2f2'; }
                        else { deltaColor = '#f59e0b'; deltaBg = '#fffbeb'; }
                        deltaText = summary.comparisonMode === 'adaptive-half'
                            ? (summary.delta > 0 ? '+' : '') + summary.delta + ' vs earlier ' + summary.previousWindowSize
                            : (summary.delta > 0 ? '+' : '') + summary.delta + ' vs previous ' + summary.previousWindowSize;
                    }
                    var strip = '<div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px;">' +
                        attendancePoints.slice(-10).map(function(point) {
                            var status = point.status ? String(point.status).toUpperCase() : (Number(point.present) === 1 ? 'P' : 'A');
                            var color = '#ef4444';
                            if (status === 'P' || status === 'E') color = '#10b981';
                            else if (status === 'L') color = '#f59e0b';
                            return '<span style="display: inline-flex; align-items: center; justify-content: center; min-width: 28px; padding: 4px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; color: #fff; background: ' + color + ';">' + escapeHtml(status) + '</span>';
                        }).join('') + '</div>';
                    attendanceCard = '<div class="trend-card">' +
                        '<div class="trend-title">\uD83D\uDC65 Attendance</div>' +
                        strip +
                        '<div class="trend-stats">' +
                        '<div><span style="color: #6b7280;">Last ' + summary.windowSize + ':</span> <strong>' + summary.currentCount + ' attended</strong></div>' +
                        '<div><span style="color: #6b7280;">Recent rate:</span> <strong>' + summary.currentRate + '%</strong></div>' +
                        (summary.previousWindowSize > 0 ? '<div><span style="color: #6b7280;">Previous rate:</span> <strong>' + summary.previousRate + '%</strong></div>' : '') +
                        '<div style="margin-top: 8px; padding: 8px; background: ' + deltaBg + '; border-radius: 6px;">' +
                        '<strong style="color: ' + deltaColor + '">' + escapeHtml(deltaText) + '</strong>' +
                        '</div></div></div>';
                }
            }
            var cards = [
                renderComponentCard('Assignments', '\uD83D\uDCDD', details.assignments),
                renderComponentCard('Quizzes', '\uD83E\uDDE0', details.quizzes),
                renderComponentCard('Projects', '\uD83D\uDCCA', details.projects),
                attendanceCard
            ].filter(Boolean).join('');
            return cards;
        }

        function renderCourseTrendSection(student, record) {
            var details = getRecordTrendDetails(record.details);
            var trendWindow = details.trend_window;
            var visual = getTrendVisual(calculateCourseOverallTrend(details)) || getTrendVisual('stable');
            var currentLevel = calculateCourseCurrentLevel(details);
            var cards = buildCourseTrendCards(details, trendWindow);
            var trendColor = visual.key === 'up' ? '#10b981' : (visual.key === 'down' ? '#ef4444' : '#f59e0b');
            return '<div class="trends-indicator" style="background: ' + trendColor + '20; border-left: 4px solid ' + trendColor + '; padding: 16px; border-radius: 8px; margin-bottom: 20px;">' +
                '<div style="display: flex; align-items: center; gap: 12px;">' +
                '<span style="font-size: 24px;">' + visual.emoji + '</span>' +
                '<div>' +
                '<div style="font-size: 13px; color: #6b7280; margin-bottom: 2px;">' + escapeHtml(student.name || student.fullname || student.username || 'Student') + '</div>' +
                '<div style="font-size: 14px; color: #6b7280;">Overall Trend (last ' + trendWindow + ')</div>' +
                '<div style="font-size: 18px; font-weight: 700; color: ' + trendColor + '">' + escapeHtml(visual.label) + ' (' + escapeHtml(currentLevel.label) + ')</div>' +
                (currentLevel.score !== null ? '<div style="font-size: 13px; color: #6b7280;">Current level score: ' + escapeHtml(String(currentLevel.score)) + '%</div>' : '') +
                '</div></div></div>' +
                '<div class="trends-grid">' +
                (cards || '<div class="trend-card"><div class="trend-title">Trend</div><div class="trend-stats"><div>No trend detail is available for this student yet.</div></div></div>') +
                '</div>';
        }

        function openTrendModal(student) {
            var records = getStudentTrendRecords(student);
            var existing = document.getElementById('ba-student-performance-trend-modal');
            if (existing) existing.remove();

            var modal = document.createElement('div');
            modal.className = 'trends-modal';
            modal.id = 'ba-student-performance-trend-modal';

            var activeIndex = 0;

            function updateModalContent() {
                var currentRecord = records.length > 0 ? records[activeIndex] : null;
                var headerTitle = (currentRecord && currentRecord.course)
                    ? (currentRecord.course + ' - Grade Trends')
                    : 'Grade Trends';

                var courseTabsHtml = '';
                if (records.length > 1) {
                    courseTabsHtml = '<div style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;">' +
                        records.map(function(rec, idx) {
                            var isAct = idx === activeIndex;
                            var btnBg = isAct ? '#4f46e5' : '#f1f5f9';
                            var btnColor = isAct ? '#ffffff' : '#475569';
                            return '<button type="button" class="ba-trend-tab-btn" data-trend-tab-idx="' + idx + '" style="padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; border: 1px solid ' + (isAct ? '#4f46e5' : '#e2e8f0') + '; background: ' + btnBg + '; color: ' + btnColor + '; cursor: pointer;">' +
                                escapeHtml(rec.course || ('Course ' + (idx + 1))) + '</button>';
                        }).join('') + '</div>';
                }

                var contentHtml = currentRecord
                    ? renderCourseTrendSection(student, currentRecord)
                    : '<div class="trends-grid"><div class="trend-card"><div class="trend-title">Trend</div><div class="trend-stats"><div>No trend detail is available for this student yet.</div></div></div></div>';

                modal.innerHTML = '<div class="trends-content">' +
                    '<div class="trends-header">' +
                    '<h3>' + escapeHtml(headerTitle) + '</h3>' +
                    '<button type="button" class="close-trends" aria-label="Close">x</button>' +
                    '</div>' +
                    courseTabsHtml +
                    contentHtml +
                    '</div>';
            }

            updateModalContent();

            modal.addEventListener('click', function(event) {
                if (event.target === modal || event.target.closest('.close-trends')) {
                    modal.remove();
                    return;
                }
                var tabBtn = event.target.closest('[data-trend-tab-idx]');
                if (tabBtn) {
                    activeIndex = Number(tabBtn.getAttribute('data-trend-tab-idx'));
                    updateModalContent();
                }
            });

            document.body.appendChild(modal);
        }

        function normalizeFeedbackList(value) {
            if (!value) return [];
            var list = Array.isArray(value) ? value : [value];
            var result = [];
            list.forEach(function(item) {
                if (typeof item === 'string') {
                    var trimmed = item.trim();
                    if (trimmed.startsWith('[') && trimmed.endsWith(']')) {
                        try {
                            var parsed = JSON.parse(trimmed);
                            if (Array.isArray(parsed)) {
                                parsed.forEach(function(p) {
                                    if (p && typeof p === 'object') {
                                        result.push({ date: String(p.date || ''), text: String(p.text || p.label || p.value || '') });
                                    } else if (p) {
                                        result.push({ date: '', text: String(p) });
                                    }
                                });
                                return;
                            }
                        } catch(e) {}
                    }
                    if (trimmed !== '') result.push({ date: '', text: trimmed });
                } else if (item && typeof item === 'object') {
                    var text = String(item.text || item.label || item.value || '');
                    if (text.trim() !== '') {
                        result.push({ date: String(item.date || ''), text: text });
                    }
                }
            });
            return result;
        }

        function formatFeedbackDate(dateStr) {
            var raw = String(dateStr || '').trim();
            if (!raw) return 'No date';
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (match) {
                var mIdx = Math.max(0, Math.min(11, parseInt(match[2], 10) - 1));
                return match[3] + '-' + months[mIdx] + '-' + match[1];
            }
            return raw;
        }

        function openFeedbackModal(student, columnKey, columnLabel) {
            var val = student.custom && student.custom[columnKey];
            var feedbacks = normalizeFeedbackList(val);
            var existing = document.getElementById('ba-feedback-modal-dialog');
            if (existing) existing.remove();

            var cards = feedbacks.length ? feedbacks.map(function(item, idx) {
                return '<div class="ba-feedback-card" style="display:flex; border:1px solid #dbe5f0; border-radius:8px; margin-bottom:12px; overflow:hidden; background:#fff;">' +
                    '<div class="ba-feedback-card-left" style="background:#eaf6ff; padding:10px 14px; border-right:1px solid #dbe5f0; min-width:140px; display:flex; flex-direction:column; gap:6px;">' +
                    '<div class="ba-feedback-card-title" style="color:#0369a1; font-size:13px; font-weight:700;">' + escapeHtml(columnLabel) + ' - ' + (idx + 1) + '</div>' +
                    '<div class="ba-feedback-card-date" style="color:#0284c7; font-size:11px; display:flex; gap:4px;"><span style="font-weight:700;">Date:</span> <span>' + escapeHtml(formatFeedbackDate(item.date)) + '</span></div>' +
                    '</div>' +
                    '<div class="ba-feedback-card-right" style="padding:10px 14px; flex:1; background:#f8fafc; color:#334155; font-size:13px; line-height:1.4; display:flex; align-items:center;">' +
                    '<div class="ba-feedback-card-text">' + escapeHtml(item.text) + '</div>' +
                    '</div>' +
                    '</div>';
            }).join('') : '<div style="text-align:center; padding:24px; color:#64748b;">No feedback provided yet.</div>';

            var modal = document.createElement('div');
            modal.className = 'trends-modal';
            modal.id = 'ba-feedback-modal-dialog';
            modal.innerHTML = '<div class="trends-content" style="max-width:700px;">' +
                '<div class="trends-header">' +
                '<h3>' + escapeHtml(student.name || student.fullname || 'Student') + ' - ' + escapeHtml(columnLabel) + '</h3>' +
                '<button type="button" class="close-trends" aria-label="Close">x</button>' +
                '</div>' +
                '<div style="max-height:60vh; overflow-y:auto; padding:4px;">' + cards + '</div>' +
                '</div>';

            modal.addEventListener('click', function(e) {
                if (e.target === modal || e.target.closest('.close-trends')) {
                    modal.remove();
                }
            });
            document.body.appendChild(modal);
        }

        function renderCustomPerformanceCell(student, column, group) {
            var value = student.custom && student.custom[column.key];
            if (column.key === 'trend') {
                return renderTrendBadge(student, value);
            }

            var isFeedback = (column.type === 'multi_feedback') ||
                             (column.key && column.key.indexOf('feedback') !== -1) ||
                             (column.label && column.label.toLowerCase().indexOf('feedback') !== -1);
            if (isFeedback) {
                var feedbacks = normalizeFeedbackList(value);
                if (!feedbacks.length) {
                    return '<span class="muted">&mdash;</span>';
                }
                return '<button type="button" class="ba-btn ba-btn-sm ba-performance-feedback-btn" data-feedback-userid="' + Number(student.userid || 0) + '" data-feedback-colkey="' + escapeHtml(column.key) + '" data-feedback-collabel="' + escapeHtml(column.label) + '" style="display:inline-flex; align-items:center; gap:4px; padding:4px 8px; border:1px solid #6366f1; color:#6366f1; background:transparent; border-radius:4px; font-size:12px; font-weight:600; cursor:pointer;">' +
                    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg> View Feedback (' + feedbacks.length + ')' +
                    '</button>';
            }

            var isBoolean = (column.type === 'boolean') ||
                            (column.datatype === 'checkbox');
            if (isBoolean) {
                var isTrue = false;
                if (Array.isArray(value)) {
                    isTrue = value.some(function(v) {
                        return v === 1 || v === '1' || v === true || v === 'true' || String(v).toLowerCase() === 'yes';
                    });
                } else {
                    isTrue = (value === 1 || value === '1' || value === true || value === 'true' || String(value).toLowerCase() === 'yes');
                }
                return isTrue
                    ? '<span class="ba-maac-chip" style="display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:999px; background:#dcfce7; color:#15803d; font-size:11.5px; font-weight:700;">Yes</span>'
                    : '<span class="ba-maac-chip" style="display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:999px; background:#f1f5f9; color:#64748b; font-size:11.5px; font-weight:600;">No</span>';
            }

            var items = null;
            if (Array.isArray(value)) {
                items = value.map(function(item) {
                    if (item && typeof item === 'object') return item.text || item.label || item.value || '';
                    return String(item);
                }).filter(function(s) { return String(s).trim() !== ''; });
            } else if (typeof value === 'string' && value.trim().startsWith('[') && value.trim().endsWith(']')) {
                try {
                    var parsed = JSON.parse(value.trim());
                    if (Array.isArray(parsed)) {
                        items = parsed.map(function(item) {
                            if (item && typeof item === 'object') return item.text || item.label || item.value || '';
                            return String(item);
                        }).filter(function(s) { return String(s).trim() !== ''; });
                    }
                } catch(e) {}
            }

            if (items !== null) {
                if (!items.length) return '<span class="muted">&mdash;</span>';
                return items.map(function(it) {
                    return '<span class="ba-maac-chip" style="display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:999px; background:#f1f5f9; color:#334155; font-size:11.5px; font-weight:600; margin:2px;">' + escapeHtml(String(it)) + '</span>';
                }).join(' ');
            }

            if (value === undefined || value === null || value === '' || value === '-') {
                return '<span class="muted">&mdash;</span>';
            }
            return escapeHtml(String(value));
        }
        function customGroupCells(student) {
            return performanceCustomGroups.map(function(group) {
                if (performanceCollapsedGroups[group.key]) {
                    return '<td class="ba-maac-collapsed-col ba-performance-group-collapsed"></td>';
                }
                return (group.columns || []).map(function(column) {
                    var display = renderCustomPerformanceCell(student, column, group);
                    var trendClass = column.key === 'trend' ? ' ba-performance-custom-trend' : '';
                    return '<td class="ba-performance-custom-col ba-performance-group-' + escapeHtml(group.key) + trendClass + '">' + display + '</td>';
                }).join('');
            }).join('');
        }

        function getVisiblePerformanceColumnCount() {
            return 4 + performanceColumns.length;
        }

        function renderPerformanceHeaders() {
            if (!performanceTable) return;
            var thead = performanceTable.querySelector('thead');
            if (!thead) return;
            var overallLabel = perfMode === 'grade' ? 'Grade' : 'Percentile';
            var overallTitle = perfMode === 'grade' ? 'Sort by Grade' : 'Sort by Percentile';
            var html = '<tr>' +
                '<th class="sortable" data-sort="band" title="Sort by Band">Band</th>' +
                '<th class="sortable ba-performance-student-head" data-sort="student" title="Sort by Student Name">Student</th>' +
                '<th class="sortable ba-performance-overall-head" data-sort="grade" title="' + overallTitle + '">' + overallLabel + '</th>';
            performanceColumns.forEach(function(column) {
                html += '<th class="sortable ba-performance-group-module" data-sort="category:' + escapeHtml(column.key) + '" title="Sort by ' + escapeHtml(column.label) + '">' + escapeHtml(column.label) + '</th>';
            });
            html += '<th class="sortable" data-sort="merit" title="Sort by Merit">Merit</th></tr>';
            thead.innerHTML = html;
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
                        var spotCountA = Number(a.spot_count || (a.spot ? 1 : 0));
                        var spotCountB = Number(b.spot_count || (b.spot ? 1 : 0));
                        var scoreA = (spotCountA * 10) + (a.pt === 'sel' ? 5 : (a.pt === 'nom' ? 2 : 0));
                        var scoreB = (spotCountB * 10) + (b.pt === 'sel' ? 5 : (b.pt === 'nom' ? 2 : 0));
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
                var percentile = (percentiles && student && student._origIdx !== undefined) ? percentiles[student._origIdx] : undefined;
                return (percentile === undefined || isNaN(percentile)) ? '&mdash;' : percentile + '%';
            }
            if (!student || student.grade === null || student.grade === undefined || student.grade === '') {
                return '&mdash;';
            }
            var num = parseFloat(student.grade);
            return isNaN(num) ? '&mdash;' : escapeHtml(num.toFixed(2) + '%');
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

        function openBandDetailModal(bandKey) {
            var existing = document.getElementById('ba-band-detail-modal');
            if (existing) existing.remove();

            var bandStudents = studentsData.filter(function(s) { return s._band === bandKey; });
            var percentileValues = getPercentileValues(studentsData);

            var meta = {
                top: {
                    title: perfMode === 'grade' ? 'Top Performers' : 'Top 15%',
                    sub: perfMode === 'grade' ? 'Overall Grade > 70%' : 'Top 15% by class rank',
                    color: '#16a34a',
                    dotClass: 'var(--green, #1e8e4e)'
                },
                mid: {
                    title: perfMode === 'grade' ? 'Middle Performers' : 'Middle 70%',
                    sub: perfMode === 'grade' ? 'Overall Grade 40% – 70%' : 'Middle 70% by class rank',
                    color: '#d97706',
                    dotClass: 'var(--amber, #c77a0a)'
                },
                bot: {
                    title: perfMode === 'grade' ? 'Low Performers' : 'Bottom 15%',
                    sub: perfMode === 'grade' ? 'Overall Grade < 40%' : 'Bottom 15% by class rank',
                    color: '#dc2626',
                    dotClass: 'var(--red, #c0392b)'
                }
            };
            var bandMeta = meta[bandKey] || meta.mid;

            var modal = document.createElement('div');
            modal.className = 'ba-band-modal';
            modal.id = 'ba-band-detail-modal';

            var modalSortCol = 'grade';
            var modalSortDir = 'desc';
            var modalSearch = '';
            // modalScoreFilter removed

            var categoryHeadersHtml = performanceColumns.map(function(c) {
                return '<th class="ba-modal-th-sortable" data-modal-col="' + escapeHtml(c.key) + '">' +
                    escapeHtml(c.label) + ' <span class="ba-sort-indicator">⇅</span></th>';
            }).join('');

            modal.innerHTML = '<div class="ba-band-modal-dialog" role="dialog" aria-modal="true">' +
                '<div class="ba-band-modal-header">' +
                    '<div style="display:flex; align-items:center; gap:12px;">' +
                        '<span style="display:inline-block; width:14px; height:14px; border-radius:50%; background:' + bandMeta.color + ';"></span>' +
                        '<div>' +
                            '<h3 style="margin:0; font-size:18px; font-weight:700; color:#0f172a;">' + escapeHtml(bandMeta.title) + ' (' + bandStudents.length + ' Students)</h3>' +
                            '<div style="font-size:12px; color:#64748b; margin-top:2px;">' + escapeHtml(bandMeta.sub) + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div style="display:flex; align-items:center; gap:10px;">' +
                        '<input type="text" id="ba-band-modal-search" class="ba-modal-input-search" placeholder="Search student name or ID..." style="width:200px;">' +
                        '<button type="button" id="ba-band-modal-export" class="ba-modal-btn-export">' +
                            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
                                '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>' +
                                '<polyline points="7 10 12 15 17 10"/>' +
                                '<line x1="12" y1="15" x2="12" y2="3"/>' +
                            '</svg>' +
                            '<span>Export CSV</span>' +
                        '</button>' +
                        '<button type="button" class="ba-modal-btn-close-circle ba-band-modal-close" aria-label="Close modal">' +
                            '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
                                '<line x1="18" y1="6" x2="6" y2="18"></line>' +
                                '<line x1="6" y1="6" x2="18" y2="18"></line>' +
                            '</svg>' +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="ba-band-modal-body">' +
                    '<div class="tablecard" style="margin:0; max-height:55vh; overflow-y:auto; overflow-x:auto;">' +
                        '<table style="width:100%; border-collapse:separate; border-spacing:0;">' +
                            '<thead style="position:sticky; top:0; background:#fff; z-index:10; box-shadow:0 1px 0 #e2e8f0;">' +
                                '<tr>' +
                                    '<th class="ba-modal-th-sortable ba-modal-sticky-col" data-modal-col="name">Student <span class="ba-sort-indicator">⇅</span></th>' +
                                    '<th class="ba-modal-th-sortable" data-modal-col="grade">' + (perfMode === 'grade' ? 'Grade' : 'Percentile') + ' <span class="ba-sort-indicator">⇅</span></th>' +
                                    categoryHeadersHtml +
                                '</tr>' +
                            '</thead>' +
                            '<tbody id="ba-band-modal-tbody"></tbody>' +
                        '</table>' +
                    '</div>' +
                '</div>' +
                '<div class="ba-band-modal-footer">' +
                    '<span id="ba-band-modal-count" style="font-size:12px; color:#64748b;">Showing ' + bandStudents.length + ' of ' + bandStudents.length + ' students</span>' +
                    '<button type="button" class="ba-modal-btn-footer-close ba-band-modal-close-btn">Close</button>' +
                '</div>' +
            '</div>';

            document.body.appendChild(modal);

            function getModalSortValue(student, col) {
                if (col === 'name') {
                    return (student.name || '').toLowerCase();
                }
                if (col === 'grade') {
                    if (perfMode === 'percentile') {
                        var p = (percentileValues && percentileValues[student._origIdx] !== undefined) ? percentileValues[student._origIdx] : -1;
                        return p;
                    }
                    return parseFloat(student.grade) || 0;
                }
                for (var i = 0; i < performanceColumns.length; i++) {
                    if (performanceColumns[i].key === col) {
                        var val = getCategoryValue(student, performanceColumns[i]);
                        if (val === null || val === undefined || val === '') return -999;
                        var num = parseFloat(val);
                        return isNaN(num) ? -999 : num;
                    }
                }
                return 0;
            }

            function getFilteredAndSortedStudents() {
                return bandStudents.filter(function(s) {
                    if (modalSearch) {
                        var name = (s.name || '').toLowerCase();
                        var id = (s.id || '').toLowerCase();
                        if (name.indexOf(modalSearch) === -1 && id.indexOf(modalSearch) === -1) {
                            return false;
                        }
                    }

                    return true;
                }).sort(function(a, b) {
                    var valA = getModalSortValue(a, modalSortCol);
                    var valB = getModalSortValue(b, modalSortCol);
                    var res = 0;
                    if (typeof valA === 'string' && typeof valB === 'string') {
                        res = valA.localeCompare(valB);
                    } else {
                        res = valA - valB;
                    }
                    if (res !== 0) {
                        return modalSortDir === 'asc' ? res : -res;
                    }
                    return (a.name || '').localeCompare(b.name || '');
                });
            }

            var tbody = modal.querySelector('#ba-band-modal-tbody');
            var countEl = modal.querySelector('#ba-band-modal-count');

            function renderModalRows() {
                var list = getFilteredAndSortedStudents();
                var rowsHtml = '';
                list.forEach(function(s) {
                    var avatarHtml = '';
                    var studentInitials = escapeHtml(s.initials || (s.name ? s.name.charAt(0).toUpperCase() : '?'));
                    if (s.profileimageurl) {
                        avatarHtml = '<div class="ba-avatar-wrap">' +
                            '<img src="' + escapeHtml(s.profileimageurl) + '" class="ba-student-avatar" alt="' + escapeHtml(s.name) + '" onerror="this.onerror=null;this.parentElement.innerHTML=\'<span class=\\\'ba-avatar-initials\\\'>' + studentInitials + '</span>\';">' +
                        '</div>';
                    } else {
                        avatarHtml = '<div class="ba-avatar-wrap"><span class="ba-avatar-initials">' + studentInitials + '</span></div>';
                    }

                    rowsHtml += '<tr>' +
                        '<td class="ba-performance-student-cell ba-modal-sticky-col">' +
                            '<div class="ba-student-cell">' +
                                avatarHtml +
                                '<div class="ba-student-info">' +
                                    '<span class="sname">' + escapeHtml(s.name) + '</span>' +
                                    '<span class="sid">' + escapeHtml(s.id) + '</span>' +
                                '</div>' +
                            '</div>' +
                        '</td>' +
                        '<td><b>' + getOverallDisplayValue(s, percentileValues) + '</b></td>' +
                        performanceColumns.map(function(column) {
                            return '<td class="ba-performance-group-module">' + formatPerformanceCell(s, column) + '</td>';
                        }).join('') +
                        '</tr>';
                });

                if (!rowsHtml) {
                    rowsHtml = '<tr><td colspan="' + (2 + performanceColumns.length) + '" style="text-align:center; padding:32px; color:#64748b;">No students found matching current filter.</td></tr>';
                }

                if (tbody) {
                    tbody.innerHTML = rowsHtml;
                }

                if (countEl) {
                    var isFiltered = Boolean(modalSearch);
                    countEl.textContent = isFiltered
                        ? ('Showing ' + list.length + ' of ' + bandStudents.length + ' students (filtered)')
                        : ('Showing ' + bandStudents.length + ' of ' + bandStudents.length + ' students');
                }

                // Update sort indicators
                var ths = modal.querySelectorAll('th.ba-modal-th-sortable');
                ths.forEach(function(th) {
                    var col = th.getAttribute('data-modal-col');
                    var ind = th.querySelector('.ba-sort-indicator');
                    if (col === modalSortCol) {
                        th.classList.add('active');
                        if (ind) ind.textContent = modalSortDir === 'asc' ? '▲' : '▼';
                    } else {
                        th.classList.remove('active');
                        if (ind) ind.textContent = '⇅';
                    }
                });
            }

            // Initial render of rows
            renderModalRows();

            // Column Header Sorting listeners
            var headerThs = modal.querySelectorAll('th.ba-modal-th-sortable');
            headerThs.forEach(function(th) {
                th.addEventListener('click', function() {
                    var col = this.getAttribute('data-modal-col');
                    if (modalSortCol === col) {
                        modalSortDir = (modalSortDir === 'asc' ? 'desc' : 'asc');
                    } else {
                        modalSortCol = col;
                        modalSortDir = (col === 'name' ? 'asc' : 'desc');
                    }
                    renderModalRows();
                });
            });

            // Filter & Search listeners
            var searchInput = modal.querySelector('#ba-band-modal-search');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    modalSearch = (this.value || '').trim().toLowerCase();
                    renderModalRows();
                });
            }

            

            // Close handling
            function closeModal() {
                modal.remove();
                document.removeEventListener('keydown', handleKey);
            }
            function handleKey(e) {
                if (e.key === 'Escape') closeModal();
            }
            document.addEventListener('keydown', handleKey);

            modal.addEventListener('click', function(e) {
                if (e.target === modal || e.target.closest('.ba-band-modal-close') || e.target.closest('.ba-band-modal-close-btn')) {
                    closeModal();
                }
            });

            // Export CSV for this modal
            var exportBtn = modal.querySelector('#ba-band-modal-export');
            if (exportBtn) {
                exportBtn.addEventListener('click', function() {
                    var csvHeaders = ['Student Name', 'Student ID', perfMode === 'grade' ? 'Grade' : 'Percentile'];
                    performanceColumns.forEach(function(column) {
                        var suffix = usesFixedGrade(column) || perfMode === 'grade' ? ' Grade' : ' Completion';
                        csvHeaders.push(column.label + suffix);
                    });

                    var csv = [csvHeaders.map(function(value) { return '"' + value.replace(/"/g, '""') + '"'; }).join(',')];
                    var exportList = getFilteredAndSortedStudents();
                    exportList.forEach(function(s) {
                        var name = (s.name || '').replace(/"/g, '""');
                        var id = (s.id || '').replace(/"/g, '""');
                        var grade = getOverallDisplayValue(s, percentileValues).replace(/&mdash;/g, '-');
                        var row = [name, id, grade];
                        performanceColumns.forEach(function(column) { row.push(csvPerformanceValue(s, column)); });
                        csv.push(row.map(function(value) { return '"' + String(value).replace(/"/g, '""') + '"'; }).join(','));
                    });
                    var blob = new Blob([csv.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
                    var link = document.createElement('a');
                    var url = URL.createObjectURL(blob);
                    link.setAttribute('href', url);
                    link.setAttribute('download', 'batch_' + bandKey + '_performers.csv');
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                });
            }
        }

        function renderPerformance() {
            if (!studentsData || studentsData.length === 0) {
                if (bandrow) {
                    bandrow.innerHTML =
                        '<div class="band top" style="cursor:default;">' +
                            '<div class="pct">0</div><div class="lbl">Top Performers</div><div class="cnt">grade &gt; 70</div>' +
                        '</div>' +
                        '<div class="band mid" style="cursor:default;">' +
                            '<div class="pct">0</div><div class="lbl">Middle Performers</div><div class="cnt">grade 40–70</div>' +
                        '</div>' +
                        '<div class="band bot" style="cursor:default;">' +
                            '<div class="pct">0</div><div class="lbl">Low Performers</div><div class="cnt">grade &lt; 40</div>' +
                        '</div>';
                }
                if (stuBody) {
                    stuBody.innerHTML = '<tr><td colspan="' + getVisiblePerformanceColumnCount() + '" style="text-align:center; padding:32px; color:#64748b;">No students found for this batch.</td></tr>';
                }
                if (paginationInfo) paginationInfo.textContent = 'No students to display';
                if (paginationBtns) paginationBtns.innerHTML = '';
                return;
            }

            // Always compute bands across the entire dataset for accurate KPI numbers
            var allBands = computeBands(studentsData, perfMode);
            var percentileValues = getPercentileValues(studentsData);
            updateOverallHeading();

            var counts = { top: 0, mid: 0, bot: 0 };
            studentsData.forEach(function(s, idx) {
                s._band = allBands[idx] || 'mid';
                if (counts[s._band] !== undefined) counts[s._band]++;
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
                    '<div class="band top" data-band="top" role="button" tabindex="0" title="Click to view Top Performers">' +
                        '<div class="pct">' + counts.top + '</div>' +
                        '<div class="lbl">' + labels.top[0] + '</div>' +
                        '<div class="cnt">' + labels.top[1] + '</div>' +
                    '</div>' +
                    '<div class="band mid" data-band="mid" role="button" tabindex="0" title="Click to view Middle Performers">' +
                        '<div class="pct">' + counts.mid + '</div>' +
                        '<div class="lbl">' + labels.mid[0] + '</div>' +
                        '<div class="cnt">' + labels.mid[1] + '</div>' +
                    '</div>' +
                    '<div class="band bot" data-band="bot" role="button" tabindex="0" title="Click to view Low Performers">' +
                        '<div class="pct">' + counts.bot + '</div>' +
                        '<div class="lbl">' + labels.bot[0] + '</div>' +
                        '<div class="cnt">' + labels.bot[1] + '</div>' +
                    '</div>';

                // Attach click and keyboard listeners to KPI cards to open modal window
                var cards = bandrow.querySelectorAll('.band[data-band]');
                cards.forEach(function(card) {
                    card.addEventListener('click', function() {
                        var b = this.getAttribute('data-band');
                        openBandDetailModal(b);
                    });
                    card.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            var b = this.getAttribute('data-band');
                            openBandDetailModal(b);
                        }
                    });
                });
            }

            var totalItems = studentsData.length;

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

            pagedStudents.forEach(function(s) {
                var b = s._band || 'mid';

                var meritHtml = '';
                var spotCount = Number(s.spot_count || 0);
                if (spotCount <= 0 && s.spot) spotCount = 1;
                if (spotCount > 0) {
                    var stars = '';
                    for (var k = 0; k < spotCount; k++) stars += '★';
                    meritHtml += '<span class="m m-spot">' + stars + ' Spot</span> ';
                }
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
                    '<td><b>' + getOverallDisplayValue(s, percentileValues) + '</b></td>' +
                    performanceColumns.map(function(column) {
                        return '<td class="ba-performance-group-module">' + formatPerformanceCell(s, column) + '</td>';
                    }).join('') +
                    '<td><span class="merit">' + meritHtml + '</span></td>' +
                    '</tr>';
            });

            if (pagedStudents.length === 0) {
                html = '<tr><td colspan="' + getVisiblePerformanceColumnCount() + '" style="text-align:center; padding:32px; color:#64748b;">No students found for this filter.</td></tr>';
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
                var feedbackButton = event.target.closest('[data-feedback-userid]');
                if (feedbackButton) {
                    event.preventDefault();
                    var fbUserId = Number(feedbackButton.getAttribute('data-feedback-userid'));
                    var fbColKey = feedbackButton.getAttribute('data-feedback-colkey');
                    var fbColLabel = feedbackButton.getAttribute('data-feedback-collabel') || 'Feedback';
                    var fbStudent = studentsData.find(function(student) { return Number(student.userid) === fbUserId; });
                    if (fbStudent) openFeedbackModal(fbStudent, fbColKey, fbColLabel);
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
                performanceColumns.forEach(function(column) {
                    var suffix = usesFixedGrade(column) || perfMode === 'grade' ? ' Grade' : ' Completion';
                    csvHeaders.push(column.label + suffix);
                });
                csvHeaders.push('Merit');
                var csv = [csvHeaders.map(function(value) { return '"' + value.replace(/"/g, '""') + '"'; }).join(',')];
                var percentileValues = getPercentileValues(studentsData);

                var exportStudents = selectedBand
                    ? studentsData.filter(function(s) { return s._band === selectedBand; })
                    : studentsData;

                exportStudents.forEach(function(s) {
                    var rawBand = (s._band || 'mid').toLowerCase();
                    var band = (rawBand === 'top') ? 'top' : ((rawBand === 'bot' || rawBand === 'bottom' || rawBand === 'low') ? 'low' : 'mid');
                    var name = (s.name || '').replace(/"/g, '""');
                    var id = (s.id || '').replace(/"/g, '""');
                    var grade = getOverallDisplayValue(s, percentileValues).replace(/&mdash;/g, '-');
                    var merit = (s.merit_text || '').replace(/"/g, '""');
                    var row = [band, name, id, grade];
                    performanceColumns.forEach(function(column) { row.push(csvPerformanceValue(s, column)); });
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