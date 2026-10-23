'use strict';

// Shared admin/anonymous editor: binary clipboard images use the file input path.
class NoteEditor {
    constructor({input, pending, attach, fileInput, send, submit, notify, limits = {}}) {
        this.input = input;
        this.pending = pending;
        this.button = send;
        this.files = [];
        this.busy = false;
        this.disabled = false;
        this.limits = {max_file_bytes: 10 * 1024 ** 2, max_request_bytes: 20 * 1024 ** 2, max_attachments: 5, ...limits};
        this.notify = notify;
        attach.onclick = () => { if (!this.busy && !this.disabled) fileInput.click(); };
        fileInput.onchange = () => { this.add([...fileInput.files]); fileInput.value = ''; };
        input.oninput = () => this.update();
        input.onkeydown = event => {
            if (event.key === 'Enter' && event.shiftKey && !event.isComposing && event.keyCode !== 229) {
                event.preventDefault();
                send.click();
            }
        };
        input.addEventListener('paste', event => {
            let images = [...(event.clipboardData?.items || [])]
                .filter(item => item.kind === 'file' && item.type.startsWith('image/'))
                .map(item => item.getAsFile()).filter(Boolean);
            if (!images.length) images = [...(event.clipboardData?.files || [])].filter(file => file.type.startsWith('image/'));
            if (!images.length) return;
            event.preventDefault();
            const seen = new Set();
            images = images.filter(file => {
                const key = [file.name, file.type, file.size, file.lastModified].join(':');
                if (seen.has(key)) return false;
                seen.add(key); return true;
            }).map((file, i) => new File([file], `clipboard-${Date.now()}-${i}.${({'image/png':'png','image/jpeg':'jpg','image/gif':'gif','image/webp':'webp'})[file.type] || 'bin'}`, {type:file.type}));
            this.add(images);
        });
        send.onclick = async () => {
            if (this.busy || this.disabled || (!input.value.trim() && !this.files.length)) return;
            this.busy = true; input.disabled = true; this.update();
            try {
                const form = new FormData();
                form.set('content', input.value);
                this.files.forEach(item => form.append('files[]', item.file));
                await submit(form);
                input.value = ''; this.clear();
            } catch (error) { notify(error.message); }
            finally { this.busy = false; input.disabled = this.disabled; this.update(); }
        };
        pending.onclick = event => {
            const button = event.target.closest('[data-remove-file]');
            if (!button || this.busy) return;
            const index = Number(button.dataset.removeFile);
            URL.revokeObjectURL(this.files[index].url);
            this.files.splice(index, 1); this.render();
        };
        window.addEventListener('beforeunload', () => this.clear());
        this.update();
    }

    add(files) {
        if (this.busy || this.disabled) return;
        for (const file of files) {
            if (!/\.(jpg|jpeg|png|gif|webp|pdf|txt|md|doc|docx|xls|xlsx|ppt|pptx|zip|rar|7z|tar|gz|json|csv|xml|log)$/i.test(file.name)) {
                this.notify('不支持的文件类型：' + file.name); continue;
            }
            if (this.files.length >= this.limits.max_attachments) { this.notify('附件数量超过限制'); break; }
            if (file.size > this.limits.max_file_bytes) { this.notify('文件超过单文件大小限制'); continue; }
            if (this.files.reduce((n, item) => n + item.file.size, 0) + file.size > this.limits.max_request_bytes) {
                this.notify('附件总大小超过限制'); continue;
            }
            this.files.push({file, url: URL.createObjectURL(file)});
        }
        this.render();
    }

    render() {
        this.pending.replaceChildren();
        this.files.forEach((item, i) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'd-flex gap-2 align-items-center border rounded p-2 mb-2 small mw-100';
            if (item.file.type.startsWith('image/')) {
                const image = document.createElement('img');
                image.src = item.url; image.width = 32; image.height = 32;
                image.className = 'rounded object-fit-cover'; image.alt = '附件预览';
                wrapper.append(image);
            }
            const name = document.createElement('span');
            name.className = 'text-truncate'; name.textContent = `${item.file.name} · ${(item.file.size / 1024).toFixed(1)} KB`;
            name.title = name.textContent;
            const remove = document.createElement('button');
            remove.className = 'btn btn-sm btn-icon btn-ghost-secondary'; remove.textContent = '×';
            remove.title = '移除附件'; remove.setAttribute('aria-label', '移除附件'); remove.dataset.removeFile = i;
            wrapper.append(name, remove); this.pending.append(wrapper);
        });
        this.update();
    }

    clear() { this.files.forEach(item => URL.revokeObjectURL(item.url)); this.files = []; this.render(); }
    update() {
        // Tabler's optional autosize plugin is not bundled locally. Native rows
        // provide bounded growth without introducing another stylesheet/library.
        this.input.rows = 3;
        while (this.input.clientWidth > 0 && this.input.scrollHeight > this.input.clientHeight && this.input.rows < 8) this.input.rows++;
        this.button.disabled = this.busy || this.disabled || (!this.input.value.trim() && !this.files.length);
        this.input.disabled = this.busy || this.disabled;
    }
}
