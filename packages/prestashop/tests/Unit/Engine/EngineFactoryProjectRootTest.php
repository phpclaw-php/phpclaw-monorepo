<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Engine;

use PhpClaw\PrestaShop\Engine\EngineFactory;
use PhpClaw\Tools\ProjectTool;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class EngineFactoryProjectRootTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_project_info_describes_the_shop_root_when_the_process_starts_at_the_filesystem_root(): void
    {
        ini_set('error_log', '/dev/null');
        $shop = sys_get_temp_dir().'/phpclaw_ps_root_'.uniqid();
        mkdir($shop.'/config', 0777, true);
        file_put_contents($shop.'/config/defines.inc.php', "<?php\n");
        define('_PS_ROOT_DIR_', $shop);
        chdir('/');

        try {
            $tools = (new EngineFactory([], [], null, 'ps_'))->buildTools(applyProfile: false, isCli: true);
            $projectTools = array_values(array_filter($tools, static fn (object $tool): bool => $tool instanceof ProjectTool));
            $result = json_decode($projectTools[0]->execute([]), true, flags: JSON_THROW_ON_ERROR);

            self::assertTrue($result['success']);
            self::assertSame($shop, $result['data']['root']);
            self::assertSame('prestashop', $result['data']['framework']);
        } finally {
            @unlink($shop.'/config/defines.inc.php');
            @rmdir($shop.'/config');
            @rmdir($shop);
        }
    }
}
