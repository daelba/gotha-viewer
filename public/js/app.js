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
    var nodes = canvas ? canvas.querySelectorAll('.node') : [];

    /* ---- přechod z jiného rodu: scroll + zvýraznění osoby (#p=QID) ---- */
    var hashM = /^#p=(Q[0-9]+)$/.exec(window.location.hash || '');
    if (hashM) {
        var targetNode = canvas ? canvas.querySelector('[data-id="' + hashM[1] + '"]') : null;
        if (targetNode) {
            Array.prototype.forEach.call(nodes, function (n) { n.classList.remove('hl'); });
            targetNode.classList.add('hl');
            // mírné zpoždění kvůli layoutu; bez smooth, ať to neseká při načtení
            setTimeout(function () {
                scrollNodeIntoView(targetNode, false);
            }, 50);
        }
    }

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
                var links = '<div class="person-links">';
                // URL entity ve zdrojové Wikibase posílá API (wiki_url); fallback = gotha-wb
                var wbUrl = data.wikiUrl || ('https://gotha-wb.daelba.eu/wiki/Item:' + encodeURIComponent(data.id));
                links += '<a class="wb-link" href="' + esc(wbUrl) + '" target="_blank" rel="noopener">' + esc(data.id) + ' ↗</a>';
                if (data.wikidataId) {
                    links += '<a class="ext-link" href="https://www.wikidata.org/wiki/' + encodeURIComponent(data.wikidataId) +
                            '" target="_blank" rel="noopener" title="Wikidata"><img src="' + f.base + '/icons/wikidata.svg" alt="Wikidata"></a>';
                    links += '<a class="ext-link" href="https://entitree.com/en/family_tree/' + encodeURIComponent(data.wikidataId) +
                            '" target="_blank" rel="noopener" title="Entitree"><img src="' + f.base + '/icons/entitree.png" alt="Entitree"></a>';
                }
                links += '</div>';
                html += links;
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
    Array.prototype.forEach.call(nodes, function (node) {
        node.addEventListener('click', function () {
            if (window.gvSuppressNextClick) { // po drag-to-pan gestu klik ignorujeme
                window.gvSuppressNextClick = false;
                return;
            }
            loadPerson(node.getAttribute('data-id'));
        });
    });

    /* ---- navigace mezi duplicitními instancemi osoby ----
     * Osoba, která je zároveň rodičem a dítětem v tomto stromu,
     * je vykreslena dvakrát. Ikona s třídou .node-dup-link nese v
     * data-goto HTML id "druhého" boxu. Klik ji posune do středu a
     * zvýrazní. Zároveň zruší klik-bublání na boxu, aby se neotvíral
     * postranní panel. */
    if (canvas) {
        canvas.addEventListener('click', function (e) {
            var a = e.target && e.target.closest ? e.target.closest('.node-dup-link') : null;
            if (!a) return;
            e.preventDefault();
            e.stopPropagation();
            var gotoId = a.getAttribute('data-goto');
            if (!gotoId) return;
            var target = document.getElementById(gotoId);
            if (!target) return;
            Array.prototype.forEach.call(nodes, function (n) { n.classList.remove('hl'); });
            target.classList.add('hl');
            scrollNodeIntoView(target, true);
        }, true);
    }

    /* ---- zoom + pan stromu ----
     * .tree-viewport je rodič se scrollem, uvnitř je .tree-canvas s transformem.
     * Při změně zoomu škálujeme i spacer (drží rozměry scroll zóny) – bez toho
     * by transformovaný obsah „vyplaval" mimo scrollovatelnou plochu. */
    var viewport = document.getElementById('tree-viewport');
    var zoomLabel = document.getElementById('zoom-level');
    var btnIn = document.getElementById('zoom-in');
    var btnOut = document.getElementById('zoom-out');
    var btnReset = document.getElementById('zoom-reset');

    var ZOOM_STEP = 1.25;
    var SCALE_MIN = 0.4;
    var SCALE_MAX = 2.5;
    var state = { scale: 1 };

    /** Souředí zvýrazněný box ve viewportu – funguje i při zoomu ≠ 1,
     *  na rozdíl od nativního scrollIntoView, které transform ignoruje. */
    function scrollNodeIntoView(node, smooth) {
        if (!viewport || !node) return;
        var v = viewport.getBoundingClientRect();
        var n = node.getBoundingClientRect();
        var dx = (n.left + n.width / 2) - (v.left + v.width / 2);
        var dy = (n.top + n.height / 2) - (v.top + v.height / 2);
        viewport.scrollBy({
            left: dx,
            top: dy,
            behavior: smooth ? 'smooth' : 'auto',
        });
    }

    if (canvas && viewport) {
        var apply = function () {
            canvas.style.width = canvas.scrollWidth + 'px';
            canvas.style.height = canvas.scrollHeight + 'px';
            canvas.style.transform = 'scale(' + state.scale + ')';
            if (zoomLabel) {
                zoomLabel.textContent = Math.round(state.scale * 100) + ' %';
            }
        };

        var setScale = function (next, pivotX, pivotY) {
            var s = Math.max(SCALE_MIN, Math.min(SCALE_MAX, next));
            if (s === state.scale) return;

            var r = viewport.getBoundingClientRect();
            if (pivotX == null) { pivotX = r.left + r.width / 2; }
            if (pivotY == null) { pivotY = r.top + r.height / 2; }

            // pozice pivotu v souřadnicích stromu před změnou
            var px = (viewport.scrollLeft + (pivotX - r.left)) / state.scale;
            var py = (viewport.scrollTop + (pivotY - r.top)) / state.scale;

            state.scale = s;
            apply();

            // pivot držíme na místě
            viewport.scrollLeft = px * state.scale - (pivotX - r.left);
            viewport.scrollTop = py * state.scale - (pivotY - r.top);
        };

        // tlačítka
        if (btnIn) btnIn.addEventListener('click', function () { setScale(state.scale * ZOOM_STEP); });
        if (btnOut) btnOut.addEventListener('click', function () { setScale(state.scale / ZOOM_STEP); });
        if (btnReset) btnReset.addEventListener('click', function () { setScale(1); });

        // Kolečko myši = zoom kolem kurzoru (klasický scroll nahradí drag-to-pan)
        viewport.addEventListener('wheel', function (e) {
            e.preventDefault();
            var dir = e.deltaY > 0 ? -1 : 1;
            setScale(state.scale * (dir > 0 ? ZOOM_STEP : 1 / ZOOM_STEP), e.clientX, e.clientY);
        }, { passive: false });

        // drag-to-pan na prázdné ploše (ne na boxech – ty mají vlastní klik)
        var pan = null;
        var panMoved = false;
        viewport.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            if (e.target.closest && e.target.closest('.node, a, button')) return;
            pan = { x: e.clientX, y: e.clientY, sx: viewport.scrollLeft, sy: viewport.scrollTop };
            panMoved = false;
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!pan) return;
            var dx = e.clientX - pan.x;
            var dy = e.clientY - pan.y;
            if (!panMoved && Math.abs(dx) < 4 && Math.abs(dy) < 4) return;
            panMoved = true;
            viewport.style.cursor = 'grabbing';
            viewport.scrollLeft = pan.sx - dx;
            viewport.scrollTop = pan.sy - dy;
        });
        document.addEventListener('mouseup', function () {
            if (!pan) return;
            if (panMoved) {
                // potlač následný "click" po drag gestu (jinak by se otevřel panel)
                window.gvSuppressNextClick = true;
            }
            pan = null;
            panMoved = false;
            viewport.style.cursor = '';
        });

        apply();
    }

    /* ---- hledání ve stromě ---- */
    var search = document.getElementById(f.searchInput);
    var results = document.getElementById(f.resultsList);
    if (search && results) {
        var highlight = function (node) {
            Array.prototype.forEach.call(nodes, function (n) { n.classList.remove('hl'); });
            if (node) {
                node.classList.add('hl');
                scrollNodeIntoView(node, true);
            }
        };

        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            results.innerHTML = '';
            if (q.length < 2) {
                results.style.display = 'none';
                return;
            }
            // hledání podřetězce kdekoli v jménu (prefixové shody řadíme na začátek)
            var matches = [], prefix = [];
            Array.prototype.forEach.call(nodes, function (node) {
                var label = node.getAttribute('data-label') || '';
                var idx = label.indexOf(q);
                if (idx === 0) prefix.push(node);
                else if (idx > 0) matches.push(node);
            });
            matches = prefix.concat(matches);
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
