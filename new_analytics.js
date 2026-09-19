/**
 * New Batch Analytics Dashboard JavaScript
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

document.addEventListener("DOMContentLoaded", function () {
  initNewBatchAnalytics();
});

function initNewBatchAnalytics() {
  const container = document.getElementById("ba-top-tab-new");
  if (!container) return;

  const BASE_URL = window.location.href.split("?")[0];
  const sesskey = document.querySelector(".local-batchanalytics-wrap")?.dataset.sesskey || "";

  let rawData = null;
  let currentSubTab = "running";
  let currentPage = 1;
  const pageSize = 10;

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  fetch(`${BASE_URL}?action=getnewbatchdata&sesskey=${encodeURIComponent(sesskey)}`)
    .then((res) => res.json())
    .then((data) => {
      if (data.error) {
        console.error("Failed to load new batch data:", data.error);
        const tbody = document.getElementById("ba-new-table-body");
        if (tbody) {
          tbody.innerHTML = `<tr><td colspan="9" class="ba-new-empty" style="color: #ef4444;">Failed to load batch data: ${escapeHtml(data.error)}</td></tr>`;
        }
        return;
      }
      rawData = data;
      renderStats(data.stats);
      populateFilters(data.filters);
      setupEventListeners();
      applyAndRender();
    })
    .catch((err) => {
      console.error("Error fetching new batch data:", err);
      const tbody = document.getElementById("ba-new-table-body");
      if (tbody) {
        tbody.innerHTML = `<tr><td colspan="9" class="ba-new-empty" style="color: #ef4444;">Error fetching batch data. Please try refreshing the page.</td></tr>`;
      }
    });

  function renderStats(stats) {
    if (!stats) return;
    const setVal = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val ?? 0;
    };
    setVal("stat-running-batches", stats.runningBatches);
    setVal("stat-online-batches", stats.onlineBatches);
    setVal("stat-offline-batches", stats.offlineBatches);
    setVal("stat-class-mentors", stats.classMentors);
    setVal("stat-lab-mentors", stats.labMentors);
    setVal("stat-on-schedule", stats.onSchedule);
    setVal("stat-delayed", stats.delayed);
    setVal("stat-early", stats.early ?? 0);
    setVal("stat-total-students", stats.totalStudents);
  }

  function populateFilters(filters) {
    if (!filters) return;

    const yearSelect = document.getElementById("ba-filter-year");
    if (yearSelect && filters.years) {
      yearSelect.innerHTML = '<option value="">All Years</option>' +
        filters.years.map(y => `<option value="${escapeHtml(y)}">${escapeHtml(y)}</option>`).join('');
    }

    const batchSelect = document.getElementById("ba-filter-batch");
    if (batchSelect && filters.batchNames) {
      batchSelect.innerHTML = '<option value="">All Batches</option>' +
        filters.batchNames.map(b => `<option value="${escapeHtml(b)}">${escapeHtml(b)}</option>`).join('');
    }

    const courseSelect = document.getElementById("ba-filter-course");
    if (courseSelect && filters.courses) {
      courseSelect.innerHTML = '<option value="">All Courses</option>' +
        filters.courses.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
    }

    const modeSelect = document.getElementById("ba-filter-mode");
    if (modeSelect && filters.modes) {
      modeSelect.innerHTML = '<option value="">All Modes</option>' +
        filters.modes.map(m => `<option value="${escapeHtml(m)}">${escapeHtml(m)}</option>`).join('');
    }
  }

  function setupEventListeners() {
    const searchInput = document.getElementById("ba-new-search-input");
    if (searchInput) {
      searchInput.addEventListener("input", () => {
        currentPage = 1;
        applyAndRender();
      });
    }

    ["ba-filter-year", "ba-filter-batch", "ba-filter-course", "ba-filter-mode"].forEach((id) => {
      const el = document.getElementById(id);
      if (el) {
        el.addEventListener("change", () => {
          currentPage = 1;
          applyAndRender();
        });
      }
    });

    const resetBtn = document.getElementById("ba-new-reset-btn");
    if (resetBtn) {
      resetBtn.addEventListener("click", () => {
        if (searchInput) searchInput.value = "";
        ["ba-filter-year", "ba-filter-batch", "ba-filter-course", "ba-filter-mode"].forEach((id) => {
          const el = document.getElementById(id);
          if (el) el.value = "";
        });
        currentPage = 1;
        applyAndRender();
      });
    }

    const subtabs = document.querySelectorAll(".ba-new-subtab");
    subtabs.forEach((tab) => {
      tab.addEventListener("click", function () {
        subtabs.forEach((t) => t.classList.remove("active"));
        this.classList.add("active");
        currentSubTab = this.dataset.subtab;
        currentPage = 1;
        applyAndRender();
      });
    });
  }

  function applyAndRender() {
    if (!rawData || !rawData.batches) return;

    const searchTerm = (document.getElementById("ba-new-search-input")?.value || "").toLowerCase().trim();
    const yearVal = document.getElementById("ba-filter-year")?.value || "";
    const batchVal = document.getElementById("ba-filter-batch")?.value || "";
    const courseVal = document.getElementById("ba-filter-course")?.value || "";
    const modeVal = document.getElementById("ba-filter-mode")?.value || "";

    let runningCount = 0;
    let completedCount = 0;

    rawData.batches.forEach((b) => {
      if (b.isCompleted) {
        completedCount++;
      } else {
        runningCount++;
      }
    });

    const cntRunningEl = document.getElementById("ba-cnt-running");
    if (cntRunningEl) cntRunningEl.textContent = runningCount;
    const cntCompletedEl = document.getElementById("ba-cnt-completed");
    if (cntCompletedEl) cntCompletedEl.textContent = completedCount;

    let filtered = rawData.batches.filter((b) => {
      if (currentSubTab === "completed" && !b.isCompleted) return false;
      if (currentSubTab === "running" && b.isCompleted) return false;

      if (searchTerm) {
        const matchesName = (b.batchId || "").toString().toLowerCase().includes(searchTerm);
        const matchesCourse = (b.courseName || "").toLowerCase().includes(searchTerm);
        const matchesModule = (b.currentModule || "").toLowerCase().includes(searchTerm);
        if (!matchesName && !matchesCourse && !matchesModule) return false;
      }

      if (yearVal && String(b.year) !== String(yearVal)) return false;
      if (batchVal && b.batchId !== batchVal) return false;
      if (courseVal && b.courseName !== courseVal) return false;
      if (modeVal && b.mode !== modeVal) return false;

      return true;
    });

    // Ensure batches are ordered with oldest date first
    filtered.sort((a, b) => {
      const tsA = a.startdateTimestamp || 0;
      const tsB = b.startdateTimestamp || 0;
      if (tsA === tsB) return (a.id || 0) - (b.id || 0);
      if (tsA === 0) return 1;
      if (tsB === 0) return -1;
      return tsA - tsB; // Oldest date first
    });

    const totalItems = filtered.length;
    const totalPages = Math.ceil(totalItems / pageSize) || 1;
    if (currentPage > totalPages) currentPage = totalPages;

    const startIndex = (currentPage - 1) * pageSize;
    const pageItems = filtered.slice(startIndex, startIndex + pageSize);

    renderTable(pageItems, startIndex);
    renderPagination(totalItems, startIndex, pageItems.length, totalPages);
  }

  function renderTable(items, startIndex) {
    const tbody = document.getElementById("ba-new-table-body");
    if (!tbody) return;

    if (items.length === 0) {
      tbody.innerHTML = `<tr><td colspan="9" class="ba-new-empty">No matching batches found.</td></tr>`;
      return;
    }

    tbody.innerHTML = items
      .map((b, idx) => {
        const siNo = startIndex + idx + 1;
        const modeLower = (b.mode || "").toLowerCase();
        const modeBadgeClass = modeLower === "online" ? "ba-badge-green" : (modeLower === "offline" ? "ba-badge-blue" : "ba-badge-purple");
        const typeBadgeClass = "ba-badge-gray";

        let statusHtml = "";
        if (b.status === "delayed") {
          statusHtml = `<span class="ba-status-pill status-delayed"><span class="ba-beacon-dot dot-red"></span> ${escapeHtml(b.statusLabel)}</span>`;
        } else if (b.status === "early") {
          statusHtml = `<span class="ba-status-pill status-early"><span class="ba-beacon-dot dot-blue"></span> ${escapeHtml(b.statusLabel)}</span>`;
        } else {
          statusHtml = `<span class="ba-status-pill status-ok"><span class="ba-beacon-dot dot-green"></span> On schedule</span>`;
        }

        let batchUrl = "";
        const secId = b.sectionId && b.sectionId > 0 ? b.sectionId : (b.id && b.id > 0 ? b.id : 0);
        if (secId > 0) {
          batchUrl = `batch.php?id=${encodeURIComponent(secId)}`;
        } else {
          batchUrl = "batch.php";
        }

        let moduleUrl = "";
        if (secId > 0 && b.moduleIdx && b.courseId) {
          moduleUrl = `module.php?batchid=${encodeURIComponent(secId)}&module=${encodeURIComponent(b.moduleIdx)}&courseid=${encodeURIComponent(b.courseId)}`;
        } else if (secId > 0 && b.moduleIdx) {
          moduleUrl = `module.php?batchid=${encodeURIComponent(secId)}&module=${encodeURIComponent(b.moduleIdx)}`;
        }

        const moduleHtml = moduleUrl
          ? `<a href="${moduleUrl}" class="ba-module-chip" title="View Module Analytics"><span class="chip-icon">📘</span> <span class="chip-name">${escapeHtml(b.currentModule || "N/A")}</span></a>`
          : `<span class="ba-module-chip static"><span class="chip-icon">📘</span> <span class="chip-name">${escapeHtml(b.currentModule || "N/A")}</span></span>`;

        return `
          <tr>
            <td class="td-sno">${siNo}</td>
            <td class="td-batch-id">
              <a href="${batchUrl}" class="ba-batch-id-pill" title="View Batch Overview">
                <span class="batch-hash">#</span><strong>${escapeHtml(b.batchId)}</strong>
              </a>
            </td>
            <td class="td-course-name">
              <span class="course-title">${escapeHtml(b.courseName)}</span>
              ${b.studentCount ? `<span class="course-meta">${b.studentCount} Students Enrolled</span>` : ''}
            </td>
            <td><span class="ba-badge ${modeBadgeClass}"><span class="badge-dot"></span> ${escapeHtml(b.mode)}</span></td>
            <td><span class="ba-badge ${typeBadgeClass}">${escapeHtml(b.type)}</span></td>
            <td class="td-date"><span class="date-icon">📅</span> ${escapeHtml(b.startDate)}</td>
            <td class="td-module">${moduleHtml}</td>
            <td>${statusHtml}</td>
            <td style="text-align:center;">
              <a href="${batchUrl}" class="ba-new-view-btn">
                <span class="btn-lbl">View Batch</span>
                <span class="btn-arr">→</span>
              </a>
            </td>
          </tr>
        `;
      })
      .join("");
    }

  function renderPagination(totalItems, startIndex, pageItemCount, totalPages) {
    const infoEl = document.getElementById("ba-new-pagination-info");
    const controlsEl = document.getElementById("ba-new-pagination-controls");

    if (infoEl) {
      if (totalItems === 0) {
        infoEl.textContent = "Showing 0 of 0 entries";
      } else {
        const start = startIndex + 1;
        const end = startIndex + pageItemCount;
        infoEl.textContent = `Showing ${start} to ${end} of ${totalItems} entries`;
      }
    }

    if (controlsEl) {
      if (totalPages <= 1) {
        controlsEl.innerHTML = "";
        return;
      }

      let btnHtml = `<button type="button" class="ba-page-btn" ${currentPage === 1 ? "disabled" : ""} data-page="${currentPage - 1}">Previous</button>`;
      for (let p = 1; p <= totalPages; p++) {
        btnHtml += `<button type="button" class="ba-page-btn ${p === currentPage ? "active" : ""}" data-page="${p}">${p}</button>`;
      }
      btnHtml += `<button type="button" class="ba-page-btn" ${currentPage === totalPages ? "disabled" : ""} data-page="${currentPage + 1}">Next</button>`;

      controlsEl.innerHTML = btnHtml;

      controlsEl.querySelectorAll(".ba-page-btn:not([disabled])").forEach((btn) => {
        btn.addEventListener("click", function () {
          currentPage = parseInt(this.dataset.page, 10);
          applyAndRender();
        });
      });
    }
  }

  function switchToOldBatch(batchName) {
    const oldTabBtn = document.querySelector('.ba-top-nav-tab[data-top-tab="old"]');
    if (oldTabBtn) {
      oldTabBtn.click();
    }

    const selectEl = document.getElementById("ba-batch");
    if (selectEl) {
      for (let i = 0; i < selectEl.options.length; i++) {
        if (selectEl.options[i].text.includes(batchName) || selectEl.options[i].value.includes(batchName)) {
          selectEl.selectedIndex = i;
          selectEl.dispatchEvent(new Event("change"));
          break;
        }
      }
    }
  }
}
