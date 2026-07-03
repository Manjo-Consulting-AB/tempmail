<?php
session_start();

if (!isset($_SESSION['pro_user_id']) || !$_SESSION['pro_user_id']) {
    header('Location: pro_login.php');
    exit;
}

$userEmail = $_SESSION['pro_user_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Agent Manager - TempMail Pro</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="/assets/images/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="main-container">
        <div class="header">
            <h1><i class="fas fa-robot"></i> Client Agent Manager</h1>
            <p class="lead">Create, configure and run your mail filter client scripts from one place.</p>
            <?php require 'partials/nav.php'; ?>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-life-ring"></i> Installation guide (easy mode)</h3>
            </div>
            <div class="card-body">
                <p class="mb-3">Follow these steps on your own server before using <strong>Run cycle</strong>. This keeps IMAP details private.</p>
                <ol class="mb-3">
                    <li>Upload <code>agent.php</code> and <code>install.php</code> to your server</li>
                    <li>Run installer in SSH: <code>php install.php --install</code></li>
                    <li>Installer saves private files outside web-root (no env var required on most hosts)</li>
                    <li>Return here and click <strong>Run cycle</strong></li>
                    <li>Connect agent.php to a CRON-task that runs periodically</li>
                </ol>

                <div class="mb-3">
                    <a href="client_agent_download.php" class="btn btn-outline-primary btn-sm me-2">
                        <i class="fas fa-download"></i> Download agent.php
                    </a>
                    <a href="client_agent_install_download.php" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-download"></i> Download install.php
                    </a>
                </div>

                <details>
                    <summary><strong>Show exact commands to copy</strong></summary>
                    <div class="mt-3">
                        <p class="mb-1"><strong>Recommended (one command):</strong></p>
                        <pre class="p-2 bg-light border rounded small mb-3">cd /path/to/agent-folder
php install.php --install</pre>

                        <div class="form-text mb-3">The installer will ask which script ID to configure and will suggest a private directory outside your web root.</div>

                        <p class="mb-1"><strong>Manual fallback (advanced):</strong></p>
                        <pre class="p-2 bg-light border rounded small mb-3"># Run this from your website root (for example public_html)
mkdir -p ../tempmail
openssl rand -hex 32 &gt; ../tempmail/client-agent.key
chmod 600 ../tempmail/client-agent.key

php /path/to/project/src/client/agent/tools/encrypt_local_config.php \
  --key-file=../tempmail/client-agent.key \
  --imap-server='&lt;IMAP_SERVER&gt;' \
  --imap-user='&lt;IMAP_USER&gt;' \
  --imap-password='&lt;IMAP_PASSWORD&gt;'</pre>

                        <p class="mb-1"><strong>Create local config file (outside web-root):</strong></p>
                        <pre class="p-2 bg-light border rounded small mb-3">&lt;?php
return [
    'key_file' =&gt; __DIR__ . '/client-agent.key',
    'imap_server_encrypted' =&gt; '...',
    'imap_user_encrypted' =&gt; '...',
    'imap_password_encrypted' =&gt; '...',
];</pre>

                        <p class="mb-1"><strong>Optional environment variable (advanced):</strong></p>
                        <pre class="p-2 bg-light border rounded small">CLIENT_AGENT_LOCAL_CONFIG_PATH=/home/&lt;YOUR_USER&gt;/tempmail-private/client-agent-&lt;SCRIPT_ID&gt;.local.php</pre>
                    </div>
                </details>

                <div class="alert alert-warning mt-3 mb-0">
                    Never put key file or local config inside public web folders.
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="mb-0"><i class="fas fa-sitemap"></i> Scripts</h3>
                <div class="d-flex gap-2">
                    <input id="newScriptLabel" class="form-control form-control-sm" placeholder="New script name" style="min-width:220px;">
                    <button id="createScriptBtn" class="btn btn-primary btn-sm">Create script</button>
                </div>
            </div>
            <div class="card-body">
                <div id="clientAgentAlert" class="mb-3"></div>
                <div class="row g-4">
                    <div class="col-lg-4">
                        <div id="scriptList" class="list-group"></div>
                    </div>
                    <div class="col-lg-8">
                        <div id="scriptDetails" class="d-none">
                            <div class="border rounded p-3">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h4 id="detailTitle" class="mb-1"></h4>
                                        <div id="detailMeta" class="text-muted small"></div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button id="runCycleBtn" class="btn btn-outline-success btn-sm">
                                            <i class="fas fa-play"></i> Run cycle
                                        </button>
                                        <button id="deleteScriptBtn" class="btn btn-outline-danger btn-sm">
                                            <i class="fas fa-trash"></i> Delete script
                                        </button>
                                    </div>
                                </div>

                                <form id="settingsForm" class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Label</label>
                                        <input id="scriptLabel" class="form-control" />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Target host</label>
                                        <input id="targetHost" class="form-control" placeholder="example.com" />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Target path</label>
                                        <input id="targetPath" class="form-control" placeholder="/client/agent/agent.php" />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Greylist days</label>
                                        <input id="greylistDays" type="number" min="0" max="365" class="form-control" />
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary">Save settings</button>
                                    </div>
                                </form>

                                <div class="card mt-4">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0"><i class="fas fa-chart-line"></i> Live status</h5>
                                        <span id="statusBadge" class="badge bg-secondary">Loading…</span>
                                    </div>
                                    <div class="card-body">
                                        <div id="statusSummary" class="row g-3"></div>
                                    </div>
                                </div>

                                <hr>

                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <h5><i class="fas fa-check-circle"></i> Whitelist</h5>
                                        <div class="input-group mb-2">
                                            <input id="whitelistInput" class="form-control" placeholder="tony@example.com or @example.com" />
                                            <button id="addWhitelistBtn" class="btn btn-outline-primary" type="button">Add</button>
                                        </div>
                                        <ul id="whitelistList" class="list-group"></ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h5><i class="fas fa-ban"></i> Blacklist</h5>
                                        <div class="input-group mb-2">
                                            <input id="blacklistInput" class="form-control" placeholder="spam@example.com or @example.com" />
                                            <button id="addBlacklistBtn" class="btn btn-outline-danger" type="button">Add</button>
                                        </div>
                                        <ul id="blacklistList" class="list-group"></ul>
                                    </div>
                                </div>

                                <hr>

                                <div>
                                    <h5><i class="fas fa-filter"></i> Spam filters</h5>
                                    <div class="row g-2 align-items-end mb-2">
                                        <div class="col-md-4">
                                            <label class="form-label">Pattern</label>
                                            <input id="spamFilterPattern" class="form-control" placeholder="limited time offer" />
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Type</label>
                                            <select id="spamFilterType" class="form-select">
                                                <option value="text">Text</option>
                                                <option value="regex">Regex</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Scope</label>
                                            <select id="spamFilterScope" class="form-select">
                                                <option value="any">Any</option>
                                                <option value="subject">Subject</option>
                                                <option value="body">Body</option>
                                                <option value="from">From</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-check mt-4">
                                                <input id="spamFilterCaseInsensitive" class="form-check-input" type="checkbox" checked>
                                                <label class="form-check-label" for="spamFilterCaseInsensitive">Case-insensitive</label>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <button id="addSpamFilterBtn" class="btn btn-primary w-100" type="button">Add rule</button>
                                        </div>
                                    </div>
                                    <ul id="spamFilterList" class="list-group"></ul>
                                </div>
                            </div>
                        </div>
                        <div id="emptyState" class="text-muted">
                            Select or create a script to begin managing it.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    const apiBase = 'client_agent_api.php';
    const state = { scripts: [], activeScriptId: null };

    function setAlert(type, message) {
        const alert = document.getElementById('clientAgentAlert');
        if (!message) {
            alert.innerHTML = '';
            return;
        }
        const allowedTypes = ['success', 'danger', 'warning', 'info', 'secondary'];
        const tone = allowedTypes.includes(type) ? type : 'secondary';
        const wrapper = document.createElement('div');
        wrapper.className = `alert alert-${tone}`;
        wrapper.textContent = String(message);
        alert.innerHTML = '';
        alert.appendChild(wrapper);
    }

    async function requestJson(path, options = {}) {
        const method = options.method || 'GET';
        const headers = options.headers || {};
        const body = options.body;
        const actualHeaders = { ...headers, Accept: 'application/json' };

        if (body !== undefined && body !== null && !(body instanceof FormData)) {
            actualHeaders['Content-Type'] = 'application/json';
        }

        const response = await fetch(`${apiBase}?route=${encodeURIComponent(path)}`, {
            method,
            headers: actualHeaders,
            body: body instanceof FormData ? body : (body !== undefined ? body : undefined)
        });

        const text = await response.text();
        let data = null;
        try {
            data = text ? JSON.parse(text) : null;
        } catch (e) {
            data = { raw: text };
        }

        return { ok: response.ok, status: response.status, data };
    }

    function renderScripts() {
        const list = document.getElementById('scriptList');
        if (!state.scripts.length) {
            list.innerHTML = '<div class="text-muted small">No scripts yet.</div>';
            return;
        }

        list.innerHTML = state.scripts.map((script) => {
            const active = script.script_id === state.activeScriptId ? 'active' : '';
            return `
                <button type="button" class="list-group-item list-group-item-action ${active}" data-script-id="${script.script_id}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong>${escapeHtml(script.label || 'Untitled script')}</strong>
                            <div class="small text-muted">${escapeHtml(script.script_id)}</div>
                        </div>
                        <span class="badge bg-secondary">${escapeHtml(script.sync_status || 'idle')}</span>
                    </div>
                </button>
            `;
        }).join('');

        list.querySelectorAll('[data-script-id]').forEach((button) => {
            button.addEventListener('click', () => selectScript(button.getAttribute('data-script-id')));
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderStatusSummary(summary) {
        const container = document.getElementById('statusSummary');
        const badge = document.getElementById('statusBadge');
        if (!summary) {
            container.innerHTML = '<div class="col-12 text-muted">No status data available yet.</div>';
            badge.textContent = 'Idle';
            badge.className = 'badge bg-secondary';
            return;
        }

        const status = summary.sync_status || 'idle';
        const tone = status === 'sent' ? 'success' : (status === 'failed' ? 'danger' : 'secondary');
        badge.textContent = status;
        badge.className = `badge bg-${tone}`;

        const lastWebhook = summary.last_webhook_result || null;
        const webhookOk = lastWebhook && lastWebhook.success;
        const webhookTone = webhookOk ? 'success' : 'danger';
        const lastWebhookText = lastWebhook && lastWebhook.http_code != null ? `${lastWebhook.http_code}` : 'Not sent yet';
        const formattedWebhookTime = formatWebhookTime(summary.last_webhook_sent_at, summary.user_timezone || null, true);
        const formattedSettingsTime = formatWebhookTime(summary.updated_at, summary.user_timezone || null, false);

        container.innerHTML = `
            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <div class="small text-uppercase text-muted mb-2">Sync status</div>
                    <div class="fw-semibold">${escapeHtml(summary.sync_status || 'idle')}</div>
                    <div class="small text-muted mt-2">Last settings update: ${escapeHtml(formattedSettingsTime)}</div>
                    <div class="small text-muted mt-2">${escapeHtml(summary.sync_message || 'No status message yet.')}</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <div class="small text-uppercase text-muted mb-2">Last webhook attempt</div>
                    <div class="fw-semibold text-${webhookTone}">${webhookOk ? 'Delivered' : 'Not delivered'}</div>
                    <div class="small text-muted mt-2">Code: ${escapeHtml(lastWebhookText)}</div>
                    <div class="small text-muted">Time: ${escapeHtml(formattedWebhookTime)}</div>
                </div>
            </div>
        `;
    }

    function formatWebhookTime(timestamp, timeZone, assumeUtcNoOffset = false) {
        if (!timestamp) {
            return 'Not sent yet';
        }

        let source = String(timestamp).trim();
        if (assumeUtcNoOffset) {
            const noOffsetSpace = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/;
            const noOffsetT = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?$/;
            if (noOffsetSpace.test(source)) {
                source = source.replace(' ', 'T') + 'Z';
            } else if (noOffsetT.test(source)) {
                source = source + 'Z';
            }
        }

        const date = new Date(source);
        if (Number.isNaN(date.getTime())) {
            return String(timestamp);
        }

        try {
            const formatter = new Intl.DateTimeFormat('sv-SE', {
                timeZone: timeZone || undefined,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false,
            });

            const formatted = formatter.format(date);
            return timeZone ? `${formatted} (${timeZone})` : formatted;
        } catch (e) {
            return String(timestamp);
        }
    }

    async function loadScripts() {
        const result = await requestJson('/api/mailfilter/scripts');
        if (!result.ok) {
            setAlert('danger', 'Could not load scripts.');
            return;
        }
        state.scripts = result.data.scripts || [];
        if (state.activeScriptId && !state.scripts.some((script) => script.script_id === state.activeScriptId)) {
            state.activeScriptId = null;
        }
        if (!state.activeScriptId && state.scripts.length) {
            state.activeScriptId = state.scripts[0].script_id;
        }
        renderScripts();
        if (state.activeScriptId) {
            await selectScript(state.activeScriptId, false);
        } else {
            document.getElementById('scriptDetails').classList.add('d-none');
            document.getElementById('emptyState').classList.remove('d-none');
        }
    }

    async function selectScript(scriptId, refreshList = true) {
        state.activeScriptId = scriptId;
        renderScripts();
        const result = await requestJson(`/api/mailfilter/${scriptId}`);
        if (!result.ok || !result.data || !result.data.script) {
            setAlert('danger', 'Could not load script details.');
            return;
        }
        const script = result.data.script;
        document.getElementById('detailTitle').textContent = script.label || 'Untitled script';
        document.getElementById('detailMeta').textContent = `ID: ${script.script_id} • Sync: ${script.sync_status || 'idle'}`;
        document.getElementById('scriptLabel').value = script.label || '';
        document.getElementById('targetHost').value = script.target_host || '';
        document.getElementById('targetPath').value = script.target_path || '/client/agent/agent.php';
        document.getElementById('greylistDays').value = script.greylist_days || 30;
        renderList('whitelist', script.whitelist || []);
        renderList('blacklist', script.blacklist || []);
        renderSpamFilters(script.spam_filters || []);

        const statusResult = await requestJson(`/api/mailfilter/${scriptId}/status`);
        if (statusResult.ok && statusResult.data && statusResult.data.script) {
            renderStatusSummary(statusResult.data.script);
        } else {
            renderStatusSummary(null);
        }

        document.getElementById('scriptDetails').classList.remove('d-none');
        document.getElementById('emptyState').classList.add('d-none');
        if (refreshList) {
            await loadScripts();
        }
    }

    function renderList(type, items) {
        const list = document.getElementById(`${type}List`);
        if (!items.length) {
            list.innerHTML = '<li class="list-group-item text-muted small">None yet.</li>';
            return;
        }
        list.innerHTML = items.map((item) => `
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>${escapeHtml(item)}</span>
                <button type="button" class="btn btn-sm btn-outline-secondary remove-item-btn" data-type="${type}" data-item="${escapeHtml(item)}">Remove</button>
            </li>
        `).join('');

        list.querySelectorAll('.remove-item-btn').forEach((button) => {
            button.addEventListener('click', async () => {
                const typeName = button.getAttribute('data-type');
                const item = button.getAttribute('data-item');
                await updateListItem(typeName, item, 'remove');
            });
        });
    }

    function renderSpamFilters(items) {
        const list = document.getElementById('spamFilterList');
        if (!items.length) {
            list.innerHTML = '<li class="list-group-item text-muted small">None yet.</li>';
            return;
        }

        list.innerHTML = items.map((rule) => `
            <li class="list-group-item d-flex justify-content-between align-items-center gap-3">
                <div>
                    <div class="fw-semibold">${escapeHtml((rule.type || 'text') + ' • ' + (rule.scope || 'any'))}</div>
                    <div class="small text-muted">${escapeHtml(rule.pattern || '')}${rule.case_insensitive ? ' • case-insensitive' : ''}</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary remove-spam-filter-btn" data-rule-id="${escapeHtml(rule.rule_id || '')}">Remove</button>
            </li>
        `).join('');

        list.querySelectorAll('.remove-spam-filter-btn').forEach((button) => {
            button.addEventListener('click', async () => {
                const ruleId = button.getAttribute('data-rule-id');
                await updateSpamFilter(ruleId, 'remove');
            });
        });
    }

    document.getElementById('settingsForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!state.activeScriptId) {
            return;
        }
        const payload = {
            label: document.getElementById('scriptLabel').value,
            target_host: document.getElementById('targetHost').value,
            target_path: document.getElementById('targetPath').value,
            greylist_days: document.getElementById('greylistDays').value
        };
        const result = await requestJson(`/api/mailfilter/${state.activeScriptId}/settings`, { method: 'PATCH', body: JSON.stringify(payload) });
        if (result.ok) {
            setAlert('success', 'Settings saved.');
            await selectScript(state.activeScriptId, true);
        } else {
            setAlert('danger', result.data?.message || 'Failed to save settings.');
        }
    });

    document.getElementById('createScriptBtn').addEventListener('click', async () => {
        const label = document.getElementById('newScriptLabel').value.trim();
        const result = await requestJson('/api/mailfilter/create', { method: 'POST', body: JSON.stringify({ label }) });
        if (result.ok && result.data && result.data.script) {
            document.getElementById('newScriptLabel').value = '';
            setAlert('success', 'Script created.');
            state.activeScriptId = result.data.script.script_id;
            await loadScripts();
        } else {
            setAlert('danger', result.data?.message || 'Could not create script.');
        }
    });

    async function updateListItem(type, item, action) {
        if (!state.activeScriptId) {
            return;
        }
        const endpoint = `/api/mailfilter/${state.activeScriptId}/${type}`;
        const result = await requestJson(endpoint, {
            method: action === 'remove' ? 'DELETE' : 'POST',
            body: JSON.stringify({ pattern: item })
        });
        if (result.ok) {
            setAlert('success', `${type} updated.`);
            await selectScript(state.activeScriptId, true);
        } else {
            setAlert('danger', result.data?.message || 'Could not update list.');
        }
    }

    async function updateSpamFilter(rule, action) {
        if (!state.activeScriptId) {
            return;
        }
        const endpoint = `/api/mailfilter/${state.activeScriptId}/spam-filters`;
        const payload = action === 'remove' ? { rule_id: rule } : rule;
        const result = await requestJson(endpoint, {
            method: action === 'remove' ? 'DELETE' : 'POST',
            body: JSON.stringify(payload)
        });
        if (result.ok) {
            setAlert('success', 'Spam filters updated.');
            await selectScript(state.activeScriptId, true);
        } else {
            setAlert('danger', result.data?.message || 'Could not update spam filters.');
        }
    }

    function bindClick(id, handler) {
        const el = document.getElementById(id);
        if (!el) {
            return;
        }
        el.addEventListener('click', handler);
    }

    bindClick('addWhitelistBtn', async () => {
        const value = document.getElementById('whitelistInput').value.trim();
        if (!value) return;
        await updateListItem('whitelist', value, 'add');
        document.getElementById('whitelistInput').value = '';
    });

    bindClick('addBlacklistBtn', async () => {
        const value = document.getElementById('blacklistInput').value.trim();
        if (!value) return;
        await updateListItem('blacklist', value, 'add');
        document.getElementById('blacklistInput').value = '';
    });

    bindClick('addSpamFilterBtn', async () => {
        const pattern = document.getElementById('spamFilterPattern').value.trim();
        if (!pattern) return;
        await updateSpamFilter({
            type: document.getElementById('spamFilterType').value,
            scope: document.getElementById('spamFilterScope').value,
            pattern,
            case_insensitive: document.getElementById('spamFilterCaseInsensitive').checked
        }, 'add');
        document.getElementById('spamFilterPattern').value = '';
    });

    bindClick('runCycleBtn', async () => {
        if (!state.activeScriptId) {
            return;
        }
        const result = await requestJson(`/api/mailfilter/${state.activeScriptId}/run-cycle`, { method: 'POST' });
        if (result.ok) {
            setAlert('success', 'Run cycle completed.');
            await selectScript(state.activeScriptId, true);
        } else {
            setAlert('danger', result.data?.message || 'Run cycle failed.');
        }
    });

    bindClick('deleteScriptBtn', async () => {
        if (!state.activeScriptId) {
            return;
        }

        const scriptId = state.activeScriptId;
        const confirmed = window.confirm(`Delete script ${scriptId}? This cannot be undone.`);
        if (!confirmed) {
            return;
        }

        const result = await requestJson(`/api/mailfilter/${scriptId}`, { method: 'DELETE' });
        if (!result.ok) {
            setAlert('danger', result.data?.message || 'Could not delete script.');
            return;
        }

        setAlert('success', `Script ${scriptId} deleted.`);
        state.activeScriptId = null;
        await loadScripts();
    });

    document.addEventListener('DOMContentLoaded', loadScripts);
    </script>
</body>
</html>
