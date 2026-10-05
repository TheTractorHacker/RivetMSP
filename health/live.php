<?php
/* Liveness: the PHP process is serving requests. Touches no dependency on purpose. Always on. */
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['status' => 'ok']);
