<?php

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use PhpClaw\Joomla\Component\Administrator\View\Guide\HtmlView;

defined('_JEXEC') || exit;

/** @var HtmlView $this */
$model = $this->getModel();
$prefix = $model->getTablePrefix();

$currentTab = Factory::getApplication()->getInput()->getString('tab', 'quickstart');
$validTabs = ['quickstart', 'tools', 'providers', 'memory', 'guards', 'hooks', 'skills', 'rest', 'cli', 'privacy'];
if (! in_array($currentTab, $validTabs, true)) {
    $currentTab = 'quickstart';
}

$baseUrl = Route::_('index.php?option=com_phpclaw&view=guide', false);
$siteUrl = Uri::root();
$chatUrl = Route::_('index.php?option=com_phpclaw&view=chat', false);
?>
<div class="pc-guide-page">

    <div class="pc-guide-tabs" role="tablist" aria-label="<?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TABS')) ?>">
        <?php foreach ($validTabs as $tabKey) {
            $tabLabel = Text::_('COM_PHPCLAW_GUIDE_TAB_'.strtoupper($tabKey));
            ?>
        <a href="<?= $this->escape($baseUrl.'&tab='.$tabKey) ?>"
           role="tab"
           aria-selected="<?= $currentTab === $tabKey ? 'true' : 'false' ?>"
           aria-controls="pc-guide-tabpanel"
           class="pc-guide-tab <?= $currentTab === $tabKey ? 'active' : '' ?>">
            <?= $this->escape($tabLabel) ?>
        </a>
        <?php } ?>
    </div>

    <div class="pc-guide-content" id="pc-guide-tabpanel" role="tabpanel" aria-label="<?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TAB_'.strtoupper($currentTab))) ?>">

    <?php if ($currentTab === 'quickstart') { ?>
        <h2 class="pc-guide-heading"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_QUICKSTART_TITLE')) ?></h2>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_QUICKSTART_INTRO')) ?></p>

        <ol class="pc-guide-steps">
            <li>
                <strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_QUICKSTART_STEP1_TITLE')) ?></strong>
                <span class="badge bg-secondary ms-1"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ADMIN_ONLY_BADGE')) ?></span>
                <p><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_QUICKSTART_STEP1_BODY')) ?></p>
            </li>
            <li>
                <strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_QUICKSTART_STEP2_TITLE')) ?></strong>
                <span class="badge bg-secondary ms-1"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ADMIN_ONLY_BADGE')) ?></span>
                <p><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_QUICKSTART_STEP2_BODY')) ?></p>
            </li>
            <li>
                <strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_QUICKSTART_STEP3_TITLE')) ?></strong>
                <p>
                    <?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_QUICKSTART_STEP3_BODY')) ?>
                    <a href="<?= $this->escape($chatUrl) ?>" class="btn btn-sm btn-primary ms-2">
                        <?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_QUICKSTART_OPEN_CHAT')) ?>
                    </a>
                </p>
            </li>
            <li>
                <strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_QUICKSTART_STEP4_TITLE')) ?></strong>
                <p><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_QUICKSTART_STEP4_BODY')) ?></p>
            </li>
        </ol>

    <?php } elseif ($currentTab === 'tools') { ?>
        <h2 class="pc-guide-heading"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TOOLS_TITLE')) ?></h2>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TOOLS_INTRO')) ?></p>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TOOLS_ACL')) ?></p>

        <?php $registeredTools = $model->getRegisteredTools(); ?>
        <h3><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TOOLS_BUILTIN')) ?></h3>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th class="pc-w-160"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_NAME')) ?></th>
                        <th class="pc-w-40p"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_DESCRIPTION')) ?></th>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_EXAMPLE_PROMPTS')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registeredTools as $t) { ?>
                    <tr>
                        <td><strong><?= $this->escape($t['name']) ?></strong></td>
                        <td><?= $this->escape($t['description']) ?></td>
                        <td class="pc-tool-prompts"><?= $this->escape($t['prompts'] ?? '') ?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <h3><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TOOLS_CORE_UTIL')) ?> <small class="text-muted"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TOOLS_CORE_UTIL_NOTE')) ?></small></h3>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th class="pc-w-160"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_EXTRA_TOOL')) ?></th>
                        <th class="pc-w-140"><?= $this->escape(Text::_('COM_PHPCLAW_STATUS')) ?></th>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_EXTRA_NOTES')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($model->getCoreUtilityTools() as $t) { ?>
                    <tr>
                        <td><code><?= $this->escape($t['tool']) ?></code></td>
                        <td>
                            <?php if ($t['deprecated']) { ?>
                            <span class="badge bg-warning"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_DEPRECATED')) ?></span>
                            <?php } else { ?>
                            <span class="badge bg-success"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_READY')) ?></span>
                            <?php } ?>
                        </td>
                        <td><?= $this->escape($t['description']) ?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="alert alert-warning">
            <?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TOOLS_SHELL_SCOPE')) ?>
        </div>

        <div class="alert alert-secondary">
            <strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_EXTRA_CUSTOM')) ?></strong>
            <p class="mb-0"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_EXTRA_CUSTOM_DESC')) ?>
                <a href="https://phpclaw.ai/enterprise" target="_blank" rel="noopener" class="pc-link-aa"><?= $this->escape(Text::_('COM_PHPCLAW_ENTERPRISE_LINK')) ?></a>
            </p>
        </div>


    <?php } elseif ($currentTab === 'memory') { ?>
        <h2 class="pc-guide-heading"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_MEMORY_TITLE')) ?></h2>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_MEMORY_DRIVERS_INTRO')) ?></p>

        <?php $memoryDrivers = $model->getDiscoveredMemoryDrivers(); ?>
        <h3 class="pc-mt-24"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_MEMORY_AUTODISCOVERED')) ?></h3>
        <?php if ($memoryDrivers === []) { ?>
            <p class="pc-text-muted"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_MEMORY_NONE')) ?></p>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th class="pc-w-140"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_DRIVER')) ?></th>
                        <th class="pc-w-200"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_LABEL')) ?></th>
                        <th class="pc-w-90"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_SOURCE')) ?></th>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_CLASS')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($memoryDrivers as $m) { ?>
                    <tr>
                        <td><code><?= $this->escape($m['driver']) ?></code></td>
                        <td><?= $this->escape($m['label']) ?></td>
                        <td><?= $this->escape($m['source']) ?></td>
                        <td><small><code><?= $this->escape($m['class']) ?></code></small></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>

        <h3 class="pc-mt-24"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_MEMORY_TABLES')) ?></h3>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_MEMORY_INTRO')) ?></p>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th class="pc-w-260"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TABLE')) ?></th>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PURPOSE')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code><?= $this->escape($prefix.'phpclaw_conversations') ?></code></td>
                        <td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TABLE_CONVERSATIONS')) ?></td>
                    </tr>
                    <tr>
                        <td><code><?= $this->escape($prefix.'phpclaw_messages') ?></code></td>
                        <td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TABLE_MESSAGES')) ?></td>
                    </tr>
                    <tr>
                        <td><code><?= $this->escape($prefix.'phpclaw_memory') ?></code></td>
                        <td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TABLE_MEMORY')) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="alert alert-info">
            <strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_BACKUP_TIP_LABEL')) ?></strong> <?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_BACKUP_TIP')) ?>
        </div>


    <?php } elseif ($currentTab === 'rest') { ?>
        <h2 class="pc-guide-heading"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_REST_TITLE')) ?></h2>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_REST_INTRO')) ?></p>

        <h2 class="pc-guide-heading"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_WS_TITLE')) ?></h2>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_WS_INTRO')) ?></p>

        <div class="alert alert-info">
            <strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_WS_PREREQ')) ?></strong>
            <ol class="mb-0">
                <li><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_WS_PREREQ_1')) ?></li>
                <li><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_WS_PREREQ_2')) ?></li>
                <li><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_WS_PREREQ_3')) ?></li>
            </ol>
        </div>

        <h3>POST <?= $this->escape($siteUrl) ?>api/index.php/v1/phpclaw/chat</h3>
        <div class="table-responsive">
            <table class="table table-striped pc-table-narrow">
                <tr><th class="pc-w-120"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ENDPOINT')) ?></th><td><code>api/index.php/v1/phpclaw/chat</code></td></tr>
                <tr><th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_METHOD')) ?></th><td><code>POST</code></td></tr>
                <tr><th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_AUTH')) ?></th><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_WS_AUTH_DESC')) ?></td></tr>
                <tr><th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_WS_ACCEPT')) ?></th><td><code>application/vnd.api+json</code></td></tr>
                <tr><th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_BODY')) ?></th><td><code>{"message":"your prompt"}</code></td></tr>
            </table>
        </div>

        <pre class="pc-code-block">curl -X POST <?= $this->escape($siteUrl) ?>api/index.php/v1/phpclaw/chat \
  -H "Authorization: Bearer YOUR_JOOMLA_API_TOKEN" \
  -H "Accept: application/vnd.api+json" \
  -H "Content-Type: application/json" \
  -d '{"message":"how many articles were published today?"}'</pre>

        <h4><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PARAMS')) ?></h4>
        <div class="table-responsive">
            <table class="table table-striped pc-table-narrow">
                <thead><tr><th class="pc-w-160"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_FIELD')) ?></th><th class="pc-w-80"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_TYPE')) ?></th><th class="pc-w-80"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_REQUIRED')) ?></th><th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_DESCRIPTION')) ?></th></tr></thead>
                <tbody>
                    <tr><td><code>message</code></td><td>string</td><td><?= $this->escape(Text::_('JYES')) ?></td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PARAM_MESSAGE')) ?></td></tr>
                    <tr><td><code>conversation_id</code></td><td>string</td><td><?= $this->escape(Text::_('JNO')) ?></td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PARAM_CONVID')) ?></td></tr>
                </tbody>
            </table>
        </div>

        <h4><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_RESPONSE')) ?></h4>
        <pre class="pc-code-block">{
  "success": true,
  "data": {
    "text": "Here are the recent articles...",
    "provider": "ollama",
    "model": "qwen2.5:7b",
    "tokens": 312,
    "iterations": 2,
    "conversation_id": "01JQABCDE...",
    "tool_calls": [
      { "tool_name": "joomla_articles", "tool_input": { "limit": 3 }, "tool_result": "{...}" }
    ]
  }
}</pre>

        <h4><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ERRORS')) ?></h4>
        <div class="table-responsive">
            <table class="table table-striped pc-table-narrow">
                <thead><tr><th class="pc-w-70">HTTP</th><th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_MEANING')) ?></th></tr></thead>
                <tbody>
                    <tr><td>400</td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ERR_400')) ?></td></tr>
                    <tr><td>401</td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ERR_401')) ?></td></tr>
                    <tr><td>403</td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ERR_403')) ?></td></tr>
                    <tr><td>406</td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ERR_406')) ?></td></tr>
                    <tr><td>500</td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ERR_500')) ?></td></tr>
                </tbody>
            </table>
        </div>

        <h3><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_STREAM_TITLE')) ?></h3>
        <p><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_STREAM_INTRO')) ?></p>
        <div class="table-responsive">
            <table class="table table-striped pc-table-narrow">
                <tr><th class="pc-w-120"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ENDPOINT')) ?></th><td><code>api/index.php/v1/phpclaw/chat/stream</code></td></tr>
                <tr><th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_METHOD')) ?></th><td><code>POST</code></td></tr>
                <tr><th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_AUTH')) ?></th><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_WS_AUTH_DESC')) ?></td></tr>
                <tr><th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_WS_ACCEPT')) ?></th><td><code>text/event-stream</code></td></tr>
                <tr><th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_BODY')) ?></th><td><code>{"message":"your prompt"}</code></td></tr>
            </table>
        </div>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_WS_ACCEPT_STREAM_NOTE')) ?></p>

        <h4><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_STREAM_EVENTS')) ?></h4>
        <div class="table-responsive">
            <table class="table table-striped pc-table-narrow">
                <thead><tr><th class="pc-w-140"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_STREAM_EVENT')) ?></th><th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_STREAM_PAYLOAD')) ?></th></tr></thead>
                <tbody>
                    <tr><td><code>tool_before</code></td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_STREAM_EVT_TOOL_BEFORE')) ?></td></tr>
                    <tr><td><code>tool_after</code></td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_STREAM_EVT_TOOL_AFTER')) ?></td></tr>
                    <tr><td><code>chunk</code></td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_STREAM_EVT_CHUNK')) ?></td></tr>
                    <tr><td><code>done</code></td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_STREAM_EVT_DONE')) ?></td></tr>
                    <tr><td><code>error</code></td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_STREAM_EVT_ERROR')) ?></td></tr>
                </tbody>
            </table>
        </div>

        <h4><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_RESPONSE')) ?></h4>
        <pre class="pc-code-block">event: tool_before
