/**
 * Horus :: client side.
 *
 * Three small jobs: the compose-time tracked-attachment zone, the expandable detail
 * drawers, and the sidebar task command. Everything else is server-rendered.
 *
 * @license GNU GPLv3+
 */

window.rcmail && rcmail.addEventListener('init', function () {
    rcmail.register_command('horus', function () { rcmail.switch_task('horus'); }, true);
    rcmail.register_command('plugin.horus.upload', horus_upload, true);
    rcmail.register_command('plugin.horus.delete', horus_delete, true);
    rcmail.register_command('plugin.horus.toggle', horus_toggle, true);
    rcmail.register_command('plugin.horus.details', horus_toggle_details, true);
    rcmail.register_command('plugin.horus.row', horus_toggle_row, true);
    rcmail.register_command('plugin.horus.series', horus_toggle_series, true);
    rcmail.register_command('plugin.horus.markbot', horus_mark_bot, true);
    rcmail.register_command('plugin.horus.dialog', horus_open_dialog, true);

    if (rcmail.env.action === 'compose') {
        horus_compose_init();
        horus_split_watch();
    }

    horus_chart_init();

    // Tag Sent-folder rows as they are inserted, so the list shows tracking state
    // without opening anything.
    rcmail.addEventListener('insertrow', horus_decorate_row);

    // The server re-classifies history when an address is marked; reload so the
    // corrected counters are what the user sees.
    rcmail.addEventListener('plugin.horus_marked', function () {
        setTimeout(function () { window.location.reload(); }, 1200);
    });

    horus_header_link();
});

/* ----------------------------------------------------- message header link */

/** Eye of Horus. Keep in step with the 'horus' entry in lib/horus_icons.php. */
var HORUS_EYE_ICON = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"'
    + ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
    + ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
    + '<path d="M3.5 6.2c2-1.6 4.4-2.4 6.9-2.4 3.6 0 6.6 1.9 8.6 4.6"/>'
    + '<path d="M2.5 12.6c2.2-3 5.1-4.6 8.2-4.6s6 1.6 8.2 4.6c-2.2 3-5.1 4.6-8.2 4.6s-6-1.6-8.2-4.6z"/>'
    + '<circle cx="10.7" cy="12.6" r="2.1"/>'
    + '<path d="M8.6 16.9L7.2 21"/>'
    + '<path d="M13.4 16.4c1.6 1.4 3.4 2.1 5.4 2.1-1.1 1.4-2.6 2.1-4.4 2.1-1 0-1.8-.4-2.4-1.1"/></svg>';

/**
 * Put "Horus" in the skin's header-links row, next to Details / Headers / Plain
 * text, and open the full report in a dialog.
 *
 * The row is rendered by the skin, so this attaches to it rather than trying to
 * template into it; if the skin has no such row the link is skipped and the data
 * simply stays hidden.
 */
function horus_header_link() {
    var data = document.getElementById('horus-msgdata');

    if (!data) {
        return;
    }

    var links = document.querySelector('div.header-links');

    if (!links || links.querySelector('.horus-headerlink')) {
        return;
    }

    var a = document.createElement('a');

    a.href = '#horus';
    a.className = 'horus-headerlink';
    a.title = rcmail.env.horus_summary || '';
    a.onclick = function () { horus_open_dialog(); return false; };

    // The Eye of Horus, matching the 'horus' entry in lib/horus_icons.php. Its
    // colour carries the state here, the same way the other links in this row
    // carry their own icon. Inside the dialog the eye is neutral; only this one
    // outside is coloured.
    var eye = document.createElement('span');
    eye.className = 'horus-eye-state horus-state-' + (rcmail.env.horus_state || 'untracked');
    eye.innerHTML = HORUS_EYE_ICON;
    a.appendChild(eye);
    a.appendChild(document.createTextNode(rcmail.get_label('horus.horusdetails')));

    links.appendChild(a);
}

