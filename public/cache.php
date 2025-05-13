<?php
// template_debug.php - im Hauptverzeichnis deines Projekts speichern

// Basispfad definieren und Autoloader einbinden
define('BASE_PATH', __DIR__);
require BASE_PATH . '/../vendor/autoload.php';

// Logger erstellen
$logger = new Src\Log\FileLogger(BASE_PATH . '/template_debug.log', 'debug');

// Template-Name, Cache- und Template-Verzeichnisse definieren
$templateName = 'players/list';
$cacheDir = BASE_PATH . '/../storage/framework/views';
$templateDir = BASE_PATH . '/../resources/views';

// Benötigte Komponenten erstellen
try {
    echo "Komponenten werden eingerichtet...\n";
    $loader = new Src\View\Loader\FilesystemTemplateLoader($templateDir);
    $cache = new Src\View\Cache\FilesystemTemplateCache($cacheDir, true);
    $compiler = new Src\View\Compiler\TemplateCompiler();

    // Korrigierte Reihenfolge der Parameter
    $engine = new Src\View\TemplateEngine($loader, $cache, $compiler);
    echo "Komponenten erfolgreich erstellt.\n\n";

    // Prüfen, ob die Template-Datei existiert
    echo "Template-Datei wird überprüft...\n";
    $templateExists = $loader->exists($templateName);
    echo "Template '$templateName' existiert: " . ($templateExists ? "Ja" : "Nein") . "\n";

    if ($templateExists) {
        // Zeit der letzten Änderung abrufen
        $lastModified = $loader->getLastModified($templateName);
        echo "Template zuletzt geändert: " . date("Y-m-d H:i:s", $lastModified) . "\n";

        // Template-Pfad abrufen
        $cachePath = $cache->getPath($templateName);
        echo "Cache-Pfad: $cachePath\n";
        echo "Cache-Datei existiert: " . (file_exists($cachePath) ? "Ja" : "Nein") . "\n\n";

        // Template laden
        echo "Template-Inhalt wird geladen...\n";
        $source = $loader->load($templateName);
        echo "Template-Inhalt geladen (" . strlen($source) . " Bytes).\n\n";

        // Template kompilieren
        echo "Template wird kompiliert...\n";
        $compiled = $compiler->compile($source, $templateName);
        echo "Template kompiliert (" . strlen($compiled) . " Bytes).\n\n";

        // Verzeichnisberechtigungen prüfen
        $cacheFileDir = dirname($cachePath);
        echo "Überprüfung der Cache-Verzeichnisberechtigungen...\n";
        echo "Cache-Verzeichnis: $cacheFileDir\n";
        echo "Verzeichnis existiert: " . (is_dir($cacheFileDir) ? "Ja" : "Nein") . "\n";

        if (!is_dir($cacheFileDir)) {
            echo "Verzeichnis wird erstellt...\n";
            $created = mkdir($cacheFileDir, 0755, true);
            echo "Verzeichnis erstellt: " . ($created ? "Ja" : "Nein") . "\n";
        }

        echo "Verzeichnis beschreibbar: " . (is_writable($cacheFileDir) ? "Ja" : "Nein") . "\n\n";

        // Kompiliertes Template speichern
        echo "Kompiliertes Template wird gespeichert...\n";
        $result = $cache->put($templateName, $compiled);
        echo "Template gespeichert: " . ($result ? "Ja" : "Nein") . "\n";

        // Prüfen, ob die Datei nach dem Speichern existiert
        echo "Cache-Datei existiert nach dem Speichern: " . (file_exists($cachePath) ? "Ja" : "Nein") . "\n";

        if (file_exists($cachePath)) {
            echo "Dateigröße: " . filesize($cachePath) . " Bytes\n";
            echo "Dateiberechtigungen: " . substr(sprintf('%o', fileperms($cachePath)), -4) . "\n";
        } else {
            echo "FEHLER: Cache-Datei konnte nicht erstellt werden.\n";

            // Versuchen, direkt in die Datei zu schreiben
            echo "Direktes Schreiben in die Datei wird versucht...\n";
            $bytesWritten = file_put_contents($cachePath, $compiled);
            echo "Ergebnis des direkten Schreibens: " . ($bytesWritten !== false ? "$bytesWritten Bytes geschrieben" : "Fehlgeschlagen") . "\n";

            if ($bytesWritten === false) {
                echo "PHP-Fehler: " . error_get_last()['message'] . "\n";
            }
        }
    } else {
        echo "KRITISCH: Template-Datei nicht gefunden. Bitte erstelle das Template unter:\n";
        echo "$templateDir/$templateName.php\n";
    }
} catch (Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
    echo "In " . $e->getFile() . " Zeile " . $e->getLine() . "\n";
    echo "Stack-Trace:\n" . $e->getTraceAsString() . "\n";
}