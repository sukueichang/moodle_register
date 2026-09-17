(function() {
    'use strict';

    var rowSyncing = false;
    var openSupportTip = null;

    function collectAndSubmit(saveAllBtn) {
        var form = document.getElementById('tm-equip-save-all-form');
        if (!form) {
            return;
        }

        // Clear any inputs injected by a previous click before rebuilding.
        form.querySelectorAll('.tm-equip-dynamic-input').forEach(function(el) {
            el.remove();
        });

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
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.className = 'tm-equip-dynamic-input';
                if (field === 'resolution') {
                    hidden.name = 'equip_all[' + desknumber + '][' + itemid + '][resolution][]';
                } else {
                    hidden.name = 'equip_all[' + desknumber + '][' + itemid + '][' + field + ']';
                }
                hidden.value = input.value;
                form.appendChild(hidden);
            });
        });

        if (deskCount < 1) {
            return;
        }

        if (saveAllBtn) {
            saveAllBtn.disabled = true;
        }
        form.submit();
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

    function syncResolutionVisibility(itemEl) {
        if (!itemEl) {
            return;
        }
        var panel = itemEl.querySelector('[data-equip-resolution]');
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
            // Intentionally do NOT clear checklist checkboxes here.
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
            // Keep remark + resolution checkbox DOM state; only hide resolution panel.
            syncResolutionVisibility(item);
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
                    syncResolutionVisibility(target.closest('.tm-equip-item'));
                }
                updateDeskProgress(getCard(form));
            });
            form.querySelectorAll('.tm-equip-item').forEach(function(item) {
                syncResolutionVisibility(item);
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
        btn.addEventListener('click', function() {
            collectAndSubmit(btn);
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
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
