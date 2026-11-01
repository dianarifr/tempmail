'use strict';

(function () {
    var boot = JSON.parse(document.getElementById('bootstrap').textContent || '{}');

    var DOMAIN = String(boot.domain || '');
    var POLL_INTERVAL = Number(boot.pollInterval || 5) * 1000;
    var TTL_HOURS = Number(boot.ttlHours || 24);
    var STORE_KEY = 'tempmail.address';
    var THEME_KEY = 'tempmail.theme';

    var el = {
        addressValue: document.getElementById('address-value'),
        addressStatus: document.getElementById('address-status'),
        ttlCountdown: document.getElementById('ttl-countdown'),
        remainingNote: document.getElementById('remaining-note'),
        btnCreate: document.getElementById('btn-create'),
        btnCreate2: document.getElementById('btn-create-2'),
        btnCopy: document.getElementById('btn-copy'),
        openForm: document.getElementById('open-form'),
        openInput: document.getElementById('open-input'),
        openHint: document.getElementById('open-hint'),
        messageList: document.getElementById('message-list'),
        messageCount: document.getElementById('message-count'),
        refreshState: document.getElementById('refresh-state'),
        loadingBar: document.getElementById('loading-bar'),
        btnRefresh: document.getElementById('btn-refresh'),
        btnClear: document.getElementById('btn-clear'),
        stateEmpty: document.getElementById('state-empty'),
        stateLoading: document.getElementById('state-loading'),
        stateInboxEmpty: document.getElementById('state-inbox-empty'),
        stateError: document.getElementById('state-error'),
        errorTitle: document.getElementById('error-title'),
        errorDetail: document.getElementById('error-detail'),
        btnRetry: document.getElementById('btn-retry'),
        reader: document.getElementById('reader'),
        readerSubject: document.getElementById('reader-subject'),
        readerMeta: document.getElementById('reader-meta'),
        readerPlain: document.getElementById('reader-plain'),
        mailFrame: document.getElementById('mail-frame'),
        attachments: document.getElementById('attachments'),
        attachmentList: document.getElementById('attachment-list'),
        btnRaw: document.getElementById('btn-raw'),
        btnDeleteOne: document.getElementById('btn-delete-one'),
        btnBack: document.getElementById('btn-back'),
        toast: document.getElementById('toast'),
        themeToggle: document.getElementById('theme-toggle'),
        inboxPanel: document.querySelector('.inbox-panel')
    };

    var state = {
        address: null,
        messages: [],
        activeId: null,
        timer: null,
        countdown: null,
        loading: false,
        lastFetchAt: null
    };

    /* ---------- helpers ---------- */

    function store(key, value) {
        try {
            if (value === null) {
                localStorage.removeItem(key);
            } else {
                localStorage.setItem(key, value);
            }
        } catch (e) { /* storage bisa diblokir */ }
    }

    function read(key) {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }

    var toastTimer = null;

    function toast(message) {
        el.toast.textContent = message;
        el.toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            el.toast.classList.remove('is-visible');
        }, 2600);
    }

    function show(node, visible) {
        if (node) { node.hidden = !visible; }
    }

    function setBusy(busy, silent) {
        state.loading = busy;
        el.loadingBar.hidden = !busy;
        el.refreshState.classList.toggle('is-busy', busy);
        el.btnRefresh.disabled = busy || !state.address;

        if (busy) {
            if (!silent) { el.refreshState.textContent = 'memuat'; }
            return;
        }

        if (el.refreshState.textContent === 'memuat') {
            el.refreshState.textContent = state.lastFetchAt ? 'diperbarui' : 'idle';
        }
    }

    function api(path, options) {
        options = options || {};
        return fetch(path, {
            method: options.method || 'GET',
            headers: Object.assign({ 'Accept': 'application/json' }, options.headers || {}),
            body: options.body || null,
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, message: 'Respons server tidak valid.' };
            }).then(function (data) {
                if (!response.ok || data.ok === false) {
                    var err = new Error(data.message || 'Permintaan gagal.');
                    err.status = response.status;
                    err.code = data.error;
                    throw err;
                }
                return data;
            });
        });
    }

    function shortTime(value) {
        if (!value) { return ''; }
        var iso = String(value).replace(' ', 'T');
        var date = new Date(iso);
        if (isNaN(date.getTime())) { return ''; }

        var now = new Date();
        var sameDay = date.toDateString() === now.toDateString();
        var pad = function (n) { return n < 10 ? '0' + n : String(n); };

        if (sameDay) {
            return pad(date.getHours()) + ':' + pad(date.getMinutes());
        }
        return pad(date.getDate()) + '/' + pad(date.getMonth() + 1) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    function formatSize(bytes) {
        var n = Number(bytes) || 0;
        if (n < 1024) { return n + ' B'; }
        if (n < 1048576) { return (n / 1024).toFixed(1) + ' KB'; }
        return (n / 1048576).toFixed(1) + ' MB';
    }

    /* ---------- address ---------- */

    function renderAddress() {
        if (!state.address) {
            el.addressValue.textContent = 'Belum ada alamat';
            el.addressValue.classList.add('is-empty');
            el.btnCopy.disabled = true;
            el.btnClear.disabled = true;
            el.addressStatus.textContent = '-';
            el.ttlCountdown.textContent = '';
            return;
        }

        el.addressValue.textContent = state.address;
        el.addressValue.classList.remove('is-empty');
        el.btnCopy.disabled = false;
        el.btnClear.disabled = false;
        el.addressStatus.textContent = 'aktif';
    }

    function applyAddress(address, options) {
        options = options || {};
        state.address = address;
        state.activeId = null;
        state.messages = [];

        store(STORE_KEY, address);
        el.openInput.value = '';

        renderAddress();
        closeReader();
        schedulePoll();

        if (options.load !== false) {
            loadMessages();
        }
    }
    function createAddress() {
        var buttons = [el.btnCreate, el.btnCreate2];
        buttons.forEach(function (b) { if (b) { b.disabled = true; } });

        api('api/create.php', { method: 'POST' })
            .then(function (data) {
                applyAddress(data.address);
                if (typeof data.remaining === 'number') {
                    el.remainingNote.textContent = 'sisa ' + data.remaining + ' alamat hari ini';
                }
                toast('Alamat baru dibuat');
            })
            .catch(function (err) {
                toast(err.message);
            })
            .finally(function () {
                buttons.forEach(function (b) { if (b) { b.disabled = false; } });
            });
    }

    function copyAddress() {
        if (!state.address) { return; }

        var done = function () { toast('Alamat disalin'); };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(state.address).then(done).catch(function () {
                fallbackCopy(state.address, done);
            });
            return;
        }

        fallbackCopy(state.address, done);
    }

    function fallbackCopy(text, done) {
        var input = document.createElement('textarea');
        input.value = text;
        input.setAttribute('readonly', 'readonly');
        input.style.position = 'fixed';
        input.style.top = '-1000px';
        document.body.appendChild(input);
        input.select();

        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            toast('Tidak bisa menyalin otomatis, salin manual.');
        }

        document.body.removeChild(input);
    }

    /* ---------- message list ---------- */

    function setState(name) {
        show(el.stateEmpty, name === 'empty');
        show(el.stateLoading, name === 'loading');
        show(el.stateInboxEmpty, name === 'inbox-empty');
        show(el.stateError, name === 'error');
        show(el.messageList, name === 'list');
        show(el.reader, name === 'reader');
        if (el.inboxPanel) {
            el.inboxPanel.hidden = name === 'reader';
        }
        syncToolbar();
    }

    /** Tombol yang tidak punya sasaran dimatikan, bukan dibiarkan mati saat diklik. */
    function syncToolbar() {
        var hasAddress = Boolean(state.address);
        var hasMessages = state.messages.length > 0;

        el.btnClear.disabled = !hasAddress || !hasMessages;
        el.btnCopy.disabled = !hasAddress;
        el.btnRefresh.disabled = !hasAddress || state.loading;
        el.btnDeleteOne.disabled = state.activeId === null;
        el.btnRaw.setAttribute('aria-disabled', state.activeId === null ? 'true' : 'false');
    }

    function renderList() {
        el.messageList.textContent = '';
        el.messageCount.textContent = state.messages.length ? String(state.messages.length) : '';

        state.messages.forEach(function (message) {
            var li = document.createElement('li');

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'message-item ' + (message.is_read ? 'is-read' : 'is-unread');
            button.dataset.id = String(message.id);
            button.addEventListener('click', function () { openMessage(message.id); });

            var top = document.createElement('div');
            top.className = 'message-top';

            var mark = document.createElement('span');
            mark.className = 'unread-mark';
            mark.setAttribute('aria-hidden', 'true');

            var from = document.createElement('span');
            from.className = 'message-from';
            from.textContent = message.from_name || message.from_addr || 'Pengirim tidak dikenal';

            var time = document.createElement('span');
            time.className = 'message-time';
            time.textContent = shortTime(message.received_at);

            top.appendChild(mark);
            top.appendChild(from);
            top.appendChild(time);

            var subject = document.createElement('div');
            subject.className = 'message-subject';
            subject.textContent = message.subject || '(tanpa subjek)';

            button.appendChild(top);
            button.appendChild(subject);

            if (message.preview) {
                var preview = document.createElement('div');
                preview.className = 'message-preview';
                preview.textContent = message.preview;
                button.appendChild(preview);
            }

            if (message.attachment_count > 0) {
                var note = document.createElement('span');
                note.className = 'attach-note';
                note.textContent = message.attachment_count + ' lampiran';
                button.appendChild(note);
            }

            li.appendChild(button);
            el.messageList.appendChild(li);
        });
    }

    function loadMessages(options) {
        options = options || {};

        if (!state.address) {
            setState('empty');
            return Promise.resolve();
        }

        if (state.loading) {
            return Promise.resolve();
        }

        if (!options.silent) {
            setBusy(true);
        } else {
            setBusy(true, true);
        }

        // Hanya tampilkan layar "memuat" bila memang belum ada data untuk
        // ditampilkan; kalau sudah ada daftar, cukup progress bar tipis.
        if (state.messages.length === 0 && !options.silent) {
            setState('loading');
        }

        var address = state.address;

        return api('api/inbox.php?address=' + encodeURIComponent(address))
            .then(function (data) {
                if (address !== state.address) { return; }

                state.messages = data.messages || [];
                state.lastFetchAt = new Date();
                el.messageCount.textContent = String(data.count || 0);
                el.refreshState.textContent = 'diperbarui';
                tickCountdown();

                if (state.activeId !== null) {
                    renderList();
                    return;
                }

                if (state.messages.length === 0) {
                    setState('inbox-empty');
                } else {
                    renderList();
                    setState('list');
                }
            })
            .catch(function (err) {
                if (address !== state.address) { return; }

                if (err.code === 'invalid_address') {
                    el.openHint.textContent = 'Alamat harus memakai domain ' + DOMAIN + '.';
                    store(STORE_KEY, null);
                    state.address = null;
                    state.messages = [];
                    renderAddress();
                    stopPoll();
                    setState('empty');
                    return;
                }

                el.refreshState.textContent = 'gagal';

                // Polling latar belakang yang gagal tidak boleh menghapus
                // daftar pesan yang sudah tampil. Error layar penuh hanya
                // dipakai saat belum ada data sama sekali.
                if (options.silent && state.messages.length > 0) {
                    return;
                }

                el.errorTitle.textContent = err.status === 429 ? 'Terlalu sering memuat' : 'Gagal memuat pesan';
                el.errorDetail.textContent = err.message || 'Periksa koneksi lalu coba lagi.';
                setState('error');
            })
            .finally(function () {
                setBusy(false);
                syncToolbar();
            });
    }

    /* ---------- reader ---------- */

    function openMessage(id) {
        if (!state.address) { return; }
        state.activeId = id;
        setState('reader');

        el.readerSubject.textContent = 'Memuat…';
        el.readerMeta.textContent = '';
        el.readerPlain.hidden = true;
        el.mailFrame.hidden = true;
        el.btnRaw.removeAttribute('href');
        show(el.attachments, false);
        syncToolbar();

        api('api/message.php?address=' + encodeURIComponent(state.address) + '&id=' + encodeURIComponent(id))
            .then(function (data) {
                if (state.activeId !== id) { return; }

                var message = data.message;

                el.readerSubject.textContent = message.subject || '(tanpa subjek)';

                el.readerMeta.textContent = '';
                var fromStrong = document.createElement('strong');
                fromStrong.textContent = message.from_name || message.from_addr || 'Pengirim tidak dikenal';
                var fromCode = document.createElement('code');
                fromCode.textContent = message.from_addr ? '<' + message.from_addr + '>' : '';
                var timeSpan = document.createElement('span');
                timeSpan.textContent = shortTime(message.received_at);
                el.readerMeta.appendChild(fromStrong);
                if (message.from_addr) { el.readerMeta.appendChild(fromCode); }
                el.readerMeta.appendChild(timeSpan);

                el.btnRaw.href = 'api/raw.php?address=' + encodeURIComponent(state.address) + '&id=' + encodeURIComponent(id);

                renderBody(message);

                el.attachmentList.textContent = '';
                if (data.attachments && data.attachments.length) {
                    data.attachments.forEach(function (attachment) {
                        el.attachmentList.appendChild(buildAttachment(attachment));
                    });
                    show(el.attachments, true);
                }

                // Tandai sudah dibaca di daftar lokal tanpa menunggu polling.
                state.messages.forEach(function (m) {
                    if (m.id === id) { m.is_read = true; }
                });

                syncToolbar();
            })
            .catch(function (err) {
                el.readerSubject.textContent = 'Gagal membuka pesan';
                el.readerMeta.textContent = '';
                el.readerPlain.textContent = err.message || 'Pesan mungkin sudah kedaluwarsa.';
                el.readerPlain.hidden = false;
            });
    }

    function renderBody(message) {
        var html = message.body_html || '';

        if (html) {
            // Sandbox tanpa allow-scripts: skrip di dalam email tidak dieksekusi.
            el.mailFrame.srcdoc = html;
            el.mailFrame.hidden = false;
            el.readerPlain.hidden = true;
        } else {
            el.mailFrame.hidden = true;
            el.readerPlain.textContent = message.body_text || '(pesan tidak memiliki isi teks)';
            el.readerPlain.hidden = false;
        }
    }

    function buildAttachment(attachment) {
        var li = document.createElement('li');
        var link = document.createElement('a');
        link.className = 'attachment-link';
        link.href = 'api/attachment.php?address=' + encodeURIComponent(state.address) + '&id=' + encodeURIComponent(attachment.id);
        link.rel = 'noopener noreferrer';
        link.setAttribute('download', attachment.filename || 'lampiran');

        var name = document.createElement('span');
        name.className = 'attachment-name';
        name.textContent = attachment.filename || 'lampiran';

        var size = document.createElement('span');
        size.className = 'attachment-size';
        size.textContent = formatSize(attachment.size);

        link.appendChild(name);
        link.appendChild(size);
        li.appendChild(link);

        return li;
    }

    function closeReader() {
        state.activeId = null;
        el.mailFrame.srcdoc = '';
        el.mailFrame.hidden = true;
        el.readerPlain.hidden = true;
        el.readerPlain.textContent = '';
        el.btnRaw.removeAttribute('href');
        show(el.attachments, false);

        if (!state.address) {
            setState('empty');
            return;
        }

        if (state.messages.length === 0) {
            setState('inbox-empty');
        } else {
            renderList();
            setState('list');
        }

        syncToolbar();
    }

    /* ---------- delete ---------- */

    function deleteMessage(id) {
        if (!state.address) { return; }

        var body = new URLSearchParams();
        body.set('address', state.address);
        body.set('id', String(id));

        api('api/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString()
        }).then(function () {
            toast('Pesan dihapus');
            state.messages = state.messages.filter(function (m) { return m.id !== id; });
            closeReader();
        }).catch(function (err) {
            toast(err.message);
        });
    }

    function clearInbox() {
        if (!state.address || state.messages.length === 0) { return; }

        if (!window.confirm('Hapus semua pesan di kotak masuk ini?')) { return; }

        var body = new URLSearchParams();
        body.set('address', state.address);

        api('api/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString()
        }).then(function (data) {
            toast((data.deleted || 0) + ' pesan dihapus');
            state.messages = [];
            closeReader();
        }).catch(function (err) {
            toast(err.message);
        });
    }

    /* ---------- polling ---------- */

    function schedulePoll() {
        stopPoll();
        if (!state.address) { return; }

        state.timer = window.setInterval(function () {
            if (document.hidden) { return; }
            loadMessages({ silent: true });
        }, POLL_INTERVAL);

        state.countdown = window.setInterval(tickCountdown, 30000);
    }

    function stopPoll() {
        if (state.timer !== null) {
            window.clearInterval(state.timer);
            state.timer = null;
        }

        if (state.countdown !== null) {
            window.clearInterval(state.countdown);
            state.countdown = null;
        }
    }
    function tickCountdown() {
        if (!state.address || state.messages.length === 0) {
            el.ttlCountdown.textContent = '';
            return;
        }

        // Pesan tertua menentukan kapan kotak masuk mulai kosong lagi,
        // jadi itu yang ditampilkan, bukan sekadar mengulang TTL di header.
        var oldest = null;

        state.messages.forEach(function (message) {
            var time = new Date(String(message.received_at).replace(' ', 'T')).getTime();
            if (!isNaN(time) && (oldest === null || time < oldest)) {
                oldest = time;
            }
        });

        if (oldest === null) {
            el.ttlCountdown.textContent = '';
            return;
        }

        var remaining = oldest + TTL_HOURS * 3600 * 1000 - Date.now();

        if (remaining <= 0) {
            el.ttlCountdown.textContent = 'kedaluwarsa';
            return;
        }

        var hours = Math.floor(remaining / 3600000);
        var minutes = Math.floor((remaining % 3600000) / 60000);

        el.ttlCountdown.textContent = hours > 0
            ? 'terlama ' + hours + 'j ' + minutes + 'm lagi'
            : 'terlama ' + minutes + 'm lagi';
    }

    /* ---------- theme ---------- */

    function syncThemeLabel() {
        var dark = document.documentElement.dataset.theme === 'dark';
        el.themeToggle.textContent = dark ? 'Tema terang' : 'Tema gelap';
        el.themeToggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }

    function toggleTheme() {
        var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        store(THEME_KEY, next);
        syncThemeLabel();
    }

    /* ---------- events ---------- */

    el.btnCreate.addEventListener('click', createAddress);
    if (el.btnCreate2) { el.btnCreate2.addEventListener('click', createAddress); }
    el.btnCopy.addEventListener('click', copyAddress);
    el.btnRefresh.addEventListener('click', function () { loadMessages(); });
    el.btnRetry.addEventListener('click', function () { loadMessages(); });
    el.btnClear.addEventListener('click', clearInbox);
    el.btnBack.addEventListener('click', closeReader);
    el.btnDeleteOne.addEventListener('click', function () {
        if (state.activeId !== null) { deleteMessage(state.activeId); }
    });
    el.themeToggle.addEventListener('click', toggleTheme);

    el.openForm.addEventListener('submit', function (event) {
        event.preventDefault();
        el.openHint.textContent = 'Alamat lama tetap bisa dibuka kapan saja. Isinya mengikuti masa simpan pesan.';

        var value = el.openInput.value.trim().toLowerCase();

        if (value === '') {
            el.openHint.textContent = 'Masukkan alamat email terlebih dahulu.';
            el.openInput.focus();
            return;
        }

        if (value.indexOf('@') === -1) {
            value = value + '@' + DOMAIN;
        }

        if (!value.endsWith('@' + DOMAIN)) {
            el.openHint.textContent = 'Alamat harus memakai domain ' + DOMAIN + '.';
            el.openInput.focus();
            return;
        }

        applyAddress(value);
    });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && state.address) {
            loadMessages({ silent: true });
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && state.activeId !== null) {
            closeReader();
        }
    });

    /* ---------- init ---------- */

    syncThemeLabel();

    var saved = read(STORE_KEY);

    if (saved && saved.indexOf('@' + DOMAIN) !== -1) {
        applyAddress(saved);
    } else {
        renderAddress();
        setState('empty');
    }

    tickCountdown();
})();
