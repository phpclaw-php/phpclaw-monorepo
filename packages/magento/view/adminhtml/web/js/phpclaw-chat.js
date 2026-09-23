(function () {
    'use strict';

    var cfgEl  = document.getElementById('pc-chat-config');
    var CONFIG = cfgEl ? JSON.parse(cfgEl.textContent || '{}') : {};

    var SEND_URL       = CONFIG.send_url || '';
    var STREAM_URL     = CONFIG.stream_url || '';
    var HISTORY_URL    = CONFIG.history_url || '';
    var CONVERSATIONS  = CONFIG.conversations || [];
    var ADMIN_URLS     = CONFIG.admin_urls || {};

    var conversationId = null;
    var currentRunId   = null;
    var isSending      = false;

    var messages    = document.getElementById('pc-messages');
    var textarea    = document.getElementById('pc-message-input');
    var sendBtn     = document.getElementById('pc-send-btn');
    var newChatBtn  = document.getElementById('pc-new-chat');
    var metaBar     = document.getElementById('pc-chat-meta');
    var metaText    = document.getElementById('pc-chat-meta-text');
    var chatList    = document.getElementById('pc-chat-list');

    if (!messages || !textarea || !sendBtn) return;

    function resizeTextarea() {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 160) + 'px';
    }
    textarea.addEventListener('input', resizeTextarea);


    function renderSidebar(conversations) {
        if (!conversations.length) {
            chatList.innerHTML = '<div class="pc-chat-placeholder">No conversations yet.</div>';
            return;
        }
        chatList.innerHTML = '';
        conversations.forEach(function (conv) {
            var el = document.createElement('div');
            el.className = 'pc-chat-list-item' + (conv.id === conversationId ? ' active' : '');
            el.dataset.id = conv.id;
            el.textContent = conv.title || conv.id.slice(0, 12) + '…';
            el.title = conv.created_at;
            el.addEventListener('click', function () {
                if (isSending) return;
                var clickedId = conv.id;
                conversationId = clickedId;
                messages.innerHTML = '<div class="pc-chat-placeholder">Loading…</div>';
                resetMeta();
                document.querySelectorAll('.pc-chat-list-item').forEach(function (i) { i.classList.remove('active'); });
                el.classList.add('active');
                fetch(HISTORY_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(phpclawReadJson)
                    .then(function (data) {
                        if (conversationId !== clickedId) return;
                        messages.innerHTML = '';
                        var found = (data.conversations || []).find(function (c) { return c.id === clickedId; });
                        ((found && found.messages) || []).forEach(function (m) {
                            if (m.role === 'tool') {
                                renderToolCard(m.tool_name || '', m.tool_input || {}, m.content || '');
                            } else if (m.role === 'user' || m.role === 'assistant') {
                                appendBubble(m.role, m.content || '');
                            }
                        });
                    })
                    .catch(function () {
                        if (conversationId !== clickedId) return;
                        messages.innerHTML = '';
                        (conv.messages || []).forEach(function (m) {
                            if (m.role === 'tool') {
                                renderToolCard(m.tool_name || '', m.tool_input || {}, m.content || '');
                            } else if (m.role === 'user' || m.role === 'assistant') {
                                appendBubble(m.role, m.content || '');
                            }
                        });
                    });
                textarea.focus();
            });
            chatList.appendChild(el);
        });
    }

    renderSidebar(CONVERSATIONS);


    var DISCLAIMER = 'Responses are AI-generated. Verify before acting.';

    function startNewChat() {
        if (isSending) return;
        conversationId = null;
        messages.innerHTML = '';
        resetMeta();
        document.querySelectorAll('.pc-chat-list-item').forEach(function (i) { i.classList.remove('active'); });
        textarea.focus();
    }

    function updateMeta(provider, model) {
        if (metaText) metaText.textContent = provider + ' · ' + model + ' · ' + DISCLAIMER;
    }
    function resetMeta() {
        if (metaText) metaText.textContent = DISCLAIMER;
    }

    if (newChatBtn) newChatBtn.addEventListener('click', startNewChat);


    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : '';
    }

    function phpclawReadJson(r) {
        var ct = r.headers.get('content-type') || '';
        if (ct.indexOf('application/json') === -1) {
            throw new Error('Your session expired or you were signed out. Reload the page and sign in again.');
        }
        if (!r.ok) {
            return r.json().then(function (d) {
                throw new Error((d && (d.error || d.message)) || 'Unexpected server response (HTTP ' + r.status + ').');
            });
        }
        return r.json();
    }


    function sendMessage() {
        var text = textarea.value.trim();
        if (!text || isSending) return;

        isSending = true;
        sendBtn.disabled = true;
        sendBtn.textContent = 'Sending…';
        textarea.disabled = true;
        if (messages) messages.setAttribute('aria-busy', 'true');
        if (chatList) { chatList.style.pointerEvents = 'none'; chatList.style.opacity = '0.5'; }
        if (newChatBtn) { newChatBtn.disabled = true; newChatBtn.style.opacity = '0.5'; }

        appendBubble('user', text);
        textarea.value = '';

        var typingEl       = appendTyping();
        var formKeyEl      = document.querySelector('input[name="form_key"]');
        var formKey        = formKeyEl ? formKeyEl.value : getCookie('form_key');
        var wasNew         = !conversationId;
        var pendingCards   = {};
        var streamingBubble = null;

        function finish() {
            isSending           = false;
            sendBtn.disabled    = false;
            sendBtn.textContent = 'Send';
            textarea.disabled   = false;
            if (messages) messages.removeAttribute('aria-busy');
            if (chatList) { chatList.style.pointerEvents = ''; chatList.style.opacity = ''; }
            if (newChatBtn) { newChatBtn.disabled = false; newChatBtn.style.opacity = ''; }
            textarea.focus();
        }

        fetch(STREAM_URL + '?form_key=' + encodeURIComponent(formKey), {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/event-stream' },
            body:    JSON.stringify({
                message:         text,
                conversation_id: conversationId,
            }),
        }).then(function (resp) {
            var ct = resp.headers.get('content-type') || '';
            if (!resp.ok || ct.indexOf('text/event-stream') === -1) {
                throw new Error('Your session expired or you were signed out. Reload the page and sign in again.');
            }
            if (!resp.body || !resp.body.getReader) {
                throw new Error('Streaming not supported by this browser.');
            }
            var reader  = resp.body.getReader();
            var decoder = new TextDecoder('utf-8');
            var buffer  = '';

            function pump() {
                return reader.read().then(function (r) {
                    if (r.done) { return; }
                    buffer += decoder.decode(r.value, { stream: true });
                    var idx;
                    while ((idx = buffer.indexOf('\n\n')) !== -1) {
                        var frame = buffer.slice(0, idx);
                        buffer    = buffer.slice(idx + 2);
                        dispatchFrame(frame);
                    }
                    return pump();
                });
            }
            return pump();
        }).then(function () {
            if (typingEl && typingEl.parentNode) { typingEl.remove(); }
            finish();
        }).catch(function (err) {
            if (typingEl && typingEl.parentNode) { typingEl.remove(); }
            appendBubble('error', 'Request failed: ' + err.message);
            finish();
        });

        function dispatchFrame(frame) {
            var ev = '';
            var dataLines = [];
            frame.split('\n').forEach(function (line) {
                if (line.indexOf('event:') === 0) { ev = line.slice(6).trim(); }
                else if (line.indexOf('data:') === 0) { dataLines.push(line.slice(5).trim()); }
            });
            if (!ev) { return; }
            var payload = {};
            try { payload = JSON.parse(dataLines.join('\n')); } catch (e) { payload = {}; }

            if (ev === 'tool_before') {
                if (typingEl && typingEl.parentNode) { typingEl.remove(); typingEl = null; }
                var key = (payload.tool_name || '') + '|' + Object.keys(pendingCards).length;
                pendingCards[key] = renderToolCardPending(payload.tool_name || '', payload.tool_input || {});
                pendingCards[key]._pendingKey = key;
                return;
            }
            if (ev === 'tool_after') {
                var firstKey = Object.keys(pendingCards)[0];
                if (firstKey) {
                    var ph = pendingCards[firstKey];
                    if (ph && ph.parentNode) { ph.parentNode.removeChild(ph); }
                    delete pendingCards[firstKey];
                }
                renderToolCard(payload.tool_name || '', payload.tool_input || {}, payload.tool_result || '');
                return;
            }
            if (ev === 'chunk') {
                if (!streamingBubble) {
                    if (typingEl && typingEl.parentNode) { typingEl.remove(); typingEl = null; }
                    streamingBubble = appendBubble('assistant', '');
                    streamingBubble._rawText = '';
                }
                streamingBubble._rawText += payload.text || '';
                streamingBubble.innerHTML = mdToHtml(streamingBubble._rawText);
                messages.scrollTop = messages.scrollHeight;
                return;
            }
            if (ev === 'done') {
                Object.keys(pendingCards).forEach(function (k) {
                    var ph = pendingCards[k];
                    if (ph && ph.parentNode) { ph.parentNode.removeChild(ph); }
                });
                pendingCards = {};
                conversationId = payload.conversation_id || conversationId;
                if (payload.run_id) { currentRunId = payload.run_id; }
                if (!streamingBubble) {
                    appendBubble('assistant', payload.text || '(no response)');
                } else {
                    streamingBubble.innerHTML = mdToHtml(streamingBubble._rawText || payload.text || '');
                }
                streamingBubble = null;
                if (payload.provider) { updateMeta(payload.provider, payload.model); }
                if (wasNew) {
                    var newConv = {
                        id:         conversationId,
                        title:      payload.title || text.slice(0, 80),
                        created_at: '',
                        messages:   []
                    };
                    CONVERSATIONS.unshift(newConv);
                    renderSidebar(CONVERSATIONS);
                    document.querySelectorAll('.pc-chat-list-item').forEach(function (i) {
                        if (i.dataset.id === conversationId) i.classList.add('active');
                    });
                }
                return;
            }
            if (ev === 'error') {
                appendBubble('error', payload.error || 'Unknown error.');
                return;
            }
        }
    }

    function renderToolCardPending(toolName, toolInput) {
        var wrap = document.createElement('div');
        wrap.className = 'pc-tool-card pc-tool-card--pending';

        var header = document.createElement('div');
        header.className = 'pc-tool-card__header';
        var icon = document.createElement('span');
        icon.className = 'pc-tool-card__icon pc-tool-card__icon--spin';
        icon.textContent = '⟳';
        icon.setAttribute('aria-hidden', 'true');
        header.appendChild(icon);
        var nameEl = document.createElement('span');
        nameEl.className = 'pc-tool-card__name';
        nameEl.textContent = String(toolName || 'tool');
        header.appendChild(nameEl);
        var inputSummary = formatToolInput(toolInput);
        if (inputSummary !== '') {
            var inputEl = document.createElement('span');
            inputEl.className = 'pc-tool-card__input';
            inputEl.textContent = inputSummary;
            header.appendChild(inputEl);
        }
        wrap.appendChild(header);

        var body = document.createElement('div');
        body.className = 'pc-tool-card__body';
        var pending = document.createElement('div');
        pending.className = 'pc-tool-card__pending-text';
        pending.textContent = 'Calling…';
        body.appendChild(pending);
        wrap.appendChild(body);

        messages.appendChild(wrap);
        messages.scrollTop = messages.scrollHeight;
        return wrap;
    }

    sendBtn.addEventListener('click', sendMessage);

    textarea.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    function mdToHtml(t) {
        var esc = function(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); };
        var blocks = [], i = 0, lines = t.split('\n'), out = '';
        while (i < lines.length) {
            if (lines[i].match(/^```/)) {
                var lang = lines[i].replace(/^```/, '').trim();
                i++;
                var code = [];
                while (i < lines.length && !lines[i].match(/^```/)) { code.push(lines[i]); i++; }
                i++;
                blocks.push('<pre class="pc-code-block"><code>' + esc(code.join('\n')) + '</code></pre>');
            } else {
                blocks.push(lines[i]);
                i++;
            }
        }
        out = blocks.join('\n');
        out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        out = out.replace(/`([^`]+)`/g, '<code class="pc-inline-code">$1</code>');
        out = out.replace(/\n/g, '<br>');
        return out;
    }

    function appendBubble(role, text) {
        var wrap = document.createElement('div');
        wrap.className = 'pc-chat-bubble-wrap ' + role;
        if (role === 'assistant') {
            var av = document.createElement('div');
            av.className = 'phpclaw-avatar';
            av.textContent = '🤖';
            wrap.appendChild(av);
        }
        var el = document.createElement('div');
        el.className = 'pc-chat-bubble ' + role;
        if (role === 'assistant' && text) {
            el.innerHTML = mdToHtml(text);
        } else {
            el.textContent = text;
        }
        wrap.appendChild(el);
        messages.appendChild(wrap);
        messages.scrollTop = messages.scrollHeight;
        return el;
    }

    function appendTyping() {
        var wrap = document.createElement('div');
        wrap.className = 'pc-chat-bubble-wrap assistant';
        wrap.id = 'pc-typing-wrap';
        var av = document.createElement('div');
        av.className = 'phpclaw-avatar';
        av.textContent = '🤖';
        wrap.appendChild(av);
        var el = document.createElement('div');
        el.className = 'pc-chat-bubble assistant pc-typing';
        el.innerHTML = '<div class="pc-typing-dots"><span class="pc-typing-dot"></span><span class="pc-typing-dot"></span><span class="pc-typing-dot"></span></div>';
        wrap.appendChild(el);
        messages.appendChild(wrap);
        messages.scrollTop = messages.scrollHeight;
        return wrap;
    }

    function renderToolCard(toolName, toolInput, toolResultJson) {
        var wrap = document.createElement('div');
        wrap.className = 'pc-tool-card';

        var header = document.createElement('div');
        header.className = 'pc-tool-card__header';

        var icon = document.createElement('span');
        icon.className = 'pc-tool-card__icon';
        icon.textContent = '🔧';
        icon.setAttribute('aria-hidden', 'true');
        header.appendChild(icon);

        var nameEl = document.createElement('span');
        nameEl.className = 'pc-tool-card__name';
        nameEl.textContent = String(toolName || 'tool');
        header.appendChild(nameEl);

        var inputSummary = formatToolInput(toolInput);
        if (inputSummary !== '') {
            var inputEl = document.createElement('span');
            inputEl.className = 'pc-tool-card__input';
            inputEl.textContent = inputSummary;
            header.appendChild(inputEl);
        }
        wrap.appendChild(header);

        var body = document.createElement('div');
        body.className = 'pc-tool-card__body';

        var parsed = null;
        try { parsed = JSON.parse(String(toolResultJson || '')); } catch (e) { parsed = null; }

        if (parsed && typeof parsed === 'object') {
            if (parsed.success === false) {
                appendEmpty(body, errorText(parsed));
            } else {
                renderTypedBody(body, String(toolName || ''), payloadOf(parsed));
                appendStatsLine(body, statsOf(parsed));
            }
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

        messages.appendChild(wrap);
        messages.scrollTop = messages.scrollHeight;
    }

    var PRIMARY_KEY = {
        magento_products: 'products',
        magento_customer: 'customers',
        magento_categories: 'categories',
        magento_orders: 'orders',
        magento_inventory: 'items',
        magento_stores: 'stores',
        db_query: 'rows',
        read_log: 'lines'
    };

    var SINGULAR_KEY = {
        products: 'product',
        customers: 'customer',
        categories: 'category',
        orders: 'order',
        items: 'item',
        stores: 'store',
        rows: 'row',
        lines: 'line'
    };

    function renderTypedBody(container, toolName, data) {
        var t = String(toolName || '').toLowerCase();
        var key = PRIMARY_KEY[t];

        if (key && pickRows(data, key).length === 0 && isRecordLike(data)) {
            return renderGeneric(container, data);
        }

        if (t === 'magento_products')   { return renderProducts(container, data); }
        if (t === 'magento_customer')   { return renderCustomers(container, data); }
        if (t === 'magento_categories') { return renderCategories(container, data); }
        if (t === 'magento_orders')     { return renderOrders(container, data); }
        if (t === 'magento_inventory')  { return renderInventory(container, data); }
        if (t === 'magento_stores')     { return renderStores(container, data); }
        if (t === 'db_query')           { return renderSqlRows(container, data); }
        if (t === 'read_log')           { return renderLogLines(container, data); }
        return renderGeneric(container, data);
    }

    function renderProducts(container, data) {
        var rows = pickRows(data, 'products');
        if (rows.length === 0) { return appendEmpty(container, 'No products.'); }
        var ol = document.createElement('ol');
        ol.className = 'pc-tool-card__list';
        rows.forEach(function (p) {
            var li = document.createElement('li');
            li.className = 'pc-tool-card__item';

            var title = document.createElement('div');
            title.className = 'pc-tool-card__item-title';
            var pid = p.entity_id || p.id || p.product_id;
            var pname = p.name || p.sku || ('Product #' + (pid || ''));
            if (pid && ADMIN_URLS.product_edit) {
                var a = document.createElement('a');
                a.href = ADMIN_URLS.product_edit.replace('__ID__', encodeURIComponent(pid));
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = String(pname);
                title.appendChild(a);
            } else {
                title.textContent = String(pname);
            }
            li.appendChild(title);

            var meta = document.createElement('div');
            meta.className = 'pc-tool-card__item-meta';
            if (p.sku)          { meta.appendChild(buildChip('SKU: ' + p.sku)); }
            if (p.price !== undefined && p.price !== null) {
                meta.appendChild(buildChip('$' + Number(p.price).toFixed(2)));
            }
            if (p.qty !== undefined && p.qty !== null) {
                var qty = Number(p.qty);
                meta.appendChild(buildBadge(qty + ' in stock', qty > 0 ? 'ok' : 'err'));
            }
            if (p.type_id) { meta.appendChild(buildChip(String(p.type_id))); }
            if (p.status !== undefined) {
                meta.appendChild(buildBadge(p.status == 1 ? 'enabled' : 'disabled', p.status == 1 ? 'ok' : 'muted'));
            }
            if (meta.childNodes.length > 0) { li.appendChild(meta); }
            ol.appendChild(li);
        });
        container.appendChild(ol);
    }

    function renderCustomers(container, data) {
        var rows = pickRows(data, 'customers');
        if (rows.length === 0 && data.entity_id) { rows = [data]; }
        if (rows.length === 0) { return appendEmpty(container, 'No customers.'); }
        var tbl = buildTable(['Name', 'Email', 'Group', 'Created', 'Status']);
        rows.forEach(function (c) {
            var tr = document.createElement('tr');
            var cid = c.entity_id || c.id;
            var nm = (c.firstname || '') + ' ' + (c.lastname || '');
            nm = nm.trim() || c.email || ('Customer #' + (cid || ''));
            var nameCell = cid && ADMIN_URLS.customer_edit
                ? '<a href="' + escapeHtmlAttr(ADMIN_URLS.customer_edit.replace('__ID__', encodeURIComponent(cid))) + '" target="_blank" rel="noopener">' + escapeHtml(nm) + '</a>'
                : escapeHtml(nm);
            var statusBadge = (c.is_active == 1 || c.is_active === true || c.is_active === undefined)
                ? '<span class="pc-badge pc-badge--ok">active</span>'
                : '<span class="pc-badge pc-badge--muted">inactive</span>';
            tr.innerHTML =
                '<td>' + nameCell + '</td>' +
                '<td>' + escapeHtml(String(c.email || '')) + '</td>' +
                '<td>' + escapeHtml(String(c.group_id || '')) + '</td>' +
                '<td>' + escapeHtml(shortDate(c.created_at || '')) + '</td>' +
                '<td>' + statusBadge + '</td>';
            tbl.tBodies[0].appendChild(tr);
        });
        container.appendChild(tbl);
    }

    function renderCategories(container, data) {
        var rows = pickRows(data, 'categories');
        if (rows.length === 0) { return appendEmpty(container, 'No categories.'); }
        var ol = document.createElement('ol');
        ol.className = 'pc-tool-card__list';
        rows.forEach(function (c) {
            var li = document.createElement('li');
            li.className = 'pc-tool-card__item';
            var title = document.createElement('div');
            title.className = 'pc-tool-card__item-title';
            var cid = c.entity_id || c.id;
            var cname = c.name || c.path || ('Category #' + (cid || ''));
            if (cid && ADMIN_URLS.category_edit) {
                var a = document.createElement('a');
                a.href = ADMIN_URLS.category_edit.replace('__ID__', encodeURIComponent(cid));
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = String(cname);
                title.appendChild(a);
            } else {
                title.textContent = String(cname);
            }
            li.appendChild(title);
            var meta = document.createElement('div');
            meta.className = 'pc-tool-card__item-meta';
            if (c.level !== undefined)        { meta.appendChild(buildChip('level ' + c.level)); }
            if (c.children_count !== undefined && c.children_count > 0) {
                meta.appendChild(buildChip(c.children_count + ' child'));
            }
            if (c.is_active !== undefined) {
                meta.appendChild(buildBadge(c.is_active == 1 ? 'active' : 'inactive', c.is_active == 1 ? 'ok' : 'muted'));
            }
            if (meta.childNodes.length > 0) { li.appendChild(meta); }
            ol.appendChild(li);
        });
        container.appendChild(ol);
    }

    function renderOrders(container, data) {
        var rows = pickRows(data, 'orders');
        if (rows.length === 0 && data.entity_id) { rows = [data]; }
        if (rows.length === 0) { return appendEmpty(container, 'No orders.'); }
        var ol = document.createElement('ol');
        ol.className = 'pc-tool-card__list';
        rows.forEach(function (o) {
            var li = document.createElement('li');
            li.className = 'pc-tool-card__item';
            var title = document.createElement('div');
            title.className = 'pc-tool-card__item-title';
            var oid = o.entity_id || o.order_id || o.id;
            var label = o.increment_id ? '#' + o.increment_id : ('Order #' + (oid || ''));
            if (oid && ADMIN_URLS.order_view) {
                var a = document.createElement('a');
                a.href = ADMIN_URLS.order_view.replace('__ID__', encodeURIComponent(oid));
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = label;
                title.appendChild(a);
            } else {
                title.textContent = label;
            }
            li.appendChild(title);
            var meta = document.createElement('div');
            meta.className = 'pc-tool-card__item-meta';
            if (o.status)                    { meta.appendChild(buildBadge(String(o.status), o.status === 'complete' ? 'ok' : 'muted')); }
            if (o.grand_total !== undefined) { meta.appendChild(buildChip('$' + Number(o.grand_total).toFixed(2))); }
            if (o.customer_email)            { meta.appendChild(buildChip(String(o.customer_email))); }
            if (o.created_at)                { meta.appendChild(buildChip(shortDate(o.created_at))); }
            if (meta.childNodes.length > 0) { li.appendChild(meta); }
            ol.appendChild(li);
        });
        container.appendChild(ol);
    }

    function renderInventory(container, data) {
        var rows = pickRows(data, 'items');
        if (rows.length === 0) { rows = pickRows(data, 'low_stock'); }
        if (rows.length === 0) { return appendEmpty(container, 'No inventory items.'); }
        var tbl = buildTable(['SKU', 'Qty', 'Status']);
        rows.forEach(function (it) {
            var qty = Number(it.qty || it.quantity || 0);
            var stockBadge = qty > 0
                ? '<span class="pc-badge pc-badge--ok">in stock</span>'
                : '<span class="pc-badge pc-badge--err">out of stock</span>';
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + escapeHtml(String(it.sku || '')) + '</td>' +
                '<td>' + escapeHtml(String(qty)) + '</td>' +
                '<td>' + stockBadge + '</td>';
            tbl.tBodies[0].appendChild(tr);
        });
        container.appendChild(tbl);
    }

    function renderStores(container, data) {
        var rows = pickRows(data, 'stores');
        if (rows.length === 0) { rows = pickRows(data, 'websites'); }
        if (rows.length === 0) { return appendEmpty(container, 'No stores.'); }

        var ol = document.createElement('ol');
        ol.className = 'pc-tool-card__list';

        rows.forEach(function (s) {
            var li = document.createElement('li');
            li.className = 'pc-tool-card__item';

            var title = document.createElement('span');
            title.className = 'pc-tool-card__item-title';
            title.textContent = String(
                s.website_name || s.name || s.store_name || s.group_name
                || s.website_code || s.code
                || ('Store #' + (s.store_id || s.website_id || s.id || '?'))
            );
            li.appendChild(title);

            var meta = document.createElement('span');
            meta.className = 'pc-tool-card__item-meta';
            var code = s.website_code || s.code || s.store_code;
            if (code)                          { meta.appendChild(buildChip(String(code))); }
            if (s.website_is_default === true) { meta.appendChild(buildBadge('default', 'ok')); }
            if (s.is_active !== undefined)     { meta.appendChild(buildBadge(s.is_active ? 'active' : 'inactive', s.is_active ? 'ok' : 'muted')); }
            li.appendChild(meta);

            appendStoreGroups(li, s.store_groups);
            ol.appendChild(li);
        });

        container.appendChild(ol);
    }

    function appendStoreGroups(parent, groups) {
        if (!Array.isArray(groups) || groups.length === 0) { return; }

        var gl = document.createElement('ul');
        gl.className = 'pc-tool-card__list';

        groups.forEach(function (g) {
            var gli = document.createElement('li');
            gli.className = 'pc-tool-card__item';

            var gt = document.createElement('span');
            gt.className = 'pc-tool-card__item-title';
            gt.textContent = String(g.group_name || g.name || ('Group #' + (g.group_id || '?')));
            gli.appendChild(gt);

            var gm = document.createElement('span');
            gm.className = 'pc-tool-card__item-meta';
            if (g.group_code)       { gm.appendChild(buildChip(String(g.group_code))); }
            if (g.root_category_id) { gm.appendChild(buildChip('root cat ' + g.root_category_id)); }
            gli.appendChild(gm);

            appendStoreViews(gli, g.store_views);
            gl.appendChild(gli);
        });

        parent.appendChild(gl);
    }

    function appendStoreViews(parent, views) {
        if (!Array.isArray(views) || views.length === 0) { return; }

        var vl = document.createElement('ul');
        vl.className = 'pc-tool-card__list';

        views.forEach(function (v) {
            var vli = document.createElement('li');
            vli.className = 'pc-tool-card__item';

            var vt = document.createElement('span');
            vt.className = 'pc-tool-card__item-title';
            vt.textContent = String(v.store_name || v.name || ('View #' + (v.store_id || '?')));
            vli.appendChild(vt);

            var vm = document.createElement('span');
            vm.className = 'pc-tool-card__item-meta';
            if (v.store_code)            { vm.appendChild(buildChip(String(v.store_code))); }
            if (v.is_active !== undefined) { vm.appendChild(buildBadge(v.is_active ? 'active' : 'inactive', v.is_active ? 'ok' : 'muted')); }
            vli.appendChild(vm);

            vl.appendChild(vli);
        });

        parent.appendChild(vl);
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

    function renderLogLines(container, data) {
        var lines = data.lines || data.entries || data.tail;
        if (!Array.isArray(lines) || lines.length === 0) {
            return appendEmpty(container, 'No log lines.');
        }
        var pre = document.createElement('pre');
        pre.className = 'pc-tool-card__raw';
        pre.textContent = lines.join('\n');
        container.appendChild(pre);
    }

    function renderGeneric(container, data) {
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

    function appendStatsLine(container, data) {
        var parts = [];
        if (typeof data.count === 'number')                       { parts.push(data.count + ' rows'); }
        if (typeof data.shown === 'number' && typeof data.total === 'number' && data.total !== data.shown) {
            parts.push(data.shown + ' of ' + data.total + ' rows');
        } else if (typeof data.total === 'number' && typeof data.count !== 'number') {
            parts.push(data.total + ' rows');
        }
        if (data.truncated === true)                              { parts.push('truncated'); }
        if (data.has_more === true)                               { parts.push('more available'); }
        if (typeof data.limit === 'number' && data.limit > 0)     { parts.push('limit ' + data.limit); }
        if (typeof data.offset === 'number' && data.offset > 0)   { parts.push('offset ' + data.offset); }
        if (parts.length === 0) { return; }
        var line = document.createElement('div');
        line.className = 'pc-tool-card__stats-line';
        line.textContent = parts.join(' · ');
        container.appendChild(line);
    }

    function buildRawToggle(rawJson, parsed) {
        var det = document.createElement('details');
        det.className = 'pc-tool-card__raw-toggle';
        var sum = document.createElement('summary');
        sum.textContent = 'Show raw JSON';
        det.appendChild(sum);
        var pre = document.createElement('pre');
        pre.className = 'pc-tool-card__raw';
        pre.textContent = parsed ? JSON.stringify(parsed, null, 2) : String(rawJson);
        det.appendChild(pre);
        return det;
    }

    function payloadOf(parsed) {
        if (parsed && typeof parsed === 'object'
            && Object.prototype.hasOwnProperty.call(parsed, 'success')
            && parsed.data && typeof parsed.data === 'object') {
            return parsed.data;
        }

        return parsed;
    }

    function statsOf(parsed) {
        if (parsed && typeof parsed === 'object' && parsed.meta && typeof parsed.meta === 'object') {
            return parsed.meta;
        }

        return parsed;
    }

    function errorText(parsed) {
        var err = parsed.error || {};
        var code = err.code ? String(err.code) : 'ERROR';
        var msg = err.message ? String(err.message) : 'The tool returned an error.';

        return code + ': ' + msg;
    }

    function pickRows(data, primaryKey) {
        if (Array.isArray(data)) { return data; }
        if (!data || typeof data !== 'object') { return []; }
        if (Array.isArray(data[primaryKey])) { return data[primaryKey]; }

        var singular = SINGULAR_KEY[primaryKey];
        if (singular && data[singular] && typeof data[singular] === 'object' && !Array.isArray(data[singular])) {
            return [data[singular]];
        }

        var fallback = pickArrayKey(data);
        return fallback ? data[fallback] : [];
    }

    function isRecordLike(data) {
        if (!data || typeof data !== 'object' || Array.isArray(data)) { return false; }

        return Object.keys(data).length > 0;
    }

    function pickArrayKey(obj) {
        var candidates = ['products', 'customers', 'categories', 'orders', 'items', 'stores', 'websites', 'rows', 'lines'];
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

    function shortDate(s) {
        if (!s) { return ''; }
        var str = String(s);
        return str.length >= 10 ? str.substring(0, 10) : str;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function escapeHtmlAttr(s) {
        return escapeHtml(s);
    }

    function labelRow(row) {
        if (row === null || typeof row !== 'object') { return String(row); }
        if (row.name)          { return String(row.name); }
        if (row.sku)           { return String(row.sku); }
        if (row.title)         { return String(row.title); }
        if (row.email)         { return String(row.email); }
        if (row.increment_id)  { return String(row.increment_id); }
        return JSON.stringify(row);
    }

    function formatToolInput(input) {
        if (!input || typeof input !== 'object') { return ''; }
        var keys = Object.keys(input);
        if (keys.length === 0) { return ''; }
        var parts = keys.slice(0, 4).map(function (k) {
            var v = input[k];
            var vStr = (typeof v === 'object') ? JSON.stringify(v) : String(v);
            return k + '=' + vStr;
        });
        return parts.join(', ');
    }
})();
