/**
 * Frontend script for Batch Analytics Operational Dashboard Block
 * Supports Mentor, SS Executive, Program Manager, and Assistant Manager personas.
 * Handles client-side pagination, filters, and activity completion.
 */
(function() {
  'use strict';

  var container = null;
  var apiUrl = '';
  var sesskey = '';
  var currentFilter = 'all';
  var currentBatch = 'all';
  var allTodos = [];
  var activeRole = 'admin';
  var pendingTask = null;

  // Pagination state
  var pageSize = 5;
  var currentPage = 1;

  function init() {
    container = document.getElementById('ba-task-dash-container');
    if (!container) return;

    apiUrl = container.getAttribute('data-api-url') || '';
    sesskey = container.getAttribute('data-sesskey') || '';

    var batchSel = document.getElementById('ba-batch-filter');
    if (batchSel) {
      batchSel.addEventListener('change', function() {
        currentBatch = this.value;
        currentPage = 1;
        renderTodoList();
      });
    }

    var filterSel = document.getElementById('ba-todo-filter');
    if (filterSel) {
      filterSel.addEventListener('change', function() {
        currentFilter = this.value;
        currentPage = 1;
        renderTodoList();
      });
    }

    // Delegated click listener for Mark Complete buttons
    container.addEventListener('click', function(e) {
      var btn = e.target.closest('button.mc-btn2[data-task-id]');
      if (btn) {
        e.preventDefault();
        var tid = btn.getAttribute('data-task-id');
        openCompleteModal(tid);
      }
    });

    // Modal listeners
    var overlay = document.getElementById('ba-task-modal-overlay');
    var btnCancel = document.getElementById('ba-modal-btn-cancel');
    var btnConfirm = document.getElementById('ba-modal-btn-confirm');

    var btnClose = document.getElementById('ba-modal-btn-close');
    if (btnClose) {
      btnClose.addEventListener('click', closeModal);
    }
    if (btnCancel) {
      btnCancel.addEventListener('click', closeModal);
    }
    if (btnConfirm) {
      btnConfirm.addEventListener('click', confirmCompletion);
    }
    if (overlay) {
      overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeModal();
      });
    }

    var initDataEl = document.getElementById('ba-dash-initial-data');
    if (initDataEl && initDataEl.textContent.trim()) {
      try {
        var d = JSON.parse(initDataEl.textContent);
        applyData(d);
      } catch (e) {
        console.error('Error parsing initial dashboard data:', e);
        loadDashboardData();
      }
    } else {
      loadDashboardData();
    }
  }

  function loadDashboardData() {
    if (!apiUrl) return;

    var url = apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') +
      'action=get_dashboard_tasks' +
      '&sesskey=' + encodeURIComponent(sesskey);

    fetch(url, { credentials: 'same-origin' })
      .then(function(res) { return res.json(); })
      .then(function(resp) {
        if (resp && resp.success && resp.data) {
          applyData(resp.data);
        } else {
          console.error('Error fetching dashboard tasks:', resp);
        }
      })
      .catch(function(err) {
        console.error('Failed to load dashboard tasks:', err);
      });
  }

  function applyData(d) {
    // Greeting & Subtitle
    var elGreet = document.getElementById('ba-dash-greeting');
    if (elGreet && d.greeting) elGreet.textContent = d.greeting;

    var elSub = document.getElementById('ba-dash-rolesub');
    if (elSub && d.role_subtitle) elSub.textContent = d.role_subtitle;

    if (d && d.active_role) {
      activeRole = d.active_role;
    }

    // Glance cards
    var elGlance = document.getElementById('ba-dash-glance');
    if (elGlance && Array.isArray(d.glance)) {
      elGlance.innerHTML = d.glance.map(function(g) {
        var alertCls = g.alert ? ' alert' : '';
        return '<div class="gt' + alertCls + '">' +
          '<div class="v">' + escapeHtml(g.val) + '</div>' +
          '<div class="k">' + escapeHtml(g.lbl) + '</div>' +
          '</div>';
      }).join('');
    }

    // Batches filter options refresh
    var batchSel = document.getElementById('ba-batch-filter');
    if (batchSel) {
      var batches = Array.isArray(d.batches) ? d.batches : [];
      if (!batches.length && Array.isArray(d.todo)) {
        var map = {};
        d.todo.forEach(function(t) {
          var bid = (t.batchid != null && t.batchid !== '') ? String(t.batchid) : '';
          var bname = t.batch_name ? String(t.batch_name).trim() : '';
          if (bid && bname && !map[bid]) {
            map[bid] = true;
            batches.push({ id: t.batchid, name: bname });
          }
        });
        batches.sort(function(a, b) { return a.name.localeCompare(b.name); });
      }

      var cur = currentBatch;
      var bHtml = '<option value="all">All batches</option>';
      batches.forEach(function(b) {
        var isSel = (String(b.id) === String(cur)) ? ' selected' : '';
        bHtml += '<option value="' + escapeHtml(b.id) + '"' + isSel + '>' + escapeHtml(b.name) + '</option>';
      });
      batchSel.innerHTML = bHtml;
      if (cur !== 'all' && !batches.some(function(b) { return String(b.id) === String(cur); })) {
        currentBatch = 'all';
        batchSel.value = 'all';
      }
    }

    // Todos
    allTodos = Array.isArray(d.todo) ? d.todo : [];
    currentPage = 1;
    renderTodoList();

    // Forthcoming
    var elFc = document.getElementById('ba-dash-fc-list');
    if (elFc) {
      var fcItems = Array.isArray(d.forthcoming) ? d.forthcoming : [];
      if (!fcItems.length) {
        elFc.innerHTML = '<div class="empty-box">Nothing scheduled in the next 7 days</div>';
      } else {
        elFc.innerHTML = fcItems.map(function(f) {
          return '<div class="fc">' +
            '<div class="t">' + escapeHtml(f.title) + '</div>' +
            '<div class="m">' + escapeHtml(f.meta) + '</div>' +
            '</div>';
        }).join('');
      }
    }
  }

  function renderTodoList() {
    var elList = document.getElementById('ba-dash-todo-list');
    var elCount = document.getElementById('ba-dash-todo-count');
    var elPagination = document.getElementById('ba-dash-todo-pagination');
    if (!elList) return;

    var filtered = allTodos.filter(function(t) {
      if (t.is_done) return false;
      if (currentBatch !== 'all') {
        if (String(t.batchid) !== String(currentBatch)) {
          return false;
        }
      }
      if (currentFilter === 'overdue') return t.status_class === 'over';
      if (currentFilter === 'today') return t.status_class === 'today';
      if (currentFilter === 'soon') return t.status_class === 'soon';
      return true;
    });

    var totalPending = allTodos.filter(function(t) {
      if (t.is_done) return false;
      if (currentBatch !== 'all' && String(t.batchid) !== String(currentBatch)) {
        return false;
      }
      return true;
    }).length;
    if (elCount) {
      elCount.textContent = totalPending + ' pending';
    }

    if (!filtered.length) {
      elList.innerHTML = '<div class="empty-box">✓ No pending tasks matching this filter</div>';
      if (elPagination) elPagination.innerHTML = '';
      return;
    }

    // Calculate pagination
    var totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    var startIdx = (currentPage - 1) * pageSize;
    var endIdx = Math.min(startIdx + pageSize, filtered.length);
    var pageItems = filtered.slice(startIdx, endIdx);

    // Render list items
    elList.innerHTML = pageItems.map(function(t) {
      var destLabel = (t.dest_type === 'batch') ? 'Go to batch →' : ((t.dest_type === 'section') ? 'Go to class section →' : 'Go to module →');
      var actionBtnHtml = '';
      if (t.action_mode === 'redirect') {
        var btnLbl = t.btn_label || 'Update →';
        var actUrl = t.action_url || t.dest_url;
        actionBtnHtml = '<a href="' + escapeHtml(actUrl) + '" class="mc-btn2 mc-btn-link">' + escapeHtml(btnLbl) + '</a>';
      } else if (activeRole !== 'admin') {
        actionBtnHtml = '<button type="button" class="mc-btn2" data-task-id="' + escapeHtml(t.id) + '">Mark Complete</button>';
      }

      return '<div class="todo" id="todo-row-' + escapeHtml(t.id) + '">' +
        '<div class="body">' +
          '<div class="t">' + escapeHtml(t.title) + '</div>' +
          '<div class="m">' + escapeHtml(t.meta) + '</div>' +
          '<a href="' + escapeHtml(t.dest_url) + '" class="go">' + destLabel + '</a>' +
        '</div>' +
        '<div class="actions">' +
          '<span class="due ' + escapeHtml(t.status_class) + '">' + escapeHtml(t.status_label) + '</span>' +
          actionBtnHtml +
        '</div>' +
      '</div>';
    }).join('');

    // Render pagination controls
    if (elPagination) {
      renderPagination(elPagination, startIdx + 1, endIdx, filtered.length, totalPages);
    }
  }

  function renderPagination(container, start, end, total, totalPages) {
    if (total <= pageSize) {
      container.innerHTML = '<span class="ba-page-summary">Showing all ' + total + ' tasks</span>';
      return;
    }

    var html = '<span class="ba-page-summary">Showing ' + start + '–' + end + ' of ' + total + ' tasks</span>';
    html += '<div class="ba-page-btns">';

    // Prev button
    html += '<button type="button" class="ba-pbtn" id="ba-pbtn-prev"' + (currentPage <= 1 ? ' disabled' : '') + '>‹ Prev</button>';

    // Page 1
    html += '<button type="button" class="ba-pbtn ba-pnum' + (currentPage === 1 ? ' active' : '') + '" data-page="1">1</button>';

    // Left ellipsis
    if (currentPage > 2) {
      html += '<span class="ba-page-ellipsis">…</span>';
    }

    // Current page (if not 1 and not totalPages)
    if (currentPage > 1 && currentPage < totalPages) {
      html += '<button type="button" class="ba-pbtn ba-pnum active" data-page="' + currentPage + '">' + currentPage + '</button>';
    }

    // Right ellipsis
    if (currentPage < totalPages - 1) {
      html += '<span class="ba-page-ellipsis">…</span>';
    }

    // End page
    if (totalPages > 1) {
      html += '<button type="button" class="ba-pbtn ba-pnum' + (currentPage === totalPages ? ' active' : '') + '" data-page="' + totalPages + '">' + totalPages + '</button>';
    }

    // Next button
    html += '<button type="button" class="ba-pbtn" id="ba-pbtn-next"' + (currentPage >= totalPages ? ' disabled' : '') + '>Next ›</button>';
    html += '</div>';

    container.innerHTML = html;

    // Attach pagination events
    var btnPrev = container.querySelector('#ba-pbtn-prev');
    if (btnPrev && !btnPrev.disabled) {
      btnPrev.addEventListener('click', function() {
        if (currentPage > 1) {
          currentPage--;
          renderTodoList();
        }
      });
    }

    var btnNext = container.querySelector('#ba-pbtn-next');
    if (btnNext && !btnNext.disabled) {
      btnNext.addEventListener('click', function() {
        if (currentPage < totalPages) {
          currentPage++;
          renderTodoList();
        }
      });
    }

    var numBtns = container.querySelectorAll('.ba-pnum');
    numBtns.forEach(function(nb) {
      nb.addEventListener('click', function() {
        var page = parseInt(this.getAttribute('data-page'), 10);
        if (page && page !== currentPage) {
          currentPage = page;
          renderTodoList();
        }
      });
    });
  }

  function openCompleteModal(taskId) {
    var task = allTodos.find(function(t) { return t.id === taskId; });
    if (!task) return;

    pendingTask = task;
    var nameEl = document.getElementById('ba-modal-task-name');
    if (nameEl) {
      nameEl.textContent = task.title + ' (' + (task.meta || '') + ')';
    }

    var validBody = document.getElementById('ba-modal-validation-body');
    var btnConfirm = document.getElementById('ba-modal-btn-confirm');
    var btnGoto = document.getElementById('ba-modal-btn-goto');

    if (btnConfirm) {
      btnConfirm.disabled = true;
      btnConfirm.textContent = 'Confirm Complete';
      btnConfirm.title = 'Validating activity status...';
    }
    if (btnGoto) {
      btnGoto.style.display = 'none';
      btnGoto.href = '#';
    }

    if (validBody) {
      validBody.innerHTML = '<div class="ba-modal-loading">' +
        '<div class="ba-spinner"></div>' +
        '<span>Checking pending submissions and evaluations...</span>' +
        '</div>';
    }

    var overlay = document.getElementById('ba-task-modal-overlay');
    if (overlay) overlay.classList.add('show');

    // Fetch real-time validation status from backend
    var formData = new FormData();
    formData.append('action', 'check_task_validation');
    formData.append('sesskey', sesskey);
    formData.append('type', task.action_type || '');
    formData.append('courseid', task.courseid || 0);
    formData.append('batchid', task.batchid || 0);
    formData.append('act_name', task.act_name || '');
    formData.append('act_key', task.act_key || '');
    formData.append('cmid', task.cmid || 0);

    fetch(apiUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    })
      .then(function(res) { return res.json(); })
      .then(function(resp) {
        if (!pendingTask || pendingTask.id !== taskId) return;
        renderModalValidation(resp);
      })
      .catch(function(err) {
        if (!pendingTask || pendingTask.id !== taskId) return;
        console.error('Validation check error:', err);
        if (validBody) {
          validBody.innerHTML = '<div class="ba-modal-alert danger">' +
            '<div class="alert-content"><div class="alert-desc">⚠️ Connection error checking submission status. You may try again or proceed.</div></div>' +
            '</div>';
        }
        if (btnConfirm) {
          btnConfirm.disabled = false;
          btnConfirm.title = '';
        }
      });
  }

  function renderModalValidation(resp) {
    var validBody = document.getElementById('ba-modal-validation-body');
    var btnConfirm = document.getElementById('ba-modal-btn-confirm');
    var btnGoto = document.getElementById('ba-modal-btn-goto');
    if (!validBody) return;

    if (!resp || !resp.success) {
      var msg = (resp && (resp.error || resp.message)) || 'Unable to validate activity submissions.';
      validBody.innerHTML = '<div class="ba-modal-alert danger">' +
        '<div class="alert-content"><div class="alert-desc">⚠️ ' + escapeHtml(msg) + '</div></div>' +
        '</div>';
      if (btnConfirm) {
        btnConfirm.disabled = true;
        btnConfirm.textContent = 'Cannot Complete';
      }
      return;
    }

    var hasPending = !!resp.has_pending;
    var pendingCnt = parseInt(resp.pending_count, 10) || 0;
    var completedCnt = parseInt(resp.completed_count, 10) || 0;
    var actUrl = resp.activity_url || (pendingTask && pendingTask.dest_url) || '';
    var items = Array.isArray(resp.items) ? resp.items : [];

    var html = '';

    // 1. Metric counter pills: Pending vs Completed
    html += '<div class="ba-modal-metrics">';
    html += '  <div class="ba-metric-pill pending' + (hasPending ? ' alert' : '') + '">';
    html += '    <div class="num">' + pendingCnt + '</div>';
    html += '    <div class="lbl">Pending / Ungraded</div>';
    html += '  </div>';
    html += '  <div class="ba-metric-pill completed">';
    html += '    <div class="num">' + completedCnt + '</div>';
    html += '    <div class="lbl">Completed / Graded</div>';
    html += '  </div>';
    html += '</div>';

    // 2. Alert status banner & item list
    if (hasPending) {
      html += '<div class="ba-modal-alert danger">';
      html += '  <div class="alert-icon">⚠️</div>';
      html += '  <div class="alert-content">';
      html += '    <div class="alert-title">Pending Submissions Detected</div>';
      html += '    <div class="alert-desc">' + escapeHtml(resp.message || 'There are pending submissions or attempts needing to be graded before this activity can be marked complete.') + '</div>';
      html += '  </div>';
      html += '</div>';

      // If items breakdown is available, show activities with pending submissions
      var pendingItems = items.filter(function(it) { return (it.pending || 0) > 0; });
      if (pendingItems.length > 0) {
        html += '<div class="ba-modal-items-list">';
        html += '  <div class="items-title">Activities Needing Evaluation in Course:</div>';
        pendingItems.slice(0, 5).forEach(function(it) {
          html += '  <div class="ba-modal-item-row">';
          html += '    <div class="item-name" title="' + escapeHtml(it.name) + '">' + escapeHtml(it.name) + '</div>';
          html += '    <div class="item-right">';
          html += '      <span class="item-badge">' + (it.pending || 0) + ' pending</span>';
          if (it.url) {
            html += '      <a href="' + escapeHtml(it.url) + '" target="_blank" class="item-link">Go to activity ↗</a>';
          }
          html += '    </div>';
          html += '  </div>';
        });
        if (pendingItems.length > 5) {
          html += '  <div class="items-more">+ ' + (pendingItems.length - 5) + ' more activities in course</div>';
        }
        html += '</div>';
      }

      validBody.innerHTML = html;

      // Primary "Go to Activity in Course ↗" button
      if (btnGoto && actUrl) {
        btnGoto.href = actUrl;
        btnGoto.textContent = 'Go to Activity in Course ↗';
        btnGoto.className = 'mc-btn-goto-act primary';
        btnGoto.style.display = 'inline-flex';
      }

      // Disable Confirm Complete button
      if (btnConfirm) {
        btnConfirm.disabled = true;
        btnConfirm.textContent = 'Grade Submissions First';
        btnConfirm.title = 'Cannot mark complete while submissions are pending evaluation';
      }

    } else {
      // 0 pending submissions
      html += '<div class="ba-modal-alert success">';
      html += '  <div class="alert-icon">✓</div>';
      html += '  <div class="alert-content">';
      html += '    <div class="alert-title">Ready to Mark Complete</div>';
      html += '    <div class="alert-desc">' + escapeHtml(resp.message || 'All submissions and attempts have been evaluated and graded.') + '</div>';
      html += '  </div>';
      html += '</div>';

      validBody.innerHTML = html;

      // Optional view link
      if (btnGoto && actUrl) {
        btnGoto.href = actUrl;
        btnGoto.textContent = 'View Activity ↗';
        btnGoto.className = 'mc-btn-goto-act secondary';
        btnGoto.style.display = 'inline-flex';
      }

      // Enable Confirm Complete button
      if (btnConfirm) {
        btnConfirm.disabled = false;
        btnConfirm.textContent = 'Confirm Complete';
        btnConfirm.title = '';
      }
    }
  }

  function closeModal() {
    var overlay = document.getElementById('ba-task-modal-overlay');
    if (overlay) overlay.classList.remove('show');
    pendingTask = null;
  }

  function confirmCompletion() {
    if (!pendingTask || !apiUrl) return;

    var btnConfirm = document.getElementById('ba-modal-btn-confirm');
    if (btnConfirm) {
      btnConfirm.disabled = true;
      btnConfirm.textContent = 'Marking complete...';
    }

    var formData = new FormData();
    formData.append('action', 'complete_task');
    formData.append('sesskey', sesskey);
    formData.append('type', pendingTask.action_type || '');
    formData.append('courseid', pendingTask.courseid || 0);
    formData.append('batchid', pendingTask.batchid || 0);
    formData.append('act_name', pendingTask.act_name || '');
    formData.append('act_key', pendingTask.act_key || '');
    formData.append('cmid', pendingTask.cmid || 0);

    fetch(apiUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    })
      .then(function(res) { return res.json(); })
      .then(function(resp) {
        if (btnConfirm) btnConfirm.disabled = false;
        if (resp && resp.success) {
          pendingTask.is_done = true;
          closeModal();
          renderTodoList();
        } else {
          renderModalValidation(resp);
        }
      })
      .catch(function(err) {
        if (btnConfirm) {
          btnConfirm.disabled = false;
          btnConfirm.textContent = 'Confirm Complete';
        }
        alert('Server connection error. Please try again.');
        console.error(err);
      });
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
