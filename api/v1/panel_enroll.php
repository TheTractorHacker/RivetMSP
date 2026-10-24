<?php
// RivetPanel device-token API (GitHub roadmap "Prompt 2"). Dispatched from index.php before any authentication
// or body read - see api/v1/includes/panel_device_api.php and vendor/rivet/rivet-core/docs/panel/PANEL_API.md.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/panel_device_api.php';

panel_dispatch('panel_enroll');
