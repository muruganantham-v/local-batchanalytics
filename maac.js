document.addEventListener("DOMContentLoaded", function () {
  const wrap = document.querySelector(".local-batchanalytics-maac");
  const app = document.getElementById("ba-maac-app");
  if (!wrap || !app) return;

  const courseId = parseInt(wrap.dataset.courseid || "0", 10);
  const sesskey = wrap.dataset.sesskey || "";
  const baseUrl = window.location.href.split("?")[0];

  const state = {
    data: null,
    filtered: [],
    editMode: false,
    hasUnsavedChanges: false,
    unsavedChangesModal: false,
    customDraft: {},
    originalCustomDraft: {},
    collapsedGroups: { module: false },
    filtersHidden: false,
    filterValues: {},
    trendModal: null,
    feedbackSummaryModal: false,
    ticketModal: null,
    ticketSaving: false,
    ticketEdit: null,
    columnWidths: {},
    tableScroll: {
      left: 0,
      top: 0,
    },
    pendingScrollRestore: null,
    sort: {
      key: "",
      direction: "asc",
      type: "text",
    },
  };
  let maacLayoutFrame = 0;

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function formatFeedbackDate(value) {
    const raw = String(value || "").trim();
    if (!raw) {
      return "";
    }

    const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (match) {
      const year = parseInt(match[1], 10);
      const month = parseInt(match[2], 10);
      const day = parseInt(match[3], 10);
      if (month >= 1 && month <= 12 && day >= 1 && day <= 31) {
        return `${String(day).padStart(2, "0")}-${months[month - 1]}-${year}`;
      }
      return "";
    }

    const parsed = new Date(raw);
    if (Number.isNaN(parsed.getTime())) {
      return "";
    }

    return `${String(parsed.getDate()).padStart(2, "0")}-${months[parsed.getMonth()]}-${parsed.getFullYear()}`;
  }

  function clampResizableColumnWidth(width) {
    return Math.max(120, Math.min(270, Math.round(width)));
  }

  function captureTableScroll() {
    const tableWrap = document.querySelector("#ba-maac-table-section .ba-table-wrap");
    if (!tableWrap) {
      return;
    }

    state.tableScroll.left = tableWrap.scrollLeft;
    state.tableScroll.top = tableWrap.scrollTop;
  }

  function restoreTableScroll(snapshot = null) {
    const tableWrap = document.querySelector("#ba-maac-table-section .ba-table-wrap");
    const scroll = snapshot || state.tableScroll;
    if (!tableWrap || !scroll) {
      return;
    }

    tableWrap.scrollLeft = scroll.left || 0;
    tableWrap.scrollTop = scroll.top || 0;
  }

  function captureScrollSnapshot() {
    const tableWrap = document.querySelector("#ba-maac-table-section .ba-table-wrap");
    return {
      left: tableWrap ? tableWrap.scrollLeft : state.tableScroll.left || 0,
      top: tableWrap ? tableWrap.scrollTop : state.tableScroll.top || 0,
      windowX: window.scrollX || window.pageXOffset || 0,
      windowY: window.scrollY || window.pageYOffset || 0,
    };
  }

  function renderPagePreservingScroll() {
    state.pendingScrollRestore = captureScrollSnapshot();
    renderPage();
  }

  function syncMaacLayoutHeights() {
    const section = document.querySelector(".ba-maac-section");
    if (!section) {
      return;
    }

    if (window.innerWidth <= 1024) {
      section.style.removeProperty("--ba-maac-performance-height");
      return;
    }

    const main = section.querySelector(".ba-maac-main");
    const sidebar = section.querySelector(".ba-maac-filter-sidebar:not(.is-hidden)");
    if (!main || !sidebar) {
      section.style.removeProperty("--ba-maac-performance-height");
      return;
    }

    section.style.removeProperty("--ba-maac-performance-height");
    const mainHeight = Math.ceil(main.getBoundingClientRect().height);
    if (mainHeight > 0) {
      section.style.setProperty("--ba-maac-performance-height", `${mainHeight}px`);
    }
  }

  function scheduleMaacLayoutSync() {
    if (maacLayoutFrame) {
      window.cancelAnimationFrame(maacLayoutFrame);
    }
    maacLayoutFrame = window.requestAnimationFrame(() => {
      maacLayoutFrame = 0;
      syncMaacLayoutHeights();
    });
  }

  window.addEventListener("resize", scheduleMaacLayoutSync);
  window.addEventListener("beforeunload", (event) => {
    if (!state.editMode || !state.hasUnsavedChanges) {
      return;
    }
    event.preventDefault();
    event.returnValue = "";
  });

  function getColumnWidth(key, fallback = 170) {
    return clampResizableColumnWidth(state.columnWidths[key] || fallback);
  }

  function setColumnWidth(key, width) {
    state.columnWidths[key] = clampResizableColumnWidth(width);
  }

  function renderResizableHeader({
    label,
    columnKey,
    columnIndex,
    sortable = false,
    sortKey = "",
    sortType = "text",
    rowSpan = null,
    colSpan = null,
    className = "",
  }) {
    const rowSpanAttr = rowSpan ? ` rowspan="${rowSpan}"` : "";
    const colSpanAttr = colSpan ? ` colspan="${colSpan}"` : "";
    const sortClasses = [];
    if (sortable) {
      sortClasses.push("sortable");
      if (state.sort.key === sortKey) {
        sortClasses.push(state.sort.direction === "asc" ? "sort-asc" : "sort-desc");
      }
    }

    return `<th class="${["ba-resizable-header", className]
      .concat(sortClasses)
      .filter(Boolean)
      .join(" ")}" data-col-index="${columnIndex}" data-col-key="${escapeHtml(
      columnKey,
    )}"${sortable ? ` data-sort-key="${escapeHtml(sortKey)}" data-sort-type="${escapeHtml(sortType)}"` : ""}${rowSpanAttr}${colSpanAttr}>
      <div class="ba-maac-head-shell">
        <span class="ba-maac-col-title">${label}</span>
      </div>
      <span class="ba-column-resizer" data-column-resizer="1" aria-hidden="true"></span>
    </th>`;
  }

  function renderGroupHeader({ label, groupKey, colSpan }) {
    return `<th colspan="${colSpan}" class="ba-maac-group-head">
      <div class="ba-maac-group-shell">
        <button type="button" class="ba-maac-group-toggle" data-group-key="${escapeHtml(groupKey)}">
          <span class="ba-maac-group-toggle-icon" aria-hidden="true">-</span>
          <span class="ba-maac-group-toggle-label">${escapeHtml(label)}</span>
        </button>
      </div>
    </th>`;
  }

  function bindTableColumnResizers(table) {
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
        const key = header.dataset.colKey || "";
        const onMouseMove = (moveEvent) => {
          const width = clampResizableColumnWidth(startWidth + moveEvent.clientX - startX);
          col.style.width = `${width}px`;
          setColumnWidth(key, width);
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

  function getSortableCustomColumn(sortKey) {
    if (!sortKey.startsWith("custom:")) {
      return null;
    }
    const columnKey = sortKey.replace(/^custom:/, "");
    return getOrderedCustomColumns().find((column) => column.key === columnKey) || null;
  }

  function normalizeSortValue(value, type = "text") {
    if (type === "number") {
      const numeric = parseFloat(value);
      return Number.isFinite(numeric) ? numeric : Number.NEGATIVE_INFINITY;
    }

    if (Array.isArray(value)) {
      return value.join(" ").toLowerCase();
    }

    if (type === "boolean") {
      return value ? 1 : 0;
    }

    return String(value ?? "").toLowerCase();
  }

  function getSortValue(student, sortKey, sortType) {
    if (sortKey === "student") {
      return normalizeSortValue(student.fullname || "", sortType);
    }
    if (sortKey === "username") {
      return normalizeSortValue(student.username || "", sortType);
    }
    if (sortKey === "ticket") {
      return normalizeSortValue(student.ticket_count || 0, "number");
    }
    if (sortKey === "latest_feedback") {
      const latestTicket = Array.isArray(student.tickets) && student.tickets.length ? student.tickets[0] : null;
      return normalizeSortValue(latestTicket?.resolutionfeedback || "", sortType);
    }
    if (sortKey === "module:overall_performance") {
      return normalizeSortValue(getOverallPerformanceValue(student), "number");
    }
    if (sortKey === "module:maac_rating") {
      return normalizeSortValue(student.maac_rating, "number");
    }
    if (sortKey.startsWith("module:")) {
      const columnKey = sortKey.replace(/^module:/, "");
      const column = (state.data.module_columns || []).find((item) => item.key === columnKey) || {};
      return normalizeSortValue(getModuleMetricValue(student, column).value, "number");
    }
    if (sortKey.startsWith("custom:")) {
      const column = getSortableCustomColumn(sortKey);
      const value = getStudentCustomValue(student, column?.key || "");
      const type = column?.type === "number"
        ? "number"
        : column?.type === "boolean"
          ? "boolean"
          : "text";
      return normalizeSortValue(value, type);
    }

    return normalizeSortValue("", sortType);
  }

  function getSortedMaacStudents(students) {
    if (!state.sort.key) {
      return students;
    }

    const direction = state.sort.direction === "desc" ? -1 : 1;
    const sortKey = state.sort.key;
    const sortType = state.sort.type || "text";
    return [...students].sort((a, b) => {
      const aValue = getSortValue(a, sortKey, sortType);
      const bValue = getSortValue(b, sortKey, sortType);
      if (aValue < bValue) {
        return -1 * direction;
      }
      if (aValue > bValue) {
        return 1 * direction;
      }
      return String(a.fullname || "").localeCompare(String(b.fullname || ""));
    });
  }

  function showMessage(message, type) {
    const badge = `<span class="ba-maac-msg ba-maac-msg-${type}">${escapeHtml(
      message,
    )}</span>`;
    const holder = document.getElementById("ba-maac-message");
    if (holder) {
      holder.innerHTML = badge;
      setTimeout(() => {
        if (holder.innerHTML === badge) {
          holder.innerHTML = "";
        }
      }, 3000);
    }
  }

  async function loadData(preserveDraft = false) {
    const previousFilters = state.filterValues;
    const previousEditMode = state.editMode;
    const previousDraft = state.customDraft;
    const previousDirty = state.hasUnsavedChanges;
    const previousOriginalDraft = state.originalCustomDraft;

    if (!preserveDraft) {
      app.innerHTML = `<div class="ba-maac-loading">Loading MAAC data...</div>`;
    }
    const res = await fetch(
      `${baseUrl}?action=getdata&courseid=${encodeURIComponent(courseId)}`,
      {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "sesskey=" + encodeURIComponent(sesskey)
      }
    );
    const responseText = await res.text();
    let json = {};
    try {
      json = responseText ? JSON.parse(responseText) : {};
    } catch (error) {
      app.innerHTML = '<div class="ba-maac-error">Unable to load MAAC data. Please check the Moodle error log.</div>';
      return;
    }
    if (!res.ok || json.error) {
      app.innerHTML = `<div class="ba-maac-error">${escapeHtml(json.error || "Unable to load MAAC data.")}</div>`;
      return;
    }

    state.data = json;
    state.filtered = json.students || [];

    if (preserveDraft) {
      state.editMode = previousEditMode;
      state.hasUnsavedChanges = previousDirty;
      const newDraft = buildDraft(json.students || []);
      if (previousDraft) {
        for (const userid in previousDraft) {
          if (newDraft[userid]) {
            newDraft[userid] = previousDraft[userid];
          }
        }
      }
      state.customDraft = newDraft;
      state.originalCustomDraft = cloneMaacDraft(previousOriginalDraft);
    } else {
      state.editMode = false;
      state.hasUnsavedChanges = false;
      state.unsavedChangesModal = false;
      state.customDraft = buildDraft(json.students || []);
      state.originalCustomDraft = cloneMaacDraft(state.customDraft);
    }

    state.filterValues =
      previousFilters && Object.keys(previousFilters).length
        ? previousFilters
        : buildDefaultFilters(json);
    renderPage();
  }

  function buildDefaultFilters(data) {
    const filters = {
      search: "",
      courseGroup: "",
      metricMode: "grade",
      performanceMin: "0",
      performanceMax: "100",
      maacMin: "0",
      maacMax: "9",
      modules: {},
      custom: {},
    };

    (data.module_columns || []).forEach((column) => {
      filters.modules[column.key] = { min: "", max: "" };
    });

    getAllCustomColumns(data).forEach((column) => {
      if (column.type === "number") {
        filters.custom[column.key] = {
          min: "",
          max: "",
        };
      } else if (column.type === "dropdown" && column.selection === "multi") {
        filters.custom[column.key] = { values: [] };
      } else {
        filters.custom[column.key] = { value: "" };
      }
    });

    return filters;
  }

  function getAllCustomColumns(data = state.data) {
    return ((data && data.column_groups) || []).flatMap((group) => group.columns || []);
  }

  function hasBuiltinColumn(key) {
    return ((state.data && state.data.builtin_columns) || []).some((column) => column.key === key);
  }

  function isBuiltinGrouped(key) {
    return ((state.data && state.data.column_groups) || []).some((group) =>
      (group.columns || []).some((column) => column.key === key),
    );
  }

  function buildDraft(students) {
    const draft = {};
    students.forEach((student) => {
      draft[student.userid] = { ...(student.custom || {}) };
    });
    return draft;
  }

  function normaliseDraftValue(value) {
    if (Array.isArray(value)) {
      return value.map((item) => normaliseDraftValue(item));
    }
    if (value && typeof value === "object") {
      return Object.keys(value)
        .sort()
        .reduce((result, key) => {
          result[key] = normaliseDraftValue(value[key]);
          return result;
        }, {});
    }
    return value;
  }

  function cloneMaacDraft(draft) {
    return normaliseDraftValue(draft || {});
  }

  function refreshMaacDraftChanged() {
    if (!state.editMode) {
      state.hasUnsavedChanges = false;
      return;
    }
    state.hasUnsavedChanges =
      JSON.stringify(normaliseDraftValue(state.customDraft)) !==
      JSON.stringify(state.originalCustomDraft);
  }

  function getStudentCustomValue(student, key) {
    if (key === "maac_rating") {
      return student.maac_rating;
    }
    if (state.editMode && state.customDraft[student.userid]) {
      return state.customDraft[student.userid][key];
    }
    return student.custom ? student.custom[key] : "";
  }

  function getOrderedCustomColumns() {
    return getAllCustomColumns();
  }

  function getSelectedValues(selectEl) {
    if (!selectEl) {
      return [];
    }

    return Array.from(selectEl.selectedOptions || [])
      .map((option) => option.value)
      .filter((value) => value !== "");
  }

  function getCustomColumnByKey(key) {
    return getOrderedCustomColumns().find((column) => column.key === key) || null;
  }

  function getMultiSelectAddLabel(column) {
    if (column && /nomination/i.test(column.label || "")) {
      return "Add nomination";
    }
    return "Add selection";
  }

  function renderMultiSelectRow(student, column, selectedValue = "") {
    const safeSelectedValue = selectedValue ? String(selectedValue) : "";
    return `<div class="ba-maac-multi-row" data-multi-row="1">
      <div class="ba-maac-multi-control">
        <div class="ba-maac-multi-input-wrap">
          <input
            type="text"
            class="ba-maac-input ba-maac-input-text ba-maac-multi-search"
            data-userid="${student.userid}"
            data-key="${escapeHtml(column.key)}"
            data-multi-entry="1"
            data-selected-value="${escapeHtml(safeSelectedValue)}"
            value="${escapeHtml(safeSelectedValue)}"
            placeholder="Choose..."
            role="combobox"
            aria-autocomplete="list"
            aria-expanded="false"
            autocomplete="off"
          >
          <button type="button" class="ba-maac-multi-toggle" data-multi-toggle="1" aria-label="Toggle options">
            <span aria-hidden="true">&#9662;</span>
          </button>
        </div>
        <div class="ba-maac-multi-menu">
          <div class="ba-maac-multi-placeholder">Choose...</div>
          <div class="ba-maac-multi-empty" hidden>No matches found</div>
          ${(column.options || [])
            .map(
              (option) => `<button type="button" class="ba-maac-multi-option" data-multi-option="1" data-value="${escapeHtml(
                option,
              )}">${escapeHtml(option)}</button>`,
            )
            .join("")}
        </div>
      </div>
      <button type="button" class="ba-maac-multi-row-remove" data-multi-remove="1" aria-label="Remove selection">&times;</button>
    </div>`;
  }



  function closeAllMultiSelectMenus(exceptControl = null) {
    document.querySelectorAll(".ba-maac-multi-control.is-open").forEach((control) => {
      if (exceptControl && control === exceptControl) {
        return;
      }
      control.classList.remove("is-open");
      const input = control.querySelector(".ba-maac-multi-search");
      if (input) {
        input.setAttribute("aria-expanded", "false");
      }
    });
  }

  function openMultiSelectMenu(control) {
    if (!control) {
      return;
    }

    closeAllMultiSelectMenus(control);
    control.classList.add("is-open");
    const input = control.querySelector(".ba-maac-multi-search");
    if (input) {
      input.setAttribute("aria-expanded", "true");
    }
    filterMultiSelectOptions(control);
  }

  function filterMultiSelectOptions(control) {
    if (!control) {
      return;
    }

    const input = control.querySelector(".ba-maac-multi-search");
    const empty = control.querySelector(".ba-maac-multi-empty");
    const term = (input?.value || "").trim().toLowerCase();
    let visibleCount = 0;

    control.querySelectorAll("[data-multi-option]").forEach((option) => {
      const value = (option.dataset.value || "").toLowerCase();
      const visible = !term || value.includes(term);
      option.hidden = !visible;
      if (visible) {
        visibleCount++;
      }
    });

    if (empty) {
      empty.hidden = visibleCount > 0;
    }
  }

  function normalizeMultiSelectInput(input) {
    if (!input) {
      return "";
    }

    const control = input.closest(".ba-maac-multi-control");
    const rawValue = input.value.trim();
    if (!rawValue) {
      input.dataset.selectedValue = "";
      return "";
    }

    const exactOption = Array.from(control?.querySelectorAll("[data-multi-option]") || []).find(
      (option) => (option.dataset.value || "").toLowerCase() === rawValue.toLowerCase(),
    );

    if (exactOption) {
      const canonicalValue = exactOption.dataset.value || "";
      input.value = canonicalValue;
      input.dataset.selectedValue = canonicalValue;
      return canonicalValue;
    }

    if ((input.dataset.selectedValue || "") !== rawValue) {
      input.dataset.selectedValue = "";
    }

    return input.dataset.selectedValue || "";
  }

  function getMultiSelectValues(userid, key) {
    const seen = new Set();
    const values = [];
    document
      .querySelectorAll(
        `.ba-maac-input[data-userid="${userid}"][data-key="${key}"][data-multi-entry="1"]`,
      )
      .forEach((input) => {
        const value = normalizeMultiSelectInput(input);
        if (value && !seen.has(value)) {
          seen.add(value);
          values.push(value);
        }
      });

    return values;
  }

  function syncMultiSelectDraft(userid, key) {
    if (!state.customDraft[userid]) {
      state.customDraft[userid] = {};
    }
    state.customDraft[userid][key] = getMultiSelectValues(userid, key);
    markMaacDraftChanged();
  }

  function bindMultiSelectRow(row) {
    if (!row) {
      return;
    }

    const input = row.querySelector(".ba-maac-multi-search");
    const toggle = row.querySelector("[data-multi-toggle]");
    const control = row.querySelector(".ba-maac-multi-control");
    const remove = row.querySelector("[data-multi-remove]");

    if (input) {
      input.addEventListener("focus", () => {
        openMultiSelectMenu(control);
      });

      input.addEventListener("click", () => {
        openMultiSelectMenu(control);
      });

      input.addEventListener("input", (event) => {
        if ((input.dataset.selectedValue || "") !== input.value.trim()) {
          input.dataset.selectedValue = "";
        }
        openMultiSelectMenu(control);
        handleCustomInput(event);
      });

      input.addEventListener("change", (event) => {
        normalizeMultiSelectInput(input);
        handleCustomInput(event);
      });

      input.addEventListener("keydown", (event) => {
        if (event.key === "ArrowDown") {
          event.preventDefault();
          openMultiSelectMenu(control);
          return;
        }

        if (event.key === "Enter") {
          const firstVisible = Array.from(
            control.querySelectorAll("[data-multi-option]"),
          ).find((option) => !option.hidden);
          if (firstVisible) {
            event.preventDefault();
            firstVisible.click();
          }
          return;
        }

        if (event.key === "Escape") {
          closeAllMultiSelectMenus();
        }
      });

      input.addEventListener("blur", () => {
        window.setTimeout(() => {
          normalizeMultiSelectInput(input);
          syncMultiSelectDraft(parseInt(input.dataset.userid || "0", 10), input.dataset.key || "");
        }, 120);
      });
    }

    if (toggle) {
      toggle.addEventListener("click", () => {
        const isOpen = control.classList.contains("is-open");
        closeAllMultiSelectMenus();
        if (!isOpen) {
          openMultiSelectMenu(control);
          input?.focus();
        }
      });
    }

    row.querySelectorAll("[data-multi-option]").forEach((option) => {
      option.addEventListener("click", () => {
        if (!input) {
          return;
        }

        input.value = option.dataset.value || "";
        input.dataset.selectedValue = option.dataset.value || "";
        control.classList.remove("is-open");
        handleCustomInput({ target: input });
      });
    });

    if (remove) {
      remove.addEventListener("click", () => {
        const userid = parseInt(input?.dataset.userid || "0", 10);
        const key = input?.dataset.key || "";
        const list = row.closest("[data-multi-list]");
        row.remove();

        if (list && !list.querySelector("[data-multi-row]")) {
          addMultiSelectRow(userid, key);
          return;
        }

        if (userid && key) {
          syncMultiSelectDraft(userid, key);
        }
      });
    }
  }

  function bindMultiSelectEditors() {
    document.querySelectorAll(".ba-maac-multi-row").forEach((row) => {
      if (row.dataset.bound === "1") {
        return;
      }
      row.dataset.bound = "1";
      bindMultiSelectRow(row);
    });

    document.querySelectorAll(".ba-maac-multi-add").forEach((button) => {
      if (button.dataset.bound === "1") {
        return;
      }
      button.dataset.bound = "1";
      button.addEventListener("click", () => {
        addMultiSelectRow(button.dataset.userid, button.dataset.key);
      });
    });
  }



  function getModuleMetricValue(student, column) {
    const module = student.modules ? student.modules[column.key] : null;
    if (!module || typeof module !== "object") {
      return {
        value: module ?? "",
        mode: "grade",
      };
    }

    const mode = column.isattendance || state.filterValues.metricMode === "grade"
      ? "grade"
      : "completion";
    return {
      value: mode === "completion" ? module.completion : module.grade,
      mode,
    };
  }

  function getOverallPerformanceValue(student) {
    const values = (state.data.module_columns || []).map((column) => {
      const value = getModuleMetricValue(student, column).value;
      const numeric = parseFloat(value);
      return Number.isFinite(numeric) ? numeric : 0;
    });

    if (!values.length) {
      return 0;
    }

    return Math.round((values.reduce((sum, value) => sum + value, 0) / values.length) * 100) / 100;
  }

  function parseFilterNumber(value) {
    if (value === null || value === undefined) {
      return null;
    }

    const normalized = String(value).replace(/,/g, "").trim();
    if (normalized === "") {
      return null;
    }

    const parsed = parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : null;
  }

  function getNormalizedNumberBounds(filter) {
    const rawMin = parseFilterNumber(filter?.min);
    const rawMax = parseFilterNumber(filter?.max);
    if (rawMin !== null && rawMax !== null && rawMin > rawMax) {
      return { min: rawMax, max: rawMin };
    }
    return { min: rawMin, max: rawMax };
  }

  function matchesNumericFilter(rawValue, filter) {
    const { min, max } = getNormalizedNumberBounds(filter);
    const hasFilter = min !== null || max !== null;
    const value = parseFilterNumber(rawValue);

    if (value === null) {
      return !hasFilter;
    }
    if (min !== null && value < min) {
      return false;
    }
    if (max !== null && value > max) {
      return false;
    }
    return true;
  }

  function renderFilterBar() {
    return `
      <div class="ba-filter-sidebar ba-maac-filter-sidebar${state.filtersHidden ? " is-hidden" : ""}">
        <div class="ba-sidebar-head">
          Filters
          <span id="ba-maac-reset" style="cursor:pointer;color:var(--primary);font-size:12px;font-weight:600;">Reset All</span>
        </div>
        <div class="ba-maac-filter-grid">
          <div class="ba-filter-toggle">
            <span class="ba-ft-label">Mode:</span>
            <div class="ba-toggle-wrapper">
              <span class="ba-toggle-text ${state.filterValues.metricMode !== "completion" ? "active" : ""}" id="ba-maac-mode-grade">Grade</span>
              <label class="ba-switch">
                <input type="checkbox" id="ba-maac-metric-toggle" ${state.filterValues.metricMode === "completion" ? "checked" : ""}>
                <span class="ba-slider round"></span>
              </label>
              <span class="ba-toggle-text ${state.filterValues.metricMode === "completion" ? "active" : ""}" id="ba-maac-mode-comp">Comp.</span>
            </div>
          </div>
          <div class="ba-filter-item-modern">
            <div class="ba-filter-header-row">
              <span class="ba-filter-label">Course Group</span>
            </div>
            <select id="ba-maac-course-group" class="ba-select-small" style="width:100%; box-sizing: border-box;">
              <option value="">All Groups</option>
              ${(state.data.moodle_groups || [])
                .map(
                  (group) =>
                    `<option value="${group.id}"${String(state.filterValues.courseGroup || "") === String(group.id) ? " selected" : ""}>${escapeHtml(group.name)}</option>`,
                )
                .join("")}
            </select>
          </div>
          ${getOrderedCustomColumns()
            .map((column) => renderCustomFilterControl(column))
            .join("")}
          ${renderStandardFilterControls()}
        </div>
      </div>`;
  }

  function renderStandardFilterControls() {
    const filterKeys = [
      "builtin:performance",
      "builtin:maac",
      ...(state.data.module_columns || []).map((column) => `module:${column.key}`),
    ];

    return filterKeys.map((filterKey) => renderDynamicFilterControl(filterKey)).join("");
  }

  function renderDynamicFilterControl(filterKey) {
    const [group, key] = filterKey.split(":");

    if (group === "builtin") {
      if (key === "performance") {
        return `
          <div class="ba-filter-item-modern">
            <div class="ba-filter-header-row">
              <span class="ba-filter-label">Overall Performance</span>
            </div>
            <div class="ba-range-wrapper">
              <input type="number" id="ba-maac-performance-min" class="ba-range-input" step="0.1" placeholder="0" value="${escapeHtml(state.filterValues.performanceMin || "0")}">
              <span class="ba-range-divider">-</span>
              <input type="number" id="ba-maac-performance-max" class="ba-range-input" step="0.1" placeholder="100" value="${escapeHtml(state.filterValues.performanceMax || "100")}">
            </div>
          </div>`;
      }

      if (key === "maac") {
        return `
          <div class="ba-filter-item-modern">
            <div class="ba-filter-header-row">
              <span class="ba-filter-label">MAAC Ratings</span>
            </div>
            <div class="ba-range-wrapper">
              <input type="number" id="ba-maac-rating-min" class="ba-range-input" min="0" max="9" step="0.1" placeholder="0" value="${escapeHtml(state.filterValues.maacMin || "0")}">
              <span class="ba-range-divider">-</span>
              <input type="number" id="ba-maac-rating-max" class="ba-range-input" min="0" max="9" step="0.1" placeholder="9" value="${escapeHtml(state.filterValues.maacMax || "9")}">
            </div>
          </div>`;
      }
    }

    if (group === "module") {
      const column = (state.data.module_columns || []).find((item) => item.key === key);
      if (!column) {
          return "";
      }

      return `
        <div class="ba-filter-item-modern">
          <div class="ba-filter-header-row">
            <span class="ba-filter-label">${escapeHtml(column.label)}</span>
          </div>
          ${renderModuleFilterControl(column)}
        </div>`;
    }

    return "";
  }

  function renderMaacActionButtons() {
    if (!state.data.canedit) {
      return "";
    }

    return state.editMode
      ? `<button type="button" id="ba-maac-save" class="ba-btn ba-btn-success">Save</button>
         <button type="button" id="ba-maac-cancel" class="ba-btn">Cancel</button>`
      : `<button type="button" id="ba-maac-edit" class="ba-btn ba-btn-view">Edit MAAC</button>`;
  }

  function renderTableControls() {
    return `
      <div class="ba-table-controls ba-maac-table-controls" style="align-items: center;">
        <div style="display: flex; flex-direction: column; gap: 4px;">
        <div style="font-size: 16px; font-weight: 700; color: #1e293b;">MAAC Student Performance</div>
          <span id="ba-maac-count" class="ba-maac-count" style="font-size: 13px; font-weight: 600; color: var(--text-gray);">Found ${(state.filtered || []).length} students</span>
        </div>
        <label class="ba-maac-search-wrap" aria-label="Search student name">
          <svg class="ba-maac-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input type="text" id="ba-maac-search" class="ba-maac-search-input" placeholder="Search student name..." value="${escapeHtml(state.filterValues.search || "")}">
        </label>
      </div>`;
  }

  function renderModuleFilterControl(column) {
    const filter = state.filterValues.modules?.[column.key] || {};
    return `
      <div class="ba-range-wrapper">
        <input type="number" class="ba-range-input" data-filter-key="${escapeHtml(
          column.key,
        )}" data-filter-group="module" data-filter-type="number" data-filter-bound="min" step="0.1" placeholder="0" value="${escapeHtml(filter.min ?? "")}">
        <span class="ba-range-divider">-</span>
        <input type="number" class="ba-range-input" data-filter-key="${escapeHtml(
          column.key,
        )}" data-filter-group="module" data-filter-type="number" data-filter-bound="max" step="0.1" placeholder="100" value="${escapeHtml(filter.max ?? "")}">
      </div>`;
  }

  function renderCustomFilterControl(column) {
    const filter = state.filterValues.custom?.[column.key] || {};
    if (column.key === "trend") {
      const options = [
        { value: "", label: "All" },
        { value: "Improving", label: "Improving" },
        { value: "Stable", label: "Stable" },
        { value: "Declining", label: "Declining" },
      ];

      return `
      <div class="ba-filter-item-modern">
        <div class="ba-filter-header-row"><span class="ba-filter-label">${escapeHtml(column.label)}</span></div>
        <select data-filter-key="${escapeHtml(column.key)}" data-filter-group="custom" data-filter-type="dropdown" class="ba-select-small" style="width:100%; box-sizing: border-box;">
          ${options
            .map(
              (option) =>
                `<option value="${escapeHtml(option.value)}"${String(filter.value || "") === String(option.value) ? " selected" : ""}>${escapeHtml(option.label)}</option>`,
            )
            .join("")}
        </select>
      </div>`;
    }

    if (column.type === "number") {
      const min = filter.min ?? "";
      const max = filter.max ?? "";
      const rangeAttrs = `${column.min !== null && column.min !== undefined ? ` min="${escapeHtml(String(column.min))}"` : ""}${column.max !== null && column.max !== undefined ? ` max="${escapeHtml(String(column.max))}"` : ""}`;
      return `
      <div class="ba-filter-item-modern">
        <div class="ba-filter-header-row"><span class="ba-filter-label">${escapeHtml(column.label)}</span></div>
        <div class="ba-range-wrapper">
          <input type="number" class="ba-range-input" data-filter-key="${escapeHtml(
            column.key,
          )}" data-filter-group="custom" data-filter-type="number" data-filter-bound="min" step="0.1" placeholder="Min" value="${escapeHtml(String(min))}"${rangeAttrs}>
          <span class="ba-range-divider">-</span>
          <input type="number" class="ba-range-input" data-filter-key="${escapeHtml(
            column.key,
          )}" data-filter-group="custom" data-filter-type="number" data-filter-bound="max" step="0.1" placeholder="Max" value="${escapeHtml(String(max))}"${rangeAttrs}>
        </div>
      </div>`;
    }

    if (column.type === "dropdown" && column.selection === "multi") {
      const selectedValues = filter.values || [];
      return `
      <div class="ba-filter-item-modern">
        <div class="ba-filter-header-row"><span class="ba-filter-label">${escapeHtml(column.label)}</span></div>
        <div class="ba-maac-filter-checkbox-list">
          ${(column.options || [])
            .map(
              (option) =>
                `<label class="ba-maac-filter-checkbox">
                  <input
                    type="checkbox"
                    data-filter-key="${escapeHtml(column.key)}"
                    data-filter-group="custom"
                    data-filter-type="dropdown-multi"
                    data-filter-value="${escapeHtml(option)}"
                    ${selectedValues.includes(option) ? "checked" : ""}
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
          ? [
              { value: "", label: "All" },
              { value: "1", label: "Yes" },
              { value: "0", label: "No" },
            ]
          : [{ value: "", label: "All" }].concat(
              (column.options || []).map((option) => ({
                value: option,
                label: option,
              })),
            );

      return `
      <div class="ba-filter-item-modern">
        <div class="ba-filter-header-row"><span class="ba-filter-label">${escapeHtml(column.label)}</span></div>
        <select data-filter-key="${escapeHtml(column.key)}" data-filter-group="custom" data-filter-type="${column.type}" class="ba-select-small" style="width:100%; box-sizing: border-box;">
          ${options
            .map(
              (option) =>
                `<option value="${escapeHtml(option.value)}"${String(filter.value || "") === String(option.value) ? " selected" : ""}>${escapeHtml(option.label)}</option>`,
            )
            .join("")}
        </select>
      </div>`;
    }

    return `
    <div class="ba-filter-item-modern">
      <div class="ba-filter-header-row"><span class="ba-filter-label">${escapeHtml(column.label)}</span></div>
      <input type="text" data-filter-key="${escapeHtml(column.key)}" data-filter-group="custom" data-filter-type="${column.type}" placeholder="Search..." value="${escapeHtml(filter.value || "")}">
    </div>`;
  }

  function renderTable() {

    const moduleColumns = state.data.module_columns || [];
    const showMaacRatingColumn = hasBuiltinColumn("maac_rating") && !isBuiltinGrouped("maac_rating");
    const customGroups = (state.data.column_groups || []).map((group) => {
      const collapsed = !!state.collapsedGroups[`group:${group.name}`];
      return {
        ...group,
        collapsed,
        visibleColumns: collapsed ? [] : group.columns || [],
      };
    });
    const moduleColumnsVisible = state.collapsedGroups.module ? [] : moduleColumns;
    const leafColumns = [];
    const addLeafColumn = (key, width) => {
      leafColumns.push({
        key,
        width: getColumnWidth(key, width),
      });
      return leafColumns.length - 1;
    };
    const topHeaderCells = [
      renderResizableHeader({
        label: "Student Name",
        columnKey: "student",
        columnIndex: addLeafColumn("student", 220),
        sortable: true,
        sortKey: "student",
        sortType: "text",
        rowSpan: 2,
        className: "ba-maac-group-head ba-maac-sticky-col ba-maac-student-head ba-maac-front-head",
      }),
      renderResizableHeader({
        label: "Username",
        columnKey: "username",
        columnIndex: addLeafColumn("username", 160),
        sortable: true,
        sortKey: "username",
        sortType: "text",
        rowSpan: 2,
        className: "ba-maac-group-head ba-maac-username-head",
      }),
    ];
    const secondHeaderCells = [];

    if (state.collapsedGroups.module) {
      topHeaderCells.push(
        renderResizableHeader({
          label: `<button type="button" class="ba-maac-group-toggle" data-group-key="module">
            <span class="ba-maac-group-toggle-icon" aria-hidden="true">+</span>
            <span class="ba-maac-group-toggle-label">Module Performance</span>
          </button>`,
          columnKey: "module:collapsed",
          columnIndex: addLeafColumn("module:collapsed", 180),
          rowSpan: 2,
          className: "ba-maac-group-head",
        }),
      );
    } else {
      topHeaderCells.push(
        renderGroupHeader({
          label: "Module Performance",
          groupKey: "module",
          colSpan: Math.max(1 + (showMaacRatingColumn ? 1 : 0) + moduleColumnsVisible.length, 1),
        }),
      );
      if (showMaacRatingColumn) {
        secondHeaderCells.push(
          renderResizableHeader({
            label: "MAAC Rating",
            columnKey: "module:maac_rating",
            columnIndex: addLeafColumn("module:maac_rating", 150),
            sortable: true,
            sortKey: "module:maac_rating",
            sortType: "number",
            className: "ba-maac-col-head",
          }),
        );
      }
      moduleColumnsVisible.forEach((column) => {
        secondHeaderCells.push(
          renderResizableHeader({
            label: escapeHtml(column.label),
            columnKey: `module:${column.key}`,
            columnIndex: addLeafColumn(`module:${column.key}`, 170),
            sortable: true,
            sortKey: `module:${column.key}`,
            sortType: "number",
            className: "ba-maac-col-head",
          }),
        );
      });
      secondHeaderCells.push(
        renderResizableHeader({
          label: "Overall Performance",
          columnKey: "module:overall_performance",
          columnIndex: addLeafColumn("module:overall_performance", 180),
          sortable: true,
          sortKey: "module:overall_performance",
          sortType: "number",
          className: "ba-maac-col-head",
        }),
      );
    }

    customGroups.forEach((group) => {
      if (group.collapsed) {
        topHeaderCells.push(
          renderResizableHeader({
            label: `<button type="button" class="ba-maac-group-toggle" data-group-key="group:${escapeHtml(group.name)}">
              <span class="ba-maac-group-toggle-icon" aria-hidden="true">+</span>
              <span class="ba-maac-group-toggle-label">${escapeHtml(group.name)}</span>
            </button>`,
            columnKey: `group:${group.name}:collapsed`,
            columnIndex: addLeafColumn(`group:${group.name}:collapsed`, 180),
            rowSpan: 2,
            className: "ba-maac-group-head",
          }),
        );
        return;
      }

      topHeaderCells.push(
        renderGroupHeader({
          label: group.name,
          groupKey: `group:${group.name}`,
          colSpan: Math.max(group.visibleColumns.length, 1),
        }),
      );
      (group.visibleColumns || []).forEach((column) => {
        secondHeaderCells.push(
          renderResizableHeader({
            label: escapeHtml(column.label),
            columnKey: `custom:${column.key}`,
            columnIndex: addLeafColumn(`custom:${column.key}`, 170),
            sortable: true,
            sortKey: `custom:${column.key}`,
            sortType:
              column.type === "number"
                ? "number"
                : column.type === "boolean"
                  ? "boolean"
                  : "text",
            className: "ba-maac-col-head",
          }),
        );
      });
    });

    topHeaderCells.push(
      renderResizableHeader({
        label: "Ticket",
        columnKey: "ticket",
        columnIndex: addLeafColumn("ticket", 150),
        sortable: true,
        sortKey: "ticket",
        sortType: "number",
        rowSpan: 2,
        className: "ba-maac-group-head ba-maac-ticket-head",
      }),
    );
    topHeaderCells.push(
      renderResizableHeader({
        label: "Latest Ticket Feedback",
        columnKey: "latest_feedback",
        columnIndex: addLeafColumn("latest_feedback", 220),
        sortable: true,
        sortKey: "latest_feedback",
        sortType: "text",
        rowSpan: 2,
        className: "ba-maac-group-head ba-maac-feedback-head",
      }),
    );

    const topHeader = `<tr>${topHeaderCells.join("")}</tr>`;
    const secondHeader = `<tr>${secondHeaderCells.join("")}</tr>`;

    const rows = getSortedMaacStudents(state.filtered)
      .map((student) => {
        let displayTicket = null;
        let displayTicketIndex = 1;

        if (Array.isArray(student.tickets) && student.tickets.length > 0) {
          // Tickets are returned newest first. The row status and feedback must
          // always follow the newest ticket, even when an older ticket was resolved.
          displayTicket = student.tickets[0];
          displayTicketIndex = student.tickets.length;
        }

        const latestStatus = String(displayTicket?.status || "").toLowerCase();
        const isEscalatedResolved = latestStatus === "resolved" && !!displayTicket?.escalatedtopm;
        let latestFeedbackHtml = escapeHtml("-");
        if (displayTicket) {
          const ticketTitle = `T${displayTicketIndex} - ${displayTicket.tickettitle || displayTicket.title || "Ticket"}`;
          const ticketDate = new Date(displayTicket.timecreated * 1000).toLocaleDateString();
          const feedbackText = displayTicket.resolutionfeedback || "No feedback yet";

          latestFeedbackHtml = `
            <div class="ba-maac-latest-feedback">
              <div class="ba-maac-latest-feedback-head" style="margin-bottom: 4px; font-size: 13px;">
                <strong>${escapeHtml(ticketTitle)}</strong>
                <span style="color: var(--text-gray); font-size: 11px;">(${escapeHtml(ticketDate)})</span>
              </div>
              <div class="ba-maac-latest-feedback-text" style="font-size: 13px;">${escapeHtml(feedbackText)}</div>
            </div>`;
        }
        const rowClass =
          isEscalatedResolved
            ? "ba-maac-ticket-row-escalated-resolved"
            : latestStatus === "resolved"
              ? "ba-maac-ticket-row-resolved"
              : latestStatus
                ? "ba-maac-ticket-row-open"
                : "";
        const studentDetailCells = [
          `<td class="ba-maac-sticky-col ba-maac-student-cell ba-maac-front-cell"><strong>${escapeHtml(student.fullname)}</strong></td>`,
          `<td>${escapeHtml(student.username)}</td>`,
        ];
        const moduleCells = state.collapsedGroups.module
          ? [`<td class="ba-maac-collapsed-col"></td>`]
          : [
              ...(showMaacRatingColumn
                ? [`<td>${renderScoreBadge(student.maac_rating, { scale: 9, decimals: 1 })}</td>`]
                : []),
              ...moduleColumnsVisible.map((column) => {
                const metric = getModuleMetricValue(student, column);
                return `<td>${renderScoreBadge(metric.value, { scale: 100, suffix: "%" })}</td>`;
              }),
              `<td>${renderScoreBadge(getOverallPerformanceValue(student), { scale: 100, suffix: "%", decimals: 2, extraClass: "ba-overall-performance" })}</td>`,
            ];

        const customCells = customGroups
          .map((group) => {
            if (group.collapsed) {
              return `<td class="ba-maac-collapsed-col"></td>`;
            }
            return (group.visibleColumns || [])
              .map((column) =>
                renderCustomCell(student, column, getStudentCustomValue(student, column.key)),
              )
              .join("");
          })
          .join("");

        return `<tr class="${rowClass}">
          ${studentDetailCells.join("")}
          ${moduleCells.join("")}
          ${customCells}
          ${renderTicketActionCell(student)}
          <td>${latestFeedbackHtml}</td>
        </tr>`;
      })
      .join("");

    return `
      <div class="ba-table-wrap">
        <table class="ba-table ba-maac-table ba-resizable-table">
          <colgroup>
            ${leafColumns.map((column) => `<col style="width:${column.width}px">`).join("")}
          </colgroup>
          <thead>
            ${topHeader}
            ${secondHeader}
          </thead>
          <tbody>${rows || '<tr><td colspan="99" class="ba-maac-empty">No students found</td></tr>'}</tbody>
        </table>
      </div>`;
  }

  function renderTicketActionCell(student) {
    const tickets = Array.isArray(student.tickets) ? student.tickets : [];
    const ticketCount = Number(student.ticket_count ?? tickets.length) || 0;
    const hasTickets = ticketCount > 0;
    const action = state.editMode && state.data?.canraise ? "raise" : "view";
    const label = action === "raise" ? "Raise Ticket" : "View Ticket";
    const countLabel = hasTickets ? ` (${ticketCount})` : "";

    return `<td class="ba-maac-ticket-cell">
      <button type="button" class="ba-btn ba-btn-sm ba-maac-ticket-action ba-maac-ticket-action-${action}" data-studentid="${escapeHtml(String(student.userid))}" data-ticket-action="${action}">${escapeHtml(label + countLabel)}</button>
    </td>`;
  }

  function getTicketMeta() {
    return state.data?.ticket_meta || {
      ss_team_role: { id: 0, label: "SS Team" },
      ss_team_users: [],
      batch_manager_role: { id: 0, label: "Batch Manager" },
      batch_manager_users: [],
    };
  }

  function getTicketTemplates() {
    return Array.isArray(state.data?.ticket_templates) ? state.data.ticket_templates : [];
  }

  function renderTicketInfoRows(rows) {
    return rows
      .map(
        (row) => `<div class="ba-maac-ticket-info-row">
          <div class="ba-maac-ticket-info-label">${escapeHtml(row.label)}</div>
          <div class="ba-maac-ticket-info-value">${escapeHtml(row.value || "-")}</div>
        </div>`,
      )
      .join("");
  }

  function formatTicketTimestamp(timestamp) {
    const value = Number(timestamp || 0);
    if (!value) {
      return "-";
    }

    try {
      return new Date(value * 1000).toLocaleString();
    } catch (error) {
      return "-";
    }
  }

  function getTicketTitle(ticket) {
    return ticket?.tickettitle || ticket?.title || "";
  }

  function getTicketReason(ticket) {
    return ticket?.ticketreason || ticket?.reason || "";
  }

  function renderTicketHistory(tickets) {
    if (!Array.isArray(tickets) || !tickets.length) {
      return `<div class="ba-maac-ticket-empty">No tickets available</div>`;
    }

    const renderTimeline = (timeline) => {
      if (!Array.isArray(timeline) || !timeline.length) {
        return "";
      }

      return `<div class="ba-maac-ticket-timeline">
        <div class="ba-maac-ticket-timeline-bar">
          <div class="ba-maac-ticket-timeline-title">Ticket Timeline</div>
          <button type="button" class="ba-btn ba-maac-ticket-timeline-toggle" aria-expanded="false">Show Timeline</button>
        </div>
        <div class="ba-maac-ticket-timeline-list is-hidden">
          ${timeline
            .map(
              (event) => `<div class="ba-maac-ticket-timeline-item">
                <div class="ba-maac-ticket-timeline-dot" aria-hidden="true"></div>
                <div class="ba-maac-ticket-timeline-content">
                  <div class="ba-maac-ticket-timeline-head">
                    <span class="ba-maac-ticket-timeline-label">${escapeHtml(event.title || "Update")}</span>
                    <span class="ba-maac-ticket-timeline-time">${escapeHtml(formatTicketTimestamp(event.timecreated))}</span>
                  </div>
                  <div class="ba-maac-ticket-timeline-actor">${escapeHtml(event.actorname || "-")}</div>
                  ${
                    Array.isArray(event.details) && event.details.length
                      ? `<ul class="ba-maac-ticket-timeline-details">${event.details
                          .map((detail) => `<li>${escapeHtml(detail)}</li>`)
                          .join("")}</ul>`
                      : ""
                  }
                </div>
              </div>`,
            )
            .join("")}
        </div>
      </div>`;
    };

    return `<div class="ba-maac-ticket-history">
      ${tickets
        .map((ticket) => {
          const ticketid = Number(ticket.id);
          const isEditing = state.ticketEdit && Number(state.ticketEdit.ticketid) === ticketid;
          const title = getTicketTitle(ticket);
          const reason = getTicketReason(ticket);
          const editTitle = isEditing ? state.ticketEdit.tickettitle : title;
          const editReason = isEditing ? state.ticketEdit.ticketreason : reason;
          const resolutionBlock =
            ticket.statuskey === "resolved" || ticket.resolutionfeedback
              ? `<div class="ba-maac-ticket-history-update">
                  ${
                    ticket.resolvedbyfullname
                      ? `<div class="ba-maac-ticket-history-update-row"><strong>Resolved By:</strong> ${escapeHtml(ticket.resolvedbyfullname)}</div>`
                      : ""
                  }
                  ${
                    ticket.resolutionfeedback
                      ? `<div class="ba-maac-ticket-history-update-row"><strong>Feedback:</strong> ${escapeHtml(ticket.resolutionfeedback)}</div>`
                      : ""
                  }
                </div>`
              : "";

          const statusClass = [
            "ba-maac-ticket-history-status",
            ticket.statuskey ? `ba-maac-ticket-history-status-${String(ticket.statuskey).replace(/_/g, "-")}` : "",
          ]
            .filter(Boolean)
            .join(" ");

          return `<div class="ba-maac-ticket-history-item" data-ticket-id="${ticketid}">
            <div class="ba-maac-ticket-history-head">
              <div class="ba-maac-ticket-history-title">${escapeHtml(title || "-")}</div>
              <div style="display:flex; align-items:center; gap:8px;">
                <div class="${statusClass}">${escapeHtml(ticket.status || "Open")}</div>
                ${ticket.canedit ? `<button type="button" class="ba-btn ba-btn-sm ba-btn-view" data-maac-ticket-edit="${ticketid}">Edit</button>` : ""}
              </div>
            </div>
            ${isEditing
              ? `<div class="ba-maac-ticket-grid" style="margin-top:10px;">
                  <div class="ba-maac-ticket-field ba-maac-ticket-field-full">
                    <label>Ticket Title <span class="ba-maac-ticket-required">*</span></label>
                    <input type="text" class="ba-range-input" data-ticket-edit-title="${ticketid}" value="${escapeHtml(editTitle)}">
                  </div>
                  <div class="ba-maac-ticket-field ba-maac-ticket-field-full">
                    <label>Reason for Raising Ticket <span class="ba-maac-ticket-required">*</span></label>
                    <textarea class="ba-maac-ticket-textarea" rows="4" data-ticket-edit-reason="${ticketid}">${escapeHtml(editReason)}</textarea>
                  </div>
                  <div class="ba-feedback-form-actions" style="justify-content:flex-end;">
                    <button type="button" class="ba-btn ba-btn-sm" data-maac-ticket-edit-cancel="${ticketid}">Cancel</button>
                    <button type="button" class="ba-btn ba-btn-sm ba-btn-primary" data-maac-ticket-edit-save="${ticketid}">Save</button>
                  </div>
                </div>`
              : `<div class="ba-maac-ticket-history-reason-block">
                  <div class="ba-maac-ticket-history-label">Description</div>
                  <div class="ba-maac-ticket-history-reason">${escapeHtml(reason || "-")}</div>
                </div>`}
            ${resolutionBlock}
            ${renderTimeline(ticket.timeline)}
          </div>`;
        })
        .join("")}
    </div>`;
  }
  function renderCustomCell(student, column, value) {
    if (column.key === "trend") {
      return renderTrendCell(student, value);
    }

    if (column.type === "multi_feedback") {
      const feedbackCount = getFeedbackItemCount(value);
      return `<td>
        <button type="button" class="ba-btn ba-btn-sm ba-btn-view ba-maac-feedback-open-modal" data-userid="${student.userid}" data-key="${escapeHtml(column.key)}" style="display:inline-flex; align-items:center; gap:4px; padding:4px 8px; border:1px solid #6366f1; color:#6366f1; background:transparent; border-radius:4px; font-size:12px; font-weight:600; cursor:pointer;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg> View Feedback (${feedbackCount})
        </button>
      </td>`;
    }

    if (column.key === "maac_rating") {
      return `<td>${renderScoreBadge(value, { scale: 9, decimals: 1 })}</td>`;
    }

    if (column.system) {
      return `<td>${renderReadOnlyCustomValue(value, column)}</td>`;
    }

    if (!state.editMode) {
      return `<td>${renderReadOnlyCustomValue(value, column)}</td>`;
    }

    const safeValue = value === null || value === undefined ? "" : value;
    if (column.type === "boolean") {
      return `<td><input type="checkbox" class="ba-maac-input" data-userid="${student.userid}" data-key="${escapeHtml(
        column.key,
      )}" ${safeValue ? "checked" : ""}></td>`;
    }

    if (column.type === "dropdown") {
      if (column.selection === "multi") {
        const selectedValues = Array.isArray(safeValue) ? safeValue.map(String) : [];
        return `<td>
          <div class="ba-maac-multi-select-editor">
            <div class="ba-maac-multi-picker" data-multi-list="${student.userid}:${escapeHtml(column.key)}">
              ${(selectedValues.length ? selectedValues : [""])
                .map((option) => renderMultiSelectRow(student, column, option))
                .join("")}
            </div>
            <button type="button" class="ba-btn ba-btn-sm ba-btn-view ba-maac-multi-add" data-userid="${student.userid}" data-key="${escapeHtml(
              column.key,
            )}">+ ${escapeHtml(getMultiSelectAddLabel(column))}</button>
          </div>
        </td>`;
      }

      return `<td><select class="ba-maac-input ba-maac-input-text" data-userid="${student.userid}" data-key="${escapeHtml(
        column.key,
      )}"><option value="">Select</option>${(column.options || [])
        .map((option) => {
          const selected = String(safeValue) === String(option) ? " selected" : "";
          return `<option value="${escapeHtml(option)}"${selected}>${escapeHtml(option)}</option>`;
        })
        .join("")}</select></td>`;
    }



    if (
      column.type === "number" &&
      column.min !== null &&
      column.min !== undefined &&
      column.max !== null &&
      column.max !== undefined
    ) {
      const min = Math.round(Number(column.min));
      const max = Math.round(Number(column.max));
      if (!Number.isNaN(min) && !Number.isNaN(max) && max >= min && max - min <= 200) {
        return `<td><select class="ba-maac-input ba-maac-input-text" data-userid="${student.userid}" data-key="${escapeHtml(
          column.key,
        )}"><option value="">Select</option>${Array.from(
          { length: max - min + 1 },
          (_, index) => min + index,
        )
          .map((option) => {
            const selected = String(safeValue) === String(option) ? " selected" : "";
            return `<option value="${option}"${selected}>${option}</option>`;
          })
          .join("")}</select></td>`;
      }
    }

    const type = column.type === "date" ? "date" : "text";
    return `<td><input type="${type}" class="ba-maac-input ba-maac-input-text" data-userid="${student.userid}" data-key="${escapeHtml(
      column.key,
    )}" value="${escapeHtml(safeValue)}"></td>`;
  }

  function renderReadOnlyCustomValue(value, column) {
    if (column.type === "number") {
      const displayValue = value === null || value === undefined || value === "" ? "-" : value;
      return `<span class="ba-filter-percentage medium">${escapeHtml(String(displayValue))}</span>`;
    }

    return formatDisplayValue(value, column.type);
  }

  function normalizeDisplayList(value) {
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

  function normalizeFeedbackItems(value) {
    const list = Array.isArray(value) ? value : value ? [value] : [];
    return list
      .map((item) => {
        if (typeof item === "string") {
          const raw = item.trim();
          if (raw.startsWith("[") && raw.endsWith("]")) {
            try {
              const parsed = JSON.parse(raw);
              if (Array.isArray(parsed)) {
                return normalizeFeedbackItems(parsed);
              }
            } catch (error) {
              return { date: "", text: raw, added_at: null };
            }
          }
          return { date: "", text: raw, added_at: null };
        }

        if (item && typeof item === "object") {
          return {
            ...item,
            date: String(item.date || ""),
            text: String(item.text || item.label || item.value || ""),
            added_at: item.added_at ?? null,
          };
        }

        return { date: "", text: String(item || ""), added_at: null };
      })
      .flat()
      .filter((item) => item && item.text.trim() !== "");
  }

  function getFeedbackItemCount(value) {
    return normalizeFeedbackItems(value).length;
  }

  function getFeedbackSummary() {
    const columns = getAllCustomColumns().filter((column) => column.type === "multi_feedback");
    const studentCounts = (state.data?.students || []).map((student) =>
      Object.fromEntries(columns.map((column) => [column.key, getFeedbackItemCount(student.custom?.[column.key])])),
    );
    const summaries = columns.map((column) => {
      const values = studentCounts.map((counts) => counts[column.key] || 0);
      const received = values.filter((count) => count > 0);
      return {
        key: column.key,
        label: column.label || "Feedback",
        highest: received.length ? Math.max(...received) : 0,
        studentCounts: values,
      };
    });
    const highest = summaries.length ? Math.max(...summaries.map((summary) => summary.highest)) : 0;
    const rows = Array.from({ length: highest }, (_, index) => {
      const feedbackNumber = index + 1;
      return {
        feedbackNumber,
        counts: Object.fromEntries(
          summaries.map((summary) => [
            summary.key,
            studentCounts.filter((counts) => (counts[summary.key] || 0) >= feedbackNumber).length,
          ]),
        ),
      };
    });

    return { summaries, rows };
  }

  function renderFeedbackSummaryModal() {
    if (!state.feedbackSummaryModal) {
      return "";
    }

    const summary = getFeedbackSummary();
    const rows = summary.rows.length
      ? summary.rows
          .map(
            (row) => `<tr>
              <td>Feedback ${row.feedbackNumber}</td>
              ${summary.summaries.map((column) => `<td>${row.counts[column.key] || 0}</td>`).join("")}
            </tr>`,
          )
          .join("")
      : `<tr><td colspan="${summary.summaries.length + 1}">No feedback has been provided yet.</td></tr>`;
    const headers = summary.summaries.map((column) => `<th>${escapeHtml(column.label)}</th>`).join("");

    return `<div class="ba-modal-overlay" id="ba-maac-feedback-summary-modal" role="dialog" aria-modal="true" aria-labelledby="ba-maac-feedback-summary-title">
      <div class="ba-modal-container ba-maac-ticket-dialog">
        <div class="ba-modal-header">
          <h3 id="ba-maac-feedback-summary-title">Feedback</h3>
          <button type="button" class="ba-modal-close" id="ba-maac-feedback-summary-close" aria-label="Close">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </div>
        <div class="ba-modal-body ba-maac-ticket-body">
          <div class="ba-maac-ticket-panel">
            <div class="ba-table-wrap">
              <table class="ba-table">
                <thead><tr><th>Feedback</th>${headers}</tr></thead>
                <tbody>${rows}</tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>`;
  }

  function formatDisplayValue(value, type) {
    if (type === "boolean") {
      return value ? "Yes" : "No";
    }
    const list = normalizeDisplayList(value);
    if (list) {
      return escapeHtml(list.length ? list.join(" | ") : "-");
    }
    return escapeHtml(value === null || value === undefined || value === "" ? "-" : value);
  }

  function getScoreClass(value, scale = 100) {
    if (value === "" || value === null || value === undefined || isNaN(parseFloat(value))) {
      return "loading";
    }

    const score = scale === 100 ? parseFloat(value) : parseFloat(value) * (100 / scale);
    if (score >= 75) return "high";
    if (score >= 50) return "medium";
    return "low";
  }

  function renderScoreBadge(value, { scale = 100, suffix = "", decimals = 0, extraClass = "" } = {}) {
    if (value === "" || value === null || value === undefined || isNaN(parseFloat(value))) {
      return `<span class="ba-filter-percentage loading">-</span>`;
    }

    const formatted = parseFloat(value).toFixed(decimals);
    const className = ["ba-filter-percentage", extraClass, getScoreClass(value, scale)].filter(Boolean).join(" ");
    return `<span class="${className}">${escapeHtml(formatted + suffix)}</span>`;
  }

  function renderTrendBadge(value) {
    const normalized = String(value || "").trim().toLowerCase();
    if (!normalized || normalized === "-") {
      return `<span class="ba-maac-inline-empty">-</span>`;
    }

    if (normalized === "improving") {
      return `<span class="ba-trend-badge ba-trend-badge-up">Up ${escapeHtml(String(value))}</span>`;
    }

    if (normalized === "declining") {
      return `<span class="ba-trend-badge ba-trend-badge-down">Down ${escapeHtml(String(value))}</span>`;
    }

    return `<span class="ba-trend-badge ba-trend-badge-stable">Stable ${escapeHtml(String(value))}</span>`;
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

  function getTrendVisual(value) {
    const key = normalizeTrendKey(value);
    if (!key) {
      return null;
    }

    if (key === "up") {
      return {
        key,
        label: "Improving",
        emoji: "Up",
        className: "ba-trend-badge-up",
      };
    }

    if (key === "down") {
      return {
        key,
        label: "Declining",
        emoji: "Down",
        className: "ba-trend-badge-down",
      };
    }

    return {
      key: "stable",
      label: "Stable",
      emoji: "Stable",
      className: "ba-trend-badge-stable",
    };
  }

  function renderTrendDisplayBadge(value) {
    const visual = getTrendVisual(value);
    if (!visual) {
      return `<span class="ba-maac-inline-empty">-</span>`;
    }

    return `<span class="ba-trend-badge ${visual.className}"><span>${visual.emoji}</span><span>${escapeHtml(
      visual.label,
    )}</span></span>`;
  }

  function getTrendVisualFixed(value) {
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

  function renderTrendDisplayBadgeFixed(value) {
    const visual = getTrendVisualFixed(value);
    if (!visual) {
      return `<span class="ba-maac-inline-empty">-</span>`;
    }

    return `<span class="ba-trend-badge ${visual.className}"><span>${visual.emoji}</span><span>${escapeHtml(
      visual.label,
    )}</span></span>`;
  }

  function renderTrendMiniChart(points) {
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
          <linearGradient id="ba-trend-fill" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stop-color="rgba(102, 126, 234, 0.28)"></stop>
            <stop offset="100%" stop-color="rgba(102, 126, 234, 0.04)"></stop>
          </linearGradient>
        </defs>
        ${axisLabels}
        <path d="${area}" fill="url(#ba-trend-fill)"></path>
        <path d="${path}" fill="none" stroke="rgba(102, 126, 234, 1)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
        ${circles}
      </svg>
    </div>`;
  }

  function renderTrendCell(student, value) {
    return `<td>
      <button
        type="button"
        class="ba-trend-badge-btn"
        data-trend-userid="${student.userid}"
        aria-label="View trend details for ${escapeHtml(student.fullname || student.username || "student")}"
      >${renderTrendDisplayBadgeFixed(value)}</button>
    </td>`;
  }

  function getStudentTrendDetails(student) {
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

  function formatTrendDate(timestamp) {
    const numeric = Number(timestamp);
    if (!numeric) {
      return "-";
    }

    return new Date(numeric * 1000).toLocaleDateString(undefined, {
      day: "2-digit",
      month: "short",
    });
  }

  function formatTrendPercent(value) {
    const numeric = parseFloat(value);
    if (Number.isNaN(numeric)) {
      return "-";
    }

    return `${Math.round(numeric * 10) / 10}%`;
  }

  function calculateTrendComponent(points) {
    if (!Array.isArray(points) || !points.length) {
      return null;
    }

    if (points.length === 1) {
      const grade = parseFloat(points[0].grade);
      if (Number.isNaN(grade)) {
        return null;
      }

      const rounded = Math.round(grade * 10) / 10;
      return {
        start: rounded,
        latest: rounded,
        change: 0,
        method: "single-point",
      };
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
        method: "first-last",
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
      method: "half-vs-half",
    };
  }

  function calculateAttendanceSummary(attendancePoints, windowSize) {
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

  function calculateOverallTrendFromDetails(details) {
    const trendWindow = Math.max(1, Number(details?.trend_window) || 20);
    const attendanceWindow = Math.max(
      2,
      Math.min(Number(details?.attendance_window) || 5, trendWindow),
    );
    const changes = [];

    ["assignments", "quizzes", "projects"].forEach((key) => {
      const points = Array.isArray(details?.[key]) ? details[key].slice(-trendWindow) : [];
      const summary = calculateTrendComponent(points);
      if (summary && typeof summary.change === "number" && !Number.isNaN(summary.change)) {
        changes.push(summary.change);
      }
    });

    const attendanceSummary = calculateAttendanceSummary(
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

  function calculateCurrentLevelFromDetails(details) {
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

    const attendanceSummary = calculateAttendanceSummary(
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
      return { key: "limited", label: "Limited Data", score: null };
    }

    const average = values.reduce((sum, value) => sum + value, 0) / values.length;
    const rounded = Math.round(average);

    if (average >= 75) {
      return { key: "strong", label: "Strong", score: rounded };
    }
    if (average >= 50) {
      return { key: "moderate", label: "Moderate", score: rounded };
    }
    return { key: "low", label: "Low", score: rounded };
  }

  function renderTrendPointList(points) {
    if (!points.length) {
      return `<div class="ba-trend-points-empty">No graded items yet.</div>`;
    }

    return `<div class="ba-trend-points-list">${points
      .slice(-6)
      .map(
        (point) => `<div class="ba-trend-point-row">
          <div class="ba-trend-point-copy">
            <strong>${escapeHtml(point.name || "Activity")}</strong>
            <span>${escapeHtml(formatTrendDate(point.date))}</span>
          </div>
          <span class="ba-trend-point-value">${escapeHtml(formatTrendPercent(point.grade))}</span>
        </div>`,
      )
      .join("")}</div>`;
  }

  function renderAttendanceStatusStrip(points, limit = 10) {
    const recent = points.slice(-limit);
    if (!recent.length) {
      return "";
    }

    return `<div class="ba-trend-attendance-strip">${recent
      .map((point) => {
        const present = Number(point.present) === 1;
        const label = String(point.status || (present ? "P" : "A")).toUpperCase();
        return `<span class="ba-trend-attendance-dot ${present ? "is-present" : "is-absent"}" title="${escapeHtml(
          `${label} - ${formatTrendDate(point.date)}`,
        )}">${escapeHtml(label)}</span>`;
      })
      .join("")}</div>`;
  }

  function renderTrendComponentCard(title, icon, points, trendWindow) {
    const recentPoints = Array.isArray(points) ? points.slice(-trendWindow) : [];
    if (!recentPoints.length) {
      return "";
    }

    const summary = calculateTrendComponent(recentPoints);
    if (!summary) {
      return "";
    }

    const positive = summary.change >= 0;
    return `<div class="ba-trend-card">
      <div class="ba-trend-card-title">${icon} ${escapeHtml(title)}</div>
      <div class="ba-trend-stat-row">
        <span>Start</span>
        <strong>${escapeHtml(formatTrendPercent(summary.start))}</strong>
      </div>
      <div class="ba-trend-stat-row">
        <span>Latest</span>
        <strong>${escapeHtml(formatTrendPercent(summary.latest))}</strong>
      </div>
      <div class="ba-trend-change ${positive ? "is-up" : "is-down"}">
        ${(positive ? "+" : "") + escapeHtml(String(summary.change))}%
      </div>
      ${renderTrendPointList(recentPoints)}
    </div>`;
  }

  function renderTrendAttendanceCard(details) {
    const attendancePoints = Array.isArray(details.attendance) ? details.attendance : [];
    if (!attendancePoints.length) {
      return "";
    }

    const summary = calculateAttendanceSummary(attendancePoints, details.attendance_window);
    let deltaText = "No previous attendance window";
    if (summary.previousWindowSize > 0) {
      deltaText =
        summary.comparisonMode === "adaptive-half"
          ? `${summary.delta > 0 ? "+" : ""}${summary.delta} vs earlier ${summary.previousWindowSize}`
          : `${summary.delta > 0 ? "+" : ""}${summary.delta} vs previous ${summary.previousWindowSize}`;
    }

    return `<div class="ba-trend-card">
      <div class="ba-trend-card-title">Attendance</div>
      ${renderAttendanceStatusStrip(attendancePoints)}
      <div class="ba-trend-stat-row">
        <span>Last ${summary.windowSize}</span>
        <strong>${summary.currentCount} attended</strong>
      </div>
      <div class="ba-trend-stat-row">
        <span>Recent rate</span>
        <strong>${summary.currentRate}%</strong>
      </div>
      ${summary.previousWindowSize > 0
        ? `<div class="ba-trend-stat-row"><span>${deltaText}</span><strong>${summary.previousRate}%</strong></div>`
        : `<div class="ba-trend-stat-row"><span>${deltaText}</span><strong>-</strong></div>`}
    </div>`;
  }

  function getTicketFeedbackSuggestions(student, category) {
    const duration = Math.max(1, Number(state.data?.feedback_duration_days) || 7);
    const insights = student.feedback_insights || {};
    if (category === "attendance") {
      const attendance = insights.attendance || {};
      const sessions = Number(attendance.sessions) || 0;
      const present = Number(attendance.present) || 0;
      const absent = Number(attendance.absent) || 0;
      if (!sessions || !present) return [`No sessions were attended in the past ${duration} days.`];
      if (present === sessions) return ["Maintaining perfect attendance."];
      return [`Out of ${sessions} sessions, ${present} were attended and ${absent} were not attended.`];
    }

    const labels = { assignments: "assignments", tests: "tests", projects: "projects" };
    const details = insights[category] || {};
    const label = labels[category] || category;
    const scheduled = Number(details.scheduled) || 0;
    const completed = Number(details.completed) || 0;
    const incomplete = Number(details.incomplete) || Math.max(0, scheduled - completed);
    if (!scheduled) return ["No activity completed yet."];
    if (!completed) return [`Out of ${scheduled} ${label}, no ${label} are completed.`];

    let completionSuggestion = "";
    if (completed === scheduled) {
      const perfectMessages = {
        assignments: "Assignments are being maintained perfectly.",
        tests: "Tests are being maintained perfectly.",
        projects: "Projects are being maintained perfectly.",
      };
      completionSuggestion = perfectMessages[category] || "Activities are being maintained perfectly.";
    } else {
      completionSuggestion = `Out of ${scheduled} ${label}, ${completed} were completed and ${incomplete} were not completed.`;
    }
    if (category !== "tests") return [completionSuggestion];

    const averageGrade = Number(details.average_grade);
    const graded = Number(details.graded) || 0;
    if (!graded || !Number.isFinite(averageGrade)) return [completionSuggestion];
    const performance = averageGrade <= 40
      ? "Needs improvement."
      : averageGrade <= 70 ? "Good performance." : "Excellent performance.";
    const grade = Number.isInteger(averageGrade) ? averageGrade : averageGrade.toFixed(1);
    const testLabel = completed === 1 ? "test is" : "tests are";
    return [completionSuggestion, `${completed} ${testLabel} completed with ${grade} grade: ${performance}`];
  }

  function appendTicketSuggestion(input, suggestion) {
    const existingText = String(input?.value || "");
    const cleanSuggestion = String(suggestion || "").trim();
    const normalise = (value) => String(value).trim().replace(/^[-*]\s*/, "").replace(/\s+/g, " ").toLowerCase();
    if (!cleanSuggestion || existingText.split(/\r?\n/).some((line) => normalise(line) === normalise(cleanSuggestion))) {
      return;
    }
    const text = existingText.trimEnd();
    input.value = text ? `${text}\n- ${cleanSuggestion}` : `- ${cleanSuggestion}`;
  }

  function renderTicketModal() {
    if (!state.ticketModal) {
      return "";
    }

    const student = state.ticketModal.student;
    const isViewMode = state.ticketModal.mode === "view";
    const ticketMeta = getTicketMeta();
    const ssTeamMissing = !ticketMeta.ss_team_role?.id || !(ticketMeta.ss_team_users || []).length;
    const saveDisabled = ssTeamMissing;
    const ticketTemplates = getTicketTemplates();
    const ticketTemplateOptions = ticketTemplates.map((template, index) =>
      `<option value="${index}">${escapeHtml(template.title || `Template ${index + 1}`)}</option>`,
    ).join("");

    return `<div class="ba-modal-overlay ba-maac-ticket-modal" id="ba-maac-ticket-modal">
      <div class="ba-modal-container ba-maac-ticket-dialog">
        <div class="ba-modal-header">
          <h3>${isViewMode ? "View Ticket" : "Raise Ticket"}</h3>
          <button type="button" class="ba-modal-close" id="ba-maac-ticket-close" aria-label="Close">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </div>
        <div class="ba-modal-body ba-maac-ticket-body">
          <div class="ba-maac-ticket-sections">
            <div class="ba-maac-ticket-panel">
              <div class="ba-maac-ticket-panel-title">Student Details</div>
              <div class="ba-maac-ticket-profile">
                <div class="ba-maac-ticket-avatar" aria-hidden="true">
                  <span>${escapeHtml((student.fullname || student.username || "?").trim().charAt(0).toUpperCase() || "?")}</span>
                </div>
                <div class="ba-maac-ticket-profile-details">
                  ${renderTicketInfoRows([
                    { label: "Admission ID", value: student.username || "-" },
                    { label: "Student Name", value: student.fullname || "-" },
                    { label: "Email", value: student.email || "-" },
                    { label: "Course", value: state.data?.course?.fullname || "-" },
                  ])}
                </div>
              </div>
            </div>

            ${isViewMode
              ? `<div class="ba-maac-ticket-panel">
                  <div class="ba-maac-ticket-panel-title">Ticket Details & Timeline</div>
                  <div class="ba-maac-ticket-history-wrap">
                    ${renderTicketHistory(student.tickets || [])}
                  </div>
                </div>`
              : `<div class="ba-maac-ticket-panel">
                  <div class="ba-maac-ticket-panel-title">Ticket Details</div>
                  <div class="ba-maac-ticket-grid">
                    <div class="ba-maac-ticket-field">
                      <label for="ba-maac-ticket-title">Ticket Title <span class="ba-maac-ticket-required">*</span></label>
                      <input type="text" id="ba-maac-ticket-title" class="ba-range-input" value="${escapeHtml(state.ticketModal.tickettitle || "")}" placeholder="Ticket Title">
                    </div>
                    <div class="ba-maac-ticket-field ba-maac-ticket-template-field">
                      <label for="ba-maac-ticket-template">Ticket Template</label>
                      <select id="ba-maac-ticket-template" class="ba-range-input"${ticketTemplates.length ? "" : " disabled"}>
                        <option value="">${ticketTemplates.length ? "Select template" : "No templates configured"}</option>
                        ${ticketTemplateOptions}
                      </select>
                    </div>
                    <div class="ba-maac-ticket-field ba-maac-ticket-field-full">
                      <label for="ba-maac-ticket-reason">Reason for Raising Ticket <span class="ba-maac-ticket-required">*</span></label>
                      <textarea id="ba-maac-ticket-reason" class="ba-maac-ticket-textarea" rows="5" placeholder="Describe the issue or reason for raising this support ticket...">${escapeHtml(state.ticketModal.ticketreason || "")}</textarea>
                      <div class="ba-feedback-suggestions ba-maac-ticket-suggestions">
                        <span class="ba-feedback-suggestions-label">Suggestions</span>
                        <div class="ba-feedback-suggestion-categories" role="group" aria-label="Ticket reason suggestions">
                          <button type="button" class="ba-feedback-suggestion-category" data-ticket-suggestion-category="attendance">Attendance</button>
                          <button type="button" class="ba-feedback-suggestion-category" data-ticket-suggestion-category="assignments">Assignments</button>
                          <button type="button" class="ba-feedback-suggestion-category" data-ticket-suggestion-category="tests">Tests</button>
                          <button type="button" class="ba-feedback-suggestion-category" data-ticket-suggestion-category="projects">Projects</button>
                        </div>
                        <div class="ba-feedback-suggestion-options" id="ba-maac-ticket-suggestion-options" hidden></div>
                      </div>
                    </div>
                  </div>
                  ${saveDisabled ? `<div class="ba-maac-ticket-warning">Ticket roles are not fully configured for this course.</div>` : ""}
                </div>`}
          </div>
        </div>
        <div class="ba-maac-ticket-footer">
          <button type="button" class="ba-btn" id="ba-maac-ticket-cancel">${isViewMode ? "Close" : "Cancel"}</button>
          ${isViewMode ? "" : `<button type="button" class="ba-btn ba-maac-ticket-submit" id="ba-maac-ticket-submit"${saveDisabled ? " disabled" : ""}>Raise Ticket</button>`}
        </div>
      </div>
    </div>`;
  }
  function renderTrendModal() {
    if (!state.trendModal) {
      return "";
    }

    const student = state.trendModal.student;
    const details = getStudentTrendDetails(student);
    const trendWindow = details.trend_window;
    const visual = getTrendVisual(calculateOverallTrendFromDetails(details)) || getTrendVisual("stable");
    const currentLevel = calculateCurrentLevelFromDetails(details);
    const cards = [
      renderTrendComponentCard("Assignments", "Assignments", details.assignments, trendWindow),
      renderTrendComponentCard("Quizzes", "Quizzes", details.quizzes, trendWindow),
      renderTrendComponentCard("Projects", "Projects", details.projects, trendWindow),
      renderTrendAttendanceCard(details),
    ]
      .filter(Boolean)
      .join("");

    return `<div class="ba-modal-overlay ba-trend-modal" id="ba-trend-modal">
      <div class="ba-modal-container ba-trend-modal-dialog">
        <div class="ba-modal-header">
          <h3>Trend Details: ${escapeHtml(student.fullname || student.username || "Student")}</h3>
          <button type="button" class="ba-modal-close" id="ba-trend-close" aria-label="Close">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </div>
        <div class="ba-modal-body ba-trend-modal-body">
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
        </div>
      </div>
    </div>`;
  }

  function buildTrendCardsFixed(details, trendWindow) {
    const renderComponentCard = (title, icon, points) => {
      const recentPoints = Array.isArray(points) ? points.slice(-trendWindow) : [];
      if (!recentPoints.length) {
        return "";
      }

      const summary = calculateTrendComponent(recentPoints);
      if (!summary) {
        return "";
      }

      const deltaBg = summary.change >= 0 ? "#f0fdf4" : "#fef2f2";
      const deltaColor = summary.change >= 0 ? "#10b981" : "#ef4444";
      return `<div class="trend-card">
        <div class="trend-title">${icon} ${escapeHtml(title)}</div>
        <div class="trend-chart">
          ${renderTrendMiniChart(recentPoints)}
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
      const summary = calculateAttendanceSummary(attendancePoints, attendanceWindow);
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
            return `<span style="display: inline-flex; align-items: center; justify-content: center; min-width: 28px; padding: 4px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; color: #fff; background: ${color};">${escapeHtml(status)}</span>`;
          })
          .join("")}</div>`;

        attendanceCard = `<div class="trend-card">
          <div class="trend-title">Attendance</div>
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
      renderComponentCard("Assignments", "Assignments", details.assignments),
      renderComponentCard("Quizzes", "Quizzes", details.quizzes),
      renderComponentCard("Projects", "Projects", details.projects),
      attendanceCard,
    ]
      .filter(Boolean)
      .join("");
  }
  function renderTrendModalFixed() {
    if (!state.trendModal) {
      return "";
    }

    const student = state.trendModal.student;
    const details = getStudentTrendDetails(student);
    const trendWindow = details.trend_window;
    const course = state.data?.course || {};
    const visual = getTrendVisualFixed(calculateOverallTrendFromDetails(details)) || getTrendVisualFixed("stable");
    const currentLevel = calculateCurrentLevelFromDetails(details);
    const cards = buildTrendCardsFixed(details, trendWindow);

    const trendColor = visual.key === "up" ? "#10b981" : visual.key === "down" ? "#ef4444" : "#f59e0b";
    return `<div class="trends-modal" id="ba-trend-modal">
      <div class="trends-content">
        <div class="trends-header">
          <h3>${escapeHtml(course.shortname || course.fullname || "Course")} - Grade Trends</h3>
          <button type="button" class="close-trends" id="ba-trend-close" aria-label="Close">x</button>
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

  function renderUnsavedChangesModal() {
    if (!state.unsavedChangesModal) {
      return "";
    }

    return `
      <div class="ba-modal-overlay ba-maac-unsaved-modal" id="ba-maac-unsaved-modal" role="dialog" aria-modal="true" aria-labelledby="ba-maac-unsaved-title">
        <div class="ba-modal-container ba-maac-unsaved-dialog">
          <div class="ba-modal-header">
            <h3 id="ba-maac-unsaved-title">You have unsaved changes</h3>
            <button type="button" class="ba-modal-close" id="ba-maac-unsaved-close" aria-label="Close">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
          </div>
          <div class="ba-modal-body">
            <p class="ba-maac-unsaved-text">You have made changes that have not been saved. Do you want to discard the changes or save the draft?</p>
          </div>
          <div class="ba-modal-footer ba-maac-unsaved-actions">
            <button type="button" class="ba-btn" id="ba-maac-unsaved-discard">Confirm Cancel</button>
            <button type="button" class="ba-btn ba-btn-success" id="ba-maac-unsaved-save">Save Draft</button>
          </div>
        </div>
      </div>`;
  }
  function renderPage() {
    const course = state.data.course || {};
    const courseUrl = wrap.dataset.courseurl || "";
    const trackerUrl = wrap.dataset.trackerurl || "";

    let totalMaac = 0;
    let maacCount = 0;
    let totalPerf = 0;
    let perfCount = 0;
    let trendCounts = { stable: 0, declining: 0, improving: 0 };
    let ticketCounts = { open: 0, pending: 0, closed: 0 };

    (state.data.students || []).forEach((student) => {
      const maac = parseFloat(student.maac_rating);
      if (!isNaN(maac)) {
        totalMaac += maac;
        maacCount++;
      }

      const perf = getOverallPerformanceValue(student);
      if (!isNaN(perf)) {
        totalPerf += perf;
        perfCount++;
      }

      const details = getStudentTrendDetails(student);
      const trend = calculateOverallTrendFromDetails(details);
      if (trend === "up" || trend === "improving") {
        trendCounts.improving++;
      } else if (trend === "down" || trend === "declining") {
        trendCounts.declining++;
      } else {
        trendCounts.stable++;
      }

      (student.tickets || []).forEach((ticket) => {
        const status = String(ticket.statuskey || ticket.status || "open")
          .trim()
          .toLowerCase()
          .replace(/\s+/g, "_");
        if (status === "resolved" || status === "closed") {
          ticketCounts.closed++;
        } else if (["ss_in_progress", "pm_in_progress", "in_progress", "pending"].includes(status)) {
          ticketCounts.pending++;
        } else {
          ticketCounts.open++;
        }
      });
    });

    const avgMaac = maacCount > 0 ? (totalMaac / maacCount).toFixed(1) : "-";
    const avgPerf = perfCount > 0 ? Math.round(totalPerf / perfCount) : "-";
    const trendStudentCount = trendCounts.stable + trendCounts.declining + trendCounts.improving;
    const trendPercentage = (count) => trendStudentCount > 0 ? Math.round((count / trendStudentCount) * 100) : 0;
    const spotAwardStudents = (state.data.students || []).filter((student) => {
      const value = student.custom?.spot_awards_nomination;
      const list = normalizeDisplayList(value);
      return list ? list.length > 0 : String(value || "").trim() !== "";
    }).length;
    const feedbackSummary = getFeedbackSummary();
    const maacActionButtons = renderMaacActionButtons();

    app.innerHTML = `
      <div class="ba-course-header-card ba-maac-header">
        <div class="ba-ch-content">
          <div class="ba-ch-left">
            <h2 class="ba-ch-title">MAAC Details: ${escapeHtml(course.fullname || "")}</h2>
            <div class="ba-ch-meta" style="display: flex; gap: 10px; flex-wrap: wrap;">
              <span class="ba-ch-badge avg">${state.data.students.length} Students</span>
              <span class="ba-ch-badge avg">Avg MAAC Rating: ${avgMaac}</span>
              <span id="ba-maac-avg-performance" class="ba-ch-badge avg">Avg Performance: ${avgPerf}%</span>
              <span class="ba-ch-badge avg">Spot Awards Students: ${spotAwardStudents}</span>
              <button type="button" class="ba-ch-badge avg" id="ba-maac-feedback-summary-open" style="border:0; cursor:pointer;">
                ${feedbackSummary.summaries.length
                  ? feedbackSummary.summaries.map((column) => `${escapeHtml(column.label)}: ${column.highest}`).join(" | ")
                  : "No feedback columns"}
              </button>
              <span class="ba-ch-badge avg ba-maac-ticket-pill">
                <span>Tickets :</span>
                <span class="ba-maac-ticket-pill-open">Open : ${ticketCounts.open}</span>
                <span class="ba-maac-ticket-pill-separator">|</span>
                <span class="ba-maac-ticket-pill-pending">Pending : ${ticketCounts.pending}</span>
                <span class="ba-maac-ticket-pill-separator">|</span>
                <span class="ba-maac-ticket-pill-closed">Closed : ${ticketCounts.closed}</span>
              </span>
              <span class="ba-ch-badge avg">Trends: Improving ${trendPercentage(trendCounts.improving)}% | Declining ${trendPercentage(trendCounts.declining)}% | Stable ${trendPercentage(trendCounts.stable)}%</span>
            </div>
          </div>
          <div class="ba-ch-right">
            ${courseUrl ? `<a class="ba-btn ba-btn-view" href="${escapeHtml(courseUrl)}" target="_blank" rel="noopener">View Course <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg></a>` : ""}
            ${trackerUrl ? `<a class="ba-btn ba-btn-sm ba-btn-view" href="${escapeHtml(trackerUrl)}">Module Tracker</a>` : ""}
          </div>
        </div>
      </div>
      <div class="ba-filter-section ba-maac-section">
        <div class="ba-filter-header ba-maac-section-toolbar">
          <button type="button" id="ba-maac-toggle-filters" class="ba-btn ba-maac-filter-toggle-btn">${state.filtersHidden ? "Show Filters" : "Hide Filters"}</button>
          <div class="ba-maac-table-actions">${maacActionButtons}</div>
        </div>
        <div class="ba-filter-body">
          ${renderFilterBar()}
          <div class="ba-filter-main ba-maac-main">
            ${renderTableControls()}
            <div id="ba-maac-table-section">${renderTable()}</div>
          </div>
        </div>
      </div>${renderTrendModalFixed()}${renderFeedbackSummaryModal()}${renderTicketModal()}
    `;

    bindEvents();
    applyFilters();
  }

  function findStudentById(userid) {
    return (state.data?.students || []).find((item) => Number(item.userid) === Number(userid));
  }

  function upsertStudentTicket(studentUserid, ticket, ticketCount = null) {
    const student = findStudentById(studentUserid);
    if (!student || !ticket) {
      return;
    }

    const tickets = Array.isArray(student.tickets) ? student.tickets.slice() : [];
    const index = tickets.findIndex((item) => Number(item.id) === Number(ticket.id));
    if (index >= 0) {
      tickets[index] = { ...tickets[index], ...ticket };
    } else {
      tickets.unshift(ticket);
    }
    student.tickets = tickets;
    student.ticket_count = ticketCount !== null ? Number(ticketCount) : tickets.length;
  }

  async function postTicketAction(action, payload) {
    const body = new URLSearchParams();
    body.set("courseid", String(courseId));
    body.set("action", action);
    body.set("sesskey", sesskey);
    body.set("payload", JSON.stringify(payload));

    const response = await fetch(baseUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: body.toString(),
    });
    const text = await response.text();
    const json = parseTicketActionResponse(text);
    if (!response.ok || json.error) {
      throw new Error(json.error || "Unable to process ticket request.");
    }
    return json;
  }

  function parseTicketActionResponse(text) {
    if (!text) {
      return {};
    }

    try {
      return JSON.parse(text);
    } catch (_directError) {
      // Moodle development mode can prepend debug HTML/text before JSON.
      // Prefer the known API object starts instead of a greedy brace match.
      const starts = ['{"status"', '{"error"'];
      const start = starts
        .map((needle) => text.lastIndexOf(needle))
        .filter((index) => index >= 0)
        .sort((a, b) => b - a)[0];

      if (start !== undefined) {
        try {
          return JSON.parse(text.slice(start).trim());
        } catch (_knownStartError) {
          const end = text.lastIndexOf("}");
          if (end > start) {
            try {
              return JSON.parse(text.slice(start, end + 1));
            } catch (_knownBoundedError) {
              // Fall through to the bounded brace fallback below.
            }
          }
        }
      }

      const firstBrace = text.indexOf("{");
      const lastBrace = text.lastIndexOf("}");
      if (firstBrace >= 0 && lastBrace > firstBrace) {
        try {
          return JSON.parse(text.slice(firstBrace, lastBrace + 1));
        } catch (_braceError) {
          // Use the generic error below.
        }
      }
    }

    return { error: "Unable to process ticket request. Please try again." };
  }

  async function openTicketModal(studentUserid, mode = "raise") {
    const student = (state.data?.students || []).find((item) => Number(item.userid) === Number(studentUserid));
    if (!student) {
      showMessage("Unable to load student details", "error");
      return;
    }

    if (mode === "raise" && !state.data?.canraise) {
      showMessage("You do not have permission to raise tickets for this course", "error");
      return;
    }

    const ticketMeta = getTicketMeta();
    state.ticketModal = {
      mode,
      student,
      ssteamuserid: ticketMeta.ss_team_users?.[0]?.id || "",
      tickettitle: "",
      ticketreason: "",
    };
    renderPagePreservingScroll();
  }

  function closeTicketModal() {
    state.ticketModal = null;
    state.ticketEdit = null;
    state.ticketSaving = false;
    renderPagePreservingScroll();
  }

  function openTrendModal(studentUserid) {
    const student = (state.data?.students || []).find((item) => Number(item.userid) === Number(studentUserid));
    if (!student) {
      showMessage("Unable to load trend details", "error");
      return;
    }

    state.trendModal = { student };
    renderPagePreservingScroll();
  }

  function closeTrendModal() {
    state.trendModal = null;
    renderPagePreservingScroll();
  }

  function openFeedbackSummaryModal() {
    state.feedbackSummaryModal = true;
    renderPagePreservingScroll();
  }

  function closeFeedbackSummaryModal() {
    state.feedbackSummaryModal = false;
    renderPagePreservingScroll();
  }

  function markMaacDraftChanged() {
    refreshMaacDraftChanged();
  }

  function discardMaacDraftChanges() {
    state.editMode = false;
    state.hasUnsavedChanges = false;
    closeUnsavedChangesModal();
    state.customDraft = buildDraft(state.data?.students || []);
    state.originalCustomDraft = cloneMaacDraft(state.customDraft);
    renderPagePreservingScroll();
  }

  function openUnsavedChangesModal() {
    state.unsavedChangesModal = true;
    if (!document.getElementById("ba-maac-unsaved-modal")) {
      document.body.insertAdjacentHTML("beforeend", renderUnsavedChangesModal());
      const modal = document.getElementById("ba-maac-unsaved-modal");
      const closeButton = document.getElementById("ba-maac-unsaved-close");

      closeButton?.addEventListener("click", closeUnsavedChangesModal);
      modal?.addEventListener("click", (event) => {
        if (event.target === modal) {
          closeUnsavedChangesModal();
          return;
        }

        const button = event.target.closest("#ba-maac-unsaved-discard, #ba-maac-unsaved-save");
        if (!button || button.disabled) {
          return;
        }

        event.preventDefault();
        if (button.id === "ba-maac-unsaved-discard") {
          discardMaacDraftChanges();
          return;
        }

        saveData({ source: "unsaved-modal" }).catch((error) => {
          showMessage(error.message || "Unable to save MAAC values", "error");
        });
      });
      document.addEventListener("keydown", handleUnsavedChangesModalKeydown);
    }
  }

  function handleUnsavedChangesModalKeydown(event) {
    if (event.key === "Escape") {
      event.preventDefault();
      closeUnsavedChangesModal();
    }
  }

  function closeUnsavedChangesModal() {
    state.unsavedChangesModal = false;
    document.getElementById("ba-maac-unsaved-modal")?.remove();
    document.removeEventListener("keydown", handleUnsavedChangesModalKeydown);
  }

  function bindEvents() {
    const search = document.getElementById("ba-maac-search");
    const courseGroup = document.getElementById("ba-maac-course-group");
    const metricToggle = document.getElementById("ba-maac-metric-toggle");
    const perfMin = document.getElementById("ba-maac-performance-min");
    const perfMax = document.getElementById("ba-maac-performance-max");
    const maacMin = document.getElementById("ba-maac-rating-min");
    const maacMax = document.getElementById("ba-maac-rating-max");

    [search, courseGroup, perfMin, perfMax, maacMin, maacMax, metricToggle].forEach((el) => {
      if (el) {
        const eventName =
          el.tagName === "SELECT" || el.type === "checkbox" ? "change" : "input";
        el.addEventListener(eventName, applyFilters);
      }
    });

    const resetBtn = document.getElementById("ba-maac-reset");
    if (resetBtn) {
      resetBtn.addEventListener("click", resetFilters);
    }

    const toggleFiltersBtn = document.getElementById("ba-maac-toggle-filters");
    if (toggleFiltersBtn) {
      toggleFiltersBtn.addEventListener("click", () => {
        state.filtersHidden = !state.filtersHidden;
        renderPage();
      });
    }

    document.querySelectorAll("[data-filter-key]").forEach((el) => {
      const eventName =
        el.tagName === "SELECT" || el.type === "checkbox" ? "change" : "input";
      el.addEventListener(eventName, applyFilters);
    });

    const backBtn = document.getElementById("ba-maac-back");
    if (backBtn) {
      backBtn.addEventListener("click", () => {
        if (window.history.length > 1) {
          window.history.back();
        } else {
          window.location.href = baseUrl.replace(/maac\.php$/, "index.php");
        }
      });
    }

    const ticketModal = document.getElementById("ba-maac-ticket-modal");
    if (ticketModal) {
      ticketModal.addEventListener("click", (event) => {
        if (event.target === ticketModal) {
          closeTicketModal();
          return;
        }

        const toggleBtn = event.target.closest(".ba-maac-ticket-timeline-toggle");
        if (toggleBtn) {
          const timelineBlock = toggleBtn.closest(".ba-maac-ticket-timeline");
          const timelineList = timelineBlock?.querySelector(".ba-maac-ticket-timeline-list");
          if (!timelineBlock || !timelineList) {
            return;
          }

          const isHidden = timelineList.classList.toggle("is-hidden");
          toggleBtn.textContent = isHidden ? "Show Timeline" : "Hide Timeline";
          toggleBtn.setAttribute("aria-expanded", isHidden ? "false" : "true");
        }
      });
    }

    const ticketCloseBtn = document.getElementById("ba-maac-ticket-close");
    if (ticketCloseBtn) {
      ticketCloseBtn.addEventListener("click", closeTicketModal);
    }

    const ticketCancelBtn = document.getElementById("ba-maac-ticket-cancel");
    if (ticketCancelBtn) {
      ticketCancelBtn.addEventListener("click", closeTicketModal);
    }

    const ticketTemplateSelect = document.getElementById("ba-maac-ticket-template");
    if (ticketTemplateSelect && ticketTemplateSelect.dataset.bound !== "1") {
      ticketTemplateSelect.dataset.bound = "1";
      ticketTemplateSelect.addEventListener("change", () => {
        const index = parseInt(ticketTemplateSelect.value || "-1", 10);
        const template = getTicketTemplates()[index];
        if (!template) {
          return;
        }
        const titleEl = document.getElementById("ba-maac-ticket-title");
        const reasonEl = document.getElementById("ba-maac-ticket-reason");
        if (titleEl) {
          titleEl.value = template.title || "";
        }
        if (reasonEl) {
          reasonEl.value = template.body || "";
        }
        state.ticketModal = {
          ...state.ticketModal,
          tickettitle: template.title || "",
          ticketreason: template.body || "",
        };
      });
    }

    const ticketSuggestionOptions = document.getElementById("ba-maac-ticket-suggestion-options");
    if (ticketModal && ticketSuggestionOptions && state.ticketModal?.mode === "raise") {
      let activeTicketSuggestions = [];
      ticketModal.querySelectorAll("[data-ticket-suggestion-category]").forEach((button) => {
        button.addEventListener("click", () => {
          activeTicketSuggestions = getTicketFeedbackSuggestions(
            state.ticketModal.student,
            button.dataset.ticketSuggestionCategory,
          );
          ticketSuggestionOptions.innerHTML = activeTicketSuggestions.map((suggestion, index) =>
            `<button type="button" class="ba-feedback-suggestion-option" data-ticket-suggestion-index="${index}">${escapeHtml(suggestion)}</button>`,
          ).join("");
          ticketSuggestionOptions.hidden = activeTicketSuggestions.length === 0;
          ticketModal.querySelectorAll("[data-ticket-suggestion-category]").forEach((item) => {
            item.classList.toggle("is-active", item === button);
          });
        });
      });
      ticketSuggestionOptions.addEventListener("click", (event) => {
        const option = event.target.closest("[data-ticket-suggestion-index]");
        const index = Number(option?.dataset.ticketSuggestionIndex);
        const reasonInput = document.getElementById("ba-maac-ticket-reason");
        if (option && activeTicketSuggestions[index] && reasonInput) {
          appendTicketSuggestion(reasonInput, activeTicketSuggestions[index]);
          reasonInput.focus();
        }
      });
    }

    // Use event delegation on the app container for the ticket submit button.
    // Delegated handlers survive DOM re-renders (unlike direct addEventListener
    // on the button element which is destroyed when app.innerHTML is rebuilt).
    if (!app._ticketSubmitBound) {
      app._ticketSubmitBound = true;
      app.addEventListener("click", (event) => {
        const btn = event.target.closest("#ba-maac-ticket-submit");
        if (btn && !btn.disabled) {
          saveTicket().catch((err) => showMessage(err.message || "Unable to raise ticket", "error"));
        }
      });
    }

    // The MAAC action buttons are recreated whenever the table is rendered.
    // Delegate their actions to the stable app container so Cancel always works.
    if (!app._maacActionsBound) {
      app._maacActionsBound = true;
      app.addEventListener("click", (event) => {
        const button = event.target.closest(
          "#ba-maac-edit, #ba-maac-cancel, #ba-maac-save, #ba-maac-unsaved-discard, #ba-maac-unsaved-save",
        );
        if (!button || !app.contains(button) || button.disabled) {
          return;
        }

        event.preventDefault();
        if (button.id === "ba-maac-edit") {
          state.editMode = true;
          state.hasUnsavedChanges = false;
          state.unsavedChangesModal = false;
          state.customDraft = buildDraft(state.data.students || []);
          renderPagePreservingScroll();
          // The initial edit render adds empty values for unfilled columns.
          // Capture that rendered state so Cancel only detects real user edits.
          state.originalCustomDraft = cloneMaacDraft(state.customDraft);
          return;
        }

        if (button.id === "ba-maac-cancel") {
          syncDraftFromInputs();
          refreshMaacDraftChanged();
          if (state.hasUnsavedChanges) {
            openUnsavedChangesModal();
          } else {
            discardMaacDraftChanges();
          }
          return;
        }

        if (button.id === "ba-maac-unsaved-discard") {
          discardMaacDraftChanges();
          return;
        }

        if (button.id === "ba-maac-save" || button.id === "ba-maac-unsaved-save") {
          saveData({ source: button.id === "ba-maac-unsaved-save" ? "unsaved-modal" : "" }).catch((error) => {
            showMessage(error.message || "Unable to save MAAC values", "error");
          });
        }
      });
    }

    const trendModal = document.getElementById("ba-trend-modal");
    if (trendModal) {
      trendModal.addEventListener("click", (event) => {
        if (event.target === trendModal) {
          closeTrendModal();
        }
      });
    }

    const trendCloseBtn = document.getElementById("ba-trend-close");
    if (trendCloseBtn) {
      trendCloseBtn.addEventListener("click", closeTrendModal);
    }

    const feedbackSummaryOpenBtn = document.getElementById("ba-maac-feedback-summary-open");
    if (feedbackSummaryOpenBtn) {
      feedbackSummaryOpenBtn.addEventListener("click", openFeedbackSummaryModal);
    }

    const feedbackSummaryModal = document.getElementById("ba-maac-feedback-summary-modal");
    if (feedbackSummaryModal) {
      feedbackSummaryModal.addEventListener("click", (event) => {
        if (event.target === feedbackSummaryModal) {
          closeFeedbackSummaryModal();
        }
      });
    }

    const feedbackSummaryCloseBtn = document.getElementById("ba-maac-feedback-summary-close");
    if (feedbackSummaryCloseBtn) {
      feedbackSummaryCloseBtn.addEventListener("click", closeFeedbackSummaryModal);
    }

    bindTableEvents();
  }

  function bindTableEvents() {
    const tableWrap = document.querySelector("#ba-maac-table-section .ba-table-wrap");
    if (tableWrap) {
      tableWrap.addEventListener("scroll", () => {
        state.tableScroll.left = tableWrap.scrollLeft;
        state.tableScroll.top = tableWrap.scrollTop;
      });
    }

    document.querySelectorAll(".ba-maac-input").forEach((input) => {
      if (input.dataset.multiEntry !== undefined) {
        return;
      }
      input.addEventListener("change", handleCustomInput);
      input.addEventListener("input", handleCustomInput);
    });

    bindMultiSelectEditors();

    document.querySelectorAll(".ba-maac-feedback-open-modal").forEach((button) => {
      button.addEventListener("click", () => {
        openFeedbackModal(button.dataset.userid, button.dataset.key);
      });
    });

    document.querySelectorAll(".ba-maac-group-toggle").forEach((button) => {
      button.addEventListener("click", () => {
        const key = button.dataset.groupKey;
        state.collapsedGroups[key] = !state.collapsedGroups[key];
        renderMaacTableSection();
      });
    });

    document.querySelectorAll("[data-ticket-action]").forEach((button) => {
      button.addEventListener("click", () => {
        openTicketModal(button.dataset.studentid, button.dataset.ticketAction || "raise").catch((error) => {
          showMessage(error.message || "Unable to open ticket", "error");
        });
      });
    });
    document.querySelectorAll("[data-maac-ticket-edit]").forEach((button) => {
      button.addEventListener("click", () => {
        const ticketid = Number(button.dataset.maacTicketEdit || 0);
        const ticket = (state.ticketModal?.student?.tickets || []).find((item) => Number(item.id) === ticketid);
        if (!ticket) {
          return;
        }
        state.ticketEdit = {
          ticketid,
          tickettitle: ticket.tickettitle || "",
          ticketreason: ticket.ticketreason || "",
        };
        renderPagePreservingScroll();
      });
    });

    document.querySelectorAll("[data-maac-ticket-edit-cancel]").forEach((button) => {
      button.addEventListener("click", () => {
        state.ticketEdit = null;
        renderPagePreservingScroll();
      });
    });

    document.querySelectorAll("[data-maac-ticket-edit-save]").forEach((button) => {
      button.addEventListener("click", () => {
        saveTicketEdit(Number(button.dataset.maacTicketEditSave || 0));
      });
    });

    document.querySelectorAll("[data-trend-userid]").forEach((button) => {
      button.addEventListener("click", () => {
        openTrendModal(button.dataset.trendUserid);
      });
    });

    document.querySelectorAll("[data-sort-key]").forEach((header) => {
      header.addEventListener("click", (event) => {
        if (event.target.closest("[data-column-resizer]")) {
          return;
        }

        const sortKey = header.dataset.sortKey || "";
        const sortType = header.dataset.sortType || "text";
        if (!sortKey) {
          return;
        }

        if (state.sort.key === sortKey) {
          state.sort.direction = state.sort.direction === "asc" ? "desc" : "asc";
        } else {
          state.sort.key = sortKey;
          state.sort.direction = "asc";
          state.sort.type = sortType;
        }

        renderMaacTableSection();
      });
    });

    bindTableColumnResizers(document.querySelector(".ba-maac-table"));
  }

  function handleCustomInput(event) {
    const input = event.target;
    const userid = parseInt(input.dataset.userid || "0", 10);
    const key = input.dataset.key || "";
    if (!userid || !key) return;

    if (!state.customDraft[userid]) {
      state.customDraft[userid] = {};
    }

    if (input.dataset.multiEntry !== undefined) {
      state.customDraft[userid][key] = getMultiSelectValues(userid, key);
      markMaacDraftChanged();
      return;
    }



    if (input.type === "checkbox") {
      state.customDraft[userid][key] = input.checked ? 1 : 0;
      markMaacDraftChanged();
      return;
    }

    if (input.multiple) {
      state.customDraft[userid][key] = getSelectedValues(input);
      markMaacDraftChanged();
      return;
    }

    state.customDraft[userid][key] = input.value;
    markMaacDraftChanged();
  }



  function syncDraftFromInputs() {
    if (!state.editMode) {
      return;
    }

    document.querySelectorAll(".ba-maac-input").forEach((input) => {
      const userid = parseInt(input.dataset.userid || "0", 10);
      const key = input.dataset.key || "";
      if (!userid || !key) {
        return;
      }

      if (!state.customDraft[userid]) {
        state.customDraft[userid] = {};
      }

      if (input.dataset.multiEntry !== undefined) {
        state.customDraft[userid][key] = getMultiSelectValues(userid, key);
        return;
      }



      if (input.type === "checkbox") {
        state.customDraft[userid][key] = input.checked ? 1 : 0;
        return;
      }

      if (input.multiple) {
        state.customDraft[userid][key] = getSelectedValues(input);
        return;
      }

      state.customDraft[userid][key] = input.value;
    });
  }

  function openFeedbackModal(userid, key) {
    const numericUserid = parseInt(userid || "0", 10);
    const student = (state.data.students || []).find((item) => Number(item.userid) === numericUserid);
    const column = getCustomColumnByKey(key);
    if (!student || !column) {
      return;
    }

    const calendarIcon = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
    const saveIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>';
    const isInternalFeedbackColumn = String(column.selection || "").toLowerCase() === "internal";
    const externalFeedbackColumn = isInternalFeedbackColumn ? findPairedExternalFeedbackColumn(column) : null;

    function findPairedExternalFeedbackColumn(sourceColumn) {
      const columns = getOrderedCustomColumns();
      const externalColumns = columns.filter((candidate) =>
        candidate &&
        candidate.key !== sourceColumn.key &&
        candidate.type === "multi_feedback" &&
        String(candidate.selection || "").toLowerCase() === "external",
      );
      if (!externalColumns.length) {
        return null;
      }

      const groups = state.data?.column_groups || [];
      const sourceGroup = groups.find((group) => (group.columns || []).some((item) => item.key === sourceColumn.key));
      if (sourceGroup) {
        const groupMatch = externalColumns.find((candidate) =>
          (sourceGroup.columns || []).some((item) => item.key === candidate.key),
        );
        if (groupMatch) {
          return groupMatch;
        }
      }

      return externalColumns[0];
    }

    function getFeedbacksForColumn(targetColumn) {
      let current = state.customDraft[userid]?.[targetColumn.key];
      if (current === undefined) {
        current = getStudentCustomValue(student, targetColumn.key);
      }
      return normaliseFeedbacks(current);
    }

    function setFeedbacksForColumn(targetColumn, nextFeedbacks) {
      const cleanFeedbacks = normaliseFeedbacks(nextFeedbacks);
      if (!state.customDraft[userid]) {
        state.customDraft[userid] = {};
      }
      state.customDraft[userid][targetColumn.key] = cleanFeedbacks;
      if (!student.custom) {
        student.custom = {};
      }
      student.custom[targetColumn.key] = cleanFeedbacks;
      return cleanFeedbacks;
    }
    function normaliseFeedbacks(source) {
      return normalizeFeedbackItems(source);
    }

    function getCurrentFeedbacks() {
      let current = state.customDraft[userid]?.[key];
      if (current === undefined) {
        current = getStudentCustomValue(student, column.key);
      }
      return normaliseFeedbacks(current);
    }

    function setCurrentFeedbacks(nextFeedbacks) {
      const cleanFeedbacks = normaliseFeedbacks(nextFeedbacks);
      if (!state.customDraft[userid]) {
        state.customDraft[userid] = {};
      }
      state.customDraft[userid][key] = cleanFeedbacks;
      if (!student.custom) {
        student.custom = {};
      }
      student.custom[key] = cleanFeedbacks;
      return cleanFeedbacks;
    }

    function getDateInputValue(value) {
      const raw = String(value || "").trim();
      return /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : new Date().toISOString().split("T")[0];
    }

    function getFeedbackSuggestions(category) {
      const duration = Math.max(1, Number(state.data?.feedback_duration_days) || 7);
      const insights = student.feedback_insights || {};
      if (category === "attendance") {
        const attendance = insights.attendance || {};
        const sessions = Number(attendance.sessions) || 0;
        const present = Number(attendance.present) || 0;
        const absent = Number(attendance.absent) || 0;
        if (!sessions || !present) {
          return [`No sessions were attended in the past ${duration} days.`];
        }
        if (present === sessions) {
          return ["Maintaining perfect attendance."];
        }
        return [`Out of ${sessions} sessions, ${present} were attended and ${absent} were not attended.`];
      }

      const labels = { assignments: "assignments", tests: "tests", projects: "projects" };
      const details = insights[category] || {};
      const label = labels[category] || category;
      const scheduled = Number(details.scheduled) || 0;
      const completed = Number(details.completed) || 0;
      const incomplete = Number(details.incomplete) || Math.max(0, scheduled - completed);
      if (!scheduled) {
        return ["No activity completed yet."];
      }
      if (!completed) {
        return [`Out of ${scheduled} ${label}, no ${label} are completed.`];
      }
      let completionSuggestion = "";
      if (completed === scheduled) {
        const perfectMessages = {
          assignments: "Assignments are being maintained perfectly.",
          tests: "Tests are being maintained perfectly.",
          projects: "Projects are being maintained perfectly.",
        };
        completionSuggestion = perfectMessages[category] || "Activities are being maintained perfectly.";
      } else {
        completionSuggestion = `Out of ${scheduled} ${label}, ${completed} were completed and ${incomplete} were not completed.`;
      }

      if (category !== "tests") {
        return [completionSuggestion];
      }

      const averageGrade = Number(details.average_grade);
      const graded = Number(details.graded) || 0;
      if (!graded || !Number.isFinite(averageGrade)) {
        return [completionSuggestion];
      }
      const performance = averageGrade <= 40
        ? "Needs improvement."
        : averageGrade <= 70
          ? "Good performance."
          : "Excellent performance.";
      const grade = Number.isInteger(averageGrade) ? averageGrade : averageGrade.toFixed(1);
      const testLabel = completed === 1 ? "test is" : "tests are";
      return [
        completionSuggestion,
        `${completed} ${testLabel} completed with ${grade} grade: ${performance}`,
      ];
    }

    function appendFeedbackSuggestion(input, suggestion) {
      const cleanSuggestion = String(suggestion || "").trim();
      const normaliseSuggestionLine = (value) => String(value)
        .trim()
        .replace(/^[-*]\s*/, "")
        .replace(/\s+/g, " ")
        .toLowerCase();
      const existingText = String(input?.value || "");
      const alreadyAdded = existingText.split(/\r?\n/)
        .some((line) => normaliseSuggestionLine(line) === normaliseSuggestionLine(cleanSuggestion));
      if (!cleanSuggestion || alreadyAdded || !input) {
        return;
      }
      const text = existingText.trimEnd();
      input.value = text ? `${text}\n- ${cleanSuggestion}` : `- ${cleanSuggestion}`;
    }

    function renderFeedbackCard(feedback, index) {
      const addedAt = Number(feedback.added_at || 0);
      const isEditable = addedAt > 0 && Date.now() - addedAt < 24 * 60 * 60 * 1000;
      const displayDate = formatFeedbackDate(feedback.date);
      const inputDate = getDateInputValue(feedback.date);

      return `
        <div class="ba-feedback-card" data-feedback-index="${index}">
          <div class="ba-feedback-card-left">
            <div class="ba-feedback-card-title">
              ${escapeHtml(column.label)} - ${index + 1}
            </div>
            <div class="ba-feedback-card-date">
              ${calendarIcon}
              <span class="ba-feedback-card-date-label">Date :</span>
              <span class="ba-feedback-card-date-value">${displayDate ? escapeHtml(displayDate) : "No date"}</span>
            </div>
          </div>
          <div class="ba-feedback-card-right">
            ${isEditable ? `<button type="button" class="ba-feedback-edit-btn" data-feedback-edit="1" data-index="${index}"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg> Edit</button>` : ""}
            <div class="ba-feedback-card-text${isEditable ? " is-editable" : ""}">${escapeHtml(feedback.text)}</div>
          </div>
          <div class="ba-feedback-inline-edit" hidden>
            <div class="ba-feedback-inline-meta">
              <div class="ba-feedback-form-title">${escapeHtml(column.label)} - ${index + 1}</div>
              <label class="ba-feedback-date-field">
                ${calendarIcon}
                <span class="ba-feedback-date-label">Date</span>
                <input type="date" class="ba-maac-input ba-feedback-inline-date" value="${escapeHtml(inputDate)}">
              </label>
            </div>
            <div class="ba-feedback-inline-main">
              <textarea class="ba-maac-input ba-feedback-textarea ba-feedback-inline-text" placeholder="Enter feedback here...">${escapeHtml(feedback.text)}</textarea>
              <div class="ba-feedback-suggestions">
                <span class="ba-feedback-suggestions-label">Suggestions</span>
                <div class="ba-feedback-suggestion-categories" role="group" aria-label="Feedback edit suggestions">
                  <button type="button" class="ba-feedback-suggestion-category" data-feedback-inline-suggestion-category="attendance">Attendance</button>
                  <button type="button" class="ba-feedback-suggestion-category" data-feedback-inline-suggestion-category="assignments">Assignments</button>
                  <button type="button" class="ba-feedback-suggestion-category" data-feedback-inline-suggestion-category="tests">Tests</button>
                  <button type="button" class="ba-feedback-suggestion-category" data-feedback-inline-suggestion-category="projects">Projects</button>
                </div>
                <div class="ba-feedback-suggestion-options" data-feedback-inline-suggestion-options hidden></div>
              </div>
              <div class="ba-feedback-form-actions">
                <button type="button" class="ba-btn ba-btn-sm ba-feedback-cancel-btn" data-feedback-inline-cancel="1">Cancel</button>
                <button type="button" class="ba-btn ba-btn-sm ba-btn-primary ba-feedback-save-btn" data-feedback-inline-save="1">${saveIcon} Update</button>
              </div>
            </div>
          </div>
        </div>
      `;
    }

    function renderFeedbackList(feedbacks) {
      return feedbacks.length
        ? feedbacks.map((feedback, index) => renderFeedbackCard(feedback, index)).join("")
        : '<div class="ba-feedback-empty">No feedback provided yet.</div>';
    }

    const feedbacks = getCurrentFeedbacks();

    const modalHtml = `
      <div class="ba-modal-overlay ba-feedback-modal" id="ba-feedback-modal-${userid}-${key}">
        <div class="ba-modal-container ba-maac-ticket-dialog ba-feedback-dialog">

          <div class="ba-modal-header">
            <h3>Feedback - ${escapeHtml(column.label)}</h3>
            <button type="button" class="ba-modal-close" aria-label="Close">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
          </div>

          <div class="ba-modal-body ba-maac-ticket-body ba-feedback-body">

            <div class="ba-maac-ticket-panel ba-feedback-student-panel">
              <div class="ba-maac-ticket-panel-title">Student Details</div>
              <div class="ba-maac-ticket-profile ba-feedback-student-profile">
                <div class="ba-maac-ticket-avatar" aria-hidden="true">
                  <span>${escapeHtml((student.fullname || student.username || "?").trim().charAt(0).toUpperCase() || "?")}</span>
                </div>
                <div class="ba-maac-ticket-profile-details">
                  ${renderTicketInfoRows([
                    { label: "Name", value: student.fullname || "-" },
                    { label: "Username", value: student.username || "-" },
                    { label: "Email", value: student.email || "-" }
                  ])}
                </div>
              </div>
            </div>

            <div class="ba-maac-ticket-panel ba-feedback-section">
              <div class="ba-maac-ticket-panel-title">${escapeHtml(column.label)}</div>

              <div class="ba-feedback-modal-status" role="status" aria-live="polite"></div>

              <div id="ba-feedback-modal-list-${userid}-${key}" class="ba-feedback-modal-list">
                ${renderFeedbackList(feedbacks)}
              </div>

              <div class="ba-feedback-add-section">
                <button type="button" class="ba-btn ba-btn-sm ba-btn-primary ba-feedback-add-btn" id="ba-feedback-modal-add-btn-${userid}-${key}">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Add Feedback
                </button>
                <div id="ba-feedback-modal-form-${userid}-${key}" class="ba-feedback-form">

                  <div class="ba-feedback-form-meta">
                    <div class="ba-feedback-form-title" id="ba-feedback-modal-form-title-${userid}-${key}">
                      ${escapeHtml(column.label)} - ${feedbacks.length + 1}
                    </div>
                    <label class="ba-feedback-date-field">
                      ${calendarIcon}
                      <span class="ba-feedback-date-label">Date</span>
                      <input type="date" id="ba-feedback-modal-date-${userid}-${key}" class="ba-maac-input ba-feedback-date-input">
                    </label>
                  </div>

                  <div class="ba-feedback-form-main">
                    <textarea id="ba-feedback-modal-text-${userid}-${key}" class="ba-maac-input ba-feedback-textarea" placeholder="Enter feedback here..."></textarea>
                    <div class="ba-feedback-suggestions">
                      <span class="ba-feedback-suggestions-label">Suggestions</span>
                      <div class="ba-feedback-suggestion-categories" role="group" aria-label="Feedback suggestions">
                        <button type="button" class="ba-feedback-suggestion-category" data-feedback-suggestion-category="attendance">Attendance</button>
                        <button type="button" class="ba-feedback-suggestion-category" data-feedback-suggestion-category="assignments">Assignments</button>
                        <button type="button" class="ba-feedback-suggestion-category" data-feedback-suggestion-category="tests">Tests</button>
                        <button type="button" class="ba-feedback-suggestion-category" data-feedback-suggestion-category="projects">Projects</button>
                      </div>
                      <div class="ba-feedback-suggestion-options" id="ba-feedback-suggestion-options-${userid}-${key}" hidden></div>
                    </div>
                    <div class="ba-feedback-form-actions ba-feedback-add-actions">
                      ${externalFeedbackColumn ? `<label class="ba-feedback-copy-external" id="ba-feedback-modal-copy-wrap-${userid}-${key}" hidden>
                        <input type="checkbox" id="ba-feedback-modal-copy-external-${userid}-${key}">
                        <span>Add the same feedback to External feedback</span>
                      </label>` : ""}
                      <div class="ba-feedback-action-buttons">
                        <button type="button" class="ba-btn ba-btn-sm ba-feedback-cancel-btn" id="ba-feedback-modal-cancel-btn-${userid}-${key}">Cancel</button>
                        <button type="button" class="ba-btn ba-btn-sm ba-btn-primary ba-feedback-save-btn" id="ba-feedback-modal-save-btn-${userid}-${key}">
                          ${saveIcon} Save Feedback
                        </button>
                      </div>
                    </div>
                  </div>

                </div>
              </div>

            </div>


          </div>
        </div>
      </div>
    `;

    document.body.insertAdjacentHTML("beforeend", modalHtml);
    const modal = document.getElementById(`ba-feedback-modal-${userid}-${key}`);
    const listEl = modal.querySelector(".ba-feedback-modal-list");
    const statusEl = modal.querySelector(".ba-feedback-modal-status");
    const dateInput = document.getElementById(`ba-feedback-modal-date-${userid}-${key}`);
    const textInput = document.getElementById(`ba-feedback-modal-text-${userid}-${key}`);
    const suggestionOptions = document.getElementById(`ba-feedback-suggestion-options-${userid}-${key}`);
    const copyExternalWrap = document.getElementById(`ba-feedback-modal-copy-wrap-${userid}-${key}`);
    const copyExternalInput = document.getElementById(`ba-feedback-modal-copy-external-${userid}-${key}`);
    const addBtn = document.getElementById(`ba-feedback-modal-add-btn-${userid}-${key}`);
    const form = document.getElementById(`ba-feedback-modal-form-${userid}-${key}`);
    const formTitle = document.getElementById(`ba-feedback-modal-form-title-${userid}-${key}`);
    const cancelBtn = document.getElementById(`ba-feedback-modal-cancel-btn-${userid}-${key}`);
    const saveBtn = document.getElementById(`ba-feedback-modal-save-btn-${userid}-${key}`);
    let statusTimer = null;
    let feedbackCloseWarning = null;
    let activeSuggestions = [];

    function clearFeedbackSuggestions() {
      activeSuggestions = [];
      suggestionOptions.hidden = true;
      suggestionOptions.innerHTML = "";
      modal.querySelectorAll("[data-feedback-suggestion-category]").forEach((button) => {
        button.classList.remove("is-active");
      });
    }

    function showFeedbackSuggestions(category, button) {
      activeSuggestions = getFeedbackSuggestions(category);
      suggestionOptions.innerHTML = activeSuggestions.map((suggestion, index) =>
        `<button type="button" class="ba-feedback-suggestion-option" data-feedback-suggestion-index="${index}">${escapeHtml(suggestion)}</button>`
      ).join("");
      suggestionOptions.hidden = activeSuggestions.length === 0;
      modal.querySelectorAll("[data-feedback-suggestion-category]").forEach((item) => {
        item.classList.toggle("is-active", item === button);
      });
    }

    function closeFeedbackCloseWarning() {
      feedbackCloseWarning?.remove();
      feedbackCloseWarning = null;
    }

    function closeFeedbackModal() {
      closeFeedbackCloseWarning();
      if (statusTimer) {
        window.clearTimeout(statusTimer);
        statusTimer = null;
      }
      document.removeEventListener("keydown", handleFeedbackModalKeydown);
      modal.remove();
    }

    function showFeedbackCloseWarning() {
      if (feedbackCloseWarning) {
        return;
      }

      const warningId = `ba-feedback-unsaved-modal-${userid}-${key}`;
      document.body.insertAdjacentHTML("beforeend", `
        <div class="ba-modal-overlay ba-maac-unsaved-modal" id="${escapeHtml(warningId)}" role="dialog" aria-modal="true" aria-labelledby="ba-feedback-unsaved-title">
          <div class="ba-modal-container ba-maac-unsaved-dialog">
            <div class="ba-modal-header">
              <h3 id="ba-feedback-unsaved-title">You have unsaved changes</h3>
              <button type="button" class="ba-modal-close" data-feedback-unsaved-close="1" aria-label="Close">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            </div>
            <div class="ba-modal-body">
              <p class="ba-maac-unsaved-text">You have made changes that have not been saved. Do you want to discard the changes?</p>
            </div>
            <div class="ba-modal-footer ba-maac-unsaved-actions">
              <button type="button" class="ba-btn" data-feedback-unsaved-cancel="1">Cancel</button>
              <button type="button" class="ba-btn ba-btn-success" data-feedback-unsaved-discard="1">Discard changes</button>
            </div>
          </div>
        </div>`);

      feedbackCloseWarning = document.getElementById(warningId);
      feedbackCloseWarning.querySelectorAll("[data-feedback-unsaved-close], [data-feedback-unsaved-cancel]").forEach((button) => {
        button.addEventListener("click", closeFeedbackCloseWarning);
      });
      feedbackCloseWarning.querySelector("[data-feedback-unsaved-discard]")?.addEventListener("click", () => {
        textInput.value = "";
        closeFeedbackModal();
      });
      feedbackCloseWarning.addEventListener("click", (event) => {
        if (event.target === feedbackCloseWarning) {
          closeFeedbackCloseWarning();
        }
      });
    }

    function requestFeedbackModalClose() {
      if (textInput.value.trim() !== "") {
        showFeedbackCloseWarning();
        return;
      }
      closeFeedbackModal();
    }

    function handleFeedbackModalKeydown(event) {
      if (event.key === "Escape") {
        event.preventDefault();
        if (feedbackCloseWarning) {
          closeFeedbackCloseWarning();
          return;
        }
        requestFeedbackModalClose();
      }
    }

    function setFeedbackStatus(message, type = "") {
      if (!statusEl) {
        return;
      }
      if (statusTimer) {
        window.clearTimeout(statusTimer);
        statusTimer = null;
      }
      statusEl.textContent = message || "";
      statusEl.className = `ba-feedback-modal-status${type ? ` ba-feedback-modal-status-${type}` : ""}`;
      if (type === "success") {
        statusTimer = window.setTimeout(() => {
          statusEl.textContent = "";
          statusEl.className = "ba-feedback-modal-status";
          statusTimer = null;
        }, 2500);
      }
    }

    function closeInlineEditors(exceptCard = null) {
      modal.querySelectorAll(".ba-feedback-card.is-editing").forEach((card) => {
        if (exceptCard && card === exceptCard) {
          return;
        }
        card.classList.remove("is-editing");
        const panel = card.querySelector(".ba-feedback-inline-edit");
        if (panel) {
          panel.hidden = true;
        }
      });
    }

    function updateCopyExternalVisibility() {
      if (!copyExternalWrap || !copyExternalInput) {
        return;
      }
      copyExternalWrap.hidden = false;
    }
    function resetAddForm() {
      form.style.display = "none";
      addBtn.style.display = "inline-flex";
      dateInput.value = new Date().toISOString().split("T")[0];
      textInput.value = "";
      clearFeedbackSuggestions();
      if (copyExternalInput) {
        copyExternalInput.checked = false;
      }
      updateCopyExternalVisibility();
      formTitle.textContent = `${column.label} - ${getCurrentFeedbacks().length + 1}`;
    }
    function renderCurrentFeedbacks() {
      const current = getCurrentFeedbacks();
      listEl.innerHTML = renderFeedbackList(current);
      formTitle.textContent = `${column.label} - ${current.length + 1}`;
      bindFeedbackCardEvents();
    }

    async function saveFeedbacksToBackend(valuesByKey) {
      const body = new URLSearchParams();
      body.set("courseid", String(courseId));
      body.set("action", "savedata");
      body.set("sesskey", sesskey);
      body.set(
        "payload",
        JSON.stringify({
          rows: [
            {
              userid: numericUserid,
              values: valuesByKey,
            },
          ],
        }),
      );

      const res = await fetch(baseUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: body.toString(),
      });
      const text = await res.text();
      let json = {};
      try {
        json = text ? JSON.parse(text) : {};
      } catch (error) {
        throw new Error("Unable to save feedback. Please refresh and try again.");
      }
      if (!res.ok || json.error) {
        throw new Error(json.error || "Unable to save feedback.");
      }
    }
    async function persistFeedbacks(nextFeedbacks, successMessage, button = null, extraFeedbacksByKey = {}) {
      if (button) {
        button.disabled = true;
      }
      const cleanFeedbacks = setCurrentFeedbacks(nextFeedbacks);
      const valuesByKey = {
        [key]: cleanFeedbacks,
      };
      Object.entries(extraFeedbacksByKey).forEach(([targetKey, feedbacks]) => {
        const targetColumn = getCustomColumnByKey(targetKey);
        if (targetColumn) {
          valuesByKey[targetKey] = setFeedbacksForColumn(targetColumn, feedbacks);
        }
      });
      renderCurrentFeedbacks();
      setFeedbackStatus("Saving...", "saving");

      try {
        await saveFeedbacksToBackend(valuesByKey);
        setFeedbackStatus(successMessage, "success");
      } catch (error) {
        setFeedbackStatus(error.message || "Unable to save feedback.", "error");
        showMessage(error.message || "Unable to save feedback.", "error");
      } finally {
        if (button && document.body.contains(button)) {
          button.disabled = false;
        }
      }
    }
    function bindFeedbackCardEvents() {
      listEl.querySelectorAll(".ba-feedback-card").forEach((card) => {
        const inlineText = card.querySelector(".ba-feedback-inline-text");
        const inlineOptions = card.querySelector("[data-feedback-inline-suggestion-options]");
        let inlineSuggestions = [];
        card.querySelectorAll("[data-feedback-inline-suggestion-category]").forEach((button) => {
          button.addEventListener("click", () => {
            inlineSuggestions = getFeedbackSuggestions(button.dataset.feedbackInlineSuggestionCategory);
            inlineOptions.innerHTML = inlineSuggestions.map((suggestion, index) =>
              `<button type="button" class="ba-feedback-suggestion-option" data-feedback-inline-suggestion-index="${index}">${escapeHtml(suggestion)}</button>`,
            ).join("");
            inlineOptions.hidden = inlineSuggestions.length === 0;
            card.querySelectorAll("[data-feedback-inline-suggestion-category]").forEach((item) => {
              item.classList.toggle("is-active", item === button);
            });
          });
        });
        inlineOptions.addEventListener("click", (event) => {
          const option = event.target.closest("[data-feedback-inline-suggestion-index]");
          const index = Number(option?.dataset.feedbackInlineSuggestionIndex);
          if (!option || !inlineSuggestions[index] || !inlineText) {
            return;
          }
          appendFeedbackSuggestion(inlineText, inlineSuggestions[index]);
          inlineText.focus();
        });
      });

      listEl.querySelectorAll("[data-feedback-edit]").forEach((button) => {
        button.addEventListener("click", () => {
          const card = button.closest(".ba-feedback-card");
          if (!card) {
            return;
          }
          resetAddForm();
          closeInlineEditors(card);
          card.classList.add("is-editing");
          const panel = card.querySelector(".ba-feedback-inline-edit");
          if (panel) {
            panel.hidden = false;
            const inlineText = panel.querySelector(".ba-feedback-inline-text");
            if (inlineText) {
              inlineText.focus();
            }
          }
        });
      });

      listEl.querySelectorAll("[data-feedback-inline-cancel]").forEach((button) => {
        button.addEventListener("click", () => {
          const card = button.closest(".ba-feedback-card");
          if (!card) {
            return;
          }
          card.classList.remove("is-editing");
          const panel = card.querySelector(".ba-feedback-inline-edit");
          if (panel) {
            panel.hidden = true;
          }
        });
      });

      listEl.querySelectorAll("[data-feedback-inline-save]").forEach((button) => {
        button.addEventListener("click", async () => {
          const card = button.closest(".ba-feedback-card");
          if (!card) {
            return;
          }
          const index = parseInt(card.dataset.feedbackIndex || "-1", 10);
          const date = card.querySelector(".ba-feedback-inline-date")?.value || "";
          const text = card.querySelector(".ba-feedback-inline-text")?.value.trim() || "";
          if (!text) {
            alert("Please enter feedback text.");
            return;
          }

          const nextFeedbacks = getCurrentFeedbacks();
          if (!nextFeedbacks[index]) {
            return;
          }
          nextFeedbacks[index] = {
            ...nextFeedbacks[index],
            date,
            text,
          };
          await persistFeedbacks(nextFeedbacks, "Feedback updated", button);
        });
      });
    }

    dateInput.value = new Date().toISOString().split("T")[0];
    updateCopyExternalVisibility();
    bindFeedbackCardEvents();

    modal.querySelectorAll("[data-feedback-suggestion-category]").forEach((button) => {
      button.addEventListener("click", () => showFeedbackSuggestions(button.dataset.feedbackSuggestionCategory, button));
    });
    suggestionOptions.addEventListener("click", (event) => {
      const option = event.target.closest("[data-feedback-suggestion-index]");
      if (!option) {
        return;
      }
      const index = Number(option.dataset.feedbackSuggestionIndex);
      if (!activeSuggestions[index]) {
        return;
      }
      appendFeedbackSuggestion(textInput, activeSuggestions[index]);
      textInput.focus();
    });

    document.addEventListener("keydown", handleFeedbackModalKeydown);

    modal.querySelectorAll(".ba-modal-close").forEach((button) => {
      button.addEventListener("click", requestFeedbackModalClose);
    });

    modal.addEventListener("click", (event) => {
      if (event.target === modal) {
        requestFeedbackModalClose();
      }
    });

    addBtn.addEventListener("click", () => {
      setFeedbackStatus("");
      closeInlineEditors();
      addBtn.style.display = "none";
      form.style.display = "flex";
      dateInput.value = new Date().toISOString().split("T")[0];
      textInput.value = "";
      clearFeedbackSuggestions();
      if (copyExternalInput) {
        copyExternalInput.checked = false;
      }
      updateCopyExternalVisibility();
      textInput.focus();
      formTitle.textContent = `${column.label} - ${getCurrentFeedbacks().length + 1}`;
    });

    cancelBtn.addEventListener("click", () => {
      resetAddForm();
    });

    saveBtn.addEventListener("click", async () => {
      const date = dateInput.value;
      const text = textInput.value.trim();
      if (!text) {
        alert("Please enter feedback text.");
        return;
      }

      const addedAt = Date.now();
      const feedbackEntry = { date, text, added_at: addedAt };
      const nextFeedbacks = getCurrentFeedbacks();
      nextFeedbacks.push(feedbackEntry);
      const extraFeedbacksByKey = {};
      if (copyExternalInput?.checked && externalFeedbackColumn) {
        const externalFeedbacks = getFeedbacksForColumn(externalFeedbackColumn);
        externalFeedbacks.push({ ...feedbackEntry });
        extraFeedbacksByKey[externalFeedbackColumn.key] = externalFeedbacks;
      }
      resetAddForm();
      await persistFeedbacks(nextFeedbacks, "Feedback added", saveBtn, extraFeedbacksByKey);
    });
  }

  function addMultiSelectRow(userid, key) {
    const numericUserid = parseInt(userid || "0", 10);
    const list = document.querySelector(`[data-multi-list="${numericUserid}:${key}"]`);
    const column = getCustomColumnByKey(key);
    if (!list || !column) {
      return;
    }

    const existingInputs = list.querySelectorAll("input.ba-maac-multi-search");
    for (let i = 0; i < existingInputs.length; i++) {
      if (!existingInputs[i].value.trim()) {
        existingInputs[i].focus();
        return;
      }
    }

    const wrapper = document.createElement("div");
    wrapper.innerHTML = renderMultiSelectRow({ userid: numericUserid }, column, "");
    const row = wrapper.firstElementChild;
    if (!row) {
      return;
    }

    list.appendChild(row);
    row.dataset.bound = "1";
    bindMultiSelectRow(row);
    const input = row.querySelector(".ba-maac-multi-search");
    input?.focus();
    if (numericUserid && key) {
      syncMultiSelectDraft(numericUserid, key);
    }
  }

  document.addEventListener("click", (event) => {
    if (!event.target.closest(".ba-maac-multi-control")) {
      closeAllMultiSelectMenus();
    }
  });

  function applyFilters() {
    const search = (document.getElementById("ba-maac-search")?.value || "").toLowerCase();
    const courseGroup = document.getElementById("ba-maac-course-group")?.value || "";
    const metricMode = document.getElementById("ba-maac-metric-toggle")?.checked
      ? "completion"
      : "grade";
    const perfMin = parseFloat(document.getElementById("ba-maac-performance-min")?.value || "");
    const perfMax = parseFloat(document.getElementById("ba-maac-performance-max")?.value || "");
    const maacMin = parseFloat(document.getElementById("ba-maac-rating-min")?.value || "");
    const maacMax = parseFloat(document.getElementById("ba-maac-rating-max")?.value || "");

    const filterState = {};
    document.querySelectorAll("[data-filter-key]").forEach((input) => {
      const key = input.getAttribute("data-filter-key");
      const type = input.getAttribute("data-filter-type");
      const group = input.getAttribute("data-filter-group") || "custom";
      const bound = input.getAttribute("data-filter-bound");
      if (!filterState[key]) {
        filterState[key] = { type, group };
      }
      if (type === "dropdown-multi") {
        if (!Array.isArray(filterState[key].values)) {
          filterState[key].values = [];
        }
        if (input.checked) {
          const optionValue =
            input.getAttribute("data-filter-value") ?? input.value ?? "";
          if (optionValue !== "") {
            filterState[key].values.push(optionValue);
          }
        }
      } else {
        const raw = (input.value || "").trim();
        if (type === "number") {
          filterState[key][bound] = parseFilterNumber(raw);
        } else {
          filterState[key].value = raw;
        }
      }
    });

    state.filterValues = {
      search: document.getElementById("ba-maac-search")?.value || "",
      courseGroup,
      metricMode,
      performanceMin: document.getElementById("ba-maac-performance-min")?.value || "0",
      performanceMax: document.getElementById("ba-maac-performance-max")?.value || "100",
      maacMin: document.getElementById("ba-maac-rating-min")?.value || "0",
      maacMax: document.getElementById("ba-maac-rating-max")?.value || "9",
      modules: {},
      custom: {},
    };
    Object.entries(filterState).forEach(([key, filter]) => {
      if (filter.group === "module") {
        state.filterValues.modules[key] = {
          min: filter.min ?? "",
          max: filter.max ?? "",
        };
      } else if (filter.type === "number") {
        state.filterValues.custom[key] = {
          min: filter.min ?? "",
          max: filter.max ?? "",
        };
      } else if (filter.type === "dropdown-multi") {
        state.filterValues.custom[key] = {
          values: filter.values || [],
        };
      } else {
        state.filterValues.custom[key] = {
          value: filter.value || "",
        };
      }
    });

    const gradeLabel = document.getElementById("ba-maac-mode-grade");
    const compLabel = document.getElementById("ba-maac-mode-comp");
    if (gradeLabel && compLabel) {
      gradeLabel.classList.toggle("active", metricMode !== "completion");
      compLabel.classList.toggle("active", metricMode === "completion");
    }

    state.filtered = (state.data.students || []).filter((student) => {
      if (
        search &&
        !student.fullname.toLowerCase().includes(search) &&
        !student.username.toLowerCase().includes(search)
      ) {
        return false;
      }

      if (courseGroup) {
        const matchesGroup = (student.groups || []).some(
          (group) => String(group.id) === String(courseGroup),
        );
        if (!matchesGroup) {
          return false;
        }
      }

      const overallPerformance = getOverallPerformanceValue(student);
      if (!isNaN(perfMin) && overallPerformance < perfMin) return false;
      if (!isNaN(perfMax) && overallPerformance > perfMax) return false;
      if (!isNaN(maacMin) && parseFloat(student.maac_rating) < maacMin) return false;
      if (!isNaN(maacMax) && parseFloat(student.maac_rating) > maacMax) return false;

      for (const [key, filter] of Object.entries(filterState)) {
        const rawValue =
          filter.group === "module"
            ? getModuleMetricValue(
                student,
                (state.data.module_columns || []).find((item) => item.key === key) || {},
              ).value
            : getStudentCustomValue(student, key);
        if (filter.type === "number") {
          if (!matchesNumericFilter(rawValue, filter)) return false;
        } else if (filter.type === "boolean") {
          const normalized = rawValue ? "1" : "0";
          if (filter.value && normalized !== filter.value) return false;
        } else if (filter.type === "dropdown-multi") {
          if (filter.values && filter.values.length) {
            const values = Array.isArray(rawValue)
              ? rawValue.map((item) => String(item))
              : rawValue
                ? [String(rawValue)]
                : [];
            if (!filter.values.some((selected) => values.includes(selected))) return false;
          }
        } else if (filter.type === "dropdown") {
          if (filter.value) {
            const values = String(rawValue || "").split(',').map(v => v.trim());
            if (!values.includes(filter.value)) return false;
          }
        } else if (filter.value && !String(rawValue || "").toLowerCase().includes(filter.value.toLowerCase())) {
          return false;
        }
      }

      return true;
    });

    const countEl = document.getElementById("ba-maac-count");
    if (countEl) {
      countEl.textContent = `Found ${state.filtered.length} students`;
    }
    const averagePerformanceEl = document.getElementById("ba-maac-avg-performance");
    if (averagePerformanceEl) {
      const students = state.data.students || [];
      const average = students.length
        ? students.reduce((sum, student) => sum + getOverallPerformanceValue(student), 0) / students.length
        : null;
      averagePerformanceEl.textContent = `Avg Performance: ${average === null ? "-" : Math.round(average)}%`;
    }
    renderMaacTableSection();
  }

  function resetFilters() {
    state.filterValues = buildDefaultFilters(state.data);
    const search = document.getElementById("ba-maac-search");
    const courseGroup = document.getElementById("ba-maac-course-group");
    const metricToggle = document.getElementById("ba-maac-metric-toggle");
    const perfMin = document.getElementById("ba-maac-performance-min");
    const perfMax = document.getElementById("ba-maac-performance-max");
    const maacMin = document.getElementById("ba-maac-rating-min");
    const maacMax = document.getElementById("ba-maac-rating-max");

    if (search) search.value = "";
    if (courseGroup) courseGroup.value = "";
    if (metricToggle) metricToggle.checked = false;
    if (perfMin) perfMin.value = "0";
    if (perfMax) perfMax.value = "100";
    if (maacMin) maacMin.value = "0";
    if (maacMax) maacMax.value = "9";

    document.querySelectorAll("[data-filter-key]").forEach((input) => {
      const type = input.getAttribute("data-filter-type");
      if (type === "dropdown-multi" && input.type === "checkbox") {
        input.checked = false;
      } else if (type === "number") {
        const key = input.getAttribute("data-filter-key");
        const bound = input.getAttribute("data-filter-bound");
        const column = getOrderedCustomColumns().find((item) => item.key === key);
        if (column) {
          input.value = "";
        } else if (input.getAttribute("data-filter-group") === "module") {
          input.value = "";
        } else {
          input.value = "";
        }
      } else {
        input.value = "";
      }
    });

    renderPage();
  }

  function renderMaacTableSection() {
    syncDraftFromInputs();
    const pendingScroll = state.pendingScrollRestore;
    if (!pendingScroll) {
      captureTableScroll();
    }
    const tableSection = document.getElementById("ba-maac-table-section");
    if (tableSection) {
      tableSection.innerHTML = renderTable();
      bindTableEvents();
      restoreTableScroll(pendingScroll);
      scheduleMaacLayoutSync();
      if (pendingScroll) {
        window.scrollTo(pendingScroll.windowX || 0, pendingScroll.windowY || 0);
        requestAnimationFrame(() => {
          restoreTableScroll(pendingScroll);
          window.scrollTo(pendingScroll.windowX || 0, pendingScroll.windowY || 0);
        });
        state.tableScroll.left = pendingScroll.left || 0;
        state.tableScroll.top = pendingScroll.top || 0;
        state.pendingScrollRestore = null;
      }
    }
  }

  async function saveData(options = {}) {
    syncDraftFromInputs();
    const rows = (state.data.students || []).map((student) => ({
      userid: student.userid,
      values: state.customDraft[student.userid] || {},
    }));

    const body = new URLSearchParams();
    body.set("courseid", String(courseId));
    body.set("action", "savedata");
    body.set("sesskey", sesskey);
    body.set("payload", JSON.stringify({ rows }));

    const saveBtn = document.getElementById("ba-maac-save");
    const unsavedSaveBtn = document.getElementById("ba-maac-unsaved-save");
    const activeSaveBtn = options.source === "unsaved-modal" ? unsavedSaveBtn : saveBtn;
    [saveBtn, unsavedSaveBtn].forEach((button) => {
      if (button) {
        button.disabled = true;
      }
    });
    if (activeSaveBtn) {
      activeSaveBtn.textContent = "Saving...";
    }

    try {
      const res = await fetch(baseUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: body.toString(),
      });
      const text = await res.text();
      const json = parseTicketActionResponse(text);

      if (!res.ok || json.error) {
        throw new Error(json.error || "Unable to save MAAC values.");
      }

      state.hasUnsavedChanges = false;
      closeUnsavedChangesModal();
      showMessage("MAAC values updated", "success");
      state.pendingScrollRestore = captureScrollSnapshot();
      await loadData();
      return true;
    } catch (error) {
      showMessage(error.message || "Unable to save MAAC values", "error");
      [saveBtn, unsavedSaveBtn].forEach((button) => {
        if (button) {
          button.disabled = false;
        }
      });
      if (saveBtn) {
        saveBtn.textContent = "Save";
      }
      if (unsavedSaveBtn) {
        unsavedSaveBtn.textContent = "Save";
      }
      return false;
    }
  }

  async function saveTicketEdit(ticketid) {
    if (!state.ticketModal || !ticketid || state.ticketSaving) {
      return;
    }

    const titleEl = document.querySelector(`[data-ticket-edit-title="${ticketid}"]`);
    const reasonEl = document.querySelector(`[data-ticket-edit-reason="${ticketid}"]`);
    const button = document.querySelector(`[data-maac-ticket-edit-save="${ticketid}"]`);
    const payload = {
      ticketid,
      tickettitle: titleEl?.value.trim() || "",
      ticketreason: reasonEl?.value.trim() || "",
    };

    if (!payload.tickettitle || !payload.ticketreason) {
      showMessage("Ticket title and reason are required", "error");
      return;
    }

    state.ticketSaving = true;
    if (button) {
      button.disabled = true;
      button.textContent = "Saving...";
    }

    try {
      const json = await postTicketAction("editticket", payload);
      if (json.ticket?.ticket) {
        upsertStudentTicket(state.ticketModal.student.userid, json.ticket.ticket);
        state.ticketModal.student = findStudentById(state.ticketModal.student.userid) || state.ticketModal.student;
      }
      state.ticketEdit = null;
      state.ticketSaving = false;
      renderPagePreservingScroll();
      showMessage(json.message || "Ticket updated successfully", "success");
    } catch (error) {
      state.ticketSaving = false;
      if (button) {
        button.disabled = false;
        button.textContent = "Save";
      }
      showMessage(error.message || "Unable to update ticket", "error");
    }
  }

  async function saveTicket() {
    if (!state.ticketModal || state.ticketSaving) {
      return;
    }

    const titleEl = document.getElementById("ba-maac-ticket-title");
    const reasonEl = document.getElementById("ba-maac-ticket-reason");
    const submitBtn = document.getElementById("ba-maac-ticket-submit");

    const payload = {
      studentuserid: state.ticketModal.student.userid,
      ssteamuserid: state.ticketModal.ssteamuserid || "",
      tickettitle: titleEl?.value.trim() || "",
      ticketreason: reasonEl?.value.trim() || "",
    };

    // Preserve form values in state so they survive a mid-flight re-render
    state.ticketModal = {
      ...state.ticketModal,
      ssteamuserid: payload.ssteamuserid,
      tickettitle: payload.tickettitle,
      ticketreason: payload.ticketreason,
    };

    if (!payload.tickettitle || !payload.ticketreason) {
      showMessage("Ticket title and reason are required", "error");
      return;
    }
    // Prevent duplicate ticket submissions and premature close.
    // Prevent duplicate ticket submissions and premature close.
    // click any of them (avoids duplicate submissions or premature close).
    state.ticketSaving = true;
    [submitBtn,
     document.getElementById("ba-maac-ticket-cancel"),
     document.getElementById("ba-maac-ticket-close"),
    ].forEach((el) => {
      if (el) el.disabled = true;
    });
    if (submitBtn) submitBtn.textContent = "Raising...";

    try {
      const json = await postTicketAction("saveticket", payload);

      // Update the in-memory student record with the new ticket so that
      // when the table re-renders the count is immediately correct.
      if (json.ticket?.ticket) {
        upsertStudentTicket(payload.studentuserid, json.ticket.ticket, json.ticket.ticketcount);
      }
    // Prevent duplicate ticket submissions and premature close.
      // Using modalEl.remove() is the ONLY reliable way to close the modal.
      // Relying solely on renderPage() to wipe the modal fails when any
      // function called inside the app.innerHTML template literal throws an
      // exception, because the browser never sets innerHTML and the old
      // modal DOM stays visible.
      state.ticketModal = null;
      state.ticketEdit = null;
      state.ticketSaving = false;

      const modalEl = document.getElementById("ba-maac-ticket-modal");
      if (modalEl) {
        modalEl.remove();
      }

      // Show the success message immediately (before the table re-renders).
      showMessage(json.message || "Ticket raised successfully", "success");

      // Re-render the page to update ticket counts in the header pill and
      // the Raise Ticket / View Ticket button in the table row.
      // Wrapped in try/catch so a render error never leaves the user stuck.
      try {
        renderPagePreservingScroll();
      } catch (renderError) {
    // Prevent duplicate ticket submissions and premature close.
        // Silently swallow; the page will recover on the next interaction.
      }

    } catch (error) {
    // Prevent duplicate ticket submissions and premature close.
      state.ticketSaving = false;
      showMessage(error.message || "Unable to raise ticket", "error");

      // Re-enable all buttons so the user can correct and retry.
      [document.getElementById("ba-maac-ticket-submit"),
       document.getElementById("ba-maac-ticket-cancel"),
       document.getElementById("ba-maac-ticket-close"),
      ].forEach((el) => {
        if (el) el.disabled = false;
      });
      const retrySubmit = document.getElementById("ba-maac-ticket-submit");
      if (retrySubmit) retrySubmit.textContent = "Raise Ticket";
    }
  }
  loadData().catch((error) => {
    app.innerHTML = `<div class="ba-maac-error">${escapeHtml(error.message || "Failed to load MAAC data")}</div>`;
  });
});
