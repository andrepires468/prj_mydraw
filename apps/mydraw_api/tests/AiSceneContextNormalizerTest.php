<?php

namespace Tests;

use App\Support\AiSceneContextNormalizer;

final class AiSceneContextNormalizerTest extends TestCase
{
    public function testFlattensStructuredFlintstonesExample(): void
    {
        $tags = AiSceneContextNormalizer::flattenToTags([
            'work'         => 'The Flintstones',
            'characters'   => ['Fred Flintstone', 'Wilma Flintstone'],
            'relationship' => 'husband and wife',
            'scene'        => ['sex', 'kiss', 'riding'],
        ]);

        $this->assertSame([
            'The Flintstones',
            'Fred Flintstone',
            'Wilma Flintstone',
            'husband and wife',
            'sex',
            'kiss',
            'riding',
        ], $tags);
        $this->assertGreaterThanOrEqual(AiSceneContextNormalizer::MIN_TAGS, count($tags));
        $this->assertLessThanOrEqual(AiSceneContextNormalizer::MAX_TAGS, count($tags));
    }

    public function testAcceptsCommaSeparatedSceneAndCapsAtTwelve(): void
    {
        $tags = AiSceneContextNormalizer::flattenToTags([
            'obra'        => 'Work A',
            'personagens' => 'A, B, C, D',
            'relação'     => 'friends',
            'cena'        => 'one, two, three, four, five, six, seven, eight',
        ]);

        $this->assertCount(12, $tags);
        $this->assertSame('Work A', $tags[0]);
        $this->assertSame('friends', $tags[5]);
    }

    public function testIgnoresUnknownPlaceholders(): void
    {
        $tags = AiSceneContextNormalizer::flattenToTags([
            'work'         => 'unknown',
            'characters'   => ['Hero'],
            'relationship' => 'none',
            'scene'        => ['kiss', 'hug'],
        ]);

        $this->assertSame(['Hero', 'kiss', 'hug'], $tags);
    }
}
