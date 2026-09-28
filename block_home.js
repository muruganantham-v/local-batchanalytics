/**
 * Client-side script for block_batchanalytics Home Page Widget
 *
 * Implements the interactive prototype Home behavior:
 * - 8 Portfolio Stat Tiles with filter actions
 * - Real-time Search and Dropdown Filters
 * - Subtabs for Current Running Batches & Completed Batches
 * - Paginated Table with "View Batch" navigation
 *
 * @package    block_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    initBlockHomeWidget();
  });

  function initBlockHomeWidget() {
    const container = document.getElementById('ba-block-home-container');
    if (!container) return;

    const SESSKEY = container.dataset.sesskey || '';
    const API_URL = container.dataset.apiUrl || (M.cfg.wwwroot + '/blocks/batchanalytics/index.php');
    const BATCH_URL_BASE = M.cfg.wwwroot + '/blocks/batchanalytics/batch.php';

    let allBatches = [];
    let runningBatches = [];
    let completedBatches = [];
    let currentTab = 'running'; // 'running' | 'completed'
    let currentFilterKey = 'all'; // 'all' | 'online' | 'offline' | 'onsch' | 'delayed' | 'classm' | 'labm'
    let currentPage = 1;
    const PER_PAGE = 10;
    let heading = 'Current Running Batches';

    // Tile configuration metadata
    const TILE_DEFS = [
      { key: 'all', lbl: 'Running batches', ic: '&#9776;', tint: 'ic-brand' },
      { key: 'online', lbl: 'Online batches', ic: '&#9673;', tint: 'ic-blue' },
      { key: 'offline', lbl: 'Offline batches', ic: '&#9635;', tint: 'ic-green' },
      { key: 'classm', lbl: 'Class mentors', ic: '&#128100;', tint: 'ic-purple' },
      { key: 'labm', lbl: 'Lab mentors', ic: '&#128100;', tint: 'ic-grey' },
      { key: 'onsch', lbl: 'On schedule', ic: '&#10003;', tint: 'ic-green' },
      { key: 'delayed', lbl: 'Delayed', ic: '&#9888;', flag: true },
      { key: 'students', lbl: 'Students (current)', ic: '&#127891;', tint: 'ic-blue' }
    ];

    // Fetch batch data from index.php endpoint
    fetch(`${API_URL}?action=getnewbatchdata&sesskey=${encodeURIComponent(SESSKEY)}`)
      .then((res) => res.json())
      .then((data) => {
        if (data.error) {
          showError('Failed to load batch data: ' + data.error);
          return;
        }

        allBatches = Array.isArray(data.batches) ? data.batches : [];
        runningBatches = allBatches.filter((b) => !b.isCompleted);
        completedBatches = allBatches.filter((b) => b.isCompleted);

        renderTiles(data.stats, runningBatches);
        populateFilterDropdowns(data.filters);
        bindEvents();
        applyFilterAndRender();
      })
      .catch((err) => {
        console.error('Batch Analytics block error:', err);
        showError('Unable to load batch analytics. Please refresh the page.');
      });

    function showError(msg) {
      const tbody = document.getElementById('ba-block-rowsBody');
      if (tbody) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:24px; color:#ef4444;">${escapeHtml(msg)}</td></tr>`;
      }
    }

    function renderTiles(stats, running) {
      const tilesContainer = document.getElementById('ba-block-tiles');
      if (!tilesContainer) return;

      const countUnique = (field) => {
        const values = new Set();
        running.forEach((b) => {
          const items = Array.isArray(b[field]) ? b[field] : [];
          items.forEach((item) => {
            const name = String(item || '').trim();
            if (name) values.add(name);
          });
        });
        return values.size;
      };

      const runningCount = running.length;
      const onlineCount = running.filter((b) => b.mode === 'Online').length;
      const offlineCount = running.filter((b) => b.mode === 'Offline').length;
      const classMCount = countUnique('classMentors');
      const labMCount = countUnique('labMentors');
      const onScheduleCount = running.filter((b) => b.status === 'on_schedule' || b.status === 'early').length;
      const delayedCount = running.filter((b) => b.status === 'delayed' || b.status === 'minor_slip').length;
      const studentTotal = running.reduce((sum, b) => sum + (parseInt(b.studentCount, 10) || 0), 0);

      const valMap = {
        all: runningCount,
        online: onlineCount,
        offline: offlineCount,
        classm: classMCount,
        labm: labMCount,
        onsch: onScheduleCount,
        delayed: delayedCount,
        students: studentTotal.toLocaleString()
      };

      tilesContainer.innerHTML = TILE_DEFS.map((t) => {
        const val = valMap[t.key] !== undefined ? valMap[t.key] : (stats ? stats[t.key] || 0 : 0);
        return `
          <div class="tile ${t.flag ? 'flag' : ''} ${currentFilterKey === t.key ? 'active-filter' : ''}" data-key="${t.key}" role="button" tabindex="0">
            <div class="ico ${t.tint || ''}">${t.ic}</div>
            <div class="val">${val}</div>
            <div class="lbl">${t.lbl}</div>
            <span class="arrow">&#8615;</span>
          </div>
        `;
      }).join('');

      // Attach tile click listeners
      tilesContainer.querySelectorAll('.tile').forEach((tileEl) => {
        tileEl.addEventListener('click', function () {
          const key = this.dataset.key;
          handleTileClick(key);
        });
      });
    }

    function handleTileClick(key) {
      currentFilterKey = key;
      currentTab = 'running';
      currentPage = 1;

      // Update active styling on tabs
      document.getElementById('ba-block-tab-running')?.classList.add('active');
      document.getElementById('ba-block-tab-completed')?.classList.remove('active');

      // Update active styling on tiles
      document.querySelectorAll('#ba-block-tiles .tile').forEach((el) => {
        el.classList.toggle('active-filter', el.dataset.key === key);
      });

      applyFilterAndRender();

      // Scroll smoothly to results table
      const resEl = document.getElementById('ba-block-resultsState');
      if (resEl) {
        resEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    }

    function populateFilterDropdowns(filters) {
      if (!filters) return;

      const yearSelect = document.getElementById('ba-block-f-year');
      if (yearSelect && filters.years) {
        yearSelect.innerHTML = '<option value="">Year — All</option>' +
          filters.years.map((y) => `<option value="${escapeHtml(y)}">${escapeHtml(y)}</option>`).join('');
      }

      const batchSelect = document.getElementById('ba-block-f-batch');
      if (batchSelect && filters.batchNames) {
        batchSelect.innerHTML = '<option value="">Batch No — All</option>' +
          filters.batchNames.map((b) => `<option value="${escapeHtml(b)}">${escapeHtml(b)}</option>`).join('');
      }

      const courseSelect = document.getElementById('ba-block-f-course');
      if (courseSelect && filters.courses) {
        courseSelect.innerHTML = '<option value="">Course — All</option>' +
          filters.courses.map((c) => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
      }

      const modeSelect = document.getElementById('ba-block-f-mode');
      if (modeSelect && filters.modes) {
        modeSelect.innerHTML = '<option value="">Mode — All</option>' +
          filters.modes.map((m) => `<option value="${escapeHtml(m)}">${escapeHtml(m)}</option>`).join('');
      }
    }

    function bindEvents() {
      // Search input & button
      const searchInput = document.getElementById('ba-block-search');
      const searchBtn = document.getElementById('ba-block-btn-search');

      if (searchInput) {
        searchInput.addEventListener('input', () => {
          currentPage = 1;
          applyFilterAndRender();
        });
        searchInput.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            currentPage = 1;
            applyFilterAndRender();
          }
        });
      }

      if (searchBtn) {
        searchBtn.addEventListener('click', () => {
          currentPage = 1;
          applyFilterAndRender();
        });
      }

      // Filter selects
      ['ba-block-f-year', 'ba-block-f-batch', 'ba-block-f-course', 'ba-block-f-mode'].forEach((id) => {
        const sel = document.getElementById(id);
        if (sel) {
          sel.addEventListener('change', () => {
            currentPage = 1;
            applyFilterAndRender();
          });
        }
      });

      // Subtabs (Running vs Completed)
      document.getElementById('ba-block-tab-running')?.addEventListener('click', function () {
        if (currentTab === 'running') return;
        currentTab = 'running';
        currentFilterKey = 'all';
        currentPage = 1;
        this.classList.add('active');
        document.getElementById('ba-block-tab-completed')?.classList.remove('active');
        clearTileActiveFilters();
        applyFilterAndRender();
      });

      document.getElementById('ba-block-tab-completed')?.addEventListener('click', function () {
        if (currentTab === 'completed') return;
        currentTab = 'completed';
        currentFilterKey = 'all';
        currentPage = 1;
        this.classList.add('active');
        document.getElementById('ba-block-tab-running')?.classList.remove('active');
        clearTileActiveFilters();
        applyFilterAndRender();
      });
    }

    function clearTileActiveFilters() {
      document.querySelectorAll('#ba-block-tiles .tile').forEach((el) => {
        el.classList.remove('active-filter');
      });
    }

    function getBaseDataset() {
      return currentTab === 'running' ? runningBatches.slice() : completedBatches.slice();
    }

    function applyFilterAndRender() {
      let list = getBaseDataset();
      heading = currentTab === 'running' ? 'Current Running Batches' : 'Completed Batches';

      // 1. Tile Filter (only applied for running batches)
      if (currentTab === 'running') {
        if (currentFilterKey === 'online') {
          list = list.filter((b) => b.mode === 'Online');
          heading = `Online batches — ${list.length}`;
        } else if (currentFilterKey === 'offline') {
          list = list.filter((b) => b.mode === 'Offline');
          heading = `Offline batches — ${list.length}`;
        } else if (currentFilterKey === 'onsch') {
          list = list.filter((b) => b.status === 'on_schedule' || b.status === 'early');
          heading = `On-schedule batches — ${list.length}`;
        } else if (currentFilterKey === 'delayed') {
          list = list.filter((b) => b.status === 'delayed' || b.status === 'minor_slip');
          heading = `Delayed batches — ${list.length}`;
        } else if (currentFilterKey === 'classm') {
          list = list.filter((b) => Array.isArray(b.classMentors) && b.classMentors.length > 0);
          heading = `Batches with Class Mentors — ${list.length}`;
        } else if (currentFilterKey === 'labm') {
          list = list.filter((b) => Array.isArray(b.labMentors) && b.labMentors.length > 0);
          heading = `Batches with Lab Mentors — ${list.length}`;
        }
      }

      // 2. Dropdown Filters
      const fYear = document.getElementById('ba-block-f-year')?.value || '';
      const fBatch = document.getElementById('ba-block-f-batch')?.value || '';
      const fCourse = document.getElementById('ba-block-f-course')?.value || '';
      const fMode = document.getElementById('ba-block-f-mode')?.value || '';

      if (fYear) {
        list = list.filter((b) => String(b.year) === String(fYear));
      }
      if (fBatch) {
        list = list.filter((b) => String(b.batchId).toLowerCase() === fBatch.toLowerCase());
      }
      if (fCourse) {
        list = list.filter((b) => String(b.courseName).toLowerCase() === fCourse.toLowerCase());
      }
      if (fMode) {
        list = list.filter((b) => String(b.mode).toLowerCase() === fMode.toLowerCase());
      }

      // 3. Search Bar
      const query = (document.getElementById('ba-block-search')?.value || '').trim().toLowerCase();
      if (query) {
        list = list.filter((b) => {
          const id = String(b.batchId || '').toLowerCase();
          const course = String(b.courseName || '').toLowerCase();
          return id.includes(query) || course.includes(query);
        });
        heading = `Found ${list.length} batches`;
      }

      renderTable(list);
    }

    function renderTable(filteredList) {
      const resultsState = document.getElementById('ba-block-resultsState');
      const emptyState = document.getElementById('ba-block-emptyState');
      const listHead = document.getElementById('ba-block-listHead');
      const rowsBody = document.getElementById('ba-block-rowsBody');
      const resultsTitle = document.getElementById('ba-block-resultsTitle');
      const pageInfo = document.getElementById('ba-block-pageInfo');
      const pager = document.getElementById('ba-block-pager');

      if (!resultsState || !emptyState || !listHead || !rowsBody) return;

      if (!filteredList.length) {
        resultsState.style.display = 'none';
        emptyState.style.display = 'block';
        return;
      }

      resultsState.style.display = 'block';
      emptyState.style.display = 'none';

      if (resultsTitle) resultsTitle.textContent = heading;

      // Table Headers based on Active Subtab
      if (currentTab === 'running') {
        listHead.innerHTML = `
          <tr>
            <th>Batch ID</th>
            <th>Course</th>
            <th>Mode</th>
            <th>Type</th>
            <th>Start</th>
            <th>Current Module</th>
            <th>Status</th>
            <th style="text-align:right;"></th>
          </tr>
        `;
      } else {
        listHead.innerHTML = `
          <tr>
            <th>Batch ID</th>
            <th>Course</th>
            <th>Mode</th>
            <th>Planned Start</th>
            <th>Planned End</th>
            <th>Actual Start</th>
            <th>Actual End</th>
            <th>Delay</th>
            <th>Status</th>
            <th style="text-align:right;"></th>
          </tr>
        `;
      }

      // Pagination slice
      const totalPages = Math.max(1, Math.ceil(filteredList.length / PER_PAGE));
      if (currentPage > totalPages) currentPage = totalPages;
      const startIdx = (currentPage - 1) * PER_PAGE;
      const pageSlice = filteredList.slice(startIdx, startIdx + PER_PAGE);

      // Render Rows
      if (currentTab === 'running') {
        rowsBody.innerHTML = pageSlice.map((b) => {
          const batchUrl = `${BATCH_URL_BASE}?id=${b.id}&mode=${encodeURIComponent(b.mode || 'Offline')}`;
          return `
            <tr>
              <td><span class="bid">${escapeHtml(b.batchId)}</span></td>
              <td>${escapeHtml(b.courseName)}</td>
              <td><span class="pill mode-${(b.mode || '').toLowerCase()}">${escapeHtml(b.mode || 'Offline')}</span></td>
              <td><span class="pill type-${(b.type || '').toLowerCase()}">${escapeHtml(b.type || 'Regular')}</span></td>
              <td class="date">${escapeHtml(b.startDate || '—')}</td>
              <td>${escapeHtml(b.currentModule || '—')}</td>
              <td>${renderStatusChip(b)}</td>
              <td class="actioncell" style="text-align:right;">
                <a href="${batchUrl}" class="viewbtn">View Batch</a>
              </td>
            </tr>
          `;
        }).join('');
      } else {
        rowsBody.innerHTML = pageSlice.map((b) => {
          const batchUrl = `${BATCH_URL_BASE}?id=${b.id}&mode=${encodeURIComponent(b.mode || 'Offline')}`;
          const delayText = b.delayDays > 0 ? `+${b.delayDays} d` : (b.delayDays < 0 ? `${b.delayDays} d` : '0 d');
          const delayClass = b.delayDays > 0 ? 'over' : 'ontime';
          return `
            <tr>
              <td><span class="bid">${escapeHtml(b.batchId)}</span></td>
              <td>${escapeHtml(b.courseName)}</td>
              <td><span class="pill mode-${(b.mode || '').toLowerCase()}">${escapeHtml(b.mode || 'Offline')}</span></td>
              <td class="date">${escapeHtml(b.startDate || '—')}</td>
              <td class="date">${escapeHtml(b.plannedEnd || '—')}</td>
              <td class="date">${escapeHtml(b.actualStart || '—')}</td>
              <td class="date">${escapeHtml(b.actualEnd || '—')}</td>
              <td><span class="dnum ${delayClass}">${delayText}</span></td>
              <td><span class="pill pill-completed">Completed</span></td>
              <td class="actioncell" style="text-align:right;">
                <a href="${batchUrl}" class="viewbtn">View Batch</a>
              </td>
            </tr>
          `;
        }).join('');
      }

      // Page Info string
      const from = filteredList.length ? startIdx + 1 : 0;
      const to = Math.min(startIdx + PER_PAGE, filteredList.length);
      if (pageInfo) {
        pageInfo.textContent = `Showing ${from}–${to} of ${filteredList.length}`;
      }

      // Pagination Controls
      if (pager) {
        if (totalPages <= 1) {
          pager.innerHTML = '';
        } else {
          let pBtns = `<button type="button" ${currentPage === 1 ? 'disabled' : ''} data-page="${currentPage - 1}">Prev</button>`;
          for (let i = 1; i <= totalPages; i++) {
            pBtns += `<button type="button" class="${i === currentPage ? 'on' : ''}" data-page="${i}">${i}</button>`;
          }
          pBtns += `<button type="button" ${currentPage === totalPages ? 'disabled' : ''} data-page="${currentPage + 1}">Next</button>`;
          pager.innerHTML = pBtns;

          pager.querySelectorAll('button').forEach((btn) => {
            btn.addEventListener('click', function () {
              const p = parseInt(this.dataset.page, 10);
              if (p > 0 && p <= totalPages && p !== currentPage) {
                currentPage = p;
                renderTable(filteredList);
              }
            });
          });
        }
      }
    }

    function renderStatusChip(b) {
      const status = b.status || 'on_schedule';
      const label = b.statusLabel || (status === 'delayed' ? 'Delayed' : (status === 'minor_slip' ? 'Minor slip' : (status === 'early' ? 'Early' : 'On schedule')));

      if (status === 'delayed') {
        return `<span class="st st-r">${escapeHtml(label)}</span>`;
      }
      if (status === 'minor_slip') {
        return `<span class="st st-a">${escapeHtml(label)}</span>`;
      }
      if (status === 'early') {
        return `<span class="st st-b">${escapeHtml(label)}</span>`;
      }
      return `<span class="st st-g">${escapeHtml(label)}</span>`;
    }

    function escapeHtml(val) {
      if (val === null || val === undefined) return '';
      return String(val)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }
  }
})();
