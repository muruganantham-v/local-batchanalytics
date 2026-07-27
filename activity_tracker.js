document.addEventListener("DOMContentLoaded", () => {
  const wrap = document.querySelector(".local-batchanalytics-activity-tracker");
  const app = document.getElementById("ba-activity-tracker-app");
  if (!wrap || !app) return;

  const courseId = wrap.dataset.courseid || "0";
  const sesskey = wrap.dataset.sesskey || "";
  const canEdit = wrap.dataset.canedit === "1";
  const baseUrl = window.location.href.split("?")[0];
  const state = { data: null, activeCategory: "", saving: new Set(), message: "" };

  const escapeHtml = (value) => String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");

  function getActiveCategory() {
    const categories = state.data?.categories || [];
    return categories.find((category) => String(category.id) === String(state.activeCategory)) || categories[0] || null;
  }

  function getTodayDate() {
    const now = new Date();
    const localNow = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
    return localNow.toISOString().slice(0, 10);
  }

  async function parseJsonResponse(response, fallbackMessage) {
    const text = await response.text();
    try {
      return text ? JSON.parse(text) : {};
    } catch (error) {
      throw new Error(fallbackMessage);
    }
  }

  function render(tableScrollTop = null) {
    const course = state.data?.course || {};
    const categories = state.data?.categories || [];
    const activeCategory = getActiveCategory();
    const courseUrl = wrap.dataset.courseurl || "";
    const maacUrl = wrap.dataset.maacurl || "";

    app.innerHTML = `
      <div class="ba-course-header-card ba-module-tracker-header">
        <div class="ba-ch-content">
          <div class="ba-ch-left">
            <h2 class="ba-ch-title">Module tracker: ${escapeHtml(course.fullname || "")}</h2>
            <div class="ba-module-tracker-pills">
              ${categories.map((category) => `<span class="ba-ch-badge avg"><strong>${escapeHtml(category.name)}:</strong> Completed ${category.completed} | Pending ${category.pending}</span>`).join("") || '<span class="ba-ch-badge avg">No Gradebook categories found</span>'}
            </div>
          </div>
          <div class="ba-ch-right ba-module-tracker-actions">
            ${courseUrl ? `<a class="ba-btn ba-btn-view" href="${escapeHtml(courseUrl)}" target="_blank" rel="noopener">View Course <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg></a>` : ""}
            ${maacUrl ? `<a class="ba-btn ba-btn-sm ba-btn-view" href="${escapeHtml(maacUrl)}">MAAC Sheet</a>` : ""}
          </div>
        </div>
      </div>
      <section class="ba-module-tracker-card">
        <div class="ba-module-tracker-tabs" role="tablist">
          ${categories.map((category) => `<button type="button" class="ba-module-tracker-tab${activeCategory && category.id === activeCategory.id ? " is-active" : ""}" data-category-id="${category.id}" role="tab">${escapeHtml(category.name)} <span>${category.activities.length}</span></button>`).join("")}
        </div>
        <div class="ba-module-tracker-status" role="status">${escapeHtml(state.message)}</div>
        ${renderActivityTable(activeCategory)}
      </section>`;

    app.querySelectorAll("[data-category-id]").forEach((button) => {
      button.addEventListener("click", () => {
        state.activeCategory = button.dataset.categoryId;
        state.message = "";
        render();
      });
    });
    app.querySelectorAll("[data-activity-status]").forEach((input) => {
      input.addEventListener("change", () => {
        const dateInput = app.querySelector(`[data-activity-date][data-cmid="${input.dataset.cmid}"]`);
        if (input.checked && dateInput && !dateInput.value) {
          dateInput.value = getTodayDate();
        }
        saveActivity(input.dataset.cmid);
      });
    });
    app.querySelectorAll("[data-activity-date]").forEach((input) => {
      input.addEventListener("change", () => saveActivity(input.dataset.cmid));
    });
    if (typeof tableScrollTop === "number") {
      const tableWrap = app.querySelector(".ba-module-tracker-table-wrap");
      if (tableWrap) {
        tableWrap.scrollTop = tableScrollTop;
      }
    }
  }

  function renderActivityTable(category) {
    if (!category) {
      return '<div class="ba-module-tracker-empty">No graded activities are available in this course.</div>';
    }
    if (!category.activities.length) {
      return '<div class="ba-module-tracker-empty">No activities are available in this category.</div>';
    }
    return `<div class="ba-table-wrap ba-module-tracker-table-wrap"><table class="ba-table ba-module-tracker-table">
      <thead><tr><th>Activity Name</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>${category.activities.map((activity) => {
        const saving = state.saving.has(String(activity.cmid));
        return `<tr>
          <td><strong>${escapeHtml(activity.name)}</strong></td>
          <td><label class="ba-module-tracker-check"><input type="checkbox" data-activity-status="1" data-cmid="${activity.cmid}"${activity.completed ? " checked" : ""}${saving || !canEdit ? " disabled" : ""}><span>Completed</span></label></td>
          <td><input type="date" class="ba-maac-input ba-module-tracker-date" data-activity-date="1" data-cmid="${activity.cmid}" value="${escapeHtml(activity.completiondate || "")}"${activity.completed && canEdit && !saving ? "" : " disabled"}></td>
        </tr>`;
      }).join("")}</tbody>
    </table></div>`;
  }

  async function saveActivity(cmid) {
    const category = getActiveCategory();
    const activity = category?.activities.find((item) => String(item.cmid) === String(cmid));
    if (!activity || state.saving.has(String(cmid))) return;

    const statusInput = app.querySelector(`[data-activity-status][data-cmid="${cmid}"]`);
    const dateInput = app.querySelector(`[data-activity-date][data-cmid="${cmid}"]`);
    const completed = Boolean(statusInput?.checked);
    const completiondate = completed ? (dateInput?.value || "") : "";
    const tableScrollTop = app.querySelector(".ba-module-tracker-table-wrap")?.scrollTop || 0;
    state.saving.add(String(cmid));
    state.message = "Saving...";
    render(tableScrollTop);

    try {
      const body = new URLSearchParams({
        courseid: String(courseId),
        action: "save",
        sesskey,
        cmid: String(cmid),
        completed: completed ? "1" : "0",
        completiondate,
      });
      const response = await fetch(baseUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
      });
      const result = await parseJsonResponse(response, "Unable to save activity status. Please check the Moodle error log.");
      if (!response.ok || result.error) throw new Error(result.error || "Unable to save activity status.");

      activity.completed = Boolean(result.completed);
      activity.completiondate = result.completiondate || "";
      category.completed = category.activities.filter((item) => item.completed).length;
      category.pending = category.activities.length - category.completed;
      state.message = "Activity status saved.";
    } catch (error) {
      state.message = error.message || "Unable to save activity status.";
    } finally {
      state.saving.delete(String(cmid));
      render(tableScrollTop);
    }
  }

  async function load() {
    app.innerHTML = '<div class="ba-module-tracker-loading">Loading Module Tracker...</div>';
    try {
      const body = new URLSearchParams({ courseid: String(courseId), action: "getdata", sesskey });
      const response = await fetch(baseUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
      });
      const result = await parseJsonResponse(response, "Unable to load Module Tracker. Please check the Moodle error log.");
      if (!response.ok || result.error) throw new Error(result.error || "Unable to load Module Tracker.");
      state.data = result;
      state.activeCategory = String(result.categories?.[0]?.id || "");
      render();
    } catch (error) {
      app.innerHTML = `<div class="ba-module-tracker-empty">${escapeHtml(error.message || "Unable to load Module Tracker.")}</div>`;
    }
  }

  load();
});
