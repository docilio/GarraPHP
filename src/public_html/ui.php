<?php
/**
 * GarraPHP — Browser Test UI
 * ==========================
 * A self-contained test interface. All agent logic runs through index.php;
 * this file is purely a frontend that talks to it via fetch().
 *
 * Access: yoursite.com/ui.php
 * Protected by GARRA_EXEC and whitelisted in .htaccess.
 */
define('GARRA_EXEC', true);
// ── Path to the garra/ engine folder (one level above public_html) ────────
define('GARRA_ROOT', dirname(__DIR__) . '/garra');

$config = require GARRA_ROOT . '/config.php';
require  GARRA_ROOT . '/garra.php';

// Pre-load skill list for the sidebar
$skillNames = [];
try {
    $agent      = new Garra($config);
    $skillNames = $agent->getSkillNames();
} catch (Throwable $e) {
    $skillNames = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GarraPHP — Agent Console</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@300;400;500;600&family=IBM+Plex+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
/* ─── Design direction: industrial terminal — monochrome with acid amber accent ─── */

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:        #0d0d0d;
  --surface:   #141414;
  --border:    #252525;
  --border-hi: #333;
  --text:      #c8c8c8;
  --muted:     #555;
  --accent:    #f5a623;
  --accent-dim:#7a5110;
  --green:     #4caf76;
  --red:       #e05c5c;
  --blue:      #5b9bd5;
  --mono:      'IBM Plex Mono', monospace;
  --sans:      'IBM Plex Sans', sans-serif;
  --radius:    2px;
}

html, body {
  height: 100%;
  background: var(--bg);
  color: var(--text);
  font-family: var(--mono);
  font-size: 13px;
  line-height: 1.6;
  overflow: hidden;
}

/* ── Layout ── */
#shell {
  display: grid;
  grid-template-columns: 220px 1fr;
  grid-template-rows: 42px 1fr 120px;
  height: 100vh;
  gap: 0;
}

/* ── Topbar ── */
#topbar {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  padding: 0 16px;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
  gap: 12px;
}

#topbar .logo {
  font-size: 14px;
  font-weight: 600;
  letter-spacing: 0.12em;
  color: var(--accent);
  text-transform: uppercase;
}

#topbar .logo span { color: var(--muted); font-weight: 300; }

#topbar .pill {
  font-size: 10px;
  padding: 2px 8px;
  border: 1px solid var(--border-hi);
  border-radius: 20px;
  color: var(--muted);
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

#topbar .pill.online { border-color: var(--green); color: var(--green); }

#status-dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: var(--green);
  margin-right: 4px;
  display: inline-block;
  animation: pulse 2s ease infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50%       { opacity: 0.3; }
}

#topbar .spacer { flex: 1; }

#topbar .meta {
  color: var(--muted);
  font-size: 11px;
}

/* ── Sidebar ── */
#sidebar {
  border-right: 1px solid var(--border);
  background: var(--surface);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.sidebar-section {
  padding: 12px 14px 8px;
  border-bottom: 1px solid var(--border);
}

.sidebar-label {
  font-size: 9px;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: 8px;
}

.skill-chip {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 5px 8px;
  margin-bottom: 3px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  cursor: pointer;
  transition: border-color 0.15s, color 0.15s;
  color: var(--text);
  font-family: var(--mono);
  font-size: 11px;
  background: transparent;
  width: 100%;
  text-align: left;
}

.skill-chip:hover {
  border-color: var(--accent-dim);
  color: var(--accent);
}

.skill-chip::before {
  content: '⬡';
  color: var(--accent);
  font-size: 9px;
}

.no-skills {
  color: var(--muted);
  font-size: 11px;
  padding: 4px 0;
  font-style: italic;
}

/* Session controls */
#session-section { padding: 12px 14px; }

#session-id-input {
  width: 100%;
  background: var(--bg);
  border: 1px solid var(--border);
  color: var(--text);
  font-family: var(--mono);
  font-size: 11px;
  padding: 5px 8px;
  border-radius: var(--radius);
  outline: none;
  margin-top: 6px;
}

#session-id-input:focus { border-color: var(--accent-dim); }

#session-id-input::placeholder { color: var(--muted); }

.btn-small {
  margin-top: 6px;
  width: 100%;
  padding: 5px 8px;
  background: transparent;
  border: 1px solid var(--border);
  color: var(--muted);
  font-family: var(--mono);
  font-size: 10px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  cursor: pointer;
  border-radius: var(--radius);
  transition: border-color 0.15s, color 0.15s;
}

.btn-small:hover { border-color: var(--border-hi); color: var(--text); }
.btn-small.danger:hover { border-color: var(--red); color: var(--red); }

