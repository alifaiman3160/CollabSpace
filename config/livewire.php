<?php

/*
 * Only what differs from Livewire's own defaults.
 *
 * The bar shown while wire:navigate fetches the next page: thin, in the app's periwinkle link colour
 * (#4a5bd0), so a slow page still gives feedback without shouting.
 */
return [
    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#4a5bd0',
    ],
];
