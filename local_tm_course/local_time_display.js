/**
 * Learner-facing session times in the browser's local timezone (Scheme A: time + GMT offset).
 *
 * @package    local_tm_course
 */
(function(global) {
    'use strict';

    function locale() {
        var lang = (document.documentElement && document.documentElement.lang) || '';
        return lang ? lang.replace(/_/g, '-') : undefined;
    }

    function pad2(n) {
        return String(n).padStart(2, '0');
    }

    function offsetLabel(date) {
        try {
            var parts = new Intl.DateTimeFormat('en-US', { timeZoneName: 'shortOffset' }).formatToParts(date);
            for (var i = 0; i < parts.length; i++) {
                if (parts[i].type === 'timeZoneName') {
                    return parts[i].value;
                }
            }
        } catch (e) {
            // Ignore.
        }
        var mins = -date.getTimezoneOffset();
        var sign = mins >= 0 ? '+' : '-';
        var abs = Math.abs(mins);
        var h = Math.floor(abs / 60);
        var m = abs % 60;
        return 'GMT' + sign + h + (m ? ':' + pad2(m) : '');
    }

    function formatHm(date) {
        return pad2(date.getHours()) + ':' + pad2(date.getMinutes());
    }

    function dayKey(date) {
        return date.getFullYear() + '-' + date.getMonth() + '-' + date.getDate();
    }

    function formatDateHeader(ts) {
        var d = new Date(ts * 1000);
        try {
            return new Intl.DateTimeFormat(locale(), {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }).format(d);
        } catch (e) {
            return d.toLocaleDateString();
        }
    }

    function formatStarts(ts) {
        var d = new Date(ts * 1000);
        var tz = offsetLabel(d);
        try {
            var formatted = new Intl.DateTimeFormat(locale(), {
                year: 'numeric',
                month: 'numeric',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            }).format(d);
            return formatted + ' (' + tz + ')';
        } catch (e) {
            return formatDateHeader(ts) + ', ' + formatHm(d) + ' (' + tz + ')';
        }
    }

    function formatTimeRange(startTs, endTs) {
        var start = new Date(startTs * 1000);
        var end = new Date(endTs * 1000);
        var tz = offsetLabel(start);

        if (dayKey(start) === dayKey(end)) {
            var datePart;
            try {
                datePart = new Intl.DateTimeFormat(locale(), {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                }).format(start);
            } catch (e) {
                datePart = start.toLocaleDateString();
            }
            return datePart + ', ' + formatHm(start) + ' – ' + formatHm(end) + ' (' + tz + ')';
        }

        try {
            var fmt = new Intl.DateTimeFormat(locale(), {
                year: '2-digit',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            });
            return fmt.format(start) + ' – ' + fmt.format(end) + ' (' + tz + ')';
        } catch (e) {
            return start.toLocaleString() + ' – ' + end.toLocaleString() + ' (' + tz + ')';
        }
    }

    function formatEventHmWithTz(date) {
        if (!date || isNaN(date.getTime())) {
            return '';
        }
        return formatHm(date) + ' (' + offsetLabel(date) + ')';
    }

    function apply(root) {
        root = root || document;
        var nodes = root.querySelectorAll('.js-tm-local-time[data-tm-start-ts]');
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            var mode = el.getAttribute('data-tm-local-mode') || 'starts';
            var start = parseInt(el.getAttribute('data-tm-start-ts'), 10);
            var end = parseInt(el.getAttribute('data-tm-end-ts'), 10);
            if (!start || isNaN(start)) {
                continue;
            }
            if (mode === 'date') {
                el.textContent = formatDateHeader(start);
            } else if (mode === 'range' && end && !isNaN(end)) {
                el.textContent = formatTimeRange(start, end);
            } else if (mode === 'starts') {
                el.textContent = formatStarts(start);
            }
        }
    }

    global.tmCourseLocalTime = {
        apply: apply,
        formatDateHeader: formatDateHeader,
        formatStarts: formatStarts,
        formatTimeRange: formatTimeRange,
        formatEventHmWithTz: formatEventHmWithTz,
        offsetLabel: offsetLabel
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            apply(document);
        });
    } else {
        apply(document);
    }
})(window);