.sidebar-footer {
  margin-top: auto;
  padding: 10px 14px;
  border-top: 1px solid var(--border);
  color: var(--muted);
  font-size: 10px;
  line-height: 1.8;
}

/* ── Conversation ── */
#convo {
  overflow-y: auto;
  padding: 20px 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  scroll-behavior: smooth;
}

#convo::-webkit-scrollbar { width: 4px; }
#convo::-webkit-scrollbar-track { background: transparent; }
#convo::-webkit-scrollbar-thumb { background: var(--border-hi); border-radius: 2px; }

/* ── Message bubbles ── */
.msg {
  display: flex;
  flex-direction: column;
  gap: 4px;
  animation: fadein 0.2s ease;
}

@keyframes fadein {
  from { opacity: 0; transform: translateY(6px); }
  to   { opacity: 1; transform: translateY(0); }
}

.msg-label {
  font-size: 9px;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--muted);
  display: flex;
  align-items: center;
  gap: 8px;
}

.msg-label .ts { color: var(--muted); font-weight: 300; }

.msg-body {
  padding: 12px 14px;
  border-radius: var(--radius);
  border: 1px solid var(--border);
  white-space: pre-wrap;
  word-break: break-word;
  line-height: 1.7;
}

.msg.user .msg-label { color: var(--accent); }
.msg.user .msg-body  {
  border-color: var(--accent-dim);
  background: #1a1200;
  color: var(--accent);
}

.msg.assistant .msg-body {
  background: var(--surface);
  color: var(--text);
}

.msg.tool-call .msg-label { color: var(--blue); }
.msg.tool-call .msg-body  {
  background: #0d1520;
  border-color: #1e3050;
  color: var(--blue);
  font-size: 11px;
}

.msg.tool-result .msg-label { color: var(--green); }
.msg.tool-result .msg-body  {
  background: #0d1a12;
  border-color: #1e3c28;
  color: var(--green);
  font-size: 11px;
}

.msg.error .msg-label { color: var(--red); }
.msg.error .msg-body  {
  background: #1a0d0d;
  border-color: #3c1e1e;
  color: var(--red);
}

.msg.system-info .msg-body {
  background: transparent;
  border-color: transparent;
  color: var(--muted);
  font-size: 11px;
  padding: 4px 0;
}

/* Thinking indicator */
.thinking {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--muted);
  font-size: 11px;
  padding: 8px 0;
}

.thinking-dots span {
  display: inline-block;
  width: 4px; height: 4px;
  border-radius: 50%;
  background: var(--accent);
  animation: blink 1.2s ease infinite;
}
.thinking-dots span:nth-child(2) { animation-delay: 0.2s; }
.thinking-dots span:nth-child(3) { animation-delay: 0.4s; }

@keyframes blink {
  0%, 80%, 100% { opacity: 0.2; transform: scale(0.8); }
  40%            { opacity: 1;   transform: scale(1); }
}

/* Detail toggle */
.detail-toggle {
  font-size: 10px;
  color: var(--muted);
  cursor: pointer;
  border: none;
  background: none;
  font-family: var(--mono);
  padding: 0;
  letter-spacing: 0.05em;
}
.detail-toggle:hover { color: var(--text); }

.detail-block {
  margin-top: 6px;
  padding: 10px 12px;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  font-size: 11px;
  color: var(--muted);
  overflow-x: auto;
  display: none;
}

.detail-block.open { display: block; }

/* ── Input bar ── */
#inputbar {
  grid-column: 2 / -1;
  border-top: 1px solid var(--border);
  background: var(--surface);
  display: flex;
  flex-direction: column;
  padding: 12px 16px;
  gap: 8px;
}

#goal-input {
  flex: 1;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  color: var(--text);
  font-family: var(--mono);
  font-size: 13px;
  padding: 10px 14px;
  resize: none;
  outline: none;
  height: 58px;
  transition: border-color 0.15s;
  line-height: 1.5;
}

#goal-input:focus  { border-color: var(--accent-dim); }
#goal-input::placeholder { color: var(--muted); }

.inputbar-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.input-hint {
  font-size: 10px;
  color: var(--muted);
  flex: 1;
}

.input-hint kbd {
  padding: 1px 5px;
  border: 1px solid var(--border-hi);
  border-radius: 2px;
  font-family: var(--mono);
  font-size: 10px;
  color: var(--muted);
}

#send-btn {
  padding: 8px 20px;
  background: var(--accent);
  color: #000;
  border: none;
  border-radius: var(--radius);
  font-family: var(--mono);
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  cursor: pointer;
  transition: opacity 0.15s;
}

#send-btn:hover   { opacity: 0.85; }
#send-btn:disabled { opacity: 0.3; cursor: not-allowed; }

