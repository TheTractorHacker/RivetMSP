#!/usr/bin/env python3
"""TLS terminator for the real-agent test: listens on https://127.0.0.1:<port> (self-signed cert for 127.0.0.1) and forwards raw bytes to a plain-http php -S."""
import socket, ssl, sys, threading
listen, target, cert, key = int(sys.argv[1]), int(sys.argv[2]), sys.argv[3], sys.argv[4]
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(cert, key)
srv = socket.socket(); srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); srv.bind(('127.0.0.1', listen)); srv.listen(64)
def pipe(a, b):
    try:
        while True:
            d = a.recv(65536)
            if not d: break
            b.sendall(d)
    except Exception: pass
    finally:
        for s in (a, b):
            try: s.shutdown(socket.SHUT_RDWR)
            except Exception: pass
def handle(c):
    try:
        t = ctx.wrap_socket(c, server_side=True)
        u = socket.create_connection(('127.0.0.1', target))
    except Exception:
        c.close(); return
    threading.Thread(target=pipe, args=(t, u), daemon=True).start()
    pipe(u, t)
while True:
    c, _ = srv.accept()
    threading.Thread(target=handle, args=(c,), daemon=True).start()
