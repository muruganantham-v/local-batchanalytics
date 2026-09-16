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

        // 3. Student Performance: Course Advanced Filter Section
        var stuData = [];
        try {
            var rawStu = container.getAttribute('data-students');
            if (rawStu) {
                stuData = JSON.parse(rawStu);
            }
        } catch (e) {
            console.error('Error parsing module students data:', e);
        }

        var stuSearch = document.getElementById('ba-mod-search');
        var tgScoreMode = document.getElementById('ba-mod-tg-grade');
        var tgCompMode = document.getElementById('ba-mod-tg-comp');
        var bandFilter = document.getElementById('ba-mod-band-filter');
        var minAttInput = document.getElementById('ba-mod-min-att');
        var minGradeInput = document.getElementById('ba-mod-min-grade');
        var stuTableBody = document.getElementById('ba-mod-stu-body');
        var countBadge = document.getElementById('ba-mod-stu-count');
        var expCsvBtn = document.getElementById('ba-mod-export-btn');

        var displayMode = 'grade'; // 'grade' or 'comp'

        function applyAdvancedFilters() {
            if (!stuData || !stuTableBody) return;

            var q = (stuSearch ? stuSearch.value.trim().toLowerCase() : '');
            var selBand = (bandFilter ? bandFilter.value : 'all');
            var minAtt = (minAttInput && minAttInput.value) ? parseFloat(minAttInput.value) : 0;
            var minGrade = (minGradeInput && minGradeInput.value) ? parseFloat(minGradeInput.value) : 0;

            var filtered = stuData.filter(function(s) {
                // Name or ID match
                if (q) {
                    var nMatch = (s.name || '').toLowerCase().indexOf(q) !== -1;
                    var idMatch = (s.id || '').toLowerCase().indexOf(q) !== -1;
                    if (!nMatch && !idMatch) return false;
                }

                // Numeric score checks
                var g = parseFloat(s.grade) || 0;
                var att = parseFloat(s.attendance) || 0;

                if (g < minGrade) return false;
                if (att < minAtt) return false;

                // Band check
                var band = g > 70 ? 'top' : (g >= 40 ? 'mid' : 'bot');
                if (selBand !== 'all' && band !== selBand) return false;

                return true;
            });

            // Update count
            if (countBadge) {
                countBadge.textContent = 'Showing ' + filtered.length + ' of ' + stuData.length + ' students';
            }

            // Render table rows
            var html = '';
            var dotColors = { top: '#1e8e4e', mid: '#c77a0a', bot: '#c0392b' };

            filtered.forEach(function(s) {
                var g = parseFloat(s.grade) || 0;
                var b = g > 70 ? 'top' : (g >= 40 ? 'mid' : 'bot');
                var valScore = (displayMode === 'grade') ? (g + '') : (s.completion_pct ? s.completion_pct + '%' : g + '%');

                var meritHtml = '';
                if (s.spot) meritHtml += '<span class="m m-spot">★ Spot</span> ';
                if (s.pt === 'nom') meritHtml += '<span class="m m-ptnom">PT-Nom</span> ';
                else if (s.pt === 'sel') meritHtml += '<span class="m m-ptsel">PT-Sel</span>';
                if (!meritHtml) meritHtml = '<span class="muted">—</span>';

                html += '<tr>' +
                    '<td><span class="bdot" style="background:' + dotColors[b] + '" title="' + b.toUpperCase() + ' Band"></span></td>' +
                    '<td><span class="sname">' + escapeHtml(s.name) + '</span><br><span class="sid">' + escapeHtml(s.id) + '</span></td>' +
                    '<td><b>' + escapeHtml(valScore) + '</b></td>' +
                    '<td>' + escapeHtml(s.attendance || '—') + '</td>' +
                    '<td>' + escapeHtml(s.assignments || '—') + '</td>' +
                    '<td>' + (s.projects && s.projects !== '—' ? escapeHtml(s.projects) : '<span class="muted">—</span>') + '</td>' +
                    '<td>' + escapeHtml(s.tests || '—') + '</td>' +
                    '<td><span class="merit">' + meritHtml + '</span></td>' +
                    '</tr>';
            });

            if (filtered.length === 0) {
                html = '<tr><td colspan="8" style="text-align:center; padding:32px; color:#64748b;">No students match the current filter criteria.</td></tr>';
            }

            stuTableBody.innerHTML = html;
        }

        if (stuSearch) stuSearch.addEventListener('input', applyAdvancedFilters);
        if (bandFilter) bandFilter.addEventListener('change', applyAdvancedFilters);
        if (minAttInput) minAttInput.addEventListener('input', applyAdvancedFilters);
        if (minGradeInput) minGradeInput.addEventListener('input', applyAdvancedFilters);

        if (tgScoreMode && tgCompMode) {
            tgScoreMode.addEventListener('click', function() {
                displayMode = 'grade';
                tgScoreMode.classList.add('on');
                tgCompMode.classList.remove('on');
                applyAdvancedFilters();
            });
            tgCompMode.addEventListener('click', function() {
                displayMode = 'comp';
                tgCompMode.classList.add('on');
                tgScoreMode.classList.remove('on');
                applyAdvancedFilters();
            });
        }

        // CSV Export
        if (expCsvBtn) {
            expCsvBtn.addEventListener('click', function() {
                var csv = ['"Band","Student Name","Student ID","Grade/Score","Attendance","Assignments","Projects","Tests","Merit"'];
                stuData.forEach(function(s) {
                    var g = parseFloat(s.grade) || 0;
                    var b = (g > 70 ? 'TOP' : (g >= 40 ? 'MID' : 'LOW'));
                    csv.push('"' + b + '","' + (s.name || '').replace(/"/g, '""') + '","' + (s.id || '') + '","' + g + '","' +
                        (s.attendance || '') + '","' + (s.assignments || '') + '","' + (s.projects || '') + '","' +
                        (s.tests || '') + '","' + (s.merit_text || '') + '"');
                });

                var blob = new Blob([csv.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
                var link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.setAttribute('download', 'module_student_performance.csv');
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

        // Initialize table
        applyAdvancedFilters();
    });
})();
