console.log("Batch Analytics - Final Version with Unified Fetch");

let BATCH_DATA = null;
let CURRENT_COURSE = null;
let CRM_CACHE = {}; // Shared Cache for Filter Status
let PTF_CACHE = {}; // Shared Cache for Detailed Data
let OVERVIEW_SELECTED_COURSES = [];

document.addEventListener("DOMContentLoaded", function () {
  const searchBox = document.getElementById("ba-search");
  const searchBtn = document.getElementById("ba-search-btn");
  const batchSelect = document.getElementById("ba-batch");
  const tabsWrapper = document.getElementById("ba-tabs-wrapper");
  const batchTabs = document.getElementById("batchTabs");
  const batchTabsContent = document.getElementById("batchTabsContent");

  if (!searchBox) return;

  // ==================== SEARCH ====================
  searchBtn.addEventListener("click", async () => {
    const k = searchBox.value.trim();
    if (k.length < 1) return showToast("Enter keyword", "warn");

    showToast("Searching...", "info");
    try {
      const baseUrl = window.location.href.split("?")[0];
      const res = await fetch(
        baseUrl + "?action=getbatchcourses&batchcode=" + encodeURIComponent(k),
      );
      if (!res.ok) throw new Error("Network error");

      const data = await res.json();
      if (!data.courses || !data.courses.length) {
        batchSelect.innerHTML =
          '<option value="">-- No batches found --</option>';
        return showToast("No batches found", "warn");
      }

      const batches = {};
      data.courses.forEach((c) => {
        const parts = (
          c.fullname.includes(":") ? c.fullname : c.shortname
        ).split(":");
        const b = parts.length > 1 ? parts[1].trim() : parts[0].trim();
        if (!batches[b]) batches[b] = { code: b, count: 0 };
        batches[b].count++;
      });

      batchSelect.innerHTML = '<option value="">-- Select a Batch --</option>';
      Object.keys(batches)
        .sort()
        .forEach((b) => {
          const opt = document.createElement("option");
          opt.value = b;
          opt.textContent = `${b} (${batches[b].count} courses)`;
          batchSelect.appendChild(opt);
        });
      showToast(`Found ${data.courses.length} courses`, "success");
    } catch (e) {
      console.error(e);
      showToast("Error searching", "error");
    }
  });

  searchBox.addEventListener("keyup", (e) => {
    if (e.key === "Enter") searchBtn.click();
  });

  // ==================== BATCH SELECTION ====================
  batchSelect.addEventListener("change", async function () {
    if (!this.value) {
      tabsWrapper.style.display = "none";
      return;
    }

    showToast("Loading Data...", "info");
    try {
      const baseUrl = window.location.href.split("?")[0];
      const res = await fetch(
        baseUrl +
          "?action=getbatchfulldata&batchcode=" +
          encodeURIComponent(this.value),
      );
      if (!res.ok) throw new Error("Network error");

      BATCH_DATA = await res.json();
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

  // ==================== UNIFIED DATA FETCHER ====================
  // ==================== GLOBAL CRM COLUMNS MAP ====================
  // Added "num" flag to tell the sorting algorithm if it's a number or text
  const PTF_COLS = [
    { h: "Class X", k: "Class_X_Score", num: true },
    { h: "Class XII", k: "Class_XII_Score", num: true },
    { h: "BE Branch", k: "BE_BTech_Branch", num: false },
    { h: "BE Score", k: "BE_BTech_Score", num: true },
    { h: "BE YOP", k: "BE_BTech_YoP", num: false },
    { h: "College Name", k: "College_Name", num: false },
    { h: "ME Score", k: "ME_MTech_Score", num: true },
    { h: "ME Branch", k: "ME_MTech_Branch", num: false },
    { h: "ME YOP", k: "ME_MTech_YoP", num: false },
    { h: "Home State", k: "Home_State", num: false },
    { h: "Total Applied", k: "Total_Applied", num: true },
    { h: "Last Applied Date", k: "Last_Applied_Date", num: false },
    { h: "Total Shortlisted", k: "Total_Shortlisted", num: true },
    { h: "Last Shortlisted Date", k: "Last_Shortlisted_Date", num: false },
    {
      h: "Tech Int Cleared",
      k: "Total_Technical_Interview_Cleared",
      num: true,
    },
    { h: "Written Tests Cleared", k: "Total_Written_Test_Cleared", num: true },
    { h: "Total L1 Cleared", k: "Total_L1_Cleared", num: true },
    { h: "Total L2 Cleared", k: "Total_L2_Cleared", num: true },
    { h: "Adv C Score", k: "Advanced_C_Score", num: true },
    { h: "C Mentor", k: "Mentor_Name_C_Mock", num: false },
    { h: "C++ Score", k: "C_Score", num: true },
    { h: "C++ Mentor", k: "Mentor_Name_C_Mock1", num: false },
    { h: "DS Score", k: "DS_Score", num: true },
    { h: "DS Mentor", k: "Mentor_Name_DS", num: false },
    { h: "Linux Score", k: "Linux_Internals_Score", num: true },
    { h: "Linux Mentor", k: "Mentor_Name_LI", num: false },
    { h: "MC Score", k: "MC_Mock_Score3", num: true },
    { h: "MC Mentor", k: "Mentor_Name_MC_Mock", num: false },
    { h: "Coach Rating", k: "Coach_Rating", num: true },
    { h: "MAAC Rating (Moodle)", k: "CALC_MAAC", num: true },
    { h: "PET Status", k: "Placement_Eli", num: false },
    { h: "Placement Status", k: "CALC_STATUS", num: false },
    { h: "Placed Company", k: "placed_company", num: false },
    { h: "Placed Package", k: "CTC", num: true },
  ];

  // ==================== UNIFIED DATA FETCHER ====================
  async function fetchUnifiedData(users) {
    const uniqueUsers = [...new Set(users)];

    for (const u of uniqueUsers) {
      if (PTF_CACHE[u]) continue;

      try {
        const baseUrl = window.location.href.split("?")[0];
        const res = await fetch(
          baseUrl + "?action=getptfdata&username=" + encodeURIComponent(u),
        );
        const data = await res.json();

        if (data && !data.error) {
          PTF_CACHE[u] = data;
          CRM_CACHE[u] = data.placed_company || "Not Placed";

          const uid = u.replace(/[^a-z0-9]/gi, "");
          const ptfRow = document.getElementById(`tr-${uid}`);
          if (ptfRow) renderPtfRowContent(ptfRow, data);

          // Trigger Live Filter Updates as data streams in
          if (window.updatePtfFilterOptions) window.updatePtfFilterOptions();
          if (
            window.applyPtfFilters &&
            document.getElementById("ptf-f-placement")
          )
            window.applyPtfFilters();

          if (CURRENT_COURSE) {
            const statusFilter = document.getElementById("f-status");
            if (statusFilter && statusFilter.value) applyFilters();
          }
        }
      } catch (e) {
        console.error("Fetch error for " + u, e);
      }
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

                  <div class="ba-filter-item-modern">
                      <div class="ba-filter-header-row"><span class="ba-filter-label">Placement Status</span></div>
                      <select id="ptf-f-placement" class="ba-select-small" style="width:100%" onchange="window.applyPtfFilters()">
                          <option value="">All</option>
                          <option value="Placed">Placed</option>
                          <option value="Not Placed">Not Placed</option>
                      </select>
                  </div>

                  <div class="ba-filter-item-modern">
                      <div class="ba-filter-header-row"><span class="ba-filter-label">PET Status</span></div>
                      <select id="ptf-f-pet" class="ba-select-small" style="width:100%" onchange="window.applyPtfFilters()">
                          <option value="">All</option>
                          </select>
                  </div>

                  ${rangeHtml("MAAC Rating", "maac", 0, 10, 0.1)}
                  ${rangeHtml("Adv C Mock Score", "advc", 0, 10, 1)}
                  ${rangeHtml("MC Mock Score", "mc", 0, 10, 1)}
                  ${rangeHtml("Total Applied", "applied", 0, 20, 1)}
                  ${rangeHtml("Total Shortlisted", "shortlisted", 0, 20, 1)}

                  <div class="ba-filter-item-modern">
                      <div class="ba-filter-header-row"><span class="ba-filter-label">Year of Passing (YOP)</span></div>
                      <div id="ptf-f-yop-list" style="max-height:120px; overflow-y:auto; border:1px solid #eee; padding:8px; border-radius:4px; font-size:12px;">
                          <span style="color:#999">Loading CRM Data...</span>
                      </div>
                  </div>

                  <div class="ba-filter-item-modern">
                      <div class="ba-filter-header-row"><span class="ba-filter-label">Home State</span></div>
                      <div id="ptf-f-state-list" style="max-height:150px; overflow-y:auto; border:1px solid #eee; padding:8px; border-radius:4px; font-size:12px;">
                          <span style="color:#999">Loading CRM Data...</span>
                      </div>
                  </div>
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

    const moodleMaac = getStudentMoodleMaac(data.username);

    // Keep Name Cell (First child), Remove the rest to refresh them cleanly
    while (tr.children.length > 1) tr.removeChild(tr.lastChild);

    PTF_COLS.forEach((c) => {
      const td = document.createElement("td");

      if (c.k === "CALC_STATUS") td.innerHTML = statusHtml;
      else if (c.k === "CALC_MAAC") td.textContent = moodleMaac;
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
    const placement = document.getElementById("ptf-f-placement").value;
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
    const appMin =
      parseFloat(document.getElementById("f-applied-min").value) || 0;
    const appMax =
      parseFloat(document.getElementById("f-applied-max").value) || 500;
    const shortMin =
      parseFloat(document.getElementById("f-shortlisted-min").value) || 0;
    const shortMax =
      parseFloat(document.getElementById("f-shortlisted-max").value) || 500;

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

        const maac = parseFloat(getStudentMoodleMaac(s.username)) || 0;
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
    document.getElementById("ptf-f-placement").value = "";

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
              <span style="font-weight: 600;">Advanced Filter ▾</span>
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
      html += `<div class="ba-section-title">🏆 Top Performers (Selected Courses)</div><div class="ba-top-performers-grid">`;
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
        html += `<div class="ba-performer-card ${medalClass}"><div class="ba-perf-rank">#${rank}</div><div class="ba-perf-info"><div class="ba-perf-name" title="${s.name}">${s.name}</div><div class="ba-perf-avg">${s.avg}% Avg</div></div>${rank === 1 ? '<div class="ba-crown">👑</div>' : ""}</div>`;
      });
      html += `</div>`;
    }

    // 5. Low Performers
    const lowStudents = getLowPerformers(coursesToRender);
    if (lowStudents.length > 0) {
      html += `<div class="ba-section-title" style="border-color:#d32f2f; color:#d32f2f;">⚠️ Needs Improvement</div><div class="ba-top-performers-grid">`;
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
        const val = isAttendance(cat.categoryname)
          ? getAvgGrade(cat)
          : getCompletionRate(cat);

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
      const val = isAttendance(cat.categoryname)
        ? getAvgGrade(cat)
        : getCompletionRate(cat);

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
                    <a href="../../course/view.php?id=${c.courseid}" target="_blank" class="ba-btn ba-btn-view">View Course <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg></a>
                </div>
            </div>
        </div>`;

    // 2. Top Performers
    const topStudents = getCourseTopPerformers(c);
    if (topStudents.length > 0) {
      html += `<div class="ba-section-title">🏆 Top Performers (${c.shortname})</div><div class="ba-top-performers-grid">`;
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
        html += `<div class="ba-performer-card ${medalClass}"><div class="ba-perf-rank">#${rank}</div><div class="ba-perf-info"><div class="ba-perf-name" title="${s.name}">${s.name}</div><div class="ba-perf-avg">${s.avg}% Avg</div></div>${rank === 1 ? '<div class="ba-crown">👑</div>' : ""}</div>`;
      });
      html += `</div>`;
    }

    // 3. Needs Improvement
    const lowStudents = getCourseLowPerformers(c);
    if (lowStudents.length > 0) {
      html += `<div class="ba-section-title" style="border-color:#d32f2f; color:#d32f2f;">⚠️ Needs Improvement</div><div class="ba-top-performers-grid">`;
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
      if (isMaac || isAtt) {
        statsHtml = `<div class="stat-box" style="width:100%; text-align:center; align-items:center;"><span class="lbl">AVG GRADE</span><span class="val">${grade}%</span></div>`;
      } else {
        statsHtml = `<div class="stat-box"><span class="lbl">AVG GRADE</span><span class="val">${grade}%</span></div><div class="stat-box"><span class="lbl">COMPLETION</span><span class="val ${getCompClass(comp)}">${comp}%</span></div>`;
      }

      html += `<div class="ba-course-metric-card" onclick="openCategoryModal(${cid}, '${escapeHtml(cat.categoryname)}')"><div class="ba-course-metric-header"><div class="ba-course-metric-icon" style="background:${style.bg}">${style.icon}</div><div class="ba-course-metric-title">${cat.categoryname}</div></div><div class="ba-course-metric-stats">${statsHtml}</div></div>`;
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

      const height = isMaac ? numVal * 10 : Math.max(numVal, 2);
      const barClass = isMaac || isAtt ? "bar-purple" : "bar-green";
      const displayVal = isMaac ? val : val + "%";

      html += `
        <div class="ba-chart-col" onclick="openDonutModal(${cid}, '${escapeHtml(cat.categoryname)}')">
            <div class="ba-chart-bar-area">
                <div class="ba-chart-value">${displayVal}</div>
                <div class="ba-chart-bar ${barClass}" style="height:${height}%"></div>
            </div>
            <div class="ba-chart-label" title="${cat.categoryname}">${cat.categoryname}</div>
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

    // 7. Filter
    html += renderFilterSection(c);

    batchTabsContent.innerHTML = html;
    initFilter(c);
  }

  // ==================== ADVANCED FILTER & UTILS ====================
  function renderFilterSection(c) {
    // Make "Student Name" sortable (Text Sort = false)
    let headers = `<th class="sortable" onclick="sortTable(this, 0, false)">Student Name</th>`;

    // Make all category columns sortable (Number Sort = true)
    c.categories.forEach((cat, i) => {
      headers += `<th class="sortable" onclick="sortTable(this, ${i + 1}, true)">${cat.categoryname}</th>`;
    });
    // GENERATE SLIDER-STYLE INPUTS FOR EACH CATEGORY
    let inputs = c.categories
      .map((cat, i) => {
        // Determine max value based on category type (MAAC = 10, Others = 100)
        const isMaac = cat.categoryname === "MAAC Ratings";
        const maxVal = isMaac ? 10 : 100;
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
                    
                    <input type="hidden" id="f-status" value=""> 
                </div>

                <div class="ba-filter-main">
                    <div class="ba-table-controls">
                        <span id="f-count" style="font-weight:600; color:var(--text-gray);">0 students</span>
                        <input type="text" id="f-search" placeholder="Search student name..." onkeyup="applyFilters()">
                    </div>
                    <div class="ba-table-wrap">
                        <table class="ba-table"><thead><tr>${headers}</tr></thead><tbody id="f-body"></tbody></table>
                    </div>
                </div>
            </div>
        </div>`;
  }

  function initFilter(c) {
    window.filterData = [];
    const map = {};
    c.categories.forEach((cat) => {
      cat.studentGrades.forEach((s) => {
        if (!map[s.username]) map[s.username] = { ...s, cats: {} };
        map[s.username].cats[cat.categoryname] = {
          pct: s.percentage,
          comp: s.completionRate, // FIX: Store true completion rate
          earned: s.totalEarned,
        };
      });
    });
    window.filterData = Object.values(map);
    applyFilters();
  }

  // 1. UPDATE: Filtering Logic to respect Toggle, MAAC, & True Completion
  window.applyFilters = function () {
    const search = document.getElementById("f-search").value.toLowerCase();
    const status = document.getElementById("f-status").value;

    const toggleEl = document.getElementById("f-metric-toggle");
    const isCompMode = toggleEl ? toggleEl.checked : false;

    if (toggleEl) {
      document
        .getElementById("t-grade")
        .classList.toggle("active", !isCompMode);
      document.getElementById("t-comp").classList.toggle("active", isCompMode);
    }

    const ranges = CURRENT_COURSE.categories.map((c, i) => ({
      name: c.categoryname,
      min: parseFloat(document.getElementById(`f-${i}-min`).value) || 0,
      max: parseFloat(document.getElementById(`f-${i}-max`).value) || 100,
      isMaac: c.categoryname === "MAAC Ratings",
      isAtt: isAttendance(c.categoryname),
    }));

    const filtered = window.filterData.filter((s) => {
      if (search && !s.fullname.toLowerCase().includes(search)) return false;

      for (let r of ranges) {
        const data = s.cats[r.name] || { pct: 0, comp: 0, earned: 0 };
        let valToCheck;

        if (r.isMaac) valToCheck = data.pct || 0;
        else if (r.isAtt) valToCheck = data.pct || 0;
        else valToCheck = isCompMode ? data.comp || 0 : data.pct || 0;

        if (valToCheck < r.min || valToCheck > r.max) return false;
      }

      if (status) {
        const comp = CRM_CACHE[s.username] || "Not Placed";
        const isPlaced =
          comp !== "Not Placed" && comp !== "Error" && comp !== "Not Found";
        if (status === "Placed" && !isPlaced) return false;
        if (status === "Not Placed" && isPlaced) return false;
      }
      return true;
    });

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
        let cols = `<td><b>${s.fullname}</b><br><small style="color:#888">${s.username}</small></td>`;

        CURRENT_COURSE.categories.forEach((c) => {
          // FIX: Ensure comp is included in the default fallback object
          const data = s.cats[c.categoryname] || { pct: 0, comp: 0, earned: 0 };
          const isMaac = c.categoryname === "MAAC Ratings";
          const isAtt = isAttendance(c.categoryname);

          let displayVal;
          let suffix = "%";
          let valForColor;

          // FIX: Safely handle null/undefined grades to prevent .toFixed errors
          if (isMaac) {
            displayVal =
              data.pct !== null && data.pct !== undefined
                ? parseFloat(data.pct).toFixed(1)
                : "-";
            suffix = ""; // No % for MAAC
            valForColor = (data.pct || 0) * 10;
          } else if (isAtt) {
            displayVal =
              data.pct !== null && data.pct !== undefined
                ? parseFloat(data.pct).toFixed(0)
                : "-";
            valForColor = data.pct || 0;
          } else {
            if (isCompMode) {
              // FIX: Use true completion rate
              displayVal =
                data.comp !== undefined && data.comp !== null
                  ? parseFloat(data.comp).toFixed(0)
                  : "0";
              valForColor = displayVal;
            } else {
              displayVal =
                data.pct !== null && data.pct !== undefined
                  ? parseFloat(data.pct).toFixed(0)
                  : "-";
              valForColor = data.pct || 0;
            }
          }

          const cls =
            valForColor >= 75 ? "high" : valForColor >= 50 ? "medium" : "low";

          // Only add suffix if it's not a dash
          const finalDisplay = displayVal === "-" ? "-" : displayVal + suffix;

          cols += `<td><span class="ba-filter-percentage ${cls}">${finalDisplay}</span></td>`;
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

    // 1. Get Settings
    const search = document.getElementById("f-search").value.toLowerCase();
    const status = document.getElementById("f-status").value;
    const toggleEl = document.getElementById("f-metric-toggle");
    const isCompMode = toggleEl ? toggleEl.checked : false;

    const ranges = CURRENT_COURSE.categories.map((c, i) => ({
      name: c.categoryname,
      min: parseFloat(document.getElementById(`f-${i}-min`).value) || 0,
      max: parseFloat(document.getElementById(`f-${i}-max`).value) || 100,
      isMaac: c.categoryname === "MAAC Ratings",
      isAtt: isAttendance(c.categoryname),
    }));

    // 2. Re-run Filtering (Using updated logic)
    const filtered = window.filterData.filter((s) => {
      if (search && !s.fullname.toLowerCase().includes(search)) return false;

      for (let r of ranges) {
        const data = s.cats[r.name] || { pct: 0, earned: 0 };
        let valToCheck;

        if (r.isMaac) valToCheck = data.pct;
        else if (r.isAtt) valToCheck = data.pct;
        else valToCheck = isCompMode ? (data.earned > 0 ? 100 : 0) : data.pct;

        if (valToCheck < r.min || valToCheck > r.max) return false;
      }

      if (status) {
        const comp = CRM_CACHE[s.username] || "Not Placed";
        const isPlaced =
          comp !== "Not Placed" && comp !== "Error" && comp !== "Not Found";
        if (status === "Placed" && !isPlaced) return false;
        if (status === "Not Placed" && isPlaced) return false;
      }
      return true;
    });

    if (filtered.length === 0)
      return showToast("No filtered data to export", "warn");

    // 3. Build CSV
    let csv = "Username,Name,";
    CURRENT_COURSE.categories.forEach((c) => (csv += `"${c.categoryname}",`));
    csv += "\n";

    filtered.forEach((s) => {
      csv += `${s.username},"${s.fullname}",`;

      CURRENT_COURSE.categories.forEach((c) => {
        const data = s.cats[c.categoryname] || { pct: 0, earned: 0 };
        const isMaac = c.categoryname === "MAAC Ratings";
        const isAtt = isAttendance(c.categoryname);
        let val;

        if (isMaac) val = data.pct.toFixed(1);
        else if (isAtt) val = data.pct.toFixed(0) + "%";
        else {
          if (isCompMode) val = (data.earned > 0 ? 100 : 0) + "%";
          else val = data.pct.toFixed(0) + "%";
        }

        csv += `"${val}",`;
      });
      csv += "\n";
    });

    downloadCSV(csv, `Filter_Export_${CURRENT_COURSE.shortname}.csv`);
  };

  // Helper: Reset all filters to default
  window.resetFilter = function () {
    if (!CURRENT_COURSE) return;

    // Reset Search & Toggle
    document.getElementById("f-search").value = "";
    const toggle = document.getElementById("f-metric-toggle");
    if (toggle) {
      toggle.checked = false;
      toggle.dispatchEvent(new Event("change"));
    } // Reset to Grade Mode

    // Reset Ranges
    CURRENT_COURSE.categories.forEach((c, i) => {
      const minEl = document.getElementById(`f-${i}-min`);
      const maxEl = document.getElementById(`f-${i}-max`);
      const isMaac = c.categoryname === "MAAC Ratings";

      if (minEl) minEl.value = "0";
      if (maxEl) maxEl.value = isMaac ? "10" : "100"; // Reset to 10 for MAAC, 100 for others
    });

    applyFilters();
    showToast("Filters reset", "info");
  };

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
        <span class="ba-dot">•</span> 
        <span class="ba-stat-item">Avg Grade: <strong>${isMaac ? avgG : avgG + "%"}</strong></span>`;

    if (!hideComp) {
      statsHtml += `<span class="ba-dot">•</span><span class="ba-stat-item">Completion: <strong>${avgC}%</strong></span>`;
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
    document.body.insertAdjacentHTML(
      "beforeend",
      `<div class="ba-modal-overlay" onclick="if(event.target===this)this.remove()">
          <div class="ba-modal-container">
              <div class="ba-modal-header">
                  <h3>${title}</h3>
                  <button class="ba-modal-close" onclick="this.closest('.ba-modal-overlay').remove()">
                      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                  </button>
              </div>
              <div class="ba-modal-body">${content}</div>
          </div>
      </div>`,
    );
  }

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
  function showToast(m, t) {
    const d = document.createElement("div");
    d.className = "ba-toast ba-toast-" + t;
    d.textContent = m;
    document.getElementById("ba-toast-container").appendChild(d);
    setTimeout(() => d.remove(), 3000);
  }
  function escapeHtml(t) {
    return t.replace(/'/g, "\\'");
  }
  function getCategoryIconAndColor(name, index) {
    const n = name.toLowerCase();
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
    else if (
      n.includes("quiz") ||
      n.includes("qiuz") ||
      n.includes("objective")
    )
      svgPath = icons.quiz;
    else if (n.includes("project")) svgPath = icons.project;
    else if (n.includes("program") || n.includes("code"))
      svgPath = icons.programming;
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

  // == HELPERS ==
  function getTopPerformers(coursesArray) {
    const courses = coursesArray || BATCH_DATA.courses;
    const stats = {};
    courses.forEach((c) => {
      c.categories.forEach((cat) => {
        if (cat.categoryname === "MAAC Ratings") return;
        cat.studentGrades.forEach((s) => {
          if (!stats[s.username])
            stats[s.username] = { name: s.fullname, sum: 0, count: 0 };
          if (s.percentage !== null) {
            stats[s.username].sum += s.percentage;
            stats[s.username].count++;
          }
        });
      });
    });
    return Object.values(stats)
      .filter((s) => s.count >= 1)
      .map((s) => ({
        name: s.name,
        avg: s.count ? Math.round(s.sum / s.count) : 0,
      }))
      .sort((a, b) => b.avg - a.avg)
      .slice(0, 5);
  }

  function getLowPerformers(coursesArray) {
    const courses = coursesArray || BATCH_DATA.courses;
    const stats = {};
    courses.forEach((c) => {
      c.categories.forEach((cat) => {
        if (cat.categoryname === "MAAC Ratings") return;
        cat.studentGrades.forEach((s) => {
          if (!stats[s.username])
            stats[s.username] = { name: s.fullname, sum: 0, count: 0 };
          if (s.percentage !== null) {
            stats[s.username].sum += s.percentage;
            stats[s.username].count++;
          }
        });
      });
    });
    return Object.values(stats)
      .filter((s) => s.count >= 3) // Minimum categories needed to be flagged as "low"
      .map((s) => ({
        name: s.name,
        avg: s.count ? Math.round(s.sum / s.count) : 0,
      }))
      .sort((a, b) => a.avg - b.avg)
      .slice(0, 5);
  }
  function getCourseTopPerformers(c) {
    const stats = {};
    c.categories.forEach((cat) => {
      if (cat.categoryname === "MAAC Ratings") return;
      cat.studentGrades.forEach((s) => {
        if (!stats[s.username])
          stats[s.username] = { name: s.fullname, sum: 0, count: 0 };
        if (s.percentage >= 0) {
          stats[s.username].sum += s.percentage;
          stats[s.username].count++;
        }
      });
    });
    return Object.values(stats)
      .filter((s) => s.count >= 1)
      .map((s) => ({ name: s.name, avg: Math.round(s.sum / s.count) }))
      .sort((a, b) => b.avg - a.avg)
      .slice(0, 5);
  }

  function getCourseLowPerformers(c) {
    const stats = {};
    c.categories.forEach((cat) => {
      if (cat.categoryname === "MAAC Ratings") return;
      cat.studentGrades.forEach((s) => {
        if (!stats[s.username])
          stats[s.username] = { name: s.fullname, sum: 0, count: 0 };
        stats[s.username].sum += s.percentage;
        stats[s.username].count++;
      });
    });
    return Object.values(stats)
      .filter((s) => s.count >= 1)
      .map((s) => ({ name: s.name, avg: Math.round(s.sum / s.count) }))
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

  // Helper: Calculate Student's Average MAAC Rating from Moodle Data
  function getStudentMoodleMaac(username) {
    if (!BATCH_DATA || !BATCH_DATA.courses) return "-";

    let total = 0;
    let count = 0;

    BATCH_DATA.courses.forEach((c) => {
      c.categories.forEach((cat) => {
        if (cat.categoryname === "MAAC Ratings") {
          const student = cat.studentGrades.find(
            (s) => s.username === username,
          );
          if (student && student.percentage !== null) {
            total += student.percentage;
            count++;
          }
        }
      });
    });

    // Return average to 1 decimal place (e.g., 8.5)
    return count > 0 ? (total / count).toFixed(1) : "-";
  }

  // Helper: Calculate Student's Average MAAC Rating from Moodle Data
  function getStudentMoodleMaac(username) {
    if (!BATCH_DATA || !BATCH_DATA.courses) return "-";

    let total = 0;
    let count = 0;

    // Loop through all courses in the batch
    BATCH_DATA.courses.forEach((c) => {
      c.categories.forEach((cat) => {
        if (cat.categoryname === "MAAC Ratings") {
          const student = cat.studentGrades.find(
            (s) => s.username === username,
          );
          // Check if student has a valid rating (not null)
          if (student && student.percentage !== null) {
            total += student.percentage;
            count++;
          }
        }
      });
    });

    // Return average rounded to 1 decimal (e.g., 8.5)
    return count > 0 ? (total / count).toFixed(1) : "-";
  }

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
