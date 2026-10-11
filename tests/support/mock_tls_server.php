<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

/*
 * Minimal HTTPS server for tests (run by MockTlsServer as a child process): accepts connections on 127.0.0.1:<port> with the PEM given (certificate and key),
 * answers every request with 200 "OK" and closes. A stand-in for a MeshCentral server's TLS front end, never a real one. Router-less script: no namespace. Copied from RivetCore (tests/Support/mock_tls_server.php, v1.0.0-rc.13).
 */
$pem = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$ctx = stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false, 'allow_self_signed' => true]]);
$srv = stream_socket_server("ssl://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($srv === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
$deadline = time() + 120;
while (time() < $deadline) {
    $c = @stream_socket_accept($srv, 2);
    if ($c === false) {
        continue;
    }
    stream_set_timeout($c, 2);
    @fread($c, 4096);
    @fwrite($c, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK");
    @fclose($c);
}
