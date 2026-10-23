'use strict';
// Frontend contract checks only; this fixture is never used by PHP entry points.
const results = document.getElementById('results'), frame = document.getElementById('preview');
const state = {groups:[{id:'1', name:'临时文件', group_type:'normal', message_count:1, summary:'initial', last_message_at:'2026-10-13 01:00:00'}], messages:[{id:'1', group_id:'1', content:'initial', source_type:'admin', created_at:'2026-10-13 01:00:00', attachments:[]}], seq:0, events:[]};
window.apiFixture = async (url, options = {}) => {
    const params = new URL(url).searchParams, action = params.get('action');
    let data;
    switch (action) {
        case 'me': data = {username:'admin', csrf:'test', timezone:'Asia/Shanghai', poll_seconds:5, max_file_bytes:10485760, max_request_bytes:20971520, max_attachments:5}; break;
        case 'groups': data = state.groups; break;
        case 'snapshot': data = {groups:state.groups, cursor:String(state.seq), page:{messages:state.messages, before:'1', has_more:false}}; break;
        case 'send': {
            if (window.failNext) {
                window.failNext = false;
                return new Response(JSON.stringify({success:false, message:'test upload failure'}), {status:422, headers:{'Content-Type':'application/json'}});
            }
            const message = {id:String(state.messages.length + 1), group_id:'1', content:options.body.get('content'), source_type:'admin', created_at:'2026-10-13 02:00:00', attachments:[]};
            state.messages.push(message); state.groups[0].message_count++;
            state.events.push({seq:++state.seq, event_type:'message_added', group_id:'1', message_id:message.id, message}); data = message; break;
        }
        case 'sync': data = {groups:state.groups, next_cursor:String(state.seq), reload:false, events:state.events.filter(event => event.seq > Number(params.get('since')))}; break;
        default: throw new Error('Unexpected fixture action: ' + action);
    }
    return new Response(JSON.stringify({success:true, data}), {headers:{'Content-Type':'application/json'}});
};
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(fn) { for (let i = 0; i < 100; i++) { if (fn()) return; await sleep(20); } throw new Error('Timed out'); }
const checks = [];
function verify(condition, description) { if (!condition) throw new Error(description); checks.push('PASS: ' + description); results.textContent = checks.join('\n'); }
(async () => {
    try {
        const source = await (await fetch('../demo.html')).text();
        const html = source.replace('<head>', `<head><base href="${new URL('../', location.href).href}">`)
            .replace('<script src="style/demo.js"></script>', '<script>window.fetch = parent.apiFixture;</script><script src="style/editor.js"></script><script src="style/app.js"></script>');
        frame.srcdoc = html;
        await new Promise(resolve => frame.onload = resolve);
        await until(() => frame.contentDocument.getElementById('group-title')?.textContent === '临时文件');
        const win = frame.contentWindow, doc = frame.contentDocument, input = doc.getElementById('message-input');
        verify(doc.getElementById('group-settings').textContent.includes('分组设置'), 'group settings entry has a visible label');
        doc.getElementById('group-settings').click();
        verify(doc.getElementById('group-name')?.value === '临时文件' && !!doc.getElementById('delete-group'), 'settings opens for selected ordinary group with delete control');
        doc.querySelector('#modal [data-bs-dismiss="modal"]').click();
        verify(doc.querySelectorAll('[data-copy]').length === 1, 'real application script renders API messages');
        verify(win.getComputedStyle(doc.getElementById('groups')).overflowX === 'hidden', 'sidebar horizontal scrolling is disabled');
        verify(doc.getElementById('groups').scrollWidth <= doc.getElementById('groups').clientWidth + 1, 'sidebar preview fits the available width');
        input.value = 'first\nsecond'; input.dispatchEvent(new win.Event('input'));
        const enter = new win.KeyboardEvent('keydown', {key:'Enter', cancelable:true, bubbles:true}); input.dispatchEvent(enter);
        verify(!enter.defaultPrevented, 'Enter remains native newline');
        const composing = new win.KeyboardEvent('keydown', {key:'Enter', shiftKey:true, isComposing:true, cancelable:true}); input.dispatchEvent(composing);
        verify(state.messages.length === 1, 'composition input does not send');
        input.dispatchEvent(new win.KeyboardEvent('keydown', {key:'Enter', shiftKey:true, cancelable:true}));
        await until(() => state.messages.length === 2 && input.value === '');
        await sleep(50);
        verify(doc.querySelectorAll('[data-copy]').length === 2, 'send response and synchronization event are deduplicated');
        verify(state.messages[1].content === 'first\nsecond', 'multiline text reaches send intact');
        const transfer = new win.DataTransfer();
        transfer.items.add(new win.File(['binary fixture'], 'screenshot.png', {type:'image/png'}));
        const paste = new win.ClipboardEvent('paste', {clipboardData:transfer, cancelable:true, bubbles:true}); input.dispatchEvent(paste);
        verify(paste.defaultPrevented && doc.querySelectorAll('[data-remove-file]').length === 1, 'binary clipboard image becomes a pending attachment');
        verify(state.messages.length === 2, 'pasting an image does not submit');
        doc.querySelector('[data-remove-file]').click();
        verify(!doc.querySelector('[data-remove-file]'), 'pending image can be removed');
        const text = new win.DataTransfer(); text.setData('text/plain', 'ordinary text');
        const plainPaste = new win.ClipboardEvent('paste', {clipboardData:text, cancelable:true}); input.dispatchEvent(plainPaste);
        verify(!plainPaste.defaultPrevented, 'plain clipboard text is not swallowed');
        input.value = 'retry this'; input.dispatchEvent(new win.Event('input'));
        input.dispatchEvent(new win.ClipboardEvent('paste', {clipboardData:transfer, cancelable:true}));
        window.failNext = true;
        doc.getElementById('send').click();
        await until(() => !doc.getElementById('send').disabled);
        verify(input.value === 'retry this' && doc.querySelectorAll('[data-remove-file]').length === 1, 'failed sends preserve text and retryable attachment');
        verify(state.messages.length === 2, 'failed send does not create a message');
        doc.getElementById('send').click();
        await until(() => state.messages.length === 3 && input.value === '');
        verify(!doc.querySelector('[data-remove-file]'), 'successful retry clears pending attachments');
        verify(![...doc.querySelectorAll('link[rel="stylesheet"]')].some(link => !link.href.endsWith('/style/tabler.min.css')), 'Tabler is the only application stylesheet');
        frame.width = '390';
        await sleep(50);
        verify(win.getComputedStyle(doc.getElementById('workspace')).display === 'none', 'mobile starts with group list');
        doc.querySelector('[data-group]').click();
        await until(() => win.getComputedStyle(doc.getElementById('workspace')).display === 'flex');
        verify(doc.documentElement.scrollWidth <= doc.documentElement.clientWidth + 1, 'mobile chat has no horizontal page overflow');
        doc.getElementById('back').click();
        verify(win.getComputedStyle(doc.getElementById('sidebar')).display === 'flex', 'mobile returns to group list');
        results.textContent += '\nALL BROWSER CHECKS PASSED';
    } catch (error) { results.textContent = checks.join('\n') + '\nFAIL: ' + error.message; }
})();
