/**
 * Admin enrolments desk board: drag-and-drop desk reassignment.
 *
 * @package local_tm_course
 */
(function () {
    'use strict';

    var board = document.getElementById('tm-admin-desk-board');
    if (!board) {
        return;
    }

    var api = board.getAttribute('data-api') || '';
    var sesskey = board.getAttribute('data-sesskey') || '';
    var cfg = window.tmEnrolDeskCfg || {};
    var dragEnrolId = 0;
    var dragFromDesk = -1;

    function clearDropHints() {
        var zones = board.querySelectorAll('.tm-admin-desk-dropzone');
        var i;
        for (i = 0; i < zones.length; i++) {
            zones[i].classList.remove('tm-desk-drop-hover');
        }
    }

    function findDropzone(el) {
        while (el && el !== board) {
            if (el.classList && el.classList.contains('tm-admin-desk-dropzone')) {
                return el;
            }
            el = el.parentNode;
        }
        return null;
    }

    board.addEventListener('dragstart', function (ev) {
        if (ev.target && ev.target.closest && ev.target.closest('.tm-desk-diet-editable, .tm-desk-diet-editor, .tm-admin-learner-actions, form')) {
            ev.preventDefault();
            return;
        }
        var card = ev.target.closest ? ev.target.closest('.tm-admin-learner-card') : null;
        if (!card || card.getAttribute('draggable') !== 'true') {
            return;
        }
        dragEnrolId = parseInt(card.getAttribute('data-enrolid'), 10) || 0;
        dragFromDesk = parseInt(card.getAttribute('data-desk'), 10);
        if (!dragEnrolId) {
            return;
        }
        card.classList.add('tm-dragging');
        if (ev.dataTransfer) {
            ev.dataTransfer.effectAllowed = 'move';
            try {
                ev.dataTransfer.setData('text/plain', String(dragEnrolId));
            } catch (e) {
                // IE / older browsers.
            }
        }
    });

    board.addEventListener('dragend', function (ev) {
        var card = ev.target.closest ? ev.target.closest('.tm-admin-learner-card') : null;
        if (card) {
            card.classList.remove('tm-dragging');
        }
        clearDropHints();
        dragEnrolId = 0;
        dragFromDesk = -1;
    });

    board.addEventListener('dragover', function (ev) {
        var zone = findDropzone(ev.target);
        if (!zone || !dragEnrolId) {
            return;
        }
        var desk = parseInt(zone.getAttribute('data-desk'), 10) || 0;
        if (desk < 1) {
            return;
        }
        ev.preventDefault();
        clearDropHints();
        zone.classList.add('tm-desk-drop-hover');
    });

    board.addEventListener('dragleave', function (ev) {
        var zone = findDropzone(ev.target);
        if (zone && !zone.contains(ev.relatedTarget)) {
            zone.classList.remove('tm-desk-drop-hover');
        }
    });

    board.addEventListener('drop', function (ev) {
        var zone = findDropzone(ev.target);
        clearDropHints();
        if (!zone || !dragEnrolId || !api) {
            return;
        }
        ev.preventDefault();
        var desk = parseInt(zone.getAttribute('data-desk'), 10) || 0;
        if (desk < 1) {
            return;
        }
        if (desk === dragFromDesk) {
            return;
        }

        var body = new URLSearchParams();
        body.set('action', 'move');
        body.set('enrolid', String(dragEnrolId));
        body.set('desk_number', String(desk));
        body.set('sesskey', sesskey);

        fetch(api, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (res) {
            return res.json().then(function (data) {
                return {ok: res.ok && data && data.ok, data: data};
            });
        }).then(function (result) {
            if (!result.ok) {
                window.alert((result.data && result.data.error) || cfg.moveFailed || 'Move failed');
                return;
            }
            window.location.reload();
        }).catch(function () {
            window.alert(cfg.moveFailed || 'Move failed');
        });
    });
})();
