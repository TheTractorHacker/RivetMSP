<?php
/*
 * Company accent colour (Admin > Settings > Theme), shared by the agent shell (includes/header.php) and every place that needs
 * the same colour outside a normal page load - Email Templates resolve it at send time from cron, the client portal and the
 * email parser, none of which load includes/header.php. Same rule as RivetIT's includes/theme_accent.php (same function names,
 * kept for compatibility across the Rivet editions): a valid custom hex wins, otherwise the preset name's hex.
 */

/** Preset accent name => hex. Mirrors the AdminLTE accent-<name> presets; admin/settings_appearance.php lists the same names. */
function itflow_theme_accent_presets(): array
{
    return [
        'teal' => '#0D9488', 'blue' => '#2563EB', 'indigo' => '#4F46E5', 'purple' => '#7C3AED', 'green' => '#16A34A',
        'red' => '#DC2626', 'orange' => '#EA580C', 'pink' => '#DB2777', 'cyan' => '#0891B2', 'yellow' => '#D97706',
        'lime' => '#65A30D', 'fuchsia' => '#C026D3', 'navy' => '#1E3A8A', 'maroon' => '#9F1239', 'gray' => '#475569',
    ];
}

/** '#RRGGBB' (upper case), or '' when the company has no known accent (an empty/unknown preset name and no valid custom hex). */
function itflow_theme_accent_hex($theme, $custom): string
{
    if (!empty($custom) && preg_match('/^#[0-9A-Fa-f]{6}$/D', (string) $custom) === 1) {
        return strtoupper((string) $custom);
    }

    return itflow_theme_accent_presets()[(string) $theme] ?? '';
}
