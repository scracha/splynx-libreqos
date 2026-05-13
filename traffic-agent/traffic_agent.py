#!/usr/bin/env python3
"""
LibreQoS Traffic Agent
Runs on the LibreQoS server. Proxies the lqosd local API and adds
CORS headers so the LAMP server can access it.

Deploy to: /opt/traffic_agent/traffic_agent.py
(Kept outside /opt/libreqos to survive LibreQoS upgrades)

The lqosd web UI (port 9123) exposes local-api endpoints but only
on localhost. This agent proxies them on port 9210 with CORS headers
so the LAMP server can fetch data remotely.
"""

import json
import urllib.request
from http.server import HTTPServer, BaseHTTPRequestHandler
from datetime import datetime

LISTEN_HOST = '0.0.0.0'
LISTEN_PORT = 9210

# lqosd local web UI
LQOSD_BASE = 'http://127.0.0.1:9123'

# Allowed endpoints to proxy (whitelist for security)
ALLOWED_ENDPOINTS = [
    '/local-api/unknownIps',
    '/local-api/unknownIpsCsv',
    '/local-api/allShapedDevices',
    '/local-api/deviceCount',
]


def proxy_lqosd(path: str) -> tuple:
    """Fetch from lqosd local API. Returns (status_code, body_bytes)."""
    url = LQOSD_BASE + path
    try:
        req = urllib.request.Request(url, headers={'Accept': 'application/json'})
        with urllib.request.urlopen(req, timeout=30) as resp:
            return (resp.status, resp.read())
    except urllib.error.HTTPError as e:
        return (e.code, json.dumps({'error': f'lqosd returned {e.code}'}).encode())
    except Exception as e:
        return (502, json.dumps({'error': f'Failed to reach lqosd: {str(e)}'}).encode())


class TrafficHandler(BaseHTTPRequestHandler):
    """Proxies lqosd local-api endpoints with CORS headers."""

    def send_cors_headers(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')

    def do_OPTIONS(self):
        self.send_response(204)
        self.send_cors_headers()
        self.end_headers()

    def do_GET(self):
        path = self.path.split('?')[0]  # Strip query params

        if path == '/health' or path == '/health/':
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.send_cors_headers()
            self.end_headers()
            self.wfile.write(json.dumps({
                'status': 'ok',
                'timestamp': datetime.utcnow().isoformat() + 'Z',
                'lqosd_base': LQOSD_BASE,
            }).encode())
            return

        # Check if the requested path is allowed
        if path not in ALLOWED_ENDPOINTS:
            self.send_response(404)
            self.send_header('Content-Type', 'application/json')
            self.send_cors_headers()
            self.end_headers()
            self.wfile.write(json.dumps({
                'error': 'Not found',
                'available_endpoints': ALLOWED_ENDPOINTS + ['/health'],
            }).encode())
            return

        # Proxy the request to lqosd
        status, body = proxy_lqosd(path)
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_cors_headers()
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format, *args):
        """Suppress default request logging to reduce noise."""
        pass


def main():
    print(f"[traffic_agent] Starting proxy on {LISTEN_HOST}:{LISTEN_PORT}")
    print(f"[traffic_agent] Proxying lqosd at {LQOSD_BASE}")
    print(f"[traffic_agent] Allowed endpoints: {ALLOWED_ENDPOINTS}")

    server = HTTPServer((LISTEN_HOST, LISTEN_PORT), TrafficHandler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n[traffic_agent] Shutting down.")
        server.shutdown()


if __name__ == '__main__':
    main()
