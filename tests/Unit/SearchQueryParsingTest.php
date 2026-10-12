<?php

namespace Tests\Unit;

use App\Services\Inventory\TyreSearchService;
use PHPUnit\Framework\TestCase;

class SearchQueryParsingTest extends TestCase
{
    public function test_condition_keywords_and_brand_terms_are_recognised(): void
    {
        $service = new TyreSearchService;

        $this->assertSame(
            ['width' => 205, 'profile' => 55, 'rim' => '16', 'condition' => 'used', 'terms' => []],
            $service->parseQuery('Used 205/55 R16'),
        );
        $this->assertSame(
            ['width' => 205, 'profile' => 55, 'rim' => '16', 'condition' => null, 'terms' => ['FALKEN']],
            $service->parseQuery('Falken 205/55R16'),
        );
        $this->assertSame(
            ['width' => null, 'profile' => null, 'rim' => '16', 'condition' => 'new', 'terms' => []],
            $service->parseQuery('new R16 tyres'),
        );
    }
}
