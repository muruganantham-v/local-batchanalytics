document.addEventListener("DOMContentLoaded", () => {
  const app = document.getElementById("ba-maac-import-app");
  if (!app) return;

  const sesskey = app.dataset.sesskey || "";
  const courses = JSON.parse(app.dataset.courses || "[]");
  const columns = JSON.parse(app.dataset.columns || "[]");
  const baseUrl = window.location.href.split("?")[0];
  const courseIds = courses.map((course) => course.id).join(",");
  let sheets = {};
  let selectedFile = null;

  const escapeHtml = (value) => String(value).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  const options = (items, selected = "") => items.map((item) => `<option value="${escapeHtml(item)}"${item === selected ? " selected" : ""}>${escapeHtml(item)}</option>`).join("");
  const icon = `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 16V4"></path><path d="m8 8 4-4 4 4"></path><path d="M4 16.5V20h16v-3.5"></path></svg>`;
  const formatFileSize = (bytes) => bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;

  function courseCard(course, sheetNames) {
    return `<article class="ba-import-course-card ba-maac-ticket-panel" data-course-id="${course.id}">
      <button type="button" class="ba-import-course-toggle" aria-expanded="false"><span>${escapeHtml(course.name)}</span><span class="ba-import-course-chevron" aria-hidden="true">&#9662;</span></button>
      <div class="ba-import-course-content" hidden>
        <div class="ba-mentor-grid ba-import-course-controls">
          <div class="ba-mentor-field-row"><div class="ba-mentor-field-label">Worksheet</div><div class="ba-mentor-field-value"><select class="ba-range-input ba-import-sheet"><option value="">Select worksheet</option>${options(sheetNames)}</select></div></div>
          <div class="ba-mentor-field-row"><div class="ba-mentor-field-label">Student Mapping</div><div class="ba-mentor-field-value"><select class="ba-range-input ba-import-username"><option value="">Select Username column</option></select></div></div>
        </div>
        <div class="ba-import-fields"></div>
      </div>
    </article>`;
  }

  function render(message = "") {
    const sheetNames = Object.keys(sheets);
    app.innerHTML = `<div class="ba-course-header-card"><div class="ba-ch-content"><div class="ba-ch-left"><h2 class="ba-ch-title">Import MAAC Sheet</h2><div class="ba-ch-meta"><span class="ba-ch-badge avg">${courses.length} Courses Selected</span></div></div></div></div>
      <section class="ba-filter-section ba-import-section"><div class="ba-filter-header"><h3>1. Upload and Analyze</h3></div><div class="ba-filter-body ba-import-upload-body"><div class="ba-import-upload-layout">
        <div class="ba-import-upload-card"><div class="ba-import-upload-head"><span class="ba-import-upload-icon">${icon}</span><div><strong>Upload files</strong><small>Select and upload the MAAC sheet</small></div></div>
          ${selectedFile ? `<div class="ba-import-file-summary"><div><strong>${escapeHtml(selectedFile.name)}</strong><small>${formatFileSize(selectedFile.size)}</small><span>File uploaded successfully</span></div><button type="button" class="ba-import-file-clear" id="ba-import-clear" aria-label="Clear selected file">Clear</button></div>` : `<div class="ba-import-dropzone" id="ba-import-dropzone" tabindex="0"><span class="ba-import-dropzone-icon">${icon}</span><strong>Choose a file or drag &amp; drop it here</strong><small>XLSX or CSV, up to 10 MB</small><button type="button" class="ba-btn ba-btn-view" id="ba-import-browse">Browse File</button></div>`}
          <input type="file" id="ba-import-file" accept=".xlsx,.csv" hidden>
        </div>${selectedFile ? `<div class="ba-import-actions"><button class="ba-btn ba-btn-primary" id="ba-import-analyze">Analyze Sheet</button></div>` : ""}<div id="ba-import-message">${escapeHtml(message)}</div></div>
      </div></section>
      ${sheetNames.length ? `<section class="ba-filter-section ba-import-section"><div class="ba-filter-header"><h3>2. Map Courses and Fields</h3><button class="ba-btn ba-btn-success" id="ba-import-submit">Import and Replace</button></div><div class="ba-filter-body ba-import-course-list">${courses.map((course) => courseCard(course, sheetNames)).join("")}</div></section>` : ""}`;
    bind();
  }

  function renderFields(card) {
    const sheet = sheets[card.querySelector(".ba-import-sheet").value];
    const headers = sheet?.headers || [];
    const username = card.querySelector(".ba-import-username");
    username.innerHTML = `<option value="">Select Username column</option>${options(headers, headers.find((header) => header.toLowerCase() === "username") || "")}`;
    card.querySelector(".ba-import-fields").innerHTML = headers.length ? `<div class="ba-import-mapping-title">Column Mapping</div><div class="ba-mentor-grid ba-import-mentor-grid">${columns.map((column) => `<div class="ba-mentor-field-row"><div class="ba-mentor-field-label">${escapeHtml(column.label)}</div><div class="ba-mentor-field-value"><select class="ba-range-input ba-import-field" data-key="${escapeHtml(column.key)}"><option value="">Do not import</option>${options(headers, headers.includes(column.label) ? column.label : "")}</select></div></div>`).join("")}</div>` : "";
  }

  function closeImportModal() {
    document.getElementById("ba-import-modal")?.remove();
  }

  function showImportReview(preview, payload) {
    const courseDetails = preview.courses.map((plan) => {
      const course = courses.find((item) => Number(item.id) === Number(plan.courseid));
      const map = payload.courses[String(plan.courseid)] || {};
      const existingValues = Number(plan.existingvalues || 0);
      const notFound = Number(plan.unmatched || 0);
      const missing = Number(plan.missingusernames || 0);
      return `<div class="ba-import-review-course"><strong>${escapeHtml(course?.name || "Course")}</strong><span>Worksheet: ${escapeHtml(map.sheet || "-")}</span><span>Student Mapping: ${escapeHtml(map.username || "-")}</span><span>${plan.rows} students found</span>${existingValues ? `<span>Will clear or replace ${existingValues} existing values</span>` : ""}${notFound ? `<span>${notFound} students not found</span>` : ""}${missing ? `<span>${missing} students ${escapeHtml(plan.usernameheader || "Username")} missing</span>` : ""}</div>`;
    }).join("");
    const skipped = preview.skipped?.length ? `<p class="ba-import-review-note">${preview.skipped.length} course(s) without mapping will be skipped.</p>` : "";
    document.body.insertAdjacentHTML("beforeend", `<div class="ba-modal-overlay" id="ba-import-modal"><div class="ba-modal-container ba-import-modal-dialog"><div class="ba-modal-header"><h3>Review MAAC Import</h3><button type="button" class="ba-modal-close" id="ba-import-modal-close" aria-label="Close">&times;</button></div><div class="ba-modal-body ba-import-modal-body"><p>Review the data below. Existing MAAC values will be updated only for matched students and mapped fields.</p><div class="ba-import-review-list">${courseDetails}</div>${skipped}</div><div class="ba-import-modal-actions"><button type="button" class="ba-btn ba-btn-view" id="ba-import-modal-cancel">Cancel</button><button type="button" class="ba-btn ba-btn-success" id="ba-import-modal-confirm">Import and Replace</button></div></div></div>`);
    document.getElementById("ba-import-modal-close")?.addEventListener("click", closeImportModal);
    document.getElementById("ba-import-modal-cancel")?.addEventListener("click", closeImportModal);
    document.getElementById("ba-import-modal-confirm")?.addEventListener("click", () => importMappedCourses(payload));
  }

  function showImportSuccess(data) {
    closeImportModal();
    const skipped = data.skipped?.length ? ` ${data.skipped.length} course(s) were skipped.` : "";
    document.body.insertAdjacentHTML("beforeend", `<div class="ba-modal-overlay" id="ba-import-modal"><div class="ba-modal-container ba-import-modal-dialog ba-import-success-dialog"><div class="ba-modal-header"><h3>Import Completed</h3></div><div class="ba-modal-body ba-import-modal-body"><p>MAAC data was imported for ${data.courses.length} course(s).${skipped}</p><p class="ba-import-redirect-text">Returning to Batch Analytics in <strong id="ba-import-countdown">5</strong> seconds.</p><div class="ba-import-progress"><span id="ba-import-progress-bar"></span></div></div></div></div>`);
    const started = Date.now();
    const timer = setInterval(() => {
      const elapsed = Math.min(Date.now() - started, 5000);
      document.getElementById("ba-import-progress-bar").style.width = `${(elapsed / 5000) * 100}%`;
      document.getElementById("ba-import-countdown").textContent = String(Math.max(0, Math.ceil((5000 - elapsed) / 1000)));
      if (elapsed === 5000) {
        clearInterval(timer);
        window.location.assign("index.php");
      }
    }, 50);
  }

  async function importMappedCourses(payload) {
    const confirm = document.getElementById("ba-import-modal-confirm");
    if (confirm) {
      confirm.disabled = true;
      confirm.textContent = "Importing...";
    }
    try {
      const data = await request("import", new URLSearchParams({ payload: JSON.stringify(payload) }));
      showImportSuccess(data);
    } catch (error) {
      const body = document.querySelector("#ba-import-modal .ba-import-modal-body");
      if (body) body.insertAdjacentHTML("beforeend", `<p class="ba-import-review-error">${escapeHtml(error.message)}</p>`);
      if (confirm) {
        confirm.disabled = false;
        confirm.textContent = "Import and Replace";
      }
    }
  }

  async function request(action, body) {
    const response = await fetch(`${baseUrl}?courses=${encodeURIComponent(courseIds)}&action=${action}&sesskey=${encodeURIComponent(sesskey)}`, { method: "POST", body });
    const text = await response.text();
    const start = text.lastIndexOf("{");
    let data;
    try { data = JSON.parse(text); } catch (_error) { data = start >= 0 ? JSON.parse(text.slice(start).trim()) : { error: "Import request returned an invalid response. Check the Moodle error log." }; }
    if (!response.ok || data.error) throw new Error(data.error || "Import request failed.");
    return data;
  }

  function bind() {
    const fileInput = document.getElementById("ba-import-file");
    const dropzone = document.getElementById("ba-import-dropzone");
    const selectFile = (file) => { if (file) { selectedFile = file; render("File selected. Click Analyze Sheet to continue."); } };
    document.getElementById("ba-import-browse")?.addEventListener("click", () => fileInput?.click());
    document.getElementById("ba-import-clear")?.addEventListener("click", () => { selectedFile = null; render(""); });
    fileInput?.addEventListener("change", () => selectFile(fileInput.files?.[0]));
    dropzone?.addEventListener("dragover", (event) => { event.preventDefault(); dropzone.classList.add("is-dragging"); });
    dropzone?.addEventListener("dragleave", () => dropzone.classList.remove("is-dragging"));
    dropzone?.addEventListener("drop", (event) => { event.preventDefault(); dropzone.classList.remove("is-dragging"); selectFile(event.dataTransfer.files?.[0]); });
    document.getElementById("ba-import-analyze")?.addEventListener("click", async () => {
      const analyzeButton = document.getElementById("ba-import-analyze");
      analyzeButton.disabled = true;
      analyzeButton.textContent = "Analyzing...";
      const form = new FormData(); form.append("sheet", selectedFile);
      try { const data = await request("analyze", form); sheets = data.sheets || {}; render("Sheet analyzed. Map the courses you want to import."); } catch (error) { analyzeButton.disabled = false; analyzeButton.textContent = "Analyze Sheet"; document.getElementById("ba-import-message").textContent = error.message; }
    });
    app.querySelectorAll(".ba-import-course-toggle").forEach((toggle) => toggle.addEventListener("click", () => {
      const content = toggle.nextElementSibling;
      const expanded = toggle.getAttribute("aria-expanded") === "true";
      toggle.setAttribute("aria-expanded", String(!expanded));
      toggle.closest(".ba-import-course-card").classList.toggle("is-expanded", !expanded);
      content.hidden = expanded;
    }));
    app.querySelectorAll(".ba-import-sheet").forEach((select) => select.addEventListener("change", () => renderFields(select.closest("[data-course-id]"))));
    document.getElementById("ba-import-submit")?.addEventListener("click", async () => {
      const payload = { courses: {} };
      app.querySelectorAll("[data-course-id]").forEach((card) => { const fields = {}; card.querySelectorAll(".ba-import-field").forEach((field) => { fields[field.dataset.key] = field.value; }); payload.courses[card.dataset.courseId] = { sheet: card.querySelector(".ba-import-sheet").value, username: card.querySelector(".ba-import-username").value, fields }; });
      try { const preview = await request("preview", new URLSearchParams({ payload: JSON.stringify(payload) })); showImportReview(preview, payload); } catch (error) { document.getElementById("ba-import-message").textContent = error.message; }
    });
  }
  render();
});
