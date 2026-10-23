'use strict';
const root = document.getElementById('anonymous-root');
const code = root.dataset.code, base = root.dataset.base;
let editor, expires = 0, clockOffset = 0, invalid = false, timer;
function notify(message, success = false) {
    const feedback = document.getElementById('feedback');
    feedback.className = 'alert mt-3 ' + (success ? 'alert-success' : 'alert-danger'); feedback.textContent = message;
}
async function request(action, form = null) {
    const url = new URL(base + '/api.php', location.origin); url.searchParams.set('action', action);
    const options = {cache:'no-store', credentials:'omit'};
    if (form) { options.method = 'POST'; form.set('code', code); options.body = form; }
    else url.searchParams.set('code', code);
    const response = await fetch(url, options);
    let data;
    try { data = await response.json(); } catch { throw new Error('服务器返回异常，请检查上传大小和网络。'); }
    if (!response.ok || !data.success) {
        if (data.error === 'token_invalid') disable();
        throw new Error(data.message || '投递失败');
    }
    return data.data;
}
function disable() {
    invalid = true; clearInterval(timer);
    if (editor) { editor.disabled = true; editor.update(); }
    document.getElementById('attach').disabled = true;
    document.getElementById('expiry').className = 'alert alert-danger';
    document.getElementById('expiry').textContent = '链接已失效，无法继续投递';
}
function tick() {
    const seconds = Math.max(0, Math.ceil((expires - Date.now() - clockOffset) / 1000));
    if (!seconds) { disable(); return; }
    document.getElementById('expiry').textContent = `剩余有效期 ${Math.floor(seconds / 60)} 分 ${String(seconds % 60).padStart(2,'0')} 秒`;
}
async function validate() {
    if (invalid) return;
    try {
        const data = await request('anonymous_validate');
        expires = new Date(data.expires_at.replace(' ', 'T') + 'Z').getTime();
        clockOffset = new Date(data.server_time.replace(' ', 'T') + 'Z').getTime() - Date.now();
        if (editor && !invalid) { editor.disabled = false; editor.update(); }
        tick();
    } catch (e) { notify(e.message); }
}
(async () => {
    editor = new NoteEditor({input:document.getElementById('message-input'), pending:document.getElementById('pending'), attach:document.getElementById('attach'), fileInput:document.getElementById('file-input'), send:document.getElementById('send'), notify,
        limits:{max_file_bytes:Number(root.dataset.maxFile), max_request_bytes:Number(root.dataset.maxRequest), max_attachments:Number(root.dataset.maxAttachments)},
        submit:async form => { await request('anonymous_submit', form); notify('保存成功', true); }});
    editor.disabled = true; editor.update();
    await validate();
    if (expires && !invalid) { editor.disabled = false; editor.update(); }
    timer = setInterval(() => { if (!invalid && expires) tick(); }, 1000);
    setInterval(() => { if (!document.hidden && navigator.onLine) validate(); }, 15000);
    window.addEventListener('online', validate);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) validate(); });
})();
