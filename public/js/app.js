/* Gotha viewer – vanilla JS */
(function () {
    'use strict';

    /* ---------- Tabulka rodů: textový filtr ---------- */
    if (window.gv && window.gv.filterInput) {
        var input = document.getElementById(window.gv.filterInput);
        var table = document.getElementById(window.gv.filterTable);
        var counter = document.getElementById(window.gv.filterCount);
        if (input && table) {
            var rows = table.tBodies[0].rows;
            var total = rows.length;
            var apply = function () {
                var q = input.value.trim().toLowerCase();
                var shown = 0;
                for (var i = 0; i < rows.length; i++) {
                    var hay = rows[i].getAttribute('data-search') || '';
                    var ok = q === '' || hay.indexOf(q) !== -1;
                    rows[i].style.display = ok ? '' : 'none';
                    if (ok) shown++;
                }
                if (counter) {
                    counter.textContent = q === '' ? '' : shown + ' / ' + total;
                }
            };
            input.addEventListener('input', apply);
        }
    }

    /* ---------- Stránka rodu ---------- */
    var f = window.gvFamily;
    if (!f) return;

    var canvas = document.getElementById(f.canvas);
    var panel = document.getElementById(f.panel);
    var panelContent = document.getElementById(f.panelContent);
    var panelClose = document.getElementById(f.panelClose);

    /* ---- postranní panel ---- */
    function openPanel() { panel.classList.add('open'); panel.setAttribute('aria-hidden', 'false'); }
    function closePanel() { panel.classList.remove('open'); panel.setAttribute('aria-hidden', 'true'); }
    if (panelClose) panelClose.addEventListener('click', closePanel);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePanel();
    });

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function loadPerson(qid) {
        openPanel();
        panelContent.innerHTML = '<p class="muted">' + esc(f.loadingText) + '</p>';
        fetch(f.api + encodeURIComponent(qid), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    panelContent.innerHTML = '<p class="flash flash-error">' + esc(data.error) + '</p>';
                    return;
                }
                var html = '<h3>' + esc(data.name) + '</h3>';
                html += '<p class="muted"><a class="wb-link" href="https://gotha.wikibase.cloud/wiki/Item:' +
                        encodeURIComponent(data.id) + '" target="_blank" rel="noopener">' + esc(data.id) + ' ↗</a></p>';
                if (data.wikidataImage) {
                    html += '<img class="person-photo" src="' + esc(data.wikidataImage) + '" alt="">';
                }
                html += '<table><tbody>';
                (data.claims || []).forEach(function (c) {
                    var val = c.link
                        ? '<a href="' + esc(c.link) + '" target="_blank" rel="noopener">' + esc(c.value) + '</a>'
                        : esc(c.value);
                    html += '<tr><th>' + esc(c.plabel) + '</th><td>' + val + '</td></tr>';
                });
                html += '</tbody></table>';
                panelContent.innerHTML = html;
            })
            .catch(function () {
                panelContent.innerHTML = '<p class="flash flash-error">API error</p>';
            });
    }

    /* ---- klik na box osoby ---- */
    var nodes = canvas ? canvas.querySelectorAll('.node') : [];
    Array.prototype.forEach.call(nodes, function (node) {
        node.addEventListener('click', function () {
            loadPerson(node.getAttribute('data-id'));
        });
    });

    /* ---- hledání ve stromě ---- */
    var search = document.getElementById(f.searchInput);
    var results = document.getElementById(f.resultsList);
    if (search && results) {
        var highlight = function (node) {
            Array.prototype.forEach.call(nodes, function (n) { n.classList.remove('hl'); });
            if (node) {
                node.classList.add('hl');
                node.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
            }
        };

        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            results.innerHTML = '';
            if (q.length < 2) {
                results.style.display = 'none';
                return;
            }
            var matches = [];
            Array.prototype.forEach.call(nodes, function (node) {
                var label = node.getAttribute('data-label') || '';
                if (label.indexOf(q) !== -1) matches.push(node);
            });
            if (!matches.length) {
                results.style.display = 'none';
                return;
            }
            matches.slice(0, 12).forEach(function (node) {
                var li = document.createElement('li');
                li.textContent = node.querySelector('.name').textContent;
                li.addEventListener('click', function () {
                    results.style.display = 'none';
                    highlight(node);
                });
                results.appendChild(li);
            });
            results.style.display = 'block';
        });

        document.addEventListener('click', function (e) {
            if (!results.contains(e.target) && e.target !== search) {
                results.style.display = 'none';
            }
        });
    }
})();
