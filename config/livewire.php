<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Livewire Configuration
    |--------------------------------------------------------------------------
    */

    'temporary_file_upload' => [
        /*
         * We force a local disk for temporary uploads even when the default
         * filesystem is S3 (MinIO). This is more reliable for chunked
         * Livewire/Filament uploads and avoids extra latency/auth issues
         * during the initial upload POST.
         *
         * The actual final files (in FileUpload fields etc.) will still go
         * to the configured disk (s3 in production).
         */
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK', 'local'),

        'rules' => null,
        'directory' => 'livewire-tmp',
        'middleware' => 'throttle:60,1',
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4', 'mov',
            'avi', 'mp3', 'm4a', 'jpg', 'jpeg', 'pdf', 'doc', 'docx',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
