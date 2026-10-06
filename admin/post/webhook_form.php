<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// The guided add/edit page (webhook_form.php) posts through admin/post.php, which picks its handler from the page name.
require_once __DIR__ . '/settings_webhooks.php';
