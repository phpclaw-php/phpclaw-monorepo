<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Skills;

use PhpClaw\AutoDiscovery\Attributes\Skill;
use PhpClaw\Joomla\Component\Administrator\Skills\JoomlaBuilderSkill;
use PhpClaw\Skills\Contracts\SkillInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JoomlaBuilderSkill::class)]
final class JoomlaBuilderSkillTest extends TestCase
{
    private JoomlaBuilderSkill $skill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skill = new JoomlaBuilderSkill;
    }

    public function test_is_a_skill(): void
    {
        $this->assertInstanceOf(SkillInterface::class, $this->skill);
    }

    public function test_name_avoids_the_joomla_token(): void
    {
        $this->assertSame('extension_scaffolder', $this->skill->name());
        $this->assertStringNotContainsStringIgnoringCase('joomla', $this->skill->name());
        $this->assertStringNotContainsStringIgnoringCase('joomla', $this->skill->description());
    }

    public function test_description_states_what_the_skill_does(): void
    {
        $this->assertSame('Scaffolding rules for producing an installable extension package', $this->skill->description());
    }

    public function test_tags_express_build_intent_not_domain_nouns(): void
    {
        $tags = $this->skill->tags();

        $this->assertContains('scaffold', $tags);
        $this->assertContains('manifest', $tags);

        foreach (['joomla', 'plugin', 'extension', 'article', 'user'] as $noun) {
            $this->assertNotContains(
                $noun,
                $tags,
                "'{$noun}' appears in ordinary admin questions, so tagging it makes this skill "
                .'inject its build rules into unrelated conversations.'
            );
        }
    }

    public function test_content_tells_the_model_to_ignore_the_rules_on_a_question(): void
    {
        $content = $this->skill->content();

        $this->assertStringContainsString('When these rules apply', $content);
        $this->assertStringContainsString('ignore everything below', $content);
    }

    public function test_content_states_component_module_not_supported(): void
    {
        $this->assertStringContainsString('NOT supported yet', $this->skill->content());
    }

    public function test_content_states_the_joomla_generation_rules(): void
    {
        $content = $this->skill->content();

        $this->assertStringContainsString('<extension type', $content);
        $this->assertStringContainsString('_JEXEC', $content);
        $this->assertStringContainsString('joomla_zip_extension', $content);
        $this->assertStringContainsString('services/provider.php', $content);
        $this->assertStringContainsString('Forbidden', $content);
    }

    public function test_content_locks_in_plugin_boot_naming_and_apis(): void
    {
        $content = $this->skill->content();

        $this->assertStringContainsString('<filename plugin="{slug}">', $content);
        $this->assertStringContainsString('strips the plg_ prefix', $content);
        $this->assertStringContainsString('onAfterRender', $content);
        $this->assertStringContainsString('setBody', $content);
        $this->assertStringContainsString('getCustomTag', $content);
        $this->assertStringContainsString('folder="language"', $content);
        $this->assertStringContainsString('setApplication', $content);
        $this->assertStringContainsString('FACTORY CLOSURE', $content);
        $this->assertStringContainsString('Joomla\CMS\Extension\PluginInterface', $content);
        $this->assertStringContainsString('There is NO', $content);
        $this->assertStringContainsString('<namespace path="src">', $content);
        $this->assertStringContainsString('fatals with HTTP 500', $content);
    }

    public function test_skill_attribute_name_matches(): void
    {
        $attrs = (new \ReflectionClass(JoomlaBuilderSkill::class))
            ->getAttributes(Skill::class);

        $this->assertCount(1, $attrs);
        $this->assertSame('extension_scaffolder', $attrs[0]->newInstance()->name);
    }
}