/** Show the full Horus report for the open message. */
function horus_open_dialog() {
    var data = document.getElementById('horus-msgdata');

    if (!data) {
        return;
    }

    // Clone: the dialog is destroyed on close and would take the original with it.
    var content = $(data).clone().removeAttr('id').show();

    // Size the dialog to its content. jQuery UI's height:'auto' collapses this
    // dialog to nothing, so measure the markup off-screen first and pass a number:
    // a message with no activity yet should not open a half-empty 500px panel, and
    // a long timeline should still get room before it starts scrolling.
    var probe = content.clone()
        .css({ position: 'absolute', visibility: 'hidden', width: '720px', left: '-9999px' })
        .appendTo(document.body);

    var measured = probe.outerHeight() || 0;
    probe.remove();

    var ceiling = Math.max(320, Math.round(window.innerHeight * 0.8));
    var height = Math.min(ceiling, Math.max(200, measured + 110));

    rcmail.simple_dialog(content, 'horus.horusdetails', null, {
        cancel_button: 'close',
        width: 760,
        height: height
    });
}

/**
 * Report an address as a bot. Everything it already did is re-classified server
 * side, so the page is reloaded to show the corrected state.
 */
function horus_mark_bot(ip) {
    if (!ip) {
        return;
    }

    rcmail.http_post('plugin.horus.markbot', { _ip: ip }, true);
}

/* ------------------------------------------------------- message list badge */

/** Icon geometry per state, mirroring lib/horus_icons.php. */
var HORUS_ROW_ICONS = {
    notopened:  '<path d="M21.5 3.5L2.5 10.2l7.3 2.9 2.9 7.3z"/><path d="M21.5 3.5L9.8 13.1"/>',
    maybe:      '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.2 2.4c-.6.2-1 .8-1 1.4v.4"/><path d="M11.7 17h.01"/>',
    // An eye: "opened" means someone looked at it, which an envelope did not say.
    opened:     '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/>',
    clicked:    '<path d="M10 13a5 5 0 0 0 7.1.4l2.5-2.5a5 5 0 0 0-7.1-7.1L11 5.3"/><path d="M14 11a5 5 0 0 0-7.1-.4l-2.5 2.5a5 5 0 0 0 7.1 7.1L13 18.7"/>',
    downloaded: '<path d="M12 3v12"/><path d="M7.5 10.5L12 15l4.5-4.5"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
    untracked:  '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>'
};

/**
 * Add the Horus state pill to one message row.
 *
 * Roundcube rebuilds rows on every list refresh, so this runs per row rather than
 * once over the table.
 */
function horus_decorate_row(event) {
    var uid = event.uid, row = event.row;

    if (!row || !row.obj || !rcmail.env.messages || !rcmail.env.messages[uid]) {
        return;
    }

    var flags = rcmail.env.messages[uid].flags || {};

    if (!flags.horus) {
        return;
    }

    // Several states can be true at once - opened AND clicked AND downloaded - and
    // each gets its own badge. They arrive comma-joined because extra_flags carries
    // a plain value.
    var states = String(flags.horus).split(',').filter(function (s) {
        return HORUS_ROW_ICONS[s];
    });

    if (!states.length) {
        return;
    }

    // The badges belong on the recipient line, not the subject: that is the line the
    // eye lands on in a Sent folder. `.fromto` holds the address list in every
    // Elastic layout; fall back to the subject only if the skin has no such element.
    var cell = row.obj.querySelector('.fromto')
        || row.obj.querySelector('span.subject')
        || row.obj.querySelector('td.subject')
        || row.obj.querySelector('td:last-child');

    if (!cell || cell.querySelector('.horus-pill')) {
        return;
    }

    var labels = rcmail.env.horus_list_labels || {};

    states.forEach(function (state, i) {
        var pill = document.createElement('span');

        pill.className = 'horus-pill horus-pill-' + state;
        pill.title = labels[state] || state;
        pill.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="2.2" stroke-linecap="round"'
            + ' stroke-linejoin="round" aria-hidden="true">' + HORUS_ROW_ICONS[state] + '</svg>';

        // Icon and colour only. The words were repeating what the colour already
        // said and crowded the row; the label stays on hover.
        pill.classList.add('horus-pill-compact');

        cell.appendChild(pill);
    });
}

