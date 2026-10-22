<?php

return [
    'temporary_file_upload' => [
        'disk' => 'private',
        'rules' => ['required', 'file', 'max:5120'],
        'directory' => 'livewire-tmp',
        'middleware' => 'throttle:30,1',
        'preview_mimes' => [],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
