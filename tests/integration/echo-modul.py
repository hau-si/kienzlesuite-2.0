"""Test-Modul für tests/integration/nginx.sh: antwortet auf jede Anfrage mit den empfangenen X-Kienzle-Kopfzeilen."""
import json
import os
import socketserver
import sys
from http.server import BaseHTTPRequestHandler


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        names = sorted({k.lower() for k in self.headers.keys() if k.lower().startswith('x-kienzle-')})
        data = {'pfad': self.path, 'kopfzeilen': {n: self.headers.get_all(n) for n in names}}
        body = json.dumps(data).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def address_string(self):
        return 'unix'

    def log_message(self, *args):
        pass


path = sys.argv[1]
try:
    os.unlink(path)
except FileNotFoundError:
    pass
server = socketserver.UnixStreamServer(path, Handler)
os.chmod(path, 0o666)
server.serve_forever()
