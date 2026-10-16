<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Model;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineFactory;
use PhpClaw\Joomla\Component\Administrator\Engine\PhpClawConfig;
use PhpClaw\Joomla\Component\Administrator\Engine\ToolBuilder;
use PhpClaw\Tools\ToolRegistry;

final class AboutModel extends BaseDatabaseModel
{
    private const BUILT_IN_NAMESPACES = ['PhpClaw\\Joomla\\Component\\', 'PhpClaw\\Tools\\'];

    /**
     * Title of the About tools card: the live built-in tool count, or no number when the list cannot be built.
     *
     * @return string Translated card title.
     */
    public function getToolsTitle(): string
    {
        try {
            $config = PhpClawConfig::fromRegistry(EngineFactory::getPluginParams());
            $registry = new ToolRegistry;
            $registry->register((new ToolBuilder)->build($config, applyProfile: false), $config->toolDeny, ToolBuilder::TOOL_GROUPS);
        } catch (\Throwable) {
            return Text::_('COM_PHPCLAW_FEAT_TOOLS_TITLE');
        }

        return Text::sprintf('COM_PHPCLAW_FEAT_TOOLS_TITLE_COUNT', self::countBuiltIn($registry->all()));
    }

    /**
     * Count the tools this package and phpClaw core ship, leaving out tools other extensions add.
     *
     * @param  array<int, object>  $tools  Registered tool objects.
     * @return int Number of built-in tools.
     */
    private static function countBuiltIn(array $tools): int
    {
        return count(array_filter($tools, static function (object $tool): bool {
            if ((new \ReflectionClass($tool))->isAnonymous()) {
                return false;
            }

            foreach (self::BUILT_IN_NAMESPACES as $namespace) {
                if (str_starts_with($tool::class, $namespace)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
