/**
 * Task Dashboard JavaScript
 *
 * Handles task management by user role:
 * 1. Program Manager, SS Executive, SS Team: Assigned in Class Section, soft skills planned vs actual, due date from planned date
 * 2. Mentor: Enrolled courses, activity due date or calendar due tasks
 * 3. Assistant Manager: Tasks displayed based on start date of Class Section
 * 4. All: Combined portfolio view
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function () {
  "use strict";

  let currentTaskData = null;
  let pendingTaskToComplete = null;
  let activeRoleFilter = "pm_ss";
  let currentTodoFilter = "due_first";

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
    const all = [...currentTaskData.todos];
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
    const initialTodos = getFilteredAndSortedTodos();
    const pendingTotal = (data.todos || []).filter((t) => !t.completed).length;

    // Determine current selected role
    const currentRole = roleInfo.current_role || activeRoleFilter;
    activeRoleFilter = currentRole;

    root.innerHTML = `
      <div class="task-dashboard-wrap">
        <div class="task-greet">
          <div>
            <h1 id="taskGreetingTitle">${greetingText}</h1>
            <div class="task-sub" id="taskRoleSub">${escapeHtml(roleInfo.subtitle || "Portfolio Overview")}</div>
          </div>
          <div class="task-role-switcher-box">
            <label for="taskRoleSelect" class="task-role-switcher-label">
              <span class="task-role-icon">👤</span> Switch Role:
            </label>
            <select id="taskRoleSelect" class="task-role-dropdown" aria-label="Switch User Role">
              <option value="pm_ss" ${currentRole === "pm_ss" ? "selected" : ""}>📋 Program Manager, SS Executive, SS Team</option>
              <option value="mentor" ${currentRole === "mentor" ? "selected" : ""}>🎓 Mentor (Enrolled Courses)</option>
              <option value="am" ${currentRole === "am" ? "selected" : ""}>🏢 Assistant Manager (Section Start Dates)</option>
              <option value="all" ${currentRole === "all" ? "selected" : ""}>🌐 All Roles (Combined Overview)</option>
            </select>
          </div>
        </div>

        <!-- 4 Glance Stat Cards in New Batch Analytics Style -->
        <div class="ba-stats-row-bottom task-glance-grid" id="taskGlance">
          <div class="ba-new-stat-card card-blue">
            <div class="ba-new-stat-header">
              <span class="ba-new-stat-title">Tasks Due This Week</span>
              <span class="ba-new-stat-icon icon-blue">📅</span>
            </div>
            <div class="ba-new-stat-value" id="taskGlanceDueWeek">${glance.due_this_week}</div>
            <div class="ba-new-stat-footer"><span class="badge-status-dot dot-blue"></span> Next 7 calendar days</div>
          </div>

          <div class="ba-new-stat-card ${glance.overdue > 0 ? "card-red" : "card-green"}" id="taskGlanceOverdueCard">
            <div class="ba-new-stat-header">
              <span class="ba-new-stat-title">Overdue Tasks</span>
              <span class="ba-new-stat-icon ${glance.overdue > 0 ? "icon-red" : "icon-green"}">${glance.overdue > 0 ? "⚠️" : "✅"}</span>
            </div>
            <div class="ba-new-stat-value ${glance.overdue > 0 ? "text-danger" : "text-success"}" id="taskGlanceOverdue">${glance.overdue}</div>
            <div class="ba-new-stat-footer"><span class="badge-status-dot ${glance.overdue > 0 ? "dot-red" : "dot-green"}"></span> ${glance.overdue > 0 ? "Requires immediate action" : "All tasks on schedule"}</div>
          </div>

          <div class="ba-new-stat-card card-purple">
            <div class="ba-new-stat-header">
              <span class="ba-new-stat-title">${currentRole === "mentor" ? "Active Courses" : "Assigned Batches"}</span>
              <span class="ba-new-stat-icon icon-purple">${currentRole === "mentor" ? "📚" : "📁"}</span>
            </div>
            <div class="ba-new-stat-value" id="taskGlanceBatches">${glance.batches}</div>
            <div class="ba-new-stat-footer"><span class="badge-status-dot dot-purple"></span> Active in portfolio</div>
          </div>

          <div class="ba-new-stat-card card-orange">
            <div class="ba-new-stat-header">
              <span class="ba-new-stat-title">Total Students</span>
              <span class="ba-new-stat-icon icon-orange">👥</span>
            </div>
            <div class="ba-new-stat-value" id="taskGlanceStudents">${glance.students}</div>
            <div class="ba-new-stat-footer"><span class="badge-status-dot dot-orange"></span> Enrolled learners</div>
          </div>
        </div>

        <div class="task-cols">
          <!-- MY TO-DO PANEL -->
          <div class="ba-new-card task-dpanel">
            <div class="task-ph">
              <h2>
                <span class="task-ph-bar"></span>
                My To-Do
                <span class="ba-controls-badge" id="taskTodoCount">${pendingTotal} pending</span>
              </h2>
              <div class="task-filter-wrap">
                <select id="taskTodoFilter" class="task-filter-select" aria-label="Filter and sort tasks">
                  <option value="due_first">Due first</option>
                  <option value="latest_first">Latest date first</option>
                  <option value="earliest_first">Earliest date first</option>
                  <option value="overdue_only">Overdue only</option>
                  <option value="due_today">Due today</option>
                  <option value="due_week">Due this week</option>
                  <option value="all">All tasks</option>
                </select>
              </div>
            </div>
            <div id="taskTodoList">
              ${renderTodoList(initialTodos)}
            </div>
          </div>

          <!-- FORTHCOMING PANEL -->
          <div class="ba-new-card task-dpanel">
            <div class="task-ph">
              <h2>
                <span class="task-ph-bar"></span>
                Forthcoming
              </h2>
              <span class="task-filter">Upcoming Schedule</span>
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
          <p>This confirms the soft skill activity is done and updates the actual completion date in Batch Management. It cannot be undone from here.</p>
          <div class="task-act-name" id="taskModalActName">—</div>
          <div class="task-mbtns">
            <button type="button" class="task-btn-cancel" id="taskModalBtnCancel">Cancel</button>
            <button type="button" class="task-btn-confirm" id="taskModalBtnConfirm">Yes, mark complete</button>
          </div>
        </div>
      </div>
    `;

    // Bind modal actions, todo item actions, filter dropdown, and role switcher
    bindModalEvents();
    bindTodoItemEvents();
    bindFilterEvents();
    bindRoleEvents();
  }

  function bindRoleEvents() {
    const roleSelect = document.getElementById("taskRoleSelect");
    if (!roleSelect) return;

    roleSelect.addEventListener("change", function () {
      activeRoleFilter = this.value;
      loadTaskData(activeRoleFilter);
    });
  }

  function bindFilterEvents() {
    const filterSelect = document.getElementById("taskTodoFilter");
    if (!filterSelect) return;

    filterSelect.value = currentTodoFilter;
    filterSelect.addEventListener("change", function () {
      currentTodoFilter = this.value;
      updateTodoList();
    });
  }

  function renderTodoList(todos) {
    if (!todos || !todos.length) {
      if (currentTodoFilter === "overdue_only") {
        return '<div class="task-empty">✅ Great job! No overdue tasks at this time.</div>';
      } else if (currentTodoFilter === "due_today") {
        return '<div class="task-empty">✅ No tasks due today.</div>';
      } else if (currentTodoFilter === "due_week") {
        return '<div class="task-empty">✅ No tasks due this week.</div>';
      }
      return '<div class="task-empty">🎉 All caught up! No pending tasks at the moment.</div>';
    }

    return todos
      .map((t) => {
        const taskKey = `${t.section_id || t.course_id}_${t.activity_key}`;
        const batchUrl = t.batch_id ? `batch.php?batchid=${encodeURIComponent(t.batch_id)}` : "#";
        const isDone = Boolean(t.completed);

        let metaHtml = "";
        if (t.role_type === "mentor") {
          metaHtml = `
            <span class="task-m-batch">Course: ${escapeHtml(t.course_name || "")}</span>
            <span class="task-m-dot">·</span>
            <span class="task-m-sec">Activity Due Date</span>
          `;
        } else if (t.role_type === "am") {
          metaHtml = `
            <a href="${batchUrl}" class="task-m-batch">Batch ${escapeHtml(t.batch_name || "")}</a>
            <span class="task-m-dot">·</span>
            <span class="task-m-sec">Section ${escapeHtml(t.section_name || "")}</span>
            <span class="task-m-dot">·</span>
            <span class="task-meta-subtext">Section Start: <strong>${escapeHtml(t.section_start_date_formatted || "—")}</strong></span>
          `;
        } else {
          metaHtml = `
            <a href="${batchUrl}" class="task-m-batch">Batch ${escapeHtml(t.batch_name || "")}</a>
            <span class="task-m-dot">·</span>
            <span class="task-m-sec">Section ${escapeHtml(t.section_name || "")}</span>
            <span class="task-m-dot">·</span>
            <span class="task-meta-subtext">Soft Skill Milestone</span>
          `;
        }

        let actionHtml = "";
        if (t.can_complete) {
          actionHtml = isDone
            ? '<span class="task-done-tag">✓ Done</span>'
            : `<button type="button" class="task-mc-btn" data-action="ask-complete" data-taskkey="${escapeHtml(taskKey)}">Mark Complete</button>`;
        } else if (t.action_url) {
          actionHtml = `<a href="${escapeHtml(t.action_url)}" target="_blank" class="task-btn-action">${escapeHtml(t.action_label || "View")} ↗</a>`;
        }

        return `
          <div class="task-todo-item ${isDone ? "done" : ""}" data-taskid="${escapeHtml(taskKey)}">
            <div class="task-body">
              <div class="task-t-wrap">
                <span class="task-t">${escapeHtml(t.activity_label)}</span>
                <div class="task-badges-group">
                  <span class="task-due ${escapeHtml(t.due_class || "soon")}">
                    ${escapeHtml(t.due_text)}
                  </span>
                  <span class="task-date-highlight">
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
      return '<div class="task-empty">📅 No forthcoming activities scheduled in this period.</div>';
    }

    return forthcoming
      .map((item) => {
        let metaHtml = "";
        if (item.role_type === "mentor") {
          metaHtml = `
            <span class="task-m-batch">Course: ${escapeHtml(item.course_name || "")}</span>
            <span class="task-m-dot">·</span>
            <span class="task-fc-rel-text">${escapeHtml(item.time_relative || "")}</span>
          `;
        } else if (item.role_type === "am") {
          metaHtml = `
            <span class="task-m-batch">Batch ${escapeHtml(item.batch_name || "")}</span>
            <span class="task-m-dot">·</span>
            <span class="task-m-sec">Section ${escapeHtml(item.section_name || "")}</span>
            <span class="task-m-dot">·</span>
            <span class="task-fc-rel-text">${escapeHtml(item.time_relative || "")}</span>
          `;
        } else {
          metaHtml = `
            <span class="task-m-batch">Batch ${escapeHtml(item.batch_name || "")}</span>
            <span class="task-m-dot">·</span>
            <span class="task-m-sec">Section ${escapeHtml(item.section_name || "")}</span>
            <span class="task-m-dot">·</span>
            <span class="task-fc-rel-text">${escapeHtml(item.time_relative || "")}</span>
          `;
        }

        return `
          <div class="task-fc-item">
            <div class="task-t-wrap">
              <span class="task-t">${escapeHtml(item.activity_label)}</span>
              <span class="task-date-highlight fc-date">
                <span class="task-cal-icon">📅</span>
                <strong class="task-date-val">${escapeHtml(item.planned_date_formatted)}</strong>
              </span>
            </div>
            <div class="task-m">
              ${metaHtml}
            </div>
          </div>
        `;
      })
      .join("");
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
    roleFilter = roleFilter || activeRoleFilter;
    if (!roleFilter || roleFilter === "auto") {
      roleFilter = "pm_ss";
    }
    activeRoleFilter = roleFilter;

    const root = document.getElementById("task-dashboard-root");
    if (!root) {
      return;
    }

    // Show smooth loader if not yet rendered
    if (!currentTaskData) {
      root.innerHTML = `
        <div style="padding: 40px; text-align: center; color: #64748b;">
          <div style="font-size: 24px; margin-bottom: 8px;">⏳</div>
          <div style="font-size: 14px; font-weight: 600;">Loading tasks from batch calendar...</div>
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
          root.innerHTML = `<div class="task-empty" style="color:#ef4444;">Error loading tasks: ${escapeHtml(data.error)}</div>`;
          return;
        }
        renderDashboard(data);
      })
      .catch((err) => {
        root.innerHTML = `<div class="task-empty" style="color:#ef4444;">Failed to load tasks: ${escapeHtml(err.message)}</div>`;
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
