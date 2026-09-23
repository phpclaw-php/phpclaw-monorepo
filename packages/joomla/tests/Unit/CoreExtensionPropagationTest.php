<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CoreExtensionPropagationTest extends TestCase
{
    private const ENGINE_BOOTSTRAPPER = '/component/src/Engine/EngineBootstrapper.php';

    public function test_engine_bootstrapper_file_exists(): void
    {
        $this->assertFileExists($this->engineBootstrapperSource());
    }

    public function test_guard_registry_default_registration_present(): void
    {
        $source = $this->readEngineBootstrapper();
        $this->assertStringContainsString('GuardRegistry::registerDefaults()', $source);
    }

    public function test_memory_drivers_are_registered_in_boot_path(): void
    {
        $source = $this->readEngineBootstrapper();
        $this->assertStringContainsString('MemoryRegistry::register', $source);
    }

    public function test_config_guards_are_resolved_in_boot_path(): void
    {
        $source = $this->readEngineBootstrapper();
        $this->assertStringContainsString('registerConfigGuards', $source);
        $this->assertStringContainsString('GuardRegistry::register(', $source);
    }

    public function test_config_hooks_are_attached_in_boot_path(): void
    {
        $source = $this->readEngineBootstrapper();
        $this->assertStringContainsString('registerConfigHooks', $source);
        $this->assertStringContainsString('HookRegistry::on(', $source);
    }

    public function test_config_skills_are_registered_in_boot_path(): void
    {
        $source = $this->readEngineBootstrapper();
        $this->assertStringContainsString('registerConfigSkills', $source);
        $this->assertStringContainsString('SkillRegistry::register(', $source);
    }

    public function test_skill_catalogue_activate_defaults_is_wired(): void
    {
        $source = $this->readEngineBootstrapper();
        $this->assertStringContainsString('registerCatalogueSkills', $source);
        $this->assertStringContainsString('SkillCatalogue::activateDefaults(', $source);
    }

    public function test_cloud_boot_is_invoked_in_boot_path(): void
    {
        $source = $this->readEngineBootstrapper();
        $this->assertStringContainsString('CloudManager::boot(', $source);
    }

    public function test_boot_calls_every_extension_path(): void
    {
        $source = $this->readEngineBootstrapper();
        $bootBody = $this->extractMethodBody($source, 'boot');

        foreach ([
            'GuardRegistry::registerDefaults()',
            'registerMemoryDrivers',
            'registerConfigGuards',
            'registerConfigHooks',
            'registerConfigSkills',
            'registerCatalogueSkills',
            'bootCloud',
        ] as $needle) {
            $this->assertStringContainsString($needle, $bootBody, "boot() must call {$needle}");
        }
    }

    private function engineBootstrapperSource(): string
    {
        return dirname(__DIR__, 2).self::ENGINE_BOOTSTRAPPER;
    }

    private function readEngineBootstrapper(): string
    {
        $path = $this->engineBootstrapperSource();
        $this->assertFileExists($path);
        $source = file_get_contents($path);
        $this->assertNotFalse($source);

        return $source;
    }

    private function extractMethodBody(string $source, string $methodName): string
    {
        $pattern = '/function\s+'.preg_quote($methodName, '/').'\s*\([^)]*\)\s*:\s*\w+\s*\{/';
        if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            $this->fail("method {$methodName} not found in source");
        }

        $offset = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $len = strlen($source);
        $body = '';

        for ($i = $offset; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }
            }
            $body .= $ch;
        }

        $this->fail("method {$methodName} body did not terminate");
    }
}
