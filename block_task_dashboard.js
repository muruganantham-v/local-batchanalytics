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
  var isPmUser = false;
  var isSsLeadUser = false;
  var pendingTask = null;

  // Pagination state
  var pageSize = 5;
  var currentPage = 1;

  function init() {
    container = document.getElementById('ba-task-dash-container');
    if (!container) return;

    apiUrl = container.getAttribute('data-api-url') || '';
    sesskey = container.getAttribute('data-sesskey') || '';
    if (container.getAttribute('data-active-role')) {
      activeRole = container.getAttribute('data-active-role');
    }

    var roleSwitcher = document.getElementById('ba-role-switcher');
    if (roleSwitcher) {
      if (roleSwitcher.value) {
        activeRole = roleSwitcher.value;
      }
      roleSwitcher.addEventListener('change', function() {
        activeRole = this.value;
        currentPage = 1;
        loadDashboardData();
      });
    }

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
    var btnReject = document.getElementById('ba-modal-btn-reject');

    var btnClose = document.getElementById('ba-modal-btn-close');
    if (btnClose) {
      btnClose.addEventListener('click', closeModal);
    }
    if (btnCancel) {
      btnCancel.addEventListener('click', closeModal);
    }
    if (btnReject) {
      btnReject.addEventListener('click', rejectApproval);
    }
    if (btnConfirm) {
      btnConfirm.addEventListener('click', confirmCompletion);
    }
    if (overlay) {
      overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeModal();
      });
    }

    // Auto-refresh interval (every 30s) so if tasks are completed automatically or by evaluations, counts update
    setInterval(function() {
      var ov = document.getElementById('ba-task-modal-overlay');
      if (!ov || !ov.classList.contains('show')) {
        loadDashboardData();
      }
    }, 30000);

    // Auto-refresh on window focus or tab visibility
    document.addEventListener('visibilitychange', function() {
      if (document.visibilityState === 'visible') {
        var ov = document.getElementById('ba-task-modal-overlay');
        if (!ov || !ov.classList.contains('show')) {
          loadDashboardData();
        }
      }
    });
    window.addEventListener('focus', function() {
      var ov = document.getElementById('ba-task-modal-overlay');
      if (!ov || !ov.classList.contains('show')) {
        loadDashboardData();
      }
    });

    // Cross-tab sync via storage event
    window.addEventListener('storage', function(e) {
      if (e.key === 'ba_task_updated') {
        var ov = document.getElementById('ba-task-modal-overlay');
        if (!ov || !ov.classList.contains('show')) {
          loadDashboardData();
        }
      }
    });

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
      '&role=' + encodeURIComponent(activeRole || '') +
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
      var roleSwitcherWrap = document.querySelector('.ph-role-switch');
      if (roleSwitcherWrap && (!d.can_switch_roles || d.active_role === 'admin')) {
        roleSwitcherWrap.style.display = 'none';
      }
      var roleSwitcher = document.getElementById('ba-role-switcher');
      if (roleSwitcher) {
        roleSwitcher.value = d.active_role;
      }
    }
    if (d && d.is_pm !== undefined) {
      isPmUser = !!d.is_pm;
    }
    if (d && d.is_sslead !== undefined) {
      isSsLeadUser = !!d.is_sslead;
    }

    // Glance cards
    var elGlance = document.getElementById('ba-dash-glance');
    if (elGlance && Array.isArray(d.glance)) {
      elGlance.innerHTML = d.glance.map(function(g) {
        var alertCls = g.alert ? ' alert gt-alert' : '';
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

  function updateGlanceCounts() {
    var overdueCount = 0;
    var tasksDueWeek = 0;
    allTodos.forEach(function(t) {
      if (t.is_done) return;
      if (t.status_class === 'over') {
        overdueCount++;
        tasksDueWeek++;
      } else if (t.status_class === 'today' || t.status_class === 'soon') {
        tasksDueWeek++;
      }
    });

    var elGlance = document.getElementById('ba-dash-glance');
    if (elGlance) {
      var cards = elGlance.querySelectorAll('.gt');
      cards.forEach(function(card) {
        var labelEl = card.querySelector('.k');
        var valEl = card.querySelector('.v');
        if (!labelEl || !valEl) return;
        var lbl = labelEl.textContent.trim().toLowerCase();
        if (lbl === 'overdue') {
          valEl.textContent = overdueCount;
          if (overdueCount > 0) {
            card.classList.add('alert');
            card.classList.add('gt-alert');
          } else {
            card.classList.remove('alert');
            card.classList.remove('gt-alert');
          }
        } else if (lbl.indexOf('due this week') !== -1 || lbl.indexOf('tasks due') !== -1) {
          valEl.textContent = tasksDueWeek;
        }
      });
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

    // Keep glance counts synchronized
    updateGlanceCounts();

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
      } else if (t.action_type === 'ss_approve' || ((activeRole === 'sslead' || activeRole === 'admin') && t.is_pending_approval)) {
        actionBtnHtml = '<button type="button" class="mc-btn2 mc-btn-approve" data-task-id="' + escapeHtml(t.id) + '" style="background:#059669; border-color:#059669; color:#fff;">Review</button>';
      } else if (t.action_type === 'ss_requested' || t.is_requested) {
        actionBtnHtml = '<button type="button" class="mc-btn2 disabled" disabled style="background:#f1f5f9; border-color:#cbd5e1; color:#94a3b8; cursor:not-allowed;">In Review</button>';
      } else if (activeRole === 'sse' && t.action_type === 'ss') {
        actionBtnHtml = '<button type="button" class="mc-btn2 mc-btn-request" data-task-id="' + escapeHtml(t.id) + '" style="background:#4f46e5; border-color:#4f46e5; color:#fff;">Submit for Review</button>';
      } else if (activeRole === 'mentors' || activeRole === 'sspm' || (activeRole !== 'admin' && activeRole !== 'pm' && activeRole !== 'sslead')) {
        actionBtnHtml = '<button type="button" class="mc-btn2" data-task-id="' + escapeHtml(t.id) + '">Mark Complete</button>';
      } else if (activeRole === 'admin') {
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

    var modalTitle = document.getElementById('ba-modal-title');
    var modalSub = document.querySelector('.ba-modal-sub');
    var validBody = document.getElementById('ba-modal-validation-body');
    var btnConfirm = document.getElementById('ba-modal-btn-confirm');
    var btnReject = document.getElementById('ba-modal-btn-reject');
    var btnGoto = document.getElementById('ba-modal-btn-goto');

    if (btnReject) {
      btnReject.style.display = 'none';
      btnReject.disabled = false;
      btnReject.textContent = 'Reject';
    }

    if (btnGoto) {
      btnGoto.style.display = 'none';
      btnGoto.setAttribute('style', 'display: none !important;');
      btnGoto.classList.remove('is-visible', 'primary', 'secondary');
      btnGoto.classList.add('is-hidden');
      btnGoto.href = '#';
    }

    var isSseRequest = (activeRole === 'sse' && task.action_type === 'ss') || (task.action_type === 'ss_request');
    var isSslApprove = ((activeRole === 'sslead' || activeRole === 'admin') && (task.action_type === 'ss_approve' || task.is_pending_approval));

    if (isSseRequest) {
      if (modalTitle) modalTitle.textContent = 'Submit for Review';
      if (modalSub) modalSub.textContent = 'Submit this Soft Skills activity for review by the SS Lead:';
      if (btnGoto) {
        btnGoto.style.display = 'none';
        btnGoto.setAttribute('style', 'display: none !important;');
        btnGoto.classList.remove('is-visible', 'primary', 'secondary');
        btnGoto.classList.add('is-hidden');
      }
      if (btnReject) {
        btnReject.style.display = 'none';
      }
      if (btnConfirm) {
        btnConfirm.style.display = '';
        btnConfirm.disabled = false;
        btnConfirm.textContent = 'Submit for Review';
        btnConfirm.className = 'confirm mc-btn-request';
        btnConfirm.style.background = '#4f46e5';
        btnConfirm.style.borderColor = '#4f46e5';
        btnConfirm.title = '';
      }
      if (validBody) {
        var notesHtml = '';
        if (task.is_rejected && task.review_notes) {
          var rejUser = task.rejected_by_name ? (' from ' + escapeHtml(task.rejected_by_name)) : '';
          notesHtml = '<div class="ba-modal-rejection-notes" style="margin-top:14px; padding:12px 14px; background:#fff1f2; border:1.5px solid #fecdd3; border-radius:10px;">' +
            '<div style="font-weight:700; color:#be123c; font-size:13px; margin-bottom:6px; display:flex; align-items:center; gap:6px;">' +
            '  <span>⚠️</span><span>Review Notes' + rejUser + ':</span>' +
            '</div>' +
            '<div style="color:#9f1239; font-size:13px; line-height:1.45; white-space:pre-wrap; background:#fff; padding:10px 12px; border-radius:6px; border:1px solid #ffe4e6;">' +
            escapeHtml(task.review_notes) +
            '</div>' +
            '</div>';
        }
        validBody.innerHTML = '<div class="ba-modal-alert success">' +
          '<div class="alert-icon">ℹ️</div>' +
          '<div class="alert-content">' +
          '  <div class="alert-title">Submit Due Activity for Approval</div>' +
          '  <div class="alert-desc">This activity due notice will be sent to the Student Success Lead (SSL) for review and sign-off.</div>' +
          '</div>' +
          '</div>' +
          notesHtml;
      }
      var overlay = document.getElementById('ba-task-modal-overlay');
      if (overlay) overlay.classList.add('show');
      return;
    }

    if (isSslApprove) {
      if (modalTitle) modalTitle.textContent = 'Review Activity';
      if (modalSub) modalSub.textContent = 'Review this Soft Skills activity and choose an action:';
      if (btnGoto) {
        btnGoto.style.display = 'none';
        btnGoto.setAttribute('style', 'display: none !important;');
        btnGoto.classList.remove('is-visible', 'primary', 'secondary');
        btnGoto.classList.add('is-hidden');
      }
      if (btnReject) {
        btnReject.style.display = 'inline-block';
        btnReject.disabled = false;
        btnReject.textContent = 'Reject';
      }
      if (btnConfirm) {
        btnConfirm.style.display = '';
        btnConfirm.disabled = false;
        btnConfirm.textContent = 'Approve';
        btnConfirm.className = 'confirm mc-btn-approve';
        btnConfirm.style.background = '#059669';
        btnConfirm.style.borderColor = '#059669';
        btnConfirm.title = '';
      }
      if (validBody) {
        var reqInfo = task.requested_by_name ? (' (Requested by: ' + escapeHtml(task.requested_by_name) + ')') : '';
        validBody.innerHTML = '<div class="ba-modal-alert success">' +
          '<div class="alert-icon">✓</div>' +
          '<div class="alert-content">' +
          '  <div class="alert-title">Ready for Final Sign-off</div>' +
          '  <div class="alert-desc">Review this activity submission. You can approve to complete or reject with review notes.' + reqInfo + '</div>' +
          '</div>' +
          '</div>' +
          '<div class="ba-modal-review-notes-section" style="margin-top:14px;">' +
          '  <label class="ba-modal-review-notes-check" style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13.5px; font-weight:600; color:#334155; user-select:none;">' +
          '    <input type="checkbox" id="ba-modal-check-review-notes" style="width:16px; height:16px; cursor:pointer; accent-color:#0284c7;" />' +
          '    <span>Add Review Notes</span>' +
          '  </label>' +
          '  <div id="ba-modal-notes-container" style="display:none; margin-top:10px;">' +
          '    <textarea id="ba-modal-review-notes" rows="3" placeholder="Provide feedback or reasons for rejection / approval..." style="width:100%; box-sizing:border-box; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:inherit; resize:vertical; line-height:1.4; outline:none; transition:border-color 0.15s ease;"></textarea>' +
          '  </div>' +
          '</div>';

        var checkEl = document.getElementById('ba-modal-check-review-notes');
        var notesContainer = document.getElementById('ba-modal-notes-container');
        var textareaEl = document.getElementById('ba-modal-review-notes');
        if (checkEl && notesContainer) {
          checkEl.addEventListener('change', function() {
            if (this.checked) {
              notesContainer.style.display = 'block';
              if (textareaEl) textareaEl.focus();
            } else {
              notesContainer.style.display = 'none';
            }
          });
        }
      }
      var overlay = document.getElementById('ba-task-modal-overlay');
      if (overlay) overlay.classList.add('show');
      return;
    }

    if (modalTitle) modalTitle.textContent = 'Mark Activity Complete';
    if (modalSub) modalSub.textContent = 'Check submissions and confirm completion of this activity:';
    if (btnConfirm) {
      btnConfirm.style.display = '';
      btnConfirm.disabled = true;
      btnConfirm.textContent = 'Confirm Complete';
      btnConfirm.className = 'confirm';
      btnConfirm.style.background = '';
      btnConfirm.style.borderColor = '';
      btnConfirm.title = 'Validating activity status...';
    }

    if (validBody) {
      var isSsePmTask = (activeRole === 'sspm') || (task && task.action_type === 'ss');
      validBody.innerHTML = '<div class="ba-modal-loading">' +
        '<div class="ba-spinner"></div>' +
        '<span>' + (isSsePmTask ? 'Checking activity status...' : 'Checking pending submissions and evaluations...') + '</span>' +
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

    var isSsePm = (activeRole === 'sspm') ||
                  (pendingTask && (pendingTask.action_type === 'ss' || pendingTask.role === 'sspm')) ||
                  (resp && (resp.action_type === 'ss' || resp.role === 'sspm'));

    if (isSsePm) {
      var sHtml = '';
      sHtml += '<div class="ba-modal-alert success">';
      sHtml += '  <div class="alert-icon">✓</div>';
      sHtml += '  <div class="alert-content">';
      sHtml += '    <div class="alert-title">Ready to Mark Complete</div>';
      sHtml += '    <div class="alert-desc">' + escapeHtml((resp.message && resp.message !== 'Milestone activity. Ready to mark complete.') ? resp.message : 'Ready to mark this activity as completed. Click Confirm Complete to finish.') + '</div>';
      sHtml += '  </div>';
      sHtml += '</div>';

      validBody.innerHTML = sHtml;

      if (btnGoto) {
        btnGoto.style.display = 'none';
        btnGoto.setAttribute('style', 'display: none !important;');
        btnGoto.classList.remove('is-visible', 'primary', 'secondary');
        btnGoto.classList.add('is-hidden');
      }
      if (btnConfirm) {
        btnConfirm.disabled = false;
        btnConfirm.textContent = 'Confirm Complete';
        btnConfirm.title = '';
      }
      return;
    }

    var normName = (pendingTask && pendingTask.act_name ? pendingTask.act_name : (resp.activity_name || '')).toLowerCase();
    var normKey  = (pendingTask && pendingTask.act_key ? pendingTask.act_key : '').toLowerCase();
    var isSpotAward = !!resp.is_spot_award ||
                      normName.indexOf('spot award') !== -1 ||
                      normName.indexOf('spot_award') !== -1 ||
                      normKey.indexOf('spot award') !== -1 ||
                      normKey.indexOf('spot_award') !== -1;

    if (isSpotAward) {
      var courseId = (pendingTask && pendingTask.courseid) ? pendingTask.courseid : (resp.courseid || '');
      var spotUrl = resp.spot_award_url || resp.activity_url || ('/local/spotaward/index.php?courseid=' + courseId);
      var isNominated = (resp.nominated === true) || (parseInt(resp.nominated_count, 10) > 0);

      if (!isNominated || resp.has_pending) {
        var sHtml = '';
        sHtml += '<div class="ba-modal-alert danger">';
        sHtml += '  <div class="alert-icon">⚠️</div>';
        sHtml += '  <div class="alert-content">';
        sHtml += '    <div class="alert-title">Spot Award Nomination Required</div>';
        sHtml += '    <div class="alert-desc">' + escapeHtml(resp.message || 'No students have been nominated for Spot Award in this course yet. Please nominate at least one student before marking this activity as complete.') + '</div>';
        sHtml += '  </div>';
        sHtml += '</div>';

        sHtml += '<div style="margin-top: 14px; padding: 12px 16px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; text-align: center;">';
        sHtml += '  <p style="margin: 0; color: #9a3412; font-size: 13px; font-weight: 500;">Please nominate at least one student from this course before marking this activity as complete.</p>';
        sHtml += '</div>';

        validBody.innerHTML = sHtml;

        // Show "Nominate Students for Spot Award" button in modal footer
        if (btnGoto) {
          btnGoto.href = spotUrl;
          btnGoto.target = '_blank';
          btnGoto.textContent = 'Nominate Students for Spot Award ↗';
          btnGoto.className = 'mc-btn-goto-act primary is-visible';
          btnGoto.classList.remove('is-hidden');
          btnGoto.style.display = 'inline-flex';
        }

        // Hide Confirm button since nomination is required
        if (btnConfirm) {
          btnConfirm.style.display = 'none';
        }
        return;
      }

      // Nominated is verified!
      var students = Array.isArray(resp.nominated_students) ? resp.nominated_students : [];
      var sHtml = '';
      sHtml += '<div class="ba-modal-alert success">';
      sHtml += '  <div class="alert-icon">✓</div>';
      sHtml += '  <div class="alert-content">';
      sHtml += '    <div class="alert-title">Spot Award Nomination Verified</div>';
      sHtml += '    <div class="alert-desc">' + escapeHtml(resp.message || ('Spot award nomination verified (' + (resp.nominated_count || students.length) + ' student(s) nominated).')) + '</div>';
      sHtml += '  </div>';
      sHtml += '</div>';

      sHtml += '<div style="margin-top: 10px; margin-bottom: 4px; text-align: right;"><a href="' + escapeHtml(spotUrl) + '" target="_blank" style="color: #0284c7; text-decoration: none; font-size: 12px; font-weight: 500;">View in Spot Award ↗</a></div>';

      validBody.innerHTML = sHtml;

      if (btnGoto) {
        btnGoto.style.display = 'none';
      }

      if (btnConfirm) {
        btnConfirm.style.display = '';
        btnConfirm.disabled = false;
        btnConfirm.textContent = 'Confirm Complete';
        btnConfirm.title = '';
      }
      return;
    }

    var isMilestone = !!resp.is_milestone ||
                      normName.indexOf('nomination') !== -1 ||
                      normName.indexOf('power track') !== -1 ||
                      normKey.indexOf('nomination') !== -1 ||
                      normKey.indexOf('power_track') !== -1;

    if (isMilestone) {
      var mHtml = '';
      mHtml += '<div class="ba-modal-alert success">';
      mHtml += '  <div class="alert-icon">✓</div>';
      mHtml += '  <div class="alert-content">';
      mHtml += '    <div class="alert-title">Operational Milestone Task</div>';
      mHtml += '    <div class="alert-desc">' + escapeHtml(resp.message || 'Operational milestone activity (no student submissions required). Click Confirm Complete to finish.') + '</div>';
      mHtml += '  </div>';
      mHtml += '</div>';

      validBody.innerHTML = mHtml;

      if (btnGoto) {
        btnGoto.style.display = 'none';
        btnGoto.setAttribute('style', 'display: none !important;');
      }
      if (btnConfirm) {
        btnConfirm.disabled = false;
        btnConfirm.textContent = 'Confirm Complete';
        btnConfirm.title = '';
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
        btnGoto.className = 'mc-btn-goto-act primary is-visible';
        btnGoto.classList.remove('is-hidden');
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
        btnGoto.className = 'mc-btn-goto-act secondary is-visible';
        btnGoto.classList.remove('is-hidden');
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
    var btnReject = document.getElementById('ba-modal-btn-reject');
    if (btnReject) btnReject.style.display = 'none';
    pendingTask = null;
  }

  function confirmCompletion() {
    if (!pendingTask || !apiUrl) return;

    var isSseRequest = (activeRole === 'sse' && pendingTask.action_type === 'ss') || (pendingTask.action_type === 'ss_request');
    var isSslApprove = ((activeRole === 'sslead' || activeRole === 'admin') && (pendingTask.action_type === 'ss_approve' || pendingTask.is_pending_approval));
    var btnConfirm = document.getElementById('ba-modal-btn-confirm');
    var btnReject = document.getElementById('ba-modal-btn-reject');

    if (isSseRequest) {
      if (btnConfirm) {
        btnConfirm.disabled = true;
        btnConfirm.textContent = 'Submitting for review...';
      }

      var formData = new FormData();
      formData.append('action', 'request_ss_approval');
      formData.append('sesskey', sesskey);
      formData.append('batchid', pendingTask.batchid || 0);
      formData.append('act_key', pendingTask.act_key || '');
      formData.append('role', activeRole || '');

      fetch(apiUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      })
        .then(function(res) { return res.json(); })
        .then(function(resp) {
          if (btnConfirm) btnConfirm.disabled = false;
          if (resp && resp.success) {
            pendingTask.is_requested = true;
            pendingTask.action_type = 'ss_requested';
            closeModal();
            renderTodoList();
            try {
              localStorage.setItem('ba_task_updated', Date.now().toString());
            } catch (e) {}
            if (resp.dashboard_data) {
              applyData(resp.dashboard_data);
            } else {
              loadDashboardData();
            }
          } else {
            alert((resp && resp.message) || 'Failed to submit review request.');
          }
        })
        .catch(function(err) {
          if (btnConfirm) {
            btnConfirm.disabled = false;
            btnConfirm.textContent = 'Submit for Review';
          }
          alert('Server connection error. Please try again.');
          console.error(err);
        });
      return;
    }

    if (btnConfirm) {
      btnConfirm.disabled = true;
      btnConfirm.textContent = isSslApprove ? 'Approving...' : 'Marking complete...';
    }
    if (btnReject) {
      btnReject.disabled = true;
    }

    var reviewNotes = '';
    var checkEl = document.getElementById('ba-modal-check-review-notes');
    var txtEl = document.getElementById('ba-modal-review-notes');
    if (checkEl && checkEl.checked && txtEl) {
      reviewNotes = txtEl.value.trim();
    }

    var formData = new FormData();
    formData.append('action', 'complete_task');
    formData.append('sesskey', sesskey);
    formData.append('type', isSslApprove ? 'ss_approve' : (pendingTask.action_type || ''));
    formData.append('courseid', pendingTask.courseid || 0);
    formData.append('batchid', pendingTask.batchid || 0);
    formData.append('act_name', pendingTask.act_name || '');
    formData.append('act_key', pendingTask.act_key || '');
    formData.append('cmid', pendingTask.cmid || 0);
    formData.append('role', activeRole || '');
    formData.append('review_notes', reviewNotes);

    fetch(apiUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    })
      .then(function(res) { return res.json(); })
      .then(function(resp) {
        if (btnConfirm) btnConfirm.disabled = false;
        if (btnReject) btnReject.disabled = false;
        if (resp && resp.success) {
          pendingTask.is_done = true;
          closeModal();
          // Optimistically update counts and todo list immediately
          renderTodoList();

          // Broadcast to other open tabs/windows
          try {
            localStorage.setItem('ba_task_updated', Date.now().toString());
          } catch (e) {}

          // Apply updated server data if returned, otherwise fetch fresh data
          if (resp.dashboard_data) {
            applyData(resp.dashboard_data);
          } else {
            loadDashboardData();
          }
        } else {
          renderModalValidation(resp);
        }
      })
      .catch(function(err) {
        if (btnConfirm) {
          btnConfirm.disabled = false;
          btnConfirm.textContent = isSslApprove ? 'Approve' : 'Confirm Complete';
        }
        if (btnReject) {
          btnReject.disabled = false;
        }
        alert('Server connection error. Please try again.');
        console.error(err);
      });
  }

  function rejectApproval() {
    if (!pendingTask || !apiUrl) return;

    var btnReject = document.getElementById('ba-modal-btn-reject');
    var btnConfirm = document.getElementById('ba-modal-btn-confirm');
    var checkEl = document.getElementById('ba-modal-check-review-notes');
    var txtEl = document.getElementById('ba-modal-review-notes');
    var notesContainer = document.getElementById('ba-modal-notes-container');

    var reviewNotes = '';
    if (checkEl && checkEl.checked && txtEl) {
      reviewNotes = txtEl.value.trim();
    }

    if (!reviewNotes) {
      if (checkEl && !checkEl.checked) {
        checkEl.checked = true;
        if (notesContainer) notesContainer.style.display = 'block';
      }
      if (txtEl) {
        txtEl.focus();
        txtEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
      var confirmReject = confirm('Please enter review notes explaining why this activity is rejected. Do you want to reject anyway?');
      if (!confirmReject) return;
      if (txtEl) reviewNotes = txtEl.value.trim();
    }

    if (btnReject) {
      btnReject.disabled = true;
      btnReject.textContent = 'Rejecting...';
    }
    if (btnConfirm) {
      btnConfirm.disabled = true;
    }

    var formData = new FormData();
    formData.append('action', 'reject_ss_approval');
    formData.append('sesskey', sesskey);
    formData.append('batchid', pendingTask.batchid || 0);
    formData.append('act_key', pendingTask.act_key || '');
    formData.append('review_notes', reviewNotes);
    formData.append('role', activeRole || '');

    fetch(apiUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    })
      .then(function(res) { return res.json(); })
      .then(function(resp) {
        if (btnReject) btnReject.disabled = false;
        if (btnConfirm) btnConfirm.disabled = false;
        if (resp && resp.success) {
          pendingTask.is_done = true;
          closeModal();
          renderTodoList();
          try {
            localStorage.setItem('ba_task_updated', Date.now().toString());
          } catch (e) {}
          if (resp.dashboard_data) {
            applyData(resp.dashboard_data);
          } else {
            loadDashboardData();
          }
        } else {
          alert((resp && resp.message) || 'Failed to reject activity.');
          if (btnReject) btnReject.textContent = 'Reject';
        }
      })
      .catch(function(err) {
        if (btnReject) {
          btnReject.disabled = false;
          btnReject.textContent = 'Reject';
        }
        if (btnConfirm) {
          btnConfirm.disabled = false;
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
