<?php

namespace Tests\Unit;

use App\Support\TyreSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TyreSizeTest extends TestCase
{
    public function test_complete_size_is_generated_from_width_profile_and_rim(): void
    {
        $this->assertSame('205/55 R16', TyreSize::format(205, 55, 16));
        $this->assertSame('265/65 R17', TyreSize::format(265, 65, '17.0'));
        $this->assertSame('215/75 R17.5', TyreSize::format(215, 75, '17.5'));
        $this->assertSame('185 R14', TyreSize::format(185, null, 14));
        $this->assertSame('205/55/16', TyreSize::key(205, 55, '16.0'));
    }

    public static function sizeInputs(): array
    {
        return [
            ['205/55 R16', 205, 55, '16'],
            ['205/55R16', 205, 55, '16'],
            ['205 55 16', 205, 55, '16'],
            ['205-55-16', 205, 55, '16'],
            ['2055516', 205, 55, '16'],
            ['205/55ZR16', 205, 55, '16'],
            ['205/55 r 16', 205, 55, '16'],
            ['LT265/65R17', 265, 65, '17'],
            ['255/70R16', 255, 70, '16'],
            ['185R14C', 185, null, '14'],
            ['185 R14', 185, null, '14'],
            ['215/75R17.5', 215, 75, '17.5'],
        ];
    }

    #[DataProvider('sizeInputs')]
    public function test_common_size_formats_are_normalised(string $input, int $w, ?int $p, string $r): void
    {
        $this->assertSame(['width' => $w, 'profile' => $p, 'rim' => $r], TyreSize::parse($input));
    }

    public function test_invalid_sizes_are_rejected(): void
    {
        $this->assertNull(TyreSize::parse('hello'));
        $this->assertNull(TyreSize::parse('999/55 R16'));
        $this->assertNull(TyreSize::parse('205/55 R99'));
        $this->assertNull(TyreSize::parse('R16'));
    }

    public function test_size_fragments_are_extracted_from_free_text(): void
    {
        $this->assertSame(['width' => 205, 'profile' => 55, 'rim' => '16', 'rest' => 'FALKEN'], TyreSize::extract('Falken 205/55 R16'));
        $this->assertSame(['width' => 205, 'profile' => 55, 'rim' => '16', 'rest' => 'USED'], TyreSize::extract('Used 205/55R16'));
        $this->assertSame(['width' => null, 'profile' => null, 'rim' => '16', 'rest' => ''], TyreSize::extract('R16'));
        $this->assertSame(['width' => 205, 'profile' => 55, 'rim' => null, 'rest' => ''], TyreSize::extract('205/55'));
        $this->assertSame(['width' => 205, 'profile' => 55, 'rim' => '16', 'rest' => ''], TyreSize::extract('205 55 16'));
        $this->assertSame(['width' => null, 'profile' => null, 'rim' => null, 'rest' => 'MICHELIN'], TyreSize::extract('Michelin'));
    }
}
