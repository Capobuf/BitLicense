<?php

return [
    'column_manager' => [
        'position' => 'Posizione :position di :count.',
        'reordered' => ':column spostata alla posizione :position di :count.',
        'reordered_deferred' => ':column spostata alla posizione :position di :count. Premi Applica colonne per salvare.',
        'actions' => [
            'move_up' => ['label' => 'Sposta :column su'],
            'move_down' => ['label' => 'Sposta :column giù'],
            'reorder' => ['label' => 'Riordina colonna'],
        ],
    ],
    'columns' => [
        'icon' => ['boolean' => ['true' => 'Sì', 'false' => 'No']],
    ],
];
