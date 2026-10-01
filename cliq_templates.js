/**
 * Client-side script for Zoho Cliq Notification Templates Management in local_batchanalytics
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
        var defaultTemplatesJson = container.getAttribute('data-default-templates') || '[]';

        var placeholders = {};
        var defaultTemplates = {};
        try {
            placeholders = JSON.parse(placeholdersJson);
            var dList = JSON.parse(defaultTemplatesJson);
            dList.forEach(function(item) {
                if (item.id) defaultTemplates[item.id] = item;
            });
        } catch (e) {
            console.error('Error parsing template metadata:', e);
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

            // Replace placeholders with samples
            for (var ph in placeholders) {
                if (placeholders.hasOwnProperty(ph)) {
                    var sampleVal = placeholders[ph].sample || ph;
                    // Global regex replace
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

        // Attach live preview updates to all textareas
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

            ta.addEventListener('input', update);
            update(); // Initial render
        });

        // Placeholder chip click -> insert into textarea
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

                // Trigger input event
                var ev = new Event('input', { bubbles: true });
                ta.dispatchEvent(ev);
            });
        });

        // Reset single template button
        container.querySelectorAll('.ba-cliq-reset-single').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var tid = this.getAttribute('data-id');
                if (!tid || !defaultTemplates[tid]) return;

                var card = this.closest('.ba-cliq-card');
                if (!card) return;

                var ta = card.querySelector('.ba-cliq-textarea');
                if (ta) {
                    ta.value = defaultTemplates[tid].template || '';
                    var ev = new Event('input', { bubbles: true });
                    ta.dispatchEvent(ev);
                    showToast('Template ' + tid + ' reset to default');
                }
            });
        });

        // Search Input Filter
        var searchInput = document.getElementById('ba-cliq-search');
        var cards = container.querySelectorAll('.ba-cliq-card');
        var currentRoleFilter = 'all';

        function applyFilters() {
            var q = (searchInput ? searchInput.value.trim().toLowerCase() : '');
            var isSpecialTab = (currentRoleFilter === 'matrix' || currentRoleFilter === 'placeholders');

            var matrixView = document.getElementById('ba-cliq-matrix-view');
            var placeholdersView = document.getElementById('ba-cliq-placeholders-view');
            var listWrap = document.getElementById('ba-cliq-templates-list');

            if (matrixView) matrixView.style.display = (currentRoleFilter === 'matrix') ? 'block' : 'none';
            if (placeholdersView) placeholdersView.style.display = (currentRoleFilter === 'placeholders') ? 'block' : 'none';
            if (listWrap) listWrap.style.display = isSpecialTab ? 'none' : 'flex';

            if (isSpecialTab) return;

            var visibleCount = 0;
            cards.forEach(function(c) {
                var role = c.getAttribute('data-role') || '';
                var text = (c.textContent || '').toLowerCase();

                var matchesRole = (currentRoleFilter === 'all' || role === currentRoleFilter);
                var matchesSearch = (!q || text.indexOf(q) !== -1);

                if (matchesRole && matchesSearch) {
                    c.style.display = 'block';
                    visibleCount++;
                } else {
                    c.style.display = 'none';
                }
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }

        // Tab Filtering
        container.querySelectorAll('.ba-cliq-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                container.querySelectorAll('.ba-cliq-tab').forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');
                currentRoleFilter = this.getAttribute('data-tab') || 'all';
                applyFilters();
            });
        });

        // Save All Templates
        var saveBtn = document.getElementById('ba-cliq-save-all');
        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                var payload = [];
                cards.forEach(function(c) {
                    var tid = c.getAttribute('data-id');
                    var ta = c.querySelector('.ba-cliq-textarea');
                    var threshInput = c.querySelector('.ba-cliq-threshold-input');
                    var enabledChk = c.querySelector('.ba-cliq-enabled-chk');

                    if (tid && ta) {
                        payload.push({
                            id: tid,
                            template: ta.value,
                            threshold_days: threshInput ? parseInt(threshInput.value, 10) || 0 : 0,
                            enabled: enabledChk ? enabledChk.checked : true
                        });
                    }
                });

                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving…';

                var formData = new FormData();
                formData.append('action', 'savetemplates');
                formData.append('sesskey', sesskey);
                formData.append('templates', JSON.stringify(payload));

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> Save All Changes';
                    if (data && data.success) {
                        showToast('All 42 templates saved successfully!');
                    } else {
                        showToast(data.message || 'Error saving templates', true);
                    }
                })
                .catch(function(err) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save All Changes';
                    showToast('Failed to save templates: ' + err.message, true);
                });
            });
        }

        // Reset All to Defaults
        var resetAllBtn = document.getElementById('ba-cliq-reset-all');
        if (resetAllBtn) {
            resetAllBtn.addEventListener('click', function() {
                if (!confirm('Are you sure you want to reset all 42 message templates to their default specifications? Any custom modifications will be discarded.')) {
                    return;
                }

                resetAllBtn.disabled = true;
                resetAllBtn.textContent = 'Resetting…';

                var formData = new FormData();
                formData.append('action', 'resettemplates');
                formData.append('sesskey', sesskey);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    resetAllBtn.disabled = false;
                    resetAllBtn.textContent = 'Reset All to Defaults';
                    if (data && data.success) {
                        showToast('Templates reset to default specifications.');
                        setTimeout(function() {
                            window.location.reload();
                        }, 700);
                    } else {
                        showToast(data.message || 'Error resetting templates', true);
                    }
                })
                .catch(function(err) {
                    resetAllBtn.disabled = false;
                    resetAllBtn.textContent = 'Reset All to Defaults';
                    showToast('Failed to reset templates: ' + err.message, true);
                });
            });
        }

        // Copy Placeholder from Dictionary table
        container.querySelectorAll('.ba-cliq-copy-ph').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var ph = this.getAttribute('data-ph');
                if (ph && navigator.clipboard) {
                    navigator.clipboard.writeText(ph).then(function() {
                        showToast('Copied ' + ph + ' to clipboard!');
                    });
                }
            });
        });

        // Test Modal elements
        var testModal = document.getElementById('ba-cliq-test-modal');
        var testModalCloseBtn = document.getElementById('ba-cliq-modal-close-btn');
        var testModalCancelBtn = document.getElementById('ba-cliq-modal-cancel-btn');
        var testModalSendBtn = document.getElementById('ba-cliq-modal-send-btn');
        var testUseridsInput = document.getElementById('ba-cliq-test-userids');
        var testMsgInput = document.getElementById('ba-cliq-test-message');
        var testResultBox = document.getElementById('ba-cliq-modal-result');

        function openTestModal(initialMessage) {
            if (!testModal) return;
            if (testResultBox) {
                testResultBox.style.display = 'none';
                testResultBox.textContent = '';
                testResultBox.className = 'ba-cliq-result-box';
            }
            if (testMsgInput) {
                testMsgInput.value = initialMessage || '🚀 Test message from Batch Analytics Kajal Bot';
            }
            testModal.classList.add('open');
        }

        function closeTestModal() {
            if (!testModal) return;
            testModal.classList.remove('open');
        }

        if (testModalCloseBtn) testModalCloseBtn.addEventListener('click', closeTestModal);
        if (testModalCancelBtn) testModalCancelBtn.addEventListener('click', closeTestModal);
        if (testModal) {
            testModal.addEventListener('click', function(e) {
                if (e.target === testModal) closeTestModal();
            });
        }

        // Global Header Test Bot Message button
        var openTestBtn = document.getElementById('ba-cliq-open-test-modal');
        if (openTestBtn) {
            openTestBtn.addEventListener('click', function() {
                openTestModal('🚀 Test message from Batch Analytics Kajal Bot\nBatch: Sample-2026-Batch\nStatus: Bot API verification successful.');
            });
        }

        // Individual Template Card Test Send button
        container.querySelectorAll('.ba-cliq-test-single-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var card = this.closest('.ba-cliq-card');
                if (!card) return;
                var ta = card.querySelector('.ba-cliq-textarea');
                var rawText = ta ? ta.value : '';

                // Substitute placeholders with sample data so the test message looks authentic
                var rendered = rawText;
                for (var ph in placeholders) {
                    if (placeholders.hasOwnProperty(ph)) {
                        var sampleVal = placeholders[ph].sample || ph;
                        rendered = rendered.split(ph).join(sampleVal);
                    }
                }

                openTestModal(rendered);
            });
        });

        // Modal Send Button action
        if (testModalSendBtn) {
            testModalSendBtn.addEventListener('click', function() {
                var message = (testMsgInput ? testMsgInput.value.trim() : '');
                var userids = (testUseridsInput ? testUseridsInput.value.trim() : '');

                if (!message) {
                    alert('Please enter a message to send.');
                    return;
                }

                testModalSendBtn.disabled = true;
                testModalSendBtn.innerHTML = 'Sending to Cliq…';

                if (testResultBox) {
                    testResultBox.style.display = 'none';
                    testResultBox.className = 'ba-cliq-result-box';
                }

                var formData = new FormData();
                formData.append('action', 'testsend');
                formData.append('sesskey', sesskey);
                formData.append('message', message);
                formData.append('userids', userids);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    testModalSendBtn.disabled = false;
                    testModalSendBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg> Send to Zoho Cliq';

                    if (testResultBox) {
                        var isSuccess = !!(data && data.success);
                        testResultBox.className = 'ba-cliq-result-box ' + (isSuccess ? 'success' : 'error');
                        testResultBox.style.display = 'block';

                        var statusText = (isSuccess ? '✅ SUCCESS' : '❌ FAILED');
                        if (data.http_code) statusText += ' (HTTP ' + data.http_code + ')';
                        var resultDetails = statusText + '\n';
                        if (data.url_used) resultDetails += 'Target URL: ' + data.url_used + '\n';
                        if (data.response) resultDetails += 'API Response: ' + data.response;
                        testResultBox.textContent = resultDetails;
                    }

                    if (data && data.success) {
                        showToast('Delivered to Zoho Cliq successfully!');
                    } else {
                        showToast(data.message || 'Delivery to Zoho Cliq failed', true);
                    }
                })
                .catch(function(err) {
                    testModalSendBtn.disabled = false;
                    testModalSendBtn.innerHTML = 'Send to Zoho Cliq';
                    if (testResultBox) {
                        testResultBox.className = 'ba-cliq-result-box error';
                        testResultBox.style.display = 'block';
                        testResultBox.textContent = 'Network or Script Error: ' + err.message;
                    }
                    showToast('Failed to connect: ' + err.message, true);
                });
            });
        }
    });
})();

