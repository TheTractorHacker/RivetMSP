#!/usr/bin/env python3
"""A stand-in for a MeshCentral server's agent download, for tests/real_agent_proof/run.sh. NOT MeshCentral: it proves RivetMSP's and the agent's side of the protocol
(the download address, the certificate pin, running the downloaded installer, reporting and mapping the node id), not that a real Mesh server speaks it.

  mock_mesh.py <pem (certificate and key)> <port> <group, MeshCentral URL form> <node id raw> <events log>

GET /meshagents?id=N&meshid=<64 characters, URL form>&installflags=F answers a tiny shell script that behaves like a MeshAgent for the commands the installer uses (-install, -nodeid,
-uninstall); a wrong meshid is a 404. Every request is appended to the events log as "MESH-DOWNLOAD id=.. meshid=.. installflags=..". Listens on all interfaces
(the agent runs in a container and reaches the host through the docker gateway). Exits after 15 minutes."""
import http.server, ssl, sys, time, urllib.parse

pem, port, group, node, log = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4], sys.argv[5]
SCRIPT = f"""#!/bin/sh
# FAKE MeshAgent for the real agent proof
case "$1" in
  -nodeid) echo "MeshAgent (fake)"; echo '{node}'; exit 0;;
  -install|-fullinstall)
    mkdir -p /usr/local/mesh_services/meshagent
    cp "$0" /usr/local/mesh_services/meshagent/meshagent
    chmod 755 /usr/local/mesh_services/meshagent/meshagent
    echo "installed by: $0 $*" >> /var/tmp/mesh-installed.marker
    exit 0;;
  -uninstall)
    echo "uninstalled" >> /var/tmp/mesh-uninstalled.marker
    rm -rf /usr/local/mesh_services/meshagent
    exit 0;;
esac
echo "unknown command: $*" >&2
exit 2
"""

class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a):  # quiet
        pass

    def do_GET(self):
        u = urllib.parse.urlparse(self.path)
        q = urllib.parse.parse_qs(u.query)
        if u.path != '/meshagents':
            self.send_error(404)
            return
        with open(log, 'a') as f:
            f.write("MESH-DOWNLOAD id=%s meshid=%s installflags=%s\n" % (q.get('id', [''])[0], q.get('meshid', [''])[0], q.get('installflags', [''])[0]))
        if q.get('meshid', [''])[0] != group:
            self.send_error(404)
            return
        body = SCRIPT.encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/octet-stream')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

srv = http.server.ThreadingHTTPServer(('0.0.0.0', port), H)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(pem)
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.timeout = 1
end = time.time() + 900
while time.time() < end:
    srv.handle_request()
