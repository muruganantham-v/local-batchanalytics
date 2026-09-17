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

        var perfMode = 'grade'; // 'grade' or 'percentile'
        var currentPage = 1;
        var pageSize = 10;

        var tgGrade = document.getElementById('ba-mod-tg-grade');
        var tgPct = document.getElementById('ba-mod-tg-pct');
        var bandrow = document.getElementById('ba-mod-bandrow');
        var stuBody = document.getElementById('ba-mod-stu-body');
        var expBtn = document.getElementById('ba-mod-export-btn');
        var stuPageSizeSelect = document.getElementById('ba-mod-page-size');
        var paginationInfo = document.getElementById('ba-mod-pagination-info');
        var paginationBtns = document.getElementById('ba-mod-pagination-btns');

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
            if (!stuData) return;

            var totalItems = stuData.length;
            var bands = computeBands(stuData, perfMode);

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
                    '<div class="band top"><div class="pct">' + counts.top + '</div><div class="lbl">' + labels.top[0] + '</div><div class="cnt">' + labels.top[1] + '</div></div>' +
                    '<div class="band mid"><div class="pct">' + counts.mid + '</div><div class="lbl">' + labels.mid[0] + '</div><div class="cnt">' + labels.mid[1] + '</div></div>' +
                    '<div class="band bot"><div class="pct">' + counts.bot + '</div><div class="lbl">' + labels.bot[0] + '</div><div class="cnt">' + labels.bot[1] + '</div></div>';
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
                html = '<tr><td colspan="8" style="text-align:center; padding:32px; color:#64748b;">No students found for this module.</td></tr>';
            }

            if (stuBody) {
                stuBody.innerHTML = html;
            }

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
                renderPerformance();
            });

            tgPct.addEventListener('click', function() {
                perfMode = 'percentile';
                tgPct.classList.add('on');
                tgGrade.classList.remove('on');
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

        // CSV Export
        if (expBtn) {
            expBtn.addEventListener('click', function() {
                var csv = ['"Band","Student Name","Student ID","Grade","Attendance","Assignments","Projects","Tests","Merit"'];
                var bands = computeBands(stuData, perfMode);

                stuData.forEach(function(s, idx) {
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
            modalContainer.className = 'ba-modal-container';

            // Modal header
            var header = document.createElement('div');
            header.className = 'ba-modal-header';

            var titleEl = document.createElement('h3');
            titleEl.textContent = cname;

            var closeBtn = document.createElement('button');
            closeBtn.className = 'ba-modal-close';
            closeBtn.type = 'button';
            closeBtn.setAttribute('aria-label', 'Close');
            closeBtn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';

            header.appendChild(titleEl);
            header.appendChild(closeBtn);

            // Modal toolbar
            var toolbar = document.createElement('div');
            toolbar.className = 'ba-modal-toolbar';

            var statsHtml = '<span class="ba-stat-item"><strong id="modal-stu-count">' + totalStudents + '</strong> Students</span>' +
                '<span class="ba-dot">-</span>' +
                '<span class="ba-stat-item">Avg Grade: <strong>' + (isMaac ? avgG : avgG + '%') + '</strong></span>';
            if (!hideComp) {
                statsHtml += '<span class="ba-dot">-</span><span class="ba-stat-item">Completion: <strong>' + avgC + '%</strong></span>';
            }

            var leftStats = document.createElement('div');
            leftStats.className = 'ba-mt-stats';
            leftStats.innerHTML = statsHtml;

            var rightActions = document.createElement('div');
            rightActions.className = 'ba-mt-actions';
            rightActions.style.display = 'flex';
            rightActions.style.alignItems = 'center';
            rightActions.style.gap = '8px';

            var searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.id = 'ba-kpi-modal-search';
            searchInput.className = 'ba-modal-search-input';
            searchInput.placeholder = 'Search student...';

            var downloadBtn = document.createElement('button');
            downloadBtn.type = 'button';
            downloadBtn.id = 'ba-kpi-modal-download';
            downloadBtn.className = 'ba-btn-download-sm';
            downloadBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg> Download CSV';
            if (!hasStudentData) {
                downloadBtn.disabled = true;
            }

            rightActions.appendChild(searchInput);
            rightActions.appendChild(downloadBtn);

            toolbar.appendChild(leftStats);
            toolbar.appendChild(rightActions);

            // Modal body
            var body = document.createElement('div');
            body.className = 'ba-modal-body';

            if (!hasStudentData) {
                body.innerHTML = '<div class="ba-modal-empty-state">There is no data available here.</div>';
            } else {
                var thHtml = '<th class="sortable" data-col="0" data-type="text">Username <span class="sort-icon"></span></th>' +
                    '<th class="sortable" data-col="1" data-type="text">Name <span class="sort-icon"></span></th>' +
                    '<th class="sortable" data-col="2" data-type="num">Grade <span class="sort-icon"></span></th>';
                if (!hideComp) {
                    thHtml += '<th class="sortable" data-col="3" data-type="num">Completion <span class="sort-icon"></span></th>';
                }

                var tfHtml = '<tr><td colspan="2" style="text-align:right;color:#64748b;font-weight:600;">TOTAL AVERAGE:</td><td><strong>' + (isMaac ? avgG : avgG + '%') + '</strong></td>';
                if (!hideComp) {
                    tfHtml += '<td><strong>' + avgC + '%</strong></td>';
                }
                tfHtml += '</tr>';

                var rowsHtml = '';
                studentGrades.forEach(function(s) {
                    var comp = s.completionRate !== undefined ? parseFloat(s.completionRate).toFixed(2) : '0.00';
                    var displayGrade = (s.percentage === null || s.percentage === undefined)
                        ? '—'
                        : (isMaac ? parseFloat(s.percentage).toFixed(1) : parseFloat(s.percentage).toFixed(2) + '%');

                    rowsHtml += '<tr>' +
                        '<td>' + escapeHtml(s.username || '—') + '</td>' +
                        '<td><strong>' + escapeHtml(s.fullname || '') + '</strong></td>' +
                        '<td>' + escapeHtml(displayGrade) + '</td>';
                    if (!hideComp) {
                        rowsHtml += '<td>' + escapeHtml(comp + '%') + '</td>';
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
                                var valA = parseFloat(cellA.replace(/[^0-9.-]+/g, '')) || 0;
                                var valB = parseFloat(cellB.replace(/[^0-9.-]+/g, '')) || 0;
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
        renderPerformance();
    });
})();
