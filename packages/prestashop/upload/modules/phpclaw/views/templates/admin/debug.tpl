{*
  phpClaw: Chat
  PrestaShop 8 Back Office admin template (Bootstrap 4, Smarty)
*}

<div class="page-header d-print-none">
  <div class="container-xl">
    <div class="row g-2 align-items-center">
      <div class="col">
        <h2 class="page-title">phpClaw AI Agent: Chat</h2>
      </div>
    </div>
  </div>
</div>

<div class="container-xl">

  {* Not-configured banner *}
  {if !$phpclaw_is_configured}
  <div class="alert alert-warning d-flex align-items-center gap-3 mb-3" role="alert">
    <i class="icon-exclamation-triangle"></i>
    <span>phpClaw is not configured. {if $can_manage_all}<a href="{$url_settings|escape:'htmlall'}" class="alert-link">Open Settings</a> to set your AI provider and model.{else}Ask an administrator to set the AI provider and model.{/if}</span>
  </div>
  {/if}

  <div id="phpclaw-chat-wrap" class="{if !$phpclaw_is_configured}phpclaw-is-disabled{/if}">

    {* Left sidebar *}
    <div id="phpclaw-sidebar">
      <div id="phpclaw-sidebar-top">
        <button id="phpclaw-new-chat" class="btn btn-primary btn-sm w-100 fw-semibold">+ New Chat</button>
        <input id="phpclaw-conv-search" type="search" placeholder="Search conversations…" />
      </div>
      <div id="phpclaw-conv-list" tabindex="0">
        {if isset($phpclaw_conversations) && $phpclaw_conversations|@count > 0}
          {foreach $phpclaw_conversations as $conv}
          <div class="phpclaw-conv-item" data-conv-id="{$conv.id|escape:'htmlall'}" data-title="{$conv.title|lower|escape:'htmlall'}">
            <div class="phpclaw-conv-item-title">{if $conv.title}{$conv.title|escape:'htmlall'}{else}New conversation{/if}</div>
            <div class="phpclaw-conv-item-time">{if $conv.time}{$conv.time|date_format:"%b %e, %H:%M"|escape:'htmlall'}{/if}</div>
          </div>
          {/foreach}
        {else}
          <p id="phpclaw-empty-sidebar" class="p-3 text-muted phpclaw-empty-sidebar-note">
            No conversations yet.<br>Send a message to start.
          </p>
        {/if}
      </div>
    </div>

    {* Right chat area *}
    <div id="phpclaw-chat-area">
      <div id="phpclaw-chat-header">New Chat</div>

      <div id="phpclaw-messages" role="log" aria-live="polite" aria-busy="false">
        <div id="phpclaw-welcome">
          <h4>What can I help you with?</h4>
          <p>Ask anything about your PrestaShop store: orders, products, customers, database queries, and more.</p>
          <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
            <button class="btn btn-sm btn-outline-secondary phpclaw-quick"
                    data-prompt="Show me the 5 most recent orders.">Recent orders</button>
            <button class="btn btn-sm btn-outline-secondary phpclaw-quick"
                    data-prompt="List products with low stock (below 5 units).">Low stock</button>
            <button class="btn btn-sm btn-outline-secondary phpclaw-quick"
                    data-prompt="How many active customers do we have?">Active customers</button>
            <button class="btn btn-sm btn-outline-secondary phpclaw-quick"
                    data-prompt="Show me the last 10 lines of the error log.">Error log</button>
          </div>
        </div>
      </div>

      <div id="phpclaw-input-area">
        <div id="phpclaw-input-row">
          <textarea id="phpclaw-message-ta" rows="2"
            placeholder="Message phpClaw… (Enter to send, Shift+Enter for new line)"></textarea>
          <button id="phpclaw-send-btn" title="Send (Enter)">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/>
            </svg>
          </button>
        </div>
        <div id="phpclaw-input-meta">
          Responses are AI-generated. Verify before acting.
        </div>
      </div>
    </div>

  </div>

</div>

{* Community Card *}
<div class="container-xl">
  {include file="./_community_card.tpl"}
</div>

