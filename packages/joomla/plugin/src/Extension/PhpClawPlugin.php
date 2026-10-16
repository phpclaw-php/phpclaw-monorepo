<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Extension;

use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Joomla\Component\Administrator\Console\ChatCommand;
use PhpClaw\Joomla\Component\Administrator\Console\McpServerCommand;
use PhpClaw\Joomla\Component\Administrator\Console\PhpClawCommand;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineFactory;
use PhpClaw\Joomla\Component\Administrator\Engine\PhpClawConfig;

/**
 * phpClaw System Plugin for Joomla 4/5/6.
 */
final class PhpClawPlugin extends CMSPlugin implements SubscriberInterface
{
    private const SECRET_FIELDS = ['api_key', 'fallback_api_key', 'cloud_key', 'cloud_signing_secret'];

    private const SECRET_MASK = '**********';

    protected $autoloadLanguage = true;

    /**
     * Return the events this plugin subscribes to.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'application.before_execute' => 'registerConsoleCommand',
            'onContentPrepareForm' => 'prepareSettingsForm',
            'onExtensionBeforeSave' => 'keepStoredSecrets',
        ];
    }

    /**
     * Prepare the phpClaw settings form: show a mask instead of each stored secret, then drop the cloud fields when cloud is absent.
     *
     * @param  Event  $event  The onContentPrepareForm event.
     * @return void
     */
    public function prepareSettingsForm(Event $event): void
    {
        $form = self::eventArgument($event, 'subject', 0);
        $data = self::eventArgument($event, 'data', 1);

        if ($form instanceof Form && $form->getName() === 'com_plugins.plugin' && self::isPhpClawPlugin($data) && is_object($data) && is_array($data->params ?? null)) {
            foreach (self::SECRET_FIELDS as $field) {
                if ((string) ($data->params[$field] ?? '') !== '') {
                    $data->params[$field] = self::SECRET_MASK;
                }
            }
        }

        $this->hideCloudFieldsIfUnavailable($event);
    }

    /**
     * Before the phpClaw settings are saved, put back each stored secret the form sent as the mask or left empty.
     *
     * @param  Event  $event  The onExtensionBeforeSave event.
     * @return void
     */
    public function keepStoredSecrets(Event $event): void
    {
        $table = self::eventArgument($event, 'subject', 1);

        if (self::eventArgument($event, 'context', 0) !== 'com_plugins.plugin' || ! self::isPhpClawPlugin($table)) {
            return;
        }

        $posted = json_decode((string) ($table->params ?? ''), true);

        if (! is_array($posted)) {
            return;
        }

        $stored = EngineFactory::getPluginParams();

        foreach (self::SECRET_FIELDS as $field) {
            $value = trim((string) ($posted[$field] ?? ''));

            if ($value === '' || PhpClawConfig::isMaskedSecret($value)) {
                $posted[$field] = (string) $stored->get($field, '');
            }
        }

        $table->params = (new Registry($posted))->toString();
    }

    /**
     * Read an event argument by its Joomla 5/6 name, falling back to the Joomla 4 position.
     *
     * @param  Event  $event  Event to read.
     * @param  string  $name  Named argument.
     * @param  int  $position  Positional argument.
     * @return mixed The argument, or null when absent.
     */
    private static function eventArgument(Event $event, string $name, int $position): mixed
    {
        return $event->getArgument($name) ?? $event->getArgument((string) $position);
    }

    /**
     * Whether a plugin row or form data object belongs to this system plugin.
     *
     * @param  mixed  $item  Plugin table row or form data.
     * @return bool True for the phpClaw system plugin.
     */
    private static function isPhpClawPlugin(mixed $item): bool
    {
        $folder = is_object($item) ? ($item->folder ?? null) : (is_array($item) ? ($item['folder'] ?? null) : null);
        $element = is_object($item) ? ($item->element ?? null) : (is_array($item) ? ($item['element'] ?? null) : null);

        return $folder === 'system' && $element === 'phpclaw';
    }

    /**
     * Remove the cloud-only fields from the plugin params form when the optional cloud package is absent.
     *
     * @param  Event  $event  The onContentPrepareForm event.
     * @return void
     */
    public function hideCloudFieldsIfUnavailable(Event $event): void
    {
        $args = method_exists($event, 'getArguments') ? $event->getArguments() : [];
        $form = $args[0] ?? null;
        $data = $args[1] ?? null;

        if (! ($form instanceof Form) || $form->getName() !== 'com_plugins.plugin') {
            return;
        }

        $folder = is_object($data) ? ($data->folder ?? null) : ($data['folder'] ?? null);
        $element = is_object($data) ? ($data->element ?? null) : ($data['element'] ?? null);

        if ($folder !== 'system' || $element !== 'phpclaw') {
            return;
        }

        if (class_exists(CloudManager::class)) {
            return;
        }

        $form->removeField('cloud_key', 'params');
        $form->removeField('cloud_signing_secret', 'params');
        $form->removeField('cloud_disable', 'params');

        $xml = $form->getXml();
        $fieldset = $xml->xpath("//fieldset[@name='cloud']");
        if (! empty($fieldset)) {
            $dom = dom_import_simplexml($fieldset[0]);
            $dom->parentNode->removeChild($dom);
        }
    }

    /**
     * Register phpClaw CLI commands with the Joomla Console Application.
     *
     * @param  Event  $event  The application event.
     * @return void
     */
    public function registerConsoleCommand(Event $event): void
    {
        $app = $this->getApplication();

        if (! ($app instanceof ConsoleApplication)) {
            return;
        }

        if (! class_exists(PhpClawCommand::class)) {
            $autoload = JPATH_ADMINISTRATOR.'/components/com_phpclaw/vendor/autoload.php';

            if (is_file($autoload)) {
                require_once $autoload;
            }
        }

        if (! class_exists(PhpClawCommand::class) || ! class_exists(McpServerCommand::class)) {
            return;
        }

        $agentFactory = static fn (string $provider = '', string $model = ''): ClawInterface => (new EngineFactory)->build(EngineFactory::sessionParams(EngineFactory::getPluginParams(), $provider, $model));

        $app->addCommand(new PhpClawCommand($agentFactory));
        $app->addCommand(new ChatCommand($agentFactory));
        $app->addCommand(new McpServerCommand);
    }
}