#clear-btn {
  padding: 8px 14px;
  background: transparent;
  border: 1px solid var(--border);
  color: var(--muted);
  font-family: var(--mono);
  font-size: 10px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  cursor: pointer;
  border-radius: var(--radius);
  transition: border-color 0.15s, color 0.15s;
}

#clear-btn:hover { border-color: var(--border-hi); color: var(--text); }

/* Iteration badge */
.iter-badge {
  display: inline-block;
  padding: 1px 6px;
  background: var(--accent-dim);
  color: var(--accent);
  border-radius: 20px;
  font-size: 9px;
  letter-spacing: 0.08em;
  vertical-align: middle;
}

/* Empty state */
#empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  gap: 10px;
  color: var(--muted);
  pointer-events: none;
}

#empty-state .big { font-size: 32px; opacity: 0.15; }
#empty-state .label { font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; }

/* Scrollbar for sidebar */
#sidebar::-webkit-scrollbar { width: 3px; }
#sidebar::-webkit-scrollbar-thumb { background: var(--border-hi); }
</style>
</head>
<body>

<div id="shell">

  <!-- ── Topbar ── -->
  <header id="topbar">
    <div class="logo">Garra<span>PHP</span></div>
    <div class="pill online"><span id="status-dot"></span>Agent Ready</div>
    <div class="pill"><?= htmlspecialchars($config['provider']) ?></div>
    <div class="pill"><?= htmlspecialchars($config['model']) ?></div>
    <div class="spacer"></div>
    <div class="meta">max <?= (int)$config['settings']['max_iterations'] ?> iterations · <?= (int)$config['settings']['timeout'] ?>s timeout</div>
  </header>

  <!-- ── Sidebar ── -->
  <aside id="sidebar">

    <div class="sidebar-section">
      <div class="sidebar-label">Loaded Skills (<?= count($skillNames) ?>)</div>
      <?php if (empty($skillNames)): ?>
        <div class="no-skills">No skills discovered.</div>
      <?php else: ?>
        <?php foreach ($skillNames as $s): ?>
          <button class="skill-chip" onclick="insertSkillPrompt('<?= htmlspecialchars($s, ENT_QUOTES) ?>')"><?= htmlspecialchars($s) ?></button>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div id="session-section" class="sidebar-section">
      <div class="sidebar-label">Session</div>
      <input id="session-id-input" type="text" placeholder="session id (optional)" maxlength="40" spellcheck="false">
      <button class="btn-small" onclick="generateSessionId()">↻ Generate ID</button>
      <button class="btn-small danger" onclick="clearSession()">✕ Clear Session</button>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-label">Loop Trace</div>
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:11px;color:var(--muted);">
        <input type="checkbox" id="trace-toggle" checked style="accent-color:var(--accent);">
        Show tool calls
      </label>
    </div>

    <div class="sidebar-footer">
      <div>entry → index.php</div>
      <div>ui    → ui.php</div>
      <div>skills/ auto-discovered</div>
    </div>

  </aside>

  <!-- ── Conversation pane ── -->
  <main id="convo">
    <div id="empty-state">
      <div class="big">◈</div>
      <div class="label">Send a goal to start the agent</div>
    </div>
  </main>

  <!-- ── Input bar ── -->
  <footer id="inputbar">
    <textarea id="goal-input" placeholder="Enter a goal… e.g. What is the weather in Dubai?" spellcheck="false"></textarea>
    <div class="inputbar-row">
      <div class="input-hint"><kbd>Ctrl</kbd>+<kbd>Enter</kbd> to send</div>
      <button id="clear-btn" onclick="clearConvo()">Clear</button>
      <button id="send-btn" onclick="sendGoal()">▶ Run</button>
    </div>
  </footer>

</div>

<script>
// ─────────────────────────────────────────────────────────────
// State
// ─────────────────────────────────────────────────────────────
let isRunning = false;

// ─────────────────────────────────────────────────────────────
// DOM shortcuts
// ─────────────────────────────────────────────────────────────
const convo      = document.getElementById('convo');
const goalInput  = document.getElementById('goal-input');
const sendBtn    = document.getElementById('send-btn');
const sessionIn  = document.getElementById('session-id-input');
const traceOn    = document.getElementById('trace-toggle');

// ─────────────────────────────────────────────────────────────
// Keyboard shortcut
// ─────────────────────────────────────────────────────────────
goalInput.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
    e.preventDefault();
    sendGoal();
  }
});

