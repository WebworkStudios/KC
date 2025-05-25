<?php
// config/cache.php
return [
    'template_cache' => [
        'enabled' => true,
        'max_cache_size' => 2000,        // In-Memory Cache-Einträge
        'gc_probability' => 0.01,        // 1% GC-Wahrscheinlichkeit
        'gc_max_age' => 86400,          // 24h für GC
        'auto_optimize' => true,         // Automatische Optimierung
        'compression_threshold' => 500   // Ab 500 Dateien komprimieren
    ]
];