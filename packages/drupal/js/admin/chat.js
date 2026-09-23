(function (Drupal, drupalSettings) {
  'use strict';

  var csrfToken    = drupalSettings.phpclaw_chat.csrf_token;
  var currentConvId = drupalSettings.phpclaw_chat.init_conv_id;
  var isSending    = false;

  var msgArea    = document.getElementById('phpclaw-messages');
  var inputEl    = document.getElementById('phpclaw-message');
  var sendBtn    = document.getElementById('phpclaw-send-btn');
  var chatHeader = document.getElementById('phpclaw-chat-header');
  var convList   = document.getElementById('phpclaw-conv-list');
  var searchEl   = document.getElementById('phpclaw-conv-search');
  var newChatBtn = document.getElementById('phpclaw-new-chat');
  var metaEl     = document.getElementById('phpclaw-input-meta');

  function phpclawHttpError(status) {
    return status === 403
      ? 'Your session expired or you were signed out. Reload the page and sign in again.'
      : 'Unexpected server response (HTTP ' + status + '). Reload the page and try again.';
  }

  function phpclawReadJson(r) {
    if ((r.headers.get('content-type') || '').indexOf('application/json') === -1) {
      throw new Error('Your session expired or you were signed out. Reload the page and sign in again.');
    }
    if (!r.ok) {
      return r.json().then(function (d) {
        throw new Error((d && (d.error || d.message)) || phpclawHttpError(r.status));
      });
    }
    return r.json();
  }

  function scrollBottom() { msgArea.scrollTop = msgArea.scrollHeight; }

  function escHtml(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }

  function renderBubble(role, content) {
    var wrap = document.createElement('div');
    wrap.className = 'phpclaw-bubble-wrap ' + role;
    if (role === 'assistant') {
      var av = document.createElement('div');
      av.className = 'phpclaw-avatar';
      av.textContent = '🤖';
      wrap.appendChild(av);
    }
    var bubble = document.createElement('div');
    bubble.className = 'phpclaw-bubble ' + role;
    bubble.textContent = content;
    wrap.appendChild(bubble);
    msgArea.appendChild(wrap);
    scrollBottom();
    return bubble;
  }

  function showTyping() {
    var wrap = document.createElement('div');
    wrap.className = 'phpclaw-bubble-wrap assistant';
    wrap.id = 'phpclaw-typing';
    var av = document.createElement('div');
    av.className = 'phpclaw-avatar';
    av.textContent = '🤖';
    wrap.appendChild(av);
    var b = document.createElement('div');
    b.className = 'phpclaw-bubble assistant phpclaw-typing';
    b.innerHTML = '<span></span><span></span><span></span>';
    wrap.appendChild(b);
    msgArea.appendChild(wrap);
    scrollBottom();
  }

  function removeTyping() { var el = document.getElementById('phpclaw-typing'); if (el) el.remove(); }

  function chip(text, mod) {
    var s = document.createElement('span');
    s.className = 'pc-chip' + (mod ? ' pc-chip--' + mod : '');
    s.textContent = text;
    return s;
  }

  function unwrap(parsed) {
    if (parsed && typeof parsed === 'object' && typeof parsed.success === 'boolean') {
      return { body: parsed.data || {}, meta: parsed.meta || {}, warnings: parsed.warnings || [], error: parsed.error || null, enveloped: true };
    }
    return { body: parsed || {}, meta: parsed || {}, warnings: [], error: null, enveloped: false };
  }

  function renderStatsLine(container, parsed) {
    var w = unwrap(parsed);
    var body = w.body;
    var meta = w.meta;
    var rows = Array.isArray(body) ? body.length : (typeof body === 'object' && body ? Object.keys(body).length : 0);
    var line = document.createElement('div');
    line.className = 'pc-tool-card__stats-line';
    var bits = [rows + ' rows'];
    if (meta && meta.count != null) { bits = [meta.count + ' rows']; }
    if (meta && meta.total && meta.total > (meta.count != null ? meta.count : rows)) bits.push('of ' + meta.total);
    if (meta && meta.limit) bits.push('limit ' + meta.limit);
    if (meta && meta.offset) bits.push('offset ' + meta.offset);
    if (meta && meta.has_more) bits.push('more available');
    line.textContent = bits.join(' \u00b7 ');
    container.appendChild(line);
  }

  function renderWarnings(container, parsed) {
    var w = unwrap(parsed);
    if (w.error) {
      var err = document.createElement('div');
      err.className = 'pc-tool-card__error';
      err.textContent = (w.error.code || 'ERROR') + ': ' + (w.error.message || '');
      container.appendChild(err);
    }
    if (!w.warnings.length) { return; }
    var ul = document.createElement('ul');
    ul.className = 'pc-tool-card__warnings';
    w.warnings.forEach(function (warn) {
      var li = document.createElement('li');
      li.textContent = (warn.code || 'WARNING') + ': ' + (warn.message || '');
      ul.appendChild(li);
    });
    container.appendChild(ul);
  }

  function renderRawToggle(container, parsed) {
    var raw = document.createElement('details');
    raw.className = 'pc-tool-card__raw';
    var summary = document.createElement('summary');
    summary.textContent = 'Show raw JSON';
    var pre = document.createElement('pre');
    pre.textContent = JSON.stringify(parsed, null, 2);
    raw.appendChild(summary);
    raw.appendChild(pre);
    container.appendChild(raw);
  }

  function renderDrupalEntity(body, data) {
    var d = unwrap(data).body;
    var items = (d && Array.isArray(d.entities)) ? d.entities : (Array.isArray(d) ? d : []);
    if (items.length === 0) {
      body.appendChild(document.createTextNode('No entities.'));
      return;
    }
    var ol = document.createElement('ol');
    ol.className = 'pc-tool-card__list';
    items.forEach(function (e) {
      var li = document.createElement('li');
      var titleSrc = e.title || e.label || e.name || (e.nid ? 'Node #' + e.nid : (e.uid ? 'User #' + e.uid : '(no title)'));
      var editPath = e.nid ? '/node/' + e.nid + '/edit' : (e.uid ? '/user/' + e.uid + '/edit' : null);
      if (editPath) {
        var a = document.createElement('a');
        a.href = editPath;
        a.target = '_blank';
        a.className = 'pc-tool-card__item-title';
        a.textContent = titleSrc;
        li.appendChild(a);
      } else {
        var t = document.createElement('span');
        t.className = 'pc-tool-card__item-title';
        t.textContent = titleSrc;
        li.appendChild(t);
      }
      var meta = document.createElement('div');
      meta.className = 'pc-tool-card__item-meta';
      if (e.type) meta.appendChild(chip(e.type));
      if (e.status !== undefined) meta.appendChild(chip(e.status, e.status === 'published' || e.status === 'active' || e.status === 1 ? 'ok' : 'muted'));
      if (e.created) meta.appendChild(chip(e.created, 'muted'));
      if (meta.childNodes.length) li.appendChild(meta);
      ol.appendChild(li);
    });
    body.appendChild(ol);
  }

  function renderDrupalRoles(body, data) {
    var d = unwrap(data).body;
    var users = (d && Array.isArray(d.users)) ? d.users : (Array.isArray(d) ? d : []);
    if (users.length === 0) {
      body.appendChild(document.createTextNode('No users.'));
      return;
    }
    var table = document.createElement('table');
    table.className = 'pc-tool-card__table';
    var thead = '<thead><tr><th>UID</th><th>Name</th><th>Roles</th><th>Status</th></tr></thead>';
    var rows = users.map(function (u) {
      var roles = Array.isArray(u.roles) ? u.roles.join(', ') : (u.role || '');
      var status = u.status === 1 || u.status === 'active' ? '<span class="pc-chip pc-chip--ok">active</span>' : '<span class="pc-chip pc-chip--muted">blocked</span>';
      var link = u.uid ? '<a href="/user/' + u.uid + '/edit" target="_blank">' + escHtml(u.name || '') + '</a>' : escHtml(u.name || '');
      return '<tr><td>' + (u.uid || '') + '</td><td>' + link + '</td><td>' + escHtml(roles) + '</td><td>' + status + '</td></tr>';
    }).join('');
    table.innerHTML = thead + '<tbody>' + rows + '</tbody>';
    body.appendChild(table);
  }

  function renderDrupalModules(body, data) {
    var d = unwrap(data).body;
    var items = (d && Array.isArray(d.modules)) ? d.modules : (Array.isArray(d) ? d : []);
    if (items.length === 0) {
      body.appendChild(document.createTextNode('No modules.'));
      return;
    }
    var byPackage = {};
    items.forEach(function (m) {
      var pkg = m.package || m.category || 'Other';
      if (!byPackage[pkg]) byPackage[pkg] = [];
      byPackage[pkg].push(m);
    });
    Object.keys(byPackage).sort().forEach(function (pkg) {
      var h = document.createElement('div');
      h.className = 'pc-tool-card__group-header';
      h.textContent = pkg + ' (' + byPackage[pkg].length + ')';
      body.appendChild(h);
      var ul = document.createElement('ul');
      ul.className = 'pc-tool-card__list';
      byPackage[pkg].forEach(function (m) {
        var li = document.createElement('li');
        var name = document.createElement('span');
        name.className = 'pc-tool-card__item-title';
        name.textContent = m.name || m.machine_name || '(no name)';
        li.appendChild(name);
        var enabled = m.enabled === true || m.status === 1 || m.status === 'enabled';
        li.appendChild(chip(enabled ? 'enabled' : 'disabled', enabled ? 'ok' : 'muted'));
        if (m.version) li.appendChild(chip(m.version, 'muted'));
        ul.appendChild(li);
      });
      body.appendChild(ul);
    });
  }

  function renderDbQuery(body, data) {
    var d = unwrap(data).body;
    var rows = (d && Array.isArray(d.rows)) ? d.rows : (Array.isArray(d) ? d : []);
    if (rows.length === 0) {
      body.appendChild(document.createTextNode('No rows.'));
      return;
    }
    var cols = Object.keys(rows[0]);
    var table = document.createElement('table');
    table.className = 'pc-tool-card__table';
    var thead = '<thead><tr>' + cols.map(function (c) { return '<th>' + escHtml(c) + '</th>'; }).join('') + '</tr></thead>';
    var tbody = '<tbody>' + rows.map(function (r) {
      return '<tr>' + cols.map(function (c) { return '<td>' + escHtml(String(r[c] == null ? '' : r[c])) + '</td>'; }).join('') + '</tr>';
    }).join('') + '</tbody>';
    table.innerHTML = thead + tbody;
    body.appendChild(table);
  }

  function renderReadLog(body, data) {
    var d = unwrap(data).body;
    var entries = (d && Array.isArray(d.entries)) ? d.entries : (Array.isArray(d) ? d : []);
    if (entries.length === 0) {
      body.appendChild(document.createTextNode('No log entries.'));
      return;
    }
    var ul = document.createElement('ul');
    ul.className = 'pc-tool-card__list';
    entries.forEach(function (e) {
      var li = document.createElement('li');
      var sev = (e.severity || e.level || '').toString().toLowerCase();
      var sevMod = sev === 'error' || sev === 'critical' || sev === 'emergency' ? 'err' : (sev === 'warning' ? 'muted' : 'ok');
      li.appendChild(chip(sev || 'info', sevMod));
      var t = document.createElement('span');
      t.className = 'pc-tool-card__item-title';
      t.textContent = (e.type ? '[' + e.type + '] ' : '') + (e.message || '').substring(0, 200);
      li.appendChild(t);
      if (e.timestamp || e.created) {
        var when = document.createElement('span');
        when.className = 'pc-tool-card__item-meta';
        when.textContent = e.timestamp || e.created;
        li.appendChild(when);
      }
      ul.appendChild(li);
    });
    body.appendChild(ul);
  }

  function renderDrupalConfig(body, data) {
    var w = unwrap(data);
    var d = w.body || {};
    var values = d.values;
    if (!values || typeof values !== 'object') {
      body.appendChild(document.createTextNode('No configuration values.'));
      return;
    }
    var withheld = (w.meta && Array.isArray(w.meta.withheld_keys)) ? w.meta.withheld_keys : [];
    if (d.config_name) {
      var head = document.createElement('div');
      head.className = 'pc-tool-card__item-title';
      head.textContent = d.config_name;
      body.appendChild(head);
    }
    var dl = document.createElement('dl');
    dl.className = 'pc-tool-card__kv';
    Object.keys(values).forEach(function (key) {
      var dt = document.createElement('dt');
      dt.textContent = key;
      var dd = document.createElement('dd');
      if (withheld.indexOf(key) !== -1) {
        dd.appendChild(chip('withheld', 'muted'));
      } else {
        dd.textContent = formatConfigValue(values[key]);
      }
      dl.appendChild(dt);
      dl.appendChild(dd);
    });
    body.appendChild(dl);
  }

  function formatConfigValue(value) {
    if (value === null) return 'null';
    if (value === true) return 'true';
    if (value === false) return 'false';
    if (value === '') return '(empty)';
    if (typeof value === 'object') {
      try { return JSON.stringify(value); } catch (e) { return '(unreadable)'; }
    }
    return String(value);
  }

  function renderDrupalCron(body, data) {
    var d = unwrap(data).body || {};
    if (d.last_cron_run === undefined && d.queues === undefined) {
      body.appendChild(document.createTextNode('No cron status.'));
      return;
    }
    var meta = document.createElement('div');
    meta.className = 'pc-tool-card__item-meta';
    if (d.last_cron_run) meta.appendChild(chip('last run ' + d.last_cron_run));
    if (typeof d.seconds_ago === 'number') meta.appendChild(chip(d.seconds_ago + 's ago', 'muted'));
    meta.appendChild(chip(d.overdue ? 'overdue' : 'not overdue', d.overdue ? 'muted' : 'ok'));
    if (typeof d.total_queued === 'number') meta.appendChild(chip(d.total_queued + ' queued', 'muted'));
    body.appendChild(meta);

    var queues = Array.isArray(d.queues) ? d.queues : [];
    if (queues.length === 0) {
      var none = document.createElement('div');
      none.className = 'pc-tool-card__fallback';
      none.textContent = 'No queues with pending items.';
      body.appendChild(none);
      return;
    }
    var ol = document.createElement('ol');
    ol.className = 'pc-tool-card__list';
    queues.forEach(function (q) {
      var li = document.createElement('li');
      var t = document.createElement('span');
      t.className = 'pc-tool-card__item-title';
      t.textContent = q.title || q.name || '(unnamed queue)';
      li.appendChild(t);
      var qm = document.createElement('div');
      qm.className = 'pc-tool-card__item-meta';
      if (q.name && q.title) qm.appendChild(chip(q.name, 'muted'));
      if (q.items !== undefined) qm.appendChild(chip(q.items + ' items'));
      if (qm.childNodes.length) li.appendChild(qm);
      ol.appendChild(li);
    });
    body.appendChild(ol);
  }

  function renderTypedBody(body, toolName, data) {
    switch (toolName) {
      case 'drupal_entity': return renderDrupalEntity(body, data);
      case 'drupal_roles':  return renderDrupalRoles(body, data);
      case 'drupal_modules': return renderDrupalModules(body, data);
      case 'db_query':       return renderDbQuery(body, data);
      case 'read_log':       return renderReadLog(body, data);
      case 'drupal_config':  return renderDrupalConfig(body, data);
      case 'drupal_cron':    return renderDrupalCron(body, data);
    }
    return null;
  }

  function renderToolCard(name, input, resultJson) {
    var card = document.createElement('div');
    card.className = 'pc-tool-card';

    var hdr = document.createElement('div');
    hdr.className = 'pc-tool-card__header';
    var icon = document.createElement('span');
    icon.className = 'pc-tool-card__icon';
    icon.textContent = '🛠';
    var nameEl = document.createElement('span');
    nameEl.className = 'pc-tool-card__name';
    nameEl.textContent = name;
    hdr.appendChild(icon);
    hdr.appendChild(nameEl);
    card.appendChild(hdr);

    if (input && Object.keys(input).length > 0) {
      var inp = document.createElement('div');
      inp.className = 'pc-tool-card__input';
      inp.textContent = JSON.stringify(input);
      card.appendChild(inp);
    }

    var body = document.createElement('div');
    body.className = 'pc-tool-card__body';
    var parsed = null;
    try { parsed = JSON.parse(resultJson); } catch (e) { parsed = null; }

    if (parsed && typeof parsed === 'object') {
      var rendered = renderTypedBody(body, name, parsed);
      if (rendered === null) {
        var fallback = document.createElement('div');
        fallback.className = 'pc-tool-card__fallback';
        fallback.textContent = '(no typed renderer for ' + name + ')';
        body.appendChild(fallback);
      }
      renderWarnings(body, parsed);
      renderStatsLine(body, parsed);
      renderRawToggle(body, parsed);
    } else {
      body.textContent = String(resultJson).substring(0, 500);
    }
    card.appendChild(body);

    msgArea.appendChild(card);
    scrollBottom();
    return card;
  }

  function clearMessages(text) {
    msgArea.innerHTML = '';
    if (text) {
      msgArea.innerHTML = '<div id="phpclaw-welcome"><h2>New Chat</h2><p>' + escHtml(text) + '</p></div>';
    }
  }

  function setActiveConv(id) {
    document.querySelectorAll('.phpclaw-conv-item').forEach(function (el) {
      el.classList.toggle('active', el.dataset.convId === id);
    });
  }

  function prependConvItem(convId, title) {
    var existing = document.querySelector('[data-conv-id="' + convId + '"]');
    if (existing) {
      existing.querySelector('.phpclaw-conv-item-time').textContent = 'just now';
      convList.insertBefore(existing, convList.firstChild);
      return;
    }
    var empty = document.getElementById('phpclaw-empty-sidebar');
    if (empty) empty.remove();
    var item = document.createElement('div');
    item.className = 'phpclaw-conv-item';
    item.dataset.convId = convId;
    item.dataset.title = (title || '').toLowerCase();
    item.innerHTML = '<div class="phpclaw-conv-item-title">' + escHtml(title || 'New conversation') + '</div><div class="phpclaw-conv-item-bottom"><span class="phpclaw-conv-item-time">just now</span><button type="button" class="phpclaw-conv-delete" data-conv-id="' + convId + '" title="Delete">✕</button></div>';
    item.addEventListener('click', function (e) { if (!e.target.classList.contains('phpclaw-conv-delete')) loadConversation(convId); });
    convList.insertBefore(item, convList.firstChild);
  }

  function loadConversation(convId) {
    if (isSending) return;
    if (convId === currentConvId && document.querySelectorAll('.phpclaw-bubble').length > 0) return;
    currentConvId = convId;
    setActiveConv(convId);
    clearMessages('');
    showTyping();

    fetch('/admin/config/phpclaw/chat/load', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ conversation_id: convId })
    })
    .then(phpclawReadJson)
    .then(function (data) {
      removeTyping();
      clearMessages('');
      if (data.ok) {
        chatHeader.textContent = data.title || 'Conversation';
        if (!data.messages || data.messages.length === 0) {
          clearMessages('No message history for this conversation.');
        } else {
          data.messages.forEach(function (m) {
            if (m.role === 'tool') {
              renderToolCard(m.tool_name || '', m.tool_input || {}, m.tool_result || '');
            } else {
              renderBubble(m.role, m.content);
            }
          });
        }
        scrollBottom();
      } else {
        clearMessages('Could not load conversation.');
      }
    })
    .catch(function (err) { removeTyping(); clearMessages('Request failed: ' + err.message); });
  }

  document.querySelectorAll('.phpclaw-conv-item').forEach(function (item) {
    item.addEventListener('click', function (e) { if (!e.target.classList.contains('phpclaw-conv-delete')) loadConversation(item.dataset.convId); });
  });

  document.addEventListener('click', function (e) {
    if (!e.target.classList.contains('phpclaw-conv-delete')) return;
    e.stopPropagation();
    if (isSending) return;
    var convId = e.target.dataset.convId;
    if (!convId || !confirm('Delete this conversation?')) return;
    fetch('/admin/config/phpclaw/chat/delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ conversation_id: convId })
    })
    .then(phpclawReadJson)
    .then(function (data) {
      if (data.ok) {
        var el = document.querySelector('.phpclaw-conv-item[data-conv-id="' + convId + '"]');
        if (el) el.remove();
        if (currentConvId === convId) {
          currentConvId = '';
          chatHeader.textContent = 'New Chat';
          clearMessages('Conversation deleted. Start a new one.');
        }
      }
    })
    .catch(function (err) { clearMessages(err.message); });
  });

  if (searchEl) {
    searchEl.addEventListener('input', function () {
      var q = searchEl.value.toLowerCase().trim();
      document.querySelectorAll('.phpclaw-conv-item').forEach(function (item) {
        item.style.display = (!q || (item.dataset.title || '').indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }

  if (newChatBtn) {
    newChatBtn.addEventListener('click', function () {
      if (isSending) return;
      currentConvId = '';
      setActiveConv('');
      chatHeader.textContent = 'New Chat';
      clearMessages('Ask anything about your Drupal site.');
      inputEl.focus();
    });
  }

  function sendMessage() {
    var msg = inputEl.value.trim();
    if (!msg || sendBtn.disabled || isSending) return;
    inputEl.value = '';
    autoResize();
    var welcome = document.getElementById('phpclaw-welcome');
    if (welcome) welcome.remove();
    renderBubble('user', msg);
    isSending = true;
    sendBtn.disabled = true;
    if (msgArea) msgArea.setAttribute('aria-busy', 'true');
    if (convList) { convList.style.pointerEvents = 'none'; convList.style.opacity = '0.5'; }
    if (newChatBtn) { newChatBtn.disabled = true; newChatBtn.style.opacity = '0.5'; }
    showTyping();

    var pendingCards = {};
    var streamingBubble = null;
    var streamingText = '';

    function placeholderKey(name, input) {
      return name + ':' + JSON.stringify(input || {});
    }

    function renderToolPlaceholder(name, input) {
      var card = document.createElement('div');
      card.className = 'pc-tool-card pc-tool-card--pending';
      var hdr = document.createElement('div');
      hdr.className = 'pc-tool-card__header';
      var icon = document.createElement('span');
      icon.className = 'pc-tool-card__icon pc-tool-card__icon--spin';
      icon.textContent = '⟳';
      var nameEl = document.createElement('span');
      nameEl.className = 'pc-tool-card__name';
      nameEl.textContent = name;
      var calling = document.createElement('span');
      calling.className = 'pc-tool-card__calling';
      calling.textContent = 'Calling…';
      hdr.appendChild(icon);
      hdr.appendChild(nameEl);
      hdr.appendChild(calling);
      card.appendChild(hdr);
      msgArea.appendChild(card);
      scrollBottom();
      return card;
    }

    function ensureStreamingBubble() {
      if (streamingBubble) return streamingBubble;
      var wrap = document.createElement('div');
      wrap.className = 'phpclaw-bubble-wrap assistant';
      var av = document.createElement('div');
      av.className = 'phpclaw-avatar';
      av.textContent = '🤖';
      wrap.appendChild(av);
      streamingBubble = document.createElement('div');
      streamingBubble.className = 'phpclaw-bubble assistant';
      wrap.appendChild(streamingBubble);
      msgArea.appendChild(wrap);
      scrollBottom();
      return streamingBubble;
    }

    fetch('/admin/config/phpclaw/chat/stream', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ message: msg, conversation_id: currentConvId || '' })
    })
    .then(function (resp) {
      if (!resp.ok) throw new Error(phpclawHttpError(resp.status));
      if (!resp.body) throw new Error('No stream body');
      var reader = resp.body.getReader();
      var decoder = new TextDecoder();
      var buffer = '';

      var MIN_PLACEHOLDER_MS = 400;

      function dispatch(event, payload) {
        if (event === 'tool_before') {
          removeTyping();
          var key = placeholderKey(payload.tool_name, payload.tool_input);
          var card = renderToolPlaceholder(payload.tool_name, payload.tool_input);
          card.dataset.bornAt = String(Date.now());
          pendingCards[key] = card;
        } else if (event === 'tool_after') {
          var key2 = placeholderKey(payload.tool_name, payload.tool_input);
          var swap = function () {
            if (pendingCards[key2]) {
              pendingCards[key2].remove();
              delete pendingCards[key2];
            }
            renderToolCard(payload.tool_name, payload.tool_input || {}, payload.tool_result || '');
          };
          if (pendingCards[key2]) {
            var bornAt = parseInt(pendingCards[key2].dataset.bornAt || '0', 10);
            var elapsed = Date.now() - bornAt;
            var remaining = MIN_PLACEHOLDER_MS - elapsed;
            if (remaining > 0) { setTimeout(swap, remaining); } else { swap(); }
          } else {
            swap();
          }
        } else if (event === 'chunk') {
          removeTyping();
          streamingText += payload.text || '';
          ensureStreamingBubble().textContent = streamingText;
          scrollBottom();
        } else if (event === 'done') {
          removeTyping();
          if (streamingBubble) {
            streamingBubble.textContent = payload.text || streamingText;
          } else {
            renderBubble('assistant', payload.text || '');
          }
          streamingBubble = null;
          streamingText = '';
          var newId = payload.conversation_id || '';
          if (newId) {
            var isNew = (newId !== currentConvId);
            currentConvId = newId;
            if (isNew || chatHeader.textContent === 'New Chat') {
              var titleText = msg.length > 60 ? msg.substring(0, 60) + '…' : msg;
              chatHeader.textContent = titleText;
              prependConvItem(newId, titleText);
            } else {
              prependConvItem(newId, chatHeader.textContent);
            }
            setActiveConv(newId);
          }
          scrollBottom();
        } else if (event === 'error') {
          removeTyping();
          renderBubble('assistant', 'Error: ' + (payload.message || 'Unknown error'));
          /* meta stays static */
          scrollBottom();
        }
      }

      function processBuffer() {
        var frames = buffer.split('\n\n');
        buffer = frames.pop();
        frames.forEach(function (frame) {
          if (!frame.trim()) return;
          var event = 'message';
          var data = '';
          frame.split('\n').forEach(function (line) {
            if (line.indexOf('event:') === 0) event = line.substring(6).trim();
            else if (line.indexOf('data:') === 0) data += line.substring(5).trim();
          });
          if (!data) return;
          var payload;
          try { payload = JSON.parse(data); } catch (e) { return; }
          dispatch(event, payload);
        });
      }

      function pump() {
        return reader.read().then(function (chunk) {
          if (chunk.done) { processBuffer(); return; }
          buffer += decoder.decode(chunk.value, { stream: true });
          processBuffer();
          return pump();
        });
      }
      return pump();
    })
    .catch(function (err) { removeTyping(); renderBubble('assistant', 'Request failed: ' + err.message); /* meta stays static */ scrollBottom(); })
    .finally(function () {
      isSending = false;
      sendBtn.disabled = false;
      if (msgArea) msgArea.removeAttribute('aria-busy');
      if (convList) { convList.style.pointerEvents = ''; convList.style.opacity = ''; }
      if (newChatBtn) { newChatBtn.disabled = false; newChatBtn.style.opacity = ''; }
      inputEl.focus();
    });
  }

  sendBtn.addEventListener('click', sendMessage);
  inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } });
  inputEl.addEventListener('input', autoResize);

  function autoResize() { inputEl.style.height = 'auto'; inputEl.style.height = Math.min(inputEl.scrollHeight, 160) + 'px'; }

  scrollBottom();

  if (currentConvId) {
    var __initId = currentConvId;
    currentConvId = '';
    loadConversation(__initId);
  }
})(Drupal, drupalSettings);