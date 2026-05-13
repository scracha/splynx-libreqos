<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unknown Service Tracker</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .category-unknown { background: #fef3c7; border-left: 4px solid #f59e0b; }
        .category-stopped { background: #fff7ed; border-left: 4px solid #f97316; }
        .category-blocked { background: #fef2f2; border-left: 4px solid #dc2626; }
        .category-unshaped { background: #f0fdf4; border-left: 4px solid #22c55e; }
        .badge-unknown { background: #fbbf24; color: #78350f; }
        .badge-stopped { background: #fb923c; color: #7c2d12; }
        .badge-blocked { background: #f87171; color: #7f1d1d; }
        .badge-unshaped { background: #4ade80; color: #14532d; }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; display: none; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen p-6">
    <div class="max-w-7xl mx-auto">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Unknown Service Tracker</h1>
                <p class="text-gray-500 mt-1">IPs seen by LibreQoS that are unknown, paused, or blocked in Splynx</p>
            </div>
            <button onclick="showIgnoredList()" class="bg-gray-600 text-white px-4 py-2 rounded text-sm font-semibold hover:bg-gray-700">
                View Ignored (<span id="ignoredCount">0</span>)
            </button>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 border-t-4 border-gray-400">
                <div class="text-2xl font-bold text-gray-800" id="totalCount">-</div>
                <div class="text-sm text-gray-500">Shown</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 border-t-4 border-yellow-400">
                <div class="text-2xl font-bold text-yellow-700" id="unknownCount">-</div>
                <div class="text-sm text-gray-500">Unknown to Splynx</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 border-t-4 border-orange-400">
                <div class="text-2xl font-bold text-orange-700" id="stoppedCount">-</div>
                <div class="text-sm text-gray-500">Service Stopped</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 border-t-4 border-red-400">
                <div class="text-2xl font-bold text-red-700" id="blockedCount">-</div>
                <div class="text-sm text-gray-500">Customer Blocked</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 border-t-4 border-green-400">
                <div class="text-2xl font-bold text-green-700" id="unshapedCount">-</div>
                <div class="text-sm text-gray-500">Active but Unshaped</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6 flex flex-wrap items-center gap-4">
            <div>
                <label class="text-xs font-semibold text-gray-500 uppercase">Category</label>
                <select id="filterCategory" onchange="loadData()" class="block mt-1 border rounded px-3 py-1.5 text-sm">
                    <option value="all">All</option>
                    <option value="unknown">Unknown</option>
                    <option value="stopped">Stopped</option>
                    <option value="blocked">Blocked</option>
                    <option value="unshaped">Unshaped</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-gray-500 uppercase">Min Traffic (MB)</label>
                <select id="filterThreshold" onchange="loadData()" class="block mt-1 border rounded px-3 py-1.5 text-sm">
                    <option value="0">Any</option>
                    <option value="10">10 MB</option>
                    <option value="50" selected>50 MB</option>
                    <option value="100">100 MB</option>
                    <option value="500">500 MB</option>
                    <option value="1000">1 GB</option>
                </select>
            </div>
            <button onclick="loadData()" class="mt-5 bg-blue-600 text-white px-4 py-1.5 rounded text-sm font-semibold hover:bg-blue-700">Refresh</button>
            <div class="ml-auto text-xs text-gray-400" id="statusInfo"></div>
        </div>

        <!-- Results Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-100 text-left">
                    <tr>
                        <th class="px-3 py-3 font-semibold text-gray-600">IP Address</th>
                        <th class="px-3 py-3 font-semibold text-gray-600">Category</th>
                        <th class="px-3 py-3 font-semibold text-gray-600">Customer</th>
                        <th class="px-3 py-3 font-semibold text-gray-600">Service</th>
                        <th class="px-3 py-3 font-semibold text-gray-600">Note</th>
                        <th class="px-3 py-3 font-semibold text-gray-600 text-right">Download</th>
                        <th class="px-3 py-3 font-semibold text-gray-600 text-right">Upload</th>
                        <th class="px-3 py-3 font-semibold text-gray-600 text-right">Total</th>
                        <th class="px-3 py-3 font-semibold text-gray-600">First Seen</th>
                        <th class="px-3 py-3 font-semibold text-gray-600">Last Seen</th>
                        <th class="px-3 py-3 font-semibold text-gray-600 text-center">Ignore</th>
                    </tr>
                </thead>
                <tbody id="resultsBody">
                    <tr><td colspan="11" class="px-4 py-8 text-center text-gray-400">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Ignore Dialog -->
    <div class="modal-overlay" id="ignoreModal">
        <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-md">
            <h3 class="text-lg font-bold text-gray-800 mb-2">Ignore IP</h3>
            <p class="text-sm text-gray-500 mb-1">IP: <span class="font-mono font-bold" id="ignoreModalIp"></span></p>
            <label class="text-xs font-semibold text-gray-500 uppercase block mt-3 mb-1">Note (reason for ignoring)</label>
            <textarea id="ignoreModalNote" rows="3" class="w-full border rounded p-2 text-sm" placeholder="e.g. Infrastructure device, router management IP..."></textarea>
            <div class="flex justify-end gap-2 mt-4">
                <button onclick="closeIgnoreModal()" class="px-4 py-2 rounded text-sm border hover:bg-gray-50">Cancel</button>
                <button onclick="confirmIgnore()" class="px-4 py-2 rounded text-sm bg-red-600 text-white font-semibold hover:bg-red-700">Ignore</button>
            </div>
        </div>
    </div>

    <!-- Ignored List Modal -->
    <div class="modal-overlay" id="ignoredListModal">
        <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-4xl max-h-[80vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-800">Ignored IPs</h3>
                <button onclick="closeIgnoredListModal()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-3 py-2 text-left">IP Address</th>
                        <th class="px-3 py-2 text-left">Note</th>
                        <th class="px-3 py-2 text-left">Ignored At</th>
                        <th class="px-3 py-2 text-left">Last Seen</th>
                        <th class="px-3 py-2 text-right">Total Traffic</th>
                        <th class="px-3 py-2 text-center">Remove</th>
                    </tr>
                </thead>
                <tbody id="ignoredListBody">
                </tbody>
            </table>
        </div>
    </div>

    <script>
    const SPLYNX_URL = <?= json_encode(rtrim($splynxAdminUrl, '/')) ?>;
    let ignoreTargetIp = '';
    let ipNotes = {};  // Cache of notes keyed by IP

    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(i > 1 ? 2 : 0) + ' ' + units[i];
    }

    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr + 'Z');
        return d.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    // --- Ignore Modal ---
    function openIgnoreModal(ip) {
        ignoreTargetIp = ip;
        document.getElementById('ignoreModalIp').textContent = ip;
        document.getElementById('ignoreModalNote').value = '';
        document.getElementById('ignoreModal').classList.add('active');
    }

    function closeIgnoreModal() {
        document.getElementById('ignoreModal').classList.remove('active');
        ignoreTargetIp = '';
    }

    async function confirmIgnore() {
        const note = document.getElementById('ignoreModalNote').value.trim();
        if (!ignoreTargetIp) return;

        await fetch('ignore.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'ignore', ip: ignoreTargetIp, note: note })
        });

        closeIgnoreModal();
        loadData();
    }

    // --- Ignored List Modal ---
    async function showIgnoredList() {
        const resp = await fetch('ignore.php?action=list');
        const data = await resp.json();

        const tbody = document.getElementById('ignoredListBody');
        if (!data.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="px-3 py-4 text-center text-gray-400">No ignored IPs</td></tr>';
        } else {
            tbody.innerHTML = data.map(r => `<tr class="border-b">
                <td class="px-3 py-2 font-mono">${esc(r.ip)}</td>
                <td class="px-3 py-2">${esc(r.note || '-')}</td>
                <td class="px-3 py-2 text-xs">${formatDate(r.ignored_at)}</td>
                <td class="px-3 py-2 text-xs">${formatDate(r.last_seen)}</td>
                <td class="px-3 py-2 text-right font-mono">${formatBytes(r.total_bytes)}</td>
                <td class="px-3 py-2 text-center">
                    <button onclick="unignoreIp('${esc(r.ip)}')" class="text-red-600 hover:text-red-800 text-xs font-semibold">Remove</button>
                </td>
            </tr>`).join('');
        }

        document.getElementById('ignoredListModal').classList.add('active');
    }

    function closeIgnoredListModal() {
        document.getElementById('ignoredListModal').classList.remove('active');
    }

    async function unignoreIp(ip) {
        await fetch('ignore.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'unignore', ip: ip })
        });
        showIgnoredList();
        loadData();
    }

    // --- Notes ---
    async function loadNotes() {
        const resp = await fetch('ignore.php?action=all_notes');
        ipNotes = await resp.json();
    }

    function editNote(ip) {
        const current = ipNotes[ip] || '';
        const note = prompt('Note for ' + ip + ':', current);
        if (note === null) return; // cancelled
        saveNote(ip, note);
    }

    async function saveNote(ip, note) {
        await fetch('ignore.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'save_note', ip: ip, note: note })
        });
        if (note === '') {
            delete ipNotes[ip];
        } else {
            ipNotes[ip] = note;
        }
        loadData();
    }

    // --- Main Data Load ---
    async function loadData() {
        const category = document.getElementById('filterCategory').value;
        const threshold = document.getElementById('filterThreshold').value;
        document.getElementById('statusInfo').textContent = 'Loading...';

        try {
            await loadNotes();
            const resp = await fetch(`api.php?category=${category}&threshold=${threshold}`);
            const data = await resp.json();

            if (data.error) {
                document.getElementById('resultsBody').innerHTML =
                    `<tr><td colspan="11" class="px-4 py-8 text-center text-red-500">${esc(data.error)}</td></tr>`;
                document.getElementById('statusInfo').textContent = 'Error';
                return;
            }

            // Update summary
            document.getElementById('totalCount').textContent = data.summary.total_ips;
            document.getElementById('unknownCount').textContent = data.summary.unknown_count;
            document.getElementById('stoppedCount').textContent = data.summary.stopped_count;
            document.getElementById('blockedCount').textContent = data.summary.blocked_count;
            document.getElementById('unshapedCount').textContent = data.summary.unshaped_count;
            document.getElementById('ignoredCount').textContent = data.summary.ignored_count || 0;
            document.getElementById('statusInfo').textContent =
                `${data.summary.lookback_days} day lookback · Threshold: ${data.summary.threshold_mb} MB · Since: ${data.summary.data_since}`;

            // Render table
            const tbody = document.getElementById('resultsBody');
            if (data.results.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" class="px-4 py-8 text-center text-gray-400">No IPs above threshold. Poller may need more time to accumulate data.</td></tr>';
                return;
            }

            tbody.innerHTML = data.results.map(r => {
                const rowClass = `category-${r.category}`;
                const badgeClass = `badge-${r.category}`;
                const categoryLabel = r.category.charAt(0).toUpperCase() + r.category.slice(1);

                let customerCell = '-';
                if (r.customer_name && r.customer_id) {
                    customerCell = `<a href="${SPLYNX_URL}/admin/customers/view?id=${r.customer_id}" target="_blank" class="text-blue-600 hover:underline">${esc(r.customer_name)}</a>`;
                } else if (r.customer_name) {
                    customerCell = esc(r.customer_name);
                }

                let serviceCell = '-';
                if (r.service_description && r.customer_id) {
                    serviceCell = `<a href="${SPLYNX_URL}/admin/customers/view?id=${r.customer_id}&tab=services" target="_blank" class="text-blue-600 hover:underline">${esc(r.service_description)}</a>`;
                } else if (r.service_description) {
                    serviceCell = esc(r.service_description);
                }

                return `<tr class="${rowClass} hover:opacity-90">
                    <td class="px-3 py-3 font-mono">${esc(r.ip)}</td>
                    <td class="px-3 py-3"><span class="px-2 py-0.5 rounded text-xs font-bold ${badgeClass}">${categoryLabel}</span></td>
                    <td class="px-3 py-3">${customerCell}</td>
                    <td class="px-3 py-3">${serviceCell}</td>
                    <td class="px-3 py-3 text-xs cursor-pointer hover:bg-white/50" onclick="editNote('${esc(r.ip)}')" title="Click to edit note">
                        ${ipNotes[r.ip] ? esc(ipNotes[r.ip]) : '<span class=&quot;text-gray-300&quot;>+ note</span>'}
                    </td>
                    <td class="px-3 py-3 text-right font-mono">${formatBytes(r.total_bytes_down)}</td>
                    <td class="px-3 py-3 text-right font-mono">${formatBytes(r.total_bytes_up)}</td>
                    <td class="px-3 py-3 text-right font-mono font-bold">${formatBytes(r.total_bytes)}</td>
                    <td class="px-3 py-3 text-xs">${formatDate(r.first_seen)}</td>
                    <td class="px-3 py-3 text-xs">${formatDate(r.last_seen)}</td>
                    <td class="px-3 py-3 text-center">
                        <button onclick="openIgnoreModal('${esc(r.ip)}')" class="text-gray-400 hover:text-red-600" title="Ignore this IP">
                            <svg class="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"/></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');

        } catch (e) {
            document.getElementById('resultsBody').innerHTML =
                `<tr><td colspan="11" class="px-4 py-8 text-center text-red-500">Error: ${esc(e.message)}</td></tr>`;
            document.getElementById('statusInfo').textContent = 'Error';
        }
    }

    loadData();
    </script>
</body>
</html>
