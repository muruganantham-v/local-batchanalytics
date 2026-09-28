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
  var allTodos = [];
  var pendingTask = null;

  // Pagination state
  var pageSize = 5;
  var currentPage = 1;

  function init() {
    container = document.getElementById('ba-task-dash-container');
    if (!container) return;

    apiUrl = container.getAttribute('data-api-url') || '';
    sesskey = container.getAttribute('data-sesskey') || '';

    var filterSel = document.getElementById('ba-todo-filter');
    if (filterSel) {
      filterSel.addEventListener('change', function() {
        currentFilter = this.value;
        currentPage = 1;
        renderTodoList();
      });
    }

    // Modal listeners
    var overlay = document.getElementById('ba-task-modal-overlay');
    var btnCancel = document.getElementById('ba-modal-btn-cancel');
    var btnConfirm = document.getElementById('ba-modal-btn-confirm');

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
      if (currentFilter === 'overdue') return t.status_class === 'over';
      if (currentFilter === 'today') return t.status_class === 'today';
      if (currentFilter === 'soon') return t.status_class === 'soon';
      return true;
    });

    var totalPending = allTodos.filter(function(t) { return !t.is_done; }).length;
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
      var destLabel = (t.dest_type === 'batch') ? 'Go to batch →' : 'Go to module →';
      return '<div class="todo" id="todo-row-' + escapeHtml(t.id) + '">' +
        '<div class="body">' +
          '<div class="t">' + escapeHtml(t.title) + '</div>' +
          '<div class="m">' + escapeHtml(t.meta) + '</div>' +
          '<a href="' + escapeHtml(t.dest_url) + '" class="go">' + destLabel + '</a>' +
        '</div>' +
        '<div class="actions">' +
          '<span class="due ' + escapeHtml(t.status_class) + '">' + escapeHtml(t.status_label) + '</span>' +
          '<button type="button" class="mc-btn2" data-task-id="' + escapeHtml(t.id) + '">Mark Complete</button>' +
        '</div>' +
      '</div>';
    }).join('');

    // Attach click events to Mark Complete buttons
    var btns = elList.querySelectorAll('.mc-btn2');
    btns.forEach(function(btn) {
      btn.addEventListener('click', function() {
        var tid = this.getAttribute('data-task-id');
        openCompleteModal(tid);
      });
    });

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

    // Page number buttons
    var maxButtons = 5;
    var startPage = Math.max(1, currentPage - 2);
    var endPage = Math.min(totalPages, startPage + maxButtons - 1);
    if (endPage - startPage < maxButtons - 1) {
      startPage = Math.max(1, endPage - maxButtons + 1);
    }

    if (startPage > 1) {
      html += '<button type="button" class="ba-pbtn ba-pnum" data-page="1">1</button>';
      if (startPage > 2) html += '<span style="color:#94a3b8; padding:0 3px;">…</span>';
    }

    for (var p = startPage; p <= endPage; p++) {
      var activeCls = (p === currentPage) ? ' active' : '';
      html += '<button type="button" class="ba-pbtn ba-pnum' + activeCls + '" data-page="' + p + '">' + p + '</button>';
    }

    if (endPage < totalPages) {
      if (endPage < totalPages - 1) html += '<span style="color:#94a3b8; padding:0 3px;">…</span>';
      html += '<button type="button" class="ba-pbtn ba-pnum" data-page="' + totalPages + '">' + totalPages + '</button>';
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

    var overlay = document.getElementById('ba-task-modal-overlay');
    if (overlay) overlay.classList.add('show');
  }

  function closeModal() {
    var overlay = document.getElementById('ba-task-modal-overlay');
    if (overlay) overlay.classList.remove('show');
    pendingTask = null;
  }

  function confirmCompletion() {
    if (!pendingTask || !apiUrl) return;

    var btnConfirm = document.getElementById('ba-modal-btn-confirm');
    if (btnConfirm) btnConfirm.disabled = true;

    var formData = new FormData();
    formData.append('action', 'complete_task');
    formData.append('sesskey', sesskey);
    formData.append('type', pendingTask.action_type || '');
    formData.append('courseid', pendingTask.courseid || 0);
    formData.append('batchid', pendingTask.batchid || 0);
    formData.append('act_name', pendingTask.act_name || '');
    formData.append('act_key', pendingTask.act_key || '');

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
          alert('Failed to save completion: ' + ((resp && resp.message) || 'Unknown error'));
        }
      })
      .catch(function(err) {
        if (btnConfirm) btnConfirm.disabled = false;
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
