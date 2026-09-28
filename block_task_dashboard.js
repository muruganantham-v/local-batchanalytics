/**
 * Frontend script for Batch Analytics Operational Dashboard Block
 * Supports Mentor, SS Executive, Program Manager, and Assistant Manager personas.
 */
(function() {
  'use strict';

  var container = null;
  var apiUrl = '';
  var sesskey = '';
  var currentRole = '';
  var currentFilter = 'all';
  var allTodos = [];
  var pendingTask = null;

  function init() {
    container = document.getElementById('ba-task-dash-container');
    if (!container) return;

    apiUrl = container.getAttribute('data-api-url') || '';
    sesskey = container.getAttribute('data-sesskey') || '';

    var roleSel = document.getElementById('ba-role-selector');
    if (roleSel) {
      currentRole = roleSel.value;
      roleSel.addEventListener('change', function() {
        currentRole = this.value;
        loadDashboardData(currentRole);
      });
    }

    var filterSel = document.getElementById('ba-todo-filter');
    if (filterSel) {
      filterSel.addEventListener('change', function() {
        currentFilter = this.value;
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

    loadDashboardData(currentRole);
  }

  function loadDashboardData(role) {
    if (!apiUrl) return;

    var url = apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') +
      'action=get_dashboard_tasks' +
      '&role=' + encodeURIComponent(role || '') +
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

    // Role switcher sync
    var roleSel = document.getElementById('ba-role-selector');
    if (roleSel && d.active_role) {
      roleSel.value = d.active_role;
      currentRole = d.active_role;
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

  function renderTodoList() {
    var elList = document.getElementById('ba-dash-todo-list');
    var elCount = document.getElementById('ba-dash-todo-count');
    if (!elList) return;

    var filtered = allTodos.filter(function(t) {
      if (t.is_done) return false;
      if (currentFilter === 'overdue') return t.status_class === 'over';
      if (currentFilter === 'today') return t.status_class === 'today';
      if (currentFilter === 'soon') return t.status_class === 'soon';
      return true;
    });

    if (elCount) {
      var remaining = allTodos.filter(function(t) { return !t.is_done; }).length;
      elCount.textContent = remaining + ' pending';
    }

    if (!filtered.length) {
      elList.innerHTML = '<div class="empty-box">✓ No pending tasks matching this filter</div>';
      return;
    }

    elList.innerHTML = filtered.map(function(t) {
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
          // Mark task done in memory
          pendingTask.is_done = true;
          var row = document.getElementById('todo-row-' + pendingTask.id);
          if (row) {
            row.classList.add('done');
            var actions = row.querySelector('.actions');
            if (actions) {
              actions.innerHTML = '<span class="done-tag">✓ Completed</span>';
            }
          }
          closeModal();
          // Update count badge
          var elCount = document.getElementById('ba-dash-todo-count');
          if (elCount) {
            var remaining = allTodos.filter(function(t) { return !t.is_done; }).length;
            elCount.textContent = remaining + ' pending';
          }
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
