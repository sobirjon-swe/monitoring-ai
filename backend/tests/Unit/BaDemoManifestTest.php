<?php

namespace Tests\Unit;

use App\Domain\Browser\BaDemo\BaDemoManifest;
use LogicException;
use PHPUnit\Framework\TestCase;

final class BaDemoManifestTest extends TestCase
{
    public function test_reviewed_manifest_pins_distinct_writer_and_tokenless_producer(): void
    {
        $this->assertTrue(class_exists(BaDemoManifest::class), 'Reviewed BA manifest is missing.');
        $manifest = BaDemoManifest::fromFile(dirname(__DIR__).'/Fixtures/ba-context-demo-v1.json');
        $this->assertSame(['project.read', 'ba.read', 'ba.source.read', 'ba.request.manage'], $manifest->data['writer']['grants']);
        $this->assertSame(['ba.fixture.manage', 'ba.request.manage', 'ba.source.read'], $manifest->data['producer']['grants']);
        $this->assertArrayNotHasKey('token_id', $manifest->data['producer']);
        $this->assertNotSame($manifest->data['writer']['user_id'], $manifest->data['producer']['user_id']);
        $this->assertSame(BaDemoManifest::SHA256, $manifest->hash);
    }

    public function test_manifest_refuses_altered_bytes(): void
    {
        $path = dirname(__DIR__).'/Fixtures/ba-context-demo-v1.json';
        $temporary = dirname(__DIR__).'/Fixtures/ba-manifest-test-'.bin2hex(random_bytes(8)).'.json';
        try {
            file_put_contents($temporary, file_get_contents($path)."\n");
            $this->expectException(LogicException::class);
            BaDemoManifest::fromFile($temporary);
        } finally {
            @unlink($temporary);
        }
    }
}
