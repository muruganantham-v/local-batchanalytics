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
                    stuBody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:32px; color:#64748b;">No students found for this batch.</td></tr>';
                }
                if (paginationInfo) paginationInfo.textContent = 'No students to display';
                if (paginationBtns) paginationBtns.innerHTML = '';
                return;
            }

            var totalItems = studentsData.length;
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

                html += '<tr>' +
                    '<td><span class="bdot" style="background:' + dotColors[b] + '" title="' + b.toUpperCase() + ' Band"></span></td>' +
                    '<td><span class="sname">' + escapeHtml(s.name) + '</span><br><span class="sid">' + escapeHtml(s.id) + '</span></td>' +
                    '<td><b>' + escapeHtml(s.grade) + '</b></td>' +
                    '<td>' + escapeHtml(s.attendance || '—') + '</td>' +
                    '<td>' + escapeHtml(s.assignments || '—') + '</td>' +
                    '<td>' + (s.projects && s.projects !== '—' ? escapeHtml(s.projects) : '<span class="muted">—</span>') + '</td>' +
                    '<td>' + escapeHtml(s.tests || '—') + '</td>' +
                    '<td><span class="merit">' + meritHtml + '</span></td>' +
                    '</tr>';
            });

            if (pagedStudents.length === 0) {
                html = '<tr><td colspan="8" style="text-align:center; padding:32px; color:#64748b;">No students found for this batch.</td></tr>';
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

        // Student Performance Table Header Sorting
        var sortHeaders = container.querySelectorAll('#panel-students th.sortable');
        sortHeaders.forEach(function(th) {
            th.addEventListener('click', function() {
                var col = this.getAttribute('data-sort');
                if (!col) return;

                if (sortColumn === col) {
                    sortDirection = (sortDirection === 'asc') ? 'desc' : 'asc';
                } else {
                    sortColumn = col;
                    // Names default to A-Z (asc), scores/percentages default to highest first (desc)
                    if (col === 'student' || col === 'name') {
                        sortDirection = 'asc';
                    } else {
                        sortDirection = 'desc';
                    }
                }

                applySort();
                currentPage = 1;
                renderPerformance();
            });
        });

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

        // Initial render
        renderPerformance();
    });
})();
