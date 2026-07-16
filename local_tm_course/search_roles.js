(function() {
    'use strict';

    var cfg = window.tmSearchRolesCfg;
    if (!cfg || !cfg.apiBase) {
        return;
    }

    var backdrop = document.getElementById('tm-search-roles-modal');
    var body = document.getElementById('tm-search-roles-modal-body');
    var titleEl = document.getElementById('tm-search-roles-modal-title');
    var saveBtn = document.getElementById('tm-search-roles-modal-save');
    var closeBtn = document.getElementById('tm-search-roles-modal-close');
    var activeCell = null;
    var activeUserid = 0;
    var loading = false;

    function apiUrl(params) {
        var q = [];
        Object.keys(params).forEach(function(k) {
            q.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
        });
        return cfg.apiBase + (cfg.apiBase.indexOf('?') >= 0 ? '&' : '?') + q.join('&');
    }

    function displayLabel(value) {
        var text = (value || '').trim();
        return text !== '' ? text : cfg.str.emptyRoles;
    }

    function updateCell(cell, displayroles) {
        var textEl = cell.querySelector('.tm-search-roles-text');
        if (textEl) {
            textEl.textContent = displayLabel(displayroles);
        }
        cell.setAttribute('data-roles', displayroles || '');
    }

    function setOpen(open) {
        if (!backdrop) {
            return;
        }
        backdrop.hidden = !open;
        if (!open) {
            activeCell = null;
            activeUserid = 0;
            body.innerHTML = '';
        }
    }

    function renderCheckboxes(data) {
        var html = '';
        var hasReadonly = data.readonlyroles && data.readonlyroles.length;
        var hasEditable = data.roles && data.roles.length;

        if (hasReadonly) {
            html += '<div class="tm-search-roles-readonly mb-3">';
            html += '<p class="small font-weight-bold mb-2">' + cfg.str.readonlyHeader + '</p>';
            data.readonlyroles.forEach(function(role) {
                html += '<div class="tm-search-roles-readonly-item">';
                html += '<strong>' + escapeHtml(role.name || role.shortname) + '</strong>';
                if (role.shortname && role.name && role.name !== role.shortname) {
                    html += ' <span class="text-muted">(' + escapeHtml(role.shortname) + ')</span>';
                }
                html += '</div>';
            });
            html += '<p class="small text-muted mb-0 mt-2">' + cfg.str.readonlyNote + '</p>';
            html += '</div>';
        }

        if (!hasEditable) {
            if (!hasReadonly) {
                body.innerHTML = '<p class="text-muted mb-0">' + cfg.str.noRolesAvailable + '</p>';
            } else {
                body.innerHTML = html;
            }
            return;
        }

        body.innerHTML = '';
        if (hasReadonly) {
            var readonlyWrap = document.createElement('div');
            readonlyWrap.innerHTML = html;
            while (readonlyWrap.firstChild) {
                body.appendChild(readonlyWrap.firstChild);
            }
        }

        var title = document.createElement('p');
        title.className = 'tm-search-roles-editable-title';
        title.textContent = cfg.str.editableHeader;
        body.appendChild(title);

        var list = document.createElement('div');
        list.className = 'tm-search-roles-checklist';
        data.roles.forEach(function(role) {
            var displayname = String(role.name || role.shortname || '').trim();
            var shortname = String(role.shortname || '').trim();
            var id = 'tm-search-role-' + data.userid + '-' + role.id;

            var row = document.createElement('div');
            row.className = 'tm-search-roles-row';

            var namebox = document.createElement('div');
            namebox.className = 'tm-search-roles-row-name';

            var nameEl = document.createElement('div');
            nameEl.className = 'tm-search-roles-row-shortname';
            nameEl.textContent = displayname;
            namebox.appendChild(nameEl);

            if (shortname && shortname !== displayname) {
                var descEl = document.createElement('div');
                descEl.className = 'tm-search-roles-row-desc';
                descEl.textContent = shortname;
                namebox.appendChild(descEl);
            }

            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'tm-search-roles-row-checkbox';
            checkbox.id = id;
            checkbox.value = String(role.id);
            checkbox.checked = !!role.assigned;
            checkbox.setAttribute('aria-label', displayname);

            row.appendChild(namebox);
            row.appendChild(checkbox);
            row.addEventListener('click', function(ev) {
                if (ev.target === checkbox) {
                    return;
                }
                ev.preventDefault();
                checkbox.checked = !checkbox.checked;
            });
            list.appendChild(row);
        });
        body.appendChild(list);
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function openEditor(cell) {
        if (loading) {
            return;
        }
        var userid = parseInt(cell.getAttribute('data-userid'), 10);
        if (!userid) {
            return;
        }
        activeCell = cell;
        activeUserid = userid;
        loading = true;
        saveBtn.disabled = true;
        body.innerHTML = '<p class="text-muted mb-0">' + cfg.str.loading + '</p>';
        setOpen(true);

        fetch(apiUrl({action: 'load', userid: userid, sesskey: cfg.sesskey}), {
            credentials: 'same-origin',
            headers: {Accept: 'application/json'}
        })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.ok) {
                    throw new Error(data.error || 'load');
                }
                titleEl.textContent = cfg.str.modalTitle.replace('{$a}', data.fullname);
                renderCheckboxes(data);
                saveBtn.disabled = false;
            })
            .catch(function() {
                body.innerHTML = '<p class="text-danger mb-0">' + cfg.str.loadError + '</p>';
            })
            .finally(function() {
                loading = false;
            });
    }

    function saveRoles() {
        if (!activeUserid || !activeCell || loading) {
            return;
        }
        var checked = body.querySelectorAll('input[type="checkbox"]:checked');
        var roleids = [];
        checked.forEach(function(el) {
            roleids.push(el.value);
        });
        var form = new FormData();
        form.append('action', 'save');
        form.append('userid', String(activeUserid));
        form.append('sesskey', cfg.sesskey);
        roleids.forEach(function(id) {
            form.append('roleids[]', id);
        });

        loading = true;
        saveBtn.disabled = true;
        saveBtn.textContent = cfg.str.saving;

        fetch(cfg.apiBase, {
            method: 'POST',
            credentials: 'same-origin',
            body: form,
            headers: {Accept: 'application/json'}
        })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.ok) {
                    throw new Error(data.error || 'save');
                }
                updateCell(activeCell, data.displayroles || '');
                setOpen(false);
            })
            .catch(function() {
                window.alert(cfg.str.saveError);
            })
            .finally(function() {
                loading = false;
                saveBtn.disabled = false;
                saveBtn.textContent = cfg.str.save;
            });
    }

    document.querySelectorAll('.tm-search-roles-cell').forEach(function(cell) {
        cell.addEventListener('click', function() {
            openEditor(cell);
        });
        cell.addEventListener('keydown', function(ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                openEditor(cell);
            }
        });
    });

    if (saveBtn) {
        saveBtn.addEventListener('click', saveRoles);
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', function() { setOpen(false); });
    }
    if (backdrop) {
        backdrop.addEventListener('click', function(ev) {
            if (ev.target === backdrop) {
                setOpen(false);
            }
        });
    }
})();
