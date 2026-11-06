/**
 * Status-detail popup on the session enrolment roster.
 * Reads only the clicked button. Writes with textContent.
 */
(function () {
    var modal = document.getElementById('tm-status-detail-modal');
    var panel = document.getElementById('tm-status-detail-panel');
    var closeBtn = document.getElementById('tm-status-detail-close');
    if (!modal || !panel || !closeBtn) {
        return;
    }

    var lastFocus = null;

    function text(id, value) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = value || '';
        }
    }

    function clear() {
        text('tm-status-detail-title', '');
        text('tm-status-detail-actor-label', '');
        text('tm-status-detail-actor', '');
        text('tm-status-detail-time-label', '');
        text('tm-status-detail-time', '');
        text('tm-status-detail-reason-label', '');
        text('tm-status-detail-reason', '');
        var reasonRow = document.getElementById('tm-status-detail-reason-row');
        if (reasonRow) {
            reasonRow.hidden = true;
        }
    }

    function focusable() {
        return Array.prototype.filter.call(
            panel.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
            function (el) {
                return !el.disabled && el.getAttribute('hidden') === null && el.offsetParent !== null;
            }
        );
    }

    function open(btn) {
        clear();
        text('tm-status-detail-title', btn.getAttribute('data-status-label'));
        text('tm-status-detail-actor-label', btn.getAttribute('data-actor-label'));
        text('tm-status-detail-actor', btn.getAttribute('data-actor'));
        text('tm-status-detail-time-label', btn.getAttribute('data-time-label'));
        text('tm-status-detail-time', btn.getAttribute('data-time'));
        var reasonLabel = btn.getAttribute('data-reason-label') || '';
        var reasonRow = document.getElementById('tm-status-detail-reason-row');
        if (reasonLabel !== '' && reasonRow) {
            text('tm-status-detail-reason-label', reasonLabel);
            text('tm-status-detail-reason', btn.getAttribute('data-reason'));
            reasonRow.hidden = false;
        }
        lastFocus = btn;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        closeBtn.focus();
    }

    function close() {
        if (modal.hidden) {
            return;
        }
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        clear();
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
        lastFocus = null;
    }

    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest
            ? event.target.closest('.tm-status-detail-trigger')
            : null;
        if (btn) {
            open(btn);
            return;
        }
        if (event.target === modal || event.target === closeBtn) {
            close();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (modal.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        var items = focusable();
        if (!items.length) {
            event.preventDefault();
            closeBtn.focus();
            return;
        }
        var first = items[0];
        var last = items[items.length - 1];
        var active = document.activeElement;
        if (!panel.contains(active)) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus();
            return;
        }
        if (event.shiftKey && active === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    });
}());
