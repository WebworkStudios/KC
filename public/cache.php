<?php
// debug.php - Platzieren Sie diese Datei im öffentlichen Verzeichnis Ihrer Anwendung

// Basispfad definieren und Autoloader einbinden
define('BASE_PATH', __DIR__);
require BASE_PATH . '/../vendor/autoload.php';

// Template-Details
$templateName = 'players/list';
$cacheDir = BASE_PATH . '/../storage/framework/views';
$templateDir = BASE_PATH . '/../resources/views';

// Header setzen
header('Content-Type: text/html; charset=UTF-8');

echo '<!DOCTYPE html>
<html>
<head>
    <title>Template-Debug</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #2c3e50; }
        .section { margin-bottom: 20px; border: 1px solid #ddd; padding: 15px; border-radius: 5px; }
        .success { color: green; }
        .error { color: red; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow: auto; }
        .code { font-family: monospace; font-size: 14px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .action-btn { 
            display: inline-block; 
            background: #3498db; 
            color: white; 
            padding: 8px 15px; 
            text-decoration: none; 
            border-radius: 3px; 
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <h1>Template-Debug</h1>';

// Aktionen verarbeiten
if (isset($_GET['action'])) {
    echo '<div class="section">';
    switch ($_GET['action']) {
        case 'clear_cache':
            // Cache leeren
            $cache = new Src\View\Cache\FilesystemTemplateCache($cacheDir, true);
            $result = $cache->clear();
            if ($result) {
                echo '<p class="success">Cache erfolgreich geleert!</p>';
            } else {
                echo '<p class="error">Fehler beim Leeren des Caches!</p>';
            }
            break;

        case 'generate_cache':
            try {
                // Komponenten erstellen
                $loader = new Src\View\Loader\FilesystemTemplateLoader($templateDir);
                $cache = new Src\View\Cache\FilesystemTemplateCache($cacheDir, true);
                $compiler = new Src\View\Compiler\TemplateCompiler();

                // Template prüfen
                if (!$loader->exists($templateName)) {
                    echo '<p class="error">Template \'' . $templateName . '\' existiert nicht!</p>';
                    break;
                }

                // Template laden und kompilieren
                $source = $loader->load($templateName);
                $compiled = $compiler->compile($source, $templateName);

                // In Cache speichern
                $result = $cache->put($templateName, $compiled);

                if ($result) {
                    echo '<p class="success">Cache erfolgreich generiert!</p>';
                } else {
                    echo '<p class="error">Fehler beim Generieren des Caches!</p>';
                }
            } catch (Throwable $e) {
                echo '<p class="error">Fehler: ' . $e->getMessage() . '</p>';
                echo '<pre>' . $e->getTraceAsString() . '</pre>';
            }
            break;

        case 'show_template':
            try {
                // Template laden
                $loader = new Src\View\Loader\FilesystemTemplateLoader($templateDir);

                if (!$loader->exists($templateName)) {
                    echo '<p class="error">Template \'' . $templateName . '\' existiert nicht!</p>';
                    break;
                }

                $source = $loader->load($templateName);

                echo '<h3>Template-Inhalt:</h3>';
                echo '<pre class="code">' . htmlspecialchars($source) . '</pre>';
            } catch (Throwable $e) {
                echo '<p class="error">Fehler: ' . $e->getMessage() . '</p>';
            }
            break;

        case 'show_compiled':
            try {
                // Cache-Datei laden
                $cache = new Src\View\Cache\FilesystemTemplateCache($cacheDir, true);
                $cachePath = $cache->getPath($templateName);

                if (!file_exists($cachePath)) {
                    echo '<p class="error">Kompilierte Datei existiert nicht!</p>';
                    break;
                }

                $compiled = file_get_contents($cachePath);

                echo '<h3>Kompilierter Code:</h3>';
                echo '<pre class="code">' . htmlspecialchars($compiled) . '</pre>';
            } catch (Throwable $e) {
                echo '<p class="error">Fehler: ' . $e->getMessage() . '</p>';
            }
            break;

        case 'test_render':
            try {
                // Komponenten erstellen
                $loader = new Src\View\Loader\FilesystemTemplateLoader($templateDir);
                $cache = new Src\View\Cache\FilesystemTemplateCache($cacheDir, true);
                $compiler = new Src\View\Compiler\TemplateCompiler();
                $engine = new Src\View\TemplateEngine($loader, $cache, $compiler);

                // Benötigte Funktionen registrieren
                echo '<p>Registriere Hilfsfunktionen...</p>';

                // URL-Funktion registrieren
                $engine->registerFunction('url', function (string $route, array $params = []) {
                    // Einfache Implementierung
                    $url = '/' . trim($route, '/');

                    // Parameter als Query-String hinzufügen
                    if (!empty($params)) {
                        $queryString = http_build_query($params);
                        $url .= '?' . $queryString;
                    }

                    return $url;
                });

                // DateFormat-Funktion registrieren
                $engine->registerFunction('dateFormat', function ($date, $format = 'd.m.Y') {
                    if ($date instanceof \DateTime) {
                        return $date->format($format);
                    }

                    if (is_string($date)) {
                        return date($format, strtotime($date));
                    }

                    return '';
                });

// Length-Funktion für Arrays und Strings
                $engine->registerFunction('length', function ($value) {
                    if ($value === null) {
                        return 0;
                    }

                    if (is_array($value) || $value instanceof \Countable) {
                        return count($value);
                    }

                    if (is_string($value)) {
                        return mb_strlen($value, 'UTF-8');
                    }

                    return 0;
                });

                // Asset-Funktion registrieren
                $engine->registerFunction('asset', function (string $path) {
                    // Einfache Implementierung
                    $path = ltrim($path, '/');

                    // Basis-URL ermitteln (kann angepasst werden)
                    $baseUrl = '';

                    // Wenn Server-Informationen verfügbar sind, versuche die Basis-URL zu ermitteln
                    if (isset($_SERVER['HTTP_HOST'])) {
                        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                        $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
                    }

                    return $baseUrl . '/assets/' . $path;
                });

// Has-Funktion registrieren (prüft, ob eine Variable existiert oder ein Schlüssel in einem Array existiert)
                $engine->registerFunction('has', function ($var, $key = null) {
                    // Fall 1: has(variable) - Prüft, ob die Variable existiert und nicht null ist
                    if ($key === null) {
                        return $var !== null;
                    }

                    // Fall 2: has(array, key) - Prüft, ob ein Schlüssel in einem Array existiert
                    if (is_array($var)) {
                        return array_key_exists($key, $var);
                    }

                    // Fall 3: has(object, property) - Prüft, ob eine Eigenschaft in einem Objekt existiert
                    if (is_object($var)) {
                        return property_exists($var, $key) || isset($var->$key);
                    }

                    return false;
                });

                // Testdaten
                $data = [
                    'title' => 'Test-Spielerliste',
                    'playersCount' => 3,
                    'players' => [
                        ['player_id' => 1, 'first_name' => 'Max', 'last_name' => 'Mustermann', 'created_date' => '2023-01-01'],
                        ['player_id' => 2, 'first_name' => 'Erika', 'last_name' => 'Musterfrau', 'created_date' => '2023-01-02'],
                        ['player_id' => 3, 'first_name' => 'John', 'last_name' => 'Doe', 'created_date' => '2023-01-03']
                    ],
                    'error' => null
                ];

                echo '<p>Rendere Template...</p>';

                // Template rendern
                $result = $engine->render($templateName, $data);

                echo '<h3>Rendering erfolgreich!</h3>';
                echo '<p>Größe des Ergebnisses: ' . strlen($result) . ' Bytes</p>';
                echo '<h3>Ergebnis:</h3>';
                echo '<iframe style="width:100%; height:500px; border:1px solid #ddd;" srcdoc="'
                    . htmlspecialchars($result) . '"></iframe>';
            } catch (Throwable $e) {
                echo '<p class="error">Fehler beim Rendering: ' . $e->getMessage() . '</p>';
                echo '<pre>' . $e->getTraceAsString() . '</pre>';
            }
            break;
    }
    echo '</div>';
}

// Cache-Informationen anzeigen
echo '<div class="section">';
echo '<h2>Aktionen</h2>';
echo '<p>
    <a href="?action=clear_cache" class="action-btn">Cache leeren</a>
    <a href="?action=generate_cache" class="action-btn">Cache generieren</a>
    <a href="?action=show_template" class="action-btn">Template anzeigen</a>
    <a href="?action=show_compiled" class="action-btn">Kompilierten Code anzeigen</a>
    <a href="?action=test_render" class="action-btn">Test-Rendering</a>
</p>';
echo '</div>';

// Template-Informationen
echo '<div class="section">';
echo '<h2>Template-Informationen</h2>';
echo '<table>';
echo '<tr><th>Eigenschaft</th><th>Wert</th></tr>';
echo '<tr><td>Template-Name</td><td>' . $templateName . '</td></tr>';
echo '<tr><td>Template-Verzeichnis</td><td>' . $templateDir . '</td></tr>';
echo '<tr><td>Cache-Verzeichnis</td><td>' . $cacheDir . '</td></tr>';

// Template-Existenz prüfen
$templatePath = $templateDir . '/' . $templateName . '.php';
$templateExists = file_exists($templatePath);
echo '<tr><td>Template existiert</td><td>' . ($templateExists ? 'Ja' : 'Nein') . '</td></tr>';

if ($templateExists) {
    echo '<tr><td>Template-Größe</td><td>' . filesize($templatePath) . ' Bytes</td></tr>';
    echo '<tr><td>Letzte Änderung</td><td>' . date('Y-m-d H:i:s', filemtime($templatePath)) . '</td></tr>';
}

// Cache-Datei prüfen
$cache = new Src\View\Cache\FilesystemTemplateCache($cacheDir, true);
$cachePath = $cache->getPath($templateName);
$cacheExists = file_exists($cachePath);

echo '<tr><td>Cache existiert</td><td>' . ($cacheExists ? 'Ja' : 'Nein') . '</td></tr>';

if ($cacheExists) {
    echo '<tr><td>Cache-Pfad</td><td>' . $cachePath . '</td></tr>';
    echo '<tr><td>Cache-Größe</td><td>' . filesize($cachePath) . ' Bytes</td></tr>';
    echo '<tr><td>Cache-Datum</td><td>' . date('Y-m-d H:i:s', filemtime($cachePath)) . '</td></tr>';
}

echo '</table>';
echo '</div>';

// Verzeichnis-Struktur
echo '<div class="section">';
echo '<h2>Verzeichnis-Struktur</h2>';

// Cache-Verzeichnis prüfen
echo '<h3>Cache-Verzeichnis</h3>';
if (is_dir($cacheDir)) {
    echo '<p class="success">Verzeichnis existiert</p>';
    echo '<p>Berechtigungen: ' . substr(sprintf('%o', fileperms($cacheDir)), -4) . '</p>';
    echo '<p>Beschreibbar: ' . (is_writable($cacheDir) ? 'Ja' : 'Nein') . '</p>';

    // Unterverzeichnisse anzeigen
    $cacheStructure = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $path = $iterator->getSubPathname();
        if ($file->isDir()) {
            $cacheStructure[] = ['type' => 'dir', 'path' => $path];
        } else {
            $cacheStructure[] = [
                'type' => 'file',
                'path' => $path,
                'size' => $file->getSize(),
                'modified' => date('Y-m-d H:i:s', $file->getMTime())
            ];
        }
    }

    if (!empty($cacheStructure)) {
        echo '<table>';
        echo '<tr><th>Typ</th><th>Pfad</th><th>Größe</th><th>Geändert</th></tr>';
        foreach ($cacheStructure as $item) {
            echo '<tr>';
            echo '<td>' . ($item['type'] === 'dir' ? 'Verzeichnis' : 'Datei') . '</td>';
            echo '<td>' . $item['path'] . '</td>';
            echo '<td>' . (isset($item['size']) ? $item['size'] . ' Bytes' : '-') . '</td>';
            echo '<td>' . (isset($item['modified']) ? $item['modified'] : '-') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p>Verzeichnis ist leer.</p>';
    }
} else {
    echo '<p class="error">Verzeichnis existiert nicht!</p>';
}

echo '</div>';

// Registrierte Funktionen anzeigen (neu hinzugefügt)
echo '<div class="section">';
echo '<h2>Debug-Information</h2>';
echo '<p>Template-Engine-Informationen anzeigen...</p>';

try {
    $loader = new Src\View\Loader\FilesystemTemplateLoader($templateDir);
    $cache = new Src\View\Cache\FilesystemTemplateCache($cacheDir, true);
    $compiler = new Src\View\Compiler\TemplateCompiler();
    $engine = new Src\View\TemplateEngine($loader, $cache, $compiler);

    // Einige Testfunktionen registrieren
    $engine->registerFunction('test', function() { return 'Test'; });

    // Reflection verwenden, um die registrierten Funktionen zu bekommen
    $reflection = new ReflectionClass($engine);
    $functionsProperty = $reflection->getProperty('functions');
    $functionsProperty->setAccessible(true);
    $functions = $functionsProperty->getValue($engine);

    echo '<h3>Registrierte Funktionen:</h3>';
    echo '<pre>';
    print_r(array_keys($functions));
    echo '</pre>';

} catch (Throwable $e) {
    echo '<p class="error">Fehler beim Anzeigen der Engine-Informationen: ' . $e->getMessage() . '</p>';
}

echo '</div>';

echo '</body>
</html>';