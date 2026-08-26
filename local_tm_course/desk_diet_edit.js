/**
 * Inline diet editor for desk boards (admin enrolments / sales batch pick).
 *
 * Expects window.tmDeskDietCfg = {
 *   api: string,
 *   sesskey: string,
 *   strings: { meat, vegetarian, noChoice, clickEdit, save, cancel, failed }
 * }
 *
 * @package local_tm_course
 */
(function () {
    'use strict';

    var cfg = window.tmDeskDietCfg;
    if (!cfg || !cfg.api) {
        return;
    }
    var S = cfg.strings || {};

    var activeEditor = null;

    function closeEditor() {
        if (activeEditor && activeEditor.parentNode) {
            activeEditor.parentNode.removeChild(activeEditor);
        }
        activeEditor = null;
    }

    function buildLabel(choice, note) {
        if (choice === 'A') {
            return note ? (S.meat + ' / ' + note) : S.meat;
        }
        if (choice === 'B') {
            return note ? (S.vegetarian + ' / ' + note) : S.vegetarian;
        }
        return S.noChoice || '—';
    }

    function openEditor(target) {
        if (!target || !target.classList.contains('tm-desk-diet-editable')) {
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
        editor.className = 'tm-desk-diet-editor';

        var row = document.createElement('div');
        row.className = 'tm-desk-diet-editor-row';

        var meatLabel = document.createElement('label');
        meatLabel.className = 'mr-2 mb-0';
        var meatRadio = document.createElement('input');
        meatRadio.type = 'radio';
        meatRadio.name = 'tm_desk_diet_' + enrolid;
        meatRadio.value = 'A';
        meatRadio.checked = (choice === 'A');
        meatLabel.appendChild(meatRadio);
        meatLabel.appendChild(document.createTextNode(' ' + (S.meat || 'A')));

        var vegLabel = document.createElement('label');
        vegLabel.className = 'mr-2 mb-0';
        var vegRadio = document.createElement('input');
        vegRadio.type = 'radio';
        vegRadio.name = 'tm_desk_diet_' + enrolid;
        vegRadio.value = 'B';
        vegRadio.checked = (choice === 'B');
        vegLabel.appendChild(vegRadio);
        vegLabel.appendChild(document.createTextNode(' ' + (S.vegetarian || 'B')));

        row.appendChild(meatLabel);
        row.appendChild(vegLabel);
        editor.appendChild(row);

        var noteInput = document.createElement('input');
        noteInput.type = 'text';
        noteInput.className = 'form-control form-control-sm mt-1';
        noteInput.maxLength = 255;
        noteInput.value = note;
        noteInput.placeholder = S.specialNote || '';
        editor.appendChild(noteInput);

        var actions = document.createElement('div');
        actions.className = 'mt-1 d-flex gap-1';
        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'btn btn-sm btn-tm-primary';
        saveBtn.textContent = S.save || 'Save';
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn btn-sm btn-secondary';
        cancelBtn.textContent = S.cancel || 'Cancel';
        actions.appendChild(saveBtn);
        actions.appendChild(cancelBtn);
        editor.appendChild(actions);

        target.style.display = 'none';
        target.parentNode.insertBefore(editor, target.nextSibling);
        activeEditor = editor;

        cancelBtn.addEventListener('click', function () {
            target.style.display = '';
            closeEditor();
        });

        saveBtn.addEventListener('click', function () {
            var newChoice = meatRadio.checked ? 'A' : (vegRadio.checked ? 'B' : '');
            if (newChoice !== 'A' && newChoice !== 'B') {
                return;
            }
            var newNote = String(noteInput.value || '').trim();
            saveBtn.disabled = true;

            var body = new URLSearchParams();
            body.set('enrolid', String(enrolid));
            body.set('diet_choice', newChoice);
            body.set('special_note', newNote);
            body.set('sesskey', cfg.sesskey || '');

            fetch(cfg.api, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            }).then(function (res) {
                return res.json().then(function (data) {
                    return {ok: res.ok && data && data.ok, data: data};
                });
            }).then(function (result) {
                if (!result.ok) {
                    window.alert((result.data && result.data.error) || S.failed || 'Failed');
                    saveBtn.disabled = false;
                    return;
                }
                var label = (result.data && result.data.label)
                    ? result.data.label
                    : buildLabel(newChoice, newNote);
                target.setAttribute('data-diet-choice', newChoice);
                target.setAttribute('data-diet-note', newNote);
                target.textContent = label;
                target.title = S.clickEdit || '';
                target.style.display = '';
                closeEditor();
            }).catch(function () {
                window.alert(S.failed || 'Failed');
                saveBtn.disabled = false;
            });
        });
    }

    document.addEventListener('click', function (ev) {
        var target = ev.target && ev.target.closest
            ? ev.target.closest('.tm-desk-diet-editable')
            : null;
        if (target) {
            ev.preventDefault();
            openEditor(target);
        }
    });
})();
