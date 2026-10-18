#!/usr/bin/env python3
"""A local stand-in for GitHub's releases API and release downloads (tests only; never talks to the network).

    python3 mock_github.py <port> <dir>

<dir>/api.json            the body of GET /repos/<owner>/<name>/releases (the test writes it)
<dir>/files/<tag>/<name>  the release assets
<dir>/ctl.json            behaviour switches the test rewrites between requests:
    api:      ok | ratelimit | 500 | garbage | slow
    download: cdn (302 to /cdn/..., same host) | direct | offhost (302 to another host) | loop (redirects forever) | huge (claims a huge Content-Length) | 404
    etag:     string the API answers with (If-None-Match equal to it answers 304)
<dir>/hits.log            one line per request (METHOD PATH), for the test to count
"""
import hashlib, http.server, json, os, sys, urllib.parse

PORT = int(sys.argv[1])
ROOT = sys.argv[2]


def ctl():
    try:
        with open(os.path.join(ROOT, 'ctl.json')) as f:
            return json.load(f)
    except Exception:
        return {}


class H(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *a):
        pass

    def _send(self, code, body=b'', headers=None):
        self.send_response(code)
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        if 'Content-Length' not in (headers or {}):
            self.send_header('Content-Length', str(len(body)))
        self.send_header('Connection', 'close')
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(body)

    def do_GET(self):
        with open(os.path.join(ROOT, 'hits.log'), 'a') as f:
            f.write('GET ' + self.path + '\n')
        c = ctl()
        u = urllib.parse.urlparse(self.path)
        parts = [urllib.parse.unquote(p) for p in u.path.split('/') if p]
        if len(parts) == 4 and parts[0] == 'repos' and parts[3] == 'releases':
            mode = c.get('api', 'ok')
            if mode == 'ratelimit':
                return self._send(403, b'{"message":"API rate limit exceeded"}', {'X-RateLimit-Remaining': '0', 'X-RateLimit-Reset': '1893456000', 'Content-Type': 'application/json'})
            if mode == '500':
                return self._send(500, b'oops')
            if mode == 'garbage':
                return self._send(200, b'<html>not json</html>', {'Content-Type': 'text/html'})
            etag = c.get('etag', '"v1"')
            if self.headers.get('If-None-Match') == etag:
                return self._send(304, b'', {'ETag': etag})
            with open(os.path.join(ROOT, 'api.json'), 'rb') as f:
                body = f.read()
            return self._send(200, body, {'Content-Type': 'application/json', 'ETag': etag})
        if len(parts) == 6 and parts[2] == 'releases' and parts[3] == 'download':
            tag, name = parts[4], parts[5]
            mode = c.get('download', 'cdn')
            if mode == '404':
                return self._send(404, b'not found')
            if mode == 'offhost':
                return self._send(302, b'', {'Location': 'http://127.0.0.2:%d/cdn/x/%s' % (PORT, name)})
            if mode == 'loop':
                return self._send(302, b'', {'Location': self.path})
            if mode == 'huge':
                return self._send(200, b'x' * 10, {'Content-Length': str(5 * 1024 * 1024 * 1024)})
            if mode == 'cdn':
                return self._send(302, b'', {'Location': '/cdn/%s/%s' % (tag, name)})
            return self._serve(tag, name)
        if len(parts) == 3 and parts[0] == 'cdn':
            return self._serve(parts[1], parts[2])
        return self._send(404, b'no such path')

    def _serve(self, tag, name):
        p = os.path.join(ROOT, 'files', tag, name)
        if '..' in tag or '..' in name or not os.path.isfile(p):
            return self._send(404, b'no such file')
        with open(p, 'rb') as f:
            data = f.read()
        return self._send(200, data, {'Content-Type': 'application/octet-stream'})


http.server.ThreadingHTTPServer.allow_reuse_address = True
http.server.ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
