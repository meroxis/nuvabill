<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * A place outside the public folder for code that should stop running, such as an extension or theme
 * added by hand that is no longer used. Nothing is deleted: staff can move it back at any time.
 */
final class Quarantine
{
    /**
     * Move a file or folder into storage/app/quarantine/{date}/{name}.
     *
     * @return string Where it is now, for the message staff see.
     */
    public static function move(string $path, string $name): string
    {
        $relative = 'quarantine/'.now()->format('Y-m-d-His').'/'.trim(str_replace('\\', '/', $name), '/');

        if (file_exists(storage_path('app/'.$relative))) {
            $relative .= '-'.bin2hex(random_bytes(3));
        }

        $target = storage_path('app/'.$relative);
        File::ensureDirectoryExists(dirname($target), 0700);

        if (! @rename($path, $target)) {
            throw new RuntimeException(__('Could not move :file. Check the folder permissions.', ['file' => $name]));
        }

        return 'storage/app/'.$relative;
    }
}
