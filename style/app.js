'use strict';
const $ = id => document.getElementById(id);
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const iconPaths = {
    plus:'M12 5v14M5 12h14',back:'m14 6-6 6 6 6',refresh:'M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-2M6 19a7 7 0 0 0 12-2',
    check:'M5 12l4 4L19 6',more:'M5 12h.01M12 12h.01M19 12h.01',paperclip:'m8 12 6-6a3 3 0 0 1 4 4l-8 8a5 5 0 0 1-7-7l9-9',
    send:'m22 2-7 20-4-9-9-4 20-7ZM22 2 11 13',folder:'M3 7V5h7l2 2h9v14H3Z',inbox:'M4 4h16l2 13v4H2v-4L4 4ZM2 15h6l2 3h4l2-3h6',
    file:'M5 3h9l5 5v13H5ZM14 3v6h5M9 13h6',copy:'M9 9h12v12H9ZM15 9V3H3v12h6',trash:'M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15',
    download:'M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5',link:'m10 13 4-4M8 16l-1 1a4 4 0 0 1-6-6l4-4a4 4 0 0 1 6 0M16 8l1-1a4 4 0 0 1 6 6l-4 4a4 4 0 0 1-6 0',
    close:'m6 6 12 12M18 6 6 18',monitor:'M2 3h20v14H2ZM12 17v4M7 21h10',
};
function icon(name) { return `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${iconPaths[name] || iconPaths.folder}"></path></svg>`; }
document.querySelectorAll('[data-icon]').forEach(element => element.innerHTML = icon(element.dataset.icon));
function hidden(id, value) { $(id).hidden = value; $(id).classList.toggle('d-none', value); }
const modalInstance = new tabler.Modal($('modal'));
const toastInstance = new tabler.Toast($('toast'), {delay:3500});
function notify(message) { $('toast').textContent = message; toastInstance.show(); }
let groups = [], messages = [], current = null, cursor = '0', before = null, hasMore = false;
let user, editor, selected = new Set(), selecting = false, pollTimer, syncing = false, loading = false, failures = 0, stopped = false, generation = 0;
const drafts = new Map();
function utcDate(value) { return new Date(value.replace(' ', 'T') + 'Z'); }
function format(value, full = false) {
    return value ? utcDate(value).toLocaleString('zh-CN', {timeZone:user?.timezone || 'Asia/Shanghai', ...(full ? {} : {hour:'2-digit', minute:'2-digit'})}) : '';
}
async function api(action, data = null, query = {}) {
    const url = new URL('api.php', document.baseURI);
    url.searchParams.set('action', action);
    Object.entries(query).forEach(([key, value]) => url.searchParams.set(key, value));
    const options = {credentials:'same-origin', cache:'no-store', headers:{}};
    if (data !== null) {
        options.method = 'POST'; options.headers['X-CSRF-Token'] = user?.csrf || '';
        if (data instanceof FormData) options.body = data;
        else { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(data); }
    }
    const response = await fetch(url, options);
    let result;
    try { result = await response.json(); } catch { throw new Error('服务器返回异常，请检查网络和上传大小限制。'); }
    if (response.status === 401) { stopped = true; clearTimeout(pollTimer); location.assign('login.php'); throw new Error('登录已失效'); }
    if (!response.ok || !result.success) throw new Error(result.message || '请求失败');
    return result.data;
}
function modal(title, body) {
    $('modal-content').innerHTML = `<div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h3 mb-0">${escapeHtml(title)}</h2><button class="btn btn-icon btn-sm btn-ghost-secondary" data-bs-dismiss="modal" aria-label="关闭">${icon('close')}</button></div>${body}`;
    modalInstance.show();
}
function confirmAction(title, description, fn) {
    modal(title, `<p class="text-secondary">${escapeHtml(description)}</p><div class="d-flex justify-content-end gap-2"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button><button class="btn btn-danger" id="confirm-action">确认</button></div>`);
    $('confirm-action').onclick = async () => {
        const button = $('confirm-action'); button.disabled = true;
        try { await fn(); modalInstance.hide(); } catch (e) { notify(e.message); } finally { button.disabled = false; }
    };
}
function renderGroups() {
    $('groups').innerHTML = groups.map(group => `<button class="nav-link d-flex w-100 gap-3 p-3 mb-1 overflow-hidden ${String(group.id) === current ? 'active' : ''}" data-group="${group.id}"><span class="avatar avatar-sm flex-shrink-0">${icon(group.group_type === 'anonymous' ? 'inbox' : 'folder')}</span><span class="flex-fill w-0 overflow-hidden text-start"><span class="d-flex justify-content-between gap-2"><span class="fw-semibold text-truncate">${escapeHtml(group.name)}</span><span class="small opacity-75 text-nowrap flex-shrink-0">${format(group.last_message_at)}</span></span><span class="d-block small opacity-75 text-truncate mt-1">${escapeHtml(group.summary || (Number(group.message_count) ? '附件' : '还没有笔记'))}</span></span></button>`).join('');
    const group = groups.find(item => String(item.id) === current);
    if (!group) return;
    $('group-title').textContent = group.name;
    $('group-description').textContent = group.group_type === 'anonymous' ? '限时匿名投递收件箱' : '每条记录，都有它的位置';
    $('message-count').textContent = `${group.message_count} 条笔记`;
    hidden('anonymous-link', group.group_type !== 'anonymous');
}
function textHtml(text) {
    // Build links before escaping pieces, preserving exact source for clipboard copying.
    return text.split(/(https?:\/\/[^\s<>"']+)/g).map(part => /^https?:\/\//.test(part)
        ? `<a href="${escapeHtml(part)}" target="_blank" rel="noopener noreferrer">${escapeHtml(part)}</a>`
        : escapeHtml(part).replace(/\n/g, '<br>')).join('');
}
function size(value) { return value < 1048576 ? (value / 1024).toFixed(1) + ' KB' : (value / 1048576).toFixed(1) + ' MB'; }
function filesHtml(files) {
    return files.map(file => {
        const href = `attachment.php?id=${file.id}`;
        return file.mime_type.startsWith('image/')
            ? `<button class="btn p-0 border-0 d-block mt-2" data-preview="${file.id}" aria-label="查看 ${escapeHtml(file.original_name)}"><img class="img-fluid rounded" width="320" src="${href}&preview=1" alt="${escapeHtml(file.original_name)}"></button><a class="btn btn-sm btn-ghost-secondary mt-1" href="${href}">${icon('download')}${escapeHtml(file.original_name)}</a>`
            : `<div class="d-flex gap-3 align-items-center mt-2"><span class="avatar bg-white text-warning">${icon('file')}</span><div class="flex-fill text-break"><strong>${escapeHtml(file.original_name)}</strong><div class="text-secondary small">${size(Number(file.file_size))}</div></div><a class="btn btn-icon btn-ghost-secondary" href="${href}" title="下载文件">${icon('download')}</a></div>`;
    }).join('');
}
function renderMessages() {
    selected = new Set([...selected].filter(id => messages.some(message => String(message.id) === id)));
    let day = '';
    $('messages').innerHTML = `${hasMore ? '<div class="text-center"><button id="load-earlier" class="btn btn-sm btn-outline-secondary">加载更早消息</button></div>' : ''}` + messages.map(message => {
        const nextDay = utcDate(message.created_at).toLocaleDateString('zh-CN', {timeZone:user.timezone});
        const divider = nextDay !== day ? `<div class="text-center text-secondary small my-3">${nextDay}</div>` : ''; day = nextDay;
        return `${divider}<article class="d-flex gap-2 mb-3">${selecting ? `<input class="form-check-input mt-2 flex-shrink-0" type="checkbox" data-select="${message.id}" aria-label="选择消息" ${selected.has(String(message.id)) ? 'checked' : ''}>` : ''}<span class="avatar avatar-sm bg-primary-lt flex-shrink-0">${message.source_type === 'anonymous' ? '匿' : '我'}</span><div class="col-9 col-lg-8"><div class="d-flex gap-2 text-secondary small mb-2"><strong>${message.source_type === 'anonymous' ? '匿名提交' : '我'}</strong><span>${format(message.created_at)}</span></div><div class="chat-bubble chat-bubble-me text-break">${message.content ? `<div class="chat-bubble-body">${textHtml(message.content)}</div>` : ''}${filesHtml(message.attachments)}</div><div class="d-flex gap-2 mt-1">${message.content ? `<button class="btn btn-sm btn-ghost-secondary" data-copy="${message.id}">${icon('copy')}复制</button>` : ''}<button class="btn btn-sm btn-ghost-danger" data-delete="${message.id}">${icon('trash')}删除</button></div></div></article>`;
    }).join('') || '<div class="text-center text-secondary py-5">还没有笔记</div>';
    hidden('selection-bar', !selecting); updateSelected();
}
function updateSelected() { $('selected-count').textContent = `已选择 ${selected.size} 条`;$('delete-selected').disabled = !selected.size; }
function bottom() { $('messages').scrollTop = $('messages').scrollHeight; hidden('new-messages', true); }
function merge(items) {
    const map = new Map(messages.map(message => [String(message.id), message]));
    items.forEach(message => map.set(String(message.id), message));
    messages = [...map.values()].sort((a, b) => a.created_at.localeCompare(b.created_at) || (BigInt(a.id) > BigInt(b.id) ? 1 : -1));
}
function mobileChat(open) {
    $('sidebar').classList.toggle('d-none', open); $('sidebar').classList.toggle('d-md-flex', open);
    $('workspace').classList.toggle('d-none', !open); $('workspace').classList.toggle('d-flex', open);
}
async function loadGroup(id, open = true) {
    if (loading || editor?.busy) return;
    loading = true; generation++; clearTimeout(pollTimer);
    if (current) drafts.set(current, {text:editor.input.value, files:editor.files});
    if (editor) { editor.files = []; editor.input.value = ''; editor.render(); }
    const previous = current; current = String(id);
    try {
        const data = await api('snapshot', null, {group_id:current});
        groups = data.groups; messages = data.page.messages; before = data.page.before; hasMore = data.page.has_more; cursor = data.cursor;
        selecting = false; selected.clear();
        const draft = drafts.get(current);
        editor.input.value = draft?.text || ''; editor.files = draft?.files || []; editor.render();
        renderGroups(); renderMessages(); bottom(); if (open) mobileChat(true);
    } catch (e) {
        current = previous;
        const draft = drafts.get(previous);
        if (draft) { editor.input.value = draft.text; editor.files = draft.files; editor.render(); }
        notify(e.message);
    } finally { loading = false; schedule(); }
}
function schedule() {
    clearTimeout(pollTimer);
    if (!stopped && navigator.onLine && current) pollTimer = setTimeout(sync, document.hidden ? 45000 : Math.min((user.poll_seconds || 5) * 1000 * 2 ** failures, 60000));
}
async function sync(manual = false) {
    if (syncing || loading || !current || stopped || !navigator.onLine) return;
    syncing = true;
    const group = current, epoch = generation;
    if (manual) { $('refresh').disabled = true; $('refresh').innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
    try {
        const data = await api('sync', null, {since:cursor, group_id:group});
        if (current !== group || loading || epoch !== generation) return;
        if (data.groups) groups = data.groups;
        if (!groups.some(item => String(item.id) === current)) {
            await loadGroup(groups[0].id, false); return;
        }
        if (data.reload) { await loadGroup(current, false); return; }
        const area = $('messages'), oldScroll = area.scrollTop, nearBottom = area.scrollHeight - oldScroll - area.clientHeight < 100;
        let changed = false, added = false;
        data.events.forEach(event => {
            if (String(event.group_id) !== current) return;
            if (event.event_type === 'message_added' && event.message) { merge([event.message]); changed = added = true; }
            if (event.event_type === 'message_deleted') { messages = messages.filter(message => String(message.id) !== String(event.message_id)); changed = true; }
        });
        cursor = data.next_cursor; renderGroups();
        if (changed) { renderMessages(); if (nearBottom) bottom(); else { area.scrollTop = oldScroll; if (added) hidden('new-messages', false); } }
        failures = 0; $('refresh').title = '最近同步：' + new Date().toLocaleTimeString('zh-CN');
        if (manual) notify('已刷新');
    } catch (e) { failures = Math.min(failures + 1, 5); if (manual || failures === 1) notify(e.message); }
    finally { syncing = false; $('refresh').disabled = false; $('refresh').innerHTML = icon('refresh') + '<span class="d-none d-sm-inline">刷新</span>'; schedule(); }
}
async function earlier() {
    if (loading || !hasMore) return;
    loading = true;
    try {
        const group = current, height = $('messages').scrollHeight, scroll = $('messages').scrollTop;
        const page = await api('messages', null, {group_id:group, before});
        if (current !== group) return;
        merge(page.messages); before = page.before; hasMore = page.has_more; renderMessages();
        $('messages').scrollTop = scroll + $('messages').scrollHeight - height;
    } catch (e) { notify(e.message); } finally { loading = false; }
}
async function deleteMessages(ids) {
    await api('delete_messages', {group_id:current, ids});
    messages = messages.filter(message => !ids.includes(String(message.id))); selected.clear(); selecting = false;
    renderMessages(); await sync();
}
async function copy(text) {
    try { await navigator.clipboard.writeText(text); notify('已复制'); } catch { notify('复制失败，请手动选择文字复制。'); }
}
function groupForm(group = null) {
    const system = group?.group_type === 'anonymous';
    modal(group ? '分组设置' : '新建分组', `<form id="group-form"><label class="form-label">分组名称<input class="form-control mt-2" id="group-name" required maxlength="120" value="${escapeHtml(group?.name || '')}" ${system ? 'disabled' : ''}></label><div class="d-flex justify-content-end gap-2 mt-3">${group && !system ? '<button class="btn btn-outline-danger" type="button" id="delete-group">删除分组</button>' : ''}<button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">关闭</button>${!system ? '<button class="btn btn-primary">保存</button>' : ''}</div></form>`);
    $('group-form').onsubmit = async event => {
        event.preventDefault(); const button = event.submitter; button.disabled = true;
        try {
            const name = $('group-name').value;
            if (group) { await api('rename_group', {id:group.id, name}); await sync(); }
            else { const created = await api('create_group', {name}); await loadGroup(created.id); }
            modalInstance.hide();
        } catch (e) { notify(e.message); } finally { button.disabled = false; }
    };
    if ($('delete-group')) $('delete-group').onclick = () => {
        if (Number(group.message_count) > 0) { notify('分组中还有内容，请先删除全部消息和附件'); return; }
        confirmAction('删除空分组？', '此空分组将永久删除。', async () => {
        await api('delete_group', {id:group.id}); await sync();
        });
    };
}
async function deviceModal() {
    const devices = await api('devices');
    const place = data => [data?.country, data?.region, data?.city, data?.isp].filter(Boolean).join(' / ');
    modal('已登录设备', devices.map(device => `<div class="d-flex gap-3 py-3 border-bottom">${icon('monitor')}<div class="flex-fill overflow-hidden"><strong class="text-break">${escapeHtml(device.device_name)}</strong>${device.current ? '<span class="badge bg-success-lt ms-2">当前设备</span>' : ''}<div class="small text-secondary mt-2 text-break">首次登录：${format(device.created_at, true)}<br>最近登录：${format(device.last_login_at || device.created_at, true)}<br>最近使用：${format(device.last_seen_at, true)}<br>到期：${format(device.expires_at, true)}<br>登录 IP：${escapeHtml(device.login_ip || device.last_ip)}${place(device.login_location) ? `<br>登录归属地：${escapeHtml(place(device.login_location))}` : ''}<br>最近 IP：${escapeHtml(device.last_ip)}${place(device.ip_location) ? `<br>最近归属地：${escapeHtml(place(device.ip_location))}` : ''}</div></div><button class="btn btn-sm btn-outline-danger" data-revoke-device="${device.id}">移除</button></div>`).join('') + '<button class="btn btn-outline-secondary mt-3" id="revoke-others">退出其他所有设备</button>');
    $('revoke-others').onclick = () => confirmAction('退出其他设备？', '其他设备下一次请求将要求重新登录。', async () => { await api('revoke_others', {}); notify('其他设备已退出'); });
}
function passwordModal() {
    modal('修改密码', '<form id="password-form"><p class="text-secondary">修改后所有设备（包括当前设备）均须重新登录。</p><label class="form-label">当前密码<input class="form-control" name="old_password" type="password" required autocomplete="current-password"></label><label class="form-label mt-3">新密码<input class="form-control" name="new_password" type="password" minlength="12" required autocomplete="new-password"></label><label class="form-label mt-3">确认新密码<input class="form-control" name="confirm" type="password" minlength="12" required autocomplete="new-password"></label><button class="btn btn-primary mt-3">修改密码</button></form>');
    $('password-form').onsubmit = async event => {
        event.preventDefault(); const form = new FormData(event.target), button = event.submitter;
        if (form.get('new_password') !== form.get('confirm')) return notify('两次输入的新密码不一致');
        button.disabled = true;
        try { await api('change_password', {old_password:form.get('old_password'), new_password:form.get('new_password')}); stopped = true; location.assign('login.php'); }
        catch (e) { notify(e.message); button.disabled = false; }
    };
}
async function tokensModal() {
    const tokens = await api('tokens');
    modal('匿名投递链接', `<p class="text-secondary small">六位数字码适合短时投递，安全性低于长随机码。仅可向匿名收件箱写入。</p><label class="form-label">有效期<select class="form-select" id="token-duration">${[5,10,20,30,60].map(n => `<option value="${n}">${n} 分钟</option>`).join('')}</select></label><button class="btn btn-primary mt-3" id="create-token">生成链接</button>${tokens.map(token => `<div class="d-flex justify-content-between align-items-center border-top py-3 mt-3"><div><strong>${escapeHtml(token.masked_code)}</strong><div class="small text-secondary">${format(token.expires_at, true)} 到期 · ${{active:'有效',expired:'已过期',revoked:'已撤销'}[token.status]} · ${token.submit_count} 次投递</div></div>${token.status === 'active' ? `<button class="btn btn-sm btn-outline-danger" data-revoke-token="${token.id}">撤销</button>` : ''}</div>`).join('')}`);
    $('create-token').onclick = async () => {
        const button = $('create-token'); button.disabled = true;
        try {
            const token = await api('create_token', {duration:Number($('token-duration').value)});
            modal('链接已生成', `<div class="h1 text-primary text-center my-4">${escapeHtml(token.code)}</div><p class="small text-secondary">完整访问码仅在本次生成时显示，请及时复制。</p><div class="bg-light p-3 text-break">${escapeHtml(token.url)}</div><div class="d-flex justify-content-end gap-2 mt-3"><button class="btn btn-outline-secondary" id="copy-token">复制链接</button><a class="btn btn-primary" target="_blank" rel="noopener noreferrer" href="${escapeHtml(token.url)}">打开投稿页</a></div>`);
            $('copy-token').onclick = () => copy(token.url);
        } catch (e) { notify(e.message); button.disabled = false; }
    };
}
document.addEventListener('click', async event => {
    const button = event.target.closest('button'); if (!button) return;
    try {
        if (button.dataset.group) await loadGroup(button.dataset.group);
        if (button.id === 'load-earlier') await earlier();
        if (button.dataset.copy) await copy(messages.find(message => String(message.id) === button.dataset.copy).content);
        if (button.dataset.delete) confirmAction('删除笔记？', '删除后不可恢复，附件将一并删除。', () => deleteMessages([button.dataset.delete]));
        if (button.dataset.preview) modal('图片预览', `<img class="img-fluid d-block mx-auto" src="attachment.php?id=${button.dataset.preview}&preview=1" alt="图片预览"><a class="btn btn-primary mt-3" href="attachment.php?id=${button.dataset.preview}">${icon('download')}下载原图</a>`);
        if (button.dataset.account === 'devices') await deviceModal();
        if (button.dataset.account === 'password') passwordModal();
        if (button.dataset.account === 'logout') confirmAction('退出登录？', '退出当前设备的登录会话。', async () => { await api('logout', {}); stopped = true; location.assign('login.php'); });
        if (button.dataset.revokeDevice) confirmAction('移除此设备？', '移除当前设备会立即退出，其他设备下一次请求将被拒绝。', async () => { const data = await api('revoke_device', {id:button.dataset.revokeDevice}); if (data.current) { stopped = true; location.assign('login.php'); } else notify('设备已移除'); });
        if (button.dataset.revokeToken) confirmAction('撤销链接？', '已打开的匿名页面也将无法再提交。', async () => { await api('revoke_token', {id:button.dataset.revokeToken}); notify('链接已撤销'); });
    } catch (e) { notify(e.message); }
});
$('messages').onchange = event => { if (!event.target.dataset.select) return; const id = event.target.dataset.select; event.target.checked ? selected.add(id) : selected.delete(id); updateSelected(); };
$('messages').addEventListener('scroll', () => { if ($('messages').scrollTop < 24 && hasMore && !loading) void earlier(); });
$('add-group').onclick = $('new-group').onclick = () => groupForm();
$('group-settings').onclick = () => {
    const group = groups.find(group => String(group.id) === current);
    if (loading) { notify('正在加载分组，请稍后重试'); return; }
    if (!group) { notify('请先点击左侧列表选择分组'); return; }
    groupForm(group);
};
$('back').onclick = () => mobileChat(false);
$('select-mode').onclick = () => { selecting = !selecting; selected.clear(); renderMessages(); };
$('cancel-select').onclick = () => { selecting = false; selected.clear(); renderMessages(); };
$('delete-selected').onclick = () => confirmAction('删除所选笔记？', `将永久删除 ${selected.size} 条消息及附件。`, () => deleteMessages([...selected]));
$('anonymous-link').onclick = () => tokensModal().catch(e => notify(e.message));
$('refresh').onclick = () => sync(true);
const newButton = document.createElement('button');
newButton.id = 'new-messages'; newButton.className = 'btn btn-sm btn-primary align-self-center my-2 d-none'; newButton.textContent = '有新消息'; newButton.onclick = bottom;
$('messages').after(newButton);
window.addEventListener('online', () => sync()); window.addEventListener('offline', () => { clearTimeout(pollTimer); notify('网络已断开，待发送内容会保留'); });
document.addEventListener('visibilitychange', () => { if (!document.hidden) sync(); else schedule(); });
window.addEventListener('beforeunload', () => drafts.forEach(draft => draft.files.forEach(item => URL.revokeObjectURL(item.url))));
(async () => {
    try {
        user = await api('me');
        $('user-menu').querySelector('.d-none.d-sm-inline').textContent = user.username;
        $('sidebar').querySelector('footer').innerHTML = '<span class="status-dot bg-success"></span>已连接<span class="ms-auto">V1.2</span>';
        editor = new NoteEditor({input:$('message-input'), pending:$('pending'), attach:$('attach'), fileInput:$('file-input'), send:$('send'), limits:user, notify,
            submit:async form => {
                const group = current; form.set('group_id', group);
                const message = await api('send', form);
                if (current === group) { merge([message]); renderMessages(); bottom(); drafts.delete(group); }
                notify('已保存');
                // Synchronization errors must not turn a committed send into a retry.
                void sync();
            }});
        groups = await api('groups'); if (groups.length) await loadGroup(groups[0].id, false);
    } catch (e) { notify(e.message); }
})();
