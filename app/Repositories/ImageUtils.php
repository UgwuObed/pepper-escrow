<?php

namespace App\Repositories;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUtils
{
    public function saveDocument($file, string $path, $entityId): array
    {
        $links = [];

        if (is_array($file)) {
            foreach ($file as $singleFile) {
                $link = $this->uploadSingleFile($singleFile, $path, $entityId);
                if ($link) {
                    $links[] = $link;
                }
            }
        } else {
            $link = $this->uploadSingleFile($file, $path, $entityId);
            if ($link) {
                $links[] = $link;
            }
        }

        return $links;
    }

    protected function uploadSingleFile($file, string $path, $entityId): ?string
    {
        try {
            $extension = $file->getClientOriginalExtension();
            $filename = Str::uuid() . '.' . $extension;
            $destinationPath = trim($path, '/') . '/' . $entityId;

            $stored = Storage::disk('public')->putFileAs($destinationPath, $file, $filename);

            return $stored ? Storage::disk('public')->url($stored) : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
