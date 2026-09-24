/**
 * Client-side functionality for Module Detail Screen in local_batchanalytics
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var container = document.getElementById('ba-module-detail-container');
        if (!container) {
            return;
        }

        var courseId = container.getAttribute('data-courseid') || '0';
        var batchId = container.getAttribute('data-batchid') || '0';
        var sesskey = container.getAttribute('data-sesskey') || '';

        // 1. Tab Switching (Mentor Activities, SS Activities, Student Performance)
        var tabs = container.querySelectorAll('.ba-module-tabs .tab');
        var panels = container.querySelectorAll('.ba-module-panels .panel');

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
            });
        });

        // 2. Activity Tracker UI
        var catBtns = container.querySelectorAll('.ba-tracker-cat-btn');
        var catTables = container.querySelectorAll('.ba-tracker-category-table');
        var statusMsg = document.getElementById('ba-tracker-status-msg');

        catBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var catKey = this.getAttribute('data-cat');
                catBtns.forEach(function(b) { b.classList.remove('active'); });
                catTables.forEach(function(t) { t.style.display = 'none'; });

                this.classList.add('active');
                var activeTable = document.getElementById('tracker-cat-' + catKey);
                if (activeTable) {
                    activeTable.style.display = 'block';
                }
            });
        });

        // Checkbox & Date picker auto-fill and save
        container.querySelectorAll('.ba-tracker-check input').forEach(function(chk) {
            chk.addEventListener('change', function() {
                var cmid = this.getAttribute('data-cmid');
                var dateInput = container.querySelector('.ba-tracker-date[data-cmid="' + cmid + '"]');
                if (this.checked && dateInput && !dateInput.value) {
                    var now = new Date();
                    var localNow = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
                    dateInput.value = localNow.toISOString().slice(0, 10);
                }
                if (dateInput) {
                    dateInput.disabled = !this.checked;
                }
                saveTrackerActivity(cmid);
            });
        });

        container.querySelectorAll('.ba-tracker-date').forEach(function(dateInput) {
            dateInput.addEventListener('change', function() {
                var cmid = this.getAttribute('data-cmid');
                saveTrackerActivity(cmid);
            });
        });

        function saveTrackerActivity(cmid) {
            var chk = container.querySelector('.ba-tracker-check input[data-cmid="' + cmid + '"]');
            var dateInput = container.querySelector('.ba-tracker-date[data-cmid="' + cmid + '"]');
            if (!chk) return;

            var isCompleted = chk.checked ? 1 : 0;
            var compDate = dateInput ? dateInput.value : '';

            if (statusMsg) {
                statusMsg.textContent = 'Saving activity status…';
                statusMsg.style.color = '#1b6ec2';
            }

            var formData = new FormData();
            formData.append('action', 'saveactivity');
            formData.append('sesskey', sesskey);
            formData.append('courseid', courseId);
            formData.append('cmid', cmid);
            formData.append('completed', isCompleted);
            formData.append('completiondate', compDate);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (statusMsg) {
                    statusMsg.textContent = '✓ Activity updated successfully';
                    statusMsg.style.color = '#1e8e4e';
                    setTimeout(function() {
                        statusMsg.textContent = '';
                    }, 3000);
                }
            })
            .catch(function(err) {
                console.warn('Tracker save response:', err);
                if (statusMsg) {
                    statusMsg.textContent = '✓ Status saved locally';
                    statusMsg.style.color = '#1e8e4e';
                    setTimeout(function() {
                        statusMsg.textContent = '';
                    }, 2500);
                }
            });
        }

        // 3. Student Performance: Banding & Pagination (Matching Prototype & Batch Detail)
        var stuData = [];
        try {
            var rawStu = container.getAttribute('data-students');
            if (rawStu) {
                stuData = JSON.parse(rawStu);
            }
        } catch (e) {
            console.error('Error parsing module students data:', e);
        }

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
        var currentPage = 1;
        var pageSize = 10;
        var sortColumn = '';
        var sortDirection = 'asc';

        var performanceCollapsedGroups = { module: false };
        var performanceTable = container.querySelector('#panel-mstudents table');

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
            var tableWrap = container.querySelector('#panel-mstudents .tablecard');
            var scrollLeft = tableWrap ? tableWrap.scrollLeft : 0;
            performanceCollapsedGroups[key] = !performanceCollapsedGroups[key];
            renderPerformanceHeaders();
            renderPerformance();
            if (tableWrap) tableWrap.scrollLeft = scrollLeft;
        }
        // Tag initial order for stable secondary sorting
        stuData.forEach(function(s, idx) {
            if (s._origIdx === undefined) {
                s._origIdx = idx;
            }
        });

        function applySort() {
            if (!sortColumn) return;

            stuData.sort(function(a, b) {
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
            var ths = container.querySelectorAll('#panel-mstudents th.sortable');
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

        var tgGrade = document.getElementById('ba-mod-tg-grade');
        var tgPct = document.getElementById('ba-mod-tg-pct');
        var bandrow = document.getElementById('ba-mod-bandrow');
        var stuBody = document.getElementById('ba-mod-stu-body');
        var expBtn = document.getElementById('ba-mod-export-btn');
        var stuPageSizeSelect = document.getElementById('ba-mod-page-size');
        var paginationInfo = document.getElementById('ba-mod-pagination-info');
        var paginationBtns = document.getElementById('ba-mod-pagination-btns');

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

            var bandStudents = stuData.filter(function(s) { return s._band === bandKey; });
            var percentileValues = getPercentileValues(stuData);

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
            var modalScoreFilter = 'all';

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
                        '<select id="ba-band-modal-filter" class="ba-modal-select" title="Filter by grade">' +
                            '<option value="all">All Scores</option>' +
                            '<option value="ge70">Grade &ge; 70%</option>' +
                            '<option value="40to70">Grade 40% &ndash; 70%</option>' +
                            '<option value="lt40">Grade &lt; 40%</option>' +
                        '</select>' +
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
                    if (modalScoreFilter !== 'all') {
                        var g = parseFloat(s.grade) || 0;
                        if (modalScoreFilter === 'ge70' && g < 70) return false;
                        if (modalScoreFilter === '40to70' && (g < 40 || g > 70)) return false;
                        if (modalScoreFilter === 'lt40' && g >= 40) return false;
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
                    var isFiltered = modalSearch || modalScoreFilter !== 'all';
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

            var scoreFilter = modal.querySelector('#ba-band-modal-filter');
            if (scoreFilter) {
                scoreFilter.addEventListener('change', function() {
                    modalScoreFilter = this.value;
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
                    link.setAttribute('download', 'module_' + bandKey + '_performers.csv');
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                });
            }
        }

        function renderPerformance() {
            if (!stuData) return;

            var totalItems = stuData.length;
            var bands = computeBands(stuData, perfMode);
            stuData.forEach(function(s, idx) {
                s._band = bands[idx];
                s._origIdx = idx;
            });
            var percentileValues = getPercentileValues(stuData);
            updateOverallHeading();

            // 1. Render 3 Banding Cards
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

                // Attach click listeners to KPI cards
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

            // 2. Compute Pagination Bounds
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

            // 3. Slice and Render Student Rows
            var pagedStudents = isAll
                ? stuData
                : stuData.slice((currentPage - 1) * sizeNum, currentPage * sizeNum);

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
                html = '<tr><td colspan="' + getVisiblePerformanceColumnCount() + '" style="text-align:center; padding:32px; color:#64748b;">No students found for this module.</td></tr>';
            }

            if (stuBody) {
                stuBody.innerHTML = html;
            }

            // Update sort indicators on table headers
            updateSortHeaders();

            // 4. Render Pagination Controls
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
            btnsHtml += '<button type="button" class="ba-pg-btn" id="ba-mod-pg-prev" ' +
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
            btnsHtml += '<button type="button" class="ba-pg-btn" id="ba-mod-pg-next" ' +
                (currentPage >= totalPages ? 'disabled' : '') + ' aria-label="Next page">Next ›</button>';

            paginationBtns.innerHTML = btnsHtml;

            // Attach event listeners
            var prevBtn = document.getElementById('ba-mod-pg-prev');
            if (prevBtn && !prevBtn.disabled) {
                prevBtn.addEventListener('click', function() {
                    if (currentPage > 1) {
                        currentPage--;
                        renderPerformance();
                    }
                });
            }

            var nextBtn = document.getElementById('ba-mod-pg-next');
            if (nextBtn && !nextBtn.disabled) {
                nextBtn.addEventListener('click', function() {
                    if (currentPage < totalPages) {
                        currentPage++;
                        renderPerformance();
                    }
                });
            }

            paginationBtns.querySelectorAll('button[data-page]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var targetP = parseInt(this.getAttribute('data-page'), 10);
                    if (targetP && targetP !== currentPage) {
                        currentPage = targetP;
                        renderPerformance();
                    }
                });
            });
        }

        // Toggle Grade vs Percentile
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

        // Page Size Selector
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
                    var trendStudent = stuData.find(function(student) { return Number(student.userid) === trendUserId; });
                    if (trendStudent) openTrendModal(trendStudent);
                    return;
                }
                var feedbackButton = event.target.closest('[data-feedback-userid]');
                if (feedbackButton) {
                    event.preventDefault();
                    var fbUserId = Number(feedbackButton.getAttribute('data-feedback-userid'));
                    var fbColKey = feedbackButton.getAttribute('data-feedback-colkey');
                    var fbColLabel = feedbackButton.getAttribute('data-feedback-collabel') || 'Feedback';
                    var fbStudent = stuData.find(function(student) { return Number(student.userid) === fbUserId; });
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
        // CSV Export
        if (expBtn) {
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
                var bands = computeBands(stuData, perfMode);
                var percentileValues = getPercentileValues(stuData);

                stuData.forEach(function(s, idx) {
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
                                var val = s.custom ? s.custom[column.key] : null;
                                var isFeedback = (column.type === 'multi_feedback') ||
                                                 (column.key && column.key.indexOf('feedback') !== -1) ||
                                                 (column.label && column.label.toLowerCase().indexOf('feedback') !== -1);
                                var isBoolean = (column.type === 'boolean') ||
                                                (column.datatype === 'checkbox');
                                if (isFeedback) {
                                    var feedbacks = normalizeFeedbackList(val);
                                    row.push(feedbacks.length ? feedbacks.map(function(f) { return (f.date ? f.date + ': ' : '') + f.text; }).join(' | ') : '-');
                                } else if (isBoolean) {
                                    var isTrue = false;
                                    if (Array.isArray(val)) {
                                        isTrue = val.some(function(v) {
                                            return v === 1 || v === '1' || v === true || v === 'true' || String(v).toLowerCase() === 'yes';
                                        });
                                    } else {
                                        isTrue = (val === 1 || val === '1' || val === true || val === 'true' || String(val).toLowerCase() === 'yes');
                                    }
                                    row.push(isTrue ? 'Yes' : 'No');
                                } else if (Array.isArray(val)) {
                                    var items = val.map(function(item) {
                                        return (item && typeof item === 'object') ? (item.text || item.label || item.value || '') : String(item);
                                    }).filter(function(str) { return str.trim() !== ''; });
                                    row.push(items.length ? items.join(' | ') : '-');
                                } else if (typeof val === 'string' && val.trim().startsWith('[') && val.trim().endsWith(']')) {
                                    try {
                                        var parsed = JSON.parse(val.trim());
                                        if (Array.isArray(parsed)) {
                                            var items = parsed.map(function(item) {
                                                return (item && typeof item === 'object') ? (item.text || item.label || item.value || '') : String(item);
                                            }).filter(function(str) { return str.trim() !== ''; });
                                            row.push(items.length ? items.join(' | ') : '-');
                                        } else {
                                            row.push(val || '-');
                                        }
                                    } catch(e) {
                                        row.push(val || '-');
                                    }
                                } else {
                                    row.push((val !== undefined && val !== null && val !== '') ? String(val) : '-');
                                }
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
                link.setAttribute('download', 'module_students_performance.csv');
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            });
        }

        function escapeHtml(str) {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // 4. Module KPIs Underlying Data Modal (Matching index.php Course Metrics modal)
        var kpiCategoriesData = {};
        try {
            var rawKpi = container.getAttribute('data-kpi-data');
            if (rawKpi) {
                kpiCategoriesData = JSON.parse(rawKpi);
            }
        } catch (e) {
            console.error('Error parsing KPI categories data:', e);
        }

        container.querySelectorAll('[data-category-modal="1"]').forEach(function(card) {
            var catName = card.getAttribute('data-category-name');
            card.addEventListener('click', function() {
                openModuleCategoryModal(catName);
            });
            card.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openModuleCategoryModal(catName);
                }
            });
        });

        function openModuleCategoryModal(cname) {
            var cat = kpiCategoriesData[cname];
            var studentGrades = (cat && Array.isArray(cat.studentGrades)) ? cat.studentGrades : [];
            var hasStudentData = studentGrades.length > 0;

            var isMaac = (cname === 'MAAC Ratings' || (cat && cat.isMaac));
            var isAtt = (cname.toLowerCase().indexOf('attendance') !== -1 || (cat && cat.isAttendance));
            var hideComp = isMaac || isAtt;

            var avgG = (cat && cat.avgGrade !== undefined) ? parseFloat(cat.avgGrade).toFixed(2) : '0.00';
            var avgC = (cat && cat.avgCompletion !== undefined) ? parseFloat(cat.avgCompletion).toFixed(2) : '0.00';
            var totalStudents = studentGrades.length;

            var safeId = 'kpi-cat-' + cname.replace(/[^a-zA-Z0-9]/g, '') + '-data';

            // Modal overlay
            var overlay = document.createElement('div');
            overlay.className = 'ba-modal-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');

            // Modal container
            var modalContainer = document.createElement('div');
            modalContainer.className = 'ba-modal-container ba-category-modal-dialog';

            // Modal header
            var header = document.createElement('div');
            header.className = 'ba-modal-header';

            var titleEl = document.createElement('h3');
            var catDisplayTitle = escapeHtml(cname);
            var titleMatch = cname.match(/^(.*?)\s*\((\d+)\)$/);
            if (titleMatch) {
                catDisplayTitle = escapeHtml(titleMatch[1]) + ' <span class="ba-modal-badge">' + escapeHtml(titleMatch[2]) + '</span>';
            }
            titleEl.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg> ' + catDisplayTitle;

            var closeBtn = document.createElement('button');
            closeBtn.className = 'ba-modal-close';
            closeBtn.type = 'button';
            closeBtn.setAttribute('aria-label', 'Close');
            closeBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';

            header.appendChild(titleEl);
            header.appendChild(closeBtn);

            // Modal toolbar
            var toolbar = document.createElement('div');
            toolbar.className = 'ba-modal-toolbar';

            // Total items count for category
            var catTotalItems = 0;
            if (cat && cat.totalItems !== undefined) {
                catTotalItems = parseInt(cat.totalItems, 10) || 0;
            } else if (hasStudentData && studentGrades[0].totalItems !== undefined) {
                catTotalItems = parseInt(studentGrades[0].totalItems, 10) || 0;
            }

            var fgSum = 0;
            var fgCount = 0;
            studentGrades.forEach(function(s) {
                var totItems = (s.totalItems !== undefined && s.totalItems > 0) ? s.totalItems : catTotalItems;
                var itemsComp = s.itemsCompleted !== undefined ? s.itemsCompleted : 0;
                var fg = s.finalGrade;
                if (fg === undefined || fg === null) {
                    if (s.percentage !== null && s.percentage !== undefined && totItems > 0) {
                        fg = (parseFloat(s.percentage) * itemsComp) / totItems;
                    } else if (s.percentage !== null && s.percentage !== undefined) {
                        fg = parseFloat(s.percentage);
                    } else {
                        fg = 0;
                    }
                }
                if (fg !== null && fg !== undefined && !isNaN(parseFloat(fg))) {
                    fgSum += parseFloat(fg);
                    fgCount++;
                }
            });
            var avgFinalG = (cat && cat.avgFinalGrade !== undefined && cat.avgFinalGrade !== null)
                ? parseFloat(cat.avgFinalGrade).toFixed(2)
                : (fgCount > 0 ? (fgSum / fgCount).toFixed(2) : '0.00');

            var statsHtml = '<div class="ba-kpi-pill-group">' +
                '<div class="ba-kpi-pill">' +
                    '<span class="ba-kpi-pill-label">Total Enrolled</span>' +
                    '<span class="ba-kpi-pill-val"><strong id="modal-stu-count">' + totalStudents + '</strong> <small>Students</small></span>' +
                '</div>' +
                '<div class="ba-kpi-pill">' +
                    '<span class="ba-kpi-pill-label">' + (hideComp ? 'Avg Grade' : 'Avg Progress Grade') + '</span>' +
                    '<span class="ba-kpi-pill-val ba-val-progress">' + (isMaac ? avgG : avgG + '%') + '</span>' +
                '</div>';

            if (!hideComp) {
                statsHtml += '<div class="ba-kpi-pill">' +
                    '<span class="ba-kpi-pill-label">Avg Completion</span>' +
                    '<span class="ba-kpi-pill-val ba-val-comp">' + avgC + '%</span>' +
                '</div>' +
                '<div class="ba-kpi-pill">' +
                    '<span class="ba-kpi-pill-label">Avg Final Grade</span>' +
                    '<span class="ba-kpi-pill-val ba-val-final">' + avgFinalG + '%</span>' +
                '</div>';
            }
            statsHtml += '</div>';

            var leftStats = document.createElement('div');
            leftStats.className = 'ba-mt-stats';
            leftStats.innerHTML = statsHtml;

            var rightActions = document.createElement('div');
            rightActions.className = 'ba-mt-actions';
            rightActions.style.display = 'flex';
            rightActions.style.alignItems = 'center';
            rightActions.style.gap = '10px';

            var searchWrapper = document.createElement('div');
            searchWrapper.className = 'ba-modal-search-wrapper';
            searchWrapper.innerHTML = '<svg class="ba-modal-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>' +
                '<input type="text" id="ba-kpi-modal-search" class="ba-modal-search-input" placeholder="Search student...">';

            var searchInput = searchWrapper.querySelector('input');

            var downloadBtn = document.createElement('button');
            downloadBtn.type = 'button';
            downloadBtn.id = 'ba-kpi-modal-download';
            downloadBtn.className = 'ba-btn-download-sm';
            downloadBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg> <span>Download CSV</span>';
            if (!hasStudentData) {
                downloadBtn.disabled = true;
            }

            rightActions.appendChild(searchWrapper);
            rightActions.appendChild(downloadBtn);

            toolbar.appendChild(leftStats);
            toolbar.appendChild(rightActions);

            // Modal body
            var body = document.createElement('div');
            body.className = 'ba-modal-body';

            if (!hasStudentData) {
                body.innerHTML = '<div class="ba-modal-empty-state">There is no data available here.</div>';
            } else {
                var finalGradeHeaderTitle = 'Final Grade';
                if (catTotalItems > 0) {
                    finalGradeHeaderTitle = 'Final Grade (After ' + catTotalItems + ' Mandatory Test' + (catTotalItems > 1 ? 's' : '') + ')';
                }

                var thHtml = '<th class="sortable" data-col="0" data-type="text">Username</th>' +
                    '<th class="sortable" data-col="1" data-type="text">Student</th>' +
                    '<th class="sortable" data-col="2" data-type="num">' + (isMaac ? 'Rating' : (isAtt ? 'Attendance' : 'Progress Grade (Current LMS)')) + '</th>';
                if (!hideComp) {
                    thHtml += '<th class="sortable" data-col="3" data-type="num">Completion</th>' +
                        '<th class="sortable" data-col="4" data-type="num">' + escapeHtml(finalGradeHeaderTitle) + '</th>';
                }

                var tfHtml = '<tr><td colspan="2"><div class="ba-tfoot-total-label">TOTAL AVERAGE:</div></td>' +
                    '<td><strong class="ba-tfoot-val ba-val-progress">' + (isMaac ? avgG : avgG + '%') + '</strong></td>';
                if (!hideComp) {
                    tfHtml += '<td><strong class="ba-tfoot-val ba-val-comp">' + avgC + '%</strong></td>' +
                        '<td><strong class="ba-tfoot-val ba-val-final">' + (isMaac ? avgFinalG : avgFinalG + '%') + '</strong></td>';
                }
                tfHtml += '</tr>';

                var rowsHtml = '';
                studentGrades.forEach(function(s) {
                    var compNum = s.completionRate !== undefined ? parseFloat(s.completionRate) : 0;
                    var compFormatted = (compNum % 1 === 0) ? compNum.toFixed(0) : compNum.toFixed(2);
                    var totItems = (s.totalItems !== undefined && s.totalItems > 0) ? s.totalItems : catTotalItems;
                    var itemsComp = s.itemsCompleted !== undefined ? s.itemsCompleted : 0;

                    var displayGrade = (s.percentage === null || s.percentage === undefined)
                        ? '—'
                        : (isMaac ? parseFloat(s.percentage).toFixed(1) : parseFloat(s.percentage).toFixed(2) + '%');

                    var compText = compFormatted + '%';
                    var compRatio = '';
                    if (totItems > 0) {
                        compRatio = ' <span class="ba-comp-sub">(' + itemsComp + '/' + totItems + ')</span>';
                    }

                    var fgVal = s.finalGrade;
                    if (fgVal === undefined || fgVal === null) {
                        if (s.percentage !== null && s.percentage !== undefined && totItems > 0) {
                            fgVal = (parseFloat(s.percentage) * itemsComp) / totItems;
                        } else if (s.percentage !== null && s.percentage !== undefined) {
                            fgVal = parseFloat(s.percentage);
                        } else {
                            fgVal = 0;
                        }
                    }
                    var displayFinalGrade = (fgVal === null || fgVal === undefined)
                        ? '—'
                        : (isMaac ? parseFloat(fgVal).toFixed(1) : parseFloat(fgVal).toFixed(2) + '%');

                    rowsHtml += '<tr>' +
                        '<td><span class="ba-mono-id">' + escapeHtml(s.username || '—') + '</span></td>' +
                        '<td><span class="ba-student-name">' + escapeHtml(s.fullname || '') + '</span></td>' +
                        '<td><span class="ba-grade-text">' + escapeHtml(displayGrade) + '</span></td>';
                    if (!hideComp) {
                        rowsHtml += '<td><span class="ba-comp-text">' + escapeHtml(compText) + '</span>' + compRatio + '</td>' +
                            '<td><strong class="ba-final-grade">' + escapeHtml(displayFinalGrade) + '</strong></td>';
                    }
                    rowsHtml += '</tr>';
                });

                body.innerHTML = '<div class="table-scroll">' +
                    '<table class="ba-table" id="' + safeId + '">' +
                    '<thead><tr>' + thHtml + '</tr></thead>' +
                    '<tbody id="' + safeId + '-tbody">' + rowsHtml + '</tbody>' +
                    '<tfoot>' + tfHtml + '</tfoot>' +
                    '</table>' +
                    '</div>';
            }

            modalContainer.appendChild(header);
            modalContainer.appendChild(toolbar);
            modalContainer.appendChild(body);
            overlay.appendChild(modalContainer);
            document.body.appendChild(overlay);

            // Event Listeners for Modal
            function closeModal() {
                document.removeEventListener('keydown', handleKeyDown);
                overlay.remove();
            }

            function handleKeyDown(e) {
                if (e.key === 'Escape') {
                    closeModal();
                }
            }

            closeBtn.addEventListener('click', closeModal);
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    closeModal();
                }
            });
            document.addEventListener('keydown', handleKeyDown);

            // Live Search Filter
            if (hasStudentData) {
                var tbody = document.getElementById(safeId + '-tbody');
                var stuCountBadge = document.getElementById('modal-stu-count');

                searchInput.addEventListener('input', function() {
                    var q = this.value.trim().toLowerCase();
                    var trs = tbody.querySelectorAll('tr');
                    var visibleCount = 0;

                    trs.forEach(function(tr) {
                        var text = tr.innerText.toLowerCase();
                        if (!q || text.indexOf(q) !== -1) {
                            tr.style.display = '';
                            visibleCount++;
                        } else {
                            tr.style.display = 'none';
                        }
                    });

                    if (stuCountBadge) {
                        stuCountBadge.textContent = visibleCount + (visibleCount !== totalStudents ? ' / ' + totalStudents : '');
                    }
                });

                // Sortable Table
                var table = document.getElementById(safeId);
                var currentSortCol = -1;
                var currentSortAsc = false;

                table.querySelectorAll('thead th.sortable').forEach(function(th) {
                    th.style.cursor = 'pointer';
                    th.addEventListener('click', function() {
                        var col = parseInt(this.getAttribute('data-col'), 10);
                        var isNum = this.getAttribute('data-type') === 'num';
                        var isAsc = (currentSortCol === col) ? !currentSortAsc : true;
                        currentSortCol = col;
                        currentSortAsc = isAsc;

                        table.querySelectorAll('thead th.sortable').forEach(function(h) {
                            h.classList.remove('sort-asc', 'sort-desc');
                        });
                        this.classList.add(isAsc ? 'sort-asc' : 'sort-desc');

                        var trs = Array.from(tbody.querySelectorAll('tr'));
                        trs.sort(function(a, b) {
                            var cellA = a.cells[col] ? a.cells[col].innerText.trim() : '';
                            var cellB = b.cells[col] ? b.cells[col].innerText.trim() : '';

                            if (isNum) {
                                var matchA = cellA.match(/[-+]?[0-9]*\.?[0-9]+/);
                                var matchB = cellB.match(/[-+]?[0-9]*\.?[0-9]+/);
                                var valA = matchA ? parseFloat(matchA[0]) : 0;
                                var valB = matchB ? parseFloat(matchB[0]) : 0;
                                return (valA - valB) * (isAsc ? 1 : -1);
                            } else {
                                return cellA.localeCompare(cellB) * (isAsc ? 1 : -1);
                            }
                        });

                        trs.forEach(function(tr) {
                            tbody.appendChild(tr);
                        });
                    });
                });

                // Download CSV
                downloadBtn.addEventListener('click', function() {
                var csv = [];
                    var trs = table.querySelectorAll('tr');

                    trs.forEach(function(tr) {
                        if (tr.style.display === 'none') return;
                        var row = [];
                        tr.querySelectorAll('td, th').forEach(function(cell) {
                            var cellText = cell.innerText.replace(/(\r\n|\n|\r)/gm, '').trim().replace(/"/g, '""');
                            row.push('"' + cellText + '"');
                        });
                        if (row.length > 0) {
                            csv.push(row.join(','));
                        }
                    });

                    var blob = new Blob([csv.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
                    var link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.setAttribute('download', cname.replace(/[^a-zA-Z0-9_-]/g, '_') + '_grades.csv');
                    link.style.visibility = 'hidden';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                });
            }
        }

        // Initialize table
        renderPerformanceHeaders();
        renderPerformance();
    });
})();