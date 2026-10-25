<?php

namespace Tests\Unit;

use Tests\TestCase;

class CarouselUchetCardTest extends TestCase
{
    public function test_uchet_carousel_card_is_only_for_dispatcher(): void
    {
        $source = file_get_contents(resource_path('js/Carousel/constants.index.js'));

        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            "/name:\\s*\"Оператор учёта\"[\\s\\S]*?role:\\s*\\['dispatcher'\\]/u",
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            "/name:\\s*\"Оператор учёта\"[\\s\\S]*?role:\\s*\\[[^\\]]*(admin|super_admin|operator)/u",
            $source
        );
    }
}