/* --------------------------------------------------------------- compose UI */

/** Tracked attachments staged in this compose, keyed by doc id. */
var horus_docs = {};

/**
 * Paperclip, matching lib/horus_icons.php. Held here as a constant rather than
 * fetched, so the list renders synchronously; keep the geometry in step with the
 * 'attachment' entry in that class.
 */
var HORUS_CLIP_ICON = '<svg class="horus-icon" width="14" height="14" viewBox="0 0 24 24"'
    + ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
    + ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
    + '<path d="M21 12.8l-8.5 8.5a5.5 5.5 0 0 1-7.8-7.8l8.9-8.9a3.7 3.7 0 0 1 5.2 5.2l-8.9 8.9'
    + 'a1.8 1.8 0 0 1-2.6-2.6l8.2-8.2"/></svg>';

function horus_compose_init() {
    // A page reload keeps the compose id, so re-sync whatever is already staged.
    horus_request('plugin.horus.list', new FormData(), function (data) {
        (data.docs || []).forEach(function (doc) { horus_docs[doc.id] = doc; });
        horus_render_docs();
    });
}

function horus_upload(input) {
    if (!input || !input.files || !input.files.length) {
        return;
    }

    var form = new FormData();

    for (var i = 0; i < input.files.length; i++) {
        // Reject oversize files before spending the upload.
        if (rcmail.env.horus_max_doc_size && input.files[i].size > rcmail.env.horus_max_doc_size) {
            rcmail.display_message(rcmail.get_label('horus.uploadtoolarge'), 'error');
            continue;
        }

        form.append('_horus_file[]', input.files[i]);
    }

    input.value = '';

    if (!form.has('_horus_file[]')) {
        return;
    }

    horus_set_busy(true);

    horus_request('plugin.horus.upload', form, function (data) {
        horus_set_busy(false);

        (data.docs || []).forEach(function (doc) { horus_docs[doc.id] = doc; });

        (data.errors || []).forEach(function (err) {
            rcmail.display_message(err.name + ': ' + rcmail.get_label(err.error), 'error');
        });

        horus_render_docs();
    }, function () {
        horus_set_busy(false);
        rcmail.display_message(rcmail.get_label('horus.uploaderror'), 'error');
    });
}

function horus_delete(doc_id) {
    var form = new FormData();
    form.append('_doc', doc_id);

    horus_request('plugin.horus.delete', form, function () {
        delete horus_docs[doc_id];
        horus_render_docs();
    });
}

function horus_render_docs() {
    var list = document.getElementById('horus-doclist');

    if (!list) {
        return;
    }

    list.innerHTML = '';

    Object.keys(horus_docs).forEach(function (id) {
        var doc = horus_docs[id];
        var row = document.createElement('div');
        row.className = 'horus-docitem';

        var name = document.createElement('span');
        name.className = 'horus-docname';
        name.innerHTML = HORUS_CLIP_ICON;
        name.appendChild(document.createTextNode(' ' + doc.name));

        var size = document.createElement('span');
        size.className = 'horus-docsize';
        size.textContent = doc.size;

        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'horus-docremove';
        del.title = rcmail.get_label('horus.removedoc');
        del.textContent = '×';
        del.onclick = function () { horus_delete(doc.id); };

        row.appendChild(name);
        row.appendChild(size);
        row.appendChild(del);
        list.appendChild(row);
    });
}

function horus_set_busy(busy) {
    var list = document.getElementById('horus-doclist');

    if (!list) {
        return;
    }

    var existing = document.getElementById('horus-uploading');

    if (busy && !existing) {
        var note = document.createElement('div');
        note.id = 'horus-uploading';
        note.className = 'horus-uploading';
        note.textContent = rcmail.get_label('horus.uploading');
        list.parentNode.insertBefore(note, list.nextSibling);
    }
    else if (!busy && existing) {
        existing.parentNode.removeChild(existing);
    }
}

