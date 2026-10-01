/**
 * Client-side script for Zoho Cliq Dynamic Notification Templates & Workflow Management in local_batchanalytics
 *
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var container = document.getElementById('ba-cliq-templates-container');
        if (!container) return;

        var sesskey = container.getAttribute('data-sesskey') || '';
        var placeholdersJson = container.getAttribute('data-placeholders') || '{}';
        var rulesJson = container.getAttribute('data-rules') || '{}';

        var placeholders = {};
        var workflowRules = {};
        try {
            placeholders = JSON.parse(placeholdersJson);
            workflowRules = JSON.parse(rulesJson);
        } catch (e) {
            console.error('Error parsing metadata:', e);
        }

        // Toast Helper
        var toastEl = document.getElementById('ba-cliq-toast');
        function showToast(message, isError) {
            if (!toastEl) return;
            toastEl.textContent = message;
            toastEl.className = 'ba-cliq-toast show ' + (isError ? 'error' : 'success');
            setTimeout(function() {
                toastEl.classList.remove('show');
            }, 3500);
        }

        // Markdown / Placeholder renderer for live preview
        function renderLivePreview(rawText) {
            if (!rawText) return '<span style="color:#94a3b8; font-style:italic;">(Empty message)</span>';

            var rendered = rawText;

            // Replace placeholders with sample values
            for (var ph in placeholders) {
                if (placeholders.hasOwnProperty(ph)) {
                    var sampleVal = placeholders[ph].sample || ph;
                    rendered = rendered.split(ph).join(sampleVal);
                }
            }

            // Escape HTML
            var div = document.createElement('div');
            div.textContent = rendered;
            var escaped = div.innerHTML;

            // Format **bold** to <strong>bold</strong>
            escaped = escaped.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');

            // Format links
            escaped = escaped.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');

            return escaped;
        }

        // Attach live preview updates to all card textareas
        function initCardPreviews() {
            var textareas = container.querySelectorAll('.ba-cliq-textarea');
            textareas.forEach(function(ta) {
                var card = ta.closest('.ba-cliq-card');
                if (!card) return;
                var previewEl = card.querySelector('.ba-cliq-rendered-text');

                function update() {
                    if (previewEl) {
                        previewEl.innerHTML = renderLivePreview(ta.value);
                    }
                }

                ta.removeEventListener('input', update);
                ta.addEventListener('input', update);
                update();
            });
        }
        initCardPreviews();

        // Card placeholder chips click
        container.querySelectorAll('.ba-cliq-chip').forEach(function(chip) {
            chip.addEventListener('click', function() {
                var ph = this.getAttribute('data-ph');
                var card = this.closest('.ba-cliq-card');
                if (!card || !ph) return;

                var ta = card.querySelector('.ba-cliq-textarea');
                if (!ta) return;

                var start = ta.selectionStart || 0;
                var end = ta.selectionEnd || 0;
                var val = ta.value;

                ta.value = val.substring(0, start) + ph + val.substring(end);
                ta.selectionStart = ta.selectionEnd = start + ph.length;
                ta.focus();

                var ev = new Event('input', { bubbles: true });
                ta.dispatchEvent(ev);
            });
        });

        // ---------------------------------------------------------------------
        // Modal: Add / Edit Template
        // ---------------------------------------------------------------------
        var tmplModal = document.getElementById('ba-cliq-template-modal');
        var tmplModalTitle = document.getElementById('ba-cliq-tmpl-modal-title');
        var tmplFormMode = document.getElementById('ba-cliq-tmpl-form-mode');
        var tmplIdInput = document.getElementById('ba-cliq-tmpl-id');
        var tmplTitleInput = document.getElementById('ba-cliq-tmpl-title');
        var tmplRecipientSelect = document.getElementById('ba-cliq-tmpl-recipient');
        var tmplSeveritySelect = document.getElementById('ba-cliq-tmpl-severity');
        var tmplConditionSelect = document.getElementById('ba-cliq-tmpl-condition');
        var tmplOffsetInput = document.getElementById('ba-cliq-tmpl-offset');
        var tmplEscalateToSelect = document.getElementById('ba-cliq-tmpl-escalate-to');
        var tmplEscalateDaysInput = document.getElementById('ba-cliq-tmpl-escalate-days');
        var tmplMessageText = document.getElementById('ba-cliq-tmpl-message');
        var tmplEnabledCheck = document.getElementById('ba-cliq-tmpl-enabled');
        var tmplModalClose = document.getElementById('ba-cliq-tmpl-modal-close');
        var tmplModalCancel = document.getElementById('ba-cliq-tmpl-modal-cancel');
        var tmplModalSave = document.getElementById('ba-cliq-tmpl-modal-save');

        function openAddTemplateModal() {
            if (!tmplModal) return;
            tmplFormMode.value = 'add';
            tmplModalTitle.textContent = 'Add Notification Template';
            tmplIdInput.value = '';
            tmplIdInput.readOnly = false;
            tmplTitleInput.value = '';
            tmplRecipientSelect.value = 'CM';
            tmplSeveritySelect.value = 'reminder';
            tmplConditionSelect.value = 'days_before_due';
            tmplOffsetInput.value = '1';
            tmplEscalateToSelect.value = 'PM';
            tmplEscalateDaysInput.value = '2';
            tmplMessageText.value = '🔔 **Reminder:** {activity} for {batch_id} / {module} is due {due_date}.\nPlease complete it in the LMS.\n{lms_link}';
            tmplEnabledCheck.checked = true;
            tmplModal.classList.add('open');
            tmplIdInput.focus();
        }

        function openEditTemplateModal(card) {
            if (!tmplModal || !card) return;
            var tid = card.getAttribute('data-id');
            var rawTmpl = card.getAttribute('data-template');
            var rawRule = card.getAttribute('data-rule');

            var tmpl = {};
            var rule = {};
            try {
                if (rawTmpl) tmpl = JSON.parse(rawTmpl);
                if (rawRule) rule = JSON.parse(rawRule);
            } catch (e) {}

            tmplFormMode.value = 'edit';
            tmplModalTitle.textContent = 'Edit Template: ' + tid;
            tmplIdInput.value = tid;
            tmplIdInput.readOnly = true;
            tmplTitleInput.value = tmpl.title || card.querySelector('.ba-cliq-trigger-title').textContent.trim();
            tmplRecipientSelect.value = tmpl.recipient || card.getAttribute('data-role') || 'CM';
            tmplSeveritySelect.value = tmpl.severity || 'info';

            var metricEl = card.querySelector('.ba-cliq-rule-metric');
            var cvalEl = card.querySelector('.ba-cliq-rule-cval');
            var escToEl = card.querySelector('.ba-cliq-rule-escalateto');
            var escDaysEl = card.querySelector('.ba-cliq-rule-escalatedays');
            var enabledEl = card.querySelector('.ba-cliq-rule-enabled');
            var textarea = card.querySelector('.ba-cliq-textarea');

            tmplConditionSelect.value = metricEl ? metricEl.value : (rule.condition_metric || 'days_before_due');
            tmplOffsetInput.value = cvalEl ? cvalEl.value : (rule.condition_value || 1);
            tmplEscalateToSelect.value = escToEl ? escToEl.value : (rule.escalate_to || '');
            tmplEscalateDaysInput.value = escDaysEl ? escDaysEl.value : (rule.escalate_days || 2);
            tmplMessageText.value = textarea ? textarea.value : (tmpl.template || '');
            tmplEnabledCheck.checked = enabledEl ? enabledEl.checked : true;

            tmplModal.classList.add('open');
            tmplTitleInput.focus();
        }

        function closeTemplateModal() {
            if (!tmplModal) return;
            tmplModal.classList.remove('open');
        }

        // Open Add Modal buttons
        var openAddBtn = document.getElementById('ba-cliq-open-add-modal');
        if (openAddBtn) openAddBtn.addEventListener('click', openAddTemplateModal);

        container.querySelectorAll('.ba-cliq-open-add-modal-btn').forEach(function(btn) {
            btn.addEventListener('click', openAddTemplateModal);
        });

        if (tmplModalClose) tmplModalClose.addEventListener('click', closeTemplateModal);
        if (tmplModalCancel) tmplModalCancel.addEventListener('click', closeTemplateModal);
        if (tmplModal) {
            tmplModal.addEventListener('click', function(e) {
                if (e.target === tmplModal) closeTemplateModal();
            });
        }

        // Modal chips click
        container.querySelectorAll('.ba-cliq-modal-chip').forEach(function(chip) {
            chip.addEventListener('click', function() {
                var ph = this.getAttribute('data-ph');
                if (!tmplMessageText || !ph) return;

                var start = tmplMessageText.selectionStart || 0;
                var end = tmplMessageText.selectionEnd || 0;
                var val = tmplMessageText.value;

                tmplMessageText.value = val.substring(0, start) + ph + val.substring(end);
                tmplMessageText.selectionStart = tmplMessageText.selectionEnd = start + ph.length;
                tmplMessageText.focus();
            });
        });

        // Save Template handler
        if (tmplModalSave) {
            tmplModalSave.addEventListener('click', function() {
                var tid = tmplIdInput.value.trim().toUpperCase();
                var title = tmplTitleInput.value.trim();
                var recipient = tmplRecipientSelect.value;
                var recipientTitle = tmplRecipientSelect.options[tmplRecipientSelect.selectedIndex].text;
                var severity = tmplSeveritySelect.value;
                var conditionMetric = tmplConditionSelect.value;
                var offset = parseInt(tmplOffsetInput.value, 10) || 0;
                var escalateTo = tmplEscalateToSelect.value;
                var escalateDays = parseInt(tmplEscalateDaysInput.value, 10) || 2;
                var message = tmplMessageText.value.trim();
                var isEnabled = tmplEnabledCheck.checked ? 1 : 0;

                if (!tid) {
                    alert('Please specify a unique Template ID (e.g. BATCH-START or CM-DUE-01).');
                    tmplIdInput.focus();
                    return;
                }
                if (!message) {
                    alert('Please enter the message body template.');
                    tmplMessageText.focus();
                    return;
                }

                tmplModalSave.disabled = true;
                tmplModalSave.textContent = 'Saving…';

                var formData = new FormData();
                formData.append('action', 'savetemplate');
                formData.append('sesskey', sesskey);
                formData.append('id', tid);
                formData.append('title', title || tid);
                formData.append('recipient', recipient);
                formData.append('recipient_title', recipientTitle);
                formData.append('severity', severity);
                formData.append('condition_metric', conditionMetric);
                formData.append('days_offset', offset);
                formData.append('recipient_role', recipient);
                formData.append('escalate_to', escalateTo);
                formData.append('escalate_days', escalateDays);
                formData.append('template', message);
                formData.append('enabled', isEnabled);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    tmplModalSave.disabled = false;
                    tmplModalSave.textContent = 'Save Template';

                    if (data && data.success) {
                        showToast('Template ' + tid + ' saved successfully!');
                        closeTemplateModal();
                        setTimeout(function() {
                            window.location.reload();
                        }, 500);
                    } else {
                        showToast(data.message || 'Error saving template', true);
                    }
                })
                .catch(function(err) {
                    tmplModalSave.disabled = false;
                    tmplModalSave.textContent = 'Save Template';
                    showToast('Network error: ' + err.message, true);
                });
            });
        }

        // Card Edit buttons
        container.querySelectorAll('.ba-cliq-card-edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var card = this.closest('.ba-cliq-card');
                openEditTemplateModal(card);
            });
        });

        // Card Duplicate buttons
        container.querySelectorAll('.ba-cliq-card-dup-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var card = this.closest('.ba-cliq-card');
                if (!card) return;
                var tid = card.getAttribute('data-id');
                openEditTemplateModal(card);
                tmplFormMode.value = 'add';
                tmplModalTitle.textContent = 'Duplicate Template';
                tmplIdInput.value = tid + '-COPY';
                tmplIdInput.readOnly = false;
            });
        });

        // Card Delete buttons
        container.querySelectorAll('.ba-cliq-card-del-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var card = this.closest('.ba-cliq-card');
                if (!card) return;
                var tid = card.getAttribute('data-id');

                if (!confirm('Are you sure you want to delete template "' + tid + '"? This will remove its workflow rule as well.')) {
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'deletetemplate');
                formData.append('sesskey', sesskey);
                formData.append('id', tid);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        showToast('Template ' + tid + ' deleted successfully.');
                        card.remove();
                        // Check if any cards left
                        var remaining = container.querySelectorAll('.ba-cliq-card');
                        if (remaining.length === 0) {
                            var emptyState = document.getElementById('ba-cliq-empty-state');
                            if (emptyState) emptyState.style.display = 'block';
                            var listEl = document.getElementById('ba-cliq-templates-list');
                            if (listEl) listEl.style.display = 'none';
                        }
                    } else {
                        showToast(data.message || 'Error deleting template', true);
                    }
                })
                .catch(function(err) {
                    showToast('Network error: ' + err.message, true);
                });
            });
        });

        // Clear All button
        var clearAllBtn = document.getElementById('ba-cliq-clear-all');
        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', function() {
                if (!confirm('Are you sure you want to clear ALL templates and workflow rules? This action cannot be undone.')) {
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'clearall');
                formData.append('sesskey', sesskey);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        showToast('All templates and rules cleared.');
                        setTimeout(function() { window.location.reload(); }, 600);
                    } else {
                        showToast(data.message || 'Error clearing templates', true);
                    }
                })
                .catch(function(err) {
                    showToast('Network error: ' + err.message, true);
                });
            });
        }

        // Save All Changes (bulk save inline tweaks)
        var saveAllBtn = document.getElementById('ba-cliq-save-all');
        if (saveAllBtn) {
            saveAllBtn.addEventListener('click', function() {
                var cards = container.querySelectorAll('.ba-cliq-card');
                var templatesPayload = [];
                var rulesPayload = [];

                cards.forEach(function(card) {
                    var tid = card.getAttribute('data-id');
                    var ta = card.querySelector('.ba-cliq-textarea');
                    var titleEl = card.querySelector('.ba-cliq-trigger-title');
                    var roleBadge = card.querySelector('.ba-cliq-role-badge');
                    var ruleEnabled = card.querySelector('.ba-cliq-rule-enabled');
                    var ruleMetric = card.querySelector('.ba-cliq-rule-metric');
                    var ruleCval = card.querySelector('.ba-cliq-rule-cval');
                    var ruleRecipient = card.querySelector('.ba-cliq-rule-recipient');
                    var ruleEscTo = card.querySelector('.ba-cliq-rule-escalateto');
                    var ruleEscDays = card.querySelector('.ba-cliq-rule-escalatedays');

                    if (tid && ta) {
                        templatesPayload.push({
                            id: tid,
                            title: titleEl ? titleEl.textContent.trim() : tid,
                            recipient: card.getAttribute('data-role') || 'CM',
                            recipient_title: roleBadge ? roleBadge.textContent.trim() : 'Class Mentor',
                            template: ta.value,
                            threshold_days: ruleCval ? (parseInt(ruleCval.value, 10) || 0) : 0,
                            enabled: ruleEnabled ? (ruleEnabled.checked ? 1 : 0) : 1
                        });

                        rulesPayload.push({
                            template_id: tid,
                            enabled: ruleEnabled ? (ruleEnabled.checked ? 1 : 0) : 1,
                            trigger_type: 'schedule',
                            condition_metric: ruleMetric ? ruleMetric.value : 'days_before_due',
                            condition_value: ruleCval ? (parseInt(ruleCval.value, 10) || 0) : 0,
                            recipient_type: ruleRecipient ? ruleRecipient.value : 'CM',
                            escalate_to: ruleEscTo ? ruleEscTo.value : '',
                            escalate_days: ruleEscDays ? (parseInt(ruleEscDays.value, 10) || 2) : 2,
                            quiet_hours_enabled: 1
                        });
                    }
                });

                saveAllBtn.disabled = true;
                saveAllBtn.textContent = 'Saving…';

                var formData = new FormData();
                formData.append('action', 'savetemplates');
                formData.append('sesskey', sesskey);
                formData.append('templates', JSON.stringify(templatesPayload));
                formData.append('rules', JSON.stringify(rulesPayload));

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    saveAllBtn.disabled = false;
                    saveAllBtn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> Save All Changes';
                    if (data && data.success) {
                        showToast('All templates and workflow rules saved successfully!');
                    } else {
                        showToast(data.message || 'Error saving templates', true);
                    }
                })
                .catch(function(err) {
                    saveAllBtn.disabled = false;
                    showToast('Network error: ' + err.message, true);
                });
            });
        }

        // ---------------------------------------------------------------------
        // Tabs & Search Filtering
        // ---------------------------------------------------------------------
        var tabs = container.querySelectorAll('.ba-cliq-tab');
        var searchInput = document.getElementById('ba-cliq-search');
        var cardsList = container.querySelectorAll('.ba-cliq-card');
        var placeholdersView = document.getElementById('ba-cliq-placeholders-view');
        var templatesListEl = document.getElementById('ba-cliq-templates-list');

        var currentTab = 'all';
        var currentSearch = '';

        function applyFilter() {
            if (placeholdersView) placeholdersView.style.display = 'none';
            if (templatesListEl) templatesListEl.style.display = 'flex';

            if (currentTab === 'placeholders') {
                if (templatesListEl) templatesListEl.style.display = 'none';
                if (placeholdersView) placeholdersView.style.display = 'block';
                return;
            }

            var query = currentSearch.toLowerCase();
            cardsList.forEach(function(card) {
                var role = card.getAttribute('data-role') || '';
                var tid = (card.getAttribute('data-id') || '').toLowerCase();
                var text = (card.innerText || '').toLowerCase();

                var matchesRole = (currentTab === 'all' || role === currentTab);
                var matchesQuery = (!query || tid.indexOf(query) !== -1 || text.indexOf(query) !== -1);

                card.style.display = (matchesRole && matchesQuery) ? 'block' : 'none';
            });
        }

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                tabs.forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');
                currentTab = this.getAttribute('data-tab');
                applyFilter();
            });
        });

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                currentSearch = this.value.trim();
                applyFilter();
            });
        }

        // ---------------------------------------------------------------------
        // Modal: Test Bot Message
        // ---------------------------------------------------------------------
        var testModal = document.getElementById('ba-cliq-test-modal');
        var testModalOpenBtn = document.getElementById('ba-cliq-open-test-modal');
        var testModalCloseBtn = document.getElementById('ba-cliq-modal-close-btn');
        var testModalCancelBtn = document.getElementById('ba-cliq-modal-cancel-btn');
        var testModalSendBtn = document.getElementById('ba-cliq-modal-send-btn');
        var testMessageText = document.getElementById('ba-cliq-test-message');
        var testUseridsInput = document.getElementById('ba-cliq-test-userids');
        var testModalResult = document.getElementById('ba-cliq-modal-result');

        function openTestModal(defaultText) {
            if (!testModal) return;
            if (testModalResult) {
                testModalResult.className = 'ba-cliq-result-box';
                testModalResult.textContent = '';
            }
            if (testMessageText) {
                testMessageText.value = defaultText || '🔔 Test notification from Batch Analytics (Kajal Bot)';
            }
            testModal.classList.add('open');
        }

        function closeTestModal() {
            if (!testModal) return;
            testModal.classList.remove('open');
        }

        if (testModalOpenBtn) {
            testModalOpenBtn.addEventListener('click', function() {
                openTestModal();
            });
        }

        container.querySelectorAll('.ba-cliq-test-single-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var card = this.closest('.ba-cliq-card');
                if (!card) return;
                var ta = card.querySelector('.ba-cliq-textarea');
                var text = ta ? ta.value : '';
                // Resolve placeholders for preview
                var sampleResolved = text;
                for (var ph in placeholders) {
                    if (placeholders.hasOwnProperty(ph)) {
                        sampleResolved = sampleResolved.split(ph).join(placeholders[ph].sample || ph);
                    }
                }
                openTestModal(sampleResolved);
            });
        });

        if (testModalCloseBtn) testModalCloseBtn.addEventListener('click', closeTestModal);
        if (testModalCancelBtn) testModalCancelBtn.addEventListener('click', closeTestModal);
        if (testModal) {
            testModal.addEventListener('click', function(e) {
                if (e.target === testModal) closeTestModal();
            });
        }

        if (testModalSendBtn) {
            testModalSendBtn.addEventListener('click', function() {
                var msg = (testMessageText ? testMessageText.value.trim() : '');
                var userids = (testUseridsInput ? testUseridsInput.value.trim() : '');

                if (!msg) {
                    alert('Please enter a message to test.');
                    return;
                }

                testModalSendBtn.disabled = true;
                testModalSendBtn.textContent = 'Sending…';

                var formData = new FormData();
                formData.append('action', 'testsend');
                formData.append('sesskey', sesskey);
                formData.append('message', msg);
                formData.append('userids', userids);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    testModalSendBtn.disabled = false;
                    testModalSendBtn.textContent = 'Send Test Message';

                    if (testModalResult) {
                        testModalResult.className = 'ba-cliq-result-box ' + (data.success ? 'success' : 'error');
                        testModalResult.textContent = data.message || 'Complete';
                    }
                })
                .catch(function(err) {
                    testModalSendBtn.disabled = false;
                    testModalSendBtn.textContent = 'Send Test Message';
                    if (testModalResult) {
                        testModalResult.className = 'ba-cliq-result-box error';
                        testModalResult.textContent = 'Network or server error: ' + err.message;
                    }
                });
            });
        }

        // ---------------------------------------------------------------------
        // Modal: Dry-Run Simulation Modal Logic
        // ---------------------------------------------------------------------
        var dryrunModal = document.getElementById('ba-cliq-dryrun-modal');
        var dryrunOpenBtn = document.getElementById('ba-cliq-open-dryrun-modal');
        var dryrunCloseBtn = document.getElementById('ba-cliq-dryrun-close-btn');
        var dryrunCancelBtn = document.getElementById('ba-cliq-dryrun-cancel-btn');
        var dryrunRefreshBtn = document.getElementById('ba-cliq-dryrun-refresh-btn');
        var dryrunSummaryEl = document.getElementById('ba-cliq-dryrun-summary');
        var dryrunResultsEl = document.getElementById('ba-cliq-dryrun-results');

        function openDryRunModal() {
            if (!dryrunModal) return;
            dryrunModal.classList.add('open');
            runDryRunScan();
        }

        function closeDryRunModal() {
            if (!dryrunModal) return;
            dryrunModal.classList.remove('open');
        }

        if (dryrunCloseBtn) dryrunCloseBtn.addEventListener('click', closeDryRunModal);
        if (dryrunCancelBtn) dryrunCancelBtn.addEventListener('click', closeDryRunModal);
        if (dryrunModal) {
            dryrunModal.addEventListener('click', function(e) {
                if (e.target === dryrunModal) closeDryRunModal();
            });
        }
        if (dryrunOpenBtn) dryrunOpenBtn.addEventListener('click', openDryRunModal);
        if (dryrunRefreshBtn) dryrunRefreshBtn.addEventListener('click', runDryRunScan);

        function runDryRunScan() {
            if (!dryrunSummaryEl || !dryrunResultsEl) return;

            dryrunSummaryEl.innerHTML = '<span style="color:#64748b; font-size:12px;">Scanning active batches and live mentor activities…</span>';
            dryrunResultsEl.innerHTML = '<div style="text-align:center; padding:30px; color:#94a3b8;">Scanning in progress…</div>';

            var formData = new FormData();
            formData.append('action', 'dryrun');
            formData.append('sesskey', sesskey);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data || !data.success) {
                    dryrunSummaryEl.innerHTML = '<span style="color:#dc2626; font-size:12px;">Scan failed: ' + (data.message || 'Unknown error') + '</span>';
                    dryrunResultsEl.innerHTML = '';
                    return;
                }

                dryrunSummaryEl.innerHTML = 
                    '<div style="background:#f1f5f9; padding:8px 14px; border-radius:6px; font-size:12px; font-weight:600; color:#334155;">Batches Checked: ' + (data.total_batches_checked || 0) + '</div>' +
                    '<div style="background:#ecfdf5; border:1px solid #a7f3d0; padding:8px 14px; border-radius:6px; font-size:12px; font-weight:600; color:#065f46;">Trigger Conditions Met: ' + (data.matches_found || 0) + '</div>';

                var notifications = data.notifications || [];
                if (notifications.length === 0) {
                    dryrunResultsEl.innerHTML = 
                        '<div style="text-align:center; padding:40px 20px; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px; color:#64748b;">' +
                        '<strong>No matching activity deadlines triggered right now.</strong><br>' +
                        '<span style="font-size:12px;">' + (data.message || 'All active batches are currently up-to-date, or activities have not reached their configured reminder/overdue thresholds.') + '</span>' +
                        '</div>';
                    return;
                }

                var html = '';
                notifications.forEach(function(item) {
                    var badgeClass = 'ba-cliq-sim-badge due';
                    var statusTitle = 'Due in ' + item.days_diff + ' day(s)';
                    if (item.days_diff === 0) {
                        badgeClass = 'ba-cliq-sim-badge today';
                        statusTitle = 'Due Today';
                    } else if (item.days_diff < 0) {
                        badgeClass = 'ba-cliq-sim-badge overdue';
                        statusTitle = 'Overdue by ' + item.overdue_days + ' day(s)';
                    }

                    html += '<div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px;">';
                    html += '  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">';
                    html += '    <div style="display:flex; align-items:center; gap:8px;">';
                    html += '      <span style="background:#0f172a; color:#fff; font-size:11px; font-weight:700; padding:2px 8px; border-radius:4px;">' + (item.template_id || '') + '</span>';
                    html += '      <span style="font-weight:600; font-size:13px; color:#0f172a;">' + (item.batch_code || '') + ' &middot; ' + (item.module || '') + '</span>';
                    html += '    </div>';
                    html += '    <span class="' + badgeClass + '">' + statusTitle + '</span>';
                    html += '  </div>';
                    html += '  <div style="font-size:12px; color:#475569; margin-bottom:6px;">';
                    html += '    <strong>Activity:</strong> ' + (item.activity || '') + ' &middot; <strong>Due:</strong> ' + (item.due_date || '') + '<br>';
                    html += '    <strong>To Whom:</strong> <span style="font-family:monospace; color:#1e40af;">' + (item.recipients || []).join(', ') + '</span>';
                    if (item.escalated) {
                        html += ' <span style="color:#dc2626; font-weight:600;">(⚠️ Escalated to Manager)</span>';
                    }
                    html += '  </div>';
                    html += '  <div style="background:#f8fafc; border-left:3px solid #3b82f6; padding:8px 12px; font-size:11px; white-space:pre-wrap; border-radius:0 4px 4px 0; color:#334155;">' + (item.rendered_text || '') + '</div>';
                    html += '</div>';
                });

                dryrunResultsEl.innerHTML = html;
            })
            .catch(function(err) {
                dryrunSummaryEl.innerHTML = '<span style="color:#dc2626; font-size:12px;">Scan error: ' + err.message + '</span>';
                dryrunResultsEl.innerHTML = '';
            });
        }
    });
})();