// ─────────────────────────────────────────────────────────────
// Send goal → index.php → render loop trace + answer
// ─────────────────────────────────────────────────────────────
async function sendGoal() {
  const goal = goalInput.value.trim();
  if (!goal || isRunning) return;

  hideEmpty();
  setRunning(true);

  appendMsg('user', 'USER', goal);
  goalInput.value = '';

  const thinkEl = appendThinking();

  const payload = { goal };
  const sid = sessionIn.value.trim();
  if (sid) payload.session_id = sid;

  let data;
  try {
    const res = await fetch('index.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    data = await res.json();
  } catch (err) {
    removeEl(thinkEl);
    appendMsg('error', 'NETWORK ERROR', String(err));
    setRunning(false);
    return;
  }

  removeEl(thinkEl);

  // ── Render loop trace from history ──────────────────────────
  if (traceOn.checked && Array.isArray(data.history)) {
    // Skip the first user message (already shown) and the final assistant message
    const trace = data.history.slice(1, -1);
    let iterCount = 0;

    trace.forEach(msg => {
      switch (msg.role) {
        case 'assistant_tool_call':
          iterCount++;
          const callLabel = `TOOL CALL <span class="iter-badge">iter ${iterCount}</span>`;
          const callBody  = formatToolCalls(msg.tool_calls_raw);
          appendMsgHTML('tool-call', callLabel, `<pre>${escHtml(callBody)}</pre>`, true);
          break;

        case 'tool':
          const resLabel = `TOOL RESULT — ${escHtml(msg.name)}`;
          let resBody = msg.content;
          try { resBody = JSON.stringify(JSON.parse(msg.content), null, 2); } catch {}
          appendMsgHTML('tool-result', resLabel, `<pre>${escHtml(resBody)}</pre>`, false);
          break;
      }
    });
  }

  // ── Final answer or error ────────────────────────────────────
  if (data.status === 'success') {
    appendMsg('assistant', 'AGENT', data.response);
    if (data.session_id) {
      appendMsg('system-info', '', `↳ session persisted: ${data.session_id}`);
    }
  } else {
    appendMsg('error', 'AGENT ERROR', data.response);
  }

  setRunning(false);
  scrollBottom();
}

// ─────────────────────────────────────────────────────────────
// Render helpers
// ─────────────────────────────────────────────────────────────
function appendMsg(type, label, text) {
  const el = appendMsgHTML(type, label, escHtml(text), false);
  return el;
}

function appendMsgHTML(type, label, innerHtml, collapsible) {
  const wrap = document.createElement('div');
  wrap.className = `msg ${type}`;

  const ts = new Date().toLocaleTimeString('en-GB', { hour12: false });

  let labelHtml = '';
  if (label) {
    labelHtml = `<div class="msg-label">${label} <span class="ts">${ts}</span></div>`;
  }

  if (collapsible) {
    wrap.innerHTML = `
      ${labelHtml}
      <button class="detail-toggle" onclick="toggleDetail(this)">▸ show details</button>
      <div class="detail-block">${innerHtml}</div>`;
  } else {
    wrap.innerHTML = `${labelHtml}<div class="msg-body">${innerHtml}</div>`;
  }

  convo.appendChild(wrap);
  scrollBottom();
  return wrap;
}

function appendThinking() {
  const el = document.createElement('div');
  el.className = 'thinking';
  el.innerHTML = `
    <div class="thinking-dots">
      <span></span><span></span><span></span>
    </div>
    Agent is thinking…`;
  convo.appendChild(el);
  scrollBottom();
  return el;
}

function toggleDetail(btn) {
  const block = btn.nextElementSibling;
  const open  = block.classList.toggle('open');
  btn.textContent = open ? '▾ hide details' : '▸ show details';
}

function formatToolCalls(raw) {
  if (!raw) return '(no raw data)';
  try { return JSON.stringify(raw, null, 2); }
  catch { return String(raw); }
}

function escHtml(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function removeEl(el) { el && el.remove(); }
function scrollBottom() { convo.scrollTop = convo.scrollHeight; }
function hideEmpty() { document.getElementById('empty-state')?.remove(); }

// ─────────────────────────────────────────────────────────────
// Controls
// ─────────────────────────────────────────────────────────────
function setRunning(state) {
  isRunning = state;
  sendBtn.disabled    = state;
  sendBtn.textContent = state ? '⏳ Running…' : '▶ Run';
  goalInput.disabled  = state;
}

function clearConvo() {
  convo.innerHTML = `<div id="empty-state">
    <div class="big">◈</div>
    <div class="label">Send a goal to start the agent</div>
  </div>`;
}

function generateSessionId() {
  const id = 'sess_' + Math.random().toString(36).slice(2, 10);
  sessionIn.value = id;
}

function clearSession() {
  sessionIn.value = '';
}

function insertSkillPrompt(skillName) {
  const prompts = {
    weather: 'What is the current weather in London?',
  };
  goalInput.value = prompts[skillName] ?? `Use the ${skillName} skill to help me.`;
  goalInput.focus();
}
</script>
</body>
</html>
