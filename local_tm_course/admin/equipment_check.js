(function() {
    'use strict';

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
                var m = input.name.match(/^equip\[(\d+)\]\[(status|remark)\]$/);
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
                hidden.name = 'equip_all[' + desknumber + '][' + itemid + '][' + field + ']';
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

    function init() {
        var btn = document.getElementById('tm-equip-save-all-btn');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function() {
            collectAndSubmit(btn);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
