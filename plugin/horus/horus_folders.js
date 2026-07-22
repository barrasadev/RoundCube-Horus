/**
 * Horus :: the folder colour picker.
 *
 * Loaded only in the folder properties frame. Three ways in - a preset swatch, the
 * browser's colour map, or a typed hex - all writing the same hidden _horus_color
 * field the form posts, and all reflected in a preview of the sidebar row.
 *
 * @license GNU GPLv3+
 */

window.rcmail && rcmail.addEventListener('init', function () {
    var picker = document.querySelector('.horus-color-picker');

    if (!picker) {
        return;
    }

    var field   = picker.querySelector('#horus-color-value');
    var custom  = picker.querySelector('#horus-color-custom');
    var hex     = picker.querySelector('#horus-color-hex');
    var preview = picker.querySelector('.horus-color-preview-row');

    function valid(color) {
        return /^#[0-9a-f]{6}$/i.test(color);
    }

    /**
     * Black or white text on the chosen background - the same WCAG luminance the
     * server uses to paint the sidebar, so the preview cannot disagree with it.
     */
    function foreground(color) {
        var luminance = [1, 3, 5].map(function (offset) {
            var c = parseInt(color.substr(offset, 2), 16) / 255;
            return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        });

        // 0.179 is where black and white contrast equally against a background; above
        // it black wins, below it white does.
        return 0.2126 * luminance[0] + 0.7152 * luminance[1] + 0.0722 * luminance[2] > 0.179
            ? '#1f2328' : '#ffffff';
    }

    // `source` is left alone so typing a hex is not fought over by the caret.
    function select(color, source) {
        color = valid(color) ? color.toLowerCase() : '';

        field.value = color;

        var preset = false;

        picker.querySelectorAll('.horus-swatch[data-color]').forEach(function (swatch) {
            var match = (swatch.dataset.color || '') === color;

            swatch.classList.toggle('selected', match);
            preset = preset || (match && color !== '');
        });

        // A colour that is not one of the presets belongs to the custom swatch.
        custom.classList.toggle('selected', color !== '' && !preset);

        if (color !== '' && source !== custom) {
            custom.value = color;
        }

        if (source !== hex) {
            hex.value = color;
        }

        preview.style.backgroundColor = color || '';
        preview.style.color = color ? foreground(color) : '';
        preview.classList.toggle('none', color === '');
    }

    picker.querySelectorAll('.horus-swatch[data-color]').forEach(function (swatch) {
        swatch.addEventListener('click', function () {
            select(swatch.dataset.color || '', swatch);
        });
    });

    custom.addEventListener('input', function () {
        select(custom.value, custom);
    });

    hex.addEventListener('input', function () {
        var typed = hex.value.trim();

        // A bare rrggbb is what a copied hex usually looks like; accept it.
        if (/^[0-9a-f]{6}$/i.test(typed)) {
            typed = '#' + typed;
        }

        hex.classList.toggle('error', typed !== '' && !valid(typed));

        if (typed === '' || valid(typed)) {
            select(typed, hex);
        }
    });

    // Leaving the field with something unusable in it: put back what is in force.
    hex.addEventListener('blur', function () {
        hex.classList.remove('error');
        hex.value = field.value;
    });

    select(field.value || '');
});