/* ------------------------------------------- per-recipient option visibility */

/**
 * The "track each recipient separately" option only means anything with more than
 * one recipient, so it is hidden until there is one.
 *
 * Elastic's recipient widget rewrites the fields programmatically as chips are
 * added and removed, so this listens for input AND watches the DOM: typing alone
 * would miss a recipient inserted from the address book.
 */
function horus_split_watch() {
    var group = document.getElementById('horus-split-group');

    if (!group) {
        return;
    }

    var form = document.getElementById('compose-content') || document.body;

    horus_split_update();

    ['input', 'change'].forEach(function (evt) {
        form.addEventListener(evt, horus_split_update, true);
    });

    if (window.MutationObserver) {
        new MutationObserver(horus_split_update).observe(form, {
            childList: true, subtree: true, characterData: true
        });
    }
}

/** Count the addresses currently in To, Cc and Bcc. */
function horus_recipient_count() {
    var seen = {};

    ['_to', '_cc', '_bcc'].forEach(function (name) {
        var fields = document.querySelectorAll('[name="' + name + '"]');

        for (var i = 0; i < fields.length; i++) {
            String(fields[i].value || '').split(',').forEach(function (part) {
                var addr = part.trim().toLowerCase();

                // Only count something that looks like an address, so a half-typed
                // name does not flip the option on and off while you type.
                if (addr.indexOf('@') > 0) {
                    seen[addr] = true;
                }
            });
        }
    });

    return Object.keys(seen).length;
}

function horus_split_update() {
    var group = document.getElementById('horus-split-group');

    if (!group) {
        return;
    }

    var many = horus_recipient_count() > 1;
    group.style.display = many ? '' : 'none';

    // Never send the flag when the option is not on screen: a value left over from
    // an earlier state would silently split a single-recipient message.
    var box = document.getElementById('horus-split');
    if (box && !many) {
        box.checked = false;
    }
}

/**
 * Tracked attachments are only meaningful on a tracked message; make that visible
 * rather than silently sending untracked links.
 */
function horus_toggle(enabled) {
    var group = document.getElementById('horus-docs-group');

    if (group) {
        group.style.opacity = enabled ? '' : '.55';
    }
}

/**
 * POST helper. Carries the compose id and the CSRF token that check_request() wants.
 */
function horus_request(action, form, onsuccess, onerror) {
    form.append('_id', rcmail.env.compose_id || '');
    form.append('_token', rcmail.env.request_token);

    var url = rcmail.url(action, { _task: 'mail' });

    fetch(url, { method: 'POST', body: form, credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && data.error) {
                if (onerror) { onerror(data); }
                return;
            }
            onsuccess(data || {});
        })
        .catch(function () { if (onerror) { onerror(); } });
}

/* -------------------------------------------------------------- activity chart */

/** Series currently hidden by the legend. Empty means "show everything". */
var horus_hidden = {};

var HORUS_KEYS = ['sent', 'opened', 'clicked'];

/**
 * Wire the hover layer. The chart itself is server-rendered SVG; this only adds the
 * crosshair and the tooltip, so the page is fully readable before (and without) JS.
 */
