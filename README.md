# Splynx LibreQoS Unknown Service Tracker

Identifies IP addresses within your network that are using data but are either unknown to Splynx, belong to blocked/inactive customers, or have stopped/paused services. Combines LibreQoS traffic visibility with Splynx billing data to find "freetards" — IPs consuming bandwidth without an active service.

## How it works

```
LibreQoS (lqosd)                    LAMP Server
┌─────────────────┐                ┌──────────────────────────┐
│ /local-api/     │                │                          │
│  unknownIps     │◄──── proxy ────│  traffic-agent (port     │
│ (port 9123,     │                │  9210, remote access)    │
│  localhost only)│                │                          │
└─────────────────┘                └──────────┬───────────────┘
                                              │
                                   poll_traffic.php (cron, */15)
                                              │
                                              ▼
                                   ┌──────────────────────────┐
                                   │  SQLite (delta tracking)  │
                                   │  + Splynx data store      │
                                   └──────────┬───────────────┘
                                              │
                                              ▼
                                   ┌──────────────────────────┐
                                   │  Web UI (index.php)       │
                                   │  - Category badges        │
                                   │  - Ignore list + notes    │
                                   │  - Splynx hyperlinks      │
                                   └──────────────────────────┘
```

### Delta-based traffic measurement

Rather than relying on LibreQoS's all-time `total_bytes` counter (which resets on lqosd restart and doesn't represent a fixed time window), the poller:

1. Snapshots each IP's `total_bytes` from LibreQoS every 15 minutes
2. Calculates the **delta** (bytes used since last poll)
3. Detects lqosd restarts (when totals drop) and handles gracefully
4. Stores deltas in SQLite with timestamps
5. Aggregates over a configurable lookback period (default 31 days)

### Categories

| Category | Meaning |
|----------|---------|
| **Unknown** | IP not in Splynx at all — truly unidentified |
| **Blocked** | Customer exists but is blocked/inactive/disabled |
| **Stopped** | Service exists but is stopped/paused/disabled |
| **Unshaped** | Active in Splynx but not in LibreQoS shaping config |

### Noise filtering

Background traffic (ARP, DHCP, broadcast) is filtered out. Only IPs with traffic above ~1.1MB per 15-minute poll interval (equivalent to sustained 10Kbps) are recorded.

## Components

### Traffic Agent (runs on LibreQoS server)

A lightweight Python HTTP proxy (`traffic-agent/traffic_agent.py`) that:
- Proxies `lqosd`'s local-only API (port 9123) to a remotely-accessible port (9210)
- Adds CORS headers
- Whitelists specific endpoints
- Zero dependencies (Python stdlib only)

### Poller (runs on LAMP server via cron)

`poll_traffic.php` — fetches unknown IPs from the traffic agent, calculates deltas, stores in SQLite.

### Web UI

`index.php` — displays aggregated traffic data with:
- Category filtering and minimum traffic threshold
- Ignore list with notes (for known infrastructure IPs)
- Splynx customer/service hyperlinks
- Per-IP notes

### API

`api.php` — JSON endpoint for programmatic access to the aggregated data.

## Requirements

### LibreQoS server
- Python 3.x (standard library only)
- LibreQoS with `lqosd` running (web UI on port 9123)
- Network access from LAMP server to port 9210

### LAMP server
- PHP 7.4+ with SQLite3
- Splynx service data store at `/dev/shm/splynx_active_services.json` (from splynx-service exporter)
- Cron access (www-data user)

## Setup

### 1. Deploy traffic agent to LibreQoS server

```bash
scp -r traffic-agent/ user@your-libreqos-server:~/
ssh user@your-libreqos-server
sudo mv ~/traffic-agent /opt/traffic_agent
sudo cp /opt/traffic_agent/traffic-agent.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable traffic-agent
sudo systemctl start traffic-agent
```

Verify: `curl http://your-libreqos-server:9210/health`

### 2. Configure LAMP server

```bash
cp config.php.example config.php
# Edit config.php with your LibreQoS server IP and Splynx URL
```

### 3. Set up cron

```bash
sudo crontab -u www-data -e
```

Add:
```
*/15 * * * * /usr/bin/php /var/www/html/splynx-libreqos/poll_traffic.php >> /var/log/libreqos-poller.log 2>&1
```

Ensure www-data can write to the directory:
```bash
sudo chown www-data:www-data /var/www/html/splynx-libreqos/
```

### 4. First run

The first poll creates a snapshot only (0 deltas). The second poll (15 min later) starts recording actual traffic. Data accumulates over time — the 50MB default display threshold means you'll see results after a few hours/days depending on traffic volume.

## Files

| File | Purpose |
|------|---------|
| `index.php` | Web UI |
| `api.php` | JSON API for aggregated traffic data |
| `poll_traffic.php` | Cron poller (delta-based) |
| `ignore.php` | Ignore list and notes management API |
| `config.php` | Configuration (git-ignored) |
| `config.php.example` | Template for config.php |
| `traffic-agent/` | Python proxy for LibreQoS server |
| `traffic-agent/traffic_agent.py` | The proxy script |
| `traffic-agent/traffic-agent.service` | systemd unit file |
| `traffic-agent/README.md` | Agent deployment docs |

## Related Projects

- [splynx-service-tools](https://github.com/scracha/splynx-service-tools) — The Splynx exporter that generates the data store this tool reads from
- [LibreQoS](https://github.com/LibreQoE/LibreQoS) — The traffic shaping platform providing per-IP visibility

## License

GNU General Public License v3.0 — see [LICENSE.TXT](LICENSE.TXT)
