<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoredImage
{
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return '/storage/'.$path;
    }

    public static function put(Request $request, string $directory, ?string $previous = null): string
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'image.required' => 'image',
            'image.file' => 'image',
            'image.image' => 'image',
            'image.mimes' => 'image',
            'image.max' => 'image',
            'image.uploaded' => 'image',
        ]);

        $path = $request->file('image')->store($directory, 'public');

        if (! is_string($path)) {
            abort(500);
        }

        self::delete($previous);

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