<script>
(function () {ldelim}
  var URL_SEND = {$url_send|json_encode};
  var URL_STREAM = {$url_stream|json_encode};
  var URL_LOAD = {$url_load_conversation|json_encode};
  var currentConvId = '';

  var msgArea    = document.getElementById('phpclaw-messages');
  var inputEl    = document.getElementById('phpclaw-message-ta');
  var sendBtn    = document.getElementById('phpclaw-send-btn');
  var chatHeader = document.getElementById('phpclaw-chat-header');
  var convList   = document.getElementById('phpclaw-conv-list');
  var searchEl   = document.getElementById('phpclaw-conv-search');
  var newChatBtn = document.getElementById('phpclaw-new-chat');

  function scrollBottom() {ldelim}
    msgArea.scrollTop = msgArea.scrollHeight;
  {rdelim}

  function escHtml(str) {ldelim}
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str)));
    return d.innerHTML;
  {rdelim}

  function renderBubble(role, content) {ldelim}
    var wrap = document.createElement('div');
    wrap.className = 'phpclaw-bubble-wrap ' + role;
    if (role === 'assistant') {ldelim}
      var av = document.createElement('div');
      av.className = 'phpclaw-avatar';
      av.textContent = '\uD83E\uDD16';
      wrap.appendChild(av);
    {rdelim}
    var bubble = document.createElement('div');
    bubble.className = 'phpclaw-bubble ' + role;
    bubble.textContent = content;
    wrap.appendChild(bubble);
    msgArea.appendChild(wrap);
    scrollBottom();
    return wrap;
  {rdelim}

  function prettyJson(str) {ldelim}
    try {ldelim} return JSON.stringify(JSON.parse(str), null, 2); {rdelim} catch (e) {ldelim} return String(str); {rdelim}
  {rdelim}

  function pcEl(tag, cls, text) {ldelim}
    var e = document.createElement(tag);
    if (cls) {ldelim} e.className = cls; {rdelim}
    if (text != null) {ldelim} e.textContent = text; {rdelim}
    return e;
  {rdelim}

  function pcParse(result) {ldelim}
    if (result && typeof result === 'object') {ldelim} return result; {rdelim}
    try {ldelim} return JSON.parse(result); {rdelim} catch (e) {ldelim} return null; {rdelim}
  {rdelim}

  function pcKey(k) {ldelim} return String(k).replace(/_/g, ' '); {rdelim}

  function pcScalar(v) {ldelim}
    if (v === true) {ldelim} return 'yes'; {rdelim}
    if (v === false) {ldelim} return 'no'; {rdelim}
    return String(v);
  {rdelim}

  function pcChip(text) {ldelim} return '<span class="pc-chip">' + escHtml(text) + '</span>'; {rdelim}

  function pcBadge(text, kind) {ldelim}
    return '<span class="pc-badge pc-badge--' + kind + '">' + escHtml(text) + '</span>';
  {rdelim}

  function pcStatsLine(container, key, val) {ldelim}
    var line = pcEl('div', 'pc-tool-card__stats-line');
    line.appendChild(pcEl('span', 'pc-tool-card__stats-key', key));
    line.appendChild(pcEl('span', 'pc-tool-card__stats-val', String(val)));
    container.appendChild(line);
  {rdelim}

  function pcRenderStats(container, obj) {ldelim}
    Object.keys(obj).forEach(function (k) {ldelim}
      if (k === 'aggregate') {ldelim} return; {rdelim}
      var v = obj[k];
      if (v == null) {ldelim} return; {rdelim}
      if (Array.isArray(v)) {ldelim}
        container.appendChild(pcEl('div', 'pc-tool-card__group-header', pcKey(k)));
        v.forEach(function (row) {ldelim}
          if (row && typeof row === 'object') {ldelim}
            var ks = Object.keys(row);
            pcStatsLine(container, String(row[ks[0]]), ks.length > 1 ? pcScalar(row[ks[1]]) : '');
          {rdelim}
        {rdelim});
      {rdelim} else if (typeof v === 'object') {ldelim}
        container.appendChild(pcEl('div', 'pc-tool-card__group-header', pcKey(k)));
        Object.keys(v).forEach(function (sk) {ldelim} pcStatsLine(container, pcKey(sk), pcScalar(v[sk])); {rdelim});
      {rdelim} else {ldelim}
        pcStatsLine(container, pcKey(k), pcScalar(v));
      {rdelim}
    {rdelim});
  {rdelim}

  function pcRenderList(container, items, titleFn, metaFn) {ldelim}
    if (!items || !items.length) {ldelim}
      container.appendChild(pcEl('div', 'pc-tool-card__item-meta', 'No results.'));
      return;
    {rdelim}
    var list = pcEl('div', 'pc-tool-card__list');
    items.forEach(function (it, i) {ldelim}
      var li = pcEl('div', 'pc-tool-card__item');
      var t = pcEl('div', 'pc-tool-card__item-title');
      t.innerHTML = (i + 1) + '. ' + titleFn(it);
      li.appendChild(t);
      var meta = metaFn ? metaFn(it) : '';
      if (meta) {ldelim}
        var m = pcEl('div', 'pc-tool-card__item-meta');
        m.innerHTML = meta;
        li.appendChild(m);
      {rdelim}
      list.appendChild(li);
    {rdelim});
    container.appendChild(list);
  {rdelim}

  function pcRenderTable(container, cols, rows) {ldelim}
    if (!rows || !rows.length) {ldelim}
      container.appendChild(pcEl('div', 'pc-tool-card__item-meta', 'No rows.'));
      return;
    {rdelim}
    var table = pcEl('table', 'pc-tool-card__table');
    var thead = pcEl('thead');
    var htr = pcEl('tr');
    cols.forEach(function (c) {ldelim} htr.appendChild(pcEl('th', null, c)); {rdelim});
    thead.appendChild(htr);
    table.appendChild(thead);
    var tbody = pcEl('tbody');
    rows.forEach(function (r) {ldelim}
      var tr = pcEl('tr');
      r.forEach(function (cell) {ldelim} tr.appendChild(pcEl('td', null, String(cell))); {rdelim});
      tbody.appendChild(tr);
    {rdelim});
    table.appendChild(tbody);
    container.appendChild(table);
  {rdelim}

  function pcFooter(container, text) {ldelim} container.appendChild(pcEl('div', 'pc-tool-card__footer', text)); {rdelim}

  function pcProducts(c, data) {ldelim}
    var items = data.products || [];
    pcRenderList(c, items, function (p) {ldelim}
      return escHtml(p.name || ('#' + p.id)) +
        (p.active ? pcBadge('active', 'ok') : pcBadge('inactive', 'muted'));
    {rdelim}, function (p) {ldelim}
      var parts = [];
      if (p.reference) {ldelim} parts.push('Ref ' + escHtml(p.reference)); {rdelim}
      if (p.price != null) {ldelim} parts.push('€' + Number(p.price).toFixed(2)); {rdelim}
      if (p.quantity != null) {ldelim} parts.push(p.quantity + ' in stock'); {rdelim}
      if (p.manufacturer_name) {ldelim} parts.push(escHtml(p.manufacturer_name)); {rdelim}
      if (p.category_name) {ldelim} parts.push(escHtml(p.category_name)); {rdelim}
      return parts.join(' · ');
    {rdelim});
    if (items.length) {ldelim} pcFooter(c, items.length + ' product(s) shown'); {rdelim}
  {rdelim}

  function pcOrders(c, data) {ldelim}
    var items = data.orders || [];
    pcRenderList(c, items, function (o) {ldelim}
      return escHtml(o.reference || ('#' + o.id)) + (o.state_name ? pcChip(o.state_name) : '');
    {rdelim}, function (o) {ldelim}
      var parts = [];
      if (o.customer_name) {ldelim} parts.push(escHtml(o.customer_name)); {rdelim}
      if (o.total_paid_tax_incl != null) {ldelim} parts.push('€' + Number(o.total_paid_tax_incl).toFixed(2)); {rdelim}
      if (o.payment) {ldelim} parts.push(escHtml(o.payment)); {rdelim}
      if (o.date_add) {ldelim} parts.push(escHtml(o.date_add)); {rdelim}
      return parts.join(' · ');
    {rdelim});
    if (items.length) {ldelim} pcFooter(c, items.length + ' order(s) shown'); {rdelim}
  {rdelim}

  function pcCustomers(c, data) {ldelim}
    var items = data.customers || [];
    if (!items.length) {ldelim} c.appendChild(pcEl('div', 'pc-tool-card__item-meta', 'No customers.')); return; {rdelim}
    var cols = ['#', 'Name', 'Email', 'Group', 'Orders', 'Spent'];
    var rows = items.map(function (u, i) {ldelim}
      return [
        i + 1,
        ((u.firstname || '') + ' ' + (u.lastname || '')).trim() || ('#' + u.id),
        u.email || '',
        u.group_name || '',
        u.total_orders != null ? u.total_orders : '',
        u.total_spent != null ? '€' + Number(u.total_spent).toFixed(2) : ''
      ];
    {rdelim});
    pcRenderTable(c, cols, rows);
    pcFooter(c, items.length + ' customer(s) shown');
  {rdelim}

  function pcCategories(c, data) {ldelim}
    var items = data.categories || [];
    pcRenderList(c, items, function (cat) {ldelim}
      return escHtml(cat.name || ('#' + cat.id)) +
        (cat.product_count != null ? pcChip(cat.product_count + ' products') : '') +
        (cat.active === 0 ? pcBadge('inactive', 'muted') : '');
    {rdelim}, function (cat) {ldelim}
      return cat.parent_name ? 'in ' + escHtml(cat.parent_name) : '';
    {rdelim});
    if (items.length) {ldelim} pcFooter(c, items.length + ' category(ies) shown'); {rdelim}
  {rdelim}

  function pcManufacturers(c, data) {ldelim}
    var items = data.manufacturers || [];
    pcRenderList(c, items, function (m) {ldelim}
      return escHtml(m.name || ('#' + m.id)) +
        (m.active === false ? pcBadge('inactive', 'muted') : pcBadge('active', 'ok')) +
        (m.product_count != null ? pcChip(m.product_count + ' products') : '');
    {rdelim}, null);
    if (items.length) {ldelim} pcFooter(c, items.length + ' brand(s) shown'); {rdelim}
  {rdelim}

  function pcDbTable(c, rows) {ldelim}
    if (!Array.isArray(rows) || !rows.length) {ldelim}
      c.appendChild(pcEl('div', 'pc-tool-card__item-meta', 'No rows returned.'));
      return;
    {rdelim}
    var cols = Object.keys(rows[0]);
    var trows = rows.map(function (r) {ldelim}
      return cols.map(function (k) {ldelim} return r[k] == null ? '' : r[k]; {rdelim});
    {rdelim});
    pcRenderTable(c, cols, trows);
    pcFooter(c, rows.length + ' row(s)');
  {rdelim}

  function pcIsEnvelope(d) {ldelim}
    return d !== null && typeof d === 'object' && !Array.isArray(d)
      && Object.prototype.hasOwnProperty.call(d, 'success')
      && Object.prototype.hasOwnProperty.call(d, 'meta');
  {rdelim}

  function pcRenderError(container, err) {ldelim}
    var msg = (err && (err.message || err.code)) ? String(err.message || err.code) : 'The tool reported an error.';
    var el = pcEl('div', 'pc-tool-card__item-meta', msg);
    container.appendChild(el);
  {rdelim}

  function pcRenderWarnings(container, warnings) {ldelim}
    if (!warnings || !warnings.length) {ldelim} return; {rdelim}
    warnings.forEach(function (w) {ldelim}
      var text = (w && typeof w === 'object') ? (w.message || w.code || '') : String(w);
      if (!text) {ldelim} return; {rdelim}
      container.appendChild(pcEl('div', 'pc-tool-card__item-meta', text));
    {rdelim});
  {rdelim}

  var PC_LABEL_FIELDS = ['name', 'product_name', 'customer_name', 'reference', 'title', 'login', 'email', 'code', 'key'];

  function pcRowLabel(row) {ldelim}
    for (var i = 0; i < PC_LABEL_FIELDS.length; i++) {ldelim}
      var f = PC_LABEL_FIELDS[i];
      if (row[f] !== null && row[f] !== undefined && String(row[f]) !== '') {ldelim}
        return {ldelim} field: f, text: String(row[f]) {rdelim};
      {rdelim}
    {rdelim}
    if (row.firstname || row.lastname) {ldelim}
      return {ldelim} field: '__person', text: ((row.firstname || '') + ' ' + (row.lastname || '')).trim() {rdelim};
    {rdelim}
    if (row.id !== null && row.id !== undefined) {ldelim} return {ldelim} field: 'id', text: '#' + row.id {rdelim}; {rdelim}
    return {ldelim} field: null, text: '(row)' {rdelim};
  {rdelim}

  function pcGenericList(container, key, items) {ldelim}
    if (!items.length) {ldelim}
      container.appendChild(pcEl('div', 'pc-tool-card__item-meta', 'No ' + pcKey(key) + '.'));
      return;
    {rdelim}
    var list = pcEl('div', 'pc-tool-card__list');
    items.forEach(function (it, i) {ldelim}
      var li = pcEl('div', 'pc-tool-card__item');
      var title = pcEl('div', 'pc-tool-card__item-title');
      if (it === null || typeof it !== 'object') {ldelim}
        title.textContent = (i + 1) + '. ' + String(it);
        li.appendChild(title);
        list.appendChild(li);
        return;
      {rdelim}
      var lab = pcRowLabel(it);
      title.textContent = (i + 1) + '. ' + lab.text;
      li.appendChild(title);
      var parts = [];
      Object.keys(it).forEach(function (k) {ldelim}
        if (k === lab.field) {ldelim} return; {rdelim}
        if (lab.field === '__person' && (k === 'firstname' || k === 'lastname')) {ldelim} return; {rdelim}
        var v = it[k];
        var shown = (v === null || v === undefined || v === '') ? '-' : pcScalar(v);
        parts.push(pcKey(k) + ' ' + shown);
      {rdelim});
      if (parts.length) {ldelim}
        var meta = pcEl('div', 'pc-tool-card__item-meta');
        meta.textContent = parts.join(' \u00b7 ');
        li.appendChild(meta);
      {rdelim}
      list.appendChild(li);
    {rdelim});
    container.appendChild(list);
    pcFooter(container, items.length + ' ' + pcKey(key) + ' shown');
  {rdelim}

  function pcTypedBody(body, name, data, raw) {ldelim}
    if (data == null) {ldelim}
      var pre = pcEl('pre', 'pc-tool-card__raw');
      pre.textContent = String(raw);
      body.appendChild(pre);
      return;
    {rdelim}

    var meta = null;
    var warnings = null;
    if (pcIsEnvelope(data)) {ldelim}
      if (data.success === false) {ldelim} return pcRenderError(body, data.error); {rdelim}
      meta = data.meta;
      warnings = data.warnings;
      data = data.data;
      if (data == null) {ldelim} return pcRenderError(body, null); {rdelim}
    {rdelim}

    if (meta && meta.mode === 'aggregate') {ldelim} pcRenderStats(body, data); pcRenderWarnings(body, warnings); return; {rdelim}
    if (data.aggregate === true) {ldelim} pcRenderStats(body, data); return; {rdelim}
    if (name === 'ps_product') {ldelim} pcProducts(body, data); pcRenderWarnings(body, warnings); return; {rdelim}
    if (name === 'ps_order') {ldelim} pcOrders(body, data); pcRenderWarnings(body, warnings); return; {rdelim}
    if (name === 'ps_customer') {ldelim} pcCustomers(body, data); pcRenderWarnings(body, warnings); return; {rdelim}
    if (name === 'ps_category') {ldelim} pcCategories(body, data); pcRenderWarnings(body, warnings); return; {rdelim}
    if (name === 'ps_manufacturer') {ldelim} pcManufacturers(body, data); pcRenderWarnings(body, warnings); return; {rdelim}
    if (name === 'database') {ldelim} pcDbTable(body, data.rows || data); pcRenderWarnings(body, warnings); return; {rdelim}
    if (Array.isArray(data)) {ldelim} pcDbTable(body, data); pcRenderWarnings(body, warnings); return; {rdelim}
    if (data && typeof data === 'object') {ldelim}
      var dk = Object.keys(data);
      if (dk.length === 1 && Array.isArray(data[dk[0]])) {ldelim}
        pcGenericList(body, dk[0], data[dk[0]]);
        pcRenderWarnings(body, warnings);
        return;
      {rdelim}
    {rdelim}
    pcRenderStats(body, data);
    pcRenderWarnings(body, warnings);
  {rdelim}

  var PC_SUPERSEDABLE_WARNINGS = [];

  function pcIsSupersedable(parsed) {ldelim}
    if (!pcIsEnvelope(parsed)) {ldelim} return false; {rdelim}
    if (parsed.success === false) {ldelim} return true; {rdelim}
    var w = parsed.warnings || [];
    for (var i = 0; i < w.length; i++) {ldelim}
      var code = (w[i] && typeof w[i] === 'object') ? w[i].code : String(w[i]);
      if (PC_SUPERSEDABLE_WARNINGS.indexOf(code) !== -1) {ldelim} return true; {rdelim}
    {rdelim}
    return false;
  {rdelim}

  function pcDropSuperseded(toolName) {ldelim}
    if (!toolName) {ldelim} return; {rdelim}
    var cards = msgArea.querySelectorAll('.pc-tool-card');
    for (var i = cards.length - 1; i >= 0; i--) {ldelim}
      var c = cards[i];
      if (c.getAttribute('data-pc-tool') === toolName && c.getAttribute('data-pc-supersedable') === '1') {ldelim}
        c.parentNode.removeChild(c);
      {rdelim}
    {rdelim}
  {rdelim}

  function renderToolCard(name, input, result) {ldelim}
    var parsed = pcParse(result);
    var supersedable = pcIsSupersedable(parsed);
    if (!supersedable) {ldelim} pcDropSuperseded(name || ''); {rdelim}

    var card = document.createElement('div');
    card.className = 'pc-tool-card';
    card.setAttribute('data-pc-tool', name || '');
    card.setAttribute('data-pc-supersedable', supersedable ? '1' : '0');

    var header = document.createElement('div');
    header.className = 'pc-tool-card__header';
    var nm = document.createElement('span');
    nm.className = 'pc-tool-card__name';
    nm.textContent = '🔧 ' + (name || 'tool');
    header.appendChild(nm);
    card.appendChild(header);

    if (input && typeof input === 'object' && Object.keys(input).length) {ldelim}
      var inp = document.createElement('div');
      inp.className = 'pc-tool-card__input';
      inp.textContent = JSON.stringify(input);
      card.appendChild(inp);
    {rdelim}

    var body = document.createElement('div');
    body.className = 'pc-tool-card__body';
    pcTypedBody(body, name || '', parsed, result);
    card.appendChild(body);

    var details = document.createElement('details');
    details.className = 'pc-tool-card__raw-toggle';
    var summary = document.createElement('summary');
    summary.textContent = 'Show raw JSON';
    details.appendChild(summary);
    var rawPre = document.createElement('pre');
    rawPre.className = 'pc-tool-card__raw';
    rawPre.textContent = prettyJson(result);
    details.appendChild(rawPre);
    card.appendChild(details);

    msgArea.appendChild(card);
    scrollBottom();
    return card;
  {rdelim}

  function renderPendingCard(name, input) {ldelim}
    var card = pcEl('div', 'pc-tool-card pc-tool-card--pending');
    var header = pcEl('div', 'pc-tool-card__header');
    header.appendChild(pcEl('span', 'pc-spin'));
    header.appendChild(pcEl('span', 'pc-tool-card__name', ' Calling ' + (name || 'tool') + '…'));
    card.appendChild(header);
    if (input && typeof input === 'object' && Object.keys(input).length) {ldelim}
      card.appendChild(pcEl('div', 'pc-tool-card__input', JSON.stringify(input)));
    {rdelim}
    msgArea.appendChild(card);
    scrollBottom();
    return card;
  {rdelim}

  function phpclawAssertType(r, expected) {ldelim}
    var ct = (r.headers.get('content-type') || '').toLowerCase();
    if (ct.indexOf(expected) === -1) {ldelim}
      if (!r.ok) {ldelim} throw new Error('Unexpected server response (HTTP ' + r.status + ').'); {rdelim}
      throw new Error('Your session expired or you were signed out. Reload the page and sign in again.');
    {rdelim}
    return r;
  {rdelim}

  function parseSse(buffer, onEvent) {ldelim}
    var blocks = buffer.split('\n\n');
    var rest = blocks.pop();
    blocks.forEach(function (block) {ldelim}
      if (!block.trim()) {ldelim} return; {rdelim}
      var ev = 'message';
      var data = '';
      block.split('\n').forEach(function (line) {ldelim}
        if (line.indexOf('event:') === 0) {ldelim} ev = line.slice(6).trim(); {rdelim}
        else if (line.indexOf('data:') === 0) {ldelim} data += line.slice(5).trim(); {rdelim}
      {rdelim});
      if (data) {ldelim}
        try {ldelim} onEvent(ev, JSON.parse(data)); {rdelim} catch (e) {ldelim} /* ignore partial frame */ {rdelim}
      {rdelim}
    {rdelim});
    return rest;
  {rdelim}

  function showTyping() {ldelim}
    var wrap = document.createElement('div');
    wrap.className = 'phpclaw-bubble-wrap assistant';
    wrap.id = 'phpclaw-typing';
    var av = document.createElement('div');
    av.className = 'phpclaw-avatar';
    av.textContent = '\uD83E\uDD16';
    wrap.appendChild(av);
    var bubble = document.createElement('div');
    bubble.className = 'phpclaw-bubble assistant phpclaw-typing';
    bubble.setAttribute('role', 'status');
    bubble.setAttribute('aria-label', 'Agent is responding');
    bubble.innerHTML = '<span></span><span></span><span></span>';
    wrap.appendChild(bubble);
    msgArea.appendChild(wrap);
    msgArea.setAttribute('aria-busy', 'true');
    scrollBottom();
  {rdelim}

  function removeTyping() {ldelim}
    var el = document.getElementById('phpclaw-typing');
    if (el) el.remove();
    msgArea.setAttribute('aria-busy', 'false');
  {rdelim}

  function clearMessages(placeholderHtml) {ldelim}
    msgArea.innerHTML = '';
    if (placeholderHtml) {ldelim}
      var d = document.createElement('div');
      d.id = 'phpclaw-welcome';
      d.innerHTML = placeholderHtml;
      msgArea.appendChild(d);
    {rdelim}
  {rdelim}

  function setActiveConv(convId) {ldelim}
    document.querySelectorAll('.phpclaw-conv-item').forEach(function (el) {ldelim}
      el.classList.toggle('active', el.dataset.convId === convId);
    {rdelim});
  {rdelim}

  function prependConvItem(convId, title, when) {ldelim}
    var existing = document.querySelector('[data-conv-id="' + convId + '"]');
    if (existing) {ldelim}
      existing.querySelector('.phpclaw-conv-item-time').textContent = when || 'just now';
      convList.insertBefore(existing, convList.firstChild);
      return;
    {rdelim}
    var emptyEl = document.getElementById('phpclaw-empty-sidebar');
    if (emptyEl) emptyEl.remove();
    var item = document.createElement('div');
    item.className = 'phpclaw-conv-item';
    item.dataset.convId = convId;
    item.dataset.title  = (title || '').toLowerCase();
    item.innerHTML =
      '<div class="phpclaw-conv-item-title">' + escHtml(title || 'New conversation') + '</div>' +
      '<div class="phpclaw-conv-item-time">' + escHtml(when || 'just now') + '</div>';
    item.addEventListener('click', function () {ldelim} loadConversation(convId); {rdelim});
    convList.insertBefore(item, convList.firstChild);
  {rdelim}

  function loadConversation(convId) {ldelim}
    if (sendBtn.disabled) return;
    currentConvId = convId;
    setActiveConv(convId);
    clearMessages('');
    showTyping();

    var body = new URLSearchParams();
    body.append('conversation_id', convId);

    fetch(URL_LOAD, {ldelim} method: 'POST', headers: {ldelim}'Content-Type':'application/x-www-form-urlencoded'{rdelim}, body: body.toString() {rdelim})
      .then(function (r) {ldelim} return phpclawAssertType(r, 'application/json').json(); {rdelim})
      .then(function (data) {ldelim}
        removeTyping();
        clearMessages('');
        if (data.success && data.messages && data.messages.length) {ldelim}
          chatHeader.textContent = data.title || 'Conversation';
          data.messages.forEach(function (m) {ldelim}
            if (m.role === 'tool') {ldelim}
              renderToolCard(m.tool_name, m.tool_input, m.tool_result);
            {rdelim} else {ldelim}
              renderBubble(m.role, m.content);
            {rdelim}
          {rdelim});
        {rdelim} else if (data.success) {ldelim}
          clearMessages('<h4>Conversation started</h4><p>No messages stored yet.</p>');
        {rdelim} else {ldelim}
          clearMessages('<h4>Error</h4><p>' + escHtml(data.error || 'Could not load conversation.') + '</p>');
        {rdelim}
        scrollBottom();
      {rdelim})
      .catch(function (err) {ldelim}
        removeTyping();
        clearMessages('<h4>Error</h4><p>' + escHtml(err.message) + '</p>');
      {rdelim});
  {rdelim}

  if (searchEl) {ldelim}
    searchEl.addEventListener('input', function () {ldelim}
      var q = searchEl.value.toLowerCase().trim();
      document.querySelectorAll('.phpclaw-conv-item').forEach(function (item) {ldelim}
        item.style.display = (!q || (item.dataset.title || '').indexOf(q) !== -1) ? '' : 'none';
      {rdelim});
    {rdelim});
  {rdelim}

  if (newChatBtn) {ldelim}
    newChatBtn.addEventListener('click', function () {ldelim}
      if (sendBtn.disabled) return;
      currentConvId = '';
      setActiveConv('');
      chatHeader.textContent = 'New Chat';
      clearMessages('<h4>New Chat</h4><p>Ask anything about your PrestaShop store.</p>');
      if (inputEl) inputEl.focus();
    {rdelim});
  {rdelim}

  function renderErrorBubble(text) {ldelim}
    var wrap = renderBubble('assistant', '\u26A0 ' + text);
    var b = wrap.querySelector('.phpclaw-bubble');
    if (b) {ldelim} b.style.background = '#fff5f5'; b.style.borderColor = '#f8d7da'; {rdelim}
    return wrap;
  {rdelim}

  function sendMessage() {ldelim}
    var msg = inputEl.value.trim();
    if (!msg || sendBtn.disabled) return;
    inputEl.value = '';
    autoResize();
    var welcome = document.getElementById('phpclaw-welcome');
    if (welcome) welcome.remove();

    renderBubble('user', msg);
    sendBtn.disabled = true;
    showTyping();

    var pendingCards   = {ldelim}{rdelim};
    var assistantWrap  = null;
    var assistantText  = '';
    var doneData       = null;
    var sawError       = false;

    function handleEvent(ev, data) {ldelim}
      if (ev === 'tool_before') {ldelim}
        removeTyping();
        pendingCards[data.tool_name] = renderPendingCard(data.tool_name, data.tool_input);
      {rdelim} else if (ev === 'tool_after') {ldelim}
        var ph = pendingCards[data.tool_name];
        if (ph) {ldelim} ph.remove(); delete pendingCards[data.tool_name]; {rdelim}
        renderToolCard(data.tool_name, data.tool_input, data.tool_result);
      {rdelim} else if (ev === 'chunk') {ldelim}
        removeTyping();
        var chunkText = data.text || '';
        // Skip empty/whitespace-only chunks: a tool-call iteration can emit blank
        // tokens before the tool runs, creating the bubble then would leave an
        // empty assistant bubble (a blank line) above the tool card. Build the
        // bubble only once real reply text arrives (which is after the tool cards).
        if (chunkText !== '') {ldelim}
          if (!assistantWrap) {ldelim} assistantWrap = renderBubble('assistant', ''); {rdelim}
          assistantText += chunkText;
          var bb = assistantWrap.querySelector('.phpclaw-bubble');
          if (bb) {ldelim} bb.textContent = assistantText; {rdelim}
          scrollBottom();
        {rdelim}
      {rdelim} else if (ev === 'done') {ldelim}
        doneData = data;
      {rdelim} else if (ev === 'error') {ldelim}
        removeTyping();
        sawError = true;
        renderErrorBubble(data.message || 'Unknown error');
      {rdelim}
    {rdelim}

    function finalize() {ldelim}
      removeTyping();

      // Remove any pending tool cards that never received a tool_after frame
      // (tool errored or stream cut before completion).
      Object.keys(pendingCards).forEach(function (name) {ldelim}
        var el = pendingCards[name];
        if (el && el.parentNode) {ldelim} el.remove(); {rdelim}
        delete pendingCards[name];
      {rdelim});

      if (doneData) {ldelim}
        // Tool cards are rendered live on tool_after only.
        // The done frame's tool_calls are NOT re-rendered here, doing so could
        // add an empty card (blank line) when a done-frame tool_result is empty.
        var finalText = doneData.text || assistantText || '';
        if (!assistantWrap && finalText) {ldelim}
          assistantWrap = renderBubble('assistant', finalText);
        {rdelim} else if (assistantWrap) {ldelim}
          var b = assistantWrap.querySelector('.phpclaw-bubble');
          if (b) {ldelim}
            if (finalText) {ldelim}
              b.textContent = finalText;
            {rdelim} else {ldelim}
              // No text in done frame and no streamed chunks, remove empty bubble.
              assistantWrap.remove();
              assistantWrap = null;
            {rdelim}
          {rdelim}
        {rdelim}

        var newId = doneData.conversation_id || '';
        if (newId) {ldelim}
          var isNew = doneData.is_new || (newId !== currentConvId);
          currentConvId = newId;
          var titleText = doneData.title ||
            (msg.length > 60 ? msg.substring(0, 60) + '\u2026' : msg);
          if (isNew || chatHeader.textContent === 'New Chat') {ldelim}
            chatHeader.textContent = titleText;
          {rdelim}
          prependConvItem(newId, chatHeader.textContent, 'just now');
          setActiveConv(newId);
        {rdelim}
      {rdelim} else if (!sawError && !assistantWrap) {ldelim}
        renderErrorBubble('No response.');
      {rdelim}
      scrollBottom();
      sendBtn.disabled = false;
      if (inputEl) inputEl.focus();
    {rdelim}

    var body = new URLSearchParams();
    body.append('message', msg);
    body.append('conversation_id', currentConvId || '');

    fetch(URL_STREAM, {ldelim} method: 'POST', headers: {ldelim}'Content-Type':'application/x-www-form-urlencoded'{rdelim}, body: body.toString() {rdelim})
      .then(function (resp) {ldelim}
        phpclawAssertType(resp, 'text/event-stream');
        if (!resp.body) {ldelim} throw new Error('Unexpected server response (no stream body).'); {rdelim}
        var reader  = resp.body.getReader();
        var decoder = new TextDecoder();
        var buffer  = '';
        function pump() {ldelim}
          return reader.read().then(function (res) {ldelim}
            if (res.done) {ldelim}
              buffer += decoder.decode();
              parseSse(buffer + '\n\n', handleEvent);
              finalize();
              return;
            {rdelim}
            buffer += decoder.decode(res.value, {ldelim} stream: true {rdelim});
            buffer = parseSse(buffer, handleEvent);
            return pump();
          {rdelim});
        {rdelim}
        return pump();
      {rdelim})
      .catch(function (err) {ldelim}
        removeTyping();
        renderErrorBubble('Request failed: ' + err.message);
        scrollBottom();
        sendBtn.disabled = false;
        if (inputEl) inputEl.focus();
      {rdelim});
  {rdelim}

  if (sendBtn) sendBtn.addEventListener('click', sendMessage);
  if (inputEl) {ldelim}
    inputEl.addEventListener('keydown', function (e) {ldelim}
      if (e.key === 'Enter' && !e.shiftKey) {ldelim} e.preventDefault(); sendMessage(); {rdelim}
    {rdelim});
    inputEl.addEventListener('input', autoResize);
  {rdelim}

  // Wire click → loadConversation on the server-rendered sidebar items so a
  // page reload keeps the conversation history clickable.
  document.querySelectorAll('#phpclaw-conv-list .phpclaw-conv-item').forEach(function (item) {ldelim}
    item.addEventListener('click', function () {ldelim}
      var cid = item.getAttribute('data-conv-id');
      if (cid) {ldelim} loadConversation(cid); {rdelim}
    {rdelim});
  {rdelim});

  function autoResize() {ldelim}
    if (!inputEl) return;
    inputEl.style.height = 'auto';
    inputEl.style.height = Math.min(inputEl.scrollHeight, 160) + 'px';
  {rdelim}

  document.querySelectorAll('.phpclaw-quick').forEach(function (btn) {ldelim}
    btn.addEventListener('click', function () {ldelim}
      if (inputEl) {ldelim} inputEl.value = btn.dataset.prompt; {rdelim}
      sendMessage();
    {rdelim});
  {rdelim});
{rdelim})();
</script>
