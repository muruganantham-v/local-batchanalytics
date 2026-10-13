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

  const wrap = document.querySelector(".local-batchanalytics-wrap");
  let canViewAll = wrap?.dataset.canViewAllCourses === "1";
  let canViewAssigned = wrap?.dataset.canViewAssignedCourses === "1";
  const initialActiveSubtab = document.querySelector(".ba-new-subtab.active");
  let defaultSubTab = initialActiveSubtab?.dataset.subtab || (canViewAll ? "running" : (canViewAssigned ? "my_running" : "running"));
  let currentSubTab = defaultSubTab;
  let currentStatFilter = currentSubTab === "completed" || currentSubTab === "my_completed" ? "" : "running";
  let currentScheduleFilter = "";
  let currentPage = 1;
  const pageSize = 10;
  let rawData = null;

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
          tbody.innerHTML = `<tr><td colspan="8" class="ba-new-empty" style="color: #ef4444;">Failed to load batch data: ${escapeHtml(data.error)}</td></tr>`;
        }
        return;
      }
      rawData = data;
      if (data.capabilities) {
        if (typeof data.capabilities.canViewAllCourses === "boolean") {
          canViewAll = data.capabilities.canViewAllCourses;
        }
        if (typeof data.capabilities.canViewAssignedCourses === "boolean") {
          canViewAssigned = data.capabilities.canViewAssignedCourses;
        }
      }
      renderStats(data.stats, data.batches);
      renderGlanceTags();
      syncSection3Filters();
      setupEventListeners();
      setupMentorModal();
      setupExportCsv();
      applyAndRender();
    })
    .catch((err) => {
      console.error("Error fetching new batch data:", err);
      const tbody = document.getElementById("ba-new-table-body");
      if (tbody) {
        tbody.innerHTML = `<tr><td colspan="8" class="ba-new-empty" style="color: #ef4444;">Error fetching batch data. Please try refreshing the page.</td></tr>`;
      }
    });

  function renderStats(stats, batches) {
    if (!stats) return;
    const runningBatches = Array.isArray(batches) ? batches.filter((b) => !b.isCompleted) : [];

    setStatValue("stat-running-batches", stats.runningBatches);
    setStatValue("stat-online-batches", stats.onlineBatches);
    setStatValue("stat-offline-batches", stats.offlineBatches);
    setStatValue("stat-completed-batches", stats.completedBatches);

    const countUnique = (field) => {
      const values = new Set();
      runningBatches.forEach((b) => {
        const items = Array.isArray(b[field]) ? b[field] : [];
        items.forEach((item) => {
          const name = String(item || "").trim();
          if (name) values.add(name);
        });
      });
      return values.size;
    };

    setStatValue("stat-class-mentors", countUnique("classMentors"));
    setStatValue("stat-lab-mentors", countUnique("labMentors"));
    renderContextStats(runningBatches);
  }

  function setStatValue(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val ?? 0;
  }

  function renderContextStats(runningBatches) {
    let scoped = Array.isArray(runningBatches) ? runningBatches : [];

    // Section 2 respects Section 1 filter
    if (currentStatFilter === "offline") {
      scoped = scoped.filter((b) => (b.mode || "").toLowerCase() === "offline");
    } else if (currentStatFilter === "online") {
      scoped = scoped.filter((b) => (b.mode || "").toLowerCase() === "online");
    }

    const statusCounts = scoped.reduce(
      (counts, b) => {
        if (b.status === "delayed") {
          counts.delayed += 1;
        } else if (b.status === "minor_slip") {
          counts.minorSlip += 1;
        } else if (b.status === "early") {
          counts.early += 1;
        } else {
          counts.onSchedule += 1;
        }
        return counts;
      },
      { onSchedule: 0, minorSlip: 0, delayed: 0, early: 0 }
    );

    let studentPool = scoped;
    if (currentScheduleFilter) {
      studentPool = scoped.filter((b) => b.status === currentScheduleFilter);
    }
    const totalStudents = studentPool.reduce(
      (sum, b) => sum + (parseInt(b.studentCount, 10) || 0),
      0
    );

    setStatValue("stat-on-schedule", statusCounts.onSchedule);
    setStatValue("stat-minor-slip", statusCounts.minorSlip);
    setStatValue("stat-delayed", statusCounts.delayed);
    setStatValue("stat-early", statusCounts.early);
    setStatValue("stat-total-students", totalStudents.toLocaleString());
  }

  /**
   * Determine available batches under current subtab, Section 1 and Section 2 filters
   */
  function getFilteredUniverse() {
    if (!rawData || !rawData.batches) return [];

    return rawData.batches.filter((b) => {
      // 1. Subtab scope
      if (currentSubTab === "completed") {
        if (!b.isCompleted) return false;
        if (!canViewAll && !b.isAssigned) return false;
      } else if (currentSubTab === "my_completed") {
        if (!b.isCompleted || !b.isAssigned) return false;
      } else if (currentSubTab === "my_running") {
        if (b.isCompleted || !b.isAssigned) return false;
      } else if (currentSubTab === "running") {
        if (b.isCompleted) return false;
      }

      // 2. Section 1 filter (Portfolio)
      if (currentStatFilter === "offline") {
        if ((b.mode || "").toLowerCase() !== "offline") return false;
      } else if (currentStatFilter === "online") {
        if ((b.mode || "").toLowerCase() !== "online") return false;
      }

      // 3. Section 2 filter (Schedule Health)
      if (currentScheduleFilter) {
        if (b.status !== currentScheduleFilter) return false;
      }

      return true;
    });
  }

  /**
   * Section 3 respects Section 1 and Section 2 filters
   * Dynamically populates Year, Section/Batch, Course, Mode dropdowns based on active filters
   */
  function syncSection3Filters() {
    const universe = getFilteredUniverse();

    const uniqueYears = [...new Set(universe.map((b) => b.year).filter(Boolean))].sort((a, b) => b - a);
    const uniqueBatches = [...new Set(universe.map((b) => b.batchId).filter(Boolean))].sort();
    const uniqueCourses = [...new Set(universe.map((b) => b.courseName).filter(Boolean))].sort();
    const uniqueModes = [...new Set(universe.map((b) => b.mode).filter(Boolean))].sort();

    updateSelectOptions("ba-filter-year", "Year — All", uniqueYears);
    updateSelectOptions("ba-filter-batch", "Section / Batch — All", uniqueBatches);
    updateSelectOptions("ba-filter-course", "Course — All", uniqueCourses);
    updateSelectOptions("ba-filter-mode", "Mode — All", uniqueModes);

    const modeSelect = document.getElementById("ba-filter-mode");
    if (modeSelect) {
      if (currentStatFilter === "offline") {
        const offOpt = Array.from(modeSelect.options).find((o) => o.value.toLowerCase() === "offline");
        if (offOpt) modeSelect.value = offOpt.value;
      } else if (currentStatFilter === "online") {
        const onOpt = Array.from(modeSelect.options).find((o) => o.value.toLowerCase() === "online");
        if (onOpt) modeSelect.value = onOpt.value;
      }
    }
  }

  function updateSelectOptions(id, placeholder, values) {
    const select = document.getElementById(id);
    if (!select) return;
    const currentVal = select.value;
    let html = `<option value="">${placeholder}</option>`;
    values.forEach((v) => {
      html += `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`;
    });
    select.innerHTML = html;
    if (currentVal && values.some((v) => String(v) === String(currentVal))) {
      select.value = currentVal;
    } else {
      select.value = "";
    }
  }

  function updateTableTitle() {
    const titleEl = document.getElementById("ba-table-title-text");
    if (!titleEl) return;
    if (currentSubTab === "completed") {
      titleEl.textContent = "Completed Batches";
    } else if (currentSubTab === "my_completed") {
      titleEl.textContent = "My Completed Batches";
    } else if (currentSubTab === "my_running") {
      titleEl.textContent = "My Running Batches";
    } else {
      titleEl.textContent = "Current Running Batches";
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
        currentStatFilter = currentSubTab === "completed" || currentSubTab === "my_completed" ? "" : "running";
        currentScheduleFilter = "";
        setActiveStatCard();
        setActiveScheduleCard();
        renderGlanceTags();
        syncSection3Filters();
        currentPage = 1;
        applyAndRender();
      });
    }

    // Subtabs Navigation - "and also if i click the tabs the filter should not get reset."
    const subtabs = document.querySelectorAll(".ba-new-subtab");
    subtabs.forEach((tab) => {
      tab.addEventListener("click", function () {
        subtabs.forEach((t) => t.classList.remove("active"));
        this.classList.add("active");
        currentSubTab = this.dataset.subtab;
        updateTableTitle();

        // Filters persist across subtab switches
        setActiveStatCard();
        setActiveScheduleCard();
        renderGlanceTags();

        // Re-sync Section 3 filters to match the new subtab scope
        syncSection3Filters();
        currentPage = 1;
        applyAndRender();
      });
    });

    // Section 1 Stat Cards (Portfolio At A Glance)
    document.querySelectorAll(".ba-new-stat-filter").forEach((card) => {
      card.addEventListener("click", function () {
        const filterVal = this.dataset.batchFilter || "running";
        const targetRunningTab = canViewAll ? "running" : "my_running";

        // Always display on Current Running Batches when card filter is applied
        if (currentSubTab !== targetRunningTab) {
          currentSubTab = targetRunningTab;
          setActiveSubtab();
          updateTableTitle();
        }

        if (filterVal === "running") {
          currentStatFilter = "running";
        } else {
          if (currentStatFilter === filterVal) {
            currentStatFilter = "running";
          } else {
            currentStatFilter = filterVal;
          }
        }

        setActiveStatCard();
        renderGlanceTags();
        syncSection3Filters();
        currentPage = 1;
        applyAndRender();
      });

      card.addEventListener("keydown", function (event) {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          this.click();
        }
      });
    });

    // Section 2 Stat Cards (Batch Schedule & Students)
    document.querySelectorAll(".ba-schedule-status-filter").forEach((card) => {
      card.addEventListener("click", function () {
        const filterVal = this.dataset.scheduleFilter || "";
        const targetRunningTab = canViewAll ? "running" : "my_running";

        // Always display on Current Running Batches when card filter is applied
        if (currentSubTab !== targetRunningTab) {
          currentSubTab = targetRunningTab;
          setActiveSubtab();
          updateTableTitle();
        }

        if (currentScheduleFilter === filterVal) {
          currentScheduleFilter = "";
        } else {
          currentScheduleFilter = filterVal;
        }

        setActiveScheduleCard();
        renderGlanceTags();
        syncSection3Filters();
        currentPage = 1;
        applyAndRender();
      });
    });
  }

  function renderGlanceTags() {
    // 1. Portfolio Tag (Section 1)
    const portfolioTagWrap = document.getElementById("ba-tag-portfolio");
    if (portfolioTagWrap) {
      if (currentStatFilter === "offline" || currentStatFilter === "online") {
        const label = currentStatFilter === "offline" ? "Offline Batches" : "Online Batches";
        portfolioTagWrap.innerHTML = `
          <span class="ba-filter-chip chip-portfolio">
            <span>${escapeHtml(label)}</span>
            <button type="button" class="ba-chip-remove" id="ba-clear-portfolio-filter" title="Remove filter" aria-label="Remove filter">&times;</button>
          </span>
        `;
        portfolioTagWrap.style.display = "inline-flex";

        const clearBtn = document.getElementById("ba-clear-portfolio-filter");
        if (clearBtn) {
          clearBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            currentStatFilter = currentSubTab === "completed" || currentSubTab === "my_completed" ? "" : "running";
            setActiveStatCard();
            renderGlanceTags();
            syncSection3Filters();
            currentPage = 1;
            applyAndRender();
          });
        }
      } else {
        portfolioTagWrap.innerHTML = "";
        portfolioTagWrap.style.display = "none";
      }
    }

    // 2. Schedule Tag (Section 2)
    const scheduleTagWrap = document.getElementById("ba-tag-schedule");
    if (scheduleTagWrap) {
      if (currentScheduleFilter) {
        const labels = {
          early: "Early",
          on_schedule: "On Schedule",
          minor_slip: "Minor Slip",
          delayed: "Delayed",
        };
        const label = labels[currentScheduleFilter] || currentScheduleFilter;
        scheduleTagWrap.innerHTML = `
          <span class="ba-filter-chip chip-schedule">
            <span>${escapeHtml(label)}</span>
            <button type="button" class="ba-chip-remove" id="ba-clear-schedule-filter" title="Remove filter" aria-label="Remove filter">&times;</button>
          </span>
        `;
        scheduleTagWrap.style.display = "inline-flex";

        const clearBtn = document.getElementById("ba-clear-schedule-filter");
        if (clearBtn) {
          clearBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            currentScheduleFilter = "";
            setActiveScheduleCard();
            renderGlanceTags();
            syncSection3Filters();
            currentPage = 1;
            applyAndRender();
          });
        }
      } else {
        scheduleTagWrap.innerHTML = "";
        scheduleTagWrap.style.display = "none";
      }
    }
  }

  function setActiveSubtab() {
    document.querySelectorAll(".ba-new-subtab").forEach((tab) => {
      tab.classList.toggle("active", tab.dataset.subtab === currentSubTab);
    });
  }

  function setActiveStatCard() {
    const isCompletedTab = currentSubTab === "completed" || currentSubTab === "my_completed";
    document.querySelectorAll(".ba-new-stat-filter").forEach((card) => {
      const filter = card.dataset.batchFilter;
      let isActive = false;
      if (filter === "running") {
        isActive = !isCompletedTab && currentStatFilter === "running";
      } else {
        isActive = filter === currentStatFilter;
      }
      card.classList.toggle("is-active", isActive);
      card.setAttribute("aria-pressed", isActive ? "true" : "false");
    });
  }

  function setActiveScheduleCard() {
    document.querySelectorAll(".ba-schedule-status-filter").forEach((card) => {
      const isActive = card.dataset.scheduleFilter === currentScheduleFilter;
      card.classList.toggle("is-active", isActive);
      card.setAttribute("aria-pressed", isActive ? "true" : "false");
    });
  }

  function getTableFilteredBatches() {
    if (!rawData || !rawData.batches) return [];

    const searchTerm = (document.getElementById("ba-new-search-input")?.value || "").toLowerCase().trim();
    const yearVal = document.getElementById("ba-filter-year")?.value || "";
    const batchVal = document.getElementById("ba-filter-batch")?.value || "";
    const courseVal = document.getElementById("ba-filter-course")?.value || "";
    const modeVal = document.getElementById("ba-filter-mode")?.value || "";

    return rawData.batches.filter((b) => {
      // 1. Subtab filter
      if (currentSubTab === "completed") {
        if (!b.isCompleted) return false;
        if (!canViewAll && !b.isAssigned) return false;
      } else if (currentSubTab === "my_completed") {
        if (!b.isCompleted || !b.isAssigned) return false;
      } else if (currentSubTab === "my_running") {
        if (b.isCompleted || !b.isAssigned) return false;
      } else if (currentSubTab === "running") {
        if (b.isCompleted) return false;
      }

      // 2. Section 1 filter
      if (currentStatFilter === "offline" && (b.mode || "").toLowerCase() !== "offline") {
        return false;
      }
      if (currentStatFilter === "online" && (b.mode || "").toLowerCase() !== "online") {
        return false;
      }

      // 3. Section 2 filter
      if (currentScheduleFilter && b.status !== currentScheduleFilter) {
        return false;
      }

      // 4. Section 3 Search
      if (searchTerm) {
        const matchesName =
          (b.batchId || "").toString().toLowerCase().includes(searchTerm) ||
          (b.parentBatchName || "").toString().toLowerCase().includes(searchTerm);
        const matchesCourse = (b.courseName || "").toLowerCase().includes(searchTerm);
        const matchesModule = (b.currentModule || "").toLowerCase().includes(searchTerm);
        if (!matchesName && !matchesCourse && !matchesModule) return false;
      }

      // 5. Section 3 Dropdowns
      if (yearVal && String(b.year) !== String(yearVal)) return false;
      if (batchVal && b.batchId !== batchVal) return false;
      if (courseVal && b.courseName !== courseVal) return false;
      if (modeVal && b.mode !== modeVal) return false;

      return true;
    });
  }

  function applyAndRender() {
    if (!rawData || !rawData.batches) return;

    let runningCount = 0;
    let completedCount = 0;
    let myRunningCount = 0;
    let myCompletedCount = 0;

    rawData.batches.forEach((b) => {
      if (b.isCompleted) {
        if (canViewAll || b.isAssigned) completedCount++;
        if (b.isAssigned) myCompletedCount++;
      } else {
        runningCount++;
        if (b.isAssigned) myRunningCount++;
      }
    });

    const runningBatches = rawData.batches.filter((b) => !b.isCompleted);
    renderContextStats(runningBatches);

    let filtered = getTableFilteredBatches();

    // Ensure batches are ordered with oldest date first
    filtered.sort((a, b) => {
      const tsA = a.startdateTimestamp || 0;
      const tsB = b.startdateTimestamp || 0;
      if (tsA === tsB) return (a.id || 0) - (b.id || 0);
      if (tsA === 0) return 1;
      if (tsB === 0) return -1;
      return tsA - tsB;
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
      let emptyMsg = "No matching batches found.";
      if (currentSubTab === "my_running") {
        emptyMsg = "No running batches currently assigned to you.";
      } else if (currentSubTab === "my_completed") {
        emptyMsg = "No completed batches currently assigned to you.";
      } else if (currentSubTab === "running") {
        emptyMsg = "No running batches found.";
      } else if (currentSubTab === "completed") {
        emptyMsg = "No completed batches found.";
      }
      tbody.innerHTML = `<tr><td colspan="8" class="ba-new-empty">${emptyMsg}</td></tr>`;
      return;
    }

    tbody.innerHTML = items
      .map((b) => {
        const modeLower = (b.mode || "").toLowerCase();
        const modeBadgeClass = modeLower === "online" ? "online" : (modeLower === "offline" ? "offline" : "hybrid");

        const typeRaw = (b.type || "Regular").trim();
        const typeLower = typeRaw.toLowerCase();
        let typeBadgeClass = "";
        if (typeLower === "int") typeBadgeClass = "int";
        else if (typeLower === "wp") typeBadgeClass = "wp";

        let statusHtml = "";
        const delta = typeof b.delayDays === "number" ? b.delayDays : parseInt(b.delayDays, 10) || 0;

        if (b.isCompleted) {
          statusHtml = '<span class="ba-status-pill status-completed"><span class="ba-dot dot-teal"></span> Completed</span>';
        } else if (b.status === "early" || delta < 0) {
          const earlyDays = Math.abs(delta);
          statusHtml = `<span class="ba-status-pill status-early"><span class="ba-dot dot-blue"></span> Early (-${earlyDays}d)</span>`;
        } else if (b.status === "delayed" || delta >= 24) {
          statusHtml = `<span class="ba-status-pill status-delayed"><span class="ba-dot dot-red"></span> Delayed (+${delta}d)</span>`;
        } else if (b.status === "minor_slip" || (delta >= 12 && delta <= 23)) {
          statusHtml = `<span class="ba-status-pill status-minor-slip"><span class="ba-dot dot-amber"></span> Minor slip (+${delta}d)</span>`;
        } else {
          const daysText = delta > 0 ? ` (+${delta}d)` : ` (0d)`;
          statusHtml = `<span class="ba-status-pill status-ok"><span class="ba-dot dot-green"></span> On schedule${daysText}</span>`;
        }

        let batchUrl = "";
        const secId = b.sectionId && b.sectionId > 0 ? b.sectionId : (b.id && b.id > 0 ? b.id : 0);
        const modeParam = b.mode ? `&mode=${encodeURIComponent(b.mode)}` : "";
        if (secId > 0) {
          batchUrl = `batch.php?id=${encodeURIComponent(secId)}${modeParam}`;
        } else {
          batchUrl = `batch.php${modeParam ? '?' + modeParam.substring(1) : ''}`;
        }

        let moduleUrl = "";
        if (secId > 0 && b.moduleIdx && b.courseId) {
          moduleUrl = `module.php?batchid=${encodeURIComponent(secId)}&module=${encodeURIComponent(b.moduleIdx)}&courseid=${encodeURIComponent(b.courseId)}${modeParam}`;
        } else if (secId > 0 && b.moduleIdx) {
          moduleUrl = `module.php?batchid=${encodeURIComponent(secId)}&module=${encodeURIComponent(b.moduleIdx)}${modeParam}`;
        }

        const moduleHtml = moduleUrl
          ? `<a href="${moduleUrl}" class="ba-module-link" title="View Module Analytics">${escapeHtml(b.currentModule || "N/A")}</a>`
          : `<span class="ba-module-text">${escapeHtml(b.currentModule || "N/A")}</span>`;

        return `
          <tr>
            <td class="td-batch-id">
              <a href="${batchUrl}" class="ba-batch-link" title="View Batch Overview">
                ${escapeHtml(b.batchId)}
              </a>
            </td>
            <td class="td-course-name">
              <span class="ba-course-title">${escapeHtml(b.courseName)}</span>
            </td>
            <td><span class="ba-pill-mode ${modeBadgeClass}">${escapeHtml(b.mode)}</span></td>
            <td><span class="ba-pill-type ${typeBadgeClass}">${escapeHtml(typeRaw)}</span></td>
            <td class="td-date">${escapeHtml(b.startDate)}</td>
            <td class="td-module">${moduleHtml}</td>
            <td>${statusHtml}</td>
            <td style="text-align:right;">
              <a href="${batchUrl}" class="ba-btn-view-outline">View Batch</a>
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
        infoEl.textContent = "Showing 0 of 0";
      } else {
        const start = startIndex + 1;
        const end = startIndex + pageItemCount;
        infoEl.textContent = `Showing ${start}–${end} of ${totalItems}`;
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

  /**
   * Setup Mentor List Modal (Class Mentors & Lab Mentors)
   */
  function setupMentorModal() {
    const modal = document.getElementById("ba-mentor-modal");
    const closeBtn = document.getElementById("ba-mentor-modal-close-btn");
    const cancelBtn = document.getElementById("ba-mentor-modal-cancel-btn");
    const searchInput = document.getElementById("ba-mentor-search-input");
    const listEl = document.getElementById("ba-mentor-modal-list");
    const titleEl = document.getElementById("ba-mentor-modal-title");
    const subEl = document.getElementById("ba-mentor-modal-sub");

    if (!modal) return;

    let currentMentorsData = [];

    function openModal(type) {
      const isClass = type === "class";
      const field = isClass ? "classMentors" : "labMentors";
      if (subEl) {
        subEl.textContent = isClass
          ? "Instructors assigned to active running batches"
          : "Lab assistants and mentors assigned to active running batches";
      }

      // Aggregate mentors from running batches
      const mentorMap = new Map();
      const runningBatches = (rawData?.batches || []).filter((b) => !b.isCompleted);
      runningBatches.forEach((b) => {
        const mentors = Array.isArray(b[field]) ? b[field] : [];
        mentors.forEach((m) => {
          const name = String(m || "").trim();
          if (!name) return;
          if (!mentorMap.has(name)) {
            mentorMap.set(name, []);
          }
          mentorMap.get(name).push({
            batchId: b.batchId,
            courseName: b.courseName,
            mode: b.mode
          });
        });
      });

      currentMentorsData = Array.from(mentorMap.entries())
        .map(([name, batches]) => ({ name, batches }))
        .sort((a, b) => a.name.localeCompare(b.name));

      if (titleEl) {
        titleEl.textContent = `${isClass ? "Class Mentors" : "Lab Mentors"} (${currentMentorsData.length})`;
      }
      if (searchInput) searchInput.value = "";
      renderMentorList(currentMentorsData);

      modal.style.display = "flex";
      requestAnimationFrame(() => modal.classList.add("is-open"));
      modal.setAttribute("aria-hidden", "false");
      if (searchInput) searchInput.focus();
    }

    function closeModal() {
      modal.classList.remove("is-open");
      modal.setAttribute("aria-hidden", "true");
      setTimeout(() => {
        modal.style.display = "none";
      }, 200);
    }

    function renderMentorList(mentors) {
      if (!listEl) return;
      if (mentors.length === 0) {
        listEl.innerHTML = `<div class="ba-mentor-empty">No mentors found.</div>`;
        return;
      }

      listEl.innerHTML = mentors
        .map((m) => {
          const initial = (m.name.charAt(0) || "M").toUpperCase();
          const batchPills = m.batches
            .map(
              (b) =>
                `<span class="ba-mentor-batch-chip" title="${escapeHtml(b.courseName || "")} (${escapeHtml(b.mode || "")})">${escapeHtml(b.batchId)}</span>`
            )
            .join("");
          const batchCountText = `${m.batches.length} batch${m.batches.length > 1 ? "es" : ""}`;

          return `
            <div class="ba-mentor-item">
              <div class="ba-mentor-avatar">${escapeHtml(initial)}</div>
              <div class="ba-mentor-info">
                <div class="ba-mentor-name-row">
                  <span class="ba-mentor-name">${escapeHtml(m.name)}</span>
                  <span class="ba-mentor-count-badge">${batchCountText}</span>
                </div>
                <div class="ba-mentor-batches-wrap">
                  ${batchPills}
                </div>
              </div>
            </div>
          `;
        })
        .join("");
    }

    if (searchInput) {
      searchInput.addEventListener("input", () => {
        const q = searchInput.value.toLowerCase().trim();
        const filtered = currentMentorsData.filter(
          (m) =>
            m.name.toLowerCase().includes(q) ||
            m.batches.some(
              (b) =>
                b.batchId.toLowerCase().includes(q) ||
                (b.courseName || "").toLowerCase().includes(q)
            )
        );
        renderMentorList(filtered);
      });
    }

    document.querySelectorAll(".ba-mentor-modal-trigger").forEach((el) => {
      el.addEventListener("click", function () {
        openModal(this.dataset.mentorType || "class");
      });
    });

    if (closeBtn) closeBtn.addEventListener("click", closeModal);
    if (cancelBtn) cancelBtn.addEventListener("click", closeModal);
    modal.addEventListener("click", (e) => {
      if (e.target === modal) closeModal();
    });
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && modal.classList.contains("is-open")) {
        closeModal();
      }
    });
  }

  /**
   * Setup Export to CSV
   */
  function setupExportCsv() {
    const exportBtn = document.getElementById("ba-new-export-btn");
    if (!exportBtn) return;

    exportBtn.addEventListener("click", () => {
      if (!rawData || !rawData.batches) {
        alert("Batch data is not available yet. Please wait.");
        return;
      }
      const batches = getTableFilteredBatches();
      if (!batches || batches.length === 0) {
        alert("No batches found to export for the current tab and filter criteria.");
        return;
      }

      // Sort batches using the exact same chronological order as the dashboard table
      const sortedBatches = [...batches].sort((a, b) => {
        const tsA = a.startdateTimestamp || 0;
        const tsB = b.startdateTimestamp || 0;
        if (tsA === tsB) return (a.id || 0) - (b.id || 0);
        if (tsA === 0) return 1;
        if (tsB === 0) return -1;
        return tsA - tsB;
      });

      const headers = [
        "Batch / Section",
        "Parent Cohort",
        "Course",
        "Mode",
        "Type",
        "Start Date",
        "Current Module",
        "Status",
        "Delay (Days)",
        "Students",
        "Class Mentors",
        "Lab Mentors"
      ];

      const getExportStatus = (b) => {
        if (b.isCompleted) return "Completed";
        const delta = typeof b.delayDays === "number" ? b.delayDays : parseInt(b.delayDays, 10) || 0;
        if (b.status === "early" || delta < 0) {
          return `Early (-${Math.abs(delta)}d)`;
        } else if (b.status === "delayed" || delta >= 24) {
          return `Delayed (+${delta}d)`;
        } else if (b.status === "minor_slip" || (delta >= 12 && delta <= 23)) {
          return `Minor slip (+${delta}d)`;
        } else {
          return delta > 0 ? `On schedule (+${delta}d)` : `On schedule (0d)`;
        }
      };

      const rows = sortedBatches.map((b) => [
        b.batchId || "",
        b.parentBatchName || "",
        b.courseName || "",
        b.mode || "",
        b.type || "",
        b.startDate || "",
        b.currentModule || "",
        getExportStatus(b),
        b.delayDays ?? 0,
        b.studentCount ?? 0,
        Array.isArray(b.classMentors) ? b.classMentors.join("; ") : "",
        Array.isArray(b.labMentors) ? b.labMentors.join("; ") : ""
      ]);

      const csvLines = [
        headers.map((h) => `"${String(h).replace(/"/g, '""')}"`).join(","),
        ...rows.map((r) =>
          r.map((cell) => `"${String(cell ?? "").replace(/"/g, '""')}"`).join(",")
        )
      ];
      const csvString = csvLines.join("\r\n");

      // Use Blob with UTF-8 BOM so Excel and browsers parse correctly without URL length limits or truncation
      const blob = new Blob(["\uFEFF" + csvString], { type: "text/csv;charset=utf-8;" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      const dateStr = new Date().toISOString().slice(0, 10);
      link.href = url;
      link.download = `batch_analytics_${currentSubTab}_${dateStr}.csv`;
      document.body.appendChild(link);
      link.click();
      setTimeout(() => {
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
      }, 150);
    });
  }
}
