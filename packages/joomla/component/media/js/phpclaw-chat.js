/* phpClaw Chat UI - Joomla 4/5/6 admin component */
/* jshint esversion: 6 */
(function () {
    const wrapEl = document.getElementById('phpclaw-chat-wrap');
    if (!wrapEl) { return; }

    function readJson(r) {
        var ct = r.headers.get('content-type') || '';
        if (ct.indexOf('application/json') === -1) {
            throw new Error(t('sessionExpired', 'Your session expired or you were signed out. Reload the page and sign in again.'));
        }
        if (!r.ok) {
            return r.json().then(function (d) {
                throw new Error((d && (d.error || d.message)) || t('unexpectedResponse', 'Unexpected server response (HTTP %s).').replace('%s', r.status));
            });
        }
        return r.json();
    }

    let currentConvId = wrapEl.dataset.initConvId || '';
    let isSending     = false;
    const apiUrl      = wrapEl.dataset.apiUrl || '';
    const streamUrl   = wrapEl.dataset.streamUrl || apiUrl.replace('task=api.send', 'task=api.stream');
    const loadUrl     = wrapEl.dataset.loadUrl || '';
    const csrfToken   = wrapEl.dataset.csrfToken || '';
    const settingsUrl = wrapEl.dataset.settingsUrl || '';

    let i18nStrings = {};
    try { i18nStrings = JSON.parse(wrapEl.dataset.i18n || '{}') || {}; } catch (e) { i18nStrings = {}; }
    function t(key, fallback) { return i18nStrings[key] || fallback || key; }

    const msgArea    = document.getElementById('phpclaw-messages');
    const inputEl    = document.getElementById('phpclaw-message');
    const sendBtn    = document.getElementById('phpclaw-send-btn');
    const chatHeader = document.getElementById('phpclaw-chat-header');
    const convList   = document.getElementById('phpclaw-conv-list');
    const searchEl   = document.getElementById('phpclaw-conv-search');
    const newChatBtn = document.getElementById('phpclaw-new-chat');
    const sidebar    = document.getElementById('phpclaw-sidebar');

    if (!msgArea) { return; }

    function escHtml(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    function scrollBottom() {
        msgArea.scrollTop = msgArea.scrollHeight;
    }

    function renderBubble(role, content) {
        const wrap = document.createElement('div');
        wrap.className = 'phpclaw-bubble-wrap ' + role;
        if (role === 'assistant') {
            const av = document.createElement('div');
            av.className = 'phpclaw-avatar';
            av.textContent = '\uD83E\uDD16';
            av.setAttribute('aria-hidden', 'true');
            wrap.appendChild(av);
        }
        const bubble = document.createElement('div');
        bubble.className = 'phpclaw-bubble ' + role;
        bubble.textContent = content;
        wrap.appendChild(bubble);
        msgArea.appendChild(wrap);
        bubble.setAttribute('tabindex', '-1');
        bubble.focus();
        return wrap;
    }

    function showTyping() {
        const wrap = document.createElement('div');
        wrap.className = 'phpclaw-bubble-wrap assistant';
        wrap.id = 'phpclaw-typing';
        const av = document.createElement('div');
        av.className = 'phpclaw-avatar';
        av.textContent = '\uD83E\uDD16';
        av.setAttribute('aria-hidden', 'true');
        wrap.appendChild(av);
        const bubble = document.createElement('div');
        bubble.className = 'phpclaw-bubble assistant phpclaw-typing';
        bubble.setAttribute('role', 'status');
        bubble.setAttribute('aria-label', 'Assistant is typing');
        bubble.innerHTML = '<span></span><span></span><span></span>';
        wrap.appendChild(bubble);
        msgArea.appendChild(wrap);
        msgArea.setAttribute('aria-busy', 'true');
        scrollBottom();
    }

    function removeTyping() {
        const el = document.getElementById('phpclaw-typing');
        if (el) { el.remove(); }
        msgArea.setAttribute('aria-busy', 'false');
    }

    function clearMessages(text) {
        msgArea.innerHTML = '';
        if (text) {
            const w = document.createElement('div');
            w.id = 'phpclaw-welcome';
            w.innerHTML = '<h2>' + escHtml(text) + '</h2>';
            msgArea.appendChild(w);
        }
    }

    function setActiveConv(convId) {
        document.querySelectorAll('.phpclaw-conv-item').forEach(function (el) {
            const isActive = el.dataset.convId === convId;
            el.classList.toggle('active', isActive);
            el.setAttribute('aria-current', isActive ? 'true' : 'false');
        });
    }

    function prependConvItem(convId, title) {
        const existing = document.querySelector('[data-conv-id="' + convId + '"]');
        if (existing) {
            convList.insertBefore(existing, convList.firstChild);
            return;
        }
        const empty = document.getElementById('phpclaw-empty-sidebar');
        if (empty) { empty.remove(); }

        const item = document.createElement('div');
        item.className = 'phpclaw-conv-item';
        item.setAttribute('role', 'listitem');
        item.setAttribute('tabindex', '0');
        item.dataset.convId = convId;
        item.dataset.title  = (title || '').toLowerCase();
        item.innerHTML = '<div class="phpclaw-conv-item-title">' + escHtml(title || t('untitled', 'New conversation')) + '</div>';
        item.addEventListener('click', function () { loadConversation(convId); });
        item.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); loadConversation(convId); }
        });
        convList.insertBefore(item, convList.firstChild);
    }

    function loadConversation(convId, force) {
        if (isSending) { return; }
        if (!force && convId === currentConvId && document.querySelectorAll('.phpclaw-bubble').length > 0) {
            return;
        }
        currentConvId = convId;
        setActiveConv(convId);
        clearMessages('');
        showTyping();

        const formData = new FormData();
        formData.append('conversation_id', convId);
        formData.append(csrfToken, '1');

        fetch(loadUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(readJson)
        .then(function (json) {
            removeTyping();
            clearMessages('');
            if (json.success) {
                const d = json.data;
                chatHeader.textContent = d.title || 'Conversation';
                if (!d.messages || d.messages.length === 0) {
                    clearMessages(t('noHistory', 'No message history for this conversation.'));
                } else {
                    d.messages.forEach(function (m) {
                        if (m.role === 'tool') {
                            renderToolCard(m.tool_name || '', m.tool_input || {}, m.tool_result || '');
                        } else {
                            renderBubble(m.role, m.content);
                        }
                    });
                }
                scrollBottom();
            } else {
                clearMessages(t('loadFailed', 'Could not load conversation.'));
            }
        })
        .catch(function () {
            removeTyping();
            clearMessages(t('loadError', 'Request failed.'));
        });
    }

    document.querySelectorAll('.phpclaw-conv-item').forEach(function (item) {
        item.setAttribute('tabindex', '0');
        item.setAttribute('role', 'listitem');
        item.addEventListener('click', function () { loadConversation(item.dataset.convId); });
        item.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); loadConversation(item.dataset.convId); }
        });
    });

    if (searchEl) {
        searchEl.addEventListener('input', function () {
            const q = searchEl.value.toLowerCase().trim();
            document.querySelectorAll('.phpclaw-conv-item').forEach(function (item) {
                const t = (item.dataset.title || '');
                item.style.display = (!q || t.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    if (newChatBtn) {
        newChatBtn.addEventListener('click', function () {
            if (isSending) { return; }
            currentConvId = '';
            setActiveConv('');
            if (chatHeader) { chatHeader.textContent = 'New Chat'; }
            clearMessages(t('emptyPrompt', 'Ask anything about your Joomla site.'));
            if (inputEl) { inputEl.focus(); }
        });
    }

    function sendMessage() {
        const msg = inputEl ? inputEl.value.trim() : '';
        if (!msg || !sendBtn || sendBtn.disabled || isSending) { return; }
        inputEl.value = '';
        autoResize();
        isSending = true;

        const welcome = document.getElementById('phpclaw-welcome');
        if (welcome) { welcome.remove(); }

        renderBubble('user', msg);
        scrollBottom();
        sendBtn.disabled = true;
        if (newChatBtn) { newChatBtn.disabled = true; newChatBtn.style.opacity = '0.5'; }
        if (sidebar) { sidebar.classList.add('sending'); }
        showTyping();

        const formData = new FormData();
        formData.append('message', msg);
        formData.append('conversation_id', currentConvId || '');
        formData.append(csrfToken, '1');

        const state = {
            assistantBubbleWrap: null,
            assistantBubble:     null,
            placeholderCards:    Object.create(null),
            sawDone:             false,
            sawChunk:            false,
            buffer:              '',
            typingRemoved:       false,
        };

        const finalize = function () {
            isSending = false;
            if (sendBtn) { sendBtn.disabled = false; }
            if (newChatBtn) { newChatBtn.disabled = false; newChatBtn.style.opacity = ''; }
            if (sidebar) { sidebar.classList.remove('sending'); }
            if (inputEl) { inputEl.focus(); }
        };

        const ensureTypingRemoved = function () {
            if (!state.typingRemoved) {
                removeTyping();
                state.typingRemoved = true;
            }
        };

        fetch(streamUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function (response) {
            if (!response.ok || !response.body) {
                throw new Error('HTTP ' + response.status);
            }
            ensureTypingRemoved();
            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');
            const pump = function () {
                return reader.read().then(function (r) {
                    if (r.done) {
                        if (state.buffer.length > 0) { drainFrames(state); }
                        finalize();
                        return;
                    }
                    state.buffer += decoder.decode(r.value, { stream: true });
                    drainFrames(state);
                    return pump();
                });
            };
            return pump();
        })
        .catch(function (err) {
            ensureTypingRemoved();
            renderErrorBubble(t('streamFailed') + ' ' + (err && err.message ? err.message : err));
            scrollBottom();
            finalize();
        });

        function drainFrames(s) {
            let idx;
            while ((idx = s.buffer.indexOf('\n\n')) !== -1) {
                const frame = s.buffer.substring(0, idx);
                s.buffer = s.buffer.substring(idx + 2);
                handleSseFrame(frame, s, msg);
            }
        }
    }

    function handleSseFrame(frame, state, originalMsg) {
        const lines = frame.split('\n');
        let event = 'message';
        let dataText = '';
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            if (line.indexOf('event:') === 0) {
                event = line.substring(6).trim();
            } else if (line.indexOf('data:') === 0) {
                dataText += (dataText ? '\n' : '') + line.substring(5).trim();
            }
        }
        if (!dataText) { return; }
        let payload;
        try { payload = JSON.parse(dataText); } catch (e) { return; }

        if (event === 'tool_before') {
            const key = (payload.tool_name || '') + ':' + JSON.stringify(payload.tool_input || {});
            const card = renderToolPlaceholder(payload.tool_name, payload.tool_input);
            state.placeholderCards[key] = card;
            scrollBottom();
            return;
        }
        if (event === 'tool_after') {
            const key = (payload.tool_name || '') + ':' + JSON.stringify(payload.tool_input || {});
            const ph = state.placeholderCards[key];
            if (ph && ph.parentNode) {
                ph.parentNode.removeChild(ph);
                delete state.placeholderCards[key];
            }
            renderToolCard(payload.tool_name, payload.tool_input || {}, payload.tool_result || '');
            scrollBottom();
            return;
        }
        if (event === 'chunk') {
            const chunkText = payload.text || '';
            if (chunkText !== '') {
                state.sawChunk = true;
                if (!state.assistantBubble) {
                    const built = createAssistantStreamBubble();
                    state.assistantBubbleWrap = built.wrap;
                    state.assistantBubble     = built.bubble;
                }
                state.assistantBubble.textContent = (state.assistantBubble.textContent || '') + chunkText;
                scrollBottom();
            }
            return;
        }
        if (event === 'done') {
            state.sawDone = true;
            const finalText = (payload.text || '').trim();
            if (!state.sawChunk && finalText !== '') {
                renderBubble('assistant', finalText);
            } else if (state.sawChunk && state.assistantBubble && finalText !== '') {
                state.assistantBubble.textContent = finalText;
            }

            Object.keys(state.placeholderCards).forEach(function (k) {
                const node = state.placeholderCards[k];
                if (node && node.parentNode) { node.parentNode.removeChild(node); }
            });
            state.placeholderCards = Object.create(null);

            const newId = payload.conversation_id || '';
            if (newId) {
                const isNew = (newId !== currentConvId);
                currentConvId = newId;
                const titleText = payload.title || (originalMsg.length > 60 ? originalMsg.substring(0, 60) + '\u2026' : originalMsg);
                if (isNew || (chatHeader && chatHeader.textContent === 'New Chat')) {
                    if (chatHeader) { chatHeader.textContent = titleText; }
                    prependConvItem(newId, titleText);
                } else {
                    prependConvItem(newId, chatHeader ? chatHeader.textContent : '');
                }
                setActiveConv(newId);
            }
            scrollBottom();
            return;
        }
        if (event === 'error') {
            renderErrorBubble(payload.message || t('unknownError'));
            scrollBottom();
            return;
        }
    }

    function createAssistantStreamBubble() {
        const wrap = document.createElement('div');
        wrap.className = 'phpclaw-bubble-wrap assistant';
        const av = document.createElement('div');
        av.className = 'phpclaw-avatar';
        av.textContent = '\ud83e\udd16';
        av.setAttribute('aria-hidden', 'true');
        wrap.appendChild(av);
        const bubble = document.createElement('div');
        bubble.className = 'phpclaw-bubble assistant';
        bubble.textContent = '';
        wrap.appendChild(bubble);
        msgArea.appendChild(wrap);
        return { wrap: wrap, bubble: bubble };
    }

    function renderToolPlaceholder(toolName, toolInput) {
        const wrap = document.createElement('div');
        wrap.className = 'pc-tool-card pc-tool-card--pending';

        const header = document.createElement('div');
        header.className = 'pc-tool-card__header';
        const icon = document.createElement('span');
        icon.className = 'pc-tool-card__icon pc-tool-card__icon--spin';
        icon.textContent = '\u27f3';
        icon.setAttribute('aria-hidden', 'true');
        const nameEl = document.createElement('span');
        nameEl.className = 'pc-tool-card__name';
        nameEl.textContent = String(toolName || 'tool');
        header.appendChild(icon);
        header.appendChild(nameEl);

        const inputSummary = formatToolInput(toolInput);
        if (inputSummary !== '') {
            const inputEl2 = document.createElement('span');
            inputEl2.className = 'pc-tool-card__input';
            inputEl2.textContent = inputSummary;
            header.appendChild(inputEl2);
        }
        wrap.appendChild(header);

        const body = document.createElement('div');
        body.className = 'pc-tool-card__body';
        const pending = document.createElement('div');
        pending.className = 'pc-tool-card__pending-text';
        pending.textContent = t('calling');
        body.appendChild(pending);
        wrap.appendChild(body);

        msgArea.appendChild(wrap);
        return wrap;
    }

    function renderErrorBubble(errMsg) {
        const wrap = document.createElement('div');
        wrap.className = 'phpclaw-bubble-wrap assistant';
        wrap.setAttribute('role', 'alert');
        const av = document.createElement('div');
        av.className = 'phpclaw-avatar';
        av.textContent = '\uD83E\uDD16';
        av.setAttribute('aria-hidden', 'true');
        wrap.appendChild(av);
        const bubble = document.createElement('div');
        bubble.className = 'phpclaw-bubble assistant';
        bubble.style.borderColor = '#f8d7da';
        bubble.style.background  = '#fff5f5';
        const l = errMsg.toLowerCase();
        if (l.indexOf('api key') !== -1 || l.indexOf('not configured') !== -1) {
            bubble.innerHTML = '\u26a0 ' + escHtml(t('notConfigured', 'phpClaw is not configured.'))
                + ' <a href="' + escHtml(settingsUrl) + '">' + escHtml(t('configureLink', 'Configure settings')) + '</a>';
        } else {
            bubble.textContent = '\u26a0 ' + errMsg;
        }
        wrap.appendChild(bubble);
        msgArea.appendChild(wrap);
    }

    if (sendBtn) { sendBtn.addEventListener('click', sendMessage); }
    if (inputEl) {
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
        });
        inputEl.addEventListener('input', autoResize);
    }

    function autoResize() {
        if (!inputEl) { return; }
        inputEl.style.height = 'auto';
        inputEl.style.height = Math.min(inputEl.scrollHeight, 160) + 'px';
    }

    function renderToolCard(toolName, toolInput, toolResultJson) {
        const wrap = document.createElement('div');
        wrap.className = 'pc-tool-card';

        const header = document.createElement('div');
        header.className = 'pc-tool-card__header';
        const icon = document.createElement('span');
        icon.className = 'pc-tool-card__icon';
        icon.textContent = '🔧';
        icon.setAttribute('aria-hidden', 'true');
        const nameEl = document.createElement('span');
        nameEl.className = 'pc-tool-card__name';
        nameEl.textContent = String(toolName || 'tool');
        header.appendChild(icon);
        header.appendChild(nameEl);

        const inputSummary = formatToolInput(toolInput);
        if (inputSummary !== '') {
            const inputEl2 = document.createElement('span');
            inputEl2.className = 'pc-tool-card__input';
            inputEl2.textContent = inputSummary;
            header.appendChild(inputEl2);
        }
        wrap.appendChild(header);

        let parsed = null;
        try { parsed = JSON.parse(toolResultJson || ''); } catch (e) { parsed = null; }

        const body = document.createElement('div');
        body.className = 'pc-tool-card__body';

        if (parsed && typeof parsed === 'object') {
            const envelope = unwrapEnvelope(parsed);
            if (envelope.error) {
                appendError(body, envelope.error);
            } else {
                renderTypedBody(body, String(toolName || ''), envelope.data, envelope.meta);
            }
            appendWarnings(body, envelope.warnings);
            appendStatsLine(body, envelope.meta);
        } else {
            const txt = document.createElement('div');
            txt.className = 'pc-tool-card__empty';
            txt.textContent = String(toolResultJson || '(no result)');
            body.appendChild(txt);
        }
        wrap.appendChild(body);

        if (toolResultJson) {
            wrap.appendChild(buildRawToggle(toolResultJson, parsed));
        }

        msgArea.appendChild(wrap);
        scrollBottom();
    }

    /* Every tool answers with {success, data, meta, warnings}. Reading rows off the
       envelope instead of off data finds nothing, so each typed renderer falls through
       to its empty state and a failure reads as an empty result. */
    function unwrapEnvelope(parsed) {
        const enveloped = typeof parsed.success === 'boolean'
            && Object.prototype.hasOwnProperty.call(parsed, 'data');

        if (!enveloped) {
            return { data: parsed, meta: parsed, warnings: [], error: null };
        }

        return {
            data: (parsed.data && typeof parsed.data === 'object') ? parsed.data : {},
            meta: (parsed.meta && typeof parsed.meta === 'object') ? parsed.meta : {},
            warnings: Array.isArray(parsed.warnings) ? parsed.warnings : [],
            error: parsed.success === false
                ? (parsed.error || { message: t('toolFailed') })
                : null,
        };
    }

    function appendError(container, error) {
        const box = document.createElement('div');
        box.className = 'pc-tool-card__error';
        const code = error.code ? String(error.code) + ': ' : '';
        box.textContent = code + String(error.message || t('toolFailed'));
        container.appendChild(box);
    }

    function appendWarnings(container, warnings) {
        if (!Array.isArray(warnings) || warnings.length === 0) { return; }
        warnings.forEach(function (warning) {
            const line = document.createElement('div');
            line.className = 'pc-tool-card__notice';
            const code = (warning && warning.code) ? String(warning.code) + ': ' : '';
            line.textContent = code + String((warning && warning.message) || warning);
            container.appendChild(line);
        });
    }

    function renderTypedBody(container, toolName, data, meta) {
        const mode = (meta && typeof meta.mode === 'string') ? meta.mode : '';
        if (mode === 'schema' || mode === 'aggregate') {
            return renderGeneric(container, data, meta);
        }
        const t = toolName.toLowerCase();
        if (t.indexOf('_articles')       !== -1) { return renderArticles(container, data); }
        if (t.indexOf('_users')          !== -1) { return renderUsers(container, data); }
        if (t.indexOf('_categories')     !== -1) { return renderCategories(container, data); }
        if (t.indexOf('_extensions')     !== -1) { return renderExtensions(container, data); }
        if (t.indexOf('_database_query') !== -1) { return renderSqlRows(container, data); }
        return renderGeneric(container, data, meta);
    }

    function renderArticles(container, data) {
        const rows = pickRows(data, 'articles');
        if (rows.length === 0) { return appendEmpty(container, t('noArticles')); }
        const ol = document.createElement('ol');
        ol.className = 'pc-tool-card__list';
        rows.forEach(function (a) {
            const li = document.createElement('li');
            li.className = 'pc-tool-card__item';

            const titleEl = document.createElement('div');
            titleEl.className = 'pc-tool-card__item-title';
            if (a.id) {
                const link = document.createElement('a');
                link.href = 'index.php?option=com_content&task=article.edit&id=' + encodeURIComponent(a.id);
                link.textContent = String(a.title || ('Article #' + a.id));
                link.target = '_blank';
                link.rel = 'noopener';
                titleEl.appendChild(link);
            } else {
                titleEl.textContent = String(a.title || '(untitled)');
            }
            li.appendChild(titleEl);

            const meta = document.createElement('div');
            meta.className = 'pc-tool-card__item-meta';
            if (a.state !== undefined) {
                meta.appendChild(buildBadge(stateLabel(a.state), stateClass(a.state)));
            }
            if (a.catid !== undefined) { meta.appendChild(buildChip(t('cat') + ' ' + a.catid)); }
            if (a.created) { meta.appendChild(buildChip(shortDate(a.created))); }
            if (a.hits !== undefined && a.hits !== 0) { meta.appendChild(buildChip(a.hits + ' ' + t('hits'))); }
            if (meta.childNodes.length > 0) { li.appendChild(meta); }
            ol.appendChild(li);
        });
        container.appendChild(ol);
    }

    function renderUsers(container, data) {
        const rows = pickRows(data, 'users');
        if (rows.length === 0) { return appendEmpty(container, t('noUsers')); }
        const tbl = buildTable([t('name'), t('email'), t('groups'), t('lastVisit'), t('status')]);
        const blockedBadge = '<span class="pc-badge pc-badge--err">' + escapeHtml(t('blocked')) + '</span>';
        const activeBadge  = '<span class="pc-badge pc-badge--ok">'  + escapeHtml(t('active'))  + '</span>';
        rows.forEach(function (u) {
            const tr = document.createElement('tr');
            const name = u.name || u.username || ('User #' + u.id);
            const link = u.id
                ? '<a href="index.php?option=com_users&task=user.edit&id=' + encodeURIComponent(u.id) + '" target="_blank" rel="noopener">' + escapeHtml(String(name)) + '</a>'
                : escapeHtml(String(name));
            tr.innerHTML =
                '<td>' + link + '</td>'
              + '<td>' + escapeHtml(String(u.email || '')) + '</td>'
              + '<td>' + escapeHtml(formatGroups(u.groups)) + '</td>'
              + '<td>' + escapeHtml(shortDate(u.lastvisitDate || u.last_visit || '')) + '</td>'
              + '<td>' + (u.block ? blockedBadge : activeBadge) + '</td>';
            tbl.tBodies[0].appendChild(tr);
        });
        container.appendChild(tbl);
    }

    function renderCategories(container, data) {
        const rows = pickRows(data, 'categories');
        if (rows.length === 0) { return appendEmpty(container, t('noCategories')); }
        const ol = document.createElement('ol');
        ol.className = 'pc-tool-card__list';
        rows.forEach(function (c) {
            const li = document.createElement('li');
            li.className = 'pc-tool-card__item';
            const titleEl = document.createElement('div');
            titleEl.className = 'pc-tool-card__item-title';
            titleEl.textContent = String(c.title || c.path || ('Category #' + c.id));
            li.appendChild(titleEl);
            const meta = document.createElement('div');
            meta.className = 'pc-tool-card__item-meta';
            if (c.extension) { meta.appendChild(buildChip(String(c.extension))); }
            if (c.level !== undefined) { meta.appendChild(buildChip(t('level') + ' ' + c.level)); }
            if (c.parent_id !== undefined && c.parent_id !== 0 && c.parent_id !== '0') {
                meta.appendChild(buildChip(t('parent') + ' ' + c.parent_id));
            }
            if (c.published !== undefined) {
                meta.appendChild(buildBadge(stateLabel(c.published), stateClass(c.published)));
            }
            if (meta.childNodes.length > 0) { li.appendChild(meta); }
            ol.appendChild(li);
        });
        container.appendChild(ol);
    }

    function renderExtensions(container, data) {
        const rows = pickRows(data, 'extensions');
        if (rows.length === 0) { return appendEmpty(container, t('noExtensions')); }
        const grouped = {};
        rows.forEach(function (e) {
            const type = String(e.type || 'other');
            if (!grouped[type]) { grouped[type] = []; }
            grouped[type].push(e);
        });
        Object.keys(grouped).sort().forEach(function (type) {
            const h = document.createElement('div');
            h.className = 'pc-tool-card__group-header';
            h.textContent = type + ' (' + grouped[type].length + ')';
            container.appendChild(h);
            const ol = document.createElement('ol');
            ol.className = 'pc-tool-card__list pc-tool-card__list--tight';
            grouped[type].forEach(function (e) {
                const li = document.createElement('li');
                li.className = 'pc-tool-card__item';
                const name = document.createElement('span');
                name.className = 'pc-tool-card__item-title';
                name.textContent = String(e.name || e.element || ('Ext #' + e.extension_id));
                li.appendChild(name);
                const meta = document.createElement('span');
                meta.className = 'pc-tool-card__item-meta';
                if (e.element) { meta.appendChild(buildChip(String(e.element))); }
                if (e.folder) { meta.appendChild(buildChip(String(e.folder))); }
                meta.appendChild(buildBadge(e.enabled ? t('enabled') : t('disabled'), e.enabled ? 'ok' : 'muted'));
                li.appendChild(meta);
                ol.appendChild(li);
            });
            container.appendChild(ol);
        });
    }

    function renderSqlRows(container, data) {
        const rows = pickRows(data, 'rows');
        if (rows.length === 0) { return appendEmpty(container, t('noRows')); }
        const cols = Object.keys(rows[0]);
        const tbl = buildTable(cols);
        rows.forEach(function (r) {
            const tr = document.createElement('tr');
            cols.forEach(function (k) {
                const td = document.createElement('td');
                const v = r[k];
                td.textContent = (v === null || v === undefined) ? '' : (typeof v === 'object' ? JSON.stringify(v) : String(v));
                tr.appendChild(td);
            });
            tbl.tBodies[0].appendChild(tr);
        });
        container.appendChild(tbl);
    }

    function renderGeneric(container, data, meta) {
        const mode = (meta && typeof meta.mode === 'string') ? meta.mode : '';
        if (mode === 'schema' || mode === 'aggregate' || data.aggregate === true || data.schema === true) {
            const dl = document.createElement('dl');
            dl.className = 'pc-tool-card__stats';
            const source = data.stats || data;
            Object.keys(source).forEach(function (k) {
                if (k === 'aggregate' || k === 'schema') { return; }
                const v = source[k];
                if (typeof v === 'object') { return; }
                const dt = document.createElement('dt'); dt.textContent = k;
                const dd = document.createElement('dd'); dd.textContent = String(v);
                dl.appendChild(dt); dl.appendChild(dd);
            });
            container.appendChild(dl);
            return;
        }
        const arrayKey = pickArrayKey(data);
        if (arrayKey && Array.isArray(data[arrayKey])) {
            if (data[arrayKey].length === 0) { return appendEmpty(container, t('noResults')); }
            const ol = document.createElement('ol');
            ol.className = 'pc-tool-card__list';
            data[arrayKey].forEach(function (row) {
                const li = document.createElement('li');
                li.textContent = labelRow(row);
                ol.appendChild(li);
            });
            container.appendChild(ol);
            return;
        }
        const pre = document.createElement('pre');
        pre.className = 'pc-tool-card__raw';
        pre.textContent = JSON.stringify(data, null, 2);
        container.appendChild(pre);
    }

    function appendStatsLine(container, data) {
        const parts = [];
        if (typeof data.count === 'number') { parts.push(data.count + ' ' + t('rows')); }
        if (data.has_more === true) { parts.push(t('moreAvailable')); }
        if (typeof data.limit === 'number' && data.limit > 0) { parts.push(t('limit') + ' ' + data.limit); }
        if (typeof data.offset === 'number' && data.offset > 0) { parts.push(t('offset') + ' ' + data.offset); }
        if (parts.length === 0) { return; }
        const line = document.createElement('div');
        line.className = 'pc-tool-card__stats-line';
        line.textContent = parts.join(' · ');
        container.appendChild(line);
    }

    function buildRawToggle(rawJson, parsed) {
        const wrap = document.createElement('details');
        wrap.className = 'pc-tool-card__raw-toggle';
        const sum = document.createElement('summary');
        sum.textContent = t('showRaw');
        wrap.appendChild(sum);
        const pre = document.createElement('pre');
        pre.className = 'pc-tool-card__raw';
        pre.textContent = parsed ? JSON.stringify(parsed, null, 2) : String(rawJson);
        wrap.appendChild(pre);
        return wrap;
    }

    function pickRows(data, primaryKey) {
        if (Array.isArray(data)) { return data; }
        if (Array.isArray(data[primaryKey])) { return data[primaryKey]; }
        const fallback = pickArrayKey(data);
        return fallback ? data[fallback] : [];
    }

    function buildTable(headers) {
        const tbl = document.createElement('table');
        tbl.className = 'pc-tool-card__table';
        const thead = document.createElement('thead');
        const trh = document.createElement('tr');
        headers.forEach(function (h) {
            const th = document.createElement('th');
            th.textContent = h;
            trh.appendChild(th);
        });
        thead.appendChild(trh);
        tbl.appendChild(thead);
        tbl.appendChild(document.createElement('tbody'));
        return tbl;
    }

    function buildChip(text) {
        const s = document.createElement('span');
        s.className = 'pc-chip';
        s.textContent = String(text);
        return s;
    }

    function buildBadge(text, kind) {
        const s = document.createElement('span');
        s.className = 'pc-badge pc-badge--' + (kind || 'muted');
        s.textContent = String(text);
        return s;
    }

    function appendEmpty(container, msg) {
        const e = document.createElement('div');
        e.className = 'pc-tool-card__empty';
        e.textContent = msg;
        container.appendChild(e);
    }

    function stateLabel(s) {
        if (s === 1 || s === '1' || s === true)  { return t('published'); }
        if (s === 0 || s === '0' || s === false) { return t('unpublished'); }
        if (s === -2 || s === '-2') { return t('trashed'); }
        if (s === 2 || s === '2')   { return t('archived'); }
        return String(s);
    }

    function stateClass(s) {
        if (s === 1 || s === '1' || s === true) { return 'ok'; }
        if (s === -2 || s === '-2') { return 'err'; }
        return 'muted';
    }

    function shortDate(s) {
        if (!s) { return ''; }
        const str = String(s);
        return str.length >= 10 ? str.substring(0, 10) : str;
    }

    function formatGroups(g) {
        if (!g) { return ''; }
        if (Array.isArray(g)) { return g.join(', '); }
        if (typeof g === 'object') { return Object.values(g).join(', '); }
        return String(g);
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function formatToolInput(input) {
        if (!input || typeof input !== 'object') { return ''; }
        const keys = Object.keys(input);
        if (keys.length === 0) { return ''; }
        const parts = keys.slice(0, 4).map(function (k) {
            const v = input[k];
            const vStr = (typeof v === 'object') ? JSON.stringify(v) : String(v);
            return k + '=' + vStr;
        });
        return parts.join(', ');
    }

    function pickArrayKey(obj) {
        const candidates = ['articles', 'users', 'categories', 'extensions', 'rows', 'items'];
        for (let i = 0; i < candidates.length; i++) {
            if (Array.isArray(obj[candidates[i]])) { return candidates[i]; }
        }
        return null;
    }

    function labelRow(row) {
        if (row === null || typeof row !== 'object') { return String(row); }
        if (row.title)     { return String(row.title); }
        if (row.name)      { return String(row.name); }
        if (row.username)  { return String(row.username); }
        if (row.element)   { return String(row.element); }
        return JSON.stringify(row);
    }

    scrollBottom();

    if (currentConvId !== '' && loadUrl !== '') {
        loadConversation(currentConvId, true);
    }
})();