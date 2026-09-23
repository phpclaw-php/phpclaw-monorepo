<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Extension;

use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Joomla\Component\Administrator\Console\McpServerCommand;
use PhpClaw\Joomla\Component\Administrator\Console\PhpClawCommand;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineFactory;

/**
 * phpClaw System Plugin for Joomla 4/5/6.
 */
final class PhpClawPlugin extends CMSPlugin implements SubscriberInterface
{
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
            'onContentPrepareForm' => 'hideCloudFieldsIfUnavailable',
        ];
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

        $agentFactory = static fn (): ClawInterface => (new EngineFactory)->build(EngineFactory::getPluginParams());

        $app->addCommand(new PhpClawCommand($agentFactory));
        $app->addCommand(new McpServerCommand);
    }
}
