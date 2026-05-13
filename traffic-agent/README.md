# LibreQoS Traffic Agent

A lightweight Python HTTP proxy that runs on the LibreQoS server.
It proxies the `lqosd` local API (which only listens on localhost:9123) and adds
CORS headers so the LAMP server can access it remotely on port 9210.

## Architecture

- **LibreQoS server** — runs this agent on port 9210, proxies lqosd
- **LAMP server** (this codebase) — fetches `/local-api/unknownIps` via the agent,
  cross-references against the local Splynx data store, serves the frontend
- **Splynx** — separate; the LAMP server has its own local copy of service data
  via the splynx_exporter_cli.php cron

## Installation

Copy this folder to the LibreQoS server:

```bash
scp -r traffic-agent/ user@your-libreqos-server:~/
ssh user@your-libreqos-server
sudo mv ~/traffic-agent /opt/traffic_agent
```

## Run as a systemd service

```bash
sudo cp /opt/traffic_agent/traffic-agent.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable traffic-agent
sudo systemctl start traffic-agent
```

## Endpoints

```
GET http://your-libreqos-server:9210/health
GET http://your-libreqos-server:9210/local-api/unknownIps
GET http://your-libreqos-server:9210/local-api/allShapedDevices
GET http://your-libreqos-server:9210/local-api/deviceCount
```

## How it works

1. `lqosd` runs its web UI on localhost:9123 (not accessible remotely)
2. This agent listens on 0.0.0.0:9210 and proxies whitelisted endpoints
3. Adds CORS headers so the LAMP server's PHP can fetch data
4. No cron needed — the LAMP server queries in real-time when the poller runs
5. LibreQoS tracks `total_bytes` per unknown IP since lqosd started

## No dependencies

Uses only Python standard library (http.server, urllib). No pip packages needed.
