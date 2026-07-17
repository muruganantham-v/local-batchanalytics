document.addEventListener("DOMContentLoaded", function () {
  const wrap = document.querySelector(".local-batchanalytics-tickets");
  const app = document.getElementById("ba-ticket-app");
  if (!wrap || !app) return;

  const sesskey = wrap.dataset.sesskey || "";
  const baseUrl = window.location.href.split("?")[0];
  const state = {
    data: null,
    activeTab: "dashboard",
    modal: null,
    selectedCourseKey: "",
    currentPage: 1,
    itemsPerPage: 10,
    filters: {
      batch: "",
      course: "",
      assignedTo: "",
      status: "",
    },
    ticketFilters: {
      status: "",
      dateFrom: "",
      dateTo: "",
      priority: "",
    },
    listFilters: {
      batch: "",
      course: "",
      dateFrom: "",
      dateTo: "",
      priority: "",
      status: "",
    },
  };

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function showMessage(message, type) {
    const holder = document.getElementById("ba-ticket-message");
    if (!holder) {
      return;
    }

    const badge = `<span class="ba-maac-msg ba-maac-msg-${type}">${escapeHtml(message)}</span>`;
    holder.innerHTML = badge;
    setTimeout(() => {
      if (holder.innerHTML === badge) {
        holder.innerHTML = "";
      }
    }, 3000);
  }

  function parseTicketJsonResponse(text) {
    if (!text) {
      return {};
    }

    try {
      return JSON.parse(text);
    } catch (_directError) {
      const starts = ['{"dashboard"', '{"status"', '{"error"'];
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
              // Fall through to the generic error below.
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

    return { error: "Unable to process ticket response. Please check Moodle developer output or session access." };
  }

  async function readTicketJsonResponse(response) {
    const text = await response.text();
    const json = parseTicketJsonResponse(text);
    if (!response.ok || json.error) {
      throw new Error(json.error || `Ticket request failed with HTTP ${response.status}`);
    }
    return json;
  }
  function formatTimestamp(timestamp) {
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

  function formatDateOnly(timestamp) {
    const value = Number(timestamp || 0);
    if (!value) {
      return "-";
    }

    try {
      return new Date(value * 1000).toLocaleDateString();
    } catch (error) {
      return "-";
    }
  }

  function getTabMeta(key) {
    if (key === "mynew") {
      return { label: "New Tickets", tableTitle: "New Tickets" };
    }
    if (key === "myresolved") {
      return { label: "Resolved Tickets", tableTitle: "Resolved Tickets" };
    }
    return { label: "Dashboard", tableTitle: "Recent Tickets" };
  }

  function getAllTickets(key = state.activeTab) {
    return state.data?.[key] || [];
  }

  function getCourseKey(ticket) {
    return String(ticket.courseid || `${ticket.batchname || "-"}:${ticket.coursename || ticket.courseshortname || "-"}`);
  }

  function getTickets(key = state.activeTab) {
    const raw = state.data?.[key] || [];
    return raw.filter((t) => {
      if (state.filters.batch && t.batchname !== state.filters.batch) return false;
      if (state.filters.course && getCourseKey(t) !== state.filters.course) return false;
      if (state.filters.assignedTo && (t.raisedto || "") !== state.filters.assignedTo) return false;
      if (state.filters.status && t.statuskey !== state.filters.status) return false;
      return true;
    });
  }

  function getSelectedCourseTickets(key = state.activeTab) {
    const raw = state.data?.[key] || [];
    return raw.filter((t) => {
      if (state.selectedCourseKey && getCourseKey(t) !== state.selectedCourseKey) return false;
      if (state.ticketFilters.status && t.statuskey !== state.ticketFilters.status) return false;
      if (state.ticketFilters.priority && t.prioritykey !== state.ticketFilters.priority) return false;

      if (state.ticketFilters.dateFrom || state.ticketFilters.dateTo) {
        const ticketDate = new Date(t.timecreated * 1000);
        ticketDate.setHours(0, 0, 0, 0);

        if (state.ticketFilters.dateFrom) {
          const fromDate = new Date(state.ticketFilters.dateFrom);
          fromDate.setHours(0, 0, 0, 0);
          if (ticketDate < fromDate) return false;
        }

        if (state.ticketFilters.dateTo) {
          const toDate = new Date(state.ticketFilters.dateTo);
          toDate.setHours(0, 0, 0, 0);
          if (ticketDate > toDate) return false;
        }
      }

      return true;
    });
  }
  function getListTickets(key = state.activeTab) {
    const raw = state.data?.[key] || [];
    return raw.filter((t) => {
      if (state.listFilters.batch && t.batchname !== state.listFilters.batch) return false;
      if (state.listFilters.course && getCourseKey(t) !== state.listFilters.course) return false;
      if (state.listFilters.status && t.statuskey !== state.listFilters.status) return false;
      if (state.listFilters.priority && t.prioritykey !== state.listFilters.priority) return false;

      if (state.listFilters.dateFrom || state.listFilters.dateTo) {
        const ticketDate = new Date(t.timecreated * 1000);
        ticketDate.setHours(0, 0, 0, 0);

        if (state.listFilters.dateFrom) {
          const fromDate = new Date(state.listFilters.dateFrom);
          fromDate.setHours(0, 0, 0, 0);
          if (ticketDate < fromDate) return false;
        }

        if (state.listFilters.dateTo) {
          const toDate = new Date(state.listFilters.dateTo);
          toDate.setHours(0, 0, 0, 0);
          if (ticketDate > toDate) return false;
        }
      }

      return true;
    });
  }

  function getFilterOptions(key = "dashboard") {
    const allDashboardTickets = getAllTickets(key);
    const batches = [...new Set(allDashboardTickets.map((t) => t.batchname).filter(Boolean))].sort();
    const courses = [];
    const courseSeen = new Set();
    const assigned = [...new Set(allDashboardTickets.map((t) => t.raisedto).filter(Boolean))].sort();

    allDashboardTickets.forEach((ticket) => {
      const key = getCourseKey(ticket);
      if (courseSeen.has(key)) {
        return;
      }
      courseSeen.add(key);
      courses.push({
        key,
        label: ticket.coursename || ticket.courseshortname || "-",
      });
    });
    courses.sort((left, right) => left.label.localeCompare(right.label));

    return { batches, courses, assigned };
  }

  function renderMainFilters() {
    const options = getFilterOptions();

    return `
      <div class="ba-ticket-filters ba-filter-section" style="margin-bottom: 20px; padding: 15px; display: flex; gap: 15px; flex-wrap: wrap; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px;">
        <div style="flex: 1; min-width: 150px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Batch</label>
          <select id="filter-batch" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
            <option value="">All Batches</option>
            ${options.batches.map((b) => `<option value="${escapeHtml(b)}"${state.filters.batch === b ? " selected" : ""}>${escapeHtml(b)}</option>`).join("")}
          </select>
        </div>
        <div style="flex: 1.4; min-width: 190px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Course</label>
          <select id="filter-course" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
            <option value="">All Courses</option>
            ${options.courses.map((course) => `<option value="${escapeHtml(course.key)}"${state.filters.course === course.key ? " selected" : ""}>${escapeHtml(course.label)}</option>`).join("")}
          </select>
        </div>
        <div style="flex: 1.2; min-width: 180px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Assigned To</label>
          <select id="filter-assigned" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
            <option value="">All Assignees</option>
            ${options.assigned.map((name) => `<option value="${escapeHtml(name)}"${state.filters.assignedTo === name ? " selected" : ""}>${escapeHtml(name)}</option>`).join("")}
          </select>
        </div>
        <div style="flex: 1; min-width: 150px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Status</label>
          <select id="filter-status" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
            <option value="">All Statuses</option>
            <option value="open"${state.filters.status === "open" ? " selected" : ""}>Open</option>
            <option value="ss_in_progress"${state.filters.status === "ss_in_progress" ? " selected" : ""}>SS - In Progress</option>
            <option value="pm_in_progress"${state.filters.status === "pm_in_progress" ? " selected" : ""}>PM - In Progress</option>
            <option value="resolved"${state.filters.status === "resolved" ? " selected" : ""}>Resolved</option>
          </select>
        </div>
        <div style="display: flex; align-items: flex-end;">
          <button id="filter-clear" class="ba-btn ba-btn-sm" style="background: #e2e8f0; color: #475569; padding: 8px 15px; margin-bottom: 1px;">Clear</button>
        </div>
      </div>
    `;
  }

  function renderTicketFilters() {
    return `
      <div class="ba-ticket-filters ba-filter-section" style="margin-bottom: 20px; padding: 15px; display: flex; gap: 15px; flex-wrap: wrap; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px;">
        <div style="flex: 1; min-width: 150px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Priority</label>
          <select id="ticket-filter-priority" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
            <option value="">All Priorities</option>
            <option value="low"${state.ticketFilters.priority === "low" ? " selected" : ""}>Low</option>
            <option value="medium"${state.ticketFilters.priority === "medium" ? " selected" : ""}>Medium</option>
            <option value="high"${state.ticketFilters.priority === "high" ? " selected" : ""}>High</option>
          </select>
        </div>
        <div style="flex: 1; min-width: 120px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Date From</label>
          <input type="date" id="ticket-filter-date-from" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;" value="${escapeHtml(state.ticketFilters.dateFrom)}">
        </div>
        <div style="flex: 1; min-width: 120px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Date To</label>
          <input type="date" id="ticket-filter-date-to" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;" value="${escapeHtml(state.ticketFilters.dateTo)}">
        </div>
        <div style="flex: 1; min-width: 150px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Status</label>
          <select id="ticket-filter-status" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
            <option value="">All Statuses</option>
            <option value="open"${state.ticketFilters.status === "open" ? " selected" : ""}>Open</option>
            <option value="ss_in_progress"${state.ticketFilters.status === "ss_in_progress" ? " selected" : ""}>SS - In Progress</option>
            <option value="pm_in_progress"${state.ticketFilters.status === "pm_in_progress" ? " selected" : ""}>PM - In Progress</option>
            <option value="resolved"${state.ticketFilters.status === "resolved" ? " selected" : ""}>Resolved</option>
          </select>
        </div>
        <div style="display: flex; align-items: flex-end;">
          <button id="ticket-filter-clear" class="ba-btn ba-btn-sm" style="background: #e2e8f0; color: #475569; padding: 8px 15px; margin-bottom: 1px;">Clear</button>
        </div>
      </div>
    `;
  }

  function renderListFilters(key = state.activeTab) {
    const options = getFilterOptions(key);

    return `
      <div class="ba-ticket-filters ba-filter-section" style="margin-bottom: 20px; padding: 15px; display: flex; gap: 15px; flex-wrap: wrap; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px;">
        <div style="flex: 1; min-width: 150px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Batch</label>
          <select id="list-filter-batch" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
            <option value="">All Batches</option>
            ${options.batches.map((b) => `<option value="${escapeHtml(b)}"${state.listFilters.batch === b ? " selected" : ""}>${escapeHtml(b)}</option>`).join("")}
          </select>
        </div>
        <div style="flex: 1.4; min-width: 190px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Course</label>
          <select id="list-filter-course" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
            <option value="">All Courses</option>
            ${options.courses.map((course) => `<option value="${escapeHtml(course.key)}"${state.listFilters.course === course.key ? " selected" : ""}>${escapeHtml(course.label)}</option>`).join("")}
          </select>
        </div>
        <div style="flex: 1; min-width: 120px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Date From</label>
          <input type="date" id="list-filter-date-from" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;" value="${escapeHtml(state.listFilters.dateFrom)}">
        </div>
        <div style="flex: 1; min-width: 120px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Date To</label>
          <input type="date" id="list-filter-date-to" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;" value="${escapeHtml(state.listFilters.dateTo)}">
        </div>
        <div style="flex: 1; min-width: 150px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Priority</label>
          <select id="list-filter-priority" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
            <option value="">All Priorities</option>
            <option value="low"${state.listFilters.priority === "low" ? " selected" : ""}>Low</option>
            <option value="medium"${state.listFilters.priority === "medium" ? " selected" : ""}>Medium</option>
            <option value="high"${state.listFilters.priority === "high" ? " selected" : ""}>High</option>
          </select>
        </div>
        <div style="flex: 1; min-width: 150px;">
          <label style="font-size: 12px; font-weight: 600; color: var(--text-gray); display: block; margin-bottom: 5px;">Status</label>
          <select id="list-filter-status" class="ba-select-small" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
            <option value="">All Statuses</option>
            <option value="open"${state.listFilters.status === "open" ? " selected" : ""}>Open</option>
            <option value="ss_in_progress"${state.listFilters.status === "ss_in_progress" ? " selected" : ""}>SS - In Progress</option>
            <option value="pm_in_progress"${state.listFilters.status === "pm_in_progress" ? " selected" : ""}>PM - In Progress</option>
            <option value="resolved"${state.listFilters.status === "resolved" ? " selected" : ""}>Resolved</option>
          </select>
        </div>
        <div style="display: flex; align-items: flex-end;">
          <button id="list-filter-clear" class="ba-btn ba-btn-sm" style="background: #e2e8f0; color: #475569; padding: 8px 15px; margin-bottom: 1px;">Clear</button>
        </div>
      </div>
    `;
  }

  function getAccessData() {
    return (
      state.data?.access || {
        rolekey: "viewer",
        rolelabel: "View Access",
        scopekey: "batch",
        scopelabel: "Showing tickets for your accessible batches.",
        canmanage: false,
        canresolve: false,
      }
    );
  }

  function getActionableCount() {
    return getTickets("dashboard").filter((ticket) => ticket.canresolve).length;
  }

  function renderTabs() {
    const tabs = ["dashboard", "mynew", "myresolved"];
    return `
      <div class="ba-ticket-tab-strip">
        ${tabs
          .map((key) => {
            const meta = getTabMeta(key);
            const count = key === "dashboard" ? buildCourseTicketRows(getTickets("dashboard")).length : getAllTickets(key).length;
            return `<button type="button" class="ba-ticket-tab${
              state.activeTab === key ? " is-active" : ""
            }" data-ticket-tab="${key}">
              <span>${escapeHtml(meta.label)}</span>
              <span class="ba-ticket-tab-count">${count}</span>
            </button>`;
          })
          .join("")}
      </div>`;
  }

  function renderStatusBadge(ticket) {
    const badgeMap = {
      open: "ba-ticket-status-badge ba-ticket-status-open",
      ss_in_progress: "ba-ticket-status-badge ba-ticket-status-ss-in-progress",
      pm_in_progress: "ba-ticket-status-badge ba-ticket-status-pm-in-progress",
      resolved: "ba-ticket-status-badge ba-ticket-status-resolved",
    };
    const badgeClass = badgeMap[ticket.statuskey] || badgeMap.open;
    return `<span class="${badgeClass}">${escapeHtml(ticket.status)}</span>`;
  }

  function renderPriorityBadge(ticket) {
    const map = {
      low: "ba-ticket-priority-badge ba-ticket-priority-low",
      medium: "ba-ticket-priority-badge ba-ticket-priority-medium",
      high: "ba-ticket-priority-badge ba-ticket-priority-high",
    };
    const key = ticket.prioritykey || "low";
    return `<span class="${map[key] || map.low}">${escapeHtml(ticket.priority || "Low")}</span>`;
  }

  function renderActions(ticket) {
    const actions = [
      `<button type="button" class="ba-btn ba-btn-sm ba-btn-view" data-ticket-view="${ticket.id}">View</button>`,
    ];

    if (ticket.canedit && ticket.statuskey !== "resolved") {
      actions.push(
        `<button type="button" class="ba-btn ba-btn-sm ba-btn-success" data-ticket-edit="${ticket.id}">${escapeHtml(
          ticket.actionlabel || "Edit",
        )}</button>`,
      );
    }

    return actions.join("");
  }

  function renderTicketStatusPill(counts) {
    return `<span class="ba-ch-badge avg ba-maac-ticket-pill">
      <span class="ba-maac-ticket-pill-open">Open : ${counts.open || 0}</span>
      <span class="ba-maac-ticket-pill-separator">|</span>
      <span class="ba-maac-ticket-pill-pending">Pending : ${counts.pending || 0}</span>
      <span class="ba-maac-ticket-pill-separator">|</span>
      <span class="ba-maac-ticket-pill-closed">Closed : ${counts.closed || 0}</span>
    </span>`;
  }

  function buildCourseTicketRows(tickets) {
    const rows = new Map();
    tickets.forEach((ticket) => {
      const key = getCourseKey(ticket);
      if (!rows.has(key)) {
        rows.set(key, {
          key,
          batchname: ticket.batchname || "-",
          coursename: ticket.coursename || ticket.courseshortname || "-",
          assigned: new Set(),
          ticketCount: 0,
          counts: { open: 0, pending: 0, closed: 0 },
        });
      }

      const row = rows.get(key);
      if (ticket.raisedto) {
        row.assigned.add(ticket.raisedto);
      }
      row.ticketCount++;
      if (ticket.statuskey === "resolved") {
        row.counts.closed++;
      } else if (ticket.statuskey === "ss_in_progress" || ticket.statuskey === "pm_in_progress") {
        row.counts.pending++;
      } else {
        row.counts.open++;
      }
    });

    return Array.from(rows.values()).sort((left, right) => {
      const batchCompare = String(left.batchname).localeCompare(String(right.batchname));
      return batchCompare || String(left.coursename).localeCompare(String(right.coursename));
    });
  }

  function renderCourseTicketTable(title, tickets) {
    const rows = buildCourseTicketRows(tickets);
    if (!rows.length) {
      return `<div class="ba-ticket-table-card">
        <div class="ba-ticket-card-head">${escapeHtml(title)}</div>
        <div class="ba-ticket-empty-state">No ticket courses found.</div>
      </div>`;
    }

    return `<div class="ba-ticket-table-card">
      <div class="ba-ticket-card-head">${escapeHtml(title)}</div>
      <div class="ba-table-wrap">
        <table class="ba-table ba-ticket-table">
          <thead>
            <tr>
              <th>Batch Name</th>
              <th>Course Name</th>
              <th>Assigned To</th>
              <th>Ticket Count</th>
              <th>Status</th>
              <th>View Tickets</th>
            </tr>
          </thead>
          <tbody>
            ${rows
              .map((row) => {
                const assigned = Array.from(row.assigned).join(", ") || "-";
                return `<tr>
                  <td>${escapeHtml(row.batchname)}</td>
                  <td>${escapeHtml(row.coursename)}</td>
                  <td>${escapeHtml(assigned)}</td>
                  <td>${escapeHtml(String(row.ticketCount))}</td>
                  <td>${renderTicketStatusPill(row.counts)}</td>
                  <td><button type="button" class="ba-btn ba-btn-sm ba-btn-view" data-course-ticket-view="${escapeHtml(row.key)}">View Tickets</button></td>
                </tr>`;
              })
              .join("")}
          </tbody>
        </table>
      </div>
    </div>`;
  }

  function getSelectedCourseMeta() {
    const ticket = (state.data?.dashboard || []).find((item) => getCourseKey(item) === state.selectedCourseKey);
    return ticket
      ? {
          batchname: ticket.batchname || "-",
          coursename: ticket.coursename || ticket.courseshortname || "-",
        }
      : { batchname: "-", coursename: "Tickets" };
  }

  function renderTableCard(title, tickets, usePagination = false, showBack = false) {
    if (!tickets.length) {
      return `<div class="ba-ticket-table-card">
        <div class="ba-ticket-card-head" style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
          <span>${escapeHtml(title)}</span>
          ${showBack ? `<button type="button" class="ba-btn ba-btn-sm" id="ba-ticket-back-to-courses">Back</button>` : ""}
        </div>
        <div class="ba-ticket-empty-state">No tickets found.</div>
      </div>`;
    }

    let displayTickets = tickets;
    let paginationHtml = "";

    if (usePagination) {
      const totalPages = Math.ceil(tickets.length / state.itemsPerPage);
      if (state.currentPage > totalPages) {
        state.currentPage = totalPages > 0 ? totalPages : 1;
      }

      const startIndex = (state.currentPage - 1) * state.itemsPerPage;
      displayTickets = tickets.slice(startIndex, startIndex + state.itemsPerPage);

      if (totalPages > 1) {
        let buttons = [];
        for (let i = 1; i <= totalPages; i++) {
          buttons.push(`<button type="button" class="ba-btn ba-btn-sm ${i === state.currentPage ? "ba-btn-success" : ""}" style="${i !== state.currentPage ? "background:#e2e8f0; color:#475569;" : ""}" data-page="${i}">${i}</button>`);
        }
        paginationHtml = `<div class="ba-ticket-pagination" style="display: flex; gap: 5px; justify-content: flex-end; margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border);">
          ${buttons.join("")}
        </div>`;
      }
    }

    const rows = displayTickets
      .map((ticket) => {
        return `<tr>
          <td>${escapeHtml(ticket.batchname || "-")}</td>
          <td>${escapeHtml(ticket.coursename || ticket.courseshortname || "-")}</td>
          <td>
            <strong>${escapeHtml(ticket.studentname || "-")}</strong>
            <small>${escapeHtml(ticket.studentusername || "")}</small>
          </td>
          <td>${escapeHtml(ticket.raisedby || "-")}</td>
          <td>${escapeHtml(ticket.tickettitle || "-")}</td>
          <td>${renderStatusBadge(ticket)}</td>
          <td>${escapeHtml(ticket.priority || "Low")}</td>
          <td>${escapeHtml(formatDateOnly(ticket.timecreated))}</td>
          <td class="ba-ticket-actions-cell">${renderActions(ticket)}</td>
        </tr>`;
      })
      .join("");

    return `<div class="ba-ticket-table-card">
      <div class="ba-ticket-card-head" style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
        <span>${escapeHtml(title)}</span>
        ${showBack ? `<button type="button" class="ba-btn ba-btn-sm" id="ba-ticket-back-to-courses">Back</button>` : ""}
      </div>
      <div class="ba-table-wrap">
        <table class="ba-table ba-ticket-table">
          <thead>
            <tr>
              <th>Batch</th>
              <th>Course Name</th>
              <th>Student Details</th>
              <th>Raised By</th>
              <th>Ticket Title</th>
              <th>Status</th>
              <th>Priority</th>
              <th>Date Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
      ${paginationHtml}
    </div>`;
  }

  function renderDashboardView() {
    if (state.selectedCourseKey) {
      const meta = getSelectedCourseMeta();
      const tickets = getSelectedCourseTickets("dashboard");
      return `
        ${renderTicketFilters()}
        <div class="ba-ticket-list-meta">${escapeHtml(meta.batchname)} - ${escapeHtml(meta.coursename)} (${tickets.length} tickets)</div>
        ${renderTableCard("Tickets", tickets, true, true)}
      `;
    }

    return `
      ${renderMainFilters()}
      ${renderCourseTicketTable("Course Tickets", getTickets("dashboard"))}
    `;
  }

  function renderListView() {
    const meta = getTabMeta(state.activeTab);
    const tickets = getListTickets(state.activeTab);
    return `
      ${renderListFilters(state.activeTab)}
      <div class="ba-ticket-list-meta">Showing ${tickets.length} tickets</div>
      ${renderTableCard(meta.tableTitle, tickets, true)}
    `;
  }
  function getInitials(name) {
    const words = String(name || "")
      .trim()
      .split(/\s+/)
      .filter(Boolean);
    if (!words.length) {
      return "NA";
    }

    return words
      .slice(0, 2)
      .map((word) => word.charAt(0).toUpperCase())
      .join("");
  }

  function renderKeyValueRows(rows) {
    return rows
      .map(
        ([label, value]) => `<div class="ba-ticket-meta-row">
          <div class="ba-ticket-meta-label">${escapeHtml(label)}</div>
          <div class="ba-ticket-meta-value">${escapeHtml(value || "-")}</div>
        </div>`,
      )
      .join("");
  }

  function renderStudentCard(ticket) {
    const rows = [
      ["Name", ticket.studentname || "-"],
      ["Email address", ticket.studentemail || "-"],
      ["Course", ticket.coursename || "-"],
    ];

    return `<div class="ba-ticket-top-card ba-ticket-top-card-student">
      <div class="ba-ticket-top-card-title">
        <span class="ba-ticket-top-card-icon" aria-hidden="true">&#128100;</span>
        <span>Student Details</span>
      </div>
      <div class="ba-ticket-meta-grid">
        ${renderKeyValueRows(rows)}
      </div>
    </div>`;
  }

  function renderRaisedByCard(ticket) {
    const rows = [
      ["Name", ticket.raisedby || "-"],
      ["Email address", ticket.raisedbyemail || "-"],
      ["Assigned To", ticket.raisedto || "-"],
      ["Raised", formatTimestamp(ticket.timecreated)],
    ];

    return `<div class="ba-ticket-top-card ba-ticket-top-card-mentor">
      <div class="ba-ticket-top-card-title">
        <span class="ba-ticket-top-card-icon" aria-hidden="true">&#128172;</span>
        <span>Raised By (Mentor)</span>
      </div>
      <div class="ba-ticket-meta-grid">
        ${renderKeyValueRows(rows)}
      </div>
    </div>`;
  }

  function renderTicketInfoPanel(ticket) {
    return `<div class="ba-ticket-info-card">
      <div class="ba-ticket-info-head">
        <div class="ba-ticket-info-head-title">
          <span class="ba-ticket-info-head-icon" aria-hidden="true">&#128424;</span>
          <span>Ticket Information</span>
        </div>
        <div class="ba-ticket-info-badges">
          ${renderStatusBadge(ticket)}
          ${renderPriorityBadge(ticket)}
        </div>
      </div>
      <div class="ba-ticket-info-body">
        <div class="ba-ticket-info-main-title">${escapeHtml(ticket.tickettitle || "-")}</div>
        <div class="ba-ticket-info-grid">
          <div class="ba-ticket-info-label">Status</div>
          <div class="ba-ticket-info-value">${renderStatusBadge(ticket)}</div>
          <div class="ba-ticket-info-label">Priority</div>
          <div class="ba-ticket-info-value">${renderPriorityBadge(ticket)}</div>
          <div class="ba-ticket-info-label">Assigned To</div>
          <div class="ba-ticket-info-value">${escapeHtml(ticket.raisedto || "-")}</div>
          <div class="ba-ticket-info-label">Description</div>
          <div class="ba-ticket-info-value">${escapeHtml(ticket.ticketreason || "-")}</div>
        </div>
      </div>
    </div>`;
  }

  function renderReadOnlyPanel(title, content) {
    return `<div class="ba-ticket-note-card">
      <div class="ba-ticket-note-card-title">${escapeHtml(title)}</div>
      <div class="ba-ticket-note-card-body">${escapeHtml(content || "-")}</div>
    </div>`;
  }

  function renderEscalateButton(ticket) {
    if (ticket.canescalate) {
      return `<button type="button" class="ba-btn ba-ticket-escalate-btn" id="ba-ticket-escalate">Escalate to PM</button>`;
    }
    if (ticket.escalatedtopm && ticket.statuskey !== "resolved") {
      return `<button type="button" class="ba-btn ba-ticket-escalate-btn" disabled>Escalated to PM</button>`;
    }
    return "";
  }
  function renderEditPanel(ticket) {
    const priority = state.modal?.priority || ticket.prioritykey || "low";
    const heading = ticket.actionlabel === "Edit" ? "Update Ticket" : "Resolve Ticket";
    return `<div class="ba-ticket-edit-card">
      <div class="ba-ticket-edit-head">${escapeHtml(heading)}</div>
      <div class="ba-ticket-edit-body">
        <div class="ba-ticket-edit-grid">
          <div class="ba-ticket-field">
            <label>Status</label>
            <div class="ba-ticket-readonly-status">${renderStatusBadge(ticket)}</div>
          </div>
          <div class="ba-ticket-field">
            <label for="ba-ticket-priority">Priority</label>
            <select id="ba-ticket-priority" class="ba-range-input">
              <option value="low"${priority === "low" ? " selected" : ""}>Low</option>
              <option value="medium"${priority === "medium" ? " selected" : ""}>Medium</option>
              <option value="high"${priority === "high" ? " selected" : ""}>High</option>
            </select>
          </div>
        </div>
        <div class="ba-ticket-field ba-ticket-field-feedback">
          <label for="ba-ticket-feedback">Support Feedback</label>
          <textarea id="ba-ticket-feedback" class="ba-maac-ticket-textarea" rows="5" placeholder="Please enter the feedback / resolution notes for this ticket.">${escapeHtml(
            state.modal?.feedback || "",
          )}</textarea>
          <div class="ba-ticket-field-help">Update saves feedback and keeps the ticket in progress. Resolve closes the ticket.</div>
        </div>
      </div>
      <div class="ba-ticket-edit-footer ba-ticket-action-footer">
        <button type="button" class="ba-btn" id="ba-ticket-cancel">&#8592; Cancel</button>
        <div class="ba-ticket-right-actions">
          ${renderEscalateButton(ticket)}
          <button type="button" class="ba-btn ba-btn-view" id="ba-ticket-update">Update Ticket</button>
          <button type="button" class="ba-btn ba-maac-ticket-submit" id="ba-ticket-resolve">Resolve</button>
        </div>
      </div>
    </div>`;
  }
  function renderResolvedDetails(ticket) {
    return `<div class="ba-ticket-note-card">
      <div class="ba-ticket-note-card-title">Resolution Details</div>
      <div class="ba-ticket-note-card-content">
        <div class="ba-ticket-resolved-grid">
          <div class="ba-ticket-info-label">Resolved By</div>
          <div class="ba-ticket-info-value">${escapeHtml(ticket.resolvedbyfullname || "-")}</div>
          <div class="ba-ticket-info-label">Resolved On</div>
          <div class="ba-ticket-info-value">${escapeHtml(formatTimestamp(ticket.timeresolved))}</div>
          <div class="ba-ticket-info-label">Feedback</div>
          <div class="ba-ticket-info-value">${escapeHtml(ticket.resolutionfeedback || "-")}</div>
        </div>
      </div>
    </div>`;
  }

  function renderTimelinePanel(ticket) {
    const timeline = Array.isArray(ticket.timeline) ? ticket.timeline : [];
    if (!timeline.length) {
      return "";
    }

    return `<div class="ba-ticket-note-card">
      <div class="ba-ticket-note-card-title">Timeline</div>
      <div class="ba-maac-ticket-timeline ba-maac-ticket-timeline-standalone">
        <div class="ba-maac-ticket-timeline-list">
          ${timeline
            .map(
              (event) => `<div class="ba-maac-ticket-timeline-item">
                <div class="ba-maac-ticket-timeline-dot" aria-hidden="true"></div>
                <div class="ba-maac-ticket-timeline-content">
                  <div class="ba-maac-ticket-timeline-head">
                    <span class="ba-maac-ticket-timeline-label">${escapeHtml(event.title || "Update")}</span>
                    <span class="ba-maac-ticket-timeline-time">${escapeHtml(formatTimestamp(event.timecreated))}</span>
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
      </div>
    </div>`;
  }

  function renderModal() {
    if (!state.modal) {
      return "";
    }

    const ticket = state.modal.ticket;
    const isEditMode = state.modal.mode === "edit";
    const canSwitchToEdit = !isEditMode && ticket.statuskey !== "resolved" && ticket.canedit;
    return `<div class="ba-modal-overlay ba-maac-ticket-modal" id="ba-ticket-modal">
      <div class="ba-modal-container ba-maac-ticket-dialog ba-ticket-mock-dialog">
        <div class="ba-modal-header">
          <h3>${isEditMode ? `${escapeHtml(ticket.actionlabel || "Edit")} Ticket` : "View Ticket"}</h3>
          <button type="button" class="ba-modal-close" id="ba-ticket-close" aria-label="Close">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </div>
        <div class="ba-modal-body ba-ticket-mock-body">
          <div class="ba-ticket-top-grid">
            ${renderStudentCard(ticket)}
            ${renderRaisedByCard(ticket)}
          </div>
          ${renderTicketInfoPanel(ticket)}
          ${
            isEditMode
              ? renderEditPanel(ticket)
              : ticket.statuskey === "resolved"
                ? renderResolvedDetails(ticket)
                : ticket.resolutionfeedback
                  ? renderReadOnlyPanel("Support Feedback", ticket.resolutionfeedback)
                  : ""
          }
          ${renderTimelinePanel(ticket)}
        </div>
        ${
          !isEditMode
            ? `<div class="ba-ticket-view-footer ba-ticket-action-footer">
                <button type="button" class="ba-btn" id="ba-ticket-cancel">Close</button>
                <div class="ba-ticket-right-actions">
                  ${renderEscalateButton(ticket)}
                  ${
                    canSwitchToEdit
                      ? `<button type="button" class="ba-btn ba-maac-ticket-submit" id="ba-ticket-switch-edit">${escapeHtml(
                          ticket.actionlabel || "Edit",
                        )} Ticket</button>`
                      : ""
                  }
                </div>
              </div>`
            : ""
        }
      </div>
    </div>`;
  }

  function renderPage() {
    const access = getAccessData();
    const pageBody = state.activeTab === "dashboard" ? renderDashboardView() : renderListView();
    app.innerHTML = `
      <div class="ba-ticket-dashboard-head">
        <div>
          <div class="ba-ticket-page-kicker">Support Tickets</div>
          <h2 class="ba-ch-title">Ticket Dashboard</h2>
          <div class="ba-ticket-list-meta">${escapeHtml(access.rolelabel)}. ${escapeHtml(access.scopelabel)}</div>
          <div id="ba-ticket-message"></div>
        </div>
        <a href="index.php" class="ba-btn ba-btn-view">Back to Analytics</a>
      </div>
      <div class="ba-ticket-shell">
        ${renderTabs()}
        ${pageBody}
      </div>
      ${renderModal()}
    `;

    bindEvents();
  }

  function updateTicketInState(ticketid, updates) {
    ["dashboard", "mynew", "myresolved"].forEach((key) => {
      (state.data?.[key] || []).forEach((ticket) => {
        if (Number(ticket.id) === Number(ticketid)) {
          Object.assign(ticket, updates);
        }
      });
    });
  }

  async function markTicketViewed(ticket) {
    if (!ticket || ticket.statuskey !== "open") {
      return;
    }

    const body = new URLSearchParams();
    body.set("action", "viewticket");
    body.set("sesskey", sesskey);
    body.set("payload", JSON.stringify({ ticketid: ticket.id }));

    try {
      const response = await fetch(baseUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
      });
      const json = await readTicketJsonResponse(response);
      if (json.ticket?.changed) {
        updateTicketInState(ticket.id, {
          status: json.ticket.status,
          statuskey: json.ticket.statuskey,
          timemodified: json.ticket.timemodified,
        });
      }
    } catch (error) {
      // Opening the ticket should not fail if status marking is unavailable.
    }
  }
  async function openModal(ticketId, mode) {
    const ticket = getAllTickets("dashboard").find((item) => Number(item.id) === Number(ticketId));
    if (!ticket) {
      showMessage("Unable to load ticket", "error");
      return;
    }

    if (mode === "edit" && !ticket.canedit) {
      showMessage("You are not allowed to update this ticket.", "error");
      return;
    }

    if (mode === "view" || mode === "edit") {
      await markTicketViewed(ticket);
    }

    state.modal = {
      mode,
      ticket,
      feedback: ticket.resolutionfeedback || "",
      priority: ticket.prioritykey || "low",
    };
    renderPage();
  }

  function closeModal() {
    state.modal = null;
    renderPage();
  }

  async function loadData(message = "") {
    app.innerHTML = `<div class="ba-maac-loading">Loading tickets...</div>`;

    const response = await fetch(`${baseUrl}?action=gettickets`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: "sesskey=" + encodeURIComponent(window.BA_SESSKEY || document.querySelector('.local-batchanalytics-wrap').getAttribute('data-sesskey'))
    });
    const json = await readTicketJsonResponse(response);

    state.data = json;
    renderPage();
    if (message) {
      showMessage(message, "success");
    }
  }

  async function submitEscalate() {
    if (!state.modal || !state.modal.ticket || !state.modal.ticket.canescalate) {
      return;
    }

    const ticketid = state.modal.ticket.id;
    const button = document.getElementById("ba-ticket-escalate");
    if (button) {
      button.disabled = true;
      button.textContent = "Escalating...";
    }

    const body = new URLSearchParams();
    body.set("action", "escalateticket");
    body.set("sesskey", sesskey);
    body.set("payload", JSON.stringify({ ticketid }));

    try {
      const response = await fetch(baseUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
      });
      const json = await readTicketJsonResponse(response);
      const responseTicket = json.ticket?.ticket || (json.ticket?.id ? json.ticket : null);
      const updatedTicket = responseTicket && !Array.isArray(responseTicket) && Object.keys(responseTicket).length
        ? responseTicket
        : null;
      const fallbackUpdate = {
        status: "PM - In Progress",
        statuskey: "pm_in_progress",
        canescalate: false,
        escalatedtopm: true,
      };
      updateTicketInState(ticketid, updatedTicket || fallbackUpdate);
      state.modal = null;
      renderPage();
      showMessage(json.message || "Ticket escalated to PM successfully", "success");
    } catch (error) {
      showMessage(error.message || "Unable to escalate ticket", "error");
      if (button) {
        button.disabled = false;
        button.textContent = "Escalate to PM";
      }
    }
  }
  async function submitTicketAction(mode) {
    if (!state.modal || state.modal.mode !== "edit") {
      return;
    }

    const feedbackEl = document.getElementById("ba-ticket-feedback");
    const priorityEl = document.getElementById("ba-ticket-priority");
    const updateBtn = document.getElementById("ba-ticket-update");
    const resolveBtn = document.getElementById("ba-ticket-resolve");
    const feedback = feedbackEl?.value.trim() || "";
    const priority = priorityEl?.value || "low";
    state.modal.feedback = feedback;
    state.modal.priority = priority;

    if (!feedback) {
      showMessage("Resolution feedback is required.", "error");
      return;
    }

    [updateBtn, resolveBtn].forEach((button) => {
      if (button) {
        button.disabled = true;
      }
    });
    const activeBtn = mode === "resolve" ? resolveBtn : updateBtn;
    if (activeBtn) {
      activeBtn.textContent = mode === "resolve" ? "Resolving..." : "Updating...";
    }

    const body = new URLSearchParams();
    body.set("action", "updateticket");
    body.set("sesskey", sesskey);
    body.set(
      "payload",
      JSON.stringify({
        ticketid: state.modal.ticket.id,
        feedback,
        priority,
        mode,
      }),
    );

    const response = await fetch(baseUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: body.toString(),
    });
    let json;
    try {
      json = await readTicketJsonResponse(response);
    } catch (error) {
      showMessage(error.message || "Unable to update ticket", "error");
      [updateBtn, resolveBtn].forEach((button) => {
        if (button) {
          button.disabled = false;
        }
      });
      if (updateBtn) updateBtn.textContent = "Update Ticket";
      if (resolveBtn) resolveBtn.textContent = "Resolve";
      return;
    }

    state.modal = null;
    state.activeTab = mode === "resolve" ? "myresolved" : "mynew";
    await loadData(json.message || (mode === "resolve" ? "Ticket resolved successfully" : "Ticket updated successfully"));
  }

  function submitUpdate() {
    submitTicketAction("update").catch((error) => showMessage(error.message || "Unable to update ticket", "error"));
  }

  function submitResolve() {
    submitTicketAction("resolve").catch((error) => showMessage(error.message || "Unable to resolve ticket", "error"));
  }
  function bindEvents() {
    const updateMainFilters = () => {
      state.filters.batch = document.getElementById("filter-batch")?.value || "";
      state.filters.course = document.getElementById("filter-course")?.value || "";
      state.filters.assignedTo = document.getElementById("filter-assigned")?.value || "";
      state.filters.status = document.getElementById("filter-status")?.value || "";
      state.currentPage = 1;
      renderPage();
    };

    ["filter-batch", "filter-course", "filter-assigned", "filter-status"].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.addEventListener("change", updateMainFilters);
    });

    const clearBtn = document.getElementById("filter-clear");
    if (clearBtn) {
      clearBtn.addEventListener("click", () => {
        state.filters = { batch: "", course: "", assignedTo: "", status: "" };
        state.currentPage = 1;
        renderPage();
      });
    }
    const updateListFilters = () => {
      state.listFilters.batch = document.getElementById("list-filter-batch")?.value || "";
      state.listFilters.course = document.getElementById("list-filter-course")?.value || "";
      state.listFilters.dateFrom = document.getElementById("list-filter-date-from")?.value || "";
      state.listFilters.dateTo = document.getElementById("list-filter-date-to")?.value || "";
      state.listFilters.priority = document.getElementById("list-filter-priority")?.value || "";
      state.listFilters.status = document.getElementById("list-filter-status")?.value || "";
      state.currentPage = 1;
      renderPage();
    };

    ["list-filter-batch", "list-filter-course", "list-filter-date-from", "list-filter-date-to", "list-filter-priority", "list-filter-status"].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.addEventListener("change", updateListFilters);
    });

    const listClearBtn = document.getElementById("list-filter-clear");
    if (listClearBtn) {
      listClearBtn.addEventListener("click", () => {
        state.listFilters = { batch: "", course: "", dateFrom: "", dateTo: "", priority: "", status: "" };
        state.currentPage = 1;
        renderPage();
      });
    }

    const updateTicketFilters = () => {
      state.ticketFilters.status = document.getElementById("ticket-filter-status")?.value || "";
      state.ticketFilters.dateFrom = document.getElementById("ticket-filter-date-from")?.value || "";
      state.ticketFilters.dateTo = document.getElementById("ticket-filter-date-to")?.value || "";
      state.ticketFilters.priority = document.getElementById("ticket-filter-priority")?.value || "";
      state.currentPage = 1;
      renderPage();
    };

    ["ticket-filter-status", "ticket-filter-date-from", "ticket-filter-date-to", "ticket-filter-priority"].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.addEventListener("change", updateTicketFilters);
    });

    const ticketClearBtn = document.getElementById("ticket-filter-clear");
    if (ticketClearBtn) {
      ticketClearBtn.addEventListener("click", () => {
        state.ticketFilters = { status: "", dateFrom: "", dateTo: "", priority: "" };
        state.currentPage = 1;
        renderPage();
      });
    }

    document.querySelectorAll("[data-course-ticket-view]").forEach((button) => {
      button.addEventListener("click", () => {
        state.selectedCourseKey = button.dataset.courseTicketView || "";
        state.ticketFilters = { status: "", dateFrom: "", dateTo: "", priority: "" };
        state.currentPage = 1;
        renderPage();
      });
    });

    const backToCourses = document.getElementById("ba-ticket-back-to-courses");
    if (backToCourses) {
      backToCourses.addEventListener("click", () => {
        state.selectedCourseKey = "";
        state.ticketFilters = { status: "", dateFrom: "", dateTo: "", priority: "" };
        state.currentPage = 1;
        renderPage();
      });
    }

    document.querySelectorAll("[data-ticket-tab]").forEach((tab) => {
      tab.addEventListener("click", () => {
        state.activeTab = tab.dataset.ticketTab || "dashboard";
        state.selectedCourseKey = "";
        state.currentPage = 1;
        renderPage();
      });
    });

    document.querySelectorAll("[data-page]").forEach((btn) => {
      btn.addEventListener("click", () => {
        state.currentPage = parseInt(btn.dataset.page, 10);
        renderPage();
      });
    });

    document.querySelectorAll("[data-ticket-view]").forEach((button) => {
      button.addEventListener("click", () => {
        openModal(button.dataset.ticketView, "view").catch((error) => showMessage(error.message || "Unable to open ticket", "error"));
      });
    });

    document.querySelectorAll("[data-ticket-edit]").forEach((button) => {
      button.addEventListener("click", () => {
        openModal(button.dataset.ticketEdit, "edit").catch((error) => showMessage(error.message || "Unable to open ticket", "error"));
      });
    });

    const modal = document.getElementById("ba-ticket-modal");
    if (modal) {
      modal.addEventListener("click", (event) => {
        if (event.target === modal) {
          closeModal();
        }
      });
    }

    const closeBtn = document.getElementById("ba-ticket-close");
    if (closeBtn) {
      closeBtn.addEventListener("click", closeModal);
    }

    const cancelBtn = document.getElementById("ba-ticket-cancel");
    if (cancelBtn) {
      cancelBtn.addEventListener("click", closeModal);
    }

    const escalateBtn = document.getElementById("ba-ticket-escalate");
    if (escalateBtn) {
      escalateBtn.addEventListener("click", submitEscalate);
    }


    const switchEditBtn = document.getElementById("ba-ticket-switch-edit");
    if (switchEditBtn) {
      switchEditBtn.addEventListener("click", () => {
        if (!state.modal) {
          return;
        }
        state.modal.mode = "edit";
        renderPage();
      });
    }
  }
  loadData().catch((error) => {
    app.innerHTML = `<div class="ba-maac-error">${escapeHtml(error.message || "Failed to load tickets")}</div>`;
  });
});
