<?php

/**
 * Horus :: country flags as inline SVG.
 *
 * Emoji flags were rejected on purpose: they render differently on every platform,
 * are missing entirely on most of Windows, and look out of place next to a set of
 * line icons. Shipping 250 hand-drawn flags is not reasonable either.
 *
 * So the common cases are composed from their geometry. Most national flags are two
 * or three bands, which is a handful of rectangles; a few widespread ones that are
 * not (US, UK, Japan...) are drawn explicitly. Anything unknown falls back to a
 * neutral chip with the country code, which is always better than a missing glyph.
 *
 * @license GNU GPLv3+
 */
class horus_flags
{
    /**
     * Horizontal band flags: top to bottom.
     */
    const HORIZONTAL = [
        'DE' => ['#000000', '#dd0000', '#ffce00'],
        'NL' => ['#ae1c28', '#ffffff', '#21468b'],
        'RU' => ['#ffffff', '#0039a6', '#d52b1e'],
        'AT' => ['#ed2939', '#ffffff', '#ed2939'],
        'ES' => ['#aa151b', '#f1bf00', '#aa151b'],
        'CO' => ['#fcd116', '#003893', '#ce1126'],
        'HU' => ['#cd2a3e', '#ffffff', '#436f4d'],
        'BG' => ['#ffffff', '#00966e', '#d62612'],
        'LT' => ['#fdb913', '#006a44', '#c1272d'],
        'EE' => ['#0072ce', '#000000', '#ffffff'],
        'LV' => ['#9e3039', '#ffffff', '#9e3039'],
        'PL' => ['#ffffff', '#dc143c', '#ffffff'],
        'UA' => ['#0057b7', '#ffd700', '#0057b7'],
        'AR' => ['#74acdf', '#ffffff', '#74acdf'],
        'LU' => ['#ed2939', '#ffffff', '#00a1de'],
    ];

    /**
     * Vertical band flags: left to right.
     */
    const VERTICAL = [
        'FR' => ['#002395', '#ffffff', '#ed2939'],
        'IT' => ['#009246', '#ffffff', '#ce2b37'],
        'IE' => ['#169b62', '#ffffff', '#ff883e'],
        'BE' => ['#000000', '#fdda24', '#ef3340'],
        'RO' => ['#002b7f', '#fcd116', '#ce1126'],
        'MX' => ['#006847', '#ffffff', '#ce1126'],
        'PE' => ['#d91023', '#ffffff', '#d91023'],
        'CL' => ['#0039a6', '#ffffff', '#d52b1e'],
        'PT' => ['#046a38', '#046a38', '#da291c'],
        'NG' => ['#008751', '#ffffff', '#008751'],
        'IN' => ['#ff9933', '#ffffff', '#138808'],
    ];

    /** Two-band horizontal flags. */
    const HALVES = [
        'ID' => ['#ce1126', '#ffffff'],
        'PL2' => ['#ffffff', '#dc143c'],
        'UA2' => ['#0057b7', '#ffd700'],
    ];

    /**
     * Render a flag for an ISO 3166-1 alpha-2 country code.
     *
     * @param string $code Two-letter code, case insensitive
     * @param int    $w    Width in px (height follows a 3:2 ratio)
     */
    public static function get($code, $w = 16)
    {
        $code = strtoupper(trim((string) $code));

        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            return '';
        }

        $h    = (int) round($w * 2 / 3);
        $open = '<svg class="horus-flag" width="' . $w . '" height="' . $h . '" viewBox="0 0 30 20"'
            . ' role="img" aria-label="' . $code . '" focusable="false">';
        $edge = '<rect x=".5" y=".5" width="29" height="19" fill="none"'
            . ' stroke="rgba(0,0,0,.25)" stroke-width="1"/></svg>';

        if (isset(self::HORIZONTAL[$code])) {
            return $open . self::bands(self::HORIZONTAL[$code], true) . $edge;
        }

        if (isset(self::VERTICAL[$code])) {
            return $open . self::bands(self::VERTICAL[$code], false) . $edge;
        }

        if ($special = self::special($code)) {
            return $open . $special . $edge;
        }

