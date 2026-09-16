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

        // 2. Performance Banding Logic (Grade vs Percentile)
        var tgGrade = document.getElementById('tg-grade');
        var tgPct = document.getElementById('tg-pct');
        var bandrow = document.getElementById('ba-bandrow');
        var stuBody = document.getElementById('ba-stuBody');
        var expBtn = document.getElementById('ba-btn-export');

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
                return;
            }

            var bands = computeBands(studentsData, perfMode);
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

            var dotColors = {
                top: 'var(--green, #1e8e4e)',
                mid: 'var(--amber, #c77a0a)',
                bot: 'var(--red, #c0392b)'
            };

            if (stuBody) {
                var rows = stuBody.querySelectorAll('tr');
                rows.forEach(function(row, idx) {
                    var b = bands[idx] || 'mid';
                    var dot = row.querySelector('.bdot');
                    if (dot) {
                        dot.style.background = dotColors[b];
                        dot.setAttribute('title', b.toUpperCase() + ' Band');
                    }
                });
            }
        }

        if (tgGrade && tgPct) {
            tgGrade.addEventListener('click', function() {
                perfMode = 'grade';
                tgGrade.classList.add('on');
                tgPct.classList.remove('on');
                renderPerformance();
            });

            tgPct.addEventListener('click', function() {
                perfMode = 'percentile';
                tgPct.classList.add('on');
                tgGrade.classList.remove('on');
                renderPerformance();
            });
        }

        // Export Filtered Students to CSV
        if (expBtn && stuBody) {
            expBtn.addEventListener('click', function() {
                var csv = ['"Band","Student Name","Student ID","Grade","Attendance","Assignments","Projects","Tests","Merit"'];
                var bands = computeBands(studentsData, perfMode);

                studentsData.forEach(function(s, idx) {
                    var band = bands[idx] ? bands[idx].toUpperCase() : 'MID';
                    var name = (s.name || '').replace(/"/g, '""');
                    var id = (s.id || '').replace(/"/g, '""');
                    var grade = s.grade || '0';
                    var att = s.attendance || '0%';
                    var assign = s.assignments || '0%';
                    var proj = s.projects || '—';
                    var test = s.tests || '0%';
                    var merit = (s.merit_text || '').replace(/"/g, '""');

                    csv.push('"' + band + '","' + name + '","' + id + '","' + grade + '","' + att + '","' + assign + '","' + proj + '","' + test + '","' + merit + '"');
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

        if (noteBtn && noteText && noteList) {
            noteBtn.addEventListener('click', function() {
                var text = noteText.value.trim();
                if (!text) {
                    return;
                }

                var author = noteBtn.getAttribute('data-author') || 'Program Manager';
                var now = new Date();
                var formattedDate = now.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) +
                    ', ' + now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });

                var noteItem = document.createElement('div');
                noteItem.className = 'note-item';
                noteItem.innerHTML = '<div class="meta"><b>' + escapeHtml(author) + '</b> · ' + escapeHtml(formattedDate) + '</div>' +
                    '<div class="body">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div>';

                noteList.insertBefore(noteItem, noteList.firstChild);
                noteText.value = '';
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

        // Initial render
        renderPerformance();
    });
})();
