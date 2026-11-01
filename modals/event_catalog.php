<?php

// The event catalog for the shared event picker (includes/event_picker.php, js/event_picker.js): RivetCore's EventCatalog, unchanged.
// Fetched once per page by the picker; any signed-in user may read it (static, public metadata). Events this server has recorded but
// the catalog does not list are not here: the picker form passes them as data-other.

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/modal_header.php';

header('Content-Type: application/json');
header('Cache-Control: private, max-age=86400');
echo \RivetCore\Webhooks\EventCatalog::toJson();
exit;
