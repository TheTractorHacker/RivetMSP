<?php

// Set Page Title 

// Get the current page name without the .php extension
$page_title = basename($_SERVER['PHP_SELF'], '.php');

// Lets make the Page title look pretty
// Replace any underscores with spaces
$page_title = str_replace('_', ' ', $page_title);

// Capitize
$page_title = ucwords($page_title);

// Acronyms keep their capitals ("Sla Policies" -> "SLA Policies"), as in RivetIT.
$page_title = preg_replace_callback('/\b(Kb|Rmm|Sla|Csat|Api|It|Ai|Mfa|Mrr|Pin)\b/', static function ($m) { return strtoupper($m[1]); }, $page_title);

// Sanitize title for SQL input such as logging
$page_title_sanitized = sanitizeInput($page_title);

// Sanitize the page title to prevent XSS for output
$page_title = nullable_htmlentities($page_title);

$tab_title = $session_company_name;
