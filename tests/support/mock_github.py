#!/usr/bin/env python3
"""A local stand-in for GitHub's releases API and release downloads (tests only; never talks to the network).

    python3 mock_github.py <port> <dir>

<dir>/api.json            the body of GET /repos/<owner>/<name>/releases (the test writes it)
<dir>/files/<tag>/<name>  the release assets
<dir>/ctl.json            behaviour switches the test rewrites between requests:
    api:      ok | ratelimit | 500 | garbage | slow
    download: cdn (302 to /cdn/..., same host) | direct | offhost (302 to another host) | loop (redirects forever) | huge (claims a huge Content-Length) | 404
    etag:     string the API answers with (If-None-Match equal to it answers 304)
    token:    the exact "Bearer ..." Authorization header the Actions endpoints below require (else 401)
    wf:       ok | 403 | 404 | disabled | redirect   (GET /repos/o/n/actions/workflows/<file>)
    dispatch: ok (204, creates a run in runs.json) | 403 | 404 | 422
<dir>/runs.json           [{"id","status","conclusion","created_at"}] the workflow runs; the dispatch appends one (status "queued"); the test edits it to move a run along
<dir>/dispatch.log        one JSON line per accepted dispatch (the request body)
<dir>/auth.log            one line per request that carried an Authorization header: "METHOD PATH header" (tests prove where a token went and where it did not)
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

    def _log(self, method):
        with open(os.path.join(ROOT, 'hits.log'), 'a') as f:
            f.write(method + ' ' + self.path + '\n')
        if self.headers.get('Authorization'):
            with open(os.path.join(ROOT, 'auth.log'), 'a') as f:
                f.write(method + ' ' + self.path + ' ' + self.headers.get('Authorization') + '\n')

    def _runs(self):
        try:
            with open(os.path.join(ROOT, 'runs.json')) as f:
                return json.load(f)
        except Exception:
            return []

    def _actions(self, method, parts, c, body):
        """/repos/o/n/actions/... (all need the token). Returns True when answered."""
        if not (len(parts) >= 5 and parts[0] == 'repos' and parts[3] == 'actions'):
            return False
        want = c.get('token')
        if want is not None and self.headers.get('Authorization') != want:
            self._send(401, b'{"message":"Bad credentials"}', {'Content-Type': 'application/json'})
            return True
        wf = c.get('wf', 'ok')
        if method == 'GET' and parts[4] == 'workflows' and len(parts) == 6:
            if wf == 'redirect':
                self._send(302, b'', {'Location': 'http://127.0.0.2:%d/steal' % PORT})
            elif wf in ('403', '404'):
                self._send(int(wf), b'{"message":"nope <b>x</b>"}', {'Content-Type': 'application/json'})
            else:
                self._send(200, json.dumps({'id': 1, 'state': 'disabled_manually' if wf == 'disabled' else 'active'}).encode(),
                           {'Content-Type': 'application/json', 'github-authentication-token-expiration': '2027-01-15 10:00:00 UTC'})
            return True
        if method == 'GET' and parts[4] == 'workflows' and len(parts) == 7 and parts[6] == 'runs':
            self._send(200, json.dumps({'total_count': len(self._runs()), 'workflow_runs': sorted(self._runs(), key=lambda r: -r['id'])}).encode(), {'Content-Type': 'application/json'})
            return True
        if method == 'GET' and parts[4] == 'runs' and len(parts) == 6:
            for r in self._runs():
                if str(r['id']) == parts[5]:
                    self._send(200, json.dumps(r).encode(), {'Content-Type': 'application/json'})
                    return True
            self._send(404, b'{"message":"Not Found"}', {'Content-Type': 'application/json'})
            return True
        if method == 'POST' and parts[4] == 'workflows' and len(parts) == 7 and parts[6] == 'dispatches':
            mode = c.get('dispatch', 'ok')
            if mode in ('403', '404', '422'):
                self._send(int(mode), b'{"message":"Workflow does not have workflow_dispatch trigger"}', {'Content-Type': 'application/json'})
                return True
            with open(os.path.join(ROOT, 'dispatch.log'), 'a') as f:
                f.write(body.decode('utf-8', 'replace') + '\n')
            runs = self._runs()
            nid = max([r['id'] for r in runs] + [1000]) + 1
            runs.append({'id': nid, 'status': 'queued', 'conclusion': None, 'created_at': '2026-10-19T12:00:00Z', 'event': 'workflow_dispatch'})
            with open(os.path.join(ROOT, 'runs.json'), 'w') as f:
                json.dump(runs, f)
            self._send(204, b'')
            return True
        self._send(404, b'{"message":"Not Found"}', {'Content-Type': 'application/json'})
        return True

    def do_POST(self):
        self._log('POST')
        n = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(n) if n else b''
        u = urllib.parse.urlparse(self.path)
        parts = [urllib.parse.unquote(p) for p in u.path.split('/') if p]
        if self._actions('POST', parts, ctl(), body):
            return
        self._send(404, b'no such path')

    def do_GET(self):
        self._log('GET')
        c = ctl()
        u = urllib.parse.urlparse(self.path)
        parts = [urllib.parse.unquote(p) for p in u.path.split('/') if p]
        if self._actions('GET', parts, c, b''):
            return
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
