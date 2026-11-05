/**
 * Zoho Cliq Notification Templates interactive controller.
 *
 * Provides collapsible section/stage handling, placeholder insertion,
 * default resets, and live Cliq card previews without inline scripts.
 *
 * @module     local_batchanalytics/cliq_templates
 * @package    local_batchanalytics
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

    var lastFocusedElement = null;

    var mockData = {
        '{batch_name}': '25002A',
        '{module_name}': 'Advance C Programming',
        '{task_name}': 'Quiz 1 Evaluation',
        '{activity_name}': 'Group Discussion 1',
        '{due_date}': '15 Oct 2026',
        '{mentor_name}': 'Prakash Narayanan',
        '{sse_name}': 'Divya Krishnan',
        '{pm_name}': 'Muruganantham V',
        '{overdue_days}': '5',
        '{completed_by}': 'Prakash Narayanan',
        '{completion_date}': '12 Oct 2026',
        '{link}': 'https://lms.example.com/local/batchanalytics/module.php?courseid=25',
        '{url}': 'https://lms.example.com/local/batchanalytics/module.php?courseid=25'
    };

    /**
     * Render mock placeholder tokens in a string.
     *
     * @param {string} str
     * @return {string}
     */
    function renderMockText(str) {
        if (!str) {
            return '';
        }
        var res = str;
        for (var k in mockData) {
            if (Object.prototype.hasOwnProperty.call(mockData, k)) {
                res = res.split(k).join(mockData[k]);
            }
        }
        return res;
    }

    /**
     * Update live preview DOM nodes for a specific stage key.
     *
     * @param {string} key
     */
    function updateLivePreview(key) {
        var subEl = document.getElementById('tpl-sub-' + key);
        var bodEl = document.getElementById('tpl-bod-' + key);

        var rawSub = subEl ? subEl.value : '';
        var rawBod = bodEl ? bodEl.value : '';

        var titleEl = document.getElementById('preview-title-' + key);
        var bodyEl = document.getElementById('preview-body-' + key);

        if (titleEl) {
            titleEl.textContent = renderMockText(rawSub);
        }
        if (bodyEl) {
            bodyEl.textContent = renderMockText(rawBod);
        }
    }

    /**
     * Toggle visibility of the live preview for a stage card.
     *
     * @param {string} key
     */
    function toggleLivePreview(key) {
        var preview = document.getElementById('preview-' + key);
        if (!preview) {
            return;
        }

        if (preview.classList.contains('visible')) {
            preview.classList.remove('visible');
        } else {
            updateLivePreview(key);
            preview.classList.add('visible');
        }
    }

    /**
     * Insert a placeholder token into the active input/textarea.
     *
     * @param {string} stageKey
     * @param {string} token
     */
    function insertPlaceholder(stageKey, token) {
        var subEl = document.getElementById('tpl-sub-' + stageKey);
        var bodEl = document.getElementById('tpl-bod-' + stageKey);
        var target = lastFocusedElement;

        if (!target || (target !== subEl && target !== bodEl)) {
            target = bodEl;
        }
        if (!target) {
            return;
        }

        var start = target.selectionStart || 0;
        var end = target.selectionEnd || 0;
        var val = target.value;

        target.value = val.substring(0, start) + token + val.substring(end);
        target.focus();
        target.selectionStart = target.selectionEnd = start + token.length;

        var preview = document.getElementById('preview-' + stageKey);
        if (preview && preview.classList.contains('visible')) {
            updateLivePreview(stageKey);
        }
    }

    /**
     * Reset a stage card's inputs to its default template texts.
     *
     * @param {string} key
     */
    function resetStageToDefault(key) {
        var card = document.getElementById('stage-card-' + key);
        if (!card) {
            return;
        }

        var defSub = card.getAttribute('data-default-subject') || '';
        var defBod = card.getAttribute('data-default-body') || '';

        var subEl = document.getElementById('tpl-sub-' + key);
        var bodEl = document.getElementById('tpl-bod-' + key);

        if (subEl) {
            subEl.value = defSub;
        }
        if (bodEl) {
            bodEl.value = defBod;
        }

        card.style.transition = 'box-shadow 0.3s ease';
        card.style.boxShadow = '0 0 0 3px rgba(34, 197, 94, 0.4)';
        setTimeout(function() {
            card.style.boxShadow = '';
        }, 800);

        updateLivePreview(key);
    }

    /**
     * Toggle a category section body.
     *
     * @param {string} catKey
     */
    function toggleSection(catKey) {
        var body = document.getElementById('section-body-' + catKey);
        var chevron = document.getElementById('section-chevron-' + catKey);
        if (body) {
            if (body.style.display === 'none') {
                body.style.display = 'block';
                if (chevron) {
                    chevron.textContent = '▼';
                }
            } else {
                body.style.display = 'none';
                if (chevron) {
                    chevron.textContent = '▶';
                }
            }
        }
    }

    /**
     * Toggle a stage card's expansion state.
     *
     * @param {string} key
     */
    function toggleStage(key) {
        var card = document.getElementById('stage-card-' + key);
        if (card) {
            card.classList.toggle('expanded');
        }
    }

    /**
     * Initialize event handlers.
     */
    function init() {
        document.addEventListener('focusin', function(e) {
            if (e.target && e.target.classList.contains('cliq-active-target')) {
                lastFocusedElement = e.target;
            }
        });

        document.addEventListener('input', function(e) {
            if (e.target && e.target.classList.contains('cliq-active-target')) {
                var stageKey = e.target.getAttribute('data-stage-key');
                if (stageKey) {
                    var preview = document.getElementById('preview-' + stageKey);
                    if (preview && preview.classList.contains('visible')) {
                        updateLivePreview(stageKey);
                    }
                }
            }
        });

        document.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) {
                return;
            }

            var action = btn.getAttribute('data-action');
            var target = btn.getAttribute('data-target') || '';

            switch (action) {
                case 'expand-all':
                    document.querySelectorAll('.cliq-stage-card').forEach(function(card) {
                        card.classList.add('expanded');
                    });
                    document.querySelectorAll('.cliq-section-body').forEach(function(sec) {
                        sec.style.display = 'block';
                    });
                    document.querySelectorAll('.section-chevron').forEach(function(chev) {
                        chev.textContent = '▼';
                    });
                    break;

                case 'collapse-all':
                    document.querySelectorAll('.cliq-stage-card').forEach(function(card) {
                        card.classList.remove('expanded');
                    });
                    break;

                case 'toggle-section':
                    if (target) {
                        toggleSection(target);
                    }
                    break;

                case 'toggle-stage':
                    if (target) {
                        toggleStage(target);
                    }
                    break;

                case 'insert-placeholder':
                    var token = btn.getAttribute('data-token');
                    if (target && token) {
                        insertPlaceholder(target, token);
                    }
                    break;

                case 'reset-stage':
                    if (target) {
                        resetStageToDefault(target);
                    }
                    break;

                case 'toggle-preview':
                    if (target) {
                        toggleLivePreview(target);
                    }
                    break;

                case 'submit-form':
                    var form = document.getElementById('cliq-templates-form');
                    if (form) {
                        form.submit();
                    }
                    break;
            }
        });
    }

    return {
        init: init
    };
});

