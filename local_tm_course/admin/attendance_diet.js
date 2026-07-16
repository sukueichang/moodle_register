(function() {
    'use strict';

    function init() {
        var cfg = window.tmAttendanceDietConfig;
        if (!cfg || !cfg.canEdit) {
            return;
        }

        var activeEditor = null;

        function closeEditor() {
            if (activeEditor && activeEditor.parentNode) {
                activeEditor.parentNode.removeChild(activeEditor);
            }
            activeEditor = null;
        }

        function buildLabel(choice, note) {
            if (choice === 'A') {
                return note ? cfg.strings.meat + ' / ' + note : cfg.strings.meat;
            }
            if (choice === 'B') {
                return note ? cfg.strings.vegetarian + ' / ' + note : cfg.strings.vegetarian;
            }
            return cfg.strings.noChoice;
        }

        function refreshSummaryCounts() {
            var meat = 0;
            var veg = 0;
            var unknown = 0;
            document.querySelectorAll('.tm-attendance-diet-line[data-diet-choice]').forEach(function(el) {
                var choice = (el.getAttribute('data-diet-choice') || '').toUpperCase();
                if (choice === 'A') {
                    meat++;
                } else if (choice === 'B') {
                    veg++;
                } else {
                    unknown++;
                }
            });

            var meatEl = document.getElementById('tm-diet-enrolled-meat');
            var vegEl = document.getElementById('tm-diet-enrolled-vegetarian');
            var unknownEl = document.getElementById('tm-diet-enrolled-unknown');
            if (meatEl) {
                meatEl.textContent = String(meat);
            }
            if (vegEl) {
                vegEl.textContent = String(veg);
            }
            if (unknownEl) {
                unknownEl.textContent = String(unknown);
            }
        }

        function openEditor(target) {
            if (!target || target.classList.contains('tm-attendance-diet-editing')) {
                return;
            }
            closeEditor();

            var enrolid = target.getAttribute('data-enrolid');
            var choice = (target.getAttribute('data-diet-choice') || 'A').toUpperCase();
            if (choice !== 'A' && choice !== 'B') {
                choice = 'A';
            }
            var note = target.getAttribute('data-diet-note') || '';

            var editor = document.createElement('div');
            editor.className = 'tm-attendance-diet-editor';

            var row = document.createElement('div');
            row.className = 'tm-attendance-diet-editor-row';

            var meatLabel = document.createElement('label');
            meatLabel.className = 'mr-2 mb-0';
            var meatRadio = document.createElement('input');
            meatRadio.type = 'radio';
            meatRadio.name = 'tm_diet_choice_' + enrolid;
            meatRadio.value = 'A';
            meatRadio.checked = choice === 'A';
            meatLabel.appendChild(meatRadio);
            meatLabel.appendChild(document.createTextNode(' ' + cfg.strings.meat));

            var vegLabel = document.createElement('label');
            vegLabel.className = 'mb-0';
            var vegRadio = document.createElement('input');
            vegRadio.type = 'radio';
            vegRadio.name = 'tm_diet_choice_' + enrolid;
            vegRadio.value = 'B';
            vegRadio.checked = choice === 'B';
            vegLabel.appendChild(vegRadio);
            vegLabel.appendChild(document.createTextNode(' ' + cfg.strings.vegetarian));

            row.appendChild(meatLabel);
            row.appendChild(vegLabel);

            var noteInput = document.createElement('input');
            noteInput.type = 'text';
            noteInput.className = 'form-control form-control-sm mt-1 tm-attendance-diet-note-input';
            noteInput.maxLength = 255;
            noteInput.placeholder = cfg.strings.specialNote;
            noteInput.value = note;

            var actions = document.createElement('div');
            actions.className = 'tm-attendance-diet-editor-actions mt-1';

            var saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'btn btn-sm btn-tm-primary tm-attendance-diet-save';
            saveBtn.textContent = cfg.strings.save;

            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn btn-sm btn-secondary tm-attendance-diet-cancel';
            cancelBtn.textContent = cfg.strings.cancel;

            actions.appendChild(saveBtn);
            actions.appendChild(cancelBtn);

            editor.appendChild(row);
            editor.appendChild(noteInput);
            editor.appendChild(actions);

            target.classList.add('tm-attendance-diet-editing');
            target.appendChild(editor);
            activeEditor = editor;

            if (noteInput) {
                noteInput.focus();
            }

            cancelBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                target.classList.remove('tm-attendance-diet-editing');
                closeEditor();
            });

            saveBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var selected = editor.querySelector('input[type="radio"]:checked');
                var newChoice = selected ? selected.value : 'A';
                var newNote = noteInput ? noteInput.value.trim() : '';
                saveBtn.disabled = true;

                var body = new URLSearchParams();
                body.set('sesskey', cfg.sesskey);
                body.set('sessionid', String(cfg.sessionid));
                body.set('action', 'update_diet');
                body.set('ajax', '1');
                body.set('enrolid', enrolid);
                body.set('diet_choice', newChoice);
                body.set('diet_special_note', newNote);

                fetch(cfg.postUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: body.toString(),
                    credentials: 'same-origin'
                })
                    .then(function(resp) {
                        return resp.json();
                    })
                    .then(function(data) {
                        if (!data || !data.ok) {
                            throw new Error((data && data.error) ? data.error : cfg.strings.saveError);
                        }

                        target.setAttribute('data-diet-choice', data.choice || newChoice);
                        target.setAttribute('data-diet-note', data.special_note || newNote);
                        var labelEl = target.querySelector('.tm-attendance-diet-label');
                        if (labelEl) {
                            labelEl.textContent = data.label || buildLabel(newChoice, newNote);
                        }
                        target.classList.remove('tm-attendance-diet-editing');
                        closeEditor();
                        refreshSummaryCounts();
                    })
                    .catch(function(err) {
                        window.alert(err.message || cfg.strings.saveError);
                        saveBtn.disabled = false;
                    });
            });
        }

        document.addEventListener('click', function(e) {
            if (activeEditor && !activeEditor.contains(e.target)) {
                var editing = document.querySelector('.tm-attendance-diet-editing');
                if (editing && !editing.contains(e.target)) {
                    editing.classList.remove('tm-attendance-diet-editing');
                    closeEditor();
                }
            }
        });

        document.querySelectorAll('.tm-attendance-diet-editable').forEach(function(el) {
            el.addEventListener('click', function(e) {
                if (e.target.closest('.tm-attendance-diet-editor')) {
                    return;
                }
                e.preventDefault();
                openEditor(el);
            });
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openEditor(el);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
