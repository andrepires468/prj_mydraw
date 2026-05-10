<?php

namespace Tests;

use App\Support\ContentRating;

final class ContentRatingTest extends TestCase
{
    public function testMaxReturnsMostRestrictiveRating(): void
    {
        $this->assertSame(
            ContentRating::XXX,
            ContentRating::max([
                ContentRating::PG,
                ContentRating::R,
                ContentRating::XXX,
                ContentRating::PG_13,
            ])
        );
    }

    public function testMaxIgnoresInvalidTokens(): void
    {
        $this->assertSame(
            ContentRating::PG,
            ContentRating::max(['invalid', '', ContentRating::PG])
        );
    }

    public function testMaxReturnsNullWhenEmpty(): void
    {
        $this->assertNull(ContentRating::max([]));
    }
}
