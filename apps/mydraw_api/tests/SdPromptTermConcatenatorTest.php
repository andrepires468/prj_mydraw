<?php

namespace Tests;

use App\Support\SdPromptTermConcatenator;

final class SdPromptTermConcatenatorTest extends TestCase
{
    public function testStripsControlsAndDedupesTerms(): void
    {
        $raw = '[hair], hair, dark hair, lips, (pink lips:1.1), BREAK, dark_hair,';
        $out = SdPromptTermConcatenator::concatenate([$raw]);

        $this->assertSame('hair, dark hair, lips, pink lips', $out);
    }

    public function testMergesDistinctPromptsWithoutRepeatingTerms(): void
    {
        $out = SdPromptTermConcatenator::concatenate([
            '1girl, dark hair, smile',
            '1girl, blue eyes, smile',
            '(red dress:1.2), dark_hair',
        ]);

        $this->assertSame('1girl, dark hair, smile, blue eyes, red dress', $out);
    }

    public function testDropsAngleBracketEmbeddings(): void
    {
        $out = SdPromptTermConcatenator::concatenate([
            'portrait, <lora:style:0.8>, soft light',
        ]);

        $this->assertSame('portrait, soft light', $out);
    }

    public function testEmptyWhenOnlyControls(): void
    {
        $out = SdPromptTermConcatenator::concatenate([
            'BREAK, <lora:x:1>, [], ()',
        ]);

        $this->assertSame('', $out);
    }
}
