# Upgrading phpClaw for Joomla

## Update

**Automatic.** Each extension declares an update server, so new builds appear under
**Components → Joomla! Update** and **Extensions → Manage → Update**.

**Manual.** **Extensions → Manage → Install → Upload Package File**, upload the package ZIP and
confirm overwrite.

Joomla runs the install scripts either way. The package enables both plugins it ships, **System -
phpClaw** and **Web Services - phpClaw**, on install and on update, because Joomla stores newly
installed plugins disabled. If you had deliberately switched one off, switch it off again after
updating.

Afterwards confirm your provider, API key and model under **Extensions → Plugins → System -
phpClaw**. Settings live in the plugin's parameters, not on the component's Options screen.

## Rolling back

Back up the database first, because uninstalling drops all phpClaw data. Uninstall via
**Extensions → Manage → Manage → Uninstall**, then upload the older package ZIP.

## Uninstalling

Uninstalling the component drops `#__phpclaw_conversations`, `#__phpclaw_messages` and
`#__phpclaw_memory`. There is no keep-data option. Disabling the plugin instead leaves everything
in place.

## Support

- Documentation: [phpclaw.ai/docs](https://phpclaw.ai/docs)
- GitHub: [github.com/phpclaw-php/phpclaw-monorepo](https://github.com/phpclaw-php/phpclaw-monorepo)
