<?php

namespace Tests;

use App\Support\AiTagNormalizer;

final class AiTagNormalizerTest extends TestCase
{
    public function testNormalizeListPreservesCasingAndCharacters(): void
    {
        $tags = AiTagNormalizer::normalizeList([
            'Cyberpunk City',
            'Fantasy-Art',
            '  trim-outer  ',
        ]);

        $this->assertSame(['Cyberpunk City', 'Fantasy-Art', 'trim-outer'], $tags);
    }

    public function testNormalizeListDedupesExactStrings(): void
    {
        $tags = AiTagNormalizer::normalizeList([
            'Same',
            'Same',
            'same',
        ]);

        $this->assertSame(['Same', 'same'], $tags);
    }

    public function testUnionUniquePreservesMergeOrder(): void
    {
        $merged = AiTagNormalizer::unionSortedUnique([
            ['Forest', 'fantasy'],
            ['fantasy', 'moon'],
        ]);

        $this->assertSame(['Forest', 'fantasy', 'moon'], $merged);
    }
}