data: {"tool_name":"joomla_articles","tool_input":{"limit":3}}

event: tool_after
data: {"tool_name":"joomla_articles","tool_input":{"limit":3},"tool_result":"{...}"}

event: done
data: {"text":"Here are 3 articles...","provider":"ollama","model":"qwen2.5:7b","tokens":312,"conversation_id":"01JQABCDE...","title":"list 3 articles?","iterations":2,"is_new":false,"tool_calls":[...]}</pre>

        <h4><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ERRORS')) ?></h4>
        <div class="table-responsive">
            <table class="table table-striped pc-table-narrow">
                <thead><tr><th class="pc-w-70">HTTP</th><th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_MEANING')) ?></th></tr></thead>
                <tbody>
                    <tr><td>400</td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ERR_400')) ?></td></tr>
                    <tr><td>401</td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ERR_401')) ?></td></tr>
                    <tr><td>403</td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ERR_403')) ?></td></tr>
                    <tr><td>406</td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ERR_406')) ?></td></tr>
                    <tr><td>200</td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ERR_STREAM_200')) ?></td></tr>
                </tbody>
            </table>
        </div>

        <div class="alert alert-info">
            <?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_STREAM_NOTE')) ?>
        </div>

        <h2 class="pc-guide-heading"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ADMIN_TITLE')) ?></h2>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ADMIN_INTRO')) ?></p>
        <div class="table-responsive">
            <table class="table table-striped pc-table-narrow">
                <thead><tr><th class="pc-w-50p"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_ENDPOINT')) ?></th><th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PURPOSE')) ?></th></tr></thead>
                <tbody>
                    <tr><td><code>task=api.send</code></td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PARAM_MESSAGE')) ?></td></tr>
                    <tr><td><code>task=api.stream</code></td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_STREAM_TITLE')) ?></td></tr>
                    <tr><td><code>task=api.loadConversation</code></td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_EP_LOAD')) ?></td></tr>
                    <?php if ($this->canManageAll) { ?>
                    <tr><td><code>task=api.testConnection</code></td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_EP_TEST')) ?></td></tr>
                    <tr><td><code>task=api.saveSettings</code></td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_EP_SAVE')) ?></td></tr>
                    <tr><td><code>task=api.enablePlugin</code></td><td><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_EP_ENABLE')) ?></td></tr>
                    <?php } ?>
                </tbody>
            </table>
            <?php if ($this->canManageAll) { ?>
            <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_EP_SAVE_FIELDS')) ?></p>
            <?php } ?>
        </div>

    <?php } elseif ($currentTab === 'cli') { ?>
        <h2 class="pc-guide-heading"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_CLI_TITLE')) ?></h2>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_CLI_INTRO')) ?></p>

        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th class="pc-w-420"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COMMAND')) ?></th>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_DESCRIPTION')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                        $commands = [
                            ['cmd' => 'php cli/joomla.php phpclaw "your prompt"',                  'desc' => Text::_('COM_PHPCLAW_GUIDE_CMD_SEND')],
                            ['cmd' => 'php cli/joomla.php phpclaw:mcp-server',                     'desc' => Text::_('COM_PHPCLAW_GUIDE_CMD_MCP_SERVER')],
                        ];
        foreach ($commands as $row) { ?>
                    <tr>
                        <td><code class="small"><?= $this->escape($row['cmd']) ?></code></td>
                        <td><?= $this->escape($row['desc']) ?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="alert alert-info">
            <?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_CLI_OWNERSHIP')) ?>
        </div>

    <?php } elseif ($currentTab === 'providers') { ?>
        <h2 class="pc-guide-heading"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PROVIDERS_TITLE')) ?></h2>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PROVIDERS_INTRO')) ?></p>

        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th class="pc-w-140"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PROVIDER')) ?></th>
                        <th class="pc-w-120"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PROVIDER_KEY')) ?></th>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PROVIDER_MODELS')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($model->getProviders() as $p) { ?>
                    <tr>
                        <td><strong><?= $this->escape($p['name']) ?></strong></td>
                        <td><code><?= $this->escape($p['key']) ?></code></td>
                        <td><?= $this->escape($p['models']) ?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="alert alert-info">
            <?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PROVIDERS_NOTE')) ?>
        </div>


    <?php } elseif ($currentTab === 'guards') { ?>
        <h2 class="pc-guide-heading"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_GUARDS_TITLE')) ?></h2>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_GUARDS_INTRO')) ?></p>

        <?php $guards = $model->getDiscoveredGuards(); ?>
        <h3 class="pc-mt-24"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_GUARDS_DISCOVERED')) ?></h3>
        <?php if ($guards === []) { ?>
            <p class="pc-text-muted"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_GUARDS_NONE')) ?></p>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th class="pc-w-160"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_NAME')) ?></th>
                        <th class="pc-w-180"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_LABEL')) ?></th>
                        <th class="pc-w-80"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_PRIORITY')) ?></th>
                        <th class="pc-w-80"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_DEFAULT')) ?></th>
                        <th class="pc-w-80"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_SOURCE')) ?></th>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_CLASS')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guards as $g) { ?>
                    <tr>
                        <td><code><?= $this->escape($g['name']) ?></code></td>
                        <td><?= $this->escape($g['label']) ?></td>
                        <td><?= $this->escape((string) $g['priority']) ?></td>
                        <td><?= $g['enabled_by_default'] ? '✔' : '✗' ?></td>
                        <td><?= $this->escape($g['source']) ?></td>
                        <td><small><code><?= $this->escape($g['class']) ?></code></small></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>


    <?php } elseif ($currentTab === 'hooks') { ?>
        <h2 class="pc-guide-heading"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_HOOKS_TITLE')) ?></h2>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_HOOKS_INTRO')) ?></p>

        <?php $hooks = $model->getDiscoveredHooks(); ?>
        <h3 class="pc-mt-24"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_HOOKS_REGISTERED')) ?></h3>
        <?php if ($hooks === []) { ?>
            <p class="pc-text-muted"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_HOOKS_NONE')) ?></p>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th class="pc-w-160"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_EVENT')) ?></th>
                        <th class="pc-w-160"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_NAME')) ?></th>
                        <th class="pc-w-80"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_PRIORITY')) ?></th>
                        <th class="pc-w-80"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_DEFAULT')) ?></th>
                        <th class="pc-w-80"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_SOURCE')) ?></th>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_CLASS')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hooks as $h) { ?>
                    <tr>
                        <td><code><?= $this->escape($h['event']) ?></code></td>
                        <td><?= $this->escape($h['name']) ?></td>
                        <td><?= $this->escape((string) $h['priority']) ?></td>
                        <td><?= $h['enabled_by_default'] ? '✔' : '✗' ?></td>
                        <td><?= $this->escape($h['source']) ?></td>
                        <td><small><code><?= $this->escape($h['class']) ?></code></small></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>


    <?php } elseif ($currentTab === 'skills') { ?>
        <h2 class="pc-guide-heading"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_SKILLS_TITLE')) ?></h2>
        <p class="pc-guide-intro"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_SKILLS_INTRO')) ?></p>

        <?php $skills = $model->getDiscoveredSkills(); ?>
        <h3 class="pc-mt-24"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_SKILLS_DISCOVERED')) ?></h3>
        <?php if ($skills === []) { ?>
            <p class="pc-text-muted"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_SKILLS_NONE')) ?></p>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th class="pc-w-180"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_NAME')) ?></th>
                        <th class="pc-w-180"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_LABEL')) ?></th>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_KEYWORDS')) ?></th>
                        <th class="pc-w-80"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_SOURCE')) ?></th>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_CLASS')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($skills as $s) { ?>
                    <tr>
                        <td><code><?= $this->escape($s['name']) ?></code></td>
                        <td><?= $this->escape($s['label']) ?></td>
                        <td><small><?= $this->escape(implode(', ', $s['keywords'])) ?></small></td>
                        <td><?= $this->escape($s['source']) ?></td>
                        <td><small><code><?= $this->escape($s['class']) ?></code></small></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>

        <h3 class="pc-mt-24"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_SKILLS_REMOTE')) ?></h3>
        <?php $remoteUrls = $model->getRemoteSkillUrls(); ?>
        <?php $remoteSkills = $model->getRemoteSkills(); ?>
        <?php if ($remoteUrls === []) { ?>
            <p class="pc-text-muted"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_SKILLS_REMOTE_NONE')) ?></p>
        <?php } elseif ($remoteSkills === []) { ?>
            <p class="pc-text-muted"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_SKILLS_REMOTE_UNREGISTERED')) ?></p>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th class="pc-w-180"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_NAME')) ?></th>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_LABEL')) ?></th>
                        <th><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_KEYWORDS')) ?></th>
                        <th class="pc-w-80"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_COL_SOURCE')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($remoteSkills as $s) { ?>
                    <tr>
                        <td><code><?= $this->escape($s['name']) ?></code></td>
                        <td><small><?= $this->escape($s['description']) ?></small></td>
                        <td><small><?= $this->escape(implode(', ', $s['keywords'])) ?></small></td>
                        <td>remote</td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <p class="pc-text-muted"><small><?= $this->escape(implode(', ', $remoteUrls)) ?></small></p>
        <?php } ?>


    <?php } elseif ($currentTab === 'privacy') { ?>
        <h3><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_HEADING')) ?></h3>
        <p><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_INTRO')) ?></p>

        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th></th>
                        <th>store_messages = <span class="text-success"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_ON')) ?></span></th>
                        <th>store_messages = <span class="text-danger"><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_OFF_LABEL')) ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_CONV_META')) ?></strong><br><small><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_CONV_META_SUB')) ?></small></td>
                        <td>✅ <?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_STORED')) ?></td>
                        <td>❌ <strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_NEVER')) ?></strong></td>
                    </tr>
                    <tr>
                        <td><strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_MSG_TEXT')) ?></strong><br><small><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_MSG_TEXT_SUB')) ?></small></td>
                        <td>✅ <?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_MSG_STORED')) ?></td>
                        <td>❌ <strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_NEVER')) ?></strong></td>
                    </tr>
                    <tr>
                        <td><strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_TOOL_DATA')) ?></strong><br><small><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_TOOL_DATA_SUB')) ?></small></td>
                        <td>✅ <?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_MSG_STORED')) ?></td>
                        <td>❌ <strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_NEVER')) ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="alert alert-info">
            <strong><?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_NOTE')) ?></strong>
        </div>

        <div class="alert alert-info">
            <strong>phpClaw Cloud:</strong> <?= $this->escape(Text::_('COM_PHPCLAW_GUIDE_PRIVACY_CLOUD')) ?>
        </div>

    <?php } ?>

    </div>

    <?php include __DIR__.'/../_community_card.php'; ?>
</div>
