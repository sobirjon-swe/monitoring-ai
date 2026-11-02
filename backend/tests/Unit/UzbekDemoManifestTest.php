<?php

namespace Tests\Unit;

use App\Domain\Browser\UzbekDemo\UzbekDemoManifest;
use PHPUnit\Framework\TestCase;

class UzbekDemoManifestTest extends TestCase
{
    public function test_pinned_scenario_has_twelve_people_three_projects_and_no_technical_employee(): void
    {
        $m = UzbekDemoManifest::fromFile(dirname(__DIR__).'/Fixtures/uzbek-demo-v1.json');
        $this->assertCount(12, $m->data['people']);
        $this->assertSame(['Savdo CRM', 'Ombor boshqaruvi', 'Mijozlar portali'], array_column($m->data['projects'], 'name'));
        $this->assertSame('Azizbek Karimov', $m->data['people'][0]['name']);
        $this->assertSame('Mohira Yusupova', $m->data['people'][11]['name']);
        $this->assertCount(2, $m->data['interactive']);
        $this->assertCount(3, $m->data['technical']);
        $this->assertSame(8, $m->data['interactive_hours']);
    }
}
