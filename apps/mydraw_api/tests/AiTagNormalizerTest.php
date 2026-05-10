<?php

namespace Tests;

use App\Support\AiTagNormalizer;

final class AiTagNormalizerTest extends TestCase
{
    public function testNormalizeListFiltersVagueAndDuplicates(): void
    {
        $tags = AiTagNormalizer::normalizeList([
            'Landscape',
            'landscape',
            'Masterpiece',
            'Cyberpunk City',
            'detailed',
        ]);

        $this->assertSame(['landscape', 'cyberpunk-city'], $tags);
    }

    public function testUnionSortedUniqueMergesPerImageGroups(): void
    {
        $merged = AiTagNormalizer::unionSortedUnique([
            ['Forest', 'fantasy'],
            ['fantasy', 'moon'],
        ]);

        $this->assertSame(['fantasy', 'forest', 'moon'], $merged);
    }
}
