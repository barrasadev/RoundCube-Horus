/**
 * Horus :: the folder colour picker.
 *
 * Loaded only in the folder properties frame. Clicking a swatch writes it into the
 * hidden _horus_color field the form posts; the native colour input is the custom
 * swatch and doubles as the preview of whatever is currently chosen.
 *
 * @license GNU GPLv3+
 */

window.rcmail && rcmail.addEventListener('init', function () {
    var picker = document.querySelector('.horus-color-picker');

    if (!picker) {
        return;
    }

    var field  = picker.querySelector('#horus-color-value');
    var custom = picker.querySelector('#horus-color-custom');

    function select(color) {
        field.value = color;

        picker.querySelectorAll('.horus-swatch').forEach(function (swatch) {
            if (swatch === custom) {
                return;
            }

            swatch.classList.toggle('selected', (swatch.dataset.color || '') === color);
        });

        // A colour that is not one of the presets is shown on the custom swatch.
        custom.classList.toggle('selected', color !== '' && !picker.querySelector('.horus-swatch.selected'));

        if (color !== '') {
            custom.value = color;
        }
    }

    picker.querySelectorAll('.horus-swatch[data-color]').forEach(function (swatch) {
        swatch.addEventListener('click', function () {
            select(swatch.dataset.color || '');
        });
    });

    custom.addEventListener('input', function () {
        select(custom.value);
    });

    select(field.value || '');
});
