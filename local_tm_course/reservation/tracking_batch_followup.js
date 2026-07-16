/* global window, document, fetch */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    function dietLabel(S, diet, special) {
        var sn = String(special || '').trim();
        if (diet === 'A') {
            var p = [S.diet_choice_meat];
            if (sn) {
                p.push(sn);
            }
            return p.join(' / ');
        }
        if (diet === 'B') {
            var q = [S.diet_choice_vegetarian];
            if (sn) {
                q.push(sn);
            }
            return q.join(' / ');
        }
        return '\u2014';
    }

    function boot(cfg) {
        if (!cfg || !cfg.str || !cfg.lookupUrl) {
            return;
        }
        var S = cfg.str;
        var modal = document.getElementById('tm-followup-confirm-modal');
        var body = document.getElementById('tm-followup-confirm-body');
        var btnOk = document.getElementById('tm-followup-confirm-ok');
        var btnClose = document.getElementById('tm-followup-confirm-close');
        if (!modal || !body || !btnOk || !btnClose) {
            return;
        }

        var pendingForm = null;

        function showModal() {
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
        }

        function hideModal() {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            pendingForm = null;
            btnOk.disabled = false;
        }

        btnClose.addEventListener('click', hideModal);

        btnOk.addEventListener('click', function () {
            if (btnOk.disabled) {
                return;
            }
            if (!pendingForm) {
                hideModal();
                return;
            }
            pendingForm.dataset.tmConfirmBypass = '1';
            if (typeof pendingForm.requestSubmit === 'function') {
                pendingForm.requestSubmit();
            } else {
                pendingForm.submit();
            }
            hideModal();
        });

        function wireForm(form) {
            form.addEventListener('submit', function (ev) {
                if (form.dataset.tmConfirmBypass === '1') {
                    form.dataset.tmConfirmBypass = '';
                    return;
                }
                ev.preventDefault();

                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }

                var emEl = form.querySelector('input[name="placeholder_email"]');
                var fnEl = form.querySelector('input[name="placeholder_firstname"]');
                var lnEl = form.querySelector('input[name="placeholder_lastname"]');
                var instEl = form.querySelector('input[name="placeholder_institution"]');
                var dietEl = form.querySelector('select[name="diet_choice"]');
                var noteEl = form.querySelector('input[name="special_note"]');

                var email = emEl ? String(emEl.value || '').trim().toLowerCase() : '';
                var fn = fnEl ? String(fnEl.value || '').trim() : '';
                var ln = lnEl ? String(lnEl.value || '').trim() : '';
                var inst = instEl ? String(instEl.value || '').trim() : '';
                var diet = dietEl ? String(dietEl.value || '').toUpperCase() : '';
                var note = noteEl ? String(noteEl.value || '').trim() : '';

                if (diet !== 'A' && diet !== 'B') {
                    window.alert(S.error_batch_diet_required);
                    return;
                }

                if (email === '') {
                    body.innerHTML = '<p class="mb-0">' + esc(S.batch_followup_confirm_clear_body) + '</p>';
                    pendingForm = form;
                    btnOk.disabled = false;
                    showModal();
                    return;
                }

                if (!fn || !ln) {
                    window.alert(S.error_batch_name_required);
                    return;
                }
                if (!inst) {
                    window.alert(S.error_institution_required);
                    return;
                }

                body.innerHTML = '<p class="text-muted small">' + esc(S.batch_lookup_loading) + '</p>';
                pendingForm = form;
                btnOk.disabled = true;
                showModal();

                if (typeof fetch === 'undefined') {
                    body.textContent = S.batch_lookup_loading;
                    btnOk.disabled = true;
                    return;
                }

                var q = 'email=' + encodeURIComponent(email) + '&sesskey=' + encodeURIComponent(cfg.sesskey);
                fetch(cfg.lookupUrl + '?' + q, {credentials: 'same-origin'})
                    .then(function (r) {
                        if (!r.ok) {
                            throw new Error('lookup');
                        }
                        return r.json();
                    })
                    .then(function (j) {
                        if (!j || j.error) {
                            throw new Error('lookup');
                        }
                        var found = j.found;
                        var displayName = found ? String(j.name || '').trim() : (fn + ' ' + ln).trim();
                        var displayInst = inst || (found ? String(j.institution || '').trim() : '');
                        if (displayInst === '') {
                            displayInst = '\u2014';
                        }
                        var userType = found ? S.batch_user_existing : S.batch_modal_email_not_registered;
                        var userCls = found ? 'existing' : 'pending';
                        var html = '';
                        html += '<div class="mb-2 small text-muted">' + esc(S.batch_modal_full_rows) + ': 1</div>';
                        html += '<table class="tm-table"><thead><tr><th>' + esc(S.label_learner) + '</th><th>' +
                            esc(S.label_email) + '</th><th>' + esc(S.batch_user_type) + '</th><th>' +
                            esc(S.institution) + '</th><th>' + esc(S.diet_survey_title) + '</th></tr></thead><tbody>';
                        html += '<tr><td>' + esc(displayName) + '</td><td>' + esc(email) + '</td><td>' +
                            '<span class="tm-batch-user-badge tm-batch-user-badge-' + esc(userCls) + '">' +
                            esc(userType) + '</span></td><td>' + esc(displayInst) + '</td><td>' +
                            esc(dietLabel(S, diet, note)) + '</td></tr>';
                        html += '</tbody></table>';
                        body.innerHTML = html;
                        btnOk.disabled = false;
                    })
                    .catch(function () {
                        body.innerHTML = '<p class="text-danger">' + esc(S.batch_followup_lookup_failed) + '</p>';
                        btnOk.disabled = true;
                    });
            });
        }

        var forms = document.querySelectorAll('form.tm-batch-followup-form');
        for (var i = 0; i < forms.length; i++) {
            wireForm(forms[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            boot(window.tmTrackingFollowupCfg);
        });
    } else {
        boot(window.tmTrackingFollowupCfg);
    }
})();
