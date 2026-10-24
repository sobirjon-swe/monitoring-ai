<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Throwable;

final class BrowserAppController
{
    public function __invoke(): Response
    {
        try {
            $root = realpath(public_path('ui-assets'));
            if ($root === false) {
                throw new \LogicException;
            }
            $manifestPath = $root.DIRECTORY_SEPARATOR.'.vite'.DIRECTORY_SEPARATOR.'manifest.json';
            if (! is_file($manifestPath) || filesize($manifestPath) > 262144) {
                throw new \LogicException;
            }
            $manifest = json_decode(file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
            $entry = $manifest['index.html'] ?? null;
            if (! is_array($entry) || ($entry['isEntry'] ?? null) !== true) {
                throw new \LogicException;
            }
            $script = $this->asset($root, $entry['file'] ?? null, 'js');
            $styles = $entry['css'] ?? [];
            if (! is_array($styles) || count($styles) > 16) {
                throw new \LogicException;
            }
            $styles = array_map(fn ($file) => $this->asset($root, $file, 'css'), $styles);

            return response()->view('browser-app', compact('script', 'styles'))->header('Cache-Control', 'no-store')->header('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'")->header('X-Content-Type-Options', 'nosniff')->header('Referrer-Policy', 'no-referrer');
        } catch (Throwable) {
            return response('Workspace build unavailable. / Ish maydoni yig‘ilmagan. / Сборка недоступна.', 503)->header('Content-Type', 'text/plain; charset=UTF-8')->header('Cache-Control', 'no-store');
        }
    }

    private function asset(string $root, mixed $file, string $extension): string
    {
        if (! is_string($file) || ! preg_match('/\Aassets\/[A-Za-z0-9_-]+\.'.preg_quote($extension, '/').'\z/D', $file)) {
            throw new \LogicException;
        }
        $path = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file));
        if ($path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw new \LogicException;
        }

        return '/ui-assets/'.$file;
    }
}
