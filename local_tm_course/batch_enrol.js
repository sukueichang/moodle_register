(function () {
    'use strict';

    function tmBatchCloneRow(protoId, tbodyId) {
        var proto = document.getElementById(protoId);
        var tbody = document.getElementById(tbodyId);
        if (!proto || !tbody) {
            return null;
        }
        var tr = proto.cloneNode(true);
        tr.removeAttribute('id');
        tr.classList.remove('tm-batch-is-prototype');
        var ctrls = tr.querySelectorAll('[disabled]');
        var ci;
        for (ci = 0; ci < ctrls.length; ci++) {
            ctrls[ci].removeAttribute('disabled');
            ctrls[ci].removeAttribute('tabindex');
        }
        tbody.appendChild(tr);
        return tr;
    }

    function tmBatchBindFullRow(tr) {
        if (!tr) {
            return;
        }
        var rm = tr.querySelector('.tm-batch-remove');
        if (rm) {
            rm.addEventListener('click', function () {
                tr.parentNode.removeChild(tr);
            });
        }
    }

    function tmBatchBindCoRow(tr) {
        if (!tr) {
            return;
        }
        var rm = tr.querySelector('.tm-batch-co-remove');
        if (rm) {
            rm.addEventListener('click', function () {
                tr.parentNode.removeChild(tr);
            });
        }
    }

    function tmBatchAddCoRow() {
        tmBatchBindCoRow(tmBatchCloneRow('tm-batch-co-prototype', 'tm-batch-co-tbody'));
    }

    function tmBatchAddFullRow() {
        tmBatchBindFullRow(tmBatchCloneRow('tm-batch-row-prototype', 'tm-batch-rows-tbody'));
    }

    function tmBatchWireRowButtons() {
        if (window.tmBatchRowButtonsWired) {
            return;
        }
        window.tmBatchRowButtonsWired = true;
        var btnCo = document.getElementById('tm-batch-co-add-row');
        var btnFull = document.getElementById('tm-batch-add-row');
        if (btnCo) {
            btnCo.addEventListener('click', tmBatchAddCoRow);
        }
        if (btnFull) {
            btnFull.addEventListener('click', tmBatchAddFullRow);
        }
        tmBatchAddCoRow();
        tmBatchAddFullRow();

        if (!window.tmBatchPreviewWired) {
            window.tmBatchPreviewWired = true;
            var btnPrev = document.getElementById('tm-batch-open-debrief');
            if (btnPrev) {
                btnPrev.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    if (typeof window.tmBatchRunPreview === 'function') {
                        window.tmBatchRunPreview();
                    } else {
                        window.alert(typeof window.tmBatchCfg === 'undefined'
                            ? '批次設定未載入，請重新整理。'
                            : '頁面尚未載入完成，請重新整理後再試。');
                    }
                });
            }
        }
    }

    function tmBatchEnrolInit() {
        if (window.tmBatchEnrolBootOk) {
            return;
        }
        var cfg = window.tmBatchCfg;
        if (!cfg || !cfg.str || !cfg.lookupBase) {
            return;
        }
        var S = cfg.str;
        var lookupBase = cfg.lookupBase;
        var prereqCheckBase = cfg.prereqCheckBase || '';
        var sk = cfg.sesskey;

        var form = document.getElementById('tm-batch-enrol-form');
        var tbodyFull = document.getElementById('tm-batch-rows-tbody');
        var tbodyCo = document.getElementById('tm-batch-co-tbody');
        var protoFull = document.getElementById('tm-batch-row-prototype');
        var protoCo = document.getElementById('tm-batch-co-prototype');
        var sessionSel = document.getElementById('tm-batch-sessionid');
        var capHint = document.getElementById('tm-batch-cap-hint');
        var modal = document.getElementById('tm-batch-modal');
        var modalBody = document.getElementById('tm-batch-modal-body');
        var fieldSeat = document.getElementById('tm-fieldset-seat');
        var fieldFull = document.getElementById('tm-fieldset-full');
        var radioSeat = document.getElementById('batch_mode_seat');
        var radioFull = document.getElementById('batch_mode_full');
        var blockSeatWrap = document.getElementById('tm-batch-block-seat-wrap');
        var modeRadiosWrap = document.getElementById('tm-batch-mode-radios-wrap');
        var prereqModeHint = document.getElementById('tm-batch-prereq-mode-hint');

        if (!form || !tbodyFull || !tbodyCo || !protoFull || !protoCo || !sessionSel || !modal || !modalBody) {
            return;
        }

        function sessionHasPrereq() {
            var opt = sessionSel.options[sessionSel.selectedIndex];
            if (!opt) {
                return false;
            }
            return opt.getAttribute('data-has-prereq') === '1';
        }

        function formatPrereqCell(row) {
            if (row._prereqMet) {
                return '<span class="text-success">' + esc(S.batch_prereq_met) + '</span>';
            }
            var html = '<span class="text-warning">' + esc(S.batch_prereq_not_met) + '</span>';
            var reasons = Array.isArray(row._prereqReasons) ? row._prereqReasons : [];
            if (!reasons.length && row._prereqMissing) {
                reasons = String(row._prereqMissing).split(/\s*[;；]\s*/).filter(function (part) {
                    return part.trim() !== '';
                });
            }
            if (reasons.length) {
                html += '<ul class="small text-muted mb-0 mt-1 pl-3">';
                reasons.forEach(function (reason) {
                    html += '<li>' + esc(reason) + '</li>';
                });
                html += '</ul>';
            }
            return html;
        }

        function remaining() {
            var opt = sessionSel.options[sessionSel.selectedIndex];
            if (!opt) {
                return null;
            }
            var rem = opt.getAttribute('data-remaining');
            if (rem === null || rem === '') {
                return null;
            }
            return parseInt(rem, 10);
        }

        function updateCapHint() {
            if (!capHint) {
                return;
            }
            var r = remaining();
            if (r === null || isNaN(r)) {
                capHint.textContent = '';
                return;
            }
            capHint.textContent = S.batch_remaining + ': ' + r;
        }

        function syncMode() {
            var seatOn = radioSeat && radioSeat.checked && !sessionHasPrereq();
            if (fieldSeat) {
                fieldSeat.disabled = !seatOn;
            }
            if (fieldFull) {
                fieldFull.disabled = !!seatOn;
            }
        }

        function syncPrereqBatchMode() {
            var hasPrereq = sessionHasPrereq();
            if (hasPrereq) {
                if (radioSeat) {
                    radioSeat.disabled = true;
                    radioSeat.checked = false;
                }
                if (radioFull) {
                    radioFull.disabled = false;
                    radioFull.checked = true;
                }
                if (blockSeatWrap) {
                    blockSeatWrap.hidden = true;
                }
                if (modeRadiosWrap) {
                    modeRadiosWrap.hidden = true;
                }
                if (prereqModeHint) {
                    prereqModeHint.hidden = false;
                }
            } else {
                if (radioSeat) {
                    radioSeat.disabled = false;
                }
                if (radioFull) {
                    radioFull.disabled = false;
                }
                if (blockSeatWrap) {
                    blockSeatWrap.hidden = false;
                }
                if (modeRadiosWrap) {
                    modeRadiosWrap.hidden = false;
                }
                if (prereqModeHint) {
                    prereqModeHint.hidden = true;
                }
            }
            syncMode();
        }

        sessionSel.addEventListener('change', function () {
            updateCapHint();
            syncPrereqBatchMode();
        });

        var radios = document.querySelectorAll('input[name="batch_mode_radio"]');
        var ri;
        for (ri = 0; ri < radios.length; ri++) {
            radios[ri].addEventListener('change', syncMode);
        }

        function collectCoPairs() {
            var pairs = [];
            var trs = tbodyCo.querySelectorAll('.tm-batch-co-row');
            var ti;
            for (ti = 0; ti < trs.length; ti++) {
                var tr = trs[ti];
                var company = (tr.querySelector('.co_company') && tr.querySelector('.co_company').value || '').trim();
                var seats = parseInt(tr.querySelector('.co_seats').value, 10);
                if (company === '' || !(seats >= 1)) {
                    continue;
                }
                pairs.push({company: company, seats: seats});
            }
            return pairs;
        }

        function expandCoPairs(pairs) {
            var expanded = [];
            var seq = 0;
            var pi, k, p;
            for (pi = 0; pi < pairs.length; pi++) {
                p = pairs[pi];
                for (k = 0; k < p.seats; k++) {
                    seq++;
                    expanded.push({company: p.company, seq: seq});
                }
            }
            return expanded;
        }

        function collectValidatedFullRows() {
            var rows = [];
            var trs = tbodyFull.querySelectorAll('.tm-batch-row');
            var ti;
            for (ti = 0; ti < trs.length; ti++) {
                var tr = trs[ti];
                var emailEl = tr.querySelector('.entry_email');
                var email = ((emailEl && emailEl.value) || '').trim().toLowerCase();
                if (!email) {
                    continue;
                }
                var firstname = ((tr.querySelector('.entry_firstname') || {}).value || '').trim();
                var lastname = ((tr.querySelector('.entry_lastname') || {}).value || '').trim();
                var diet = (tr.querySelector('.entry_diet') || {}).value || '';
                rows.push({
                    firstname: firstname,
                    lastname: lastname,
                    email: email,
                    institution: ((tr.querySelector('.entry_institution') || {}).value || '').trim(),
                    diet: diet,
                    special_note: ((tr.querySelector('.entry_special_note') || {}).value || '').trim()
                });
            }
            return rows;
        }

        function esc(s) {
            return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
        }

        function dietLabel(row) {
            if (row.diet === 'A') {
                var p = [S.diet_choice_meat];
                if (row.special_note) {
                    p.push(row.special_note);
                }
                return p.join(' / ');
            }
            if (row.diet === 'B') {
                var q = [S.diet_choice_vegetarian];
                if (row.special_note) {
                    q.push(row.special_note);
                }
                return q.join(' / ');
            }
            return '\u2014';
        }

        function getSessionSummaryHtml() {
            var opt = sessionSel && sessionSel.options ? sessionSel.options[sessionSel.selectedIndex] : null;
            if (!opt) {
                return '';
            }
            var courseName = String(opt.dataset.courseName || '').trim();
            var sessionDate = String(opt.dataset.sessionDate || '').trim();
            var startTime = String(opt.dataset.sessionStartTime || '').trim();
            if (!courseName && !sessionDate && !startTime) {
                return '';
            }

            var html = '<div class="mb-2 p-2 border rounded bg-light">';
            html += '<div class="font-weight-bold">' + esc(S.batch_modal_session_info_title) + '</div>';
            if (courseName) {
                html += '<div class="mt-1"><strong>' + esc(S.batch_modal_course_name) + ':</strong> ' + esc(courseName) + '</div>';
            }
            if (sessionDate) {
                html += '<div class="small text-muted"><strong>' + esc(S.batch_modal_session_date) + ':</strong> ' + esc(sessionDate) + '</div>';
            }
            if (startTime) {
                html += '<div class="small text-muted"><strong>' + esc(S.batch_modal_session_start_time) + ':</strong> ' + esc(startTime) + '</div>';
            }
            html += '</div>';
            return html;
        }

        function stripDynamicHidden() {
            var n;
            var entryNodes = form.querySelectorAll('input[name^="entry_"]');
            for (n = entryNodes.length - 1; n >= 0; n--) {
                entryNodes[n].parentNode.removeChild(entryNodes[n]);
            }
            var seatNodes = form.querySelectorAll('input[name^="seat_slot_company"]');
            for (n = seatNodes.length - 1; n >= 0; n--) {
                seatNodes[n].parentNode.removeChild(seatNodes[n]);
            }
        }

        var emDash = '\u2014';

        window.tmBatchRunPreview = function () {
            if (!sessionSel || !String(sessionSel.value || '').trim()) {
                window.alert(S.batch_select_session);
                return;
            }
            var seatMode = radioSeat && radioSeat.checked;
            if (seatMode && sessionHasPrereq()) {
                window.alert(S.error_batch_seat_prerequisite);
                return;
            }

            if (seatMode) {
                var pairs = collectCoPairs();
                if (!pairs.length) {
                    window.alert(S.error_batch_co_rows_required);
                    return;
                }
                var expanded = expandCoPairs(pairs);
                var use = expanded.slice();

                modalBody.innerHTML = '';
                var noteEl = document.getElementById('batch_submitternote');
                var noteRaw = noteEl ? String(noteEl.value || '').trim() : '';
                var html = '';
                html += getSessionSummaryHtml();
                if (noteRaw) {
                    html += '<div class="mb-2 p-2 border rounded bg-light"><strong>' + esc(S.batch_modal_note_preview) + '</strong>' +
                        '<div class="mt-1 small" style="white-space:pre-wrap">' + esc(noteRaw) + '</div></div>';
                }
                html += '<div class="mb-2 small text-muted">' + esc(S.batch_modal_seat_expand_summary) +
                    ': ' + esc(String(expanded.length)) + '</div>';
                html += '<table class="tm-table"><thead><tr><th>#</th><th>' + esc(S.batch_co_company_col) + '</th><th>' +
                    esc(S.batch_modal_placeholder_seat) + '</th></tr></thead><tbody>';
                var ui;
                for (ui = 0; ui < use.length; ui++) {
                    var row = use[ui];
                    html += '<tr><td>' + esc(String(row.seq)) + '</td><td>' + esc(row.company) + '</td><td>' +
                        esc(S.batch_modal_seat_no_email) + '</td></tr>';
                }
                html += '</tbody></table>';
                modalBody.innerHTML = html;
                modal.hidden = false;
                modal.removeAttribute('hidden');
                modal._mode = 'seat';
                modal._seatSlots = use.map(function (row) {
                    return row.company;
                });
                modal._capped = false;
                modal._payload = [];
                return;
            }

            var data = collectValidatedFullRows();
            if (!data.length) {
                window.alert(S.batch_need_one_row);
                return;
            }
            var i;
            for (i = 0; i < data.length; i++) {
                if (!data[i].firstname || !data[i].lastname) {
                    window.alert(S.error_batch_name_required);
                    return;
                }
                if (!data[i].institution) {
                    window.alert(S.error_institution_required);
                    return;
                }
                if (data[i].diet !== 'A' && data[i].diet !== 'B') {
                    window.alert(S.error_batch_diet_required);
                    return;
                }
            }

            var usef = data.slice();

            modalBody.textContent = '';
            modalBody.innerHTML = '<p class="text-muted">' + esc(S.batch_lookup_loading) + '</p>';
            modal.hidden = false;
            modal.removeAttribute('hidden');
            modal._mode = 'full';
            modal._payload = [];

            if (typeof Promise === 'undefined' || typeof fetch === 'undefined') {
                window.alert(S.batch_lookup_loading);
                return;
            }

            var sessionId = parseInt(sessionSel.value, 10) || 0;
            var needsPrereqCol = sessionHasPrereq();

            Promise.all(usef.map(function (row) {
                var q = 'email=' + encodeURIComponent(row.email) + '&sesskey=' + encodeURIComponent(sk);
                if (needsPrereqCol && sessionId > 0) {
                    q += '&sessionid=' + encodeURIComponent(String(sessionId));
                }
                return fetch(lookupBase + '?' + q, {credentials: 'same-origin'}).then(function (resp) {
                    return resp.json();
                }).then(function (j) {
                    row._displayName = (j && j.found) ? j.name : (row.firstname + ' ' + row.lastname).trim();
                    row._displayInst = (row.institution || (j && j.institution) || emDash);
                    row._userType = (j && j.found) ? S.batch_user_existing : S.batch_modal_email_not_registered;
                    row._userTypeClass = (j && j.found) ? 'existing' : 'pending';
                    if (needsPrereqCol) {
                        row._prereqMet = !!(j && j.prereq_met === true);
                        row._prereqMissing = (j && j.prereq_missing) ? String(j.prereq_missing) : '';
                        row._prereqReasons = (j && Array.isArray(j.prereq_reasons)) ? j.prereq_reasons.slice() : [];
                    }
                    return row;
                }).catch(function () {
                    row._displayName = (row.firstname + ' ' + row.lastname).trim() || emDash;
                    row._displayInst = row.institution || emDash;
                    row._userType = S.batch_modal_email_not_registered;
                    row._userTypeClass = 'pending';
                    if (needsPrereqCol) {
                        row._prereqMet = false;
                        row._prereqMissing = '';
                        row._prereqReasons = [];
                    }
                    return row;
                });
            })).then(function (rows) {
                    var showPrereq = needsPrereqCol;
                    var htmlf = '';
                    var noteElf = document.getElementById('batch_submitternote');
                    var noteRawf = noteElf ? String(noteElf.value || '').trim() : '';
                    htmlf += getSessionSummaryHtml();
                    if (noteRawf) {
                        htmlf += '<div class="mb-2 p-2 border rounded bg-light"><strong>' + esc(S.batch_modal_note_preview) + '</strong>' +
                            '<div class="mt-1 small" style="white-space:pre-wrap">' + esc(noteRawf) + '</div></div>';
                    }
                    htmlf += '<div class="mb-2 small text-muted">' + esc(S.batch_modal_full_rows) + ': ' + esc(String(data.length)) + '</div>';
                    htmlf += '<table class="tm-table"><thead><tr><th>' + esc(S.label_learner) + '</th><th>' + esc(S.label_email) +
                        '</th><th>' + esc(S.batch_user_type) + '</th>';
                    if (showPrereq) {
                        htmlf += '<th>' + esc(S.batch_prereq_warning_title) + '</th>';
                    }
                    htmlf += '<th>' + esc(S.institution) + '</th><th>' + esc(S.diet_survey_title) +
                        '</th></tr></thead><tbody>';
                    rows.forEach(function (row) {
                        var prereqCell = showPrereq ? formatPrereqCell(row) : '';
                        htmlf += '<tr><td>' + esc(row._displayName) + '</td><td>' + esc(row.email) + '</td><td><span class="tm-batch-user-badge tm-batch-user-badge-' +
                            esc(row._userTypeClass || 'existing') + '">' + esc(row._userType || '') + '</span></td>';
                        if (showPrereq) {
                            htmlf += '<td>' + prereqCell + '</td>';
                        }
                        htmlf += '<td>' + esc(row._displayInst) + '</td><td>' +
                            esc(dietLabel(row)) + '</td></tr>';
                    });
                    htmlf += '</tbody></table>';
                    modalBody.innerHTML = htmlf;
                    modal._payload = rows;
                    modal._capped = false;
            });
        };

        var btnClose = document.getElementById('tm-batch-modal-close');
        if (btnClose) {
            btnClose.addEventListener('click', function () {
                modal.hidden = true;
                modal.setAttribute('hidden', 'hidden');
            });
        }

        var btnConfirm = document.getElementById('tm-batch-modal-confirm');
        if (btnConfirm) {
            btnConfirm.addEventListener('click', function () {
                var mode = modal._mode;
                var modeHidden = document.getElementById('batch_mode_hidden');
                if (modeHidden) {
                    modeHidden.value = mode === 'seat' ? 'seat' : 'full';
                }
                stripDynamicHidden();

                if (mode === 'seat') {
                    var slots = modal._seatSlots || [];
                    if (!slots.length) {
                        modal.hidden = true;
                        return;
                    }
                    slots.forEach(function (company, i) {
                        var inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = 'seat_slot_company[' + i + ']';
                        inp.value = company;
                        form.appendChild(inp);
                    });
                } else {
                    var use = modal._payload || [];
                    if (!use.length) {
                        modal.hidden = true;
                        return;
                    }
                    use.forEach(function (row, i) {
                        [['entry_firstname', row.firstname], ['entry_lastname', row.lastname], ['entry_email', row.email],
                            ['entry_institution', row.institution], ['entry_diet', row.diet],
                            ['entry_special_note', row.special_note]].forEach(function (pair) {
                            var inp = document.createElement('input');
                            inp.type = 'hidden';
                            inp.name = pair[0] + '[' + i + ']';
                            inp.value = pair[1];
                            form.appendChild(inp);
                        });
                    });
                }

                var batchConfirmed = document.getElementById('batch_confirmed');
                if (batchConfirmed) {
                    batchConfirmed.value = '1';
                }
                modal.hidden = true;
                form.submit();
            });
        }

        updateCapHint();
        syncPrereqBatchMode();
        window.tmBatchEnrolBootOk = true;
    }

    function tmBatchReservationInit() {
        var cfg = window.tmBatchCfg;
        if (!cfg || cfg.context !== 'reservation' || !cfg.str) {
            return;
        }
        var S = cfg.str;
        var lookupBase = cfg.lookupBase;
        var sk = cfg.sesskey;
        var requireDiet = !!cfg.requireDiet;
        var form = document.getElementById(cfg.formId || 'tm-reservation-form');
        var tbodyFull = document.getElementById('tm-batch-rows-tbody');
        var protoFull = document.getElementById('tm-batch-row-prototype');
        var modal = document.getElementById('tm-batch-modal');
        var modalBody = document.getElementById('tm-batch-modal-body');
        var proceedBtn = document.getElementById('tm-resv-proceed-calendar');
        var emDash = '\u2014';

        if (!form || !tbodyFull || !protoFull || !modal || !modalBody) {
            return;
        }

        function esc(s) {
            return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
        }

        function dietLabel(row) {
            if (row.diet === 'A') {
                var p = [S.diet_choice_meat];
                if (row.special_note) { p.push(row.special_note); }
                return p.join(' / ');
            }
            if (row.diet === 'B') {
                var q = [S.diet_choice_vegetarian];
                if (row.special_note) { q.push(row.special_note); }
                return q.join(' / ');
            }
            return emDash;
        }

        function collectValidatedFullRows() {
            var rows = [];
            var trs = tbodyFull.querySelectorAll('.tm-batch-row');
            var ti;
            for (ti = 0; ti < trs.length; ti++) {
                var tr = trs[ti];
                var emailEl = tr.querySelector('.entry_email');
                var email = ((emailEl && emailEl.value) || '').trim().toLowerCase();
                var firstname = ((tr.querySelector('.entry_firstname') || {}).value || '').trim();
                var lastname = ((tr.querySelector('.entry_lastname') || {}).value || '').trim();
                var institution = ((tr.querySelector('.entry_institution') || {}).value || '').trim();
                if (email === '' && firstname === '' && lastname === '' && institution === '') {
                    continue;
                }
                rows.push({
                    firstname: firstname,
                    lastname: lastname,
                    email: email,
                    institution: institution,
                    diet: (tr.querySelector('.entry_diet') || {}).value || '',
                    special_note: ((tr.querySelector('.entry_special_note') || {}).value || '').trim()
                });
            }
            return rows;
        }

        function stripDynamicHidden() {
            var n;
            var entryNodes = form.querySelectorAll('input[name^="entry_"]');
            for (n = entryNodes.length - 1; n >= 0; n--) {
                entryNodes[n].parentNode.removeChild(entryNodes[n]);
            }
        }

        function seedInitialRows() {
            var rows = window.tmBatchInitialRows || [];
            var ri;
            if (rows.length) {
                for (ri = 0; ri < rows.length; ri++) {
                    var tr = tmBatchCloneRow('tm-batch-row-prototype', 'tm-batch-rows-tbody');
                    if (!tr) { continue; }
                    var fn = tr.querySelector('.entry_firstname');
                    var ln = tr.querySelector('.entry_lastname');
                    var em = tr.querySelector('.entry_email');
                    var inst = tr.querySelector('.entry_institution');
                    var diet = tr.querySelector('.entry_diet');
                    var sn = tr.querySelector('.entry_special_note');
                    if (fn) { fn.value = rows[ri].firstname || ''; }
                    if (ln) { ln.value = rows[ri].lastname || ''; }
                    if (em) { em.value = rows[ri].email || ''; }
                    if (inst) { inst.value = rows[ri].institution || ''; }
                    if (diet) { diet.value = rows[ri].diet || ''; }
                    if (sn) { sn.value = rows[ri].special_note || ''; }
                    tmBatchBindFullRow(tr);
                }
            } else {
                tmBatchBindFullRow(tmBatchCloneRow('tm-batch-row-prototype', 'tm-batch-rows-tbody'));
            }
        }

        window.tmBatchRunPreview = function () {
            var data = collectValidatedFullRows();
            if (!data.length) {
                var batchConfirmed = document.getElementById('batch_confirmed');
                if (batchConfirmed) { batchConfirmed.value = '1'; }
                form.submit();
                return;
            }
            var i;
            for (i = 0; i < data.length; i++) {
                if (!data[i].firstname || !data[i].lastname) {
                    window.alert(S.error_batch_name_required);
                    return;
                }
                if (!data[i].institution) {
                    window.alert(S.error_institution_required);
                    return;
                }
                if (requireDiet && data[i].diet !== 'A' && data[i].diet !== 'B') {
                    window.alert(S.error_batch_diet_required);
                    return;
                }
                if (!requireDiet && data[i].diet !== 'A' && data[i].diet !== 'B') {
                    data[i].diet = 'A';
                }
            }

            modalBody.textContent = '';
            modalBody.innerHTML = '<p class="text-muted">' + esc(S.batch_lookup_loading) + '</p>';
            modal.hidden = false;
            modal.removeAttribute('hidden');
            modal._payload = [];

            if (typeof Promise === 'undefined' || typeof fetch === 'undefined') {
                window.alert(S.batch_lookup_loading);
                return;
            }

            Promise.all(data.map(function (row) {
                if (!row.email) {
                    row._displayName = (row.firstname + ' ' + row.lastname).trim();
                    row._displayInst = row.institution || emDash;
                    row._userType = S.batch_modal_email_not_registered;
                    row._userTypeClass = 'pending';
                    return row;
                }
                var q = 'email=' + encodeURIComponent(row.email) + '&sesskey=' + encodeURIComponent(sk);
                return fetch(lookupBase + '?' + q, {credentials: 'same-origin'}).then(function (resp) {
                    return resp.json();
                }).then(function (j) {
                    row._displayName = (j && j.found) ? j.name : (row.firstname + ' ' + row.lastname).trim();
                    row._displayInst = row.institution || (j && j.institution) || emDash;
                    row._userType = (j && j.found) ? S.batch_user_existing : S.batch_modal_email_not_registered;
                    row._userTypeClass = (j && j.found) ? 'existing' : 'pending';
                    return row;
                }).catch(function () {
                    row._displayName = (row.firstname + ' ' + row.lastname).trim() || emDash;
                    row._displayInst = row.institution || emDash;
                    row._userType = S.batch_modal_email_not_registered;
                    row._userTypeClass = 'pending';
                    return row;
                });
            })).then(function (rows) {
                var htmlf = '';
                var noteElf = document.getElementById('batch_submitternote');
                var noteRawf = noteElf ? String(noteElf.value || '').trim() : '';
                if (S.reservation_batch_context_title) {
                    htmlf += '<div class="mb-2 p-2 border rounded bg-light"><div class="font-weight-bold">' +
                        esc(S.reservation_batch_context_title) + '</div></div>';
                }
                if (noteRawf) {
                    htmlf += '<div class="mb-2 p-2 border rounded bg-light"><strong>' + esc(S.batch_modal_note_preview) + '</strong>' +
                        '<div class="mt-1 small" style="white-space:pre-wrap">' + esc(noteRawf) + '</div></div>';
                }
                htmlf += '<div class="mb-2 small text-muted">' + esc(S.batch_modal_full_rows) + ': ' + esc(String(data.length)) + '</div>';
                htmlf += '<table class="tm-table"><thead><tr><th>' + esc(S.label_learner) + '</th><th>' + esc(S.label_email) +
                    '</th><th>' + esc(S.batch_user_type) + '</th><th>' + esc(S.institution) + '</th><th>' + esc(S.diet_survey_title) +
                    '</th></tr></thead><tbody>';
                rows.forEach(function (row) {
                    htmlf += '<tr><td>' + esc(row._displayName) + '</td><td>' + esc(row.email || emDash) + '</td><td><span class="tm-batch-user-badge tm-batch-user-badge-' +
                        esc(row._userTypeClass || 'existing') + '">' + esc(row._userType || '') + '</span></td><td>' + esc(row._displayInst) + '</td><td>' +
                        esc(dietLabel(row)) + '</td></tr>';
                });
                htmlf += '</tbody></table>';
                modalBody.innerHTML = htmlf;
                modal._payload = rows;
            });
        };

        var btnClose = document.getElementById('tm-batch-modal-close');
        if (btnClose) {
            btnClose.addEventListener('click', function () {
                modal.hidden = true;
                modal.setAttribute('hidden', 'hidden');
            });
        }

        var btnConfirm = document.getElementById('tm-batch-modal-confirm');
        if (btnConfirm) {
            btnConfirm.addEventListener('click', function () {
                stripDynamicHidden();
                var use = modal._payload || [];
                if (use.length) {
                    use.forEach(function (row, i) {
                        [['entry_firstname', row.firstname], ['entry_lastname', row.lastname], ['entry_email', row.email],
                            ['entry_institution', row.institution], ['entry_diet', row.diet],
                            ['entry_special_note', row.special_note]].forEach(function (pair) {
                            var inp = document.createElement('input');
                            inp.type = 'hidden';
                            inp.name = pair[0] + '[' + i + ']';
                            inp.value = pair[1];
                            form.appendChild(inp);
                        });
                    });
                }
                var batchConfirmed = document.getElementById('batch_confirmed');
                if (batchConfirmed) { batchConfirmed.value = '1'; }
                modal.hidden = true;
                form.submit();
            });
        }

        if (proceedBtn) {
            proceedBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                if (typeof window.tmBatchRunPreview === 'function') {
                    window.tmBatchRunPreview();
                }
            });
        }

        seedInitialRows();
        window.tmBatchReservationBootOk = true;
    }

    function boot() {
        tmBatchWireRowButtons();
        var cfg = window.tmBatchCfg;
        if (cfg && cfg.context === 'reservation') {
            tmBatchReservationInit();
            return;
        }
        tmBatchEnrolInit();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
