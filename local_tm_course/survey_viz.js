/**
 * Local SVG charts for survey result cards. No CDN.
 */
(function() {
    var COLORS = ['#1a73e8', '#34a853', '#f9ab00', '#ea4335', '#9334e6', '#12b5cb', '#e8710a', '#5f6368'];

    function parse(el, attr) {
        try {
            return JSON.parse(el.getAttribute(attr) || '[]');
        } catch (e) {
            return [];
        }
    }

    function color(i) {
        return COLORS[i % COLORS.length];
    }

    function emptyNode(text) {
        var p = document.createElement('p');
        p.className = 'tm-sviz-nodata';
        p.textContent = text || '—';
        return p;
    }

    function drawPie(host, series) {
        host.innerHTML = '';
        var total = 0;
        series.forEach(function(row) { total += Number(row.count) || 0; });
        if (total <= 0) {
            host.appendChild(emptyNode());
            return;
        }
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 200 200');
        svg.setAttribute('class', 'tm-sviz-pie');
        svg.setAttribute('role', 'img');
        var angle = -Math.PI / 2;
        series.forEach(function(row, i) {
            var count = Number(row.count) || 0;
            if (count <= 0) { return; }
            var slice = (count / total) * Math.PI * 2;
            var x1 = 100 + 80 * Math.cos(angle);
            var y1 = 100 + 80 * Math.sin(angle);
            angle += slice;
            var x2 = 100 + 80 * Math.cos(angle);
            var y2 = 100 + 80 * Math.sin(angle);
            var large = slice > Math.PI ? 1 : 0;
            var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            var d = 'M100 100 L' + x1 + ' ' + y1 + ' A80 80 0 ' + large + ' 1 ' + x2 + ' ' + y2 + ' Z';
            if (series.filter(function(r) { return (Number(r.count) || 0) > 0; }).length === 1) {
                d = 'M100 20 A80 80 0 1 1 99.9 20 Z';
            }
            path.setAttribute('d', d);
            path.setAttribute('fill', color(i));
            var title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
            title.textContent = (row.label || '') + ' ' + count;
            path.appendChild(title);
            svg.appendChild(path);
        });
        host.appendChild(svg);
    }

    function drawBar(host, series, vertical) {
        host.innerHTML = '';
        var max = 1;
        series.forEach(function(row) {
            var c = Number(row.count) || 0;
            if (c > max) { max = c; }
        });
        var wrap = document.createElement('div');
        wrap.className = vertical ? 'tm-sviz-bars tm-sviz-bars-v' : 'tm-sviz-bars';
        series.forEach(function(row, i) {
            var count = Number(row.count) || 0;
            var pct = Math.round((count / max) * 100);
            var item = document.createElement('div');
            item.className = 'tm-sviz-bar-item';
            var label = document.createElement('span');
            label.className = 'tm-sviz-bar-label';
            label.textContent = row.label || '';
            var track = document.createElement('span');
            track.className = 'tm-sviz-bar-track';
            var fill = document.createElement('span');
            fill.className = 'tm-sviz-bar-fill';
            fill.style.background = color(i);
            if (vertical) {
                fill.style.height = pct + '%';
            } else {
                fill.style.width = pct + '%';
            }
            track.appendChild(fill);
            var num = document.createElement('span');
            num.className = 'tm-sviz-bar-num';
            num.textContent = String(count);
            item.appendChild(label);
            item.appendChild(track);
            item.appendChild(num);
            wrap.appendChild(item);
        });
        host.appendChild(wrap);
    }

    function drawCloud(host, tokens) {
        host.innerHTML = '';
        if (!tokens || !tokens.length) {
            host.appendChild(emptyNode());
            return;
        }
        var max = 1;
        tokens.forEach(function(t) {
            if (t.count > max) { max = t.count; }
        });
        tokens.forEach(function(t, i) {
            var span = document.createElement('span');
            span.className = 'tm-sviz-word';
            var scale = 0.85 + (t.count / max) * 1.6;
            span.style.fontSize = scale + 'rem';
            span.style.color = color(i);
            span.textContent = t.text;
            span.title = t.text + ' × ' + t.count;
            host.appendChild(span);
        });
    }

    function paint(card) {
        card.querySelectorAll('.tm-sviz-swatch').forEach(function(sw, i) {
            sw.style.background = color(i);
        });
        var chart = card.querySelector('.tm-sviz-chart');
        if (!chart) { return; }
        var modeBtn = card.querySelector('.tm-sviz-mode.is-active');
        var mode = modeBtn ? modeBtn.getAttribute('data-mode') : (chart.getAttribute('data-mode') || 'bar');
        var qtype = card.getAttribute('data-qtype');
        if (qtype === 'text') {
            var cloud = card.querySelector('.tm-sviz-cloud');
            var list = card.querySelector('.tm-sviz-list');
            if (mode === 'list') {
                if (cloud) { cloud.hidden = true; cloud.classList.remove('is-active'); }
                if (list) { list.hidden = false; }
            } else {
                if (list) { list.hidden = true; }
                if (cloud) {
                    cloud.hidden = false;
                    cloud.classList.add('is-active');
                    drawCloud(cloud, parse(cloud, 'data-tokens'));
                }
            }
            return;
        }
        var series = parse(chart, 'data-series');
        chart.setAttribute('data-mode', mode);
        if (qtype === 'scale' || mode === 'bar') {
            drawBar(chart, series, qtype === 'scale');
        } else {
            drawPie(chart, series);
        }
    }

    function scan(scope) {
        var root = scope || document;
        root.querySelectorAll('.tm-sviz-card').forEach(paint);
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest ? e.target.closest('.tm-sviz-mode') : null;
        if (!btn) { return; }
        var card = btn.closest('.tm-sviz-card');
        if (!card) { return; }
        card.querySelectorAll('.tm-sviz-mode').forEach(function(el) {
            el.classList.toggle('is-active', el === btn);
        });
        paint(card);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { scan(document); });
    } else {
        scan(document);
    }
    window.tmSurveyViz = { scan: scan };
})();