        return self::chip($code);
    }

    /**
     * Equal bands, horizontal or vertical.
     */
    private static function bands(array $colors, $horizontal)
    {
        $n   = count($colors);
        $out = '';

        foreach ($colors as $i => $color) {
            if ($horizontal) {
                $out .= sprintf('<rect x="0" y="%.2f" width="30" height="%.2f" fill="%s"/>',
                    20 / $n * $i, 20 / $n + .01, $color);
            }
            else {
                $out .= sprintf('<rect x="%.2f" y="0" width="%.2f" height="20" fill="%s"/>',
                    30 / $n * $i, 30 / $n + .01, $color);
            }
        }

        return $out;
    }

    /**
     * Flags that are not plain bands but are common enough to be worth drawing.
     */
    private static function special($code)
    {
        switch ($code) {
            case 'US':
                $out = '<rect width="30" height="20" fill="#ffffff"/>';
                for ($i = 0; $i < 7; $i++) {
                    $out .= sprintf('<rect x="0" y="%.2f" width="30" height="1.54" fill="#b22234"/>',
                        $i * 3.08);
                }
                return $out . '<rect width="13" height="10.8" fill="#3c3b6e"/>';

            case 'GB':
                return '<rect width="30" height="20" fill="#012169"/>'
                    . '<path d="M0 0l30 20M30 0L0 20" stroke="#ffffff" stroke-width="4"/>'
                    . '<path d="M0 0l30 20M30 0L0 20" stroke="#c8102e" stroke-width="2"/>'
                    . '<path d="M15 0v20M0 10h30" stroke="#ffffff" stroke-width="6.6"/>'
                    . '<path d="M15 0v20M0 10h30" stroke="#c8102e" stroke-width="4"/>';

            case 'JP':
                return '<rect width="30" height="20" fill="#ffffff"/>'
                    . '<circle cx="15" cy="10" r="6" fill="#bc002d"/>';

            case 'CH':
                return '<rect width="30" height="20" fill="#d52b1e"/>'
                    . '<path d="M13 5h4v3.5h3.5v4H17V16h-4v-3.5H9.5v-4H13z" fill="#ffffff"/>';

            case 'BR':
                return '<rect width="30" height="20" fill="#009c3b"/>'
                    . '<path d="M15 3l12 7-12 7L3 10z" fill="#ffdf00"/>'
                    . '<circle cx="15" cy="10" r="4.2" fill="#002776"/>';

            case 'CA':
                return '<rect width="30" height="20" fill="#ffffff"/>'
                    . '<rect width="7.5" height="20" fill="#d80621"/>'
                    . '<rect x="22.5" width="7.5" height="20" fill="#d80621"/>'
                    . '<path d="M15 5l1.4 3.2 3-.9-1.2 3 2.3 1.4-3 .6.3 2.2-2.8-1.6-2.8 1.6.3-2.2-3-.6 2.3-1.4-1.2-3 3 .9z" fill="#d80621"/>';

            case 'SE':
                return '<rect width="30" height="20" fill="#006aa7"/>'
                    . '<rect x="0" y="8" width="30" height="4" fill="#fecc00"/>'
                    . '<rect x="9" y="0" width="4" height="20" fill="#fecc00"/>';

            case 'NO':
                return '<rect width="30" height="20" fill="#ef2b2d"/>'
                    . '<rect x="0" y="7.5" width="30" height="5" fill="#ffffff"/>'
                    . '<rect x="8" y="0" width="5" height="20" fill="#ffffff"/>'
                    . '<rect x="0" y="8.5" width="30" height="3" fill="#002868"/>'
                    . '<rect x="9" y="0" width="3" height="20" fill="#002868"/>';

            case 'DK':
                return '<rect width="30" height="20" fill="#c8102e"/>'
                    . '<rect x="0" y="8" width="30" height="4" fill="#ffffff"/>'
                    . '<rect x="9" y="0" width="4" height="20" fill="#ffffff"/>';

            case 'FI':
                return '<rect width="30" height="20" fill="#ffffff"/>'
                    . '<rect x="0" y="8" width="30" height="4" fill="#002f6c"/>'
                    . '<rect x="9" y="0" width="4" height="20" fill="#002f6c"/>';

            case 'AU':
                return '<rect width="30" height="20" fill="#012169"/>'
                    . '<path d="M0 0l15 10M15 0L0 10" stroke="#ffffff" stroke-width="2.4"/>'
                    . '<path d="M7.5 0v10M0 5h15" stroke="#ffffff" stroke-width="3.4"/>'
                    . '<path d="M7.5 0v10M0 5h15" stroke="#c8102e" stroke-width="2"/>'
                    . '<circle cx="22" cy="12" r="1.6" fill="#ffffff"/>';

            case 'CN':
                return '<rect width="30" height="20" fill="#de2910"/>'
                    . '<circle cx="6" cy="5" r="2.6" fill="#ffde00"/>'
                    . '<circle cx="11.5" cy="2.5" r="1" fill="#ffde00"/>'
                    . '<circle cx="13.5" cy="5.5" r="1" fill="#ffde00"/>'
                    . '<circle cx="11.5" cy="8.5" r="1" fill="#ffde00"/>';

            case 'GR':
                $out = '<rect width="30" height="20" fill="#ffffff"/>';
                for ($i = 0; $i < 5; $i++) {
                    $out .= sprintf('<rect x="0" y="%.2f" width="30" height="2.22" fill="#0d5eaf"/>',
                        $i * 4.44);
                }
                return $out . '<rect width="11" height="11" fill="#0d5eaf"/>'
                    . '<path d="M4.4 0v11M0 5.5h11" stroke="#ffffff" stroke-width="2.2"/>';
        }

        return null;
    }

    /**
     * Fallback for a country we have no geometry for: the code in a neutral chip.
     */
    private static function chip($code)
    {
        return '<span class="horus-flag-chip" title="' . rcube::Q($code) . '">' . rcube::Q($code) . '</span>';
    }

    /**
     * Best-effort country code from an Accept-Language value ("es-ES,es;q=0.9").
     *
     * Only the explicit region subtag is used. Guessing a country from a bare
     * language ("en" -> US?) would be inventing information.
     */
    public static function country_from_language($language)
    {
        if (!$language) {
            return null;
        }

        $first = trim(strtok((string) $language, ','));

        return preg_match('/^[A-Za-z]{2,3}[-_]([A-Za-z]{2})\b/', $first, $m)
            ? strtoupper($m[1]) : null;
    }
}