function horus_chart_init() {
    var figure = document.querySelector('.horus-figure');

    if (!figure) {
        return;
    }

    var points, labels;

    try {
        points = JSON.parse(figure.getAttribute('data-points') || '[]');
        labels = JSON.parse(figure.getAttribute('data-labels') || '{}');
    } catch (e) {
        return;
    }

    var svg = figure.querySelector('svg');
    var tip = figure.querySelector('.horus-tooltip');
    var cross = figure.querySelector('.horus-crosshair');
    var bands = figure.querySelectorAll('.horus-band');

    if (!svg || !tip) {
        return;
    }

    Array.prototype.forEach.call(bands, function (band) {
        band.addEventListener('mouseenter', function () {
            var i = parseInt(band.getAttribute('data-i'), 10);
            var p = points[i];

            if (!p) {
                return;
            }

            // Park the crosshair on the band's centre, in the SVG's own coordinates.
            if (cross) {
                var x = parseFloat(band.getAttribute('x')) + parseFloat(band.getAttribute('width')) / 2;
                cross.setAttribute('x1', x);
                cross.setAttribute('x2', x);
                cross.style.display = '';
            }

            tip.innerHTML = '';

            var head = document.createElement('div');
            head.className = 'horus-tip-head';
            head.textContent = p.d;
            tip.appendChild(head);

            HORUS_KEYS.forEach(function (key, idx) {
                if (horus_hidden[key]) {
                    return;
                }

                var row = document.createElement('div');
                row.className = 'horus-tip-row';

                var sw = document.createElement('span');
                sw.className = 'horus-swatch horus-s' + (idx + 1);
                row.appendChild(sw);

                var name = document.createElement('span');
                name.className = 'horus-tip-name';
                name.textContent = labels[key] || key;
                row.appendChild(name);

                var val = document.createElement('span');
                val.className = 'horus-tip-value';
                val.textContent = p[key.charAt(0)];
                row.appendChild(val);

                tip.appendChild(row);
            });

            // Position within the figure, flipping side near the right edge so the
            // tooltip never leaves the card.
            var rect = svg.getBoundingClientRect();
            var ratio = rect.width / (svg.viewBox.baseVal.width || 760);
            var left = (parseFloat(band.getAttribute('x')) + parseFloat(band.getAttribute('width')) / 2) * ratio;

            tip.style.display = 'block';
            tip.style.left = (left > rect.width * 0.7 ? left - tip.offsetWidth - 12 : left + 12) + 'px';
            tip.style.top = '8px';
        });
    });

    figure.addEventListener('mouseleave', function () {
        tip.style.display = 'none';
        if (cross) { cross.style.display = 'none'; }
    });
}

/**
 * Legend toggle. First click isolates the clicked series; clicking the isolated one
 * again brings everything back. Colour slots are fixed server-side, so hiding a
 * series never recolours the others.
 */
function horus_toggle_series(key) {
    var visible = HORUS_KEYS.filter(function (k) { return !horus_hidden[k]; });
    var isolated = visible.length === 1 && visible[0] === key;

    horus_hidden = {};

    if (!isolated) {
        HORUS_KEYS.forEach(function (k) {
            if (k !== key) { horus_hidden[k] = true; }
        });
    }

    HORUS_KEYS.forEach(function (k) {
        var on = !horus_hidden[k];

        document.querySelectorAll('[data-series="' + k + '"]').forEach(function (el) {
            if (el.classList.contains('horus-legend-item')) {
                el.setAttribute('aria-pressed', on ? 'true' : 'false');
                el.classList.toggle('horus-legend-off', !on);
            }
            else {
                el.style.display = on ? '' : 'none';
            }
        });
    });
}

/* ------------------------------------------------------------------ drawers */

/** Expand/collapse the timeline under a sent message's tracking box. */
function horus_toggle_details(id) {
    var el = document.getElementById(id);

    if (!el) {
        return;
    }

    var open = el.style.display !== 'none';
    el.style.display = open ? 'none' : '';

    var button = document.querySelector('[aria-controls="' + id + '"]');
    if (button) {
        button.setAttribute('aria-expanded', open ? 'false' : 'true');
    }
}

/** Expand/collapse a dashboard row's pre-rendered drawer. */
function horus_toggle_row(uuid) {
    var row = document.querySelector('.horus-row[data-uuid="' + uuid + '"]');

    if (!row || !row.nextElementSibling) {
        return;
    }

    var drawer = row.nextElementSibling;
    drawer.style.display = drawer.style.display === 'none' ? '' : 'none';
}
