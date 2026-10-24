<?php

namespace Tests\Feature;

use Tests\Support\BrowserTestCase;

class BrowserAppTest extends BrowserTestCase
{
    public function test_missing_build_is_safe_and_unknown_navigation_is_not_mounted(): void
    {
        $original = public_path();
        $root = storage_path('framework/testing/ui-'.bin2hex(random_bytes(6)));
        mkdir($root, 0700, true);
        app()->usePublicPath($root);
        try {
            $this->get('/app')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private')->assertSee('Workspace build unavailable')->assertDontSee('stack');
            $this->get('/app/arbitrary-file')->assertNotFound();
        } finally {
            app()->usePublicPath($original);
            rmdir($root);
        }
    }

    public function test_verified_local_manifest_serves_navigation_and_rejects_escape(): void
    {
        $original = public_path();
        $root = storage_path('framework/testing/ui-'.bin2hex(random_bytes(6)));
        mkdir($root.'/ui-assets/.vite', 0700, true);
        mkdir($root.'/ui-assets/assets', 0700, true);
        file_put_contents($root.'/ui-assets/assets/app-test.js', '// synthetic');
        file_put_contents($root.'/ui-assets/assets/app-test.css', 'body{}');
        $manifest = $root.'/ui-assets/.vite/manifest.json';
        app()->usePublicPath($root);
        try {
            file_put_contents($manifest, json_encode(['index.html' => ['isEntry' => true, 'file' => 'assets/app-test.js', 'css' => ['assets/app-test.css']]]));
            foreach (['/app', '/app/overview', '/app/requirements', '/app/tasks', '/app/qa', '/app/ba', '/app/management'] as $path) {
                $this->get($path)->assertOk()->assertSee('/ui-assets/assets/app-test.js', false)->assertSee('/ui-assets/assets/app-test.css', false)->assertSee('id="root"', false);
                $this->head($path)->assertOk()->assertContent('');
            }
            foreach (['https://evil.invalid/app.js', '../private.js', 'assets/missing.js', 'assets/app-test.css'] as $invalid) {
                file_put_contents($manifest, json_encode(['index.html' => ['isEntry' => true, 'file' => $invalid]]));
                $this->get('/app')->assertStatus(503)->assertDontSee($invalid);
            }
        } finally {
            app()->usePublicPath($original);
            unlink($manifest);
            unlink($root.'/ui-assets/assets/app-test.js');
            unlink($root.'/ui-assets/assets/app-test.css');
            rmdir($root.'/ui-assets/assets');
            rmdir($root.'/ui-assets/.vite');
            rmdir($root.'/ui-assets');
            rmdir($root);
        }
    }
}
