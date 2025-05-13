<?php

// cache_diagnostic.php - im Root-Verzeichnis speichern

define('BASE_PATH', __DIR__);
require BASE_PATH . '/../vendor/autoload.php';

// Logger erstellen
$logger = new Src\Log\FileLogger(BASE_PATH . '/cache_debug.log', 'debug');

// Cache-Pfade
$cacheDir = BASE_PATH . '/storage/framework/views';
$templateDir = BASE_PATH . '/resources/views';

echo "Cache-Diagnostik:\n";
echo "===============\n";
echo "Cache-Verzeichnis: $cacheDir\n";
echo "Template-Verzeichnis: $templateDir\n\n";

// 1. Verzeichnisse überprüfen
echo "Verzeichnisprüfung:\n";
echo "Cache-Verzeichnis existiert: " . (is_dir($cacheDir) ? "Ja" : "Nein") . "\n";
echo "Cache-Verzeichnis beschreibbar: " . (is_writable($cacheDir) ? "Ja" : "Nein") . "\n";
echo "Template-Verzeichnis existiert: " . (is_dir($templateDir) ? "Ja" : "Nein") . "\n";
echo "Template-Verzeichnis lesbar: " . (is_readable($templateDir) ? "Ja" : "Nein") . "\n\n";

// 2. Cache-Komponenten erstellen
echo "Komponenten-Test:\n";
try {
    $loader = new Src\View\Loader\FilesystemTemplateLoader($templateDir);
    echo "Loader erstellt: OK\n";

    $cache = new Src\View\Cache\FilesystemTemplateCache($cacheDir, true);
    echo "Cache erstellt: OK\n";
    echo "Cache aktiviert: " . ($cache->isEnabled() ? "Ja" : "Nein") . "\n";

    $compiler = new Src\View\Compiler\TemplateCompiler();
    echo "Compiler erstellt: OK\n";

    $engine = new Src\View\TemplateEngine($loader, $cache, $compiler);
    echo "Engine erstellt: OK\n\n";

    // 3. Test-Template erstellen und compilieren
    echo "Template-Test:\n";

    // Erstelle Test-Template, falls noch nicht vorhanden
    $testTemplatePath = $templateDir . '/test.php';
    if (!file_exists($testTemplatePath)) {
        $testTemplateContent = "<!DOCTYPE html>\n<html>\n<head>\n    <title>{{ title }}</title>\n</head>\n<body>\n    <h1>{{ greeting }}</h1>\n    <p>Dies ist ein Test-Template.</p>\n</body>\n</html>";
        file_put_contents($testTemplatePath, $testTemplateContent);
        echo "Test-Template erstellt: $testTemplatePath\n";
    } else {
        echo "Test-Template existiert bereits: $testTemplatePath\n";
    }

    // Teste den Kompilierungsprozess
    echo "Kompiliere Template 'test'...\n";
    $source = $loader->load('test');
    echo "Template geladen: " . (strlen($source) > 0 ? "OK (" . strlen($source) . " Bytes)" : "FEHLER") . "\n";

    $compiled = $compiler->compile($source, 'test');
    echo "Template kompiliert: " . (strlen($compiled) > 0 ? "OK (" . strlen($compiled) . " Bytes)" : "FEHLER") . "\n";

    $result = $cache->put('test', $compiled);
    echo "In Cache geschrieben: " . ($result ? "OK" : "FEHLER") . "\n";

    $cachePath = $cache->getPath('test');
    echo "Cache-Pfad: $cachePath\n";
    echo "Cache-Datei existiert: " . (file_exists($cachePath) ? "Ja" : "Nein") . "\n\n";

    // 4. Render-Test
    echo "Render-Test:\n";
    try {
        $output = $engine->render('test', ['title' => 'Test', 'greeting' => 'Hallo Welt!']);
        echo "Template gerendert: OK (" . strlen($output) . " Bytes)\n";
        echo "Ausgabe-Beispiel: " . substr($output, 0, 100) . "...\n";
    } catch (Throwable $e) {
        echo "Render-Fehler: " . $e->getMessage() . "\n";
    }

} catch (Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
    echo "In: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
}