(function() {
    'use strict';

    var rowSyncing = false;
    var openSupportTip = null;
    var toastTimer = null;

    function getConfig() {
        return window.tmEquipCheckConfig || {};
    }

    function showToast(message, isError) {
        var existing = document.getElementById('tm-equip-toast');
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }
        if (toastTimer) {
            clearTimeout(toastTimer);
            toastTimer = null;
        }
        var tip = document.createElement('div');
        tip.id = 'tm-equip-toast';
        tip.className = 'tm-equip-toast' + (isError ? ' is-error' : ' is-success');
        tip.setAttribute('role', 'status');
        tip.textContent = message || '';
        document.body.appendChild(tip);
        void tip.offsetHeight;
        tip.classList.add('is-visible');
        toastTimer = setTimeout(function() {
            tip.classList.remove('is-visible');
            setTimeout(function() {
                if (tip.parentNode) {
                    tip.parentNode.removeChild(tip);
                }
            }, 250);
        }, 2800);
    }

    function postEquipment(body, onDone) {
        var cfg = getConfig();
        var url = cfg.postUrl || window.location.href;
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString(),
            credentials: 'same-origin'
        })
            .then(function(resp) {
                return resp.json().then(function(data) {
                    return { httpOk: resp.ok, data: data };
                }).catch(function() {
                    return { httpOk: false, data: null };
                });
            })
            .then(function(result) {
                if (onDone) {
                    onDone(result);
                }
            })
            .catch(function() {
                if (onDone) {
                    onDone({ httpOk: false, data: null });
                }
            });
    }

    function collectDeskFormBody(deskForm, action) {
        var cfg = getConfig();
        var body = new URLSearchParams();
        body.set('sesskey', cfg.sesskey || '');
        body.set('sessionid', String(cfg.sessionid || ''));
        body.set('action', action || 'equipment_save');
        body.set('ajax', '1');
        var deskInput = deskForm.querySelector('input[name="desknumber"]');
        if (deskInput) {
            body.set('desknumber', deskInput.value);
        }
        deskForm.querySelectorAll('input[name^="equip["]').forEach(function(input) {
            if ((input.type === 'radio' || input.type === 'checkbox') && !input.checked) {
                return;
            }
            // URLSearchParams append keeps array fields like resolution[].
            body.append(input.name, input.value);
        });
        return body;
    }

    function collectAllDesksBody() {
        var cfg = getConfig();
        var body = new URLSearchParams();
        body.set('sesskey', cfg.sesskey || '');
        body.set('sessionid', String(cfg.sessionid || ''));
        body.set('action', 'equipment_save_all');
        body.set('ajax', '1');

        var deskCount = 0;
        document.querySelectorAll('.tm-equip-form').forEach(function(deskForm) {
            var desknumberInput = deskForm.querySelector('input[name="desknumber"]');
            if (!desknumberInput) {
                return;
            }
            var desknumber = desknumberInput.value;
            deskCount++;
            deskForm.querySelectorAll('input[name^="equip["]').forEach(function(input) {
                var m = input.name.match(/^equip\[(\d+)\]\[(status|remark|resolution)\](?:\[\])?$/);
                if (!m) {
                    return;
                }
                if ((input.type === 'radio' || input.type === 'checkbox') && !input.checked) {
                    return;
                }
                var itemid = m[1];
                var field = m[2];
                var name;
                if (field === 'resolution') {
                    name = 'equip_all[' + desknumber + '][' + itemid + '][resolution][]';
                } else {
                    name = 'equip_all[' + desknumber + '][' + itemid + '][' + field + ']';
                }
                body.append(name, input.value);
            });
        });
        return { body: body, deskCount: deskCount };
    }

    function handleSaveResult(result, unlock) {
        var cfg = getConfig();
        var strings = cfg.strings || {};
        if (unlock) {
            unlock();
        }
        if (result && result.data && result.data.ok) {
            showToast(result.data.message || strings.saved || '已儲存', false);
            return;
        }
        var msg = strings.saveFailed || '儲存失敗，請稍後再試';
        if (result && result.data && result.data.message) {
            msg = result.data.message;
        }
        showToast(msg, true);
    }

    function saveAllAjax(saveAllBtn) {
        var packed = collectAllDesksBody();
        if (packed.deskCount < 1) {
            return;
        }
        if (saveAllBtn) {
            saveAllBtn.disabled = true;
        }
        postEquipment(packed.body, function(result) {
            handleSaveResult(result, function() {
                if (saveAllBtn) {
                    saveAllBtn.disabled = false;
                }
            });
        });
    }

    function getCard(el) {
        return el ? el.closest('.tm-equip-desk-card') : null;
    }

    /**
     * Same visual CSS-grid row: cards whose tops align (responsive column count).
     */
    function getRowPeerDetails(details) {
        var card = getCard(details);
        if (!card) {
            return [details];
        }
        var top = card.offsetTop;
        var peers = [];
        document.querySelectorAll('.tm-equip-desk-card').forEach(function(otherCard) {
            if (Math.abs(otherCard.offsetTop - top) > 2) {
                return;
            }
            var d = otherCard.querySelector('.tm-equip-desk-details');
            if (d) {
                peers.push(d);
            }
        });
        return peers.length ? peers : [details];
    }

    function isItemComplete(itemEl) {
        var checkbox = itemEl.querySelector('input[type="checkbox"][name^="equip["][name$="[status]"]');
        if (checkbox) {
            return !!checkbox.checked;
        }
        var checked = itemEl.querySelector('input[type="radio"][name^="equip["]:checked');
        if (!checked) {
            return false;
        }
        return checked.value === 'normal' || checked.value === 'abnormal';
    }

    function updateDeskProgress(card) {
        if (!card) {
            return;
        }
        var progress = card.querySelector('.tm-equip-desk-progress');
        var form = card.querySelector('.tm-equip-form');
        if (!progress || !form) {
            return;
        }
        var items = form.querySelectorAll('.tm-equip-item');
        var total = items.length;
        var completed = 0;
        items.forEach(function(item) {
            if (isItemComplete(item)) {
                completed++;
            }
        });
        var declared = parseInt(progress.getAttribute('data-total') || String(total), 10);
        if (!declared || declared < 1) {
            declared = total;
        }
        progress.setAttribute('data-total', String(declared));
        progress.textContent = completed + '/' + declared;
    }

    function syncAbnormalPanelVisibility(itemEl) {
        if (!itemEl) {
            return;
        }
        var panel = itemEl.querySelector('[data-equip-abnormal-panel]');
        if (!panel) {
            return;
        }
        var abnormal = itemEl.querySelector('input.js-equip-status-radio[value="abnormal"]');
        var open = !!(abnormal && abnormal.checked);
        if (open) {
            panel.hidden = false;
            panel.classList.add('is-open');
        } else {
            panel.hidden = true;
            panel.classList.remove('is-open');
            // Keep remark + resolution checkbox DOM values; only hide the panel.
        }
    }

    function fillDeskOk(form) {
        if (!form) {
            return;
        }
        form.querySelectorAll('.tm-equip-item').forEach(function(item) {
            var checkbox = item.querySelector('input[type="checkbox"][name^="equip["][name$="[status]"]');
            if (checkbox) {
                checkbox.checked = true;
                return;
            }
            var normal = item.querySelector('input[type="radio"][name^="equip["][value="normal"]');
            if (normal) {
                normal.checked = true;
            }
            // Keep remark + resolution checkbox DOM state; only hide abnormal panel.
            syncAbnormalPanelVisibility(item);
        });
        updateDeskProgress(getCard(form));
    }

    function initRowExpandSync() {
        document.querySelectorAll('.tm-equip-desk-details').forEach(function(details) {
            details.addEventListener('toggle', function() {
                if (rowSyncing) {
                    return;
                }
                rowSyncing = true;
                try {
                    var wantOpen = details.open;
                    getRowPeerDetails(details).forEach(function(peer) {
                        if (peer !== details && peer.open !== wantOpen) {
                            peer.open = wantOpen;
                        }
                    });
                } finally {
                    rowSyncing = false;
                }
            });
        });
    }

    function initFillDeskButtons() {
        document.querySelectorAll('.js-equip-fill-desk').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var form = btn.closest('.tm-equip-form');
                fillDeskOk(form);
            });
        });
    }

    function initLiveProgressAndResolution() {
        document.querySelectorAll('.tm-equip-form').forEach(function(form) {
            form.addEventListener('change', function(e) {
                var target = e.target;
                if (target && target.classList && target.classList.contains('js-equip-status-radio')) {
                    syncAbnormalPanelVisibility(target.closest('.tm-equip-item'));
                }
                updateDeskProgress(getCard(form));
            });
            form.querySelectorAll('.tm-equip-item').forEach(function(item) {
                syncAbnormalPanelVisibility(item);
            });
            updateDeskProgress(getCard(form));
        });
    }

    function closeSupportTip() {
        if (openSupportTip && openSupportTip.parentNode) {
            openSupportTip.parentNode.removeChild(openSupportTip);
        }
        openSupportTip = null;
    }

    function showSupportTip(btn) {
        closeSupportTip();
        var support = btn.getAttribute('data-support') || '';
        if (!support) {
            return;
        }
        var tip = document.createElement('div');
        tip.className = 'tm-equip-support-popover';
        tip.setAttribute('role', 'tooltip');
        var title = document.createElement('div');
        title.className = 'tm-equip-support-popover-title';
        title.textContent = btn.getAttribute('aria-label') || '';
        var body = document.createElement('div');
        body.className = 'tm-equip-support-popover-body';
        body.textContent = support;
        tip.appendChild(title);
        tip.appendChild(body);
        document.body.appendChild(tip);
        openSupportTip = tip;

        var rect = btn.getBoundingClientRect();
        var tipRect = tip.getBoundingClientRect();
        var left = rect.left + (rect.width / 2) - (tipRect.width / 2) + window.pageXOffset;
        var top = rect.bottom + 6 + window.pageYOffset;
        if (left < 8) {
            left = 8;
        }
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
    }

    function initSupportTips() {
        document.querySelectorAll('.js-equip-support-tip').forEach(function(btn) {
            btn.addEventListener('mouseenter', function() {
                showSupportTip(btn);
            });
            btn.addEventListener('mouseleave', function() {
                closeSupportTip();
            });
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (openSupportTip) {
                    closeSupportTip();
                } else {
                    showSupportTip(btn);
                }
            });
        });
        document.addEventListener('click', function(e) {
            if (!openSupportTip) {
                return;
            }
            if (e.target && e.target.closest && e.target.closest('.js-equip-support-tip')) {
                return;
            }
            closeSupportTip();
        });
    }

    function initSaveAll() {
        var btn = document.getElementById('tm-equip-save-all-btn');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            saveAllAjax(btn);
        });
    }

    function initDeskFormAjax() {
        document.querySelectorAll('.tm-equip-form').forEach(function(form) {
            // Track last clicked submit action (save vs sync) for browsers without e.submitter.
            var pendingAction = 'equipment_save';
            form.querySelectorAll('button[type="submit"][name="action"]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    pendingAction = btn.value || 'equipment_save';
                });
            });
            form.addEventListener('submit', function(e) {
                var action = pendingAction;
                if (e.submitter && e.submitter.name === 'action') {
                    action = e.submitter.value || action;
                }
                // Keep classic POST+redirect for "sync to all desks" so other desks re-render.
                if (action === 'equipment_sync') {
                    return;
                }
                e.preventDefault();
                var submitBtn = form.querySelector('button[type="submit"][value="equipment_save"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                }
                var body = collectDeskFormBody(form, 'equipment_save');
                postEquipment(body, function(result) {
                    handleSaveResult(result, function() {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                        }
                    });
                });
            });
        });
    }

    function init() {
        if (!document.querySelector('.tm-equip-grid')) {
            return;
        }
        initRowExpandSync();
        initFillDeskButtons();
        initLiveProgressAndResolution();
        initSupportTips();
        initSaveAll();
        initDeskFormAjax();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
