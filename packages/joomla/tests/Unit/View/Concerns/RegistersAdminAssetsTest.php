<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\View\Concerns;

use Joomla\CMS\Factory;
use PhpClaw\Joomla\Component\Administrator\View\Concerns\RegistersAdminAssets;
use PHPUnit\Framework\TestCase;

final class RegistersAdminAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        Factory::$application = null;
        Factory::$applicationError = null;
    }

    protected function tearDown(): void
    {
        Factory::$application = null;
        Factory::$applicationError = null;
    }

    public function test_it_registers_and_uses_exactly_one_style(): void
    {
        $recorded = [];
        Factory::$application = $this->applicationRecording($recorded);

        $this->subject()->enqueue();

        self::assertCount(1, $recorded);
    }

    public function test_the_handle_and_path_match_the_asset_registry(): void
    {
        $recorded = [];
        Factory::$application = $this->applicationRecording($recorded);

        $this->subject()->enqueue();

        [$handle, $path] = $recorded[0];

        $registry = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/component/media/joomla.asset.json'), true);
        $styles = array_values(array_filter(
            $registry['assets'],
            static fn (array $a): bool => $a['type'] === 'style',
        ));

        self::assertSame($styles[0]['name'], $handle, 'The handle must match joomla.asset.json.');
        self::assertSame($styles[0]['uri'], $path, 'The path must match joomla.asset.json.');
    }

    public function test_the_stylesheet_it_points_at_exists_on_disk(): void
    {
        $recorded = [];
        Factory::$application = $this->applicationRecording($recorded);

        $this->subject()->enqueue();

        [, $path] = $recorded[0];

        $file = dirname(__DIR__, 4).'/component/media/css/'.basename($path);

        self::assertFileExists($file);
    }

    public function test_every_admin_view_enqueues_through_the_trait(): void
    {
        foreach (['About', 'Analytics', 'Chat', 'Guide'] as $view) {
            $source = (string) file_get_contents(dirname(__DIR__, 4).'/component/src/View/'.$view.'/HtmlView.php');

            self::assertStringContainsString('use RegistersAdminAssets;', $source, $view.' must use the shared trait.');
            self::assertStringNotContainsString(
                "registerAndUseStyle('com_phpclaw.admin'",
                $source,
                $view.' must not re-inline the stylesheet handle.',
            );
        }
    }

    private function subject(): object
    {
        return new class
        {
            use RegistersAdminAssets;

            public function enqueue(): void
            {
                $this->enqueueAdminStyle();
            }
        };
    }

    private function applicationRecording(array &$recorded): object
    {
        $wa = new class($recorded)
        {
            public function __construct(private array &$recorded) {}

            public function registerAndUseStyle(string $handle, string $path): void
            {
                $this->recorded[] = [$handle, $path];
            }
        };

        $document = new class($wa)
        {
            public function __construct(private readonly object $wa) {}

            public function getWebAssetManager(): object
            {
                return $this->wa;
            }
        };

        return new class($document)
        {
            public function __construct(private readonly object $document) {}

            public function getDocument(): object
            {
                return $this->document;
            }
        };
    }
}
