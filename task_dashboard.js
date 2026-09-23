/**
 * Task Dashboard JavaScript
 *
 * Ultra-Modern Interactive Task Dashboard
 * Features:
 * 1. Role switcher (Program Manager / SS Team, Mentor, Assistant Manager, All)
 * 2. Clickable stat cards for fast 1-click filtering
 * 3. Instant search input across task labels, batches, and sections
 * 4. Filter chips (All, Overdue, Due Today, Due This Week)
 * 5. Modern task cards with status accents, icons, and smooth completion modal
 * 6. Interactive timeline view for forthcoming activities
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function () {
  "use strict";

  let currentTaskData = null;
  let pendingTaskToComplete = null;
  const initialParams = new URLSearchParams(window.location.search);
  let activeRoleFilter = initialParams.get("role") || "all";
  let currentTodoFilter = "due_first"; // due_first, overdue_only, due_today, due_week, all, latest_first, earliest_first
  let currentSearchQuery = "";

  function getGreeting(userName) {
    const hour = new Date().getHours();
    let timeGreeting = "Good morning";
    if (hour >= 12 && hour < 17) {
      timeGreeting = "Good afternoon";
    } else if (hour >= 17 || hour < 4) {
      timeGreeting = "Good evening";
    }
    return `${timeGreeting}, ${userName || "User"}`;
  }

  function getFormattedCurrentDate() {
    const options = { weekday: "long", year: "numeric", month: "short", day: "numeric" };
    return new Date().toLocaleDateString(undefined, options);
  }

  function getInitials(name) {
    if (!name) return "U";
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) {
      return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return name.substring(0, 2).toUpperCase();
  }

  function getActivityIcon(label, roleType) {
    const l = (label || "").toLowerCase();
    if (l.includes("assignment")) return "📝";
    if (l.includes("calendar")) return "📅";
    if (l.includes("audit") || l.includes("verify") || l.includes("verification")) return "🔍";
    if (l.includes("kickoff") || l.includes("induction")) return "🚀";
    if (l.includes("feedback") || l.includes("talk") || l.includes("softskill") || l.includes("soft skill") || l.includes("pet")) return "💬";
    if (roleType === "mentor") return "🎓";
    if (roleType === "am") return "🏢";
    return "📋";
  }

  function getSesskey() {
    const wrap = document.querySelector(".local-batchanalytics-wrap");
    if (wrap && wrap.dataset.sesskey) {
      return wrap.dataset.sesskey;
    }
    if (typeof M !== "undefined" && M.cfg && M.cfg.sesskey) {
      return M.cfg.sesskey;
    }
    return "";
  }

  function showToast(message, type) {
    type = type || "info";
    const container = document.getElementById("ba-toast-container");
    if (!container) {
      return;
    }
    const toast = document.createElement("div");
    toast.className = `ba-toast ba-toast-${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
  }

  function getFilteredAndSortedTodos() {
    if (!currentTaskData || !currentTaskData.todos) {
      return [];
    }
    let all = [...currentTaskData.todos];

    // 1. Text Search Filter
    if (currentSearchQuery.trim() !== "") {
      const q = currentSearchQuery.toLowerCase().trim();
      all = all.filter((t) => {
        const titleMatch = (t.activity_label || "").toLowerCase().includes(q);
        const batchMatch = (t.batch_name || "").toLowerCase().includes(q);
        const secMatch = (t.section_name || "").toLowerCase().includes(q);
        const courseMatch = (t.course_name || "").toLowerCase().includes(q);
        return titleMatch || batchMatch || secMatch || courseMatch;
      });
    }

    // 2. Status / Time Filter
    let filtered = [];
    switch (currentTodoFilter) {
      case "overdue_only":
        filtered = all.filter((t) => (t.due_class === "over" || t.due_class === "esc") && !t.completed);
        filtered.sort((a, b) => (a.planned_timestamp || 0) - (b.planned_timestamp || 0));
        break;
      case "due_today":
        filtered = all.filter((t) => t.due_class === "today" && !t.completed);
        filtered.sort((a, b) => (a.planned_timestamp || 0) - (b.planned_timestamp || 0));
        break;
      case "due_week":
        filtered = all.filter((t) => (t.due_class === "today" || t.due_class === "soon") && !t.completed);
        filtered.sort((a, b) => (a.planned_timestamp || 0) - (b.planned_timestamp || 0));
        break;
      case "latest_first":
        filtered = all.filter((t) => !t.completed);
        filtered.sort((a, b) => (b.planned_timestamp || 0) - (a.planned_timestamp || 0));
        break;
      case "earliest_first":
        filtered = all.filter((t) => !t.completed);
        filtered.sort((a, b) => (a.planned_timestamp || 0) - (b.planned_timestamp || 0));
        break;
      case "all":
        filtered = all;
        filtered.sort((a, b) => (a.sort_order || a.planned_timestamp || 0) - (b.sort_order || b.planned_timestamp || 0));
        break;
      case "due_first":
      default:
        filtered = all.filter((t) => !t.completed);
        filtered.sort((a, b) => (a.sort_order || a.planned_timestamp || 0) - (b.sort_order || b.planned_timestamp || 0));
        break;
    }

    return filtered;
  }

  function updateTodoList() {
    const listEl = document.getElementById("taskTodoList");
    const countEl = document.getElementById("taskTodoCount");
    if (!listEl) return;

    const filtered = getFilteredAndSortedTodos();
    listEl.innerHTML = renderTodoList(filtered);
    bindTodoItemEvents();

    if (countEl) {
      const pendingTotal = (currentTaskData && currentTaskData.todos)
        ? currentTaskData.todos.filter((t) => !t.completed).length
        : 0;

      if (currentTodoFilter === "due_first" || currentTodoFilter === "all") {
        countEl.textContent = `${pendingTotal} pending`;
      } else if (currentTodoFilter === "overdue_only") {
        countEl.textContent = `${filtered.length} overdue (${pendingTotal} total)`;
      } else if (currentTodoFilter === "due_today") {
        countEl.textContent = `${filtered.length} today (${pendingTotal} total)`;
      } else if (currentTodoFilter === "due_week") {
        countEl.textContent = `${filtered.length} this week (${pendingTotal} total)`;
      } else {
        countEl.textContent = `${filtered.length} shown (${pendingTotal} total)`;
      }
    }

    // Sync filter pills state
    document.querySelectorAll(".task-pill-btn").forEach((pill) => {
      if (pill.dataset.filter === currentTodoFilter) {
        pill.classList.add("active");
      } else {
        pill.classList.remove("active");
      }
    });

    // Sync stat cards active state
    document.querySelectorAll(".task-stat-card-clickable").forEach((card) => {
      const filter = card.dataset.filter;
      if (filter && filter === currentTodoFilter) {
        card.classList.add("is-active");
      } else {
        card.classList.remove("is-active");
      }
    });

    // Sync dropdown
    const sortSelect = document.getElementById("taskSortSelect");
    if (sortSelect) {
      sortSelect.value = currentTodoFilter;
    }
  }

  function renderDashboard(data) {
    currentTaskData = data;
    const root = document.getElementById("task-dashboard-root");
    if (!root) {
      return;
    }

    const user = data.user || {};
    const glance = data.glance || { due_this_week: 0, overdue: 0, batches: 0, students: 0 };
    const forthcoming = data.forthcoming || [];
    const roleInfo = data.role_info || {};

    const greetingText = getGreeting(user.firstname || user.name);
    const userInitials = getInitials(user.firstname ? `${user.firstname} ${user.lastname || ""}` : user.name);
    const currentDate = getFormattedCurrentDate();
    const initialTodos = getFilteredAndSortedTodos();
    const pendingTotal = (data.todos || []).filter((t) => !t.completed).length;

    // Determine current selected role
    const currentRole = roleInfo.current_role || activeRoleFilter;
    activeRoleFilter = currentRole;

    root.innerHTML = `
      <div class="task-dashboard-wrap">
        <!-- TOP GREETING HERO CARD -->
        <div class="task-greet-card">
          <div class="task-greet-left">
            <div class="task-user-avatar-badge">${escapeHtml(userInitials)}</div>
            <div class="task-greet-title-wrap">
              <h1 id="taskGreetingTitle">
                ${greetingText}
                <span class="task-date-pill">📅 ${escapeHtml(currentDate)}</span>
              </h1>
              <div class="task-sub" id="taskRoleSub">
                <span class="task-persona-badge">${escapeHtml(roleInfo.current_role ? roleInfo.current_role.toUpperCase() : "PORTFOLIO")}</span>
                ${escapeHtml(roleInfo.subtitle || "Portfolio Overview")}
              </div>
            </div>
          </div>
          <div class="task-role-switcher-box">
            <label for="taskRoleSelect" class="task-role-switcher-label">
              <span>👤</span> Switch View:
            </label>
            <select id="taskRoleSelect" class="task-role-dropdown" aria-label="Switch User Role">
              <option value="pm_ss" ${currentRole === "pm_ss" ? "selected" : ""}>📋 Program Manager, SS Executive, SS Team</option>
              <option value="mentor" ${currentRole === "mentor" ? "selected" : ""}>🎓 Mentor (Enrolled Courses)</option>
              <option value="am" ${currentRole === "am" ? "selected" : ""}>🏢 Assistant Manager (Section Start Dates)</option>
              <option value="all" ${currentRole === "all" ? "selected" : ""}>🌐 All Roles (Combined Overview)</option>
            </select>
          </div>
        </div>

        <!-- 4 INTERACTIVE GLANCE STAT CARDS -->
        <div class="ba-stats-row-bottom task-glance-grid" id="taskGlance">
          <div class="ba-new-stat-card card-blue task-stat-card-clickable ${currentTodoFilter === "due_week" ? "is-active" : ""}" data-filter="due_week" role="button" tabindex="0">
            <div class="ba-new-stat-header">
              <span class="ba-new-stat-title">Tasks Due This Week</span>
              <span class="ba-new-stat-icon icon-blue">📅</span>
            </div>
            <div class="ba-new-stat-value" id="taskGlanceDueWeek">${glance.due_this_week}</div>
            <div class="ba-new-stat-footer"><span class="badge-status-dot dot-blue"></span> Next 7 calendar days</div>
          </div>

          <div class="ba-new-stat-card ${glance.overdue > 0 ? "card-red" : "card-green"} task-stat-card-clickable ${currentTodoFilter === "overdue_only" ? "is-active" : ""}" id="taskGlanceOverdueCard" data-filter="overdue_only" role="button" tabindex="0">
            <div class="ba-new-stat-header">
              <span class="ba-new-stat-title">Overdue Tasks</span>
              <span class="ba-new-stat-icon ${glance.overdue > 0 ? "icon-red" : "icon-green"}">${glance.overdue > 0 ? "⚠️" : "✅"}</span>
            </div>
            <div class="ba-new-stat-value ${glance.overdue > 0 ? "text-danger" : "text-success"}" id="taskGlanceOverdue">${glance.overdue}</div>
            <div class="ba-new-stat-footer"><span class="badge-status-dot ${glance.overdue > 0 ? "dot-red" : "dot-green"}"></span> ${glance.overdue > 0 ? "Requires immediate attention" : "All tasks on schedule"}</div>
          </div>

          <div class="ba-new-stat-card card-purple task-stat-card-clickable ${currentTodoFilter === "all" ? "is-active" : ""}" data-filter="all" role="button" tabindex="0">
            <div class="ba-new-stat-header">
              <span class="ba-new-stat-title">${currentRole === "mentor" ? "Active Courses" : "Assigned Batches"}</span>
              <span class="ba-new-stat-icon icon-purple">${currentRole === "mentor" ? "📚" : "📁"}</span>
            </div>
            <div class="ba-new-stat-value" id="taskGlanceBatches">${glance.batches}</div>
            <div class="ba-new-stat-footer"><span class="badge-status-dot dot-purple"></span> Active in portfolio</div>
          </div>

          <div class="ba-new-stat-card card-orange task-stat-card-clickable" data-filter="all" role="button" tabindex="0">
            <div class="ba-new-stat-header">
              <span class="ba-new-stat-title">Total Students</span>
              <span class="ba-new-stat-icon icon-orange">👥</span>
            </div>
            <div class="ba-new-stat-value" id="taskGlanceStudents">${glance.students}</div>
            <div class="ba-new-stat-footer"><span class="badge-status-dot dot-orange"></span> Enrolled learners</div>
          </div>
        </div>

        <!-- SEARCH & QUICK FILTERS BAR -->
        <div class="task-controls-card">
          <div class="task-search-wrap">
            <span class="task-search-icon">🔍</span>
            <input type="text" id="taskSearchInput" class="task-search-input" placeholder="Search tasks by title, batch, or section..." value="${escapeHtml(currentSearchQuery)}" />
          </div>
          <div class="task-filter-pills">
            <button type="button" class="task-pill-btn ${currentTodoFilter === "due_first" ? "active" : ""}" data-filter="due_first">All Pending (${pendingTotal})</button>
            <button type="button" class="task-pill-btn pill-overdue ${currentTodoFilter === "overdue_only" ? "active" : ""}" data-filter="overdue_only">⚠️ Overdue (${glance.overdue})</button>
            <button type="button" class="task-pill-btn ${currentTodoFilter === "due_today" ? "active" : ""}" data-filter="due_today">📅 Due Today</button>
            <button type="button" class="task-pill-btn ${currentTodoFilter === "due_week" ? "active" : ""}" data-filter="due_week">🗓️ Due This Week (${glance.due_this_week})</button>
          </div>
        </div>

        <div class="task-cols">
          <!-- MY TO-DO PANEL -->
          <div class="task-dpanel">
            <div class="task-ph">
              <h2>
                <span class="task-ph-bar"></span>
                My To-Do
                <span class="task-badge-counter" id="taskTodoCount">${pendingTotal} pending</span>
              </h2>
              <div class="task-sort-wrap">
                <select id="taskSortSelect" class="task-sort-select" aria-label="Sort tasks">
                  <option value="due_first" ${currentTodoFilter === "due_first" ? "selected" : ""}>Sort: Due first</option>
                  <option value="latest_first" ${currentTodoFilter === "latest_first" ? "selected" : ""}>Sort: Latest date</option>
                  <option value="earliest_first" ${currentTodoFilter === "earliest_first" ? "selected" : ""}>Sort: Earliest date</option>
                  <option value="all" ${currentTodoFilter === "all" ? "selected" : ""}>Show: All (incl. completed)</option>
                </select>
              </div>
            </div>
            <div id="taskTodoList">
              ${renderTodoList(initialTodos)}
            </div>
          </div>

          <!-- FORTHCOMING PANEL -->
          <div class="task-dpanel">
            <div class="task-ph">
              <h2>
                <span class="task-ph-bar"></span>
                Forthcoming Schedule
              </h2>
              <span class="task-date-pill">Upcoming Activities</span>
            </div>
            <div id="taskFcList">
              ${renderForthcomingList(forthcoming)}
            </div>
          </div>
        </div>
      </div>

      <!-- TASK COMPLETION CONFIRMATION MODAL -->
      <div class="task-overlay" id="taskModalOverlay">
        <div class="task-modal">
          <h3>Mark activity as complete?</h3>
          <p>This confirms the soft skill activity is done and updates the actual completion date in Batch Management.</p>
          <div class="task-act-name" id="taskModalActName">—</div>
          <div class="task-mbtns">
            <button type="button" class="task-btn-cancel" id="taskModalBtnCancel">Cancel</button>
            <button type="button" class="task-btn-confirm" id="taskModalBtnConfirm">Yes, mark complete</button>
          </div>
        </div>
      </div>
    `;

    // Bind all user events
    bindModalEvents();
    bindTodoItemEvents();
    bindControlsEvents();
    bindRoleEvents();
  }

  function bindRoleEvents() {
    const roleSelect = document.getElementById("taskRoleSelect");
    if (!roleSelect) return;

    roleSelect.addEventListener("change", function () {
      activeRoleFilter = this.value;
      const url = new URL(window.location.href);
      url.searchParams.set("role", this.value);
      window.history.pushState({}, "", url.toString());
      loadTaskData(activeRoleFilter);
    });
  }

  function bindControlsEvents() {
    // Search input live filtering
    const searchInput = document.getElementById("taskSearchInput");
    if (searchInput) {
      searchInput.addEventListener("input", function () {
        currentSearchQuery = this.value;
        updateTodoList();
      });
    }

    // Sort select
    const sortSelect = document.getElementById("taskSortSelect");
    if (sortSelect) {
      sortSelect.addEventListener("change", function () {
        currentTodoFilter = this.value;
        updateTodoList();
      });
    }

    // Quick filter pills
    document.querySelectorAll(".task-pill-btn").forEach((pill) => {
      pill.addEventListener("click", function () {
        currentTodoFilter = this.dataset.filter;
        updateTodoList();
      });
    });

    // Clickable Glance Stat Cards
    document.querySelectorAll(".task-stat-card-clickable").forEach((card) => {
      card.addEventListener("click", function () {
        const filter = this.dataset.filter;
        if (filter) {
          currentTodoFilter = filter;
          updateTodoList();
        }
      });
    });
  }

  function renderTodoList(todos) {
    if (!todos || !todos.length) {
      if (currentSearchQuery.trim() !== "") {
        return `
          <div class="task-empty">
            <div class="task-empty-icon">🔍</div>
            <div>No tasks match "${escapeHtml(currentSearchQuery)}"</div>
          </div>
        `;
      }
      if (currentTodoFilter === "overdue_only") {
        return `
          <div class="task-empty">
            <div class="task-empty-icon">🎉</div>
            <div>Great job! No overdue tasks at this time.</div>
          </div>
        `;
      } else if (currentTodoFilter === "due_today") {
        return `
          <div class="task-empty">
            <div class="task-empty-icon">☕</div>
            <div>No tasks due today. All caught up!</div>
          </div>
        `;
      } else if (currentTodoFilter === "due_week") {
        return `
          <div class="task-empty">
            <div class="task-empty-icon">✅</div>
            <div>No tasks due this week.</div>
          </div>
        `;
      }
      return `
        <div class="task-empty">
          <div class="task-empty-icon">🌟</div>
          <div>All caught up! No pending tasks at the moment.</div>
        </div>
      `;
    }

    return todos
      .map((t) => {
        const taskKey = `${t.section_id || t.course_id}_${t.activity_key}`;
        const batchUrl = t.batch_id ? `batch.php?batchid=${encodeURIComponent(t.batch_id)}` : "#";
        const isDone = Boolean(t.completed);
        const icon = getActivityIcon(t.activity_label, t.role_type);

        let borderClass = "task-border-soon";
        if (isDone) {
          borderClass = "task-border-done";
        } else if (t.due_class === "over" || t.due_class === "esc") {
          borderClass = "task-border-over";
        } else if (t.due_class === "today") {
          borderClass = "task-border-today";
        }

        let metaHtml = "";
        if (t.role_type === "mentor") {
          metaHtml = `
            <span class="task-pill-tag">📚 ${escapeHtml(t.course_name || "")}</span>
            <span class="task-m-dot">·</span>
            <span>Activity Due Date</span>
          `;
        } else if (t.role_type === "am") {
          metaHtml = `
            <a href="${batchUrl}" class="task-m-batch">📁 ${escapeHtml(t.batch_name || "")}</a>
            <span class="task-m-dot">·</span>
            <span class="task-pill-tag">Section ${escapeHtml(t.section_name || "")}</span>
            <span class="task-m-dot">·</span>
            <span>Section Start: <strong>${escapeHtml(t.section_start_date_formatted || "—")}</strong></span>
          `;
        } else {
          metaHtml = `
            <a href="${batchUrl}" class="task-m-batch">📁 ${escapeHtml(t.batch_name || "")}</a>
            <span class="task-m-dot">·</span>
            <span class="task-pill-tag">Section ${escapeHtml(t.section_name || "")}</span>
            <span class="task-m-dot">·</span>
            <span>Soft Skill Milestone</span>
          `;
        }

        let actionHtml = "";
        if (t.can_complete) {
          actionHtml = isDone
            ? '<span class="task-done-tag">✓ Completed</span>'
            : `<button type="button" class="task-mc-btn" data-action="ask-complete" data-taskkey="${escapeHtml(taskKey)}"><span>✓</span> Mark Complete</button>`;
        } else if (t.action_url) {
          actionHtml = `<a href="${escapeHtml(t.action_url)}" target="_blank" class="task-btn-action">${escapeHtml(t.action_label || "View")} ↗</a>`;
        }

        return `
          <div class="task-todo-item ${borderClass} ${isDone ? "done" : ""}" data-taskid="${escapeHtml(taskKey)}">
            <div class="task-body">
              <div class="task-t-wrap">
                <span class="task-act-icon">${icon}</span>
                <span class="task-t">${escapeHtml(t.activity_label)}</span>
                <div class="task-badges-group">
                  <span class="task-due ${escapeHtml(t.due_class || "soon")}">
                    ${escapeHtml(t.due_text)}
                  </span>
                  <span class="task-date-highlight ${t.due_class === "over" || t.due_class === "esc" ? "due-overdue" : ""}">
                    <span class="task-cal-icon">📅</span>
                    <strong class="task-date-val">${escapeHtml(t.planned_date_formatted)}</strong>
                  </span>
                </div>
              </div>
              <div class="task-m">
                ${metaHtml}
              </div>
            </div>
            <div class="task-actions">
              ${actionHtml}
            </div>
          </div>
        `;
      })
      .join("");
  }

  function renderForthcomingList(forthcoming) {
    if (!forthcoming || !forthcoming.length) {
      return `
        <div class="task-empty">
          <div class="task-empty-icon">📅</div>
          <div>No forthcoming activities scheduled in this period.</div>
        </div>
      `;
    }

    return `
      <div class="task-timeline-container">
        ${forthcoming
          .map((item) => {
            const icon = getActivityIcon(item.activity_label, item.role_type);
            const batchName = item.batch_name || item.course_name || "";
            const sectionName = item.section_name ? ` · Section ${item.section_name}` : "";

            return `
              <div class="task-timeline-item">
                <div class="task-timeline-track">
                  <div class="task-timeline-dot"></div>
                  <div class="task-timeline-line"></div>
                </div>
                <div class="task-timeline-content">
                  <div class="task-timeline-header">
                    <span class="task-timeline-title">${icon} ${escapeHtml(item.activity_label)}</span>
                    <span class="task-timeline-countdown">${escapeHtml(item.time_relative || "")}</span>
                  </div>
                  <div class="task-m">
                    <span class="task-pill-tag">📁 ${escapeHtml(batchName)}${escapeHtml(sectionName)}</span>
                    <span class="task-m-dot">·</span>
                    <span class="task-date-highlight fc-date">
                      <span class="task-cal-icon">📅</span>
                      <strong class="task-date-val">${escapeHtml(item.planned_date_formatted)}</strong>
                    </span>
                  </div>
                </div>
              </div>
            `;
          })
          .join("")}
      </div>
    `;
  }

  function bindTodoItemEvents() {
    const list = document.getElementById("taskTodoList");
    if (!list) return;

    list.querySelectorAll('button[data-action="ask-complete"]').forEach((btn) => {
      btn.addEventListener("click", function () {
        const taskKey = this.dataset.taskkey;
        if (currentTaskData && currentTaskData.todos) {
          const task = currentTaskData.todos.find(
            (t) => `${t.section_id || t.course_id}_${t.activity_key}` === taskKey
          );
          if (task) {
            askComplete(task);
          }
        }
      });
    });
  }

  function askComplete(task) {
    pendingTaskToComplete = task;
    const modal = document.getElementById("taskModalOverlay");
    const actName = document.getElementById("taskModalActName");
    if (modal && actName) {
      actName.textContent = `${task.activity_label} (${task.batch_name || ""} - ${task.section_name || ""})`;
      modal.classList.add("show");
    }
  }

  function closeCompleteModal() {
    const modal = document.getElementById("taskModalOverlay");
    if (modal) {
      modal.classList.remove("show");
    }
    pendingTaskToComplete = null;
  }

  function bindModalEvents() {
    const modal = document.getElementById("taskModalOverlay");
    const cancelBtn = document.getElementById("taskModalBtnCancel");
    const confirmBtn = document.getElementById("taskModalBtnConfirm");

    if (cancelBtn) {
      cancelBtn.addEventListener("click", closeCompleteModal);
    }

    if (modal) {
      modal.addEventListener("click", function (e) {
        if (e.target === this) {
          closeCompleteModal();
        }
      });
    }

    if (confirmBtn) {
      confirmBtn.addEventListener("click", confirmTaskCompletion);
    }
  }

  function confirmTaskCompletion() {
    if (!pendingTaskToComplete) {
      return;
    }

    const task = pendingTaskToComplete;
    const confirmBtn = document.getElementById("taskModalBtnConfirm");
    if (confirmBtn) {
      confirmBtn.disabled = true;
      confirmBtn.textContent = "Updating...";
    }

    const payload = new URLSearchParams();
    payload.append("action", "complete_task");
    payload.append("sectionid", task.section_id);
    payload.append("activity_key", task.activity_key);
    payload.append("sesskey", getSesskey());

    fetch("index.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
        "X-Requested-With": "XMLHttpRequest",
      },
      body: payload.toString(),
    })
      .then((res) => res.json())
      .then((data) => {
        if (confirmBtn) {
          confirmBtn.disabled = false;
          confirmBtn.textContent = "Yes, mark complete";
        }
        closeCompleteModal();

        if (data.error) {
          showToast(`Error: ${data.error}`, "error");
          return;
        }

        // Mark task completed
        task.completed = true;
        if (currentTaskData && currentTaskData.todos) {
          const matched = currentTaskData.todos.find(
            (t) => String(t.section_id) === String(task.section_id) && t.activity_key === task.activity_key
          );
          if (matched) {
            matched.completed = true;
          }
        }

        // Re-render task list with current active filter
        updateTodoList();

        // Update overdue glance if this task was overdue
        if (task.due_class === "over" || task.due_class === "esc") {
          const overdueEl = document.getElementById("taskGlanceOverdue");
          if (overdueEl) {
            const currentOverdue = parseInt(overdueEl.textContent, 10) || 0;
            const newOverdue = Math.max(0, currentOverdue - 1);
            overdueEl.textContent = newOverdue;
            const overdueCard = document.getElementById("taskGlanceOverdueCard");
            if (overdueCard && newOverdue === 0) {
              overdueCard.classList.remove("card-red");
              overdueCard.classList.add("card-green");
              const iconEl = overdueCard.querySelector(".ba-new-stat-icon");
              if (iconEl) {
                iconEl.classList.remove("icon-red");
                iconEl.classList.add("icon-green");
                iconEl.textContent = "✅";
              }
              const dotEl = overdueCard.querySelector(".badge-status-dot");
              if (dotEl) {
                dotEl.classList.remove("dot-red");
                dotEl.classList.add("dot-green");
              }
            }
          }
        }

        showToast(`${task.activity_label} marked as completed in Batch Management`, "success");
      })
      .catch((err) => {
        if (confirmBtn) {
          confirmBtn.disabled = false;
          confirmBtn.textContent = "Yes, mark complete";
        }
        closeCompleteModal();
        showToast(`Failed to update task: ${err.message}`, "error");
      });
  }

  function loadTaskData(roleFilter) {
    if (!roleFilter) {
      const urlParams = new URLSearchParams(window.location.search);
      roleFilter = activeRoleFilter || urlParams.get("role") || "all";
    }
    activeRoleFilter = roleFilter;

    const root = document.getElementById("task-dashboard-root");
    if (!root) {
      return;
    }

    // Show smooth modern loader if not yet rendered
    if (!currentTaskData) {
      root.innerHTML = `
        <div style="padding: 60px 20px; text-align: center; color: #64748b;">
          <div style="font-size: 32px; margin-bottom: 12px; animation: spin 1.5s linear infinite; display: inline-block;">⚡</div>
          <div style="font-size: 15px; font-weight: 700; color: #0f172a;">Loading task dashboard...</div>
          <div style="font-size: 13px; color: #94a3b8; margin-top: 4px;">Synchronizing calendar and course activities</div>
        </div>
      `;
    }

    const url = `index.php?action=get_task_data&view_role=${encodeURIComponent(roleFilter)}&_t=${Date.now()}`;
    fetch(url, {
      headers: { "X-Requested-With": "XMLHttpRequest" },
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.error) {
          root.innerHTML = `<div class="task-empty" style="color:#ef4444;"><div class="task-empty-icon">⚠️</div><div>Error loading tasks: ${escapeHtml(data.error)}</div></div>`;
          return;
        }
        renderDashboard(data);
      })
      .catch((err) => {
        root.innerHTML = `<div class="task-empty" style="color:#ef4444;"><div class="task-empty-icon">⚠️</div><div>Failed to load tasks: ${escapeHtml(err.message)}</div></div>`;
      });
  }

  function escapeHtml(text) {
    if (!text) return "";
    return String(text)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  // Initialize when DOM is ready
  document.addEventListener("DOMContentLoaded", function () {
    const taskRoot = document.getElementById("task-dashboard-root");
    if (taskRoot) {
      loadTaskData(activeRoleFilter);
    }

    // Top-level tab click listener to refresh tasks when Task tab is activated
    document.querySelectorAll('.ba-top-nav-tab[data-top-tab="task"]').forEach((tab) => {
      tab.addEventListener("click", function () {
        if (!currentTaskData) {
          loadTaskData(activeRoleFilter);
        }
      });
    });
  });
})();
