<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Guards;

use PhpClaw\Guards\Concerns\NormalisesText;
use PHPUnit\Framework\TestCase;

final class NormalisesTextTest extends TestCase
{
    public function test_fold_fullwidth_ascii_folds_fullwidth_letters_to_ascii(): void
    {
        $subject = new class
        {
            use NormalisesText;

            public function callFold(string $s): string
            {
                return self::foldFullwidthAscii($s);
            }
        };

        $this->assertSame('ignore', $subject->callFold("\u{FF49}\u{FF47}\u{FF4E}\u{FF4F}\u{FF52}\u{FF45}"));
    }

    public function test_fold_fullwidth_ascii_covers_boundary_codepoints(): void
    {
        $subject = new class
        {
            use NormalisesText;

            public function callFold(string $s): string
            {
                return self::foldFullwidthAscii($s);
            }
        };

        $this->assertSame('!', $subject->callFold("\u{FF01}"));
        $this->assertSame('~', $subject->callFold("\u{FF5E}"));
    }

    public function test_fold_fullwidth_ascii_preserves_regular_ascii_unchanged(): void
    {
        $subject = new class
        {
            use NormalisesText;

            public function callFold(string $s): string
            {
                return self::foldFullwidthAscii($s);
            }
        };

        $this->assertSame('hello world', $subject->callFold('hello world'));
    }

    public function test_fold_fullwidth_ascii_handles_mixed_fullwidth_and_ascii(): void
    {
        $subject = new class
        {
            use NormalisesText;

            public function callFold(string $s): string
            {
                return self::foldFullwidthAscii($s);
            }
        };

        $this->assertSame('hello world', $subject->callFold("\u{FF48}\u{FF45}\u{FF4C}\u{FF4C}\u{FF4F} world"));
    }

    public function test_fold_fullwidth_ascii_output_matches_nfkc_for_fullwidth_input(): void
    {
        if (! class_exists(\Normalizer::class)) {
            $this->markTestSkipped('Requires ext-intl to compare against NFKC reference output.');
        }

        $subject = new class
        {
            use NormalisesText;

            public function callFold(string $s): string
            {
                return self::foldFullwidthAscii($s);
            }
        };

        $fullwidth = "\u{FF49}\u{FF47}\u{FF4E}\u{FF4F}\u{FF52}\u{FF45}";
        $nfkc = \Normalizer::normalize($fullwidth, \Normalizer::FORM_KC);

        $this->assertSame($nfkc, $subject->callFold($fullwidth));
    }

    public function test_fold_fullwidth_ascii_returns_message_unchanged_on_null_result(): void
    {
        $subject = new class
        {
            use NormalisesText;

            public function callFold(string $s): string
            {
                return self::foldFullwidthAscii($s);
            }
        };

        $input = 'safe message with no fullwidth chars';
        $this->assertSame($input, $subject->callFold($input));
    }
}
