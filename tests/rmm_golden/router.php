<?php
// Router for `php -S` in tests/endpoint_agent_golden.php, tests/endpoint_agent_module.php and tests/endpoint_agent_smoke.php: sends /api/v1/*
// through the same front controller nginx uses.
$rmm_test_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($rmm_test_uri, '/api/v1/') === 0) {
    require __DIR__ . '/../../api/v1/index.php';
    return true;
}
return false;
