/*
  app.bundle.js
  单文件版 — 已把所有模块合并：
    - ui helpers
    - api helpers
    - probe (tz)
    - terminal (xterm + ws)
    - mods
    - players
    - files
    - items
    - version
    - app (入口绑定)

  说明：
  - 依赖：bootstrap.bundle.js, xterm.js, xterm-addon-fit.js, jquery（页面原本已经引入）
  - 保持与原来 API 行为一致（使用 api.php / tz.php 等）
  - 为兼容旧 index.html，保留部分 window 全局函数（controlServer, saveGame, loadMods...）
*/

/* ===========================
   UI Helpers (DOM / Toast / util)
   =========================== */
(function(){

// --- DOM helper
function $id(id) { return document.getElementById(id); }

// --- escape html
function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// --- Toast container
function getToastContainer() {
    let c = document.getElementById('toast-container');
    if (!c) {
        c = document.createElement('div');
        c.id = 'toast-container';
        c.style.position = 'fixed';
        c.style.top = '20px';
        c.style.right = '20px';
        c.style.zIndex = 9999;
        document.body.appendChild(c);
    }
    return c;
}

function showToast(msg, type='info', timeout=3000) {
    const container = getToastContainer();
    const t = document.createElement('div');
    const cls = type === 'error' ? 'bg-danger' : type === 'success' ? 'bg-success' : 'bg-primary';
    t.className = `toast align-items-center text-white ${cls} border-0 show mb-2`;
    t.innerHTML = `<div class="d-flex"><div class="toast-body">${escapeHtml(msg)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    container.appendChild(t);
    setTimeout(() => t.remove(), timeout);
    return t;
}

function smoothWidth(el, pct) {
    if (!el) return;
    let v = Number(pct);
    if (isNaN(v)) v = 0;
    v = Math.max(0, Math.min(100, v));
    el.style.width = `${v}%`;
}

function fmtBytes(b) {
    if (!b && b !== 0) return '--';
    if (b === 0) return '0 B';
    const k = 1024;
    const sizes = ['B','KB','MB','GB','TB'];
    const i = Math.floor(Math.log(b)/Math.log(k));
    return parseFloat((b/Math.pow(k,i)).toFixed(1)) + ' ' + (sizes[i] || 'B');
}

// --- expose to global
window.__ui = { $id, escapeHtml, showToast, smoothWidth, fmtBytes };
window.$id = $id;   // <<< 新增这一句，修复 $id 未定义问题

})();



/* ===========================
   API Helpers
   =========================== */
(function(){

async function apiCall(action, form = null, qs = '') {
    const fd = (form instanceof FormData) ? form : new FormData();
    if (!fd.has('action')) fd.append('action', action);
    try {
        const res = await fetch('api.php' + (qs || ''), {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.error) {
            // surface error to caller
            throw new Error(json.error);
        }
        return json;
    } catch (err) {
        throw err;
    }
}

async function fetchJson(url) {
    const r = await fetch(url, {cache: 'no-cache'});
    if (!r.ok) throw new Error(`${r.status} ${r.statusText}`);
    return await r.json();
}

window.__api = {
    apiCall, fetchJson
};

})(); // end api helpers


/* ===========================
   Probe Module (tz.php polling)
   =========================== */
(function(){
const $ = window.__ui.$id;
const smoothWidth = window.__ui.smoothWidth;
const fmtBytes = window.__ui.fmtBytes;
const fetchJson = window.__api.fetchJson;

let probeTimer = null;
const POLL_INTERVAL = 2000;

function initProbe() {
    startProbe();
    updateProbe().catch(()=>{});
}

function startProbe() {
    if (probeTimer) clearInterval(probeTimer);
    probeTimer = setInterval(()=>updateProbe().catch(()=>{}), POLL_INTERVAL);
}

function stopProbe() {
    if (probeTimer) clearInterval(probeTimer);
    probeTimer = null;
}

async function updateProbe() {
    try {
        const d = await fetchJson('tz.php');
        // CPU
        const cpuPct = d.cpu_usage ?? 0;
        smoothWidth($('#yh-cpu-bar'), cpuPct);
        $('#yh-cpu-txt').innerText = cpuPct + '%';
        $('#yh-cpu-model').innerText = `(${d.cpu_name || ''})`;
        // Mem
        const m = d.mem || {};
        const total = m.total || 1;
        smoothWidth($('#yh-mem-real'), (m.real_used||0)/total*100);
        smoothWidth($('#yh-mem-buf'), (m.buffers||0)/total*100);
        smoothWidth($('#yh-mem-cache'), (m.cached||0)/total*100);
        $('#yh-mem-txt').innerText = `${Math.round((m.real_used||0)/1024/1024)} MB`;
        // Load, disk, net
        $('#yh-load').innerText = (d.load || []).join(' ');
        smoothWidth($('#yh-disk-bar'), d.disk ? d.disk.percent : 0);
        $('#yh-disk-txt').innerText = d.disk ? `${d.disk.percent}%` : '--';
        $('#yh-net-rx').innerText = fmtBytes(d.net?.rx || 0);
        $('#yh-net-tx').innerText = fmtBytes(d.net?.tx || 0);
        $('#yh-os').innerText = d.os || 'Linux';
        $('#sys-uptime').innerText = `运行: ${fmtUptime(d.uptime)}`;
        // app status
        updateAppStatus(Boolean(d.app_running));
    } catch (err) {
        // silently ignore frequent probe errors
        // console.warn('probe error', err);
    }
}

function fmtUptime(s=0) {
    const d = Math.floor(s/86400), h = Math.floor(s%86400/3600), m = Math.floor(s%3600/60);
    return `${d}d ${h}h ${m}m`;
}

function updateAppStatus(running) {
    const b = $('#server-status-badge');
    if (!b) return;
    if (running) {
        b.className = 'badge bg-success';
        b.innerText = '运行中';
        $('#stop-area')?.classList.remove('d-none');
        $('#startForm')?.classList.add('d-none');
    } else {
        b.className = 'badge bg-secondary';
        b.innerText = '已停止';
        $('#stop-area')?.classList.add('d-none');
        $('#startForm')?.classList.remove('d-none');
    }
}

window.__probe = {
    initProbe, startProbe, stopProbe, updateProbe
};

})(); // end probe


/* ===========================
   Terminal Module (xterm + websocket)
   =========================== */
(function(){
const $ = window.__ui.$id;
const showToast = window.__ui.showToast;

let term = null;
let fitAddon = null;
let ws = null;
let reconnectTimer = null;
const RECONNECT_DELAY = 3000;

function initTerminal() {
    // create terminal
    try {
        term = new Terminal({
            fontSize: 13,
            fontFamily: 'Consolas,Monaco,monospace',
            theme: { background: '#1e1e1e' },
            cursorBlink: true,
            disableStdin: true,
            rows: 15,
            convertEol: true
        });
        // FitAddon class is provided by xterm-addon-fit.js global FitAddon
        fitAddon = new FitAddon.FitAddon();
        term.loadAddon(fitAddon);
        const termDiv = document.getElementById('terminal');
        term.open(termDiv);
        window.addEventListener('resize', ()=> { try { fitAddon.fit(); } catch(e){} });
        setTimeout(()=>{ try { fitAddon.fit(); } catch(e){} }, 300);
    } catch (err) {
        console.warn('xterm init fail', err);
    }
    connectWs();
    // bind clear button
    const clearBtn = document.querySelector('.term-header button');
    if (clearBtn) clearBtn.addEventListener('click', ()=>term.clear());
}

function connectWs() {
    if (ws) return;
    const proto = (location.protocol === 'https:' ? 'wss:' : 'ws:');
    const url = proto + '//' + location.host + '/ws';
    try {
        ws = new WebSocket(url);
    } catch (err) {
        scheduleReconnect();
        return;
    }
    ws.onopen = () => {
        $id('ws-badge').innerText = '已连接';
        $id('ws-badge').className = 'badge bg-success me-3';
        try { fitAddon.fit(); } catch(e){}
    };
    ws.onmessage = (e) => {
        try { term.write(e.data); } catch(e){}
    };
    ws.onclose = () => {
        ws = null;
        $id('ws-badge').innerText = '断开连接';
        $id('ws-badge').className = 'badge bg-danger me-3';
        scheduleReconnect();
    };
    ws.onerror = () => {
        try { ws.close(); } catch(e){}
    };
}

function scheduleReconnect() {
    if (reconnectTimer) return;
    reconnectTimer = setTimeout(()=> {
        reconnectTimer = null;
        connectWs();
    }, RECONNECT_DELAY);
}

function sendConsoleCmd(cmd) {
    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(cmd);
    } else {
        showToast('控制台尚未连接', 'error');
    }
}

window.__terminal = {
    initTerminal, sendConsoleCmd
};

})(); // end terminal


/* ===========================
   Mods Module
   =========================== */
(function(){
const $ = window.__ui.$id;
const showToast = window.__ui.showToast;
const escapeHtml = window.__ui.escapeHtml;
const apiCall = window.__api.apiCall;

async function loadMods() {
    const el = $ && $('mod-list');
    if (!el) return;
    el.innerHTML = `<div class="text-center p-3 text-muted">加载中...</div>`;
    try {
        const res = await fetch('api.php?action=mod_list');
        const d = await res.json();
        el.innerHTML = '';
        if (!d.mods || !d.mods.length) {
            el.innerHTML = '<div class="text-center p-3 text-muted">暂无模组</div>';
            return;
        }
        const frag = document.createDocumentFragment();
        d.mods.forEach(m => {
            const wrapper = document.createElement('div');
            wrapper.className = 'mod-item';
            const isDlc = m.filename === '[官方 DLC]';
            const delBtnHtml = isDlc ? '' : `<button class="btn btn-sm btn-link text-danger p-0 ms-3" data-action="delete-mod" data-filename="${escapeHtml(m.filename)}"><i class="bi bi-trash"></i></button>`;
            const checked = m.enabled ? 'checked' : '';
            wrapper.innerHTML = `
                <div class="text-truncate" style="flex:1">
                    <div class="mod-name ${m.enabled ? '' : 'text-muted'}">${escapeHtml(m.name)}</div>
                    <div class="mod-file small">${escapeHtml(m.filename)}</div>
                </div>
                <div class="d-flex align-items-center">
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input mod-toggle" type="checkbox" ${checked} data-name="${escapeHtml(m.name)}">
                    </div>
                    ${delBtnHtml}
                </div>`;
            frag.appendChild(wrapper);
        });
        el.appendChild(frag);
        el.querySelectorAll('.mod-toggle').forEach(i => i.addEventListener('change', (ev)=>{
            toggleMod(ev.target.dataset.name, ev.target.checked).catch(()=>{});
        }));
    } catch (err) {
        el.innerHTML = '<div class="text-center p-3 text-danger">加载失败</div>';
    }
}

async function toggleMod(name, enabled) {
    try {
        const fd = new FormData();
        fd.append('name', name);
        fd.append('enabled', enabled ? '1' : '0');
        await apiCall('mod_toggle', fd);
        showToast(`已${enabled ? '启用' : '禁用'} ${name}`, 'success');
    } catch (e) {
        showToast(e.message || '操作失败', 'error');
        throw e;
    }
}

async function uploadMod(inputEl) {
    const inp = inputEl || document.getElementById('mod-upload-input');
    if (!inp || !inp.files || inp.files.length === 0) return showToast('请选择文件', 'error');
    const fd = new FormData();
    for (const f of inp.files) fd.append('file[]', f);
    fd.append('action','mod_upload');
    try {
        inp.disabled = true;
        await apiCall('mod_upload', fd);
        showToast('上传完成', 'success');
        inp.value = '';
        await loadMods();
    } catch (e) {
        showToast('上传失败', 'error');
    } finally {
        inp.disabled = false;
    }
}

async function delMod(filename) {
    if (!confirm(`删除 ${filename}?`)) return;
    try {
        const fd = new FormData();
        fd.append('filename', filename);
        await apiCall('mod_delete', fd);
        showToast('已删除', 'success');
        await loadMods();
    } catch (e) {
        showToast('删除失败', 'error');
    }
}

async function installModFromPortal(name, username, token) {
    if (!username || !token) return showToast('请填写用户名和 Token', 'error');
    if (!confirm(`下载安装 ${name}?`)) return;
    try {
        const fd = new FormData();
        fd.append('name', name);
        fd.append('username', username);
        fd.append('token', token);
        await apiCall('mod_portal_install', fd);
        showToast('安装请求已提交', 'success');
        await loadMods();
    } catch (e) {
        showToast('安装失败', 'error');
    }
}

window.__mods = {
    loadMods, uploadMod, delMod, installModFromPortal
};

})(); // end mods


/* ===========================
   Players Module
   =========================== */
(function(){
const $ = window.__ui.$id;
const showToast = window.__ui.showToast;
const apiCall = window.__api.apiCall;

async function loadPlayerLists() {
    try {
        const r = await fetch('api.php?action=player_lists');
        const d = await r.json();
        fill('list-admins', d.admins);
        fill('list-bans', d.bans);
        fill('list-whitelist', d.whitelist);
    } catch (e) {
        // ignore
    }
}

function fill(id, arr) {
    const el = document.getElementById(id);
    if (!el) return;
    if (!arr || arr.length === 0) {
        el.innerHTML = '<div class="text-center text-muted small p-2">无记录</div>';
        return;
    }
    el.innerHTML = arr.map(p => {
        const n = typeof p === 'string' ? p : p.username;
        return `<button class="list-group-item list-group-item-action py-1" data-player="${n}">${n}</button>`;
    }).join('');
    el.querySelectorAll('button[data-player]').forEach(b => b.addEventListener('click', ()=>{
        document.getElementById('target-player').value = b.dataset.player;
    }));
}

function doAction(type) {
    const n = document.getElementById('target-player').value;
    if (!n) return showToast('请输入玩家名', 'error');
    const cmds = { ban: `/ban ${n}`, kick: `/kick ${n}`, unban: `/unban ${n}`, promote: `/promote ${n}`, 'wl-add': `/whitelist add ${n}`, 'wl-remove': `/whitelist remove ${n}` };
    if (!cmds[type]) return;
    if (!confirm('确认执行?')) return;
    const fd = new FormData();
    fd.append('cmd', cmds[type]);
    apiCall('console', fd).then(()=> {
        showToast('命令已发送', 'success');
        setTimeout(loadPlayerLists, 1000);
    }).catch(()=> showToast('执行失败', 'error'));
}

function doGift() {
    const n = document.getElementById('target-player').value;
    const i = document.getElementById('gift-item').value;
    const c = document.getElementById('gift-count').value;
    if (!n || !i || !c) return showToast('请完善参数', 'error');
    if (!confirm(`给 ${n} 发送 ${c}个 ${i}?`)) return;
    const fd = new FormData();
    fd.append('cmd', `/c game.players["${n}"].insert{name="${i}",count=${c}}`);
    apiCall('console', fd).then(()=> showToast('发送完成', 'success')).catch(()=> showToast('发送失败', 'error'));
}

window.__players = {
    loadPlayerLists, doAction, doGift
};

})(); // end players


/* ===========================
   Files Module
   =========================== */
(function(){
const $ = window.__ui.$id;
const showToast = window.__ui.showToast;
const apiCall = window.__api.apiCall;

async function loadDropdown(type, id) {
    try {
        const r = await fetch(`api.php?action=files&type=${encodeURIComponent(type)}`);
        const d = await r.json();
        const s = document.getElementById(id);
        if(!s) return;
        s.innerHTML = '';
        if (d.files && d.files.length) {
            d.files.forEach(f => {
                const o = document.createElement('option');
                o.value = f.filename; o.innerText = f.filename;
                s.appendChild(o);
            });
            s.selectedIndex = 0;
        } else {
            s.innerHTML = '<option disabled>无文件</option>';
        }
    } catch (e) {
        // ignore
    }
}

async function loadFiles(type, id) {
    try {
        const r = await fetch(`api.php?action=files&type=${encodeURIComponent(type)}`);
        const d = await r.json();
        const el = document.getElementById(id);
        if(!el) return;
        el.innerHTML = '';
        if(!d.files || !d.files.length) {
            el.innerHTML = '<div class="text-center text-muted small py-2">无文件</div>';
            return;
        }
        const frag = document.createDocumentFragment();
        d.files.forEach(f => {
            const div = document.createElement('div');
            div.className = 'list-group-item d-flex justify-content-between align-items-center';
            const dl = type !== 'config' ? `<a href="api.php?action=download&filename=${encodeURIComponent(f.filename)}&type=${encodeURIComponent(type)}" class="btn btn-sm btn-link text-success p-0 me-2"><i class="bi bi-download"></i></a>` : '';
            div.innerHTML = `<span>${f.filename}</span><div>${dl}<button class="btn btn-sm btn-link text-danger p-0" data-action="delete-file" data-filename="${f.filename}" data-type="${type}"><i class="bi bi-trash"></i></button></div>`;
            frag.appendChild(div);
        });
        el.appendChild(frag);
    } catch (e) {
        // ignore
    }
}

async function loadAllFiles() {
    loadFiles('map','file-list-map');
    loadFiles('config','file-list-config');
}

async function uploadFiles(inputEl) {
    const inp = inputEl || document.getElementById('file-upload');
    if (!inp || !inp.files || inp.files.length === 0) return showToast('请选择文件', 'error');
    const fd = new FormData();
    for (const f of inp.files) fd.append('file[]', f);
    fd.append('action', 'upload');
    try {
        const btn = document.getElementById('btn-upload');
        if (btn) btn.disabled = true;
        await apiCall('upload', fd);
        showToast('上传完成', 'success');
        inp.value = '';
        await loadAllFiles();
        await loadDropdown('map', 'map-select');
        await loadDropdown('config', 'config-select');
        if (btn) btn.disabled = false;
    } catch (e) {
        showToast('上传失败', 'error');
        const btn = document.getElementById('btn-upload'); if (btn) btn.disabled = false;
    }
}

async function deleteFile(filename, type) {
    if (!confirm('确认删除?')) return;
    try {
        const fd = new FormData();
        fd.append('filename', filename);
        fd.append('type', type);
        await apiCall('delete_file', fd);
        showToast('删除完成', 'success');
        await loadAllFiles();
    } catch (e) {
        showToast('删除失败', 'error');
    }
}

window.__files = {
    loadDropdown, loadFiles, loadAllFiles, uploadFiles, deleteFile
};

})(); // end files


/* ===========================
   Items Module (物品库)
   =========================== */
(function(){
const $ = window.__ui.$id;
const showToast = window.__ui.showToast;

const ITEMS = {
    logistics: {"wood":"木头","coal":"煤","stone":"石头","iron-plate":"铁板","copper-plate":"铜板","steel-plate":"钢材","transport-belt":"传送带","fast-transport-belt":"高速传送带","express-transport-belt":"极速传送带","inserter":"机械臂","fast-inserter":"高速臂","stack-inserter":"堆叠臂","pipe":"管道","pump":"泵","rail":"铁路","locomotive":"机车","car":"汽车","logistic-robot":"物流机器人","roboport":"指令台"},
    production: {"repair-pack":"修理包","boiler":"锅炉","steam-engine":"蒸汽机","solar-panel":"太阳能","accumulator":"蓄电池","nuclear-reactor":"核堆","electric-mining-drill":"电矿机","stone-furnace":"石炉","electric-furnace":"电炉","assembling-machine-2":"组装机2","oil-refinery":"炼油厂","lab":"实验室","speed-module-3":"速度插件3"},
    combat: {"pistol":"手枪","submachine-gun":"冲锋枪","rocket-launcher":"火箭筒","firearm-magazine":"黄弹","piercing-rounds-magazine":"红弹","grenade":"手雷","heavy-armor":"重甲","power-armor-mk2":"MK2装甲","laser-turret":"激光塔","wall":"墙","radar":"雷达"}
};

function renderItems(cat='logistics') {
    const grid = document.getElementById('item-grid');
    if (!grid) return;
    grid.innerHTML = '';
    const frag = document.createDocumentFragment();
    const list = ITEMS[cat] || {};
    Object.entries(list).forEach(([code, name])=>{
        const b = document.createElement('button');
        b.className = 'btn btn-outline-secondary item-btn';
        b.innerHTML = `<div class="fw-bold">${name}</div><div class="small text-muted" style="font-size:0.7rem">${code}</div>`;
        b.addEventListener('click', ()=>{
            document.getElementById('gift-item').value = code;
            const modalEl = document.getElementById('itemModal');
            const bsModal = bootstrap.Modal.getInstance(modalEl);
            if (bsModal) bsModal.hide();
            showToast(`已选择 ${name}`, 'success', 1200);
        });
        frag.appendChild(b);
    });
    grid.appendChild(frag);
}

function filterItems(q='') {
    q = (q || '').toLowerCase();
    const grid = document.getElementById('item-grid');
    if (!grid) return;
    grid.innerHTML = '';
    const frag = document.createDocumentFragment();
    for (const cat in ITEMS) {
        for (const [code, name] of Object.entries(ITEMS[cat])) {
            if (code.includes(q) || name.includes(q)) {
                const b = document.createElement('button');
                b.className = 'btn btn-outline-secondary item-btn';
                b.innerHTML = `<div class="fw-bold">${name}</div><div class="small text-muted" style="font-size:0.7rem">${code}</div>`;
                b.addEventListener('click', ()=>{
                    document.getElementById('gift-item').value = code;
                    const modalEl = document.getElementById('itemModal');
                    const bsModal = bootstrap.Modal.getInstance(modalEl);
                    if (bsModal) bsModal.hide();
                });
                frag.appendChild(b);
            }
        }
    }
    grid.appendChild(frag);
}

window.__items = {
    ITEMS, renderItems, filterItems
};

})(); // end items


/* ===========================
   Version Module (update check / install)
   =========================== */
(function(){
const fetchJson = window.__api.fetchJson;
const apiCall = window.__api.apiCall;
const showToast = window.__ui.showToast;

async function checkUpdate() {
    const m = new bootstrap.Modal(document.getElementById('updateModal'));
    m.show();
    const vs = document.getElementById('ver-stable');
    const ve = document.getElementById('ver-exp');
    if (vs) vs.innerText = '...'; if (ve) ve.innerText = '...';
    try {
        const d = await fetchJson('api.php?action=update_check');
        if (vs) vs.innerText = d.stable || '—';
        if (ve) ve.innerText = d.experimental || '—';
    } catch (e) {
        showToast('检查失败', 'error');
    }
}

async function installVer(v) {
    if (!confirm(`确认安装 ${v}?`)) return;
    const l = document.getElementById('update-log');
    if (l) { l.classList.remove('d-none'); l.innerText = '下载中...'; }
    const fd = new FormData();
    fd.append('version', v);
    try {
        await apiCall('update_install', fd);
        if (l) l.innerText = '安装成功';
        await loadLocalVersions();
    } catch (e) {
        if (l) l.innerText = '安装失败';
        showToast('安装失败', 'error');
    }
}

async function loadLocalVersions() {
    try {
        const r = await fetch('api.php?action=get_versions');
        const d = await r.json();
        const s = document.getElementById('version-select');
        if (!s) return;
        s.innerHTML = '';
        (d.versions || []).forEach(v=>{
            const opt = document.createElement('option');
            opt.value = v.id; opt.text = v.name;
            s.appendChild(opt);
        });
    } catch (e) {
        // ignore
    }
}

window.__version = {
    checkUpdate, installVer, loadLocalVersions
};

})(); // end version


/* ===========================
   App Initialization (入口)
   =========================== */
(function(){
const $ = window.__ui.$id;
const showToast = window.__ui.showToast;
const apiCall = window.__api.apiCall;

document.addEventListener('DOMContentLoaded', ()=>{

    // set server address (same as original behavior)
    const addrEl = document.getElementById('server-address');
    if (addrEl) addrEl.innerText = window.location.hostname + ":34197";

    // initialize modules
    try { window.__terminal.initTerminal(); } catch(e){}
    try { window.__probe.initProbe(); } catch(e){}
    try { window.__mods.loadMods(); } catch(e){}
    try { window.__players.loadPlayerLists(); } catch(e){}
    try { window.__files.loadAllFiles(); } catch(e){}
    try { window.__files.loadDropdown('map','map-select'); } catch(e){}
    try { window.__files.loadDropdown('config','config-select'); } catch(e){}
    try { window.__version.loadLocalVersions(); } catch(e){}

    // Tab lazy loads
    document.querySelector('button[data-bs-target="#tab-mods"]')
        ?.addEventListener('shown.bs.tab', ()=>{ window.__mods.loadMods(); });
    document.querySelector('button[data-bs-target="#tab-players"]')
        ?.addEventListener('shown.bs.tab', ()=>{ window.__players.loadPlayerLists(); });

    // item modal render
    document.getElementById('itemModal')?.addEventListener('shown.bs.modal', ()=>{ window.__items.renderItems('logistics'); });

    // Event delegation for data-action
    document.addEventListener('click', (e)=>{
        const el = e.target.closest('[data-action]');
        if (!el) return;
        const action = el.dataset.action;
        switch (action) {
            case 'upload-mod':
                window.__mods.uploadMod(document.getElementById('mod-upload-input'));
                break;
            case 'refresh-mods':
                window.__mods.loadMods();
                break;
            case 'delete-mod':
                window.__mods.delMod(el.dataset.filename);
                break;
            case 'upload-file':
                window.__files.uploadFiles(document.getElementById('file-upload'));
                break;
            case 'delete-file':
                window.__files.deleteFile(el.dataset.filename, el.dataset.type);
                break;
            case 'player-action':
                window.__players.doAction(el.dataset.type);
                break;
            case 'send-gift':
                window.__players.doGift();
                break;
            case 'check-update':
                window.__version.checkUpdate();
                break;
            case 'install-version':
                window.__version.installVer(el.dataset.version);
                break;
            default:
                // unhandled actions
                break;
        }
    });

    // Bind legacy functions expected by original index.html
    window.loadMods = window.__mods.loadMods;
    window.loadPlayerLists = window.__players.loadPlayerLists;
    window.loadAllFiles = window.__files.loadAllFiles;
    window.refreshMapList = ()=> window.__files.loadDropdown('map','map-select');
    window.refreshConfigList = ()=> window.__files.loadDropdown('config','config-select');

    // server control & saveGame (compat)
    window.controlServer = (act) => { apiCall(act, new FormData(document.getElementById('startForm'))).then(()=> { window.__probe.startProbe(); }).catch(()=>{}); };
    window.saveGame = () => { const f = new FormData(); f.append('cmd','/save'); apiCall('console', f).catch(()=>{}); };

    // local mod filter
    document.getElementById('local-mod-filter')?.addEventListener('input', (e)=>{
        const q = e.target.value.toLowerCase();
        document.querySelectorAll('#mod-list .mod-item').forEach(node=>{
            const name = node.querySelector('.mod-name')?.innerText?.toLowerCase() || '';
            node.style.display = name.includes(q) ? 'flex' : 'none';
        });
    });

    // item search box
    document.getElementById('item-search')?.addEventListener('input', (e)=> window.__items.filterItems(e.target.value.toLowerCase()));

    // portal search enter key — keep compatibility with original function searchModPortal
    const portalSearch = document.getElementById('portal-search');
    if (portalSearch) {
        portalSearch.addEventListener('keydown', (e)=>{
            if (e.key === 'Enter') {
                // original code used searchModPortal which relied on API; recreate minimal behavior:
                // call api.php?action=mod_portal_search&q=...
                const q = portalSearch.value || '';
                const resultsHolder = document.getElementById('portal-results');
                if (!q.trim()) {
                    resultsHolder.innerHTML = '<div class="text-center text-muted">请输入查询词</div>';
                    return;
                }
                resultsHolder.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>';
                fetch(`api.php?action=mod_portal_search&q=${encodeURIComponent(q)}`).then(r=>r.json()).then(data=>{
                    resultsHolder.innerHTML = '';
                    if (!data.results || data.results.length === 0) {
                        resultsHolder.innerHTML = '<div class="text-center text-muted">未找到 Mod</div>';
                        return;
                    }
                    data.results.forEach(m=>{
                        const item = document.createElement('div');
                        item.className = 'list-group-item';
                        item.innerHTML = `<div class="d-flex justify-content-between"><div><h6 class="mb-1 text-primary fw-bold">${escapeHtml(m.title)} <small class="text-muted fw-normal">(${escapeHtml(m.name)})</small></h6><p class="mb-1 small text-secondary text-truncate" style="max-width:500px;">${escapeHtml(m.summary)}</p><small class="text-muted">下载: ${m.downloads_count}</small></div><button class="btn btn-sm btn-outline-primary" data-portal="${escapeHtml(m.name)}">安装</button></div>`;
                        // install button handler
                        item.querySelector('button')?.addEventListener('click', ()=>{
                            const username = document.getElementById('factorio-user').value;
                            const token = document.getElementById('factorio-token').value;
                            window.__mods.installModFromPortal(m.name, username, token);
                        });
                        resultsHolder.appendChild(item);
                    });
                }).catch(()=>{ resultsHolder.innerHTML = '<div class="text-danger text-center">搜索失败</div>'; });
            }
        });
    }

    // legacy function for searchModPortal & installVer used elsewhere in original file
    window.searchModPortal = function(){ const btn = document.querySelector('#portal-search'); if (btn) { const ev = new KeyboardEvent('keydown', {key:'Enter'}); btn.dispatchEvent(ev); } };
    window.installVer = function(v){ window.__version.installVer(v); };
    window.loadLocalVersions = function(){ window.__version.loadLocalVersions(); };

}); // DOMContentLoaded

})(); // end app init

/* ===========================
   EOF app.bundle.js
   =========================== */

