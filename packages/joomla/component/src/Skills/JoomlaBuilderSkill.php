<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Skills;

use PhpClaw\AutoDiscovery\Attributes\Skill;
use PhpClaw\Skills\Contracts\SkillInterface;

/**
 * Injects extension-generation rules when the user asks to build a plugin, component or module.
 * The name and description avoid the token "joomla", which would match nearly every prompt.
 */
#[Skill(
    name: 'extension_scaffolder',
    label: 'Extension Scaffolder',
    keywords: ['scaffold', 'manifest', 'boilerplate', 'generate', 'installable'],
    since: '0.1.0',
)]
final class JoomlaBuilderSkill implements SkillInterface
{
    /**
     * The unique skill identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'extension_scaffolder';
    }

    /**
     * One-line description of what this skill provides.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Scaffolding rules for producing an installable extension package';
    }

    /**
     * Keyword tags the matcher scores a message against.
     *
     * @return string[]
     */
    public function tags(): array
    {
        return ['scaffold', 'manifest', 'boilerplate', 'generate', 'installable'];
    }

    /**
     * The skill content injected into the prompt when this skill matches.
     *
     * @return string
     */
    public function content(): string
    {
        return <<<'MD'
## Joomla Plugin Generation Rules

### When these rules apply
They apply ONLY when the user has asked you to create, generate, scaffold or package a Joomla
extension. The keyword matcher is fuzzy, so this text is often supplied for messages that merely
mention Joomla, a plugin or an extension. If the user is asking a QUESTION about the site (how many
articles, which plugins are installed, list users, run a query), ignore everything below, answer the
question, and use the tools you were given. Do not describe how to build a plugin, do not write
files, and do not mention these rules.

When they do apply, follow them exactly. This skill builds system/content PLUGINS only (see the
scope note at the end).

### Workflow
1. State that you are building a Joomla plugin and its group (system, content, …).
2. Plan the file list first and state it.
3. Write each file with file_write (one file per call). Parent folders are created automatically.
4. After every PHP file: verify it parses (re-read it; check braces/quotes).
5. Package ONLY with joomla_zip_extension (never zip_package directly for extensions).

### Every plugin needs a manifest (root, exactly one):
{slug}.xml starts with the standard XML declaration line, then:
<extension type="plugin" group="{group}" method="upgrade">
  ...plugin nodes...
</extension>
The joomla_zip_extension tool will package a component or module manifest too, but this skill does
not generate those; see the scope note at the end. It refuses any folder whose manifest has no
<extension type="..."> element at all.

### Guards and namespacing:
- Non-namespaced entry files (module {slug}.php, tmpl files) MUST start with the standard JEXEC guard line: the backslash-defined _JEXEC check or die.
- Namespaced PSR-4 classes under src/ do NOT need _JEXEC (autoloaded, never web-reached).
- Never add a PHP closing tag to pure-PHP files. Omit it entirely.

### Plugin (type="plugin", group e.g. system/content):
{slug} is the SHORT name ONLY (e.g. helloworld). NEVER prefix it with plg_ or plg_system_.
Files (folder = {slug}):
  {slug}.xml            manifest: <extension type="plugin" group="system">; inside <files> put <filename plugin="{slug}">{slug}.php</filename>
  {slug}.php            bootstrap entry (the JEXEC guard line only); filename MUST equal {slug}
The manifest MUST declare the PSR-4 namespace: <namespace path="src">{Vendor}\Plugin\{Group}\{Name}</namespace>.
{Vendor} is the extension author's own namespace, chosen by whoever the plugin is for. Ask if it is not
obvious, otherwise derive it from the plugin name. Never use PhpClaw\Plugin\..., that is this project's
namespace, not the user's. Never use Joomla\Plugin\..., that is reserved for Joomla core plugins.
This single string MUST match the class's own namespace and the provider's use import EXACTLY (same case).
Omit it and Joomla never registers the autoloader mapping, the plugin class cannot load, and the very first
front-end request fatals with HTTP 500.
  services/provider.php DI provider returning a ServiceProviderInterface; PluginHelper::getPlugin('{group}', '{slug}') uses the SHORT slug
  src/Extension/{Name}.php  namespaced plugin class implementing SubscriberInterface
  language/en-GB/plg_{group}_{slug}.ini
CRITICAL: element/folder naming: at boot Joomla strips the plg_ prefix (ExtensionManagerTrait::bootPlugin)
and loads plugins/{group}/{slug}/services/provider.php. If the element/folder is plg_system_{slug}, Joomla
looks for plugins/system/system_{slug}/ instead, the provider is never found, and the plugin installs but
SILENTLY never loads. element = folder = <filename plugin> = {slug}, always the short name.
Register events via getSubscribedEvents(); read params from the plugin params.
To change page output (e.g. append a footer), subscribe to onAfterRender and rewrite the response via
the application getBody() / setBody() pair (str_ireplace before the closing body tag). Do NOT call the
document addCustomTag method (it targets the head, not the footer) and NEVER call the document
getCustomTag method. No such method exists (fatal).
services/provider.php MUST register a FACTORY CLOSURE (not a bare instance) and MUST call
the plugin setApplication() method. A plugin built without setApplication() has a null application at
event time, so getApplication() returns null and the plugin silently does nothing. The closure gets the
event dispatcher from the container (import Joomla\Event\DispatcherInterface and call
container->get(DispatcherInterface::class)), builds the plugin with new {Name}(dispatcher, []),
DISPATCHER FIRST, then the config array. Calls setApplication with the current application from
Factory::getApplication(), and returns it.
The Joomla 4/5/6 CMSPlugin constructor signature is (DispatcherInterface dispatcher, array config = []).
The dispatcher is the FIRST argument. Build it as new {Name}(dispatcher, []) (the second arg is the params
array, usually empty). NEVER new {Name}((array) PluginHelper::getPlugin(...)) with the config as the sole
argument. That is the Joomla 3 signature and fatals when the Joomla 4/5/6 DI container resolves the
plugin. NEVER new {Name}() with no args.
The plugin interface key is EXACTLY Joomla\CMS\Extension\PluginInterface. Import that class and register
under PluginInterface::class. There is NO Joomla\CMS\Plugin\PluginInterface; registering under that wrong
FQCN makes the ::class constant resolve to a bogus key that Joomla's loader never reads, so the plugin
loads but is never subscribed and its events never fire.
The event-listener method must accept the event: onAfterRender(EventInterface event): void.

### Components and modules: NOT supported yet
This skill only generates system/content PLUGINS, which are wiring-validated by joomla_zip_extension.
Component (com_*) and module (mod_*) generation is not yet supported here. If the user asks for a component
or module, say it is not yet available and stop. Do not scaffold one from these plugin rules.

### Language files (all types):
- The manifest <languages> block MUST carry folder="language", with paths relative to it:
  <languages folder="language"><language tag="en-GB">en-GB/plg_{group}_{slug}.ini</language></languages>
- Without folder="language" the ini is not installed and every LABEL/DESC renders as its raw constant.
- Plugin ini filename convention: plg_{group}_{slug}.ini (e.g. plg_system_helloworld.ini).

### Settings and I/O (all types):
- Read config from the extension params / module params; never hardcode.
- Escape on output with htmlspecialchars or the Joomla escape helper. Always.
- Sanitize input via Joomla\CMS\Filter\InputFilter or Factory input filtering.
- All queries through the Joomla DatabaseInterface with quoteName + bound params.

### Forbidden (generation fails review if present):
- Reading request input without a token check (Session::checkToken) where a form posts
- Dynamic code execution or OS commands: the eval / exec / system / passthru family, or base64-decoding user input
- Direct SQL without the query builder and bound parameters
- Writing outside the extension's own folder
- Inline script or style blobs in admin templates
MD;
    }
}
