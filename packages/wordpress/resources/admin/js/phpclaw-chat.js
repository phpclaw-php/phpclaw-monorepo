/* phpClaw Chat UI - loaded only on wp-admin → phpClaw → Chat */
/* jshint esversion: 6 */
(function () {
    function readJson(r) {
        var ct = r.headers.get('content-type') || '';
        if (ct.indexOf('application/json') === -1) {
            throw new Error('Your session expired or you were signed out. Reload the page and sign in again.');
        }
        if (!r.ok) {
            return r.json().then(function (d) {
                throw new Error((d && (d.error || (d.data && d.data.message) || d.message)) || 'Unexpected server response (HTTP ' + r.status + ').');
            });
        }
        return r.json();
    }

    var data        = window.phpClawChatData || {};
    var wrapEl      = document.getElementById('phpclaw-chat-wrap');
    var currentConvId = (wrapEl && wrapEl.dataset.initConvId) ? wrapEl.dataset.initConvId : '';
    var ajaxUrl     = data.ajaxUrl     || '';
    var nonceSend   = data.nonceSend   || '';
    var nonceStream = data.nonceStream || '';
    var nonceLoad   = data.nonceLoad   || '';
    var settingsUrl = data.settingsUrl || '';

    var msgArea    = document.getElementById('phpclaw-messages');
    var inputEl    = document.getElementById('phpclaw-message');
    var sendBtn    = document.getElementById('phpclaw-send-btn');
    var chatHeader = document.getElementById('phpclaw-chat-header');
    var convList   = document.getElementById('phpclaw-conv-list');
    var searchEl   = document.getElementById('phpclaw-conv-search');
    var newChatBtn = document.getElementById('phpclaw-new-chat');

    if (!msgArea) { return; }

    var isSending = false;

    function escHtml(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    function scrollBottom() {
        msgArea.scrollTop = msgArea.scrollHeight;
    }

    function renderBubble(role, content, appendTo) {
        var wrap = document.createElement('div');
        wrap.className = 'phpclaw-bubble-wrap ' + role;

        if (role === 'assistant') {
            var av = document.createElement('div');
            av.className = 'phpclaw-avatar';
            av.textContent = '🤖';
            av.setAttribute('aria-hidden', 'true');
            wrap.appendChild(av);
        }

        var bubble = document.createElement('div');
        bubble.className = 'phpclaw-bubble ' + role;
        bubble.textContent = content;
        wrap.appendChild(bubble);

        (appendTo || msgArea).appendChild(wrap);
        return wrap;
    }

    function showTyping() {
        var wrap = document.createElement('div');
        wrap.className = 'phpclaw-bubble-wrap assistant';
        wrap.id = 'phpclaw-typing';
        wrap.setAttribute('aria-label', 'AI is thinking');

        var av = document.createElement('div');
        av.className = 'phpclaw-avatar';
        av.textContent = '🤖';
        av.setAttribute('aria-hidden', 'true');
        wrap.appendChild(av);

        var bubble = document.createElement('div');
        bubble.className = 'phpclaw-bubble assistant phpclaw-typing';
        bubble.innerHTML = '<span></span><span></span><span></span>';
        wrap.appendChild(bubble);

        msgArea.appendChild(wrap);
        scrollBottom();
        return wrap;
    }

    function removeTyping() {
        var el = document.getElementById('phpclaw-typing');
        if (el) { el.remove(); }
    }

    function clearMessages(placeholderText) {
        msgArea.innerHTML = '';
        if (placeholderText) {
            var welcome = document.createElement('div');
            welcome.id = 'phpclaw-welcome';
            welcome.innerHTML = '<h2>New Chat</h2><p>' + escHtml(placeholderText) + '</p>';
            msgArea.appendChild(welcome);
        }
    }

    function setActiveConv(convId) {
        document.querySelectorAll('.phpclaw-conv-item').forEach(function (el) {
            var isActive = el.dataset.convId === convId;
            el.classList.toggle('active', isActive);
            el.setAttribute('aria-current', isActive ? 'true' : 'false');
        });
    }

    function prependConvItem(convId, title) {
        var existing = document.querySelector('[data-conv-id="' + convId + '"]');
        if (existing) {
            existing.querySelector('.phpclaw-conv-item-time').textContent = 'just now';
            convList.insertBefore(existing, convList.firstChild);
            return;
        }

        var emptyEl = document.getElementById('phpclaw-empty-sidebar');
        if (emptyEl) { emptyEl.remove(); }

        var item = document.createElement('div');
        item.className = 'phpclaw-conv-item';
        item.setAttribute('role', 'listitem');
        item.setAttribute('tabindex', '0');
        item.dataset.convId = convId;
        item.dataset.title  = (title || '').toLowerCase();
        item.innerHTML =
            '<div class="phpclaw-conv-item-title">' + escHtml(title || 'New conversation') + '</div>' +
            '<div class="phpclaw-conv-item-time">just now</div>';

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

        var formData = new FormData();
        formData.append('action',          'phpclaw_load_conversation');
        formData.append('nonce',           nonceLoad);
        formData.append('conversation_id', convId);

        fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(readJson)
        .then(function (json) {
            removeTyping();
            clearMessages('');
            if (json.success) {
                var d = json.data;
                chatHeader.textContent = d.title || 'Conversation';
                if (!d.messages || d.messages.length === 0) {
                    clearMessages('No message history for this conversation.');
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
                clearMessages('Could not load conversation: ' + (json.data ? json.data.message : 'Unknown error'));
            }
        })
        .catch(function (err) {
            removeTyping();
            clearMessages('Request failed: ' + err.message);
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
            var q = searchEl.value.toLowerCase().trim();
            document.querySelectorAll('.phpclaw-conv-item').forEach(function (item) {
                var t = (item.dataset.title || '');
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
            clearMessages('Ask anything about your WordPress site.');
            if (inputEl) { inputEl.focus(); }
        });
    }

    function sendMessage() {
        var msg = inputEl ? inputEl.value.trim() : '';
        if (!msg || !sendBtn || sendBtn.disabled || isSending) { return; }

        inputEl.value = '';
        autoResize();

        var welcome = document.getElementById('phpclaw-welcome');
        if (welcome) { welcome.remove(); }

        renderBubble('user', msg);
        scrollBottom();

        sendBtn.disabled = true;
        isSending = true;
        msgArea.setAttribute('aria-busy', 'true');
        if (convList) { convList.style.pointerEvents = 'none'; convList.style.opacity = '0.5'; }
        if (newChatBtn) { newChatBtn.disabled = true; newChatBtn.style.opacity = '0.5'; }
        showTyping();

        var formData = new FormData();
        formData.append('action',          'phpclaw_stream');
        formData.append('nonce',           nonceStream);
        formData.append('message',         msg);
        formData.append('conversation_id', currentConvId || '');

        var state = {
            assistantBubbleWrap: null,
            assistantBubble:     null,
            placeholderCards:    Object.create(null),
            sawDone:             false,
            sawChunk:            false,
            buffer:              '',
            typingRemoved:       false
        };

        var finalize = function () {
            isSending = false;
            msgArea.removeAttribute('aria-busy');
            if (sendBtn) { sendBtn.disabled = false; }
            if (convList) { convList.style.pointerEvents = ''; convList.style.opacity = ''; }
            if (newChatBtn) { newChatBtn.disabled = false; newChatBtn.style.opacity = ''; }
            if (inputEl) { inputEl.focus(); }
        };

        var ensureTypingRemoved = function () {
            if (!state.typingRemoved) { removeTyping(); state.typingRemoved = true; }
        };

        fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function (response) {
            if (!response.ok || !response.body) { throw new Error('HTTP ' + response.status); }
            ensureTypingRemoved();
            var reader  = response.body.getReader();
            var decoder = new TextDecoder('utf-8');
            var pump = function () {
                return reader.read().then(function (r) {
                    if (r.done) {
                        if (state.buffer.length > 0) { drainFrames(state, msg); }
                        finalize();
                        return;
                    }
                    state.buffer += decoder.decode(r.value, { stream: true });
                    drainFrames(state, msg);
                    return pump();
                });
            };
            return pump();
        })
        .catch(function (err) {
            ensureTypingRemoved();
            renderErrorBubble('Request failed: ' + (err && err.message ? err.message : err));
            scrollBottom();
            finalize();
        });
    }

    /* \u2500\u2500 SSE drain + frame dispatch \u2500\u2500 */
    function drainFrames(s, originalMsg) {
        var idx;
        while ((idx = s.buffer.indexOf('\n\n')) !== -1) {
            var frame = s.buffer.substring(0, idx);
            s.buffer  = s.buffer.substring(idx + 2);
            handleSseFrame(frame, s, originalMsg);
        }
    }

    function handleSseFrame(frame, state, originalMsg) {
        var lines = frame.split('\n');
        var event = 'message';
        var dataText = '';
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            if (line.indexOf('event:') === 0) {
                event = line.substring(6).trim();
            } else if (line.indexOf('data:') === 0) {
                dataText += (dataText ? '\n' : '') + line.substring(5).trim();
            }
        }
        if (!dataText) { return; }
        var payload;
        try { payload = JSON.parse(dataText); } catch (e) { return; }

        if (event === 'tool_before') {
            var keyB = (payload.tool_name || '') + ':' + JSON.stringify(payload.tool_input || {});
            var card = renderToolPlaceholder(payload.tool_name, payload.tool_input);
            state.placeholderCards[keyB] = card;
            scrollBottom();
            return;
        }
        if (event === 'tool_after') {
            var keyA = (payload.tool_name || '') + ':' + JSON.stringify(payload.tool_input || {});
            var ph = state.placeholderCards[keyA];
            if (ph && ph.parentNode) {
                ph.parentNode.removeChild(ph);
                delete state.placeholderCards[keyA];
            }
            renderToolCard(payload.tool_name, payload.tool_input || {}, payload.tool_result || '');
            scrollBottom();
            return;
        }
        if (event === 'chunk') {
            var chunkText = payload.text || '';
            if (chunkText !== '') {
                state.sawChunk = true;
                if (!state.assistantBubble) {
                    var built = createAssistantStreamBubble();
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
            var finalText = (payload.text || '').trim();
            if (!state.sawChunk && finalText !== '') {
                renderBubble('assistant', finalText);
            } else if (state.sawChunk && state.assistantBubble && finalText !== '') {
                state.assistantBubble.textContent = finalText;
            }

            Object.keys(state.placeholderCards).forEach(function (k) {
                var node = state.placeholderCards[k];
                if (node && node.parentNode) { node.parentNode.removeChild(node); }
            });
            state.placeholderCards = Object.create(null);

            var newId = payload.conversation_id || '';
            if (newId) {
                var isNew = (newId !== currentConvId);
                currentConvId = newId;
                var titleText = payload.title || (originalMsg.length > 60 ? originalMsg.substring(0, 60) + '\u2026' : originalMsg);
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
            renderErrorBubble(payload.message || 'Unknown error');
            scrollBottom();
            return;
        }
    }

    function createAssistantStreamBubble() {
        var wrap = document.createElement('div');
        wrap.className = 'phpclaw-bubble-wrap assistant';
        var av = document.createElement('div');
        av.className = 'phpclaw-avatar';
        av.textContent = '\ud83e\udd16';
        av.setAttribute('aria-hidden', 'true');
        wrap.appendChild(av);
        var bubble = document.createElement('div');
        bubble.className = 'phpclaw-bubble assistant';
        bubble.textContent = '';
        wrap.appendChild(bubble);
        msgArea.appendChild(wrap);
        return { wrap: wrap, bubble: bubble };
    }

    function renderToolPlaceholder(toolName, toolInput) {
        var wrap = document.createElement('div');
        wrap.className = 'pc-tool-card pc-tool-card--pending';

        var header = document.createElement('div');
        header.className = 'pc-tool-card__header';
        var icon = document.createElement('span');
        icon.className = 'pc-tool-card__icon pc-tool-card__icon--spin';
        icon.textContent = '\u27f3';
        icon.setAttribute('aria-hidden', 'true');
        var nameEl = document.createElement('span');
        nameEl.className = 'pc-tool-card__name';
        nameEl.textContent = String(toolName || 'tool');
        header.appendChild(icon);
        header.appendChild(nameEl);

        var inputSummary = formatToolInput(toolInput);
        if (inputSummary !== '') {
            var inputEl2 = document.createElement('span');
            inputEl2.className = 'pc-tool-card__input';
            inputEl2.textContent = inputSummary;
            header.appendChild(inputEl2);
        }
        wrap.appendChild(header);

        var body = document.createElement('div');
        body.className = 'pc-tool-card__body';
        var pending = document.createElement('div');
        pending.className = 'pc-tool-card__pending-text';
        pending.textContent = 'Calling\u2026';
        body.appendChild(pending);
        wrap.appendChild(body);

        msgArea.appendChild(wrap);
        return wrap;
    }

    function renderErrorBubble(errMsg) {
        var wrap = document.createElement('div');
        wrap.className = 'phpclaw-bubble-wrap assistant';
        wrap.setAttribute('role', 'alert');

        var av = document.createElement('div');
        av.className = 'phpclaw-avatar';
        av.textContent = '🤖';
        av.setAttribute('aria-hidden', 'true');
        wrap.appendChild(av);

        var bubble = document.createElement('div');
        bubble.className = 'phpclaw-bubble assistant';
        bubble.style.borderColor = '#f8d7da';
        bubble.style.background  = '#fff5f5';

        var isConfig = (function(m) {
            var l = m.toLowerCase();
            return l.indexOf('api key') !== -1 || l.indexOf('not configured') !== -1
                || l.indexOf('no provider') !== -1;
        })(errMsg);

        if (isConfig) {
            bubble.innerHTML = '\u26a0 phpClaw is not configured. <a href="' + escHtml(settingsUrl) + '" style="color:#2271b1;">Add your API key in Settings</a> \u2014 then try again.';
        } else {
            bubble.textContent = '\u26a0 ' + errMsg;
        }

        wrap.appendChild(bubble);
        msgArea.appendChild(wrap);
    }

    if (sendBtn) { sendBtn.addEventListener('click', sendMessage); }

    if (inputEl) {
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        inputEl.addEventListener('input', autoResize);
    }

    function autoResize() {
        if (!inputEl) { return; }
        inputEl.style.height = 'auto';
        inputEl.style.height = Math.min(inputEl.scrollHeight, 160) + 'px';
    }

    var adminUrl = (data.adminUrl || '').replace(/\/$/, '');

    function renderToolCard(toolName, toolInput, toolResultJson) {
        var wrap = document.createElement('div');
        wrap.className = 'pc-tool-card';

        var header = document.createElement('div');
        header.className = 'pc-tool-card__header';
        var icon = document.createElement('span');
        icon.className = 'pc-tool-card__icon';
        icon.textContent = '🔧';
        icon.setAttribute('aria-hidden', 'true');
        var nameEl = document.createElement('span');
        nameEl.className = 'pc-tool-card__name';
        nameEl.textContent = String(toolName || 'tool');
        header.appendChild(icon);
        header.appendChild(nameEl);

        var inputSummary = formatToolInput(toolInput);
        if (inputSummary !== '') {
            var inputEl2 = document.createElement('span');
            inputEl2.className = 'pc-tool-card__input';
            inputEl2.textContent = inputSummary;
            header.appendChild(inputEl2);
        }
        wrap.appendChild(header);

        var parsed = null;
        try { parsed = JSON.parse(toolResultJson || ''); } catch (e) { parsed = null; }

        var body = document.createElement('div');
        body.className = 'pc-tool-card__body';
        var supersedable = false;

        if (parsed && typeof parsed === 'object') {
            var envelope = unwrapEnvelope(parsed);
            supersedable = envelope.error !== null || hasIgnoredArgument(envelope.warnings);
            if (envelope.error) {
                appendError(body, envelope.error);
            } else {
                renderTypedBody(body, String(toolName || ''), envelope.data, envelope.meta);
            }
            appendWarnings(body, envelope.warnings);
            appendStatsLine(body, envelope.meta);
        } else {
            var empty = document.createElement('div');
            empty.className = 'pc-tool-card__empty';
            empty.textContent = String(toolResultJson || '(no result)');
            body.appendChild(empty);
        }
        wrap.appendChild(body);

        if (toolResultJson) {
            wrap.appendChild(buildRawToggle(toolResultJson, parsed));
        }

        wrap.setAttribute('data-pc-tool', String(toolName || ''));
        if (supersedable) {
            wrap.setAttribute('data-pc-superseded', '1');
        } else {
            dropSupersededCards(String(toolName || ''));
        }

        msgArea.appendChild(wrap);
        scrollBottom();
    }

    /* A call the agent immediately retried is loop noise, not a result the reader needs:
       it either errored outright, or ran with an argument the tool discarded, so it never
       answered what was asked. A call the agent never recovered from stays on screen, and
       so does any result merely truncated, clamped or redacted. */
    function hasIgnoredArgument(warnings) {
        if (!Array.isArray(warnings)) { return false; }
        for (var i = 0; i < warnings.length; i++) {
            if (warnings[i] && warnings[i].code === 'IGNORED_ARGUMENT') { return true; }
        }
        return false;
    }

    function dropSupersededCards(toolName) {
        if (toolName === '') { return; }
        var stale = msgArea.querySelectorAll('.pc-tool-card[data-pc-superseded="1"]');
        for (var i = 0; i < stale.length; i++) {
            if (stale[i].getAttribute('data-pc-tool') === toolName && stale[i].parentNode) {
                stale[i].parentNode.removeChild(stale[i]);
            }
        }
    }

    function renderTypedBody(container, toolName, data, meta) {
        var mode = (meta && typeof meta.mode === 'string') ? meta.mode : '';
        if (mode === 'schema' || mode === 'aggregate') {
            return renderGeneric(container, data, meta);
        }
        var n = toolName.toLowerCase();
        if (n.indexOf('wp_query')          !== -1) { return renderPosts(container, data); }
        if (n.indexOf('wp_users')          !== -1) { return renderUsersList(container, data); }
        if (n.indexOf('wp_taxonomy')       !== -1) { return renderTaxonomies(container, data); }
        if (n.indexOf('wp_plugins')        !== -1) { return renderPluginsList(container, data); }
        if (n.indexOf('database') !== -1 || n.indexOf('db_query') !== -1 || n === 'db' || n.indexOf('sql') !== -1) {
            return renderSqlRows(container, data);
        }
        if (n.indexOf('wp_comment')        !== -1) { return renderComments(container, data); }
        if (n.indexOf('wp_option')         !== -1) { return renderOptions(container, data); }
        if (n.indexOf('wp_menu')           !== -1) { return renderMenus(container, data); }
        if (n.indexOf('wp_media')          !== -1) { return renderMedia(container, data); }
        if (n.indexOf('wp_cron')           !== -1) { return renderCron(container, data); }
        return renderGeneric(container, data, meta);
    }

    function renderPosts(container, data) {
        var rows = pickRows(data, 'posts');
        if (rows.length === 0) { return appendEmpty(container, 'No posts.'); }
        var ol = document.createElement('ol');
        ol.className = 'pc-tool-card__list';
        rows.forEach(function (p) {
            var li = document.createElement('li');
            li.className = 'pc-tool-card__item';

            var titleEl = document.createElement('div');
            titleEl.className = 'pc-tool-card__item-title';
            var id = p.ID || p.id;
            var title = p.post_title || p.title || ('Post #' + id);
            if (id && adminUrl) {
                var link = document.createElement('a');
                link.href = adminUrl + '/post.php?action=edit&post=' + encodeURIComponent(id);
                link.textContent = String(title);
                link.target = '_blank';
                link.rel = 'noopener';
                titleEl.appendChild(link);
            } else {
                titleEl.textContent = String(title);
            }
            li.appendChild(titleEl);

            var meta = document.createElement('div');
            meta.className = 'pc-tool-card__item-meta';
            var status = p.post_status || p.status;
            if (status) { meta.appendChild(buildBadge(String(status), postStatusClass(status))); }
            var type = p.post_type || p.type;
            if (type) { meta.appendChild(buildChip(String(type))); }
            var date = p.post_date || p.date;
            if (date) { meta.appendChild(buildChip(shortDate(date))); }
            var author = p.post_author || p.author;
            if (author) { meta.appendChild(buildChip('author ' + author)); }
            if (meta.childNodes.length > 0) { li.appendChild(meta); }
            ol.appendChild(li);
        });
        container.appendChild(ol);
    }

    function renderUsersList(container, data) {
        var rows = pickRows(data, 'users');
        if (rows.length === 0) { return appendEmpty(container, 'No users.'); }
        var tbl = buildTable(['Name', 'Email', 'Role', 'Registered']);
        rows.forEach(function (u) {
            var tr = document.createElement('tr');
            var id   = u.ID || u.id;
            var name = u.display_name || u.user_login || u.name || ('User #' + id);
            var nameCell = (id && adminUrl)
                ? '<a href="' + escapeHtml(adminUrl + '/user-edit.php?user_id=' + id) + '" target="_blank" rel="noopener">' + escapeHtml(String(name)) + '</a>'
                : escapeHtml(String(name));
            tr.innerHTML =
                '<td>' + nameCell + '</td>'
              + '<td>' + escapeHtml(String(u.user_email || u.email || '')) + '</td>'
              + '<td>' + escapeHtml(formatRoles(u.roles || u.role)) + '</td>'
              + '<td>' + escapeHtml(shortDate(u.user_registered || u.registered || '')) + '</td>';
            tbl.tBodies[0].appendChild(tr);
        });
        container.appendChild(tbl);
    }

    function renderTaxonomies(container, data) {
        var rows = pickRows(data, 'terms');
        if (rows.length === 0) { rows = pickRows(data, 'taxonomies'); }
        if (rows.length === 0) { return appendEmpty(container, 'No taxonomies/terms.'); }
        var ol = document.createElement('ol');
        ol.className = 'pc-tool-card__list';
        rows.forEach(function (term) {
            var li = document.createElement('li');
            li.className = 'pc-tool-card__item';
            var titleEl = document.createElement('div');
            titleEl.className = 'pc-tool-card__item-title';
            titleEl.textContent = String(term.name || term.slug || term.taxonomy || ('Term #' + (term.term_id || term.id)));
            li.appendChild(titleEl);
            var meta = document.createElement('div');
            meta.className = 'pc-tool-card__item-meta';
            if (term.taxonomy) { meta.appendChild(buildChip(String(term.taxonomy))); }
            if (term.count !== undefined) { meta.appendChild(buildChip(term.count + ' posts')); }
            if (term.parent !== undefined && term.parent !== 0 && term.parent !== '0') {
                meta.appendChild(buildChip('parent ' + term.parent));
            }
            if (meta.childNodes.length > 0) { li.appendChild(meta); }
            ol.appendChild(li);
        });
        container.appendChild(ol);
    }

    function renderPluginsList(container, data) {
        var rows = pickRows(data, 'plugins');
        if (rows.length === 0) { return appendEmpty(container, 'No plugins.'); }
        var grouped = { active: [], inactive: [], 'must-use': [], dropins: [] };
        rows.forEach(function (p) {
            var key = p.status || (p.active ? 'active' : 'inactive');
            if (!grouped[key]) { grouped[key] = []; }
            grouped[key].push(p);
        });
        Object.keys(grouped).forEach(function (status) {
            if (!grouped[status].length) { return; }
            var h = document.createElement('div');
            h.className = 'pc-tool-card__group-header';
            h.textContent = status + ' (' + grouped[status].length + ')';
            container.appendChild(h);
            var ol = document.createElement('ol');
            ol.className = 'pc-tool-card__list pc-tool-card__list--tight';
            grouped[status].forEach(function (p) {
                var li = document.createElement('li');
                li.className = 'pc-tool-card__item';
                var name = document.createElement('span');
                name.className = 'pc-tool-card__item-title';
                name.textContent = String(p.Name || p.name || p.plugin || '(unnamed)');
                li.appendChild(name);
                var meta = document.createElement('span');
                meta.className = 'pc-tool-card__item-meta';
                if (p.Version || p.version) { meta.appendChild(buildChip('v' + (p.Version || p.version))); }
                if (p.Author || p.author)   { meta.appendChild(buildChip(String(p.Author || p.author))); }
                meta.appendChild(buildBadge(status, status === 'active' ? 'ok' : 'muted'));
                li.appendChild(meta);
                ol.appendChild(li);
            });
            container.appendChild(ol);
        });
    }

    function renderComments(container, data) {
        var rows = pickRows(data, 'comments');
        if (rows.length === 0) { return appendEmpty(container, 'No comments.'); }
        var ol = document.createElement('ol');
        ol.className = 'pc-tool-card__list';
        rows.forEach(function (c) {
            var li = document.createElement('li');
            li.className = 'pc-tool-card__item';
            var titleEl = document.createElement('div');
            titleEl.className = 'pc-tool-card__item-title';
            titleEl.textContent = String(c.comment_author || c.author || ('Comment #' + (c.comment_ID || c.id)));
            li.appendChild(titleEl);
            var snippet = document.createElement('div');
            snippet.textContent = String(c.comment_content || c.content || '').substring(0, 200);
            li.appendChild(snippet);
            var meta = document.createElement('div');
            meta.className = 'pc-tool-card__item-meta';
            if (c.comment_approved !== undefined || c.status) {
                var st = c.status || (c.comment_approved == 1 ? 'approved' : (c.comment_approved == 'spam' ? 'spam' : 'pending'));
                meta.appendChild(buildBadge(st, st === 'approved' ? 'ok' : (st === 'spam' || st === 'trash' ? 'err' : 'muted')));
            }
            if (c.comment_date || c.date) { meta.appendChild(buildChip(shortDate(c.comment_date || c.date))); }
            if (meta.childNodes.length > 0) { li.appendChild(meta); }
            ol.appendChild(li);
        });
        container.appendChild(ol);
    }

    function renderOptions(container, data) {
        var rows = pickRows(data, 'options');
        if (rows.length === 0 && data.option_name !== undefined) { rows = [data]; }
        if (rows.length === 0) { return appendEmpty(container, 'No options.'); }
        var tbl = buildTable(['Option', 'Value', 'Autoload']);
        rows.forEach(function (o) {
            var tr = document.createElement('tr');
            var v  = o.option_value !== undefined ? o.option_value : o.value;
            var vs = (v === null || v === undefined) ? '' : (typeof v === 'object' ? JSON.stringify(v) : String(v));
            if (vs.length > 200) { vs = vs.substring(0, 200) + '…'; }
            tr.innerHTML =
                '<td>' + escapeHtml(String(o.option_name || o.name || '')) + '</td>'
              + '<td>' + escapeHtml(vs) + '</td>'
              + '<td>' + escapeHtml(String(o.autoload || '')) + '</td>';
            tbl.tBodies[0].appendChild(tr);
        });
        container.appendChild(tbl);
    }

    function renderMenus(container, data) {
        var rows = pickRows(data, 'menus');
        if (rows.length === 0) { rows = pickRows(data, 'items'); }
        if (rows.length === 0) { return appendEmpty(container, 'No menus.'); }
        var ol = document.createElement('ol');
        ol.className = 'pc-tool-card__list';
        rows.forEach(function (m) {
            var li = document.createElement('li');
            li.className = 'pc-tool-card__item';
            var titleEl = document.createElement('div');
            titleEl.className = 'pc-tool-card__item-title';
            titleEl.textContent = String(m.name || m.title || ('Menu #' + (m.term_id || m.id)));
            li.appendChild(titleEl);
            var meta = document.createElement('div');
            meta.className = 'pc-tool-card__item-meta';
            if (m.count !== undefined) { meta.appendChild(buildChip(m.count + ' items')); }
            if (m.location) { meta.appendChild(buildChip(String(m.location))); }
            if (meta.childNodes.length > 0) { li.appendChild(meta); }
            ol.appendChild(li);
        });
        container.appendChild(ol);
    }

    function renderMedia(container, data) {
        var rows = pickRows(data, 'media');
        if (rows.length === 0) { rows = pickRows(data, 'attachments'); }
        if (rows.length === 0) { return appendEmpty(container, 'No media.'); }
        var ol = document.createElement('ol');
        ol.className = 'pc-tool-card__list';
        rows.forEach(function (a) {
            var li = document.createElement('li');
            li.className = 'pc-tool-card__item';
            var titleEl = document.createElement('div');
            titleEl.className = 'pc-tool-card__item-title';
            var id = a.ID || a.id;
            var title = a.post_title || a.title || a.filename || ('Attachment #' + id);
            if (id && adminUrl) {
                var link = document.createElement('a');
                link.href = adminUrl + '/post.php?action=edit&post=' + encodeURIComponent(id);
                link.textContent = String(title);
                link.target = '_blank';
                link.rel = 'noopener';
                titleEl.appendChild(link);
            } else {
                titleEl.textContent = String(title);
            }
            li.appendChild(titleEl);
            var meta = document.createElement('div');
            meta.className = 'pc-tool-card__item-meta';
            if (a.post_mime_type || a.mime_type) { meta.appendChild(buildChip(String(a.post_mime_type || a.mime_type))); }
            if (a.post_date || a.date) { meta.appendChild(buildChip(shortDate(a.post_date || a.date))); }
            if (meta.childNodes.length > 0) { li.appendChild(meta); }
            ol.appendChild(li);
        });
        container.appendChild(ol);
    }

    function renderCron(container, data) {
        var rows = pickRows(data, 'events');
        if (rows.length === 0) { rows = pickRows(data, 'cron'); }
        if (rows.length === 0) { return appendEmpty(container, 'No scheduled events.'); }
        var tbl = buildTable(['Hook', 'Next Run', 'Schedule']);
        rows.forEach(function (e) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + escapeHtml(String(e.hook || '')) + '</td>'
              + '<td>' + escapeHtml(String(e.next_run || e.timestamp || '')) + '</td>'
              + '<td>' + escapeHtml(String(e.schedule || e.recurrence || 'once')) + '</td>';
            tbl.tBodies[0].appendChild(tr);
        });
        container.appendChild(tbl);
    }

    function renderSqlRows(container, data) {
        var rows = pickRows(data, 'rows');
        if (rows.length === 0) { return appendEmpty(container, 'No rows.'); }
        var cols = Object.keys(rows[0]);
        var tbl = buildTable(cols);
        rows.forEach(function (r) {
            var tr = document.createElement('tr');
            cols.forEach(function (k) {
                var td = document.createElement('td');
                var v = r[k];
                td.textContent = (v === null || v === undefined) ? '' : (typeof v === 'object' ? JSON.stringify(v) : String(v));
                tr.appendChild(td);
            });
            tbl.tBodies[0].appendChild(tr);
        });
        container.appendChild(tbl);
    }

    function renderGeneric(container, data, meta) {
        var mode = (meta && typeof meta.mode === 'string') ? meta.mode : '';
        if (data.aggregate === true || data.schema === true || mode === 'aggregate' || mode === 'schema') {
            var dl = document.createElement('dl');
            dl.className = 'pc-tool-card__stats';
            var source = data.stats || data;
            Object.keys(source).forEach(function (k) {
                if (k === 'aggregate' || k === 'schema') { return; }
                appendStat(dl, k, source[k], 0);
            });
            container.appendChild(dl);
            return;
        }
        var arrayKey = pickArrayKey(data);
        if (arrayKey && Array.isArray(data[arrayKey])) {
            if (data[arrayKey].length === 0) { return appendEmpty(container, 'No results.'); }
            var ol = document.createElement('ol');
            ol.className = 'pc-tool-card__list';
            data[arrayKey].forEach(function (row) {
                var li = document.createElement('li');
                li.textContent = labelRow(row);
                ol.appendChild(li);
            });
            container.appendChild(ol);
            return;
        }
        var pre = document.createElement('pre');
        pre.className = 'pc-tool-card__raw';
        pre.textContent = JSON.stringify(data, null, 2);
        container.appendChild(pre);
    }

    function unwrapEnvelope(parsed) {
        var enveloped = typeof parsed.success === 'boolean'
            && Object.prototype.hasOwnProperty.call(parsed, 'data');

        if (!enveloped) {
            return { data: parsed, meta: parsed, warnings: [], error: null };
        }

        return {
            data: (parsed.data && typeof parsed.data === 'object') ? parsed.data : {},
            meta: (parsed.meta && typeof parsed.meta === 'object') ? parsed.meta : {},
            warnings: Array.isArray(parsed.warnings) ? parsed.warnings : [],
            error: parsed.success === false
                ? (parsed.error || { message: 'The tool reported a failure.' })
                : null
        };
    }

    function appendStat(dl, key, value, depth) {
        if (Array.isArray(value)) {
            addStatRow(dl, key, value.join(', '));
            return;
        }
        if (value && typeof value === 'object') {
            if (depth >= 2) { return; }
            Object.keys(value).forEach(function (sub) {
                appendStat(dl, key + '.' + sub, value[sub], depth + 1);
            });
            return;
        }
        if (value === null || value === undefined) { return; }
        addStatRow(dl, key, String(value));
    }

    function addStatRow(dl, key, value) {
        var dt = document.createElement('dt');
        dt.textContent = key;
        var dd = document.createElement('dd');
        dd.textContent = value;
        dl.appendChild(dt);
        dl.appendChild(dd);
    }

    function appendError(container, error) {
        var box = document.createElement('div');
        box.className = 'pc-tool-card__error';
        var code = error.code ? String(error.code) + ': ' : '';
        box.textContent = code + String(error.message || 'The tool reported a failure.');
        container.appendChild(box);
    }

    function appendWarnings(container, warnings) {
        if (!Array.isArray(warnings) || warnings.length === 0) { return; }
        warnings.forEach(function (warning) {
            var line = document.createElement('div');
            line.className = 'pc-tool-card__notice';
            var code = (warning && warning.code) ? String(warning.code) + ': ' : '';
            line.textContent = code + String((warning && warning.message) || warning);
            container.appendChild(line);
        });
    }

    function appendStatsLine(container, data) {
        var parts = [];
        if (typeof data.count === 'number') { parts.push(data.count + ' rows'); }
        if (data.has_more === true) { parts.push('more available'); }
        if (typeof data.limit  === 'number' && data.limit  > 0) { parts.push('limit '  + data.limit); }
        if (typeof data.offset === 'number' && data.offset > 0) { parts.push('offset ' + data.offset); }
        if (parts.length === 0) { return; }
        var line = document.createElement('div');
        line.className = 'pc-tool-card__stats-line';
        line.textContent = parts.join(' · ');
        container.appendChild(line);
    }

    function buildRawToggle(rawJson, parsed) {
        var wrap = document.createElement('details');
        wrap.className = 'pc-tool-card__raw-toggle';
        var sum = document.createElement('summary');
        sum.textContent = 'Show raw JSON';
        wrap.appendChild(sum);
        var pre = document.createElement('pre');
        pre.className = 'pc-tool-card__raw';
        pre.textContent = parsed ? JSON.stringify(parsed, null, 2) : String(rawJson);
        wrap.appendChild(pre);
        return wrap;
    }

    function pickRows(data, primaryKey) {
        if (Array.isArray(data)) { return data; }
        if (Array.isArray(data[primaryKey])) { return data[primaryKey]; }
        var fb = pickArrayKey(data);
        return fb ? data[fb] : [];
    }

    function pickArrayKey(obj) {
        var candidates = ['posts', 'users', 'terms', 'taxonomies', 'plugins', 'comments', 'options', 'menus', 'media', 'attachments', 'events', 'cron', 'lines', 'customers', 'tables', 'rows', 'items',
            'products', 'orders', 'coupons', 'categories', 'reviews', 'shipping_zones', 'tax_rates'];
        for (var i = 0; i < candidates.length; i++) {
            if (Array.isArray(obj[candidates[i]])) { return candidates[i]; }
        }
        return null;
    }

    function buildTable(headers) {
        var tbl = document.createElement('table');
        tbl.className = 'pc-tool-card__table';
        var thead = document.createElement('thead');
        var trh = document.createElement('tr');
        headers.forEach(function (h) {
            var th = document.createElement('th');
            th.textContent = h;
            trh.appendChild(th);
        });
        thead.appendChild(trh);
        tbl.appendChild(thead);
        tbl.appendChild(document.createElement('tbody'));
        return tbl;
    }

    function buildChip(text) {
        var s = document.createElement('span');
        s.className = 'pc-chip';
        s.textContent = String(text);
        return s;
    }

    function buildBadge(text, kind) {
        var s = document.createElement('span');
        s.className = 'pc-badge pc-badge--' + (kind || 'muted');
        s.textContent = String(text);
        return s;
    }

    function appendEmpty(container, msg) {
        var e = document.createElement('div');
        e.className = 'pc-tool-card__empty';
        e.textContent = msg;
        container.appendChild(e);
    }

    function postStatusClass(s) {
        var t = String(s).toLowerCase();
        if (t === 'publish' || t === 'published') { return 'ok'; }
        if (t === 'trash')   { return 'err'; }
        return 'muted';
    }

    function shortDate(s) {
        if (!s) { return ''; }
        var str = String(s);
        return str.length >= 10 ? str.substring(0, 10) : str;
    }

    function formatRoles(r) {
        if (!r) { return ''; }
        if (Array.isArray(r)) { return r.join(', '); }
        if (typeof r === 'object') { return Object.values(r).join(', '); }
        return String(r);
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function labelRow(row) {
        if (row === null || typeof row !== 'object') { return String(row); }

        var label = row.post_title || row.title || row.name || row.display_name
            || row.user_login || row.code || row.sku || '';

        if (label === '' && row.id !== undefined && row.id !== null) { label = '#' + row.id; }
        if (label === '') { return JSON.stringify(row); }

        var parts = [];
        if (row.sku && String(row.sku) !== String(label)) { parts.push(String(row.sku)); }
        if (row.status) { parts.push(String(row.status)); }

        /* A field the caller asked for is shown even when it is empty. Dropping it lets an unset
           value read as "not requested" rather than "not set", which is how a null sale price
           gets reported as a real one. */
        [['price', ''], ['total', ''], ['regular_price', 'regular '], ['sale_price', 'sale ']]
            .forEach(function (pair) {
                if (! Object.prototype.hasOwnProperty.call(row, pair[0])) { return; }
                var v = row[pair[0]];
                parts.push(pair[1] + ((v === null || v === undefined || v === '') ? '-' : String(v)));
            });

        if (Object.prototype.hasOwnProperty.call(row, 'stock_qty') && row.stock_qty !== null && row.stock_qty !== undefined) {
            parts.push(row.stock_qty + ' in stock');
        } else if (row.stock_status) {
            parts.push(String(row.stock_status));
        } else if (Object.prototype.hasOwnProperty.call(row, 'stock_qty')) {
            parts.push('stock -');
        }

        return parts.length === 0 ? String(label) : String(label) + ' - ' + parts.join(' · ');
    }

    function formatToolInput(input) {
        if (!input || typeof input !== 'object') { return ''; }
        var parts = [];
        for (var k in input) {
            if (!Object.prototype.hasOwnProperty.call(input, k)) { continue; }
            var v = input[k];
            if (typeof v === 'object') { v = JSON.stringify(v); }
            parts.push(k + '=' + v);
            if (parts.length >= 4) { break; }
        }
        return parts.join(', ');
    }

    scrollBottom();

    if (currentConvId !== '' && ajaxUrl !== '' && nonceLoad !== '') {
        loadConversation(currentConvId, true);
    }
})();
