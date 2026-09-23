{*
  phpClaw: Analytics Page
  PrestaShop 8 Back Office admin template (Bootstrap 4, Smarty)
*}

<div class="page-header d-print-none">
  <div class="container-xl">
    <div class="row g-2 align-items-center">
      <div class="col">
        <h2 class="page-title">phpClaw AI Agent: Analytics</h2>
      </div>
    </div>
  </div>
</div>

<div class="container-xl py-4">

  {* Stat cards *}
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="card h-100">
        <div class="card-body text-center">
          <div class="phpclaw-stat-hero">{$stats.total_conversations|intval}</div>
          <div class="text-muted phpclaw-stat-label">Total Conversations</div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card h-100">
        <div class="card-body text-center">
          <div class="phpclaw-stat-number">{$stats.total_messages|intval}</div>
          <div class="text-muted phpclaw-stat-label">Total Messages</div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card h-100">
        <div class="card-body text-center">
          <div class="phpclaw-stat-hero--accent">{$stats.active_24h|intval}</div>
          <div class="text-muted phpclaw-stat-label">Active (Last 24h)</div>
        </div>
      </div>
    </div>
  </div>

  {* Community Card *}
  {include file="./_community_card.tpl"}

</div>
