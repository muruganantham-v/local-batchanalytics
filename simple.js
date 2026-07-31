console.log("Batch Analytics - Index page");

let BATCH_DATA = null;
let CURRENT_COURSE = null;
let CRM_CACHE = {};
let PTF_CACHE = {};
let PTF_PENDING = {};
let MENTOR_DETAILS_CACHE = {};
let MENTOR_DETAILS_PENDING = {};
let OVERVIEW_SELECTED_COURSES = [];
let MAAC_SUMMARY_CACHE = {};
let MAAC_SUMMARY_PENDING = {};
let MAAC_COURSE_CACHE = {};
let MAAC_COURSE_PENDING = {};
let COURSE_SUMMARY_MODAL = null;
let COURSE_SUMMARY_ESCAPE_HANDLER = null;
let COURSE_FILTER_COLLAPSED_GROUPS = {};
let COURSE_FILTER_COLUMN_WIDTHS = {};

document.addEventListener("DOMContentLoaded", function () {
  const searchBox = document.getElementById("ba-search");
  const searchBtn = document.getElementById("ba-search-btn");
  const batchSelect = document.getElementById("ba-batch");
  const tabsWrapper = document.getElementById("ba-tabs-wrapper");
  const batchTabs = document.getElementById("batchTabs");
  const batchTabsContent = document.getElementById("batchTabsContent");

  if (!searchBox || !searchBtn || !batchSelect) {
    return;
  }

  const BASE_URL = window.location.href.split("?")[0];

  function showToast(message, type = "info") {
    const container = document.getElementById("ba-toast-container");
    if (!container) {
      return;
    }

    const toast = document.createElement("div");
    toast.className = `ba-toast ba-toast-${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function renderTrophyIcon() {
    return `<svg class="ba-trophy-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path><path d="M4 22h16"></path><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"></path><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"></path><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"></path></svg>`;
  }
  function extractCourseGroupLabel(fullname, shortname) {
    const source = (fullname && fullname.includes(":") ? fullname : shortname || fullname || "").trim();
    if (!source) {
      return "";
    }

    const parts = source
      .split(":")
      .map((part) => part.trim())
      .filter((part) => part.length > 0);

    return parts.length ? parts[parts.length - 1] : "";
  }

  function clampResizableColumnWidth(width) {
    return Math.max(120, Math.min(270, Math.round(width)));
  }

  function getCourseFilterColumnWidth(courseId, key, fallback = 170) {
    return clampResizableColumnWidth(COURSE_FILTER_COLUMN_WIDTHS[`${courseId}:${key}`] || fallback);
  }

  function setCourseFilterColumnWidth(courseId, key, width) {
    COURSE_FILTER_COLUMN_WIDTHS[`${courseId}:${key}`] = clampResizableColumnWidth(width);
  }

  function renderResizableColumnHeader({
    label,
    columnKey,
    columnIndex,
    courseId,
    sortable = false,
    sortIndex = 0,
    numericSort = false,
    rowSpan = null,
    className = "",
  }) {
    const classes = [className, sortable ? "sortable" : "", "ba-resizable-header"]
      .filter(Boolean)
      .join(" ");
    const sortAttr = sortable ? ` onclick="sortTable(this, ${sortIndex}, ${numericSort})"` : "";
    const rowSpanAttr = rowSpan ? ` rowspan="${rowSpan}"` : "";

    return `<th class="${classes}" data-col-index="${columnIndex}" data-col-key="${escapeHtml(
      columnKey,
    )}" data-courseid="${courseId}"${rowSpanAttr}${sortAttr}>
      <div class="ba-maac-head-shell">
        <span class="ba-maac-col-title">${escapeHtml(label)}</span>
      </div>
      <span class="ba-column-resizer" data-column-resizer="1" aria-hidden="true"></span>
    </th>`;
  }

  function renderCourseGroupHeader({ label, courseId, groupKey, colSpan }) {
    const safeGroupKey = JSON.stringify(groupKey);
    return `<th colspan="${colSpan}" class="ba-maac-group-head">
      <div class="ba-maac-group-shell">
        <button type="button" class="ba-maac-group-toggle" onclick='toggleCourseFilterGroup(${courseId}, ${safeGroupKey})'>
          <span class="ba-maac-group-toggle-icon" aria-hidden="true">-</span>
          <span class="ba-maac-group-toggle-label">${escapeHtml(label)}</span>
        </button>
      </div>
    </th>`;
  }

  function bindTableColumnResizers(table, onResize) {
    if (!table) {
      return;
    }

    const columns = Array.from(table.querySelectorAll("colgroup col"));
    table.querySelectorAll("[data-column-resizer]").forEach((handle) => {
      if (handle.dataset.bound === "1") {
        return;
      }

      handle.dataset.bound = "1";
      handle.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
      });
      handle.addEventListener("mousedown", (event) => {
        event.preventDefault();
        event.stopPropagation();
        const header = handle.closest("[data-col-index]");
        if (!header) {
          return;
        }

        const index = parseInt(header.dataset.colIndex || "-1", 10);
        const col = columns[index];
        if (!col) {
          return;
        }

        const startX = event.clientX;
        const startWidth = col.getBoundingClientRect().width;
        const columnKey = header.dataset.colKey || "";
        const onMouseMove = (moveEvent) => {
          const width = clampResizableColumnWidth(startWidth + moveEvent.clientX - startX);
          col.style.width = `${width}px`;
          if (typeof onResize === "function") {
            onResize(columnKey, width);
          }
        };
        const onMouseUp = () => {
          document.removeEventListener("mousemove", onMouseMove);
          document.removeEventListener("mouseup", onMouseUp);
        };

        document.addEventListener("mousemove", onMouseMove);
        document.addEventListener("mouseup", onMouseUp);
      });
    });
  }

  function enableCourseFilterColumnResize(courseId) {
    const table = document.querySelector(".ba-grouped-filter-table");
    if (!table) {
      return;
    }

    bindTableColumnResizers(table, (columnKey, width) => {
      setCourseFilterColumnWidth(courseId, columnKey, width);
    });
  }

  // ==================== CAPABILITY FLAGS ====================
  const wrapEl = document.querySelector(".local-batchanalytics-wrap");
  const BA_CAN_MANAGE = wrapEl ? wrapEl.dataset.canManage === "1" : false;
  const BA_CAN_VIEW_ALL_COURSES = wrapEl ? wrapEl.dataset.canViewAllCourses === "1" : false;
  const BA_CRM_FIELDS = wrapEl ? JSON.parse(wrapEl.dataset.crmFields || "[]") : [];
  const BA_MENTOR_CRM_FIELDS = wrapEl ? JSON.parse(wrapEl.dataset.mentorCrmFields || "[]") : [];
  const BA_MENTOR_CRM_GROUPS = wrapEl ? JSON.parse(wrapEl.dataset.mentorCrmGroups || "[]") : [];
  const BA_RESTRICTED = BA_CRM_FIELDS.filter((f) => f.restricted).map((f) => f.key);
  const BA_SESSKEY = wrapEl ? (wrapEl.dataset.sesskey || "") : "";

  // ==================== BATCH LOADING ====================
  let ALL_BATCHES = []; // [{code, count}, ...] - used for teacher client-side filtering

  function populateDropdown(batches) {
    batchSelect.innerHTML = '<option value="">-- Select a Batch --</option>';
    batches.forEach((b) => {
      const opt = document.createElement("option");
      opt.value = b.code;
      opt.textContent = `${b.code} (${b.count} courses)`;
      batchSelect.appendChild(opt);
    });
  }

  function batchMatchesKeyword(batch, keyword) {
    const haystack = `${batch.code || ""} ${batch.searchtext || ""}`.toLowerCase();
    return haystack.includes(keyword);
  }

  document.addEventListener("click", (event) => {
    const categoryTrigger = event.target.closest("[data-category-modal]");
    if (categoryTrigger) {
      openCategoryModal(categoryTrigger.dataset.courseId, categoryTrigger.dataset.categoryName || "");
      return;
    }

    const donutTrigger = event.target.closest("[data-donut-modal]");
    if (donutTrigger) {
      openDonutModal(donutTrigger.dataset.courseId, donutTrigger.dataset.categoryName || "");
      return;
    }

    const feedbackTrigger = event.target.closest("[data-course-feedback]");
    if (feedbackTrigger) {
      openCourseFeedbackModal(
        feedbackTrigger.dataset.courseId,
        feedbackTrigger.dataset.studentId,
        feedbackTrigger.dataset.columnKey || "",
      );
      return;
    }

    const summaryTrigger = event.target.closest("[data-course-summary]");
    if (summaryTrigger) {
      openCourseSummaryModal(summaryTrigger.dataset.courseId);
    }
  });

  if (!BA_CAN_VIEW_ALL_COURSES) {
    // Teachers: auto-populate dropdown on page load, search filters client-side.
    (async function loadAllBatches() {
      try {
        const res = await fetch(BASE_URL + "?action=getallbatches");
        if (!res.ok) throw new Error("Network error");
        const data = await res.json();
        ALL_BATCHES = data.batches || [];
        populateDropdown(ALL_BATCHES);
        if (ALL_BATCHES.length > 0) {
          showToast(`${ALL_BATCHES.length} batch groups loaded`, "success");
        }
      } catch (e) {
        console.error(e);
        showToast("Failed to load batch groups", "error");
      }
    })();

    searchBtn.addEventListener("click", () => {
      const k = searchBox.value.trim().toLowerCase();
      if (k.length === 0) {
        populateDropdown(ALL_BATCHES);
        showToast(`Showing all ${ALL_BATCHES.length} batch groups`, "info");
        return;
      }
      const filtered = ALL_BATCHES.filter((b) => batchMatchesKeyword(b, k));
      populateDropdown(filtered);
      if (filtered.length === 0) {
        showToast("No matching batches", "warn");
      } else {
        showToast(`Found ${filtered.length} batch groups`, "success");
      }
    });

    searchBox.addEventListener("keyup", (e) => {
      const k = searchBox.value.trim().toLowerCase();
      if (k.length === 0) return populateDropdown(ALL_BATCHES);
      const filtered = ALL_BATCHES.filter((b) => batchMatchesKeyword(b, k));
      populateDropdown(filtered);
    });
  } else {
    // Managers: search-based flow - type keyword, click search, populate dropdown from API.
    searchBtn.addEventListener("click", async () => {
      const k = searchBox.value.trim();
      if (k.length < 1) return showToast("Enter keyword", "warn");

      showToast("Searching...", "info");
      try {
        const res = await fetch(
          BASE_URL + "?action=searchcourses&keyword=" + encodeURIComponent(k),
        );
        if (!res.ok) throw new Error("Network error");

        const data = await res.json();
        if (!data.courses || !data.courses.length) {
          batchSelect.innerHTML = '<option value="">-- No batches found --</option>';
          return showToast("No batches found", "warn");
        }

        const batches = {};
        data.courses.forEach((c) => {
          const b = extractCourseGroupLabel(c.fullname || "", c.shortname || "");
          if (!b) return;
          if (!batches[b]) batches[b] = { code: b, count: 0, searchtext: "" };
          batches[b].count++;
          batches[b].searchtext += ` ${c.fullname || ""} ${c.shortname || ""}`;
        });

        populateDropdown(
          Object.values(batches).sort((a, b) => a.code.localeCompare(b.code))
        );
        showToast(`Found ${data.courses.length} courses`, "success");
      } catch (e) {
        console.error(e);
        showToast("Error searching", "error");
      }
    });

    searchBox.addEventListener("keyup", (e) => {
      if (e.key === "Enter") searchBtn.click();
    });
  }

  // ==================== BATCH SELECTION ====================
  batchSelect.addEventListener("change", async function () {
    if (!this.value) {
      tabsWrapper.style.display = "none";
      return;
    }

    showToast("Loading Data...", "info");

    // Show Loading Skeleton immediately while waiting for network
    tabsWrapper.style.display = "block";
    batchTabs.innerHTML = `<li class="active ba-skeleton-pulse" style="width:150px; height:20px; border:none; margin-top:2px;"></li>`;
    batchTabsContent.innerHTML = `
      <div class="ba-skeleton-wrapper">
        <div class="ba-skeleton-pulse ba-skel-header"></div>
        <div class="ba-skel-grid">
          <div class="ba-skeleton-pulse ba-skel-card"></div>
          <div class="ba-skeleton-pulse ba-skel-card"></div>
          <div class="ba-skeleton-pulse ba-skel-card"></div>
          <div class="ba-skeleton-pulse ba-skel-card"></div>
        </div>
        <div class="ba-skeleton-pulse ba-skel-header" style="width:180px; margin-top:20px;"></div>
        <div class="ba-skel-grid">
          <div class="ba-skeleton-pulse ba-skel-card" style="height:250px;"></div>
          <div class="ba-skeleton-pulse ba-skel-card" style="height:250px;"></div>
        </div>
      </div>
    `;

    try {
      const res = await fetch(
        BASE_URL +
        "?action=getbatchfulldata&batchcode=" +
        encodeURIComponent(this.value),
      );
      if (!res.ok) throw new Error("Network error");

      BATCH_DATA = await res.json();

      // CRM data is lazy-loaded via fetchUnifiedData when tabs are rendered.
      // Kick off CRM fetch in the background so data is ready when user opens CRM tab.
      if (BATCH_DATA && BATCH_DATA.uniqueStudents) {
        const allUsers = BATCH_DATA.uniqueStudents.map(s => s.username);
        fetchUnifiedData(allUsers);
      }

      OVERVIEW_SELECTED_COURSES = BATCH_DATA.courses.map((c) => c.courseid);
      buildTabs();
      renderOverview();
      tabsWrapper.style.display = "block";
      showToast("Data Loaded Successfully", "success");
    } catch (e) {
      console.error(e);
      showToast("Error loading batch data", "error");
    }
  });

  function buildTabs() {
    batchTabs.innerHTML = `
        <li class="active" onclick="switchTab('overview', this)">Batch Overview</li>
        <li onclick="switchTab('ptf', this)">CRM Data</li>
        <li data-tab="mentor" onclick="switchTab('mentor', this)">Mentor Details</li>
    `;
    BATCH_DATA.courses.forEach((c) => {
      const li = document.createElement("li");
      const cleanName = c.coursename.split(":")[0].trim();
      li.textContent = cleanName;
      li.dataset.tab = `course-${c.courseid}`;
      li.onclick = () => switchTab(c.courseid, li);
      batchTabs.appendChild(li);
    });
  }

  window.switchTab = function (id, tabEl) {
    document
      .querySelectorAll(".ba-tabs-nav li")
      .forEach((l) => l.classList.remove("active"));
    tabEl.classList.add("active");

    if (id === "overview") renderOverview();
    else if (id === "ptf") renderPtf();
    else if (id === "mentor") renderMentorDetails();
    else renderCourse(id);
  };

  window.triggerCourseTab = function (cid) {
    const tab = document.querySelector(
      `.ba-tabs-nav li[data-tab="course-${cid}"]`,
    );
    if (tab) {
      tab.click();
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  };

  // ==================== GLOBAL CRM COLUMNS MAP ====================
  // Built dynamically from admin config (data-crm-fields attribute).
  const PTF_COLS_ALL = BA_CRM_FIELDS.map((f) => ({
    h: f.label,
    k: f.key,
    num: f.numeric,
    type: f.type || "text",
  }));

  // Filter columns based on user capability - restricted fields are hidden for view-only users.
  const PTF_COLS = BA_CAN_MANAGE
    ? PTF_COLS_ALL
    : PTF_COLS_ALL.filter((c) => !BA_RESTRICTED.includes(c.k));

  // ==================== UNIFIED DATA FETCHER ====================

  async function fetchUnifiedData(users) {
    const uncached = [...new Set(users)].filter((u) => !PTF_CACHE[u] && !PTF_PENDING[u]);
    if (uncached.length === 0) return;

    // Batch fetch in chunks of 20.
    const chunkSize = 20;
    for (let i = 0; i < uncached.length; i += chunkSize) {
      const chunk = uncached.slice(i, i + chunkSize);
      const request = fetch(BASE_URL + "?action=getptfdata", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "sesskey=" + encodeURIComponent(BA_SESSKEY) + "&payload=" + encodeURIComponent(JSON.stringify({ usernames: chunk }))
      })
        .then((res) => res.json())
        .then((result) => {
          if (!(result && result.students)) {
            return;
          }

          result.students.forEach((data) => {
            const u = data.username;
            PTF_CACHE[u] = data.data ? { ...data.data, placed_company: data.placed_company, CTC: data.CTC } : data;
            CRM_CACHE[u] = data.placed_company || "Not Placed";

            const uid = u.replace(/[^a-z0-9]/gi, "");
            const ptfRow = document.getElementById(`tr-${uid}`);
            if (ptfRow) renderPtfRowContent(ptfRow, PTF_CACHE[u]);
          });

          if (window.updatePtfFilterOptions) window.updatePtfFilterOptions();
          if (window.applyPtfFilters && document.getElementById("ptf-f-search")) {
            window.applyPtfFilters();
          }

          if (CURRENT_COURSE) {
            const statusFilter = document.getElementById("f-status");
            if (statusFilter && statusFilter.value) applyFilters();
          }
        })
        .catch((e) => {
          console.error("Batch fetch error for chunk", chunk, e);
        })
        .finally(() => {
          chunk.forEach((username) => {
            delete PTF_PENDING[username];
          });
        });

      chunk.forEach((username) => {
        PTF_PENDING[username] = request;
      });
      await request;
    }
  }


  // ==================== MENTOR DETAILS TAB ====================
  function getMentorFieldMap(mentorfields) {
    const map = {};
    (mentorfields || []).forEach((field) => {
      if (field && field.key) {
        map[field.key] = field;
      }
    });
    return map;
  }

  function normaliseMentorGroups(mentorfields, mentorgroups) {
    const availableKeys = new Set((mentorfields || []).map((field) => field.key).filter(Boolean));
    const validGroups = (mentorgroups || [])
      .map((group) => ({
        title: group.title || "Mentor Details",
        fields: (group.fields || []).filter((key) => availableKeys.has(key)),
      }))
      .filter((group) => group.fields.length > 0);

    if (validGroups.length > 0) {
      return validGroups;
    }

    const fieldKeys = Array.from(availableKeys);
    return fieldKeys.length ? [{ title: "Mentor Details", fields: fieldKeys }] : [];
  }

  function formatMentorValue(value, field) {
    if (value === null || value === undefined || value === "") {
      return "-";
    }
    if (field && field.type === "lookup" && typeof value === "object") {
      return value.name || value.full_name || value.id || "-";
    }
    if (field && field.type === "date") {
      return formatCrmDate(value);
    }
    if (Array.isArray(value)) {
      return value.map((item) => formatMentorValue(item, field)).join(", ");
    }
    if (typeof value === "object") {
      return value.name || value.full_name || JSON.stringify(value);
    }
    return String(value);
  }

  async function fetchMentorDetails(batchgroup) {
    if (!batchgroup) {
      return null;
    }
    if (MENTOR_DETAILS_CACHE[batchgroup]) {
      return MENTOR_DETAILS_CACHE[batchgroup];
    }
    if (MENTOR_DETAILS_PENDING[batchgroup]) {
      return MENTOR_DETAILS_PENDING[batchgroup];
    }

    const request = fetch(BASE_URL + "?action=getmentordetails", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: "sesskey=" + encodeURIComponent(BA_SESSKEY) + "&batchgroup=" + encodeURIComponent(batchgroup),
    })
      .then((res) => res.json())
      .then((mentorresponse) => {
        MENTOR_DETAILS_CACHE[batchgroup] = mentorresponse;
        return mentorresponse;
      })
      .catch((error) => {
        console.error("Mentor CRM fetch error", error);
        return { error: "Unable to fetch mentor details" };
      })
      .finally(() => {
        delete MENTOR_DETAILS_PENDING[batchgroup];
      });

    MENTOR_DETAILS_PENDING[batchgroup] = request;
    return request;
  }

  function renderMentorDetails() {
    const batchgroup = BATCH_DATA?.batchcode || batchSelect.value || "";
    const cached = batchgroup ? MENTOR_DETAILS_CACHE[batchgroup] : null;
    const mentorfields = cached?.mentorfields || cached?.fields || BA_MENTOR_CRM_FIELDS || [];
    const mentorgroups = cached?.mentorgroups || cached?.groups || BA_MENTOR_CRM_GROUPS || [];
    const mentorrecord = cached?.mentorrecord || cached?.data || {};
    const mentorfieldmap = getMentorFieldMap(mentorfields);
    const displayGroups = normaliseMentorGroups(mentorfields, mentorgroups);

    let bodyHtml = "";
    if (!mentorfields.length) {
      bodyHtml = `<div class="ba-empty-state">Mentor CRM fields are not configured.</div>`;
    } else if (cached?.error) {
      bodyHtml = `<div class="ba-empty-state">${escapeHtml(cached.error)}</div>`;
    } else if (!cached) {
      bodyHtml = `<div class="ba-empty-state">Loading mentor details...</div>`;
    } else if (!Object.keys(mentorrecord).length) {
      bodyHtml = `<div class="ba-empty-state">No mentor details found for batch ${escapeHtml(batchgroup)}.</div>`;
    } else {
      bodyHtml = displayGroups.map((group) => {
        const rows = group.fields.map((key) => {
          const mentorfield = mentorfieldmap[key] || { key, label: key, type: "text" };
          return `<div class="ba-mentor-field-row">
            <div class="ba-mentor-field-label">${escapeHtml(mentorfield.label || key)}</div>
            <div class="ba-mentor-field-value">${escapeHtml(formatMentorValue(mentorrecord[key], mentorfield))}</div>
          </div>`;
        }).join("");
        return `<section class="ba-mentor-group">
          <h4>${escapeHtml(group.title)}</h4>
          <div class="ba-mentor-grid">${rows}</div>
        </section>`;
      }).join("");
    }

    batchTabsContent.innerHTML = `
      <div class="ba-mentor-details-section">
        <div class="ba-filter-header">
          <div>
            <h3>Mentor Details</h3>
            <p class="ba-mentor-subtitle">Batch : ${escapeHtml(batchgroup || "-")}</p>
          </div>
        </div>
        ${bodyHtml}
      </div>`;

    if (batchgroup && !cached) {
      fetchMentorDetails(batchgroup).then(() => {
        const activeTab = document.querySelector('.ba-tabs-nav li.active');
        if (activeTab && activeTab.dataset.tab === "mentor") {
          renderMentorDetails();
        }
      });
    }
  }

  // ==================== PTF DATA TAB (Redesigned with Sidebar Filter) ====================
  function renderPtf() {
    const students = BATCH_DATA.uniqueStudents || [];

    // Build Sortable Header Row
    let thead = `<th class="sortable" onclick="sortTable(this, 0, false)" style="position: sticky; left: 0; background: #f1f5f9; z-index: 1001; border-right: 2px solid #e2e8f0;">Student Name</th>`;
    PTF_COLS.forEach((c, i) => {
      thead += `<th class="sortable" onclick="sortTable(this, ${i + 1}, ${c.num})">${c.h}</th>`;
    });

    // Build Initial Body Rows
    let tbody = "";
    let toFetch = [];

    students.forEach((s) => {
      const u = s.username;
      const uid = u.replace(/[^a-z0-9]/gi, "");

      tbody += `<tr id="tr-${uid}">`;
      tbody += `<td style="position:sticky;left:0;background:#fff;z-index:2;border-right:1px solid #eee;">
                      <b>${s.fullname}</b><br><small style="color:#888">${u}</small>
                    </td>`;

      if (!PTF_CACHE[u]) toFetch.push(u);

      PTF_COLS.forEach(() => (tbody += `<td class="ptf-load">...</td>`));
      tbody += `</tr>`;
    });

    // Helper function for building range inputs
    const rangeHtml = (lbl, id, min, max, step) => `
        <div class="ba-filter-item-modern">
            <div class="ba-filter-header-row"><span class="ba-filter-label">${lbl}</span></div>
            <div class="ba-range-wrapper">
                <input type="number" class="ba-range-input" id="f-${id}-min" value="${min}" min="${min}" max="${max}" step="${step}" onchange="window.applyPtfFilters()" placeholder="Min">
                <span class="ba-range-divider">-</span>
                <input type="number" class="ba-range-input" id="f-${id}-max" value="${max}" min="${min}" max="${max}" step="${step}" onchange="window.applyPtfFilters()" placeholder="Max">
            </div>
        </div>`;

    const html = `
      <div class="ba-filter-section" style="margin-top:0;">
          <div class="ba-filter-header">
              <h3>CRM Data & Advanced Filter</h3>
              <button class="ba-btn ba-btn-success" onclick="exportTable('ptf-table', 'CRM_Data_Export')">Export CSV</button>
          </div>
          <div class="ba-filter-body">

              <div class="ba-filter-sidebar">
                  <div class="ba-sidebar-head">Filters <span onclick="window.resetPtfFilters()" style="cursor:pointer;color:var(--primary);font-size:12px;font-weight:600;">Reset All</span></div>

                  <div class="ba-filter-item-modern">
                      <div class="ba-filter-header-row"><span class="ba-filter-label">Search Name/ID</span></div>
                      <input type="text" id="ptf-f-search" class="ba-range-input" style="width:100%" placeholder="Search..." onkeyup="window.applyPtfFilters()">
                  </div>

                  ${BA_CAN_MANAGE ? `<div class="ba-filter-item-modern">
                      <div class="ba-filter-header-row"><span class="ba-filter-label">Placement Status</span></div>
                      <select id="ptf-f-placement" class="ba-select-small" style="width:100%" onchange="window.applyPtfFilters()">
                          <option value="">All</option>
                          <option value="Placed">Placed</option>
                          <option value="Not Placed">Not Placed</option>
                      </select>
                  </div>` : '<div id="ptf-f-placement-placeholder"></div>'}

                  <div class="ba-filter-item-modern">
                      <div class="ba-filter-header-row"><span class="ba-filter-label">PET Status</span></div>
                      <select id="ptf-f-pet" class="ba-select-small" style="width:100%" onchange="window.applyPtfFilters()">
                          <option value="">All</option>
                          </select>
                  </div>

                  ${rangeHtml("MAAC Rating", "maac", 0, 10, 0.1)}
                  ${rangeHtml("Adv C Mock Score", "advc", 0, 10, 1)}
                  ${rangeHtml("MC Mock Score", "mc", 0, 10, 1)}
                  ${!BA_RESTRICTED.includes("Total_Applied") ? rangeHtml("Total Applied", "applied", 0, 20, 1) : ""}
                  ${!BA_RESTRICTED.includes("Total_Shortlisted") ? rangeHtml("Total Shortlisted", "shortlisted", 0, 20, 1) : ""}

                  ${!BA_RESTRICTED.includes("BE_BTech_YoP") && !BA_RESTRICTED.includes("ME_MTech_YoP") ? `
                  <div class="ba-filter-item-modern">
                      <div class="ba-filter-header-row"><span class="ba-filter-label">Year of Passing (YOP)</span></div>
                      <div id="ptf-f-yop-list" style="max-height:120px; overflow-y:auto; border:1px solid #eee; padding:8px; border-radius:4px; font-size:12px;">
                          <span style="color:#999">Loading CRM Data...</span>
                      </div>
                  </div>` : ""}

                  ${!BA_RESTRICTED.includes("Home_State") ? `
                  <div class="ba-filter-item-modern">
                      <div class="ba-filter-header-row"><span class="ba-filter-label">Home State</span></div>
                      <div id="ptf-f-state-list" style="max-height:150px; overflow-y:auto; border:1px solid #eee; padding:8px; border-radius:4px; font-size:12px;">
                          <span style="color:#999">Loading CRM Data...</span>
                      </div>
                  </div>` : ""}
              </div>

              <div class="ba-filter-main">
                  <div class="ba-table-controls">
                      <span id="ptf-f-count" style="font-weight:600; color:var(--text-gray);">Loading students...</span>
                  </div>
                  <div class="ba-table-wrap">
                      <table class="ba-table" id="ptf-table">
                          <thead><tr>${thead}</tr></thead>
                          <tbody id="ptf-body">${tbody}</tbody>
                      </table>
                  </div>
              </div>
          </div>
      </div>
      `;

    batchTabsContent.innerHTML = html;

    // Render Cached Rows Immediately
    students.forEach((s) => {
      const u = s.username;
      if (PTF_CACHE[u]) {
        const uid = u.replace(/[^a-z0-9]/gi, "");
        const tr = document.getElementById(`tr-${uid}`);
        if (tr) renderPtfRowContent(tr, PTF_CACHE[u]);
      }
    });

    // Populate dropdowns and apply filters
    if (window.updatePtfFilterOptions) window.updatePtfFilterOptions();
    if (window.applyPtfFilters) window.applyPtfFilters();
    if (toFetch.length > 0) fetchUnifiedData(toFetch);
  }

  // Format a Zoho date string (YYYY-MM-DD) to a readable local date.
  function formatCrmDate(val) {
    if (!val || val === "-") return "-";
    const d = new Date(val);
    return isNaN(d.getTime()) ? val : d.toLocaleDateString();
  }

  // Helper: Render Row Content
  window.renderPtfRowContent = function (tr, data) {
    const company = data.placed_company || "Not Placed";
    const isPlaced =
      company !== "Not Placed" &&
      company !== "Checking..." &&
      company !== "Error";

    const statusHtml = isPlaced
      ? `<span class="ba-status-badge status-placed">Placed</span>`
      : `<span class="ba-status-badge status-not-placed">Not Placed</span>`;

    // Keep Name Cell (First child), Remove the rest to refresh them cleanly
    while (tr.children.length > 1) tr.removeChild(tr.lastChild);

    PTF_COLS.forEach((c) => {
      const td = document.createElement("td");

      if (c.k === "CALC_STATUS") td.innerHTML = statusHtml;
      else if (c.type === "lookup") td.textContent = (data[c.k] && data[c.k].name) ? data[c.k].name : (data[c.k] || "-");
      else if (c.type === "date") td.textContent = data[c.k] ? formatCrmDate(data[c.k]) : "-";
      else td.textContent = data[c.k] || "-";

      tr.appendChild(td);
    });
  };

  // ==================== NEW CRM FILTER LOGIC ====================
  // Generates Dynamic Checkboxes & Selects based on Live Data
  window.updatePtfFilterOptions = function () {
    const yopSet = new Set();
    const stateSet = new Set();
    const petSet = new Set();

    Object.values(PTF_CACHE).forEach((d) => {
      if (d.BE_BTech_YoP && d.BE_BTech_YoP !== "-") yopSet.add(d.BE_BTech_YoP);
      if (d.ME_MTech_YoP && d.ME_MTech_YoP !== "-") yopSet.add(d.ME_MTech_YoP);
      if (d.Home_State && d.Home_State !== "-") stateSet.add(d.Home_State);
      if (d.Placement_Eli && d.Placement_Eli !== "-")
        petSet.add(d.Placement_Eli);
    });

    // Helper to render checkbox lists preserving checked state
    const renderCbList = (id, items, cls) => {
      const container = document.getElementById(id);
      if (!container) return;
      const checked = Array.from(
        container.querySelectorAll("input:checked"),
      ).map((cb) => cb.value);
      let html = "";
      items.forEach((item) => {
        const isChecked = checked.includes(item) ? "checked" : "";
        html += `<label style="display:flex; gap:8px; align-items:center; cursor:pointer; margin-bottom:5px;"><input type="checkbox" value="${item}" class="${cls}" onchange="window.applyPtfFilters()" ${isChecked}> ${item}</label>`;
      });
      container.innerHTML =
        html || '<span style="color:#999">No data found</span>';
    };

    renderCbList("ptf-f-yop-list", Array.from(yopSet).sort(), "cb-yop");
    renderCbList("ptf-f-state-list", Array.from(stateSet).sort(), "cb-state");

    // Update PET Dropdown
    const petSelect = document.getElementById("ptf-f-pet");
    if (petSelect) {
      const currentVal = petSelect.value;
      let opts = '<option value="">All</option>';
      Array.from(petSet)
        .sort()
        .forEach((p) => (opts += `<option value="${p}">${p}</option>`));
      petSelect.innerHTML = opts;
      petSelect.value = currentVal;
    }
  };

  // Applies the CRM Sidebar filters to hide/show rows dynamically
  window.applyPtfFilters = function () {
    const search = document.getElementById("ptf-f-search").value.toLowerCase();
    const placement = document.getElementById("ptf-f-placement")?.value || "";
    const pet = document.getElementById("ptf-f-pet").value;

    const maacMin =
      parseFloat(document.getElementById("f-maac-min").value) || 0;
    const maacMax =
      parseFloat(document.getElementById("f-maac-max").value) || 10;
    const advcMin =
      parseFloat(document.getElementById("f-advc-min").value) || 0;
    const advcMax =
      parseFloat(document.getElementById("f-advc-max").value) || 100;
    const mcMin = parseFloat(document.getElementById("f-mc-min").value) || 0;
    const mcMax = parseFloat(document.getElementById("f-mc-max").value) || 100;
    const appMinEl = document.getElementById("f-applied-min");
    const appMin = appMinEl ? parseFloat(appMinEl.value) || 0 : 0;
    const appMaxEl = document.getElementById("f-applied-max");
    const appMax = appMaxEl ? parseFloat(appMaxEl.value) || 500 : 999999;
    const shortMinEl = document.getElementById("f-shortlisted-min");
    const shortMin = shortMinEl ? parseFloat(shortMinEl.value) || 0 : 0;
    const shortMaxEl = document.getElementById("f-shortlisted-max");
    const shortMax = shortMaxEl ? parseFloat(shortMaxEl.value) || 500 : 999999;

    const yopChecked = Array.from(
      document.querySelectorAll(".cb-yop:checked"),
    ).map((cb) => cb.value);
    const stateChecked = Array.from(
      document.querySelectorAll(".cb-state:checked"),
    ).map((cb) => cb.value);

    let matchCount = 0;

    BATCH_DATA.uniqueStudents.forEach((s) => {
      const uid = s.username.replace(/[^a-z0-9]/gi, "");
      const tr = document.getElementById(`tr-${uid}`);
      if (!tr) return;

      let show = true;

      if (
        search &&
        !s.fullname.toLowerCase().includes(search) &&
        !s.username.toLowerCase().includes(search)
      )
        show = false;

      if (show && PTF_CACHE[s.username]) {
        const data = PTF_CACHE[s.username];

        const company = data.placed_company || "Not Placed";
        const isPlaced =
          company !== "Not Placed" &&
          company !== "Checking..." &&
          company !== "Error";
        const actualPlacement = isPlaced ? "Placed" : "Not Placed";
        if (placement && placement !== actualPlacement) show = false;

        if (show && pet && data.Placement_Eli !== pet) show = false;

        const maac = parseFloat(data.MAAC_Rating) || 0;
        if (show && (maac < maacMin || maac > maacMax)) show = false;

        const advc = parseFloat(data.Advanced_C_Score) || 0;
        if (show && (advc < advcMin || advc > advcMax)) show = false;

        const mc = parseFloat(data.MC_Mock_Score3) || 0;
        if (show && (mc < mcMin || mc > mcMax)) show = false;

        const applied = parseFloat(data.Total_Applied) || 0;
        if (show && (applied < appMin || applied > appMax)) show = false;

        const short = parseFloat(data.Total_Shortlisted) || 0;
        if (show && (short < shortMin || short > shortMax)) show = false;

        if (show && yopChecked.length > 0) {
          if (
            !yopChecked.includes(data.BE_BTech_YoP) &&
            !yopChecked.includes(data.ME_MTech_YoP)
          )
            show = false;
        }

        if (show && stateChecked.length > 0) {
          if (!stateChecked.includes(data.Home_State)) show = false;
        }
      } else if (show && !PTF_CACHE[s.username]) {
        // Hide rows that are still loading if any strict filter is applied
        if (
          placement ||
          pet ||
          yopChecked.length > 0 ||
          stateChecked.length > 0 ||
          maacMin > 0 ||
          advcMin > 0 ||
          mcMin > 0 ||
          appMin > 0 ||
          shortMin > 0
        ) {
          show = false;
        }
      }

      tr.style.display = show ? "" : "none";
      if (show) matchCount++;
    });

    const countEl = document.getElementById("ptf-f-count");
    if (countEl)
      countEl.innerText = `Showing ${matchCount} of ${BATCH_DATA.uniqueStudents.length} Students`;

    // Reset Table Sort Arrows when filter changes
    const table = document.getElementById("ptf-table");
    if (table)
      table
        .querySelectorAll("th")
        .forEach((th) => th.classList.remove("sort-asc", "sort-desc"));
  };

  window.resetPtfFilters = function () {
    document.getElementById("ptf-f-search").value = "";
    const placementEl = document.getElementById("ptf-f-placement");
    if (placementEl) placementEl.value = "";

    const petEl = document.getElementById("ptf-f-pet");
    if (petEl) petEl.value = "";

    const defaultRanges = {
      maac: 10,
      advc: 100,
      mc: 100,
      applied: 500,
      shortlisted: 500,
    };
    Object.keys(defaultRanges).forEach((id) => {
      const minEl = document.getElementById(`f-${id}-min`);
      const maxEl = document.getElementById(`f-${id}-max`);
      if (minEl) minEl.value = 0;
      if (maxEl) maxEl.value = defaultRanges[id];
    });

    document
      .querySelectorAll(".cb-yop, .cb-state")
      .forEach((cb) => (cb.checked = false));
    window.applyPtfFilters();
    showToast("Filters reset", "info");
  };

  // ==================== OVERVIEW TAB ====================
  function renderOverview() {
    // 1. Filter courses based on selection
    const coursesToRender = BATCH_DATA.courses.filter((c) =>
      OVERVIEW_SELECTED_COURSES.includes(c.courseid),
    );

    // 2. Generate Advanced Filter Dropdown HTML

    // Check if filter is active (not all courses selected)
    const isFilterActive =
      OVERVIEW_SELECTED_COURSES.length < BATCH_DATA.courses.length;

    // Toggle SVG Icon: Green Funnel with Checkmark if active, otherwise standard Funnel
    const filterIconSvg = isFilterActive
      ? `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
             <path d="M2 3h20l-8 9.46V19l-4 2v-8.54L2 3z" stroke="#2e7d32" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
             <circle cx="18" cy="9" r="6" fill="#2e7d32" stroke="#fff" stroke-width="2"/>
             <path d="M15.5 9.5l1.5 1.5 3-3" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
           </svg>`
      : `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>`;

    let filterHtml = `
      <div class="ba-overview-filter" style="position: relative; display: inline-block;">
          <button class="ba-btn ba-btn-view" style="position: relative;" onclick="document.getElementById('ov-filter-dropdown').style.display = document.getElementById('ov-filter-dropdown').style.display === 'none' ? 'block' : 'none'">
              ${filterIconSvg}
              <span style="font-weight: 600;">Advanced Filter v</span>
          </button>

          <div id="ov-filter-dropdown" style="display:none; position: absolute; top: 100%; right: 0; background: #fff; min-width: 250px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); border: 1px solid #e2e8f0; border-radius: 8px; z-index: 1000; padding: 16px; margin-top: 8px; text-align: left;">

              <div style="font-weight:700; margin-bottom:12px; font-size:14px; color:#1e293b;">Filter by Course</div>

              <div style="max-height: 200px; overflow-y:auto; margin-bottom:10px; padding-right:5px; display: flex; flex-direction: column; gap: 8px;">
                  ${BATCH_DATA.courses
        .map(
          (c) => `
                      <label style="display: flex; align-items: center; gap: 8px; margin: 0; cursor: pointer; font-size: 13px; color: #1e293b;">
                          <input type="checkbox" class="ov-course-cb" value="${c.courseid}" ${OVERVIEW_SELECTED_COURSES.includes(c.courseid) ? "checked" : ""}>
                          ${c.coursename}
                      </label>
                  `,
        )
        .join("")}
              </div>

              <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px; padding-top: 12px; border-top: 1px solid #f1f5f9;">
                  <span onclick="document.querySelectorAll('.ov-course-cb').forEach(cb => cb.checked = true)" style="cursor:pointer; color:#0f6cbf; font-size:12px; font-weight:600;">Select All</span>
                  <button class="ba-btn ba-btn-sm ba-btn-success" onclick="applyOverviewFilter(); document.getElementById('ov-filter-dropdown').style.display='none';">Apply</button>
              </div>
          </div>
      </div>`;
    // 3. New Header Card
    let html = `
      <div class="ba-course-header-card">
          <div class="ba-ch-content">
              <div class="ba-ch-left">
                  <h2 class="ba-ch-title">Batch Overview: ${BATCH_DATA.batchcode}</h2>
                  <div class="ba-ch-meta">
                      <span class="ba-ch-badge students"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg> ${BATCH_DATA.totalStudents || 0} Total Students</span>
                      <span class="ba-ch-badge avg" style="background:#E8F5E9; color:#2E7D32;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg> ${coursesToRender.length} Courses Selected</span>
                      <span class="ba-ch-badge teachers"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg> ${BATCH_DATA.totalTeachers || 0} Total Teachers</span>
                  </div>
              </div>
              <div class="ba-ch-right">
                  ${filterHtml}
              </div>
          </div>
      </div>`;

    // 4. Top Performers
    const topStudents = getTopPerformers(coursesToRender);
    if (topStudents.length > 0) {
      html += `<div class="ba-section-title">Top Performers</div><div class="ba-top-performers-grid">`;
      topStudents.forEach((s, i) => {
        const rank = i + 1;
        const medalClass =
          rank === 1
            ? "gold"
            : rank === 2
              ? "silver"
              : rank === 3
                ? "bronze"
                : "default";
        html += `<div class="ba-performer-card ${medalClass}"><div class="ba-perf-rank">#${rank}</div><div class="ba-perf-info"><div class="ba-perf-name" title="${s.name}">${s.name}</div><div class="ba-perf-avg">${s.avg}% Avg</div></div>${rank === 1 ? `<div class="ba-crown">${renderTrophyIcon()}</div>` : ""}</div>`;
      });
      html += `</div>`;
    }

    // 5. Low Performers
    const lowStudents = getLowPerformers(coursesToRender);
    if (lowStudents.length > 0) {
      html += `<div class="ba-section-title" style="border-color:#d32f2f; color:#d32f2f;">Needs Improvement</div><div class="ba-top-performers-grid">`;
      lowStudents.forEach((s, i) => {
        html += `<div class="ba-performer-card low-performer"><div class="ba-perf-rank warning">!</div><div class="ba-perf-info"><div class="ba-perf-name" title="${s.name}">${s.name}</div><div class="ba-perf-avg low-avg">${s.avg}% Avg</div></div></div>`;
      });
      html += `</div>`;
    }

    // 6. Batch Performance Metrics
    html += `<div class="ba-section-title">Batch Performance Metrics</div><div class="ba-batch-metrics-grid">`;
    const metrics = {};
    coursesToRender.forEach((c) => {
      c.categories.forEach((cat) => {
        if (cat.categoryname === "MAAC Ratings") return;
        if (!metrics[cat.categoryname])
          metrics[cat.categoryname] = { sum: 0, count: 0 };

        const isAtt = isAttendance(cat.categoryname);
        const val = isAtt ? getAvgGrade(cat) : getCompletionRate(cat);

        // FIX: Enforce parsing to float and fallback to 0
        metrics[cat.categoryname].sum += parseFloat(val) || 0;
        metrics[cat.categoryname].count++;
      });
    });

    Object.keys(metrics).forEach((k, i) => {
      const m = metrics[k];
      const avg = m.count ? Math.round(m.sum / m.count) : 0;
      const style = getCategoryIconAndColor(k, i);
      const label = isAttendance(k) ? "AVG GRADE" : "AVG COMPLETION";
      html += `<div class="ba-batch-metric-card"><div class="ba-course-metric-header"><div class="ba-course-metric-icon" style="background:${style.bg}">${style.icon}</div><div class="ba-course-metric-title">${k}</div></div><div class="ba-metric-footer"><div class="ba-metric-label">${label}</div><div class="ba-metric-value">${avg}%</div></div></div>`;
    });
    html += `</div>`;

    // 7. Overall Performance Table
    const batchStats = {};
    coursesToRender.forEach((c) => {
      c.categories.forEach((cat) => {
        if (cat.categoryname === "MAAC Ratings") return;
        cat.studentGrades.forEach((s) => {
          if (!batchStats[s.username]) {
            batchStats[s.username] = {
              fullname: s.fullname,
              username: s.username,
              sum: 0,
              count: 0,
            };
          }
          // Using null check based on our previous logic updates
          if (s.percentage !== null && s.percentage >= 0) {
            batchStats[s.username].sum += s.percentage;
            batchStats[s.username].count++;
          }
        });
      });
    });
    const batchStudentList = Object.values(batchStats).map((s) => ({
      fullname: s.fullname,
      username: s.username,
      percentage: s.count ? Math.round(s.sum / s.count) : null,
    }));
    html += renderGradeDistributionTable(batchStudentList, "Batch Overall");

    // 8. Course Overview Cards
    html += `<div class="ba-section-title">Course Overview <small style="font-weight:normal;color:#666;font-size:12px">(Click to view details)</small></div><div class="ba-course-cards-grid">`;
    coursesToRender.forEach((c, idx) => {
      let totalPct = 0;
      let catCount = 0;
      c.categories.forEach((cat) => {
        if (cat.categoryname === "MAAC Ratings") return;
        const val = getCompletionRate(cat);

        // FIX: Parse string to float to prevent string concatenation
        totalPct += parseFloat(val);
        catCount++;
      });
      const courseAvg = catCount ? Math.round(totalPct / catCount) : 0;
      const letters = c.shortname
        .split(" ")
        .map((w) => w[0])
        .join("")
        .substring(0, 2)
        .toUpperCase();
      const colors = ["#2196F3", "#673AB7", "#009688", "#FF5722", "#607D8B"];
      const color = colors[idx % colors.length];

      html += `<div class="ba-course-card" onclick="window.triggerCourseTab(${c.courseid})" style="border-top: 4px solid ${color}"><div class="ba-cc-header"><div class="ba-cc-icon" style="background:${color}15; color:${color}">${letters}</div><div class="ba-cc-info"><div class="ba-cc-title">${c.coursename}</div><div class="ba-cc-subtitle">${c.shortname}</div></div></div><div class="ba-cc-stats"><div class="ba-cc-stat"><span class="ba-cc-val">${c.studentCount}</span><span class="ba-cc-lbl">Students</span></div><div class="ba-cc-divider"></div><div class="ba-cc-stat"><span class="ba-cc-val" style="color:${color}">${courseAvg}%</span><span class="ba-cc-lbl">Avg %</span></div><div class="ba-cc-divider"></div><div class="ba-cc-stat"><span class="ba-cc-val">${c.teacherCount || 0}</span><span class="ba-cc-lbl">Teachers</span></div></div></div>`;
    });
    html += `</div>`;

    batchTabsContent.innerHTML = html;
  }

  // GLOBAL FUNCTION: Handle Filter Application
  window.applyOverviewFilter = function () {
    const checkboxes = document.querySelectorAll(".ov-course-cb");
    OVERVIEW_SELECTED_COURSES = Array.from(checkboxes)
      .filter((cb) => cb.checked)
      .map((cb) => parseInt(cb.value));

    if (OVERVIEW_SELECTED_COURSES.length === 0) {
      showToast("Please select at least one course", "warn");
      return;
    }
    renderOverview();
  };

  // ==================== COURSE TAB ====================
  function renderCourse(cid) {
    const c = BATCH_DATA.courses.find((x) => x.courseid == cid);
    CURRENT_COURSE = c;
    if (!c) return;

    // 1. Header Card
    let totalPct = 0;
    let catCount = 0;
    c.categories.forEach((cat) => {
      if (cat.categoryname === "MAAC Ratings") return;
      const val = getCompletionRate(cat);

      // FIX: Parse string to float here too
      totalPct += parseFloat(val);
      catCount++;
    });
    const courseAvg = catCount ? Math.round(totalPct / catCount) : 0;
    const teacherCount = c.teacherCount || 0;

    let html = `
        <div class="ba-course-header-card">
            <div class="ba-ch-content">
                <div class="ba-ch-left">
                    <h2 class="ba-ch-title">${c.coursename}</h2>
                    <div class="ba-ch-meta">
                        <span class="ba-ch-badge students"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg> ${c.studentCount} Students</span>
                        <span class="ba-ch-badge avg"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20V10"></path><path d="M18 20V4"></path><path d="M6 20v-6"></path></svg> ${courseAvg}% Avg Performance</span>
                        <span class="ba-ch-badge teachers"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg> ${teacherCount} Teachers</span>
                    </div>
                </div>
                <div class="ba-ch-right">
                    <a href="../../course/view.php?id=${c.courseid}" target="_blank" rel="noopener" class="ba-btn ba-btn-view">View Course <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg></a>
                    <button type="button" class="ba-btn ba-btn-view" data-course-summary="1" data-course-id="${c.courseid}">Summary <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg></button>
                    <a href="maac.php?courseid=${c.courseid}" target="_blank" rel="noopener" class="ba-btn ba-btn-view">MAAC sheet <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg></a>
                </div>
            </div>
        </div>`;

    // 2. Top Performers
    const topStudents = getCourseTopPerformers(c);
    if (topStudents.length > 0) {
      html += `<div class="ba-section-title">Top Performers (${c.shortname})</div><div class="ba-top-performers-grid">`;
      topStudents.forEach((s, i) => {
        const rank = i + 1;
        const medalClass =
          rank === 1
            ? "gold"
            : rank === 2
              ? "silver"
              : rank === 3
                ? "bronze"
                : "default";
        html += `<div class="ba-performer-card ${medalClass}"><div class="ba-perf-rank">#${rank}</div><div class="ba-perf-info"><div class="ba-perf-name" title="${s.name}">${s.name}</div><div class="ba-perf-avg">${s.avg}% Avg</div></div>${rank === 1 ? `<div class="ba-crown">${renderTrophyIcon()}</div>` : ""}</div>`;
      });
      html += `</div>`;
    }

    // 3. Needs Improvement
    const lowStudents = getCourseLowPerformers(c);
    if (lowStudents.length > 0) {
      html += `<div class="ba-section-title" style="border-color:#d32f2f; color:#d32f2f;">Needs Improvement</div><div class="ba-top-performers-grid">`;
      lowStudents.forEach((s, i) => {
        html += `<div class="ba-performer-card low-performer"><div class="ba-perf-rank warning">!</div><div class="ba-perf-info"><div class="ba-perf-name" title="${s.name}">${s.name}</div><div class="ba-perf-avg low-avg">${s.avg}% Avg</div></div></div>`;
      });
      html += `</div>`;
    }

    // 4. Course Metrics
    html += `<div class="ba-section-title">Course Metrics</div><div class="ba-course-metrics-grid">`;
    c.categories.forEach((cat, i) => {
      const style = getCategoryIconAndColor(cat.categoryname, i);
      const grade = getAvgGrade(cat);
      const comp = getCompletionRate(cat);
      const isMaac = cat.categoryname === "MAAC Ratings";
      const isAtt = isAttendance(cat.categoryname);

      let statsHtml = "";
      if (isMaac) {
        statsHtml = `<div class="stat-box" style="width:100%; text-align:center; align-items:center;"><span class="lbl">AVG MAAC RATING</span><span class="val">${grade}</span></div>`;
      } else if (isAtt) {
        statsHtml = `<div class="stat-box" style="width:100%; text-align:center; align-items:center;"><span class="lbl">AVG GRADE</span><span class="val">${grade}%</span></div>`;
      } else {
        statsHtml = `<div class="stat-box"><span class="lbl">AVG GRADE</span><span class="val">${grade}%</span></div><div class="stat-box"><span class="lbl">COMPLETION</span><span class="val ${getCompClass(comp)}">${comp}%</span></div>`;
      }

      html += `<div class="ba-course-metric-card" data-category-modal="1" data-course-id="${cid}" data-category-name="${escapeHtml(cat.categoryname)}"><div class="ba-course-metric-header"><div class="ba-course-metric-icon" style="background:${style.bg}">${style.icon}</div><div class="ba-course-metric-title">${escapeHtml(cat.categoryname)}</div></div><div class="ba-course-metric-stats">${statsHtml}</div></div>`;
    });
    html += `</div>`;

    // 5. Performance Chart (Updated with Legend & Scaling)
    html += `<div class="ba-section-title">Performance Overview <small style="font-weight:normal;color:#666;font-size:12px">(Click bars to analyze)</small></div>`;
    html += `
    <div class="ba-chart-legend">
        <div class="ba-legend-item"><div class="ba-legend-box bar-green"></div> Completion Percentage Avg %</div>
        <div class="ba-legend-item"><div class="ba-legend-box bar-purple"></div> Completion Grade Avg</div>
    </div>`;

    html += `<div class="ba-performance-chart-modern"><div class="ba-chart-track">`;
    c.categories.forEach((cat, i) => {
      const isMaac = cat.categoryname === "MAAC Ratings";
      const isAtt = isAttendance(cat.categoryname);

      let val;
      if (isMaac) {
        val = getAvgGrade(cat);
      } else {
        val = isAtt ? getAvgGrade(cat) : getCompletionRate(cat);
      }

      // FIX: Force to number for mathematical height scaling
      const numVal = parseFloat(val) || 0;

      const height = isMaac ? (numVal / 9) * 100 : Math.max(numVal, 2);
      const barClass = isMaac || isAtt ? "bar-purple" : "bar-green";
      const displayVal = isMaac ? val : val + "%";

      html += `
        <div class="ba-chart-col" data-donut-modal="1" data-course-id="${cid}" data-category-name="${escapeHtml(cat.categoryname)}">
            <div class="ba-chart-bar-area">
                <div class="ba-chart-value">${displayVal}</div>
                <div class="ba-chart-bar ${barClass}" style="height:${height}%"></div>
            </div>
            <div class="ba-chart-label" title="${escapeHtml(cat.categoryname)}">${escapeHtml(cat.categoryname)}</div>
        </div>`;
    });
    html += `</div></div>`;

    // 6. Overall Performance Table
    const courseStats = {};
    c.categories.forEach((cat) => {
      if (cat.categoryname === "MAAC Ratings") return;
      cat.studentGrades.forEach((s) => {
        if (!courseStats[s.username]) {
          courseStats[s.username] = {
            fullname: s.fullname,
            username: s.username,
            sum: 0,
            count: 0,
          };
        }
        if (s.percentage >= 0) {
          courseStats[s.username].sum += s.percentage;
          courseStats[s.username].count++;
        }
      });
    });
    const courseStudentList = Object.values(courseStats).map((s) => ({
      fullname: s.fullname,
      username: s.username,
      percentage: s.count ? Math.round(s.sum / s.count) : 0,
    }));
    html += renderGradeDistributionTable(courseStudentList, c.coursename);
    html += renderMaacDetailsCard(c);

    // 7. Filter
    html += `<div id="ba-course-filter-section"></div>`;

    batchTabsContent.innerHTML = html;
    renderCourseFilterSection(c);
    loadMaacSummary(c.courseid);
    loadCourseMaacData(c.courseid);
  }

  // ==================== ADVANCED FILTER & UTILS ====================
  function getCourseMaacColumns(courseId) {
    const maacData = MAAC_COURSE_CACHE[courseId];
    return maacData ? maacData.columns || [] : [];
  }

  function getCourseBuiltinColumns(courseId) {
    const maacData = MAAC_COURSE_CACHE[courseId];
    return maacData ? maacData.builtin_columns || [] : [];
  }

  function isBuiltinColumnEnabled(courseId, key) {
    return getCourseBuiltinColumns(courseId).some((column) => column.key === key);
  }

  function isBuiltinColumnGrouped(courseId, key) {
    return getCourseMaacColumnGroups(courseId).some((group) =>
      (group.columns || []).some((column) => column.key === key),
    );
  }

  function getVisibleCourseCategories(course) {
    const showMaacRating =
      isBuiltinColumnEnabled(course.courseid, "maac_rating") &&
      !isBuiltinColumnGrouped(course.courseid, "maac_rating");
    return (course.categories || []).filter((category) => {
      if (category.categoryname === "MAAC Ratings") {
        return showMaacRating;
      }
      return true;
    });
  }

  function getCourseMaacColumnGroups(courseId) {
    const maacData = MAAC_COURSE_CACHE[courseId];
    return maacData ? maacData.column_groups || [] : [];
  }

  function getOrderedCourseMaacColumns(courseId) {
    const grouped = getCourseMaacColumnGroups(courseId).flatMap(
      (group) => group.columns || [],
    );
    return grouped.length ? grouped : getCourseMaacColumns(courseId);
  }

  function getCourseStudentCustomValue(student, column) {
    if (column.key === "maac_rating") {
      return student.maac_rating;
    }

    if (column.system) {
      return student.maaccustom ? student.maaccustom[column.key] : "";
    }

    return student.maaccustom ? student.maaccustom[column.key] : "";
  }

  function normalizeCourseFeedbackItems(value) {
    const list = Array.isArray(value) ? value : value ? [value] : [];
    return list
      .map((item) => {
        if (typeof item === "string") {
          const raw = item.trim();
          if (raw.startsWith("[") && raw.endsWith("]")) {
            try {
              const parsed = JSON.parse(raw);
              if (Array.isArray(parsed)) {
                return normalizeCourseFeedbackItems(parsed);
              }
            } catch (error) {
              return { date: "", text: raw };
            }
          }
          return { date: "", text: raw };
        }

        if (item && typeof item === "object") {
          return {
            date: String(item.date || ""),
            text: String(item.text || item.label || item.value || ""),
          };
        }

        return { date: "", text: String(item || "") };
      })
      .flat()
      .filter((item) => item.text.trim() !== "");
  }

  function normalizeMaacDisplayList(value) {
    if (Array.isArray(value)) {
      return value
        .map((item) => {
          if (item && typeof item === "object") {
            return String(item.text || item.label || item.value || "");
          }
          return String(item);
        })
        .filter((item) => item.trim() !== "");
    }

    if (typeof value === "string") {
      const raw = value.trim();
      if (raw.startsWith("[") && raw.endsWith("]")) {
        try {
          const parsed = JSON.parse(raw);
          return Array.isArray(parsed)
            ? parsed
                .map((item) => {
                  if (item && typeof item === "object") {
                    return String(item.text || item.label || item.value || "");
                  }
                  return String(item);
                })
                .filter((item) => item.trim() !== "")
            : null;
        } catch (error) {
          return null;
        }
      }
    }

    return null;
  }

  function getCourseFeedbackCount(value) {
    return normalizeCourseFeedbackItems(value).length;
  }

  function formatCourseFeedbackDate(value) {
    const raw = String(value || "").trim();
    if (!raw) {
      return "No date";
    }
    const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (match) {
      return `${match[3]}-${months[Math.max(0, Math.min(11, parseInt(match[2], 10) - 1))]}-${match[1]}`;
    }
    const parsed = new Date(raw);
    return Number.isNaN(parsed.getTime())
      ? raw
      : `${String(parsed.getDate()).padStart(2, "0")}-${months[parsed.getMonth()]}-${parsed.getFullYear()}`;
  }

  function formatMaacValueForDisplay(value, column) {
    if (column.type === "boolean") {
      return value ? "Yes" : "No";
    }

    if (column.type === "multi_feedback" || column.type === "dropdown" || column.type === "list") {
      const list = normalizeMaacDisplayList(value);
      if (list) {
        return list.length ? list : [];
      }
    }

    return value === null || value === undefined || value === "" ? "-" : value;
  }
  function formatMaacValueForExport(value, column) {
    if (column.type === "multi_feedback") {
      const feedbacks = normalizeCourseFeedbackItems(value);
      return feedbacks.length
        ? feedbacks.map((item) => `${item.date ? formatCourseFeedbackDate(item.date) + ": " : ""}${item.text}`).join(" | ")
        : "-";
    }

    const display = formatMaacValueForDisplay(value, column);
    if (Array.isArray(display)) {
      return display.length ? display.join(" | ") : "-";
    }
    return String(display);
  }
  function renderCourseTrendBadge(value) {
    const normalized = String(value || "").trim().toLowerCase();
    if (!normalized || normalized === "-") {
      return `<span class="ba-maac-inline-empty">-</span>`;
    }

    if (normalized === "improving") {
      return `<span class="ba-trend-badge ba-trend-badge-up">${escapeHtml(String(value))}</span>`;
    }

    if (normalized === "declining") {
      return `<span class="ba-trend-badge ba-trend-badge-down">${escapeHtml(String(value))}</span>`;
    }

    return `<span class="ba-trend-badge ba-trend-badge-stable">${escapeHtml(String(value))}</span>`;
  }

  function normalizeTrendKey(value) {
    const normalized = String(value || "").trim().toLowerCase();
    if (!normalized || normalized === "-") {
      return "";
    }

    if (normalized === "up" || normalized === "improving") {
      return "up";
    }

    if (normalized === "down" || normalized === "declining") {
      return "down";
    }

    return "stable";
  }

  function getCourseTrendVisual(value) {
    const key = normalizeTrendKey(value);
    if (!key) {
      return null;
    }

    if (key === "up") {
      return {
        key,
        label: "Improving",
        emoji: "+",
        className: "ba-trend-badge-up",
      };
    }

    if (key === "down") {
      return {
        key,
        label: "Declining",
        emoji: "-",
        className: "ba-trend-badge-down",
      };
    }

    return {
      key: "stable",
      label: "Stable",
      emoji: "=",
      className: "ba-trend-badge-stable",
    };
  }

  function renderCourseTrendBadgeButton(value, student, courseId) {
    const visual = getCourseTrendVisual(value);
    if (!visual) {
      return `<span class="ba-maac-inline-empty">-</span>`;
    }

    const badge = `<span class="ba-trend-badge ${visual.className}"><span>${visual.emoji}</span><span>${escapeHtml(
      visual.label,
    )}</span></span>`;
    if (!student || !student.userid) {
      return badge;
    }

    return `<button
      type="button"
      class="ba-trend-badge-btn"
      onclick="openCourseTrendModal(${Number(courseId) || 0}, ${Number(student.userid)})"
      aria-label="View trend details for ${escapeHtml(student.fullname || student.username || "student")}"
    >${badge}</button>`;
  }

  function getCourseTrendVisualFixed(value) {
    const key = normalizeTrendKey(value);
    if (!key) {
      return null;
    }

    if (key === "up") {
      return {
        key,
        label: "Improving",
        emoji: "\uD83D\uDCC8",
        className: "ba-trend-badge-up",
      };
    }

    if (key === "down") {
      return {
        key,
        label: "Declining",
        emoji: "\uD83D\uDCC9",
        className: "ba-trend-badge-down",
      };
    }

    return {
      key: "stable",
      label: "Stable",
      emoji: "\u27A1\uFE0F",
      className: "ba-trend-badge-stable",
    };
  }

  function renderCourseTrendBadgeButtonFixed(value, student, courseId) {
    const visual = getCourseTrendVisualFixed(value);
    if (!visual) {
      return `<span class="ba-maac-inline-empty">-</span>`;
    }

    const badge = `<span class="ba-trend-badge ${visual.className}"><span>${visual.emoji}</span><span>${escapeHtml(
      visual.label,
    )}</span></span>`;
    if (!student || !student.userid) {
      return badge;
    }

    return `<button
      type="button"
      class="ba-trend-badge-btn"
      onclick="openCourseTrendModal(${Number(courseId) || 0}, ${Number(student.userid)})"
      aria-label="View trend details for ${escapeHtml(student.fullname || student.username || "student")}"
    >${badge}</button>`;
  }

  function renderCourseTrendMiniChart(points) {
    if (!Array.isArray(points) || !points.length) {
      return "";
    }

    const safePoints = points
      .map((point, index) => ({
        label: point.name || `Item ${index + 1}`,
        value: Math.max(0, Math.min(100, parseFloat(point.grade) || 0)),
      }));
    if (!safePoints.length) {
      return "";
    }

    const width = 320;
    const height = 80;
    const leftPadding = 28;
    const rightPadding = 10;
    const topPadding = 10;
    const bottomPadding = 10;
    const drawableWidth = width - leftPadding - rightPadding;
    const drawableHeight = height - topPadding - bottomPadding;
    const step = safePoints.length === 1 ? 0 : drawableWidth / (safePoints.length - 1);
    const coords = safePoints.map((point, index) => {
      const x = leftPadding + step * index;
      const y = topPadding + ((100 - point.value) / 100) * drawableHeight;
      return { ...point, x, y };
    });
    const path = coords
      .map((point, index) => `${index === 0 ? "M" : "L"} ${point.x.toFixed(2)} ${point.y.toFixed(2)}`)
      .join(" ");
    const area = `${path} L ${coords[coords.length - 1].x.toFixed(2)} ${(height - bottomPadding).toFixed(2)} L ${coords[0].x.toFixed(2)} ${(height - bottomPadding).toFixed(2)} Z`;
    const circles = coords
      .map((point) => {
        const color = point.value >= 75 ? "#667eea" : point.value >= 60 ? "#fbbf24" : "#ef4444";
        return `<circle cx="${point.x.toFixed(2)}" cy="${point.y.toFixed(2)}" r="3.5" fill="${color}" stroke="${color}" stroke-width="1">
          <title>${escapeHtml(point.label)}: ${escapeHtml(String(Math.round(point.value * 10) / 10))}%</title>
        </circle>`;
      })
      .join("");
    const axisLabels = [
      { value: 100, y: topPadding },
      { value: 50, y: topPadding + drawableHeight / 2 },
      { value: 0, y: height - bottomPadding },
    ]
      .map(
        (tick) => `<g>
          <line x1="${leftPadding}" y1="${tick.y.toFixed(2)}" x2="${(width - rightPadding).toFixed(2)}" y2="${tick.y.toFixed(2)}" stroke="#e5e7eb" stroke-width="1"></line>
          <text x="${(leftPadding - 6).toFixed(2)}" y="${(tick.y + 4).toFixed(2)}" text-anchor="end" font-size="10" fill="#94a3b8">${tick.value}</text>
        </g>`,
      )
      .join("");

    return `<div class="chart-container" style="position: relative; height: 80px; width: 100%;">
      <svg viewBox="0 0 ${width} ${height}" width="100%" height="80" role="img" aria-label="Grade trend chart">
        <defs>
          <linearGradient id="ba-course-trend-fill" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stop-color="rgba(102, 126, 234, 0.28)"></stop>
            <stop offset="100%" stop-color="rgba(102, 126, 234, 0.04)"></stop>
          </linearGradient>
        </defs>
        ${axisLabels}
        <path d="${area}" fill="url(#ba-course-trend-fill)"></path>
        <path d="${path}" fill="none" stroke="rgba(102, 126, 234, 1)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
        ${circles}
      </svg>
    </div>`;
  }

  function getCourseStudentTrendDetails(student) {
    const details = student?.trend_details || {};
    return {
      assignments: Array.isArray(details.assignments) ? details.assignments : [],
      quizzes: Array.isArray(details.quizzes) ? details.quizzes : [],
      projects: Array.isArray(details.projects) ? details.projects : [],
      attendance: Array.isArray(details.attendance) ? details.attendance : [],
      overall_trend: details.overall_trend || "",
      trend_window: Math.max(1, Number(details.trend_window) || 20),
      attendance_window: Math.max(2, Number(details.attendance_window) || 5),
    };
  }

  function formatCourseTrendDate(timestamp) {
    const numeric = Number(timestamp);
    if (!numeric) {
      return "-";
    }

    return new Date(numeric * 1000).toLocaleDateString(undefined, {
      day: "2-digit",
      month: "short",
    });
  }

  function formatCourseTrendPercent(value) {
    const numeric = parseFloat(value);
    if (Number.isNaN(numeric)) {
      return "-";
    }

    return `${Math.round(numeric * 10) / 10}%`;
  }

  function calculateCourseTrendComponent(points) {
    if (!Array.isArray(points) || !points.length) {
      return null;
    }

    if (points.length === 1) {
      const grade = parseFloat(points[0].grade);
      if (Number.isNaN(grade)) {
        return null;
      }

      const rounded = Math.round(grade * 10) / 10;
      return { start: rounded, latest: rounded, change: 0 };
    }

    if (points.length < 4) {
      const firstGrade = parseFloat(points[0].grade);
      const lastGrade = parseFloat(points[points.length - 1].grade);
      if (Number.isNaN(firstGrade) || Number.isNaN(lastGrade)) {
        return null;
      }

      return {
        start: Math.round(firstGrade * 10) / 10,
        latest: Math.round(lastGrade * 10) / 10,
        change: Math.round((lastGrade - firstGrade) * 10) / 10,
      };
    }

    const split = Math.floor(points.length / 2);
    const olderGrades = points
      .slice(0, split)
      .map((item) => parseFloat(item.grade))
      .filter((grade) => !Number.isNaN(grade));
    const recentGrades = points
      .slice(split)
      .map((item) => parseFloat(item.grade))
      .filter((grade) => !Number.isNaN(grade));

    if (!olderGrades.length || !recentGrades.length) {
      return null;
    }

    const olderAverage = olderGrades.reduce((sum, grade) => sum + grade, 0) / olderGrades.length;
    const recentAverage = recentGrades.reduce((sum, grade) => sum + grade, 0) / recentGrades.length;
    return {
      start: Math.round(olderAverage * 10) / 10,
      latest: Math.round(recentAverage * 10) / 10,
      change: Math.round((recentAverage - olderAverage) * 10) / 10,
    };
  }

  function calculateCourseAttendanceSummary(attendancePoints, windowSize) {
    if (!Array.isArray(attendancePoints) || !attendancePoints.length) {
      return null;
    }

    const size = windowSize || 5;
    const countPresent = (points) =>
      points.reduce((sum, item) => sum + (Number(item.present) === 1 ? 1 : 0), 0);

    if (attendancePoints.length < 2) {
      const onlyCount = countPresent(attendancePoints);
      return {
        windowSize: attendancePoints.length,
        currentCount: onlyCount,
        currentRate: attendancePoints.length ? Math.round((onlyCount / attendancePoints.length) * 100) : 0,
        previousWindowSize: 0,
        previousCount: 0,
        previousRate: 0,
        delta: null,
        comparisonMode: "insufficient",
        comparisonLabel: "Need at least 2 sessions for comparison",
      };
    }

    const recent = attendancePoints.slice(-size);
    const currentCount = countPresent(recent);
    const currentTotal = recent.length;
    const previous = attendancePoints.slice(
      Math.max(0, attendancePoints.length - size * 2),
      attendancePoints.length - size,
    );
    const previousCount = countPresent(previous);
    const previousTotal = previous.length;
    const minComparablePrevious = Math.max(2, Math.ceil(currentTotal / 2));

    if (!previousTotal || previousTotal < minComparablePrevious) {
      const split = Math.floor(attendancePoints.length / 2);
      const older = attendancePoints.slice(0, split);
      const newer = attendancePoints.slice(split);
      if (older.length && newer.length) {
        const olderCount = countPresent(older);
        const newerCount = countPresent(newer);
        return {
          windowSize: newer.length,
          currentCount: newerCount,
          currentRate: Math.round((newerCount / newer.length) * 100),
          previousWindowSize: older.length,
          previousCount: olderCount,
          previousRate: Math.round((olderCount / older.length) * 100),
          delta: newerCount - olderCount,
          comparisonMode: "adaptive-half",
          comparisonLabel: "Compared recent half vs earlier half",
        };
      }
    }

    return {
      windowSize: currentTotal,
      currentCount,
      currentRate: currentTotal ? Math.round((currentCount / currentTotal) * 100) : 0,
      previousWindowSize: previousTotal,
      previousCount,
      previousRate: previousTotal ? Math.round((previousCount / previousTotal) * 100) : 0,
      delta: previousTotal > 0 ? currentCount - previousCount : null,
      comparisonMode: "window",
      comparisonLabel: previousTotal > 0 ? `Compared last ${currentTotal} vs previous ${previousTotal}` : "",
    };
  }

  function calculateCourseOverallTrend(details) {
    const trendWindow = Math.max(1, Number(details?.trend_window) || 20);
    const attendanceWindow = Math.max(
      2,
      Math.min(Number(details?.attendance_window) || 5, trendWindow),
    );
    const changes = [];

    ["assignments", "quizzes", "projects"].forEach((key) => {
      const points = Array.isArray(details?.[key]) ? details[key].slice(-trendWindow) : [];
      const summary = calculateCourseTrendComponent(points);
      if (summary && typeof summary.change === "number" && !Number.isNaN(summary.change)) {
        changes.push(summary.change);
      }
    });

    const attendanceSummary = calculateCourseAttendanceSummary(
      Array.isArray(details?.attendance) ? details.attendance.slice(-trendWindow) : [],
      attendanceWindow,
    );
    if (attendanceSummary && attendanceSummary.previousWindowSize > 0) {
      const attendanceChange = attendanceSummary.currentRate - attendanceSummary.previousRate;
      if (!Number.isNaN(attendanceChange)) {
        changes.push(Math.round(attendanceChange * 10) / 10);
      }
    }

    if (!changes.length) {
      return normalizeTrendKey(details?.overall_trend) || "stable";
    }

    const averageChange = changes.reduce((sum, value) => sum + value, 0) / changes.length;
    if (averageChange > 5) {
      return "up";
    }
    if (averageChange < -5) {
      return "down";
    }
    return "stable";
  }

  function calculateCourseCurrentLevel(details) {
    const trendWindow = Math.max(1, Number(details?.trend_window) || 20);
    const attendanceWindow = Math.max(
      2,
      Math.min(Number(details?.attendance_window) || 5, trendWindow),
    );
    const values = [];

    ["assignments", "quizzes", "projects"].forEach((key) => {
      const points = Array.isArray(details?.[key]) ? details[key].slice(-trendWindow) : [];
      if (!points.length) {
        return;
      }
      const latest = parseFloat(points[points.length - 1].grade);
      if (!Number.isNaN(latest)) {
        values.push(latest);
      }
    });

    const attendanceSummary = calculateCourseAttendanceSummary(
      Array.isArray(details?.attendance) ? details.attendance.slice(-trendWindow) : [],
      attendanceWindow,
    );
    if (
      attendanceSummary &&
      typeof attendanceSummary.currentRate === "number" &&
      !Number.isNaN(attendanceSummary.currentRate)
    ) {
      values.push(attendanceSummary.currentRate);
    }

    if (!values.length) {
      return { label: "Limited Data", score: null };
    }

    const average = values.reduce((sum, value) => sum + value, 0) / values.length;
    const rounded = Math.round(average);

    if (average >= 75) {
      return { label: "Strong", score: rounded };
    }
    if (average >= 50) {
      return { label: "Moderate", score: rounded };
    }
    return { label: "Low", score: rounded };
  }

  function renderCourseTrendPointList(points) {
    if (!points.length) {
      return `<div class="ba-trend-points-empty">No graded items yet.</div>`;
    }

    return `<div class="ba-trend-points-list">${points
      .slice(-6)
      .map(
        (point) => `<div class="ba-trend-point-row">
          <div class="ba-trend-point-copy">
            <strong>${escapeHtml(point.name || "Activity")}</strong>
            <span>${escapeHtml(formatCourseTrendDate(point.date))}</span>
          </div>
          <span class="ba-trend-point-value">${escapeHtml(formatCourseTrendPercent(point.grade))}</span>
        </div>`,
      )
      .join("")}</div>`;
  }

  function renderCourseAttendanceStatusStrip(points, limit = 10) {
    const recent = points.slice(-limit);
    if (!recent.length) {
      return "";
    }

    return `<div class="ba-trend-attendance-strip">${recent
      .map((point) => {
        const present = Number(point.present) === 1;
        const label = String(point.status || (present ? "P" : "A")).toUpperCase();
        return `<span class="ba-trend-attendance-dot ${present ? "is-present" : "is-absent"}" title="${escapeHtml(
          `${label} - ${formatCourseTrendDate(point.date)}`,
        )}">${escapeHtml(label)}</span>`;
      })
      .join("")}</div>`;
  }

  function renderCourseTrendCard(title, icon, points, trendWindow) {
    const recentPoints = Array.isArray(points) ? points.slice(-trendWindow) : [];
    if (!recentPoints.length) {
      return "";
    }

    const summary = calculateCourseTrendComponent(recentPoints);
    if (!summary) {
      return "";
    }

    const positive = summary.change >= 0;
    return `<div class="ba-trend-card">
      <div class="ba-trend-card-title">${icon} ${escapeHtml(title)}</div>
      <div class="ba-trend-stat-row">
        <span>Start</span>
        <strong>${escapeHtml(formatCourseTrendPercent(summary.start))}</strong>
      </div>
      <div class="ba-trend-stat-row">
        <span>Latest</span>
        <strong>${escapeHtml(formatCourseTrendPercent(summary.latest))}</strong>
      </div>
      <div class="ba-trend-change ${positive ? "is-up" : "is-down"}">
        ${(positive ? "+" : "") + escapeHtml(String(summary.change))}%
      </div>
      ${renderCourseTrendPointList(recentPoints)}
    </div>`;
  }

  function renderCourseAttendanceTrendCard(details) {
    const trendWindow = Math.max(1, Number(details?.trend_window) || 20);
    const attendanceWindow = Math.max(
      2,
      Math.min(Number(details?.attendance_window) || 5, trendWindow),
    );
    const attendancePoints = Array.isArray(details?.attendance) ? details.attendance.slice(-trendWindow) : [];
    if (!attendancePoints.length) {
      return "";
    }

    const summary = calculateCourseAttendanceSummary(attendancePoints, attendanceWindow);
    if (!summary) {
      return "";
    }

    let deltaClass = "is-stable";
    let deltaText = summary.comparisonLabel || "Need more sessions for comparison";
    if (summary.delta !== null) {
      if (summary.delta > 0) {
        deltaClass = "is-up";
      } else if (summary.delta < 0) {
        deltaClass = "is-down";
      }

      deltaText =
        summary.comparisonMode === "adaptive-half"
          ? `${summary.delta > 0 ? "+" : ""}${summary.delta} vs earlier ${summary.previousWindowSize}`
          : `${summary.delta > 0 ? "+" : ""}${summary.delta} vs previous ${summary.previousWindowSize}`;
    }

    return `<div class="ba-trend-card">
      <div class="ba-trend-card-title">Attendance</div>
      ${renderCourseAttendanceStatusStrip(attendancePoints)}
      <div class="ba-trend-stat-row">
        <span>Last ${summary.windowSize}</span>
        <strong>${summary.currentCount} attended</strong>
      </div>
      <div class="ba-trend-stat-row">
        <span>Recent rate</span>
        <strong>${summary.currentRate}%</strong>
      </div>
      ${summary.previousWindowSize > 0
        ? `<div class="ba-trend-stat-row">
            <span>Previous rate</span>
            <strong>${summary.previousRate}%</strong>
          </div>`
        : ""}
      <div class="ba-trend-change ${deltaClass}">${escapeHtml(deltaText)}</div>
    </div>`;
  }

  function renderCourseTrendModalContent(student) {
    const details = getCourseStudentTrendDetails(student);
    const trendWindow = details.trend_window;
    const visual = getCourseTrendVisual(calculateCourseOverallTrend(details)) || getCourseTrendVisual("stable");
    const currentLevel = calculateCourseCurrentLevel(details);
    const cards = [
      renderCourseTrendCard("Assignments", "A", details.assignments, trendWindow),
      renderCourseTrendCard("Quizzes", "Q", details.quizzes, trendWindow),
      renderCourseTrendCard("Projects", "P", details.projects, trendWindow),
      renderCourseAttendanceTrendCard(details),
    ]
      .filter(Boolean)
      .join("");

    return `<div class="ba-trend-modal-body">
      <div class="ba-trend-overview ba-trend-overview-${visual.key}">
        <div class="ba-trend-overview-icon">${visual.emoji}</div>
        <div>
          <div class="ba-trend-overview-eyebrow">Overall Trend (last ${trendWindow})</div>
          <div class="ba-trend-overview-title">${escapeHtml(visual.label)} (${escapeHtml(currentLevel.label)})</div>
          ${currentLevel.score !== null
            ? `<div class="ba-trend-overview-subtitle">Current level score: ${escapeHtml(String(currentLevel.score))}%</div>`
            : ""}
        </div>
      </div>
      <div class="ba-trend-grid">
        ${cards || '<div class="ba-trend-empty">No trend detail is available for this student yet.</div>'}
      </div>
    </div>`;
  }

  function renderCourseAttendanceTrendCardFixed(details) {
    const trendWindow = Math.max(1, Number(details?.trend_window) || 20);
    const attendanceWindow = Math.max(
      2,
      Math.min(Number(details?.attendance_window) || 5, trendWindow),
    );
    const attendancePoints = Array.isArray(details?.attendance) ? details.attendance.slice(-trendWindow) : [];
    if (!attendancePoints.length) {
      return "";
    }

    const summary = calculateCourseAttendanceSummary(attendancePoints, attendanceWindow);
    if (!summary) {
      return "";
    }

    let deltaClass = "is-stable";
    let deltaText = summary.comparisonLabel || "Need more sessions for comparison";
    if (summary.delta !== null) {
      if (summary.delta > 0) {
        deltaClass = "is-up";
      } else if (summary.delta < 0) {
        deltaClass = "is-down";
      }

      deltaText =
        summary.comparisonMode === "adaptive-half"
          ? `${summary.delta > 0 ? "+" : ""}${summary.delta} vs earlier ${summary.previousWindowSize}`
          : `${summary.delta > 0 ? "+" : ""}${summary.delta} vs previous ${summary.previousWindowSize}`;
    }

    const strip = `<div class="ba-trend-attendance-strip">${attendancePoints
      .slice(-10)
      .map((point) => {
        const present = Number(point.present) === 1;
        const label = String(point.status || (present ? "P" : "A")).toUpperCase();
        return `<span class="ba-trend-attendance-dot ${present ? "is-present" : "is-absent"}" title="${escapeHtml(
          `${label} - ${formatCourseTrendDate(point.date)}`,
        )}">${escapeHtml(label)}</span>`;
      })
      .join("")}</div>`;

    return `<div class="ba-trend-card">
      <div class="ba-trend-card-title">\uD83D\uDC65 Attendance</div>
      ${strip}
      <div class="ba-trend-stat-row">
        <span>Last ${summary.windowSize}</span>
        <strong>${summary.currentCount} attended</strong>
      </div>
      <div class="ba-trend-stat-row">
        <span>Recent rate</span>
        <strong>${summary.currentRate}%</strong>
      </div>
      ${summary.previousWindowSize > 0
        ? `<div class="ba-trend-stat-row">
            <span>Previous rate</span>
            <strong>${summary.previousRate}%</strong>
          </div>`
        : ""}
      <div class="ba-trend-change ${deltaClass}">${escapeHtml(deltaText)}</div>
    </div>`;
  }

  function buildCourseTrendCardsFixed(details, trendWindow) {
    const renderComponentCard = (title, icon, points) => {
      const recentPoints = Array.isArray(points) ? points.slice(-trendWindow) : [];
      if (!recentPoints.length) {
        return "";
      }

      const summary = calculateCourseTrendComponent(recentPoints);
      if (!summary) {
        return "";
      }

      const deltaBg = summary.change >= 0 ? "#f0fdf4" : "#fef2f2";
      const deltaColor = summary.change >= 0 ? "#10b981" : "#ef4444";
      return `<div class="trend-card">
        <div class="trend-title">${icon} ${escapeHtml(title)}</div>
        <div class="trend-chart">
          ${renderCourseTrendMiniChart(recentPoints)}
        </div>
        <div class="trend-stats">
          <div><span style="color: #6b7280;">Start:</span> <strong>${escapeHtml(String(summary.start))}%</strong></div>
          <div><span style="color: #6b7280;">Latest:</span> <strong>${escapeHtml(String(summary.latest))}%</strong></div>
          <div style="margin-top: 8px; padding: 8px; background: ${deltaBg}; border-radius: 6px;">
            <strong style="color: ${deltaColor}">${summary.change >= 0 ? "+" : ""}${escapeHtml(String(summary.change))}%</strong>
          </div>
        </div>
      </div>`;
    };

    const attendancePoints = Array.isArray(details?.attendance) ? details.attendance : [];
    let attendanceCard = "";
    if (attendancePoints.length) {
      const attendanceWindow = Math.max(
        2,
        Math.min(Number(details?.attendance_window) || 5, trendWindow),
      );
      const summary = calculateCourseAttendanceSummary(attendancePoints, attendanceWindow);
      if (summary) {
        let deltaColor = "#6b7280";
        let deltaBg = "#f3f4f6";
        let deltaText = summary.comparisonLabel || "Need more sessions for comparison";
        if (summary.delta !== null) {
          if (summary.delta > 0) {
            deltaColor = "#10b981";
            deltaBg = "#f0fdf4";
          } else if (summary.delta < 0) {
            deltaColor = "#ef4444";
            deltaBg = "#fef2f2";
          } else {
            deltaColor = "#f59e0b";
            deltaBg = "#fffbeb";
          }
          deltaText =
            summary.comparisonMode === "adaptive-half"
              ? `${summary.delta > 0 ? "+" : ""}${summary.delta} vs earlier ${summary.previousWindowSize}`
              : `${summary.delta > 0 ? "+" : ""}${summary.delta} vs previous ${summary.previousWindowSize}`;
        }

        const strip = `<div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px;">${attendancePoints
          .slice(-10)
          .map((point) => {
            const status = point.status ? String(point.status).toUpperCase() : Number(point.present) === 1 ? "P" : "A";
            let color = "#ef4444";
            if (status === "P" || status === "E") {
              color = "#10b981";
            } else if (status === "L") {
              color = "#f59e0b";
            }
            return `<span style="display: inline-flex; align-items: center; justify-content: center; min-width: 28px; padding: 4px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; color: #fff; background: ${color};">${escapeHtml(
              status,
            )}</span>`;
          })
          .join("")}</div>`;

        attendanceCard = `<div class="trend-card">
          <div class="trend-title">\uD83D\uDC65 Attendance</div>
          ${strip}
          <div class="trend-stats">
            <div><span style="color: #6b7280;">Last ${summary.windowSize}:</span> <strong>${summary.currentCount} attended</strong></div>
            <div><span style="color: #6b7280;">Recent rate:</span> <strong>${summary.currentRate}%</strong></div>
            ${summary.previousWindowSize > 0
              ? `<div><span style="color: #6b7280;">Previous rate:</span> <strong>${summary.previousRate}%</strong></div>`
              : ""}
            <div style="margin-top: 8px; padding: 8px; background: ${deltaBg}; border-radius: 6px;">
              <strong style="color: ${deltaColor}">${escapeHtml(deltaText)}</strong>
            </div>
          </div>
        </div>`;
      }
    }

    return [
      renderComponentCard("Assignments", "\uD83D\uDCDD", details.assignments),
      renderComponentCard("Quizzes", "\uD83E\uDDE0", details.quizzes),
      renderComponentCard("Projects", "\uD83D\uDCCA", details.projects),
      attendanceCard,
    ]
      .filter(Boolean)
      .join("");
  }

  function renderCourseTrendModalContentFixed(student, courseLabel) {
    const details = getCourseStudentTrendDetails(student);
    const trendWindow = details.trend_window;
    const visual = getCourseTrendVisualFixed(calculateCourseOverallTrend(details)) || getCourseTrendVisualFixed("stable");
    const currentLevel = calculateCourseCurrentLevel(details);
    const cards = buildCourseTrendCardsFixed(details, trendWindow);
    const trendColor = visual.key === "up" ? "#10b981" : visual.key === "down" ? "#ef4444" : "#f59e0b";

    return `<div class="trends-modal" id="ba-course-trend-modal" onclick="if(event.target===this)this.remove()">
      <div class="trends-content">
        <div class="trends-header">
          <h3>${escapeHtml(courseLabel || "Course")} - Grade Trends</h3>
          <button type="button" class="close-trends" onclick="this.closest('.trends-modal').remove()" aria-label="Close">x</button>
        </div>
        <div class="trends-indicator" style="background: ${trendColor}20; border-left: 4px solid ${trendColor}; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
          <div style="display: flex; align-items: center; gap: 12px;">
            <span style="font-size: 24px;">${visual.emoji}</span>
            <div>
              <div style="font-size: 13px; color: #6b7280; margin-bottom: 2px;">${escapeHtml(student.fullname || student.username || "Student")}</div>
              <div style="font-size: 14px; color: #6b7280;">Overall Trend (last ${trendWindow})</div>
              <div style="font-size: 18px; font-weight: 700; color: ${trendColor}">${escapeHtml(visual.label)} (${escapeHtml(currentLevel.label)})</div>
              ${currentLevel.score !== null
                ? `<div style="font-size: 13px; color: #6b7280;">Current level score: ${escapeHtml(String(currentLevel.score))}%</div>`
                : ""}
            </div>
          </div>
        </div>
        <div class="trends-grid">
          ${cards || '<div class="trend-card"><div class="trend-title">Trend</div><div class="trend-stats"><div>No trend detail is available for this student yet.</div></div></div>'}
        </div>
      </div>
    </div>`;
  }

  function renderCourseCustomValue(value, column, student, courseId) {
    const display = formatMaacValueForDisplay(value, column);
    if (column.key === "trend") {
      return renderCourseTrendBadgeButtonFixed(display, student, courseId);
    }

    if (column.type === "multi_feedback") {
      const feedbackCount = getCourseFeedbackCount(value);
      return `<button type="button" class="ba-btn ba-btn-sm ba-btn-view ba-course-feedback-open" data-course-feedback="1" data-course-id="${Number(courseId)}" data-student-id="${Number(student.userid || 0)}" data-column-key="${escapeHtml(column.key)}" style="display:inline-flex; align-items:center; gap:4px; padding:4px 8px; border:1px solid #6366f1; color:#6366f1; background:transparent; border-radius:4px; font-size:12px; font-weight:600; cursor:pointer;">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg> View Feedback (${feedbackCount})
      </button>`;
    }

    if (Array.isArray(display)) {
      if (!display.length) {
        return `<span class="ba-maac-inline-empty">-</span>`;
      }

      return `<ul class="ba-maac-bullet-list">${display
        .map((item) => `<li>${escapeHtml(String(item))}</li>`)
        .join("")}</ul>`;
    }

    return escapeHtml(String(display));
  }
  function renderCourseCustomFilterControl(column) {
    if (column.key === "trend") {
      const options = [
        '<option value="">All</option>',
        '<option value="improving">Improving</option>',
        '<option value="stable">Stable</option>',
        '<option value="declining">Declining</option>',
      ];

      return `
          <div class="ba-filter-item-modern">
              <div class="ba-filter-header-row">
                  <span class="ba-filter-label">${escapeHtml(column.label)}</span>
              </div>
              <select id="f-maac-${column.key}" class="ba-select-small" style="width:100%; box-sizing: border-box;" onchange="applyFilters()">
                  ${options.join("")}
              </select>
          </div>`;
    }

    if (column.type === "number") {
      return `
          <div class="ba-filter-item-modern">
              <div class="ba-filter-header-row">
                  <span class="ba-filter-label">${escapeHtml(column.label)}</span>
              </div>
              <div class="ba-range-wrapper">
                  <input type="number" class="ba-range-input" id="f-maac-${column.key}-min"
                         value="" step="0.1" onchange="applyFilters()" placeholder="${column.min ?? "Min"}">
                  <span class="ba-range-divider">-</span>
                  <input type="number" class="ba-range-input" id="f-maac-${column.key}-max"
                         value="" step="0.1" onchange="applyFilters()" placeholder="${column.max ?? "Max"}">
              </div>
          </div>`;
    }

    if (column.type === "dropdown" && column.selection === "multi") {
      return `
        <div class="ba-filter-item-modern">
            <div class="ba-filter-header-row">
                <span class="ba-filter-label">${escapeHtml(column.label)}</span>
            </div>
            <div class="ba-maac-filter-checkbox-list">
                ${(column.options || [])
                  .map(
                    (option) => `
                      <label class="ba-maac-filter-checkbox">
                        <input
                          type="checkbox"
                          data-course-filter-key="${escapeHtml(column.key)}"
                          data-course-filter-type="dropdown-multi"
                          value="${escapeHtml(option)}"
                          onchange="applyFilters()"
                        >
                        <span>${escapeHtml(option)}</span>
                      </label>`,
                  )
                  .join("")}
            </div>
        </div>`;
    }

    if (column.type === "dropdown" || column.type === "boolean") {
      const options =
        column.type === "boolean"
          ? ['<option value="">All</option><option value="1">Yes</option><option value="0">No</option>']
          : ['<option value="">All</option>'].concat(
              (column.options || []).map(
                (option) =>
                  `<option value="${escapeHtml(option)}">${escapeHtml(option)}</option>`,
              ),
            );

      return `
          <div class="ba-filter-item-modern">
              <div class="ba-filter-header-row">
                  <span class="ba-filter-label">${escapeHtml(column.label)}</span>
              </div>
              <select id="f-maac-${column.key}" class="ba-select-small" style="width:100%; box-sizing: border-box;" onchange="applyFilters()">
                  ${options.join("")}
              </select>
          </div>`;
    }

    return `
        <div class="ba-filter-item-modern">
            <div class="ba-filter-header-row">
                <span class="ba-filter-label">${escapeHtml(column.label)}</span>
            </div>
            <input type="text" id="f-maac-${column.key}" class="ba-range-input" style="width:100%; box-sizing: border-box;" placeholder="Search..." onkeyup="applyFilters()">
        </div>`;
  }

  function getSelectedValues(selectEl) {
    if (!selectEl) return [];
    return Array.from(selectEl.selectedOptions || [])
      .map((option) => option.value)
      .filter((value) => value !== "");
  }

  function getCourseCheckedValues(key) {
    return Array.from(
      document.querySelectorAll(
        `[data-course-filter-key="${key}"][data-course-filter-type="dropdown-multi"]:checked`,
      ),
    ).map((input) => input.value);
  }

  function getCourseAdvancedFilterState() {
    const toggleEl = document.getElementById("f-metric-toggle");
    const isCompMode = toggleEl ? toggleEl.checked : false;
    const maacColumns = getCourseMaacColumns(CURRENT_COURSE.courseid);
    const visibleCategories = getVisibleCourseCategories(CURRENT_COURSE);

    return {
      search: (document.getElementById("f-search")?.value || "").toLowerCase(),
      status: document.getElementById("f-status")?.value || "",
      isCompMode,
      ranges: visibleCategories.map((c, i) => ({
        name: c.categoryname,
        min: parseFloat(document.getElementById(`f-${i}-min`)?.value || "") || 0,
        max:
          parseFloat(document.getElementById(`f-${i}-max`)?.value || "") ||
          (c.categoryname === "MAAC Ratings" ? 9 : 100),
        isMaac: c.categoryname === "MAAC Ratings",
        isAtt: isAttendance(c.categoryname),
      })),
      maacColumns,
      customFilters: maacColumns.reduce((acc, column) => {
        if (column.key === "trend") {
          acc[column.key] = {
            value: (document.getElementById(`f-maac-${column.key}`)?.value || "").toLowerCase(),
          };
        } else if (column.type === "number") {
          acc[column.key] = {
            min: parseFloat(document.getElementById(`f-maac-${column.key}-min`)?.value || ""),
            max: parseFloat(document.getElementById(`f-maac-${column.key}-max`)?.value || ""),
          };
        } else if (column.type === "dropdown" && column.selection === "multi") {
          acc[column.key] = { values: getCourseCheckedValues(column.key) };
        } else {
          acc[column.key] = {
            value: (document.getElementById(`f-maac-${column.key}`)?.value || "").toLowerCase(),
          };
        }
        return acc;
      }, {}),
    };
  }

  function matchesCourseCustomFilter(value, column, filter) {
    if (column.key === "trend") {
      return !filter.value || String(value || "").toLowerCase() === filter.value;
    }

    if (column.type === "number") {
      const numeric = parseFloat(value);
      const hasFilter = !isNaN(filter.min) || !isNaN(filter.max);
      if (isNaN(numeric)) {
        return !hasFilter;
      }
      if (!isNaN(filter.min) && numeric < filter.min) return false;
      if (!isNaN(filter.max) && numeric > filter.max) return false;
      return true;
    }

    if (column.type === "boolean") {
      if (!filter.value) return true;
      return (value ? "1" : "0") === filter.value;
    }

    if (column.type === "dropdown" && column.selection === "multi") {
      if (!filter.values || !filter.values.length) return true;
      const values = Array.isArray(value)
        ? value.map((item) => String(item))
        : value
          ? [String(value)]
          : [];
      return filter.values.some((selected) => values.includes(selected));
    }

    if (column.type === "dropdown") {
      return !filter.value || String(value || "").toLowerCase() === filter.value;
    }

    return !filter.value || String(value || "").toLowerCase().includes(filter.value);
  }

  function getCourseMetricValue(data, range, isCompMode) {
    if (range.isMaac || range.isAtt) {
      return data.pct || 0;
    }
    return isCompMode ? data.comp || 0 : data.pct || 0;
  }

  function getCourseMetricDisplay(data, categoryName, isCompMode) {
    const isMaac = categoryName === "MAAC Ratings";
    const isAtt = isAttendance(categoryName);

    if (isMaac) {
      const hasValue = data.pct !== null && data.pct !== undefined;
      const value = hasValue ? parseFloat(data.pct).toFixed(1) : "-";
      return {
        label: value,
        className: hasValue
          ? (data.pct || 0) * (100 / 9) >= 75
            ? "high"
            : (data.pct || 0) * (100 / 9) >= 50
              ? "medium"
              : "low"
          : "medium",
        exportValue: value,
      };
    }

    if (isAtt) {
      const hasValue = data.pct !== null && data.pct !== undefined;
      const value = hasValue ? parseFloat(data.pct).toFixed(0) : "-";
      return {
        label: hasValue ? `${value}%` : "-",
        className: hasValue
          ? (data.pct || 0) >= 75
            ? "high"
            : (data.pct || 0) >= 50
              ? "medium"
              : "low"
          : "medium",
        exportValue: hasValue ? `${value}%` : "-",
      };
    }

    const metric = isCompMode ? data.comp : data.pct;
    const hasValue = metric !== null && metric !== undefined;
    const numeric = hasValue ? parseFloat(metric) : 0;
    const value = hasValue ? numeric.toFixed(0) : "-";
    return {
      label: hasValue ? `${value}%` : "-",
      className: numeric >= 75 ? "high" : numeric >= 50 ? "medium" : "low",
      exportValue: hasValue ? `${value}%` : "-",
    };
  }

  function formatCourseOverallPerformance(value) {
    if (!Number.isFinite(value)) {
      return "-";
    }
    return `${parseFloat(value.toFixed(2))}%`;
  }

  function getCourseOverallPerformanceValue(student, categories, isCompMode) {
    const values = (categories || [])
      .filter((category) => category.categoryname !== "MAAC Ratings")
      .map((category) => {
        const data = student.cats?.[category.categoryname];
        if (!data) {
          return null;
        }
        const value = getCourseMetricValue(
          data,
          {
            isMaac: false,
            isAtt: isAttendance(category.categoryname),
          },
          isCompMode,
        );
        const numeric = parseFloat(value);
        return Number.isFinite(numeric) ? numeric : null;
      })
      .filter((value) => value !== null);

    if (!values.length) {
      return null;
    }

    return values.reduce((sum, value) => sum + value, 0) / values.length;
  }

  function getCourseOverallPerformanceDisplay(student, categories, isCompMode) {
    const value = getCourseOverallPerformanceValue(student, categories, isCompMode);
    if (value === null) {
      return {
        label: "-",
        className: "loading",
        exportValue: "-",
      };
    }

    return {
      label: formatCourseOverallPerformance(value),
      className: value >= 75 ? "high" : value >= 50 ? "medium" : "low",
      exportValue: formatCourseOverallPerformance(value),
    };
  }

  function getFilteredCourseStudents() {
    const filterState = getCourseAdvancedFilterState();
    const moduleCollapsed = !!COURSE_FILTER_COLLAPSED_GROUPS[`${CURRENT_COURSE.courseid}:module`];
    const visibleCategories = moduleCollapsed ? [] : getVisibleCourseCategories(CURRENT_COURSE);
    const groupedMaacColumns = getCourseMaacColumnGroups(CURRENT_COURSE.courseid).map((group) => {
      const collapsed = !!COURSE_FILTER_COLLAPSED_GROUPS[
        `${CURRENT_COURSE.courseid}:group:${group.name}`
      ];
      return {
        ...group,
        collapsed,
        visibleColumns: collapsed ? [] : group.columns || [],
      };
    });
    const visibleMaacColumns = groupedMaacColumns.flatMap((group) => group.visibleColumns || []);
    const filtered = window.filterData.filter((student) => {
      if (
        filterState.search &&
        !student.fullname.toLowerCase().includes(filterState.search) &&
        !student.username.toLowerCase().includes(filterState.search)
      ) {
        return false;
      }

      for (const range of filterState.ranges) {
        const data = student.cats[range.name] || { pct: 0, comp: 0, earned: 0 };
        const value = getCourseMetricValue(data, range, filterState.isCompMode);
        if (value < range.min || value > range.max) {
          return false;
        }
      }

      for (const column of filterState.maacColumns) {
        const value = getCourseStudentCustomValue(student, column);
        if (!matchesCourseCustomFilter(value, column, filterState.customFilters[column.key] || {})) {
          return false;
        }
      }

      if (filterState.status) {
        const company = CRM_CACHE[student.username] || "Not Placed";
        const isPlaced =
          company !== "Not Placed" && company !== "Error" && company !== "Not Found";
        if (filterState.status === "Placed" && !isPlaced) return false;
        if (filterState.status === "Not Placed" && isPlaced) return false;
      }

      return true;
    });

    return {
      ...filterState,
      orderedMaacColumns: getOrderedCourseMaacColumns(CURRENT_COURSE.courseid),
      visibleCategories,
      moduleCollapsed,
      groupedMaacColumns,
      visibleMaacColumns,
      filtered,
    };
  }

  window.toggleCourseFilterGroup = function (courseId, groupKey) {
    const stateKey = `${courseId}:${groupKey}`;
    COURSE_FILTER_COLLAPSED_GROUPS[stateKey] = !COURSE_FILTER_COLLAPSED_GROUPS[stateKey];

    const tableWrap = document.querySelector("#ba-course-filter-section .ba-table-wrap");
    const scrollLeft = tableWrap ? tableWrap.scrollLeft : 0;

    if (CURRENT_COURSE && CURRENT_COURSE.courseid === courseId) {
      renderCourseFilterSection(CURRENT_COURSE);

      const newTableWrap = document.querySelector("#ba-course-filter-section .ba-table-wrap");
      if (newTableWrap) {
        newTableWrap.scrollLeft = scrollLeft;
      }
    }
  };

  function renderCourseFilterSection(c) {
    const container = document.getElementById("ba-course-filter-section");
    if (!container) return;
    container.innerHTML = renderFilterSection(c);
    initFilter(c);
    enableCourseFilterColumnResize(c.courseid);
  }

  function renderFilterSection(c) {
    const maacColumns = getCourseMaacColumns(c.courseid);
    const maacGroups = getCourseMaacColumnGroups(c.courseid);
    const moduleCollapsed = !!COURSE_FILTER_COLLAPSED_GROUPS[`${c.courseid}:module`];
    const allVisibleCategories = getVisibleCourseCategories(c);
    const visibleCategories = moduleCollapsed ? [] : allVisibleCategories;
    const visibleGroups = maacGroups
      .map((group) => {
        const collapsed = !!COURSE_FILTER_COLLAPSED_GROUPS[`${c.courseid}:group:${group.name}`];
        return {
          ...group,
          collapsed,
          visibleColumns: collapsed ? [] : group.columns || [],
        };
      })
      .filter((group) => (group.columns || []).length > 0);
    const leafColumns = [];
    const addLeafColumn = (key, width) => {
      leafColumns.push({
        key,
        width: getCourseFilterColumnWidth(c.courseid, key, width),
      });
      return leafColumns.length - 1;
    };
    const groupHeaders = [
      renderResizableColumnHeader({
        label: "Student Name",
        columnKey: "student",
        columnIndex: addLeafColumn("student", 220),
        courseId: c.courseid,
        sortable: true,
        sortIndex: 0,
        numericSort: false,
        rowSpan: 2,
        className: "ba-maac-group-head ba-maac-sticky-col ba-maac-student-head ba-maac-front-head",
      }),
    ];
    const subHeaders = [];
    let sortIndex = 1;

    if (moduleCollapsed) {
      groupHeaders.push(
        renderResizableColumnHeader({
          label: `<button type="button" class="ba-maac-group-toggle" onclick="toggleCourseFilterGroup(${c.courseid}, 'module')">
            <span class="ba-maac-group-toggle-icon" aria-hidden="true">+</span>
            <span class="ba-maac-group-toggle-label">Module Performance</span>
          </button>`,
          columnKey: "module:collapsed",
          columnIndex: addLeafColumn("module:collapsed", 180),
          courseId: c.courseid,
          rowSpan: 2,
          className: "ba-maac-group-head",
        }),
      );
    } else {
      groupHeaders.push(
        renderCourseGroupHeader({
          label: "Module Performance",
          courseId: c.courseid,
          groupKey: "module",
          colSpan: Math.max(visibleCategories.length + 1, 1),
        }),
      );
      visibleCategories.forEach((cat) => {
        subHeaders.push(
          renderResizableColumnHeader({
            label: escapeHtml(cat.categoryname),
            columnKey: `category:${cat.categoryname}`,
            columnIndex: addLeafColumn(`category:${cat.categoryname}`, 170),
            courseId: c.courseid,
            sortable: true,
            sortIndex: sortIndex++,
            numericSort: true,
            className: "ba-maac-col-head",
          }),
        );
      });
      subHeaders.push(
        renderResizableColumnHeader({
          label: "Overall Performance",
          columnKey: "category:overall_performance",
          columnIndex: addLeafColumn("category:overall_performance", 180),
          courseId: c.courseid,
          sortable: true,
          sortIndex: sortIndex++,
          numericSort: true,
          className: "ba-maac-col-head",
        }),
      );
    }

    visibleGroups.forEach((group) => {
      const groupKey = JSON.stringify(`group:${group.name}`);
      if (group.collapsed) {
        groupHeaders.push(
          renderResizableColumnHeader({
            label: `<button type="button" class="ba-maac-group-toggle" onclick='toggleCourseFilterGroup(${c.courseid}, ${groupKey})'>
              <span class="ba-maac-group-toggle-icon" aria-hidden="true">+</span>
              <span class="ba-maac-group-toggle-label">${escapeHtml(group.name)}</span>
            </button>`,
            columnKey: `group:${group.name}:collapsed`,
            columnIndex: addLeafColumn(`group:${group.name}:collapsed`, 180),
            courseId: c.courseid,
            rowSpan: 2,
            className: "ba-maac-group-head",
          }),
        );
        return;
      }

      groupHeaders.push(
        renderCourseGroupHeader({
          label: group.name,
          courseId: c.courseid,
          groupKey: `group:${group.name}`,
          colSpan: Math.max(group.visibleColumns.length, 1),
        }),
      );
      group.visibleColumns.forEach((column) => {
        subHeaders.push(
          renderResizableColumnHeader({
            label: escapeHtml(column.label),
            columnKey: `custom:${column.key}`,
            columnIndex: addLeafColumn(`custom:${column.key}`, 170),
            courseId: c.courseid,
            sortable: true,
            sortIndex: sortIndex++,
            numericSort: column.type === "number",
            className: "ba-maac-col-head",
          }),
        );
      });
    });
    // GENERATE SLIDER-STYLE INPUTS FOR EACH CATEGORY
    let inputs = allVisibleCategories
      .map((cat, i) => {
        // MAAC is on a 0-9 scale; other category metrics are percentages.
        const isMaac = cat.categoryname === "MAAC Ratings";
        const maxVal = isMaac ? 9 : 100;
        const step = isMaac ? 0.1 : 1;

        return `
        <div class="ba-filter-item-modern">
            <div class="ba-filter-header-row">
                <span class="ba-filter-label">${cat.categoryname}</span>
            </div>
            <div class="ba-range-wrapper">
                <input type="number" class="ba-range-input" id="f-${i}-min"
                       value="0" min="0" max="${maxVal}" step="${step}"
                       onchange="applyFilters()" placeholder="Min">

                <span class="ba-range-divider">-</span>

                <input type="number" class="ba-range-input" id="f-${i}-max"
                       value="${maxVal}" min="0" max="${maxVal}" step="${step}"
                       onchange="applyFilters()" placeholder="Max">
            </div>
        </div>`;
      })
      .join("");

    const customFilters = maacColumns.map((column) => renderCourseCustomFilterControl(column)).join("");

    return `
        <div class="ba-filter-section">
            <div class="ba-filter-header">
                <h3>Advanced Filter</h3>
                <button class="ba-btn ba-btn-success" onclick="exportFilter()">Export Filtered</button>
            </div>
            <div class="ba-filter-body">
                <div class="ba-filter-sidebar">
                    <div class="ba-sidebar-head">
                        Filters
                        <span onclick="resetFilter()" style="cursor:pointer;color:var(--primary);font-size:12px;font-weight:600;">Reset All</span>
                    </div>

                    <div class="ba-filter-toggle">
                        <span class="ba-ft-label">Mode:</span>
                        <div class="ba-toggle-wrapper">
                            <span class="ba-toggle-text active" id="t-grade">Grade</span>
                            <label class="ba-switch">
                                <input type="checkbox" id="f-metric-toggle" onchange="applyFilters()">
                                <span class="ba-slider round"></span>
                            </label>
                            <span class="ba-toggle-text" id="t-comp">Comp.</span>
                        </div>
                    </div>

                    ${inputs}
                    ${customFilters}

                    <input type="hidden" id="f-status" value="">
                </div>

                <div class="ba-filter-main">
                    <div class="ba-table-controls">
                        <span id="f-count" style="font-weight:600; color:var(--text-gray);">0 students</span>
                        <input type="text" id="f-search" placeholder="Search student name..." onkeyup="applyFilters()">
                    </div>
                    <div class="ba-table-wrap">
                        <table class="ba-table ba-grouped-filter-table ba-resizable-table">
                          <colgroup>
                            ${leafColumns.map((column) => `<col style="width:${column.width}px">`).join("")}
                          </colgroup>
                          <thead>
                            <tr>${groupHeaders.join("")}</tr>
                            <tr>${subHeaders.join("")}</tr>
                          </thead>
                          <tbody id="f-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>`;
  }

  function initFilter(c) {
    window.filterData = [];
    const map = {};
    const maacData = MAAC_COURSE_CACHE[c.courseid];
    c.categories.forEach((cat) => {
      if (cat.categoryname === "MAAC Ratings" && !isBuiltinColumnEnabled(c.courseid, "maac_rating")) {
        return;
      }
      cat.studentGrades.forEach((s) => {
        if (!map[s.username]) map[s.username] = { ...s, cats: {}, maaccustom: {} };
        map[s.username].cats[cat.categoryname] = {
          pct: s.percentage,
          comp: s.completionRate, // FIX: Store true completion rate
          earned: s.totalEarned,
        };
      });
    });

    if (maacData && maacData.students) {
      maacData.students.forEach((student) => {
        if (!map[student.username]) {
          map[student.username] = {
            userid: student.userid,
            fullname: student.fullname,
            username: student.username,
            cats: {},
            maaccustom: {},
            maac_rating: student.maac_rating,
            trend_details: student.trend_details || null,
          };
        }
        map[student.username].maaccustom = student.custom || {};
        map[student.username].maac_rating = student.maac_rating;
        map[student.username].trend_details = student.trend_details || null;
      });
    }
    window.filterData = Object.values(map);
    applyFilters();
  }

  // 1. UPDATE: Filtering Logic to respect Toggle, MAAC, & True Completion
  window.applyFilters = function () {
    const {
      filtered,
      isCompMode,
      visibleCategories,
      visibleMaacColumns,
      moduleCollapsed,
      groupedMaacColumns,
    } =
      getFilteredCourseStudents();
    const toggleEl = document.getElementById("f-metric-toggle");

    if (toggleEl) {
      document
        .getElementById("t-grade")
        .classList.toggle("active", !isCompMode);
      document.getElementById("t-comp").classList.toggle("active", isCompMode);
    }

    document.getElementById("f-count").innerText =
      `Found ${filtered.length} students`;
    const tbody = document.getElementById("f-body");

    let html = "";
    let toFetch = [];

    if (filtered.length === 0) {
      html =
        '<tr><td colspan="100" style="text-align:center;padding:30px;color:#999">No matches</td></tr>';
    } else {
      filtered.forEach((s) => {
        let cols = `<td class="ba-maac-sticky-col ba-maac-student-cell ba-maac-front-cell"><b>${s.fullname}</b><br><small style="color:#888">${s.username}</small></td>`;

        if (moduleCollapsed) {
          cols += `<td class="ba-maac-collapsed-col"></td>`;
        } else {
          visibleCategories.forEach((c) => {
            const data = s.cats[c.categoryname] || { pct: 0, comp: 0, earned: 0 };
            const metric = getCourseMetricDisplay(data, c.categoryname, isCompMode);
            cols += `<td><span class="ba-filter-percentage ${metric.className}">${metric.label}</span></td>`;
          });
          const overall = getCourseOverallPerformanceDisplay(s, visibleCategories, isCompMode);
          cols += `<td><span class="ba-filter-percentage ba-overall-performance ${overall.className}">${overall.label}</span></td>`;
        }

        groupedMaacColumns.forEach((group) => {
          if (group.collapsed) {
            cols += `<td class="ba-maac-collapsed-col"></td>`;
            return;
          }

          (group.visibleColumns || []).forEach((column) => {
            const value = getCourseStudentCustomValue(s, column);
            if (column.type === "number") {
              const display = formatMaacValueForDisplay(value, column);
              cols += `<td><span class="ba-filter-percentage medium">${escapeHtml(String(display))}</span></td>`;
            } else {
              cols += `<td>${renderCourseCustomValue(value, column, s, CURRENT_COURSE?.courseid || 0)}</td>`;
            }
          });
        });

        if (!CRM_CACHE[s.username]) {
          toFetch.push(s.username);
        }

        html += `<tr>${cols}</tr>`;
      });
    }
    tbody.innerHTML = html;

    if (toFetch.length) fetchUnifiedData(toFetch);

    // Reset Sort Indicators
    const table = document.getElementById("f-body").closest("table");
    if (table) {
      table
        .querySelectorAll("th")
        .forEach((th) => th.classList.remove("sort-asc", "sort-desc"));
    }
  };

  // 2. UPDATE: Export Logic to match the Filter Logic
  window.exportFilter = function () {
    if (!window.filterData || !CURRENT_COURSE)
      return showToast("No data to export", "warn");
    const { filtered, isCompMode, visibleCategories, visibleMaacColumns } =
      getFilteredCourseStudents();

    if (filtered.length === 0)
      return showToast("No filtered data to export", "warn");

    // 3. Build CSV
    let csv = "Username,Name,";
    visibleCategories.forEach((c) => (csv += `"${c.categoryname}",`));
    csv += `"Overall Performance",`;
    visibleMaacColumns.forEach((column) => (csv += `"${column.label}",`));
    csv += "\n";

    filtered.forEach((s) => {
      csv += `${s.username},"${s.fullname}",`;

      visibleCategories.forEach((c) => {
        const data = s.cats[c.categoryname] || { pct: 0, comp: 0, earned: 0 };
        const metric = getCourseMetricDisplay(data, c.categoryname, isCompMode);
        csv += `"${metric.exportValue}",`;
      });

      const overall = getCourseOverallPerformanceDisplay(s, visibleCategories, isCompMode);
      csv += `"${overall.exportValue}",`;

      visibleMaacColumns.forEach((column) => {
        const value = getCourseStudentCustomValue(s, column);
        const display = formatMaacValueForExport(value, column);
        csv += `"${display}",`;
      });
      csv += "\n";
    });

    downloadCSV(csv, `Filter_Export_${CURRENT_COURSE.shortname}.csv`);
  };

  // Helper: Reset all filters to default
  window.resetFilter = function () {
    if (!CURRENT_COURSE) return;

    // Reset the same controls and category set used to render this filter panel.
    const search = document.getElementById("f-search");
    if (search) {
      search.value = "";
    }

    const status = document.getElementById("f-status");
    if (status) {
      status.value = "";
    }

    const toggle = document.getElementById("f-metric-toggle");
    if (toggle) {
      toggle.checked = false;
    }

    // The input IDs are based on the visible categories, not every raw course category.
    getVisibleCourseCategories(CURRENT_COURSE).forEach((c, i) => {
      const minEl = document.getElementById(`f-${i}-min`);
      const maxEl = document.getElementById(`f-${i}-max`);
      const isMaac = c.categoryname === "MAAC Ratings";

      if (minEl) minEl.value = "0";
      if (maxEl) maxEl.value = isMaac ? "9" : "100";
    });

    getCourseMaacColumns(CURRENT_COURSE.courseid).forEach((column) => {
      if (column.type === "number") {
        const minEl = document.getElementById(`f-maac-${column.key}-min`);
        const maxEl = document.getElementById(`f-maac-${column.key}-max`);
        if (minEl) minEl.value = "";
        if (maxEl) maxEl.value = "";
      } else {
        const el = document.getElementById(`f-maac-${column.key}`);
        const checkboxes = document.querySelectorAll(
          `[data-course-filter-key="${column.key}"][data-course-filter-type="dropdown-multi"]`,
        );
        if (checkboxes.length) {
          checkboxes.forEach((checkbox) => {
            checkbox.checked = false;
          });
        } else if (el) {
          el.value = "";
        }
      }
    });

    applyFilters();
    showToast("Filters reset", "info");
  };

  function getCourseById(courseId) {
    return (BATCH_DATA?.courses || []).find((item) => Number(item.courseid) === Number(courseId));
  }

  function renderCourseSummaryModal() {
    if (!COURSE_SUMMARY_MODAL) {
      return;
    }

    const course = getCourseById(COURSE_SUMMARY_MODAL.courseid);
    if (!course) {
      return;
    }

    const summary = COURSE_SUMMARY_MODAL.summary ?? course.summary ?? "";
    const canEdit = !!course.cansummaryedit;
    const isEditing = COURSE_SUMMARY_MODAL.mode === "edit";
    const existing = String(summary || "").trim();
    const bodyHtml = isEditing
      ? `<div class="ba-course-summary-form">
          <label for="ba-course-summary-input">Summary</label>
          <textarea id="ba-course-summary-input" class="ba-course-summary-textarea" rows="7">${escapeHtml(summary)}</textarea>
        </div>`
      : existing
        ? `<div class="ba-course-summary-text">${escapeHtml(existing)}</div>`
        : `<div class="ba-course-summary-empty">No summary given yet</div>`;
    const footerHtml = isEditing
      ? `<button type="button" class="ba-btn" id="ba-course-summary-cancel">Cancel</button>
         <button type="button" class="ba-btn ba-btn-success" id="ba-course-summary-save">Save</button>`
      : canEdit
        ? `<button type="button" class="ba-btn ba-btn-view" id="ba-course-summary-edit">${existing ? "Edit" : "Add Summary"}</button>`
        : "";

    const modalHtml = `<div class="ba-modal-overlay ba-course-summary-modal" id="ba-course-summary-modal">
      <div class="ba-modal-container ba-course-summary-dialog">
        <div class="ba-modal-header">
          <h3>${escapeHtml(course.coursename || course.shortname || "Course")} : Summary</h3>
          <button type="button" class="ba-modal-close" id="ba-course-summary-close" aria-label="Close">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </div>
        <div class="ba-modal-body ba-course-summary-body">${bodyHtml}</div>
        ${footerHtml ? `<div class="ba-course-summary-footer">${footerHtml}</div>` : ""}
      </div>
    </div>`;

    document.getElementById("ba-course-summary-modal")?.remove();
    document.body.insertAdjacentHTML("beforeend", modalHtml);
    bindCourseSummaryModalEvents();
  }

  function closeCourseSummaryModal() {
    if (COURSE_SUMMARY_ESCAPE_HANDLER) {
      document.removeEventListener("keydown", COURSE_SUMMARY_ESCAPE_HANDLER);
      COURSE_SUMMARY_ESCAPE_HANDLER = null;
    }
    COURSE_SUMMARY_MODAL = null;
    document.getElementById("ba-course-summary-modal")?.remove();
  }

  function openCourseSummaryModal(courseId) {
    const course = getCourseById(courseId);
    if (!course) {
      showToast("Unable to load course summary", "warn");
      return;
    }
    COURSE_SUMMARY_MODAL = {
      courseid: Number(course.courseid),
      mode: "view",
      summary: course.summary || "",
    };
    renderCourseSummaryModal();
  }

  function bindCourseSummaryModalEvents() {
    const modal = document.getElementById("ba-course-summary-modal");
    if (!modal || !COURSE_SUMMARY_MODAL) {
      return;
    }

    modal.addEventListener("click", (event) => {
      if (event.target === modal) {
        closeCourseSummaryModal();
      }
    });

    if (COURSE_SUMMARY_ESCAPE_HANDLER) {
      document.removeEventListener("keydown", COURSE_SUMMARY_ESCAPE_HANDLER);
    }
    COURSE_SUMMARY_ESCAPE_HANDLER = (event) => {
      if (event.key === "Escape") {
        closeCourseSummaryModal();
      }
    };
    document.addEventListener("keydown", COURSE_SUMMARY_ESCAPE_HANDLER);

    document.getElementById("ba-course-summary-close")?.addEventListener("click", closeCourseSummaryModal);
    document.getElementById("ba-course-summary-edit")?.addEventListener("click", () => {
      COURSE_SUMMARY_MODAL.mode = "edit";
      renderCourseSummaryModal();
    });
    document.getElementById("ba-course-summary-cancel")?.addEventListener("click", () => {
      COURSE_SUMMARY_MODAL.mode = "view";
      renderCourseSummaryModal();
    });
    document.getElementById("ba-course-summary-save")?.addEventListener("click", saveCourseSummary);
  }

  async function saveCourseSummary() {
    if (!COURSE_SUMMARY_MODAL) {
      return;
    }

    const course = getCourseById(COURSE_SUMMARY_MODAL.courseid);
    const textarea = document.getElementById("ba-course-summary-input");
    const saveBtn = document.getElementById("ba-course-summary-save");
    const summary = textarea ? textarea.value.trim() : "";
    if (!course) {
      showToast("Unable to save course summary", "error");
      return;
    }

    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.textContent = "Saving...";
    }

    try {
      const body = new URLSearchParams();
      body.set("sesskey", BA_SESSKEY);
      body.set("courseid", String(course.courseid));
      body.set("summary", summary);
      const summaryUrl = new URL(BASE_URL, window.location.href);
      summaryUrl.searchParams.set("action", "savecoursesummary");
      const response = await fetch(summaryUrl.toString(), {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/x-www-form-urlencoded",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: body.toString(),
      });
      const responseText = await response.text();
      let result = {};
      try {
        result = responseText ? JSON.parse(responseText) : {};
      } catch (parseError) {
        const message = responseText.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();
        throw new Error(message || "Server returned an invalid response while saving the summary");
      }
      if (!response.ok || result.error) {
        throw new Error(result.error || "Unable to save course summary");
      }
      course.summary = result.summary || "";
      COURSE_SUMMARY_MODAL.summary = course.summary;
      COURSE_SUMMARY_MODAL.mode = "view";
      renderCourseSummaryModal();
      showToast("Summary saved", "success");
    } catch (error) {
      showToast(error.message || "Unable to save course summary", "error");
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.textContent = "Save";
      }
    }
  }
  window.openCategoryModal = function (cid, cname) {
    const c = BATCH_DATA.courses.find((x) => x.courseid == cid);
    const cat = c.categories.find((x) => x.categoryname === cname);

    // NEW: Check if it is MAAC or Attendance
    const isMaac = cname === "MAAC Ratings";
    const isAtt = isAttendance(cname);
    const hideComp = isMaac || isAtt; // Flag to hide completion logic

    const avgG = getAvgGrade(cat);
    const avgC = getCompletionRate(cat);
    const totalStudents = cat.studentGrades.length;

    // Create a safe ID without spaces for the export function
    const safeId = cname.replace(/[^a-zA-Z0-9]/g, "") + "-data";

    // 1. Generate Rows (Hide Completion cell if MAAC/Attendance)
    let rows = cat.studentGrades
      .map((s) => {
        // FIX: Use the true completion rate from PHP!
        const comp = s.completionRate !== undefined ? s.completionRate : 0;
        const displayGrade =
          s.percentage === null
            ? "-"
            : isMaac
              ? parseFloat(s.percentage).toFixed(1)
              : s.percentage + "%";

        let rowHtml = `<tr>
              <td>${s.username}</td>
              <td><strong>${s.fullname}</strong></td>
              <td>${displayGrade}</td>`;

        if (!hideComp) {
          rowHtml += `<td>${comp}%</td>`;
        }
        rowHtml += `</tr>`;
        return rowHtml;
      })
      .join("");

    // 2. Generate Toolbar Stats (Hide Completion avg if MAAC/Attendance)
    let statsHtml = `
        <span class="ba-stat-item"><strong>${totalStudents}</strong> Students</span>
        <span class="ba-dot">-</span>
        <span class="ba-stat-item">Avg Grade: <strong>${isMaac ? avgG : avgG + "%"}</strong></span>`;

    if (!hideComp) {
      statsHtml += `<span class="ba-dot">-</span><span class="ba-stat-item">Completion: <strong>${avgC}%</strong></span>`;
    }

    const toolbarHtml = `
        <div class="ba-modal-toolbar">
            <div class="ba-mt-stats">
                ${statsHtml}
            </div>
            <button class="ba-btn-download-sm" onclick="exportTable('${safeId}', '${cname}')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Download CSV
            </button>
        </div>
      `;

    // 3. Generate Table Headers (Hide Completion column if MAAC/Attendance)
    let thHtml = `
        <th class="sortable" onclick="sortTable(this, 0, false)">Username</th>
        <th class="sortable" onclick="sortTable(this, 1, false)">Name</th>
        <th class="sortable" onclick="sortTable(this, 2, true)">Grade</th>`;
    if (!hideComp) {
      thHtml += `<th class="sortable" onclick="sortTable(this, 3, true)">Completion</th>`;
    }

    // 4. Generate Table Footer
    let tfHtml = `<tr><td colspan="2" style="text-align:right;color:#64748b;">TOTAL AVERAGE:</td><td>${isMaac ? avgG : avgG + "%"}</td>`;
    if (!hideComp) {
      tfHtml += `<td>${avgC}%</td>`;
    }
    tfHtml += `</tr>`;

    // 5. Build Final Table
    const tableHtml = `
        <div class="table-scroll">
            <table class="ba-table" id="${safeId}">
                <thead><tr>${thHtml}</tr></thead>
                <tbody>${rows}</tbody>
                <tfoot>${tfHtml}</tfoot>
            </table>
        </div>
      `;

    showModal(cname, toolbarHtml + tableHtml);
  };

  //===============

  function showModal(title, content) {
    const overlay = document.createElement("div");
    overlay.className = "ba-modal-overlay";

    const container = document.createElement("div");
    container.className = "ba-modal-container";

    const header = document.createElement("div");
    header.className = "ba-modal-header";

    const titleEl = document.createElement("h3");
    titleEl.textContent = title;

    const closeBtn = document.createElement("button");
    closeBtn.className = "ba-modal-close";
    closeBtn.type = "button";
    closeBtn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';

    const body = document.createElement("div");
    body.className = "ba-modal-body";
    body.innerHTML = content;

    closeBtn.addEventListener("click", () => overlay.remove());
    overlay.addEventListener("click", (event) => {
      if (event.target === overlay) {
        overlay.remove();
      }
    });

    header.appendChild(titleEl);
    header.appendChild(closeBtn);
    container.appendChild(header);
    container.appendChild(body);
    overlay.appendChild(container);
    document.body.appendChild(overlay);
  }

  window.openCourseFeedbackModal = function (courseId, studentId, columnKey) {
    const student = (window.filterData || []).find((item) => Number(item.userid) === Number(studentId));
    const column = getCourseMaacColumns(courseId).find((item) => item.key === columnKey);
    if (!student || !column) {
      showToast("Unable to load feedback details", "warn");
      return;
    }

    const feedbacks = normalizeCourseFeedbackItems(getCourseStudentCustomValue(student, column));
    const cards = feedbacks.length
      ? feedbacks
          .map(
            (feedback, index) => `<div class="ba-feedback-card">
              <div class="ba-feedback-card-left">
                <div class="ba-feedback-card-title">${escapeHtml(column.label)} - ${index + 1}</div>
                <div class="ba-feedback-card-date">
                  <span class="ba-feedback-card-date-label">Date :</span>
                  <span class="ba-feedback-card-date-value">${escapeHtml(formatCourseFeedbackDate(feedback.date))}</span>
                </div>
              </div>
              <div class="ba-feedback-card-right">
                <div class="ba-feedback-card-text">${escapeHtml(feedback.text)}</div>
              </div>
            </div>`,
          )
          .join("")
      : '<div class="ba-feedback-empty">No feedback provided yet.</div>';

    showModal(`Feedback - ${column.label}`, `<div class="ba-feedback-modal-list">${cards}</div>`);
  };
  window.openCourseTrendModal = function (courseId, studentId) {
    const student = (window.filterData || []).find((item) => Number(item.userid) === Number(studentId));
    if (!student) {
      showToast("Unable to load trend details", "warn");
      return;
    }

    const course = (BATCH_DATA?.courses || []).find((item) => Number(item.courseid) === Number(courseId));
    const courseLabel = course?.shortname || course?.fullname || "Course";
    const existingModal = document.getElementById("ba-course-trend-modal");
    if (existingModal) {
      existingModal.remove();
    }
    document.body.insertAdjacentHTML("beforeend", renderCourseTrendModalContentFixed(student, courseLabel));
  };

  window.openDonutModal = function (cid, cname) {
    const c = BATCH_DATA.courses.find((x) => x.courseid == cid);
    const cat = c.categories.find((x) => x.categoryname === cname);
    const isMaac = cname === "MAAC Ratings";
    const isAtt = isAttendance(cname);
    const useGrade = isMaac || isAtt;
    const metricLabel = useGrade ? "Grade" : "Completion";
    const isCompMode = !useGrade; // NEW: Tell drilldown to use Completion instead of Grade

    const ranges = [
      { l: "0-20%", min: 0, max: 20, c: "#EF5350", s: [] },
      { l: "21-40%", min: 21, max: 40, c: "#FF7043", s: [] },
      { l: "41-60%", min: 41, max: 60, c: "#FFCA28", s: [] },
      { l: "61-80%", min: 61, max: 80, c: "#42A5F5", s: [] },
      { l: "81-100%", min: 81, max: 100, c: "#66BB6A", s: [] },
    ];

    cat.studentGrades.forEach((s) => {
      if (useGrade && (s.percentage === null || s.percentage === undefined))
        return;
      const val = useGrade
        ? s.percentage
        : s.completionRate !== undefined
          ? s.completionRate
          : 0;

      ranges.forEach((r) => {
        if (val >= r.min && val <= r.max) r.s.push(s);
      });
    });

    let total = ranges.reduce((acc, r) => acc + r.s.length, 0);
    if (total === 0) total = 1;

    let cumulative = 0;
    let svg = `<svg viewBox="0 0 100 100" class="ba-donut-svg">`;
    let legendHtml = `<div class="ba-donut-legend-list">`;

    ranges.forEach((r) => {
      if (r.s.length === 0) return;
      const percent = r.s.length / total;
      const seg = percent * 100;

      // FIX: Added ${isCompMode} to the onclick event
      svg += `<circle cx="50" cy="50" r="15.915" fill="transparent" stroke="${r.c}" stroke-width="8"
                  stroke-dasharray="${seg} ${100 - seg}" stroke-dashoffset="-${cumulative}"
                  class="ba-donut-segment"
                  onclick='openDrilldown("${cname}", "${r.l}", ${JSON.stringify(r.s).replace(/'/g, "")}, ${isCompMode})' />`;
      cumulative += seg;
      legendHtml += `
            <div class="ba-legend-item" onclick='openDrilldown("${cname}", "${r.l}", ${JSON.stringify(r.s).replace(/'/g, "")}, ${isCompMode})'>
                <div class="ba-legend-left"><span class="ba-legend-dot" style="background:${r.c}"></span><span class="ba-legend-range">${r.l}</span></div>
                <div class="ba-legend-right"><strong>${Math.round(percent * 100)}%</strong><span class="ba-legend-count">(${r.s.length})</span></div>
            </div>`;
    });
    svg += `<text x="50" y="50" class="ba-donut-text" dy="0.3em">${metricLabel}</text></svg>`;
    legendHtml += `</div>`;

    showModal(
      cname,
      `
        <div class="ba-donut-container">
            <div class="ba-donut-left">${svg}</div>
            <div class="ba-donut-right"><h4 class="ba-legend-title">Distribution Details</h4>${legendHtml}<p class="ba-donut-hint">Click chart or list item to view students</p></div>
        </div>`,
    );
  };

  window.openDrilldown = function (cat, range, students, isCompMode = false) {
    const id = `drill-${Date.now()}`;
    const isMaac = cat === "MAAC Ratings";

    // NEW: Change the Column Header Text dynamically
    const metricName = isCompMode ? "Completion %" : "Grade";

    let rows = students
      .map((s) => {
        let displayVal = "-";
        let hasVal = false;

        if (isCompMode) {
          // FIX: Render the true Completion Rate
          const comp =
            s.completionRate !== undefined && s.completionRate !== null
              ? s.completionRate
              : 0;
          displayVal = parseFloat(comp).toFixed(0) + "%";
          hasVal = true;
        } else {
          // Render the Grade
          const hasGrade = s.percentage !== null && s.percentage !== undefined;
          const suffix = isMaac ? "" : "%";
          if (hasGrade) {
            displayVal = isMaac
              ? parseFloat(s.percentage).toFixed(1) + suffix
              : s.percentage + suffix;
            hasVal = true;
          }
        }

        return `<tr>
            <td>${s.username}</td>
            <td><strong>${s.fullname}</strong></td>
            <td><span class="ba-filter-percentage ${hasVal ? "high" : "loading"}">${displayVal}</span></td>
        </tr>`;
      })
      .join("");

    const html = `
        <div class="ba-modal-overlay" id="${id}" style="z-index:10001">
            <div class="ba-modal-container">
                <div class="ba-modal-header">
                    <h3>${cat} (${range})</h3>
                    <button class="ba-modal-close" onclick="document.getElementById('${id}').remove()">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
                <div class="ba-modal-body">
                    <div style="text-align:right;margin:10px">
                        <button class="ba-btn-download-sm" onclick="exportTable('${id}-t', '${cat}_${range}')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            Download
                        </button>
                    </div>
                    <div class="table-scroll">
                        <table class="ba-table" id="${id}-t">
                            <thead>
                                <tr>
                                    <th class="sortable" onclick="sortTable(this, 0, false)">Username</th>
                                    <th class="sortable" onclick="sortTable(this, 1, false)">Name</th>
                                    <th class="sortable" onclick="sortTable(this, 2, true)">${metricName}</th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>`;
    document.body.insertAdjacentHTML("beforeend", html);
  };

  function isAttendance(n) {
    return n.toLowerCase().includes("attendance");
  }
  function getAvgGrade(c) {
    if (!c || !c.studentGrades) return "0.00";

    // Safely filter out null, undefined, or invalid numbers
    const validStudents = c.studentGrades.filter(
      (s) =>
        s.percentage !== null &&
        s.percentage !== undefined &&
        !isNaN(s.percentage),
    );

    return validStudents.length > 0
      ? (
        validStudents.reduce((a, b) => a + parseFloat(b.percentage || 0), 0) /
        validStudents.length
      ).toFixed(2)
      : "0.00";
  }

  function getCompletionRate(c) {
    if (!c || !c.studentGrades || c.studentGrades.length === 0) return "0.00";

    // Averages the true completion rates of all students
    const sum = c.studentGrades.reduce(
      (a, b) => a + (parseFloat(b.completionRate) || 0),
      0,
    );
    return (sum / c.studentGrades.length).toFixed(2);
  }

  function getCompClass(v) {
    return v < 50 ? "text-danger" : v < 80 ? "text-warning" : "text-success";
  }

  function renderMaacDetailsCard(course) {
    const cardId = `ba-maac-summary-${course.courseid}`;
    return `
      <div class="ba-section-title">MAAC Details</div>
      <div class="ba-course-header-card ba-maac-course-card" id="${cardId}">
        <div class="ba-course-maac-head">
          <h3 class="ba-ch-title">MAAC Details</h3>
          <div class="ba-course-maac-actions">
            <a href="maac.php?courseid=${course.courseid}" target="_blank" rel="noopener" class="ba-btn ba-btn-view">MAAC sheet</a>
          </div>
        </div>
        <div class="ba-maac-inline-grid" data-group-container>
          <div class="ba-maac-inline-item">
            <span class="ba-maac-inline-label">Loading groups...</span>
          </div>
        </div>
      </div>`;
  }

  function renderMaacSummaryGroups(groups) {
    return groups
      .map((group) => {
        return `<div class="ba-maac-inline-item">
          <span class="ba-maac-inline-label">${escapeHtml(group.name)}</span>
          <div class="ba-maac-inline-values">
            ${(group.items || [])
              .map(
                (item) => `<div class="ba-maac-inline-summary">
                  <strong>${escapeHtml(item.label)}</strong>
                  <span>${escapeHtml(item.summary || "-")}</span>
                </div>`,
              )
              .join("") || '<div class="ba-maac-inline-summary"><span>No columns configured</span></div>'}
          </div>
        </div>`;
      })
      .join("");
  }

  async function loadMaacSummary(courseId) {
    const card = document.getElementById(`ba-maac-summary-${courseId}`);
    if (!card) return;

    const container = card.querySelector("[data-group-container]");
    if (!container) return;

    if (MAAC_SUMMARY_CACHE[courseId]) {
      container.innerHTML = renderMaacSummaryGroups(MAAC_SUMMARY_CACHE[courseId]);
      return;
    }

    if (MAAC_SUMMARY_PENDING[courseId]) {
      await MAAC_SUMMARY_PENDING[courseId];
      if (MAAC_SUMMARY_CACHE[courseId]) {
        container.innerHTML = renderMaacSummaryGroups(MAAC_SUMMARY_CACHE[courseId]);
      }
      return;
    }

    MAAC_SUMMARY_PENDING[courseId] = fetch(
      `maac.php?action=summary&courseid=${encodeURIComponent(courseId)}`,
      {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "sesskey=" + encodeURIComponent(BA_SESSKEY)
      }
    )
      .then((res) => res.json())
      .then((data) => {
        if (data.error || !Array.isArray(data.course_groups)) {
          throw new Error(data.error || "Failed to load MAAC details");
        }
        MAAC_SUMMARY_CACHE[courseId] = data.course_groups;
        container.innerHTML = renderMaacSummaryGroups(data.course_groups);
      })
      .catch(() => {
        container.innerHTML = `<div class="ba-maac-inline-item"><span class="ba-maac-inline-label">Unable to load MAAC details</span></div>`;
      })
      .finally(() => {
        delete MAAC_SUMMARY_PENDING[courseId];
      });

    await MAAC_SUMMARY_PENDING[courseId];
  }

  function syncCourseMaacRatings(courseId, maacData) {
    const course = BATCH_DATA?.courses?.find((item) => Number(item.courseid) === Number(courseId));
    const maacCategory = course?.categories?.find((category) => category.categoryname === "MAAC Ratings");
    if (!maacCategory || !Array.isArray(maacData?.students)) {
      return;
    }

    const ratings = new Map(maacData.students.map((student) => [student.username, student.maac_rating]));
    maacCategory.studentGrades.forEach((student) => {
      student.percentage = ratings.has(student.username) ? ratings.get(student.username) : null;
    });
  }

  async function loadCourseMaacData(courseId) {
    if (MAAC_COURSE_CACHE[courseId]) {
      if (CURRENT_COURSE && CURRENT_COURSE.courseid === courseId) {
        renderCourseFilterSection(CURRENT_COURSE);
      }
      return;
    }

    if (MAAC_COURSE_PENDING[courseId]) {
      await MAAC_COURSE_PENDING[courseId];
      return;
    }

    MAAC_COURSE_PENDING[courseId] = fetch(
      `maac.php?action=getdata&courseid=${encodeURIComponent(courseId)}`,
      {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "sesskey=" + encodeURIComponent(BA_SESSKEY)
      }
    )
      .then((res) => res.json())
      .then((data) => {
        if (data.error) {
          throw new Error(data.error);
        }

        MAAC_COURSE_CACHE[courseId] = data;
        syncCourseMaacRatings(courseId, data);
        if (CURRENT_COURSE && CURRENT_COURSE.courseid === courseId) {
          renderCourse(courseId);
        }
      })
      .catch((e) => {
        console.error("Failed to load MAAC course data", e);
      })
      .finally(() => {
        delete MAAC_COURSE_PENDING[courseId];
      });

    await MAAC_COURSE_PENDING[courseId];
  }

  function getCategoryIconAndColor(name, index) {
    const n = String(name || "").toLowerCase();
    const icons = {
      attendance:
        '<path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2zm-7 5h5v5h-5v-5z"></path>',
      assignment:
        '<path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 18H6V4h2v8l2.5-1.5L13 12V4h5v16z"></path>',
      quiz: '<path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"></path>',
      test: '<path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"></path>',
      project:
        '<path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"></path>',
      programming:
        '<path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"></path>',
      maac: '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"></path>',
    };
    let svgPath = icons.assignment;
    if (n.includes("attend")) svgPath = icons.attendance;
    else if (n.includes("quiz") || n.includes("qiuz") || n.includes("objective")) svgPath = icons.quiz;
    else if (n.includes("project")) svgPath = icons.project;
    else if (n.includes("program") || n.includes("code")) svgPath = icons.programming;
    else if (n.includes("test") || n.includes("module")) svgPath = icons.test;
    else if (n.includes("maac") || n.includes("report")) svgPath = icons.maac;

    const palettes = [
      { c: "#2196F3", b: "#E3F2FD" },
      { c: "#E91E63", b: "#FCE4EC" },
      { c: "#FF9800", b: "#FFF3E0" },
      { c: "#4CAF50", b: "#E8F5E9" },
      { c: "#9C27B0", b: "#F3E5F5" },
      { c: "#009688", b: "#E0F2F1" },
    ];
    const colorSet = palettes[index % palettes.length];
    return {
      bg: colorSet.b,
      icon: `<svg width="24" height="24" viewBox="0 0 24 24" fill="${colorSet.c}">${svgPath}</svg>`,
    };
  }

  function collectRankedStudents(coursesArray, minimumGradeCount = 1) {
    const courses = coursesArray || BATCH_DATA.courses;
    const stats = {};

    courses.forEach((course) => {
      course.categories.forEach((cat) => {
        if (cat.categoryname === "MAAC Ratings") return;

        cat.studentGrades.forEach((student) => {
          if (student.percentage === null || student.percentage === undefined || isNaN(student.percentage)) {
            return;
          }

          if (!stats[student.username]) {
            stats[student.username] = {
              name: student.fullname,
              sum: 0,
              count: 0,
            };
          }

          stats[student.username].sum += parseFloat(student.percentage);
          stats[student.username].count++;
        });
      });
    });

    return Object.values(stats)
      .filter((student) => student.count >= minimumGradeCount)
      .map((student) => ({
        name: student.name,
        avg: Math.round(student.sum / student.count),
      }));
  }

  function getTopPerformers(coursesArray) {
    return collectRankedStudents(coursesArray, 1)
      .sort((a, b) => b.avg - a.avg)
      .slice(0, 5);
  }
  function getLowPerformers(coursesArray) {
    const stats = {};

    coursesArray.forEach((c) => {
      const gradedCategories = c.categories.filter(
        (cat) => cat.categoryname !== "MAAC Ratings",
      );

      gradedCategories.forEach((cat) => {
        cat.studentGrades.forEach((student) => {
          if (!stats[student.username]) {
            stats[student.username] = {
              name: student.fullname,
              sum: 0,
              count: 0,
            };
          }

          const percentage =
            student.percentage === null ||
            student.percentage === undefined ||
            isNaN(student.percentage)
              ? 0
              : parseFloat(student.percentage);

          stats[student.username].sum += percentage;
          stats[student.username].count++;
        });
      });
    });

    return Object.values(stats)
      .filter((student) => student.count > 0)
      .map((student) => ({
        name: student.name,
        avg: Math.round(student.sum / student.count),
      }))
      .sort((a, b) => a.avg - b.avg)
      .slice(0, 5);
  }

  function getCourseTopPerformers(c) {
    return collectRankedStudents([c], 1)
      .sort((a, b) => b.avg - a.avg)
      .slice(0, 5);
  }

  function getCourseLowPerformers(c) {
    const stats = {};
    const gradedCategories = c.categories.filter(
      (cat) => cat.categoryname !== "MAAC Ratings",
    );

    gradedCategories.forEach((cat) => {
      cat.studentGrades.forEach((student) => {
        if (!stats[student.username]) {
          stats[student.username] = {
            name: student.fullname,
            sum: 0,
            count: 0,
          };
        }

        const percentage =
          student.percentage === null ||
          student.percentage === undefined ||
          isNaN(student.percentage)
            ? 0
            : parseFloat(student.percentage);

        stats[student.username].sum += percentage;
        stats[student.username].count++;
      });
    });

    return Object.values(stats)
      .filter((student) => student.count > 0)
      .map((student) => ({
        name: student.name,
        avg: Math.round(student.sum / student.count),
      }))
      .sort((a, b) => a.avg - b.avg)
      .slice(0, 5);
  }

  function renderGradeDistributionTable(studentStats, contextName) {
    const ranges = [
      { label: "90-100%", min: 90, max: 100, count: 0, students: [] },
      { label: "80-89%", min: 80, max: 89, count: 0, students: [] },
      { label: "70-79%", min: 70, max: 79, count: 0, students: [] },
      { label: "60-69%", min: 60, max: 69, count: 0, students: [] },
      { label: "50-59%", min: 50, max: 59, count: 0, students: [] },
      { label: "0-49%", min: 0, max: 49, count: 0, students: [] },
    ];
    const totalStudents = studentStats.length;
    studentStats.forEach((s) => {
      const range = ranges.find(
        (r) => s.percentage >= r.min && s.percentage <= r.max,
      );
      if (range) {
        range.count++;
        range.students.push(s);
      }
    });
    let rows = ranges
      .map((r) => {
        const pct = totalStudents
          ? ((r.count / totalStudents) * 100).toFixed(2)
          : "0.00";
        const sJson = JSON.stringify(r.students)
          .replace(/'/g, "&apos;")
          .replace(/"/g, "&quot;");
        return `<tr onclick='openDrilldown("${contextName}", "${r.label}", ${sJson})' style="cursor:pointer; border-bottom:1px solid #f1f5f9; transition:background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                <td style="padding:12px 15px;"><span style="font-weight:600; color:var(--primary)">${r.label}</span></td>
                <td style="padding:12px 15px;"><strong>${r.count}</strong><span style="font-size:11px; color:#94a3b8; margin-left:5px; font-weight:normal;">(Click to view)</span></td>
                <td style="padding:12px 15px;">${pct}%</td>
            </tr>`;
      })
      .join("");
    return `<div class="ba-section-title">Overall Performance Distribution</div><div style="background:#fff; border:1px solid var(--border); border-radius:8px; overflow:hidden; margin-bottom:30px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);"><table style="width:100%; border-collapse:collapse; font-size:14px; color:#333;"><thead><tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left;"><th style="padding:12px 15px; font-weight:700; color:#64748b;">Grade Range</th><th style="padding:12px 15px; font-weight:700; color:#64748b;">Number of Students</th><th style="padding:12px 15px; font-weight:700; color:#64748b;">Percentage</th></tr></thead><tbody>${rows}</tbody></table></div>`;
  }

  function downloadCSV(csv, filename) {
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.setAttribute("href", url);
    link.setAttribute("download", filename);
    link.style.visibility = "hidden";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }

  window.exportTable = function (tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return showToast("Table not found", "error");

    let csv = [];
    const rows = table.querySelectorAll("tr");

    for (let i = 0; i < rows.length; i++) {
      // FIX: Check if the row is hidden by a filter, and skip it!
      if (rows[i].style.display === "none") continue;

      const row = [];
      const cols = rows[i].querySelectorAll("td, th");

      for (let j = 0; j < cols.length; j++) {
        let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").trim();
        data = data.replace(/"/g, '""');
        row.push('"' + data + '"');
      }
      csv.push(row.join(","));
    }

    downloadCSV(csv.join("\n"), filename + ".csv");
  };

  // Helper: Build a lookup map of MAAC ratings per username (computed once, invalidated on new batch load)
  // Helper: Sort Table Columns
  window.sortTable = function (th, colIndex, isNumber) {
    const table = th.closest("table");
    const tbody = table.querySelector("tbody");
    const rows = Array.from(tbody.querySelectorAll("tr"));

    // Determine sort direction
    const isAscending = th.classList.contains("sort-asc");

    // Clear sorting classes from all headers
    table
      .querySelectorAll("th")
      .forEach((h) => h.classList.remove("sort-asc", "sort-desc"));

    // Apply new sorting class
    th.classList.add(isAscending ? "sort-desc" : "sort-asc");
    const multiplier = isAscending ? -1 : 1;

    // Sort the rows
    rows.sort((a, b) => {
      let valA = a.cells[colIndex].innerText.trim();
      let valB = b.cells[colIndex].innerText.trim();

      if (isNumber) {
        // Remove % signs or any other text, parse as float
        valA = parseFloat(valA.replace(/[^0-9.-]+/g, "")) || 0;
        valB = parseFloat(valB.replace(/[^0-9.-]+/g, "")) || 0;
        return (valA - valB) * multiplier;
      } else {
        // Alphabetical sort for text
        return valA.localeCompare(valB) * multiplier;
      }
    });

    // Re-append rows to tbody in sorted order
    rows.forEach((row) => tbody.appendChild(row));
  };

});

