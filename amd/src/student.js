/**
 * JavaScript AMD module for Student Details page in local_batchanalytics v3.0.
 *
 * @module     local_batchanalytics/student
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/notification'], function($, Notification) {
    'use strict';

    var StudentPage = {
        config: {},

        init: function(config) {
            var p = document.getElementById('page');
            if (p) p.classList.remove('drawers', 'drag-container');
            var $container = $('#ba-student-container');
            this.config = {
                studentId: parseInt($container.attr('data-studentid') || (config && config.studentId) || 0, 10),
                batchId: parseInt($container.attr('data-batchid') || (config && config.batchId) || 0, 10),
                sesskey: $container.attr('data-sesskey') || (config && config.sesskey) || (M.cfg && M.cfg.sesskey) || '',
                canManageNotes: ($container.attr('data-can-manage-notes') === '1' || (config && config.canManageNotes))
            };
            this.bindEvents();
            this.restoreTabFromHash();
        },

        bindEvents: function() {
            var self = this;

            // 1. Tab Navigation Switcher
            $('.ba-tab-btn').on('click', function(e) {
                e.preventDefault();
                var targetTab = $(this).attr('data-tab');
                self.switchTab(targetTab);
            });

            // 2. Add Counseling Note
            $('#ba-btn-add-note').on('click', function(e) {
                e.preventDefault();
                self.submitCounselingNote();
            });

            // 3. Edit Note: Toggle inline edit box
            $(document).on('click', '.ba-btn-edit-note', function(e) {
                e.preventDefault();
                var noteId = $(this).attr('data-note-id');
                $('#note-view-' + noteId).hide();
                $('#note-edit-box-' + noteId).fadeIn(150);
                $('#note-edit-textarea-' + noteId).focus();
            });

            // 4. Cancel Edit Note
            $(document).on('click', '.ba-btn-cancel-edit', function(e) {
                e.preventDefault();
                var noteId = $(this).attr('data-note-id');
                $('#note-edit-box-' + noteId).hide();
                $('#note-view-' + noteId).fadeIn(150);
            });

            // 5. Save Edited Note (PUT request)
            $(document).on('click', '.ba-btn-save-note', function(e) {
                e.preventDefault();
                var noteId = $(this).attr('data-note-id');
                self.saveCounselingNote(noteId, $(this));
            });

            // 6. Download Profile button (downloads high-fidelity PDF matching prototype UI)
            $('#ba-btn-download-profile').on('click', function(e) {
                e.preventDefault();
                self.downloadProfilePdf($(this));
            });
        },

        switchTab: function(tabName) {
            if (!tabName) {
                return;
            }

            $('.ba-tab-btn').removeClass('active');
            $('.ba-tab-btn[data-tab="' + tabName + '"]').addClass('active');

            $('.ba-tab-panel').removeClass('active');
            $('#panel-' + tabName).addClass('active');

            if (history.replaceState) {
                history.replaceState(null, null, '#' + tabName);
            }
        },

        restoreTabFromHash: function() {
            var hash = window.location.hash;
            if (hash && hash.length > 1) {
                var tabName = hash.substring(1);
                if ($('#panel-' + tabName).length) {
                    this.switchTab(tabName);
                }
            }
        },

        submitCounselingNote: function() {
            var self = this;
            var $input = $('#ba-notes-input');
            var noteBody = $input.val() ? $input.val().trim() : '';

            if (noteBody === '') {
                self.showToast('Please enter a note before saving.', 'error');
                $input.focus();
                return;
            }

            var $btn = $('#ba-btn-add-note');
            var origText = $btn.find('.ba-btn-text').length ? $btn.find('.ba-btn-text').text() : $btn.text();
            $btn.prop('disabled', true).text('Saving…');

            $.ajax({
                url: M.cfg.wwwroot + '/local/batchanalytics/student.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'add_counseling_note',
                    id: self.config.studentId,
                    sesskey: self.config.sesskey || M.cfg.sesskey,
                    note: noteBody
                }
            }).done(function(res) {
                if (res && res.success && res.note) {
                    $input.val('');
                    self.prependNoteItem(res.note);
                    self.showToast('Counseling note added successfully.', 'success');
                } else {
                    self.showToast((res && res.message) ? res.message : 'Failed to save note.', 'error');
                }
            }).fail(function(xhr) {
                var msg = 'An error occurred while adding counseling note.';
                try {
                    var parsed = JSON.parse(xhr.responseText);
                    if (parsed && parsed.message) {
                        msg = parsed.message;
                    }
                } catch (e) {}
                self.showToast(msg, 'error');
            }).always(function() {
                $btn.prop('disabled', false).html('<span class="ba-btn-text">' + origText + '</span>');
            });
        },

        saveCounselingNote: function(noteId, $btn) {
            var self = this;
            var $textarea = $('#note-edit-textarea-' + noteId);
            var updatedText = $textarea.val() ? $textarea.val().trim() : '';

            if (updatedText === '') {
                self.showToast('Note content cannot be empty.', 'error');
                $textarea.focus();
                return;
            }

            var origText = $btn.text();
            $btn.prop('disabled', true).text('Saving…');

            $.ajax({
                url: M.cfg.wwwroot + '/local/batchanalytics/student.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'update_counseling_note',
                    id: self.config.studentId,
                    note_id: noteId,
                    sesskey: self.config.sesskey || M.cfg.sesskey,
                    note: updatedText
                }
            }).done(function(res) {
                if (res && res.success && res.note) {
                    var bodyHtml = $('<div>').text(res.note.body).html().replace(/\n/g, '<br>');
                    $('#note-view-' + noteId).html(bodyHtml).fadeIn(150);
                    $('#note-edit-box-' + noteId).hide();
                    self.showToast('Counseling note updated successfully.', 'success');
                } else {
                    self.showToast((res && res.message) ? res.message : 'Failed to update note.', 'error');
                }
            }).fail(function(xhr) {
                var msg = 'An error occurred while updating counseling note.';
                try {
                    var parsed = JSON.parse(xhr.responseText);
                    if (parsed && parsed.message) {
                        msg = parsed.message;
                    }
                } catch (e) {}
                self.showToast(msg, 'error');
            }).always(function() {
                $btn.prop('disabled', false).text(origText);
            });
        },

        prependNoteItem: function(note) {
            var $feed = $('#ba-counseling-notes-feed');
            $('#ba-empty-notes').hide();

            var noteId = $('<div>').text(note.id).html();
            var title = $('<div>').text(note.title || 'Counseling Notes - LMS').html();
            var author = $('<div>').text(note.author || 'User').html();
            var dateStr = $('<div>').text(note.date_str || 'Just now').html();
            var bodyHtml = $('<div>').text(note.body).html().replace(/\n/g, '<br>');
            var bodyRaw = $('<div>').text(note.body).html();
            var hoursLeft = note.hours_left || 24;

            var html = '<div class="ba-card ba-note-card" id="note-card-' + noteId + '" data-note-id="' + noteId + '">' +
                       '  <div class="ba-note-card-header">' +
                       '    <div class="ba-note-meta-left">' +
                       '      <span class="ba-note-title">' + title + '</span>' +
                       '      <span class="ba-note-dot">·</span>' +
                       '      <span class="ba-note-author">By <strong>' + author + '</strong></span>' +
                       '      <span class="ba-note-dot">·</span>' +
                       '      <span class="ba-note-date">' + dateStr + '</span>' +
                       '    </div>' +
                       '    <div class="ba-note-meta-right">' +
                       '      <span class="ba-note-badge-editable" title="Notes can be modified within 24 hours of creation">' +
                       '        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> ' + hoursLeft + 'h left to edit' +
                       '      </span>' +
                       '      <button type="button" class="ba-btn-edit-note" data-note-id="' + noteId + '">' +
                       '        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg> Edit' +
                       '      </button>' +
                       '    </div>' +
                       '  </div>' +
                       '  <div class="ba-note-body-view" id="note-view-' + noteId + '">' + bodyHtml + '</div>' +
                       '  <div class="ba-note-body-edit" id="note-edit-box-' + noteId + '" style="display: none;">' +
                       '    <textarea class="ba-note-edit-textarea" id="note-edit-textarea-' + noteId + '">' + bodyRaw + '</textarea>' +
                       '    <div class="ba-note-edit-actions">' +
                       '      <button type="button" class="ba-btn-cancel-edit" data-note-id="' + noteId + '">Cancel</button>' +
                       '      <button type="button" class="ba-btn-save-note" data-note-id="' + noteId + '">Save Changes</button>' +
                       '    </div>' +
                       '  </div>' +
                       '</div>';

            var $newElem = $(html).hide();
            $feed.prepend($newElem);
            $newElem.fadeIn(250);

            // Update badge count
            var count = $feed.find('.ba-note-card').length;
            $('.ba-notes-count-badge').text(count + ' Notes');
        },

        showToast: function(message, type) {
            var $container = $('.ba-toast-container');
            if (!$container.length) {
                $container = $('<div class="ba-toast-container"></div>').appendTo('body');
            }

            var toastClass = type === 'success' ? 'success' : 'error';
            var icon = type === 'success' ? '✓' : '⚠️';
            var $toast = $('<div class="ba-toast ' + toastClass + '"><span>' + icon + '</span> <span>' + $('<div>').text(message).html() + '</span></div>');

            $container.append($toast);
            setTimeout(function() {
                $toast.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3500);
        },

        loadPdfLibraries: function(callback) {
            if (window.html2pdf) {
                callback(null);
                return;
            }

            var base = M.cfg.wwwroot + '/local/batchanalytics/amd/src/';

            function loadScript(url, next) {
                var oldDefine = window.define;
                window.define = undefined;
                var script = document.createElement('script');
                script.src = url;
                script.onload = function() {
                    window.define = oldDefine;
                    if (next) {
                        next(null);
                    }
                };
                script.onerror = function() {
                    window.define = oldDefine;
                    if (next) {
                        next(new Error('Failed to load ' + url));
                    }
                };
                document.head.appendChild(script);
            }

            loadScript(base + 'html2pdf.bundle.min.js', function(err) {
                if (err || !window.html2pdf) {
                    callback(err || new Error('html2pdf library could not be loaded'));
                    return;
                }
                callback(null);
            });
        },

        downloadProfilePdf: function($btn) {
            var self = this;
            var originalHtml = $btn.html();
            $btn.html('<span>Generating PDF...</span>').css('pointer-events', 'none');

            self.loadPdfLibraries(function(err) {
                if (err) {
                    $btn.html(originalHtml).css('pointer-events', 'auto');
                    alert('Could not load PDF generation library: ' + err.message);
                    return;
                }

                try {
                    var templateEl = document.getElementById('ba-pdf-export-content');
                    if (!templateEl) {
                        $btn.html(originalHtml).css('pointer-events', 'auto');
                        alert('PDF export template not found on page.');
                        return;
                    }

                    var root = templateEl.firstElementChild.cloneNode(true);
                    root.classList.add('ba-pdf-render-root');

                    var studentName = $(root).find('.name, .pmain h1').text().trim().replace(/[^a-zA-Z0-9_-]/g, '_') || 'student';
                    var fileName = studentName + '_profile.pdf';

                    var opt = {
                        margin:      [12, 12, 12, 12],
                        filename:    fileName,
                        image:       { type: 'jpeg', quality: 0.98 },
                        html2canvas: { scale: 2, scrollX: 0, scrollY: 0, useCORS: true, backgroundColor: '#ffffff' },
                        jsPDF:       { unit: 'mm', format: 'a4', orientation: 'portrait' },
                        pagebreak:   { mode: ['css', 'legacy'], avoid: ['.card', 'tr'] }
                    };

                    window.html2pdf().set(opt).from(root).save().then(function() {
                        $btn.html(originalHtml).css('pointer-events', 'auto');
                    }).catch(function(pdfErr) {
                        console.error('PDF generation error:', pdfErr);
                        $btn.html(originalHtml).css('pointer-events', 'auto');
                        alert('Could not generate the PDF.');
                    });
                } catch (ex) {
                    console.error('PDF error:', ex);
                    $btn.html(originalHtml).css('pointer-events', 'auto');
                    alert('Error preparing PDF generation: ' + ex.message);
                }
            });
        }
    };

    return {
        init: function(config) {
            StudentPage.init(config);
        }
    };
});
