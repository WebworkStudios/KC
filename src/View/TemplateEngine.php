<?php
namespace Src\View;

use Src\Http\Router;
use Src\View\Exception\TemplateException;
use Src\View\Functions\DefaultFunctions;

/**
 * Leistungsstarke Template-Engine für PHP 8.4
 *
 * Bietet eine einfache und intuitive Syntax für Templates mit Unterstützung für
 * Layouts, Komponenten, Partials und Hilfsfunktionen. Optimiert für Sicherheit
 * und Leistung mit Cache-Unterstützung.
 *
 * Unterstützt modernes Template-Syntax:
 * {{ variable }} - Gibt eine Variable escaped aus
 * {!! variable !!} - Gibt eine Variable unescaped aus
 * {% if condition %} {% else %} {% endif %} - Bedingte Anweisungen
 * {% foreach items as item %} {% endforeach %} - Schleifen
 * {% extends 'layout' %} - Template-Vererbung
 * {% section 'name' %} {% endsection %} - Content-Sections
 * {% yield 'name' 'default' %} - Section-Output
 * {% include 'partial' %} - Partials einbinden
 * {% component 'name' with params %} {% endcomponent %} - Komponenten
 */
class TemplateEngine
{
    /**
     * Regex-Pattern für Template-Parsing
     *
     * @var array
     */
    private const PATTERNS = [
        'comment'       => '/\{\{--(.+?)--\}\}/s',
        'escaped'       => '/\{\{\s*(.+?)\s*\}\}/s',
        'raw'           => '/\{!!\s*(.+?)\s*!!\}/s',
        'if'            => '/\{%\s*if\s+(.+?)\s*%\}/s',
        'elseif'        => '/\{%\s*elseif\s+(.+?)\s*%\}/s',
        'else'          => '/\{%\s*else\s*%\}/s',
        'endif'         => '/\{%\s*endif\s*%\}/s',
        'foreach'       => '/\{%\s*foreach\s+(.+?)\s*%\}/s',
        'endforeach'    => '/\{%\s*endforeach\s*%\}/s',
        'for'           => '/\{%\s*for\s+(.+?)\s*%\}/s',
        'endfor'        => '/\{%\s*endfor\s*%\}/s',
        'extends'       => '/\{%\s*extends\s+(["\'])(.*?)\1\s*%\}/s',
        'section'       => '/\{%\s*section\s+(["\'])(.*?)\1\s*%\}/s',
        'endsection'    => '/\{%\s*endsection\s*%\}/s',
        'include'       => '/\{%\s*include\s+(["\'])(.*?)\1\s*%\}/s',
        'component'     => '/\{%\s*component\s+(["\'])(.*?)\1(\s+with\s+(.*?))?\s*%\}/s',
        'endcomponent'  => '/\{%\s*endcomponent\s*%\}/s',
        'yield'         => '/\{%\s*yield\s+(["\'])(.*?)\1(\s+(["\']?)(.*?)\4)?\s*%\}/s', // Unterstützung für Standardwert
        'isset'         => '/\{%\s*isset\s+(.+?)\s*%\}/s',                                // isset-Direktive
        'endisset'      => '/\{%\s*endisset\s*%\}/s',                                     // endisset-Direktive
        'empty'         => '/\{%\s*empty\s+(.+?)\s*%\}/s',                                // empty-Direktive
        'endempty'      => '/\{%\s*endempty\s*%\}/s',                                     // endempty-Direktive
        'php'           => '/\{%\s*php\s*%\}(.*?)\{%\s*endphp\s*%\}/s',                   // PHP-Code-Blöcke
    ];

    /**
     * Potenziell gefährliche Funktionen, die blockiert werden sollten
     *
     * @var array
     */
    private const BLOCKED_FUNCTIONS = [
        'exec', 'shell_exec', 'system', 'passthru', 'eval',
        'popen', 'proc_open', 'pcntl_exec', 'assert'
    ];

    /**
     * Registrierte Hilfsfunktionen
     *
     * @var array
     */
    private array $functions = [];

    /**
     * Template-Variablen
     *
     * @var array
     */
    private array $data = [];

    /**
     * Cache für kompilierte Templates - Maps templatePath zu compiledPath
     *
     * @var array
     */
    private array $compiledTemplates = [];

    /**
     * Verzeichnis für Templates
     *
     * @var string
     */
    private string $templateDir;

    /**
     * Verzeichnis für kompilierte Templates
     *
     * @var string
     */
    private string $cacheDir;

    /**
     * Cache-Nutzung aktiviert/deaktiviert
     *
     * @var bool
     */
    private bool $useCache;

    /**
     * Aktuelles Layout
     *
     * @var string|null
     */
    private ?string $layout = null;

    /**
     * Gespeicherte Sections
     *
     * @var array
     */
    private array $sections = [];

    /**
     * Aktuell aktive Section
     *
     * @var string|null
     */
    private ?string $currentSection = null;

    /**
     * Stack für Parent-Templates
     *
     * @var string[]
     */
    private array $templateStack = [];

    /**
     * Stack für Komponenten
     *
     * @var array
     */
    private array $components = [];

    /**
     * Router-Instanz für URL-Generierung
     *
     * @var Router|null
     */
    private ?Router $router = null;

    /**
     * Erstellt eine neue Template-Engine-Instanz
     *
     * @param string $templateDir Verzeichnis für Templates
     * @param string|null $cacheDir Verzeichnis für kompilierte Templates (null für kein Caching)
     * @param bool $useCache Cache-Nutzung aktivieren/deaktivieren
     */
    public function __construct(
        string $templateDir,
        ?string $cacheDir = null,
        bool $useCache = true
    ) {
        $this->templateDir = rtrim($templateDir, '/') . '/';

        if ($cacheDir !== null) {
            $this->cacheDir = rtrim($cacheDir, '/') . '/';

            // Verzeichnis erstellen, falls nicht vorhanden
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }
        } else {
            $this->cacheDir = sys_get_temp_dir() . '/template_cache/';

            // Temporäres Verzeichnis erstellen, falls nicht vorhanden
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }
        }

        $this->useCache = $useCache;

        // Standard-Hilfsfunktionen registrieren
        $this->registerDefaultFunctions();
    }

    /**
     * Registriert den Router für URL-Generierung
     *
     * @param Router $router Router-Instanz
     * @return self
     */
    public function setRouter(Router $router): self
    {
        $this->router = $router;

        // DefaultFunctions mit dem Router aktualisieren
        $this->registerFunctionProvider(new DefaultFunctions($router));

        return $this;
    }

    /**
     * Registriert die Standard-Hilfsfunktionen
     *
     * @return void
     */
    private function registerDefaultFunctions(): void
    {
        $defaultFunctions = new DefaultFunctions($this->router);
        $this->registerFunctionProvider($defaultFunctions);

        // Zusätzliche Filterfunktionen registrieren
        $this->registerFunction('length', function($value) {
            if (is_array($value) || $value instanceof \Countable) {
                return count($value);
            }
            return is_string($value) ? mb_strlen($value) : 0;
        });

        $this->registerFunction('uppercase', function($value) {
            return is_string($value) ? mb_strtoupper($value, 'UTF-8') : $value;
        });

        $this->registerFunction('lowercase', function($value) {
            return is_string($value) ? mb_strtolower($value, 'UTF-8') : $value;
        });

        $this->registerFunction('json', function($value, $options = 0) {
            return json_encode($value, $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });

        $this->registerFunction('date', function($date, $format = 'd.m.Y') {
            if ($date instanceof \DateTimeInterface) {
                return $date->format($format);
            } elseif (is_numeric($date)) {
                return date($format, $date);
            } elseif (is_string($date)) {
                return date($format, strtotime($date));
            }
            return '';
        });

        $this->registerFunction('isset', function($var) {
            return isset($var);
        });

        $this->registerFunction('empty', function($var) {
            return empty($var);
        });

        $this->registerFunction('trim', function($string, $charlist = " \t\n\r\0\x0B") {
            return trim($string, $charlist);
        });
    }

    /**
     * Registriert einen Function-Provider
     *
     * @param FunctionProviderInterface $provider Function-Provider
     * @return self
     */
    public function registerFunctionProvider(FunctionProviderInterface $provider): self
    {
        // Alle Methoden des Providers registrieren
        $functions = $provider->getFunctions();

        foreach ($functions as $name => $callback) {
            $this->registerFunction($name, $callback);
        }

        return $this;
    }

    /**
     * Registriert eine Hilfsfunktion
     *
     * @param string $name Funktionsname
     * @param callable $callback Callback-Funktion
     * @return self
     */
    public function registerFunction(string $name, callable $callback): self
    {
        $this->functions[$name] = $callback;
        return $this;
    }

    /**
     * Setzt Daten für das Template
     *
     * @param string|array $key Schlüssel oder Array mit Schlüssel-Wert-Paaren
     * @param mixed $value Wert (wenn $key ein String ist)
     * @return self
     */
    public function with(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[$key] = $value;
        }

        return $this;
    }

    /**
     * Setzt das Layout für das Template
     *
     * @param string|null $layout Layout-Name oder null, um kein Layout zu verwenden
     * @return self
     */
    public function layout(?string $layout = null): self
    {
        $this->layout = $layout;
        return $this;
    }

    /**
     * Rendert ein Template mit den gegebenen Daten
     *
     * @param string $template Template-Name
     * @param array $data Zusätzliche Daten für das Template
     * @return string Gerendertes Template
     * @throws TemplateException Wenn das Template nicht gefunden wird
     */
    public function render(string $template, array $data = []): string
    {
        // Daten zusammenführen
        $mergedData = array_merge($this->data, $data);

        // Template-Stack zurücksetzen
        $this->templateStack = [];

        // Sections zurücksetzen
        $this->sections = [];

        // Layout merken, damit es nach dem Rendern wiederhergestellt werden kann
        $originalLayout = $this->layout;

        try {
            // Template kompilieren und ausführen
            $result = $this->executeTemplate($template, $mergedData);

            // Wenn ein Layout gesetzt ist, dieses mit dem Inhalt als "content" rendern
            if ($this->layout !== null) {
                $layoutData = array_merge($mergedData, ['content' => $result]);
                $result = $this->executeTemplate($this->layout, $layoutData);
            }

            return $result;
        } finally {
            // Layout zurücksetzen
            $this->layout = $originalLayout;
        }
    }

    /**
     * Führt ein Template aus
     *
     * @param string $template Template-Name
     * @param array $data Daten für das Template
     * @return string Ausgeführtes Template
     * @throws TemplateException Wenn ein Fehler auftritt
     */
    private function executeTemplate(string $template, array $data): string
    {
        // Vollständigen Pfad zum Template ermitteln
        $templatePath = $this->resolvePath($template);

        // Template-Stack aktualisieren
        $this->templateStack[] = $template;

        try {
            // Template-Code kompilieren
            $compiledPath = $this->getCompiledPath($templatePath);

            // Prüfen, ob eine neue Kompilierung erforderlich ist
            if ($this->shouldRecompile($templatePath, $compiledPath)) {
                $this->compileTemplate($templatePath, $compiledPath);
                // Cache aktualisieren
                $this->compiledTemplates[$templatePath] = $compiledPath;
            }

            // Template mit Daten ausführen
            return $this->evaluateTemplate($compiledPath, $data);
        } catch (\Throwable $e) {
            // Verbesserte Fehlerbehandlung mit Kontextinformationen
            $errorContext = $this->getErrorContext($templatePath, $e);

            throw new TemplateException(
                "Fehler beim Ausführen des Templates '$template': " . $e->getMessage() . "\n" . $errorContext,
                $e->getCode(),
                $e
            );
        } finally {
            // Template vom Stack entfernen
            array_pop($this->templateStack);
        }
    }

    /**
     * Ermittelt den Fehlerkontext für eine verbesserte Fehlermeldung
     *
     * @param string $templatePath Pfad zum Template
     * @param \Throwable $e Die aufgetretene Exception
     * @return string Formatierter Fehlerkontext
     */
    private function getErrorContext(string $templatePath, \Throwable $e): string
    {
        if (!file_exists($templatePath)) {
            return "Template-Datei nicht gefunden: $templatePath";
        }

        $lines = file($templatePath);
        $lineNumber = 0;

        // Versuche, die betroffene Zeile zu finden
        if (preg_match('/line\s+(\d+)/', $e->getMessage(), $matches)) {
            $lineNumber = (int)$matches[1];
        } elseif (preg_match('/on line (\d+)/', $e->getMessage(), $matches)) {
            $lineNumber = (int)$matches[1];
        }

        // Wenn keine Zeilennummer gefunden wurde, prüfe Kompilierungsprobleme
        if ($lineNumber === 0 && $compiledPath = $this->getCompiledPath($templatePath)) {
            if (file_exists($compiledPath) && preg_match('/line\s+(\d+)/', $e->getMessage(), $matches)) {
                return "Fehler im kompilierten Code. Bitte überprüfen Sie die Template-Syntax.";
            }
        }

        $contextLines = [];
        if ($lineNumber > 0 && isset($lines[$lineNumber - 1])) {
            $start = max(0, $lineNumber - 3);
            $end = min(count($lines) - 1, $lineNumber + 1);
            $contextLines[] = "Fehlerkontext:";
            for ($i = $start; $i <= $end; $i++) {
                $marker = ($i + 1 === $lineNumber) ? ' >>> ' : '     ';
                $contextLines[] = sprintf("%s%3d: %s", $marker, $i + 1, rtrim($lines[$i]));
            }
        }

        return implode("\n", $contextLines);
    }

    /**
     * Prüft, ob ein Template neu kompiliert werden muss
     *
     * @param string $templatePath Pfad zum Template
     * @param string $compiledPath Pfad zum kompilierten Template
     * @return bool True, wenn das Template neu kompiliert werden muss
     */
    private function shouldRecompile(string $templatePath, string $compiledPath): bool
    {
        // Wenn Cache deaktiviert ist, immer neu kompilieren
        if (!$this->useCache) {
            return true;
        }

        // Wenn das kompilierte Template nicht existiert, neu kompilieren
        if (!file_exists($compiledPath)) {
            return true;
        }

        // Wenn das Template neuer ist als das kompilierte Template, neu kompilieren
        return filemtime($templatePath) > filemtime($compiledPath);
    }

    /**
     * Kompiliert ein Template
     *
     * @param string $templatePath Pfad zum Template
     * @param string $compiledPath Pfad zum kompilierten Template
     * @throws TemplateException Wenn das Template nicht gelesen werden kann
     */
    private function compileTemplate(string $templatePath, string $compiledPath): void
    {
        // Template-Inhalt lesen
        $content = @file_get_contents($templatePath);

        if ($content === false) {
            throw new TemplateException("Template '$templatePath' konnte nicht gelesen werden");
        }

        // Template kompilieren
        $compiled = $this->compile($content);

        // Kompiliertes Template speichern
        $result = @file_put_contents($compiledPath, $compiled);

        if ($result === false) {
            throw new TemplateException("Kompiliertes Template konnte nicht in '$compiledPath' gespeichert werden");
        }
    }

    /**
     * Kompiliert einen Template-String
     *
     * @param string $content Template-Inhalt
     * @return string Kompilierter PHP-Code
     */
    private function compile(string $content): string
    {
        // Kommentare entfernen
        $content = preg_replace(self::PATTERNS['comment'], '', $content);

        // Layout-Direktiven verarbeiten
        $content = $this->compileLayoutDirectives($content);

        // Includes verarbeiten
        $content = $this->compileIncludes($content);

        // Komponenten verarbeiten
        $content = $this->compileComponents($content);

        // Zusätzliche Direktiven verarbeiten (isset, empty, etc.)
        $content = $this->compileAdditionalDirectives($content);

        // Kontrollstrukturen verarbeiten
        $content = $this->compileControlStructures($content);

        // Pipe-Ausdrücke verarbeiten
        $content = $this->compilePipes($content);

        // PHP-Code-Blöcke verarbeiten
        $content = $this->compilePhpBlocks($content);

        // Variablen verarbeiten
        $content = $this->compileVariables($content);

        // PHP-Code für das Template erstellen
        return '<?php 
if (!function_exists("e")) {
    /**
     * @param mixed $expression
     * @return mixed
     */
    function e(mixed $expression): mixed { 
        return htmlspecialchars((string)$expression, ENT_QUOTES, "UTF-8", false); 
    }
}
?>' . $content;
    }

    /**
     * Kompiliert PHP-Code-Blöcke
     *
     * @param string $content Template-Inhalt
     * @return string Kompilierter Inhalt
     */
    private function compilePhpBlocks(string $content): string
    {
        return preg_replace_callback(self::PATTERNS['php'], function ($matches) {
            return '<?php ' . $matches[1] . ' ?>';
        }, $content);
    }

    /**
     * Kompiliert Layout-Direktiven
     *
     * @param string $content Template-Inhalt
     * @return string Kompilierter Inhalt
     */
    private function compileLayoutDirectives(string $content): string
    {
        // @extends - Layout-Direktive
        $content = preg_replace_callback(self::PATTERNS['extends'], function ($matches) {
            return '<?php $this->layout("' . $matches[2] . '"); ?>';
        }, $content);

        // @section - Section-Direktive
        $content = preg_replace_callback(self::PATTERNS['section'], function ($matches) {
            return '<?php $this->startSection("' . $matches[2] . '"); ?>';
        }, $content);

        // @endsection - EndSection-Direktive
        $content = preg_replace(self::PATTERNS['endsection'], '<?php $this->endSection(); ?>', $content);

        // @yield - Yield-Direktive
        $content = preg_replace_callback(self::PATTERNS['yield'], function ($matches) {
            $name = $matches[2];
            $default = isset($matches[5]) ? ', "' . addslashes($matches[5]) . '"' : '';
            return '<?php echo $this->yieldContent("' . $name . '"' . $default . '); ?>';
        }, $content);

        return $content;
    }

    /**
     * Kompiliert Include-Direktiven
     *
     * @param string $content Template-Inhalt
     * @return string Kompilierter Inhalt
     */
    private function compileIncludes(string $content): string
    {
        return preg_replace_callback(self::PATTERNS['include'], function ($matches) {
            return '<?php echo $this->includeTemplate("' . $matches[2] . '", get_defined_vars()); ?>';
        }, $content);
    }

    /**
     * Kompiliert Komponenten-Direktiven
     *
     * @param string $content Template-Inhalt
     * @return string Kompilierter Inhalt
     */
    private function compileComponents(string $content): string
    {
        // @component - Komponenten-Direktive mit optionalen Parametern
        $content = preg_replace_callback(self::PATTERNS['component'], function ($matches) {
            $component = $matches[2];
            $withParams = isset($matches[4]) ? $matches[4] : '';

            // Füge $ zu Variablennamen in Parametern hinzu
            if (!empty($withParams)) {
                $withParams = preg_replace('/([\'"])\s*:\s*([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*\()/', '$1: $$2', $withParams);
                return '<?php $this->startComponent("' . $component . '", [' . $withParams . ']); ?>';
            }

            return '<?php $this->startComponent("' . $component . '", []); ?>';
        }, $content);

        // @endcomponent - EndComponent-Direktive
        $content = preg_replace(self::PATTERNS['endcomponent'], '<?php echo $this->endComponent(); ?>', $content);

        return $content;
    }

    /**
     * Kompiliert zusätzliche Direktiven (isset, empty, etc.)
     *
     * @param string $content Template-Inhalt
     * @return string Kompilierter Inhalt
     */
    private function compileAdditionalDirectives(string $content): string
    {
        // isset-Direktive kompilieren
        $content = preg_replace_callback(self::PATTERNS['isset'], function ($matches) {
            $variable = $matches[1];
            // Variable automatisch mit $ präfixieren
            $variable = preg_replace_callback(
                '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*\()/',
                function($matches) {
                    return '$' . $matches[1];
                },
                $variable
            );
            return '<?php if(isset(' . $this->sanitizeExpression($variable) . ')): ?>';
        }, $content);
        $content = preg_replace(self::PATTERNS['endisset'], '<?php endif; ?>', $content);

        // empty-Direktive kompilieren
        $content = preg_replace_callback(self::PATTERNS['empty'], function ($matches) {
            $variable = $matches[1];
            // Variable automatisch mit $ präfixieren
            $variable = preg_replace_callback(
                '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*\()/',
                function($matches) {
                    return '$' . $matches[1];
                },
                $variable
            );
            return '<?php if(empty(' . $this->sanitizeExpression($variable) . ')): ?>';
        }, $content);
        $content = preg_replace(self::PATTERNS['endempty'], '<?php endif; ?>', $content);

        return $content;
    }
    /**
     * Kompiliert Kontrollstrukturen
     *
     * @param string $content Template-Inhalt
     * @return string Kompilierter Inhalt
     */
    private function compileControlStructures(string $content): string
    {
        // @if - If-Direktive
        $content = preg_replace_callback(self::PATTERNS['if'], function ($matches) {
            $condition = $matches[1];
            // Füge $ zu Variablennamen hinzu, sofern nicht bereits vorhanden
            $condition = preg_replace_callback(
                '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*\()/',
                function($matches) {
                    return '$' . $matches[1];
                },
                $condition
            );
            return '<?php if(' . $this->sanitizeExpression($condition) . '): ?>';
        }, $content);

        // @elseif - ElseIf-Direktive
        $content = preg_replace_callback(self::PATTERNS['elseif'], function ($matches) {
            $condition = $matches[1];
            // Füge $ zu Variablennamen hinzu, sofern nicht bereits vorhanden
            $condition = preg_replace_callback(
                '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*\()/',
                function($matches) {
                    return '$' . $matches[1];
                },
                $condition
            );
            return '<?php elseif(' . $this->sanitizeExpression($condition) . '): ?>';
        }, $content);

        // @else - Else-Direktive
        $content = preg_replace(self::PATTERNS['else'], '<?php else: ?>', $content);

        // @endif - EndIf-Direktive
        $content = preg_replace(self::PATTERNS['endif'], '<?php endif; ?>', $content);

        // @foreach - Foreach-Direktive
        $content = preg_replace_callback(self::PATTERNS['foreach'], function ($matches) {
            $forEach = $matches[1];

            // Regex zum Extrahieren der Variablen aus der foreach-Anweisung
            if (preg_match('/(.+?)\s+as\s+(\w+)(?:\s*=>\s*(\w+))?/', $forEach, $foreachParts)) {
                $collection = $foreachParts[1];
                $keyVar = isset($foreachParts[3]) ? $foreachParts[2] : null;
                $valueVar = isset($foreachParts[3]) ? $foreachParts[3] : $foreachParts[2];

                // Collection-Variable mit $ präfixieren, wenn noch nicht vorhanden
                if (strpos(trim($collection), '$') !== 0 && !preg_match('/\(.*\)/', $collection)) {
                    $collection = '$' . trim($collection);
                } else {
                    // Komplexerer Ausdruck: Alle Variablen mit $ präfixieren
                    $collection = preg_replace_callback(
                        '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*\()/',
                        function($matches) {
                            return '$' . $matches[1];
                        },
                        $collection
                    );
                    $collection = $this->sanitizeExpression($collection);
                }

                // Stellt sicher, dass die Variablen in der PHP-Schleife ein $-Präfix haben
                $valueVar = '$' . $valueVar;
                $keyPart = $keyVar ? '$' . $keyVar . ' => ' : '';

                return '<?php foreach(' . $collection . ' as ' . $keyPart . $valueVar . '): ?>';
            }

            // Fallback
            $forEach = preg_replace_callback(
                '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*\()/',
                function($matches) {
                    return '$' . $matches[1];
                },
                $forEach
            );
            return '<?php foreach(' . $this->sanitizeExpression($forEach) . '): ?>';
        }, $content);

        // @endforeach - EndForeach-Direktive
        $content = preg_replace(self::PATTERNS['endforeach'], '<?php endforeach; ?>', $content);

        // @for - For-Direktive
        $content = preg_replace_callback(self::PATTERNS['for'], function ($matches) {
            $forLoop = $matches[1];
            // Füge $ zu Variablennamen hinzu, sofern nicht bereits vorhanden
            $forLoop = preg_replace_callback(
                '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*\()/',
                function($matches) {
                    return '$' . $matches[1];
                },
                $forLoop
            );
            return '<?php for(' . $this->sanitizeExpression($forLoop) . '): ?>';
        }, $content);

        // @endfor - EndFor-Direktive
        $content = preg_replace(self::PATTERNS['endfor'], '<?php endfor; ?>', $content);

        return $content;
    }

    /**
     * Kompiliert Pipe-Ausdrücke
     *
     * @param string $content Template-Inhalt
     * @return string Kompilierter Inhalt
     */
    private function compilePipes(string $content): string
    {
        // Pipe-Ausdrücke in {{...}} und {!!...!!} suchen
        $patterns = [
            'escaped' => '/\{\{\s*(.*?\|.*?)\s*\}\}/s',
            'raw' => '/\{!!\s*(.*?\|.*?)\s*!!\}/s',
        ];

        foreach ($patterns as $type => $pattern) {
            $content = preg_replace_callback($pattern, function ($matches) use ($type) {
                $expression = $matches[1];

                // Pipe-Ausdrücke verarbeiten (z.B. "variable|filter" in "filter(variable)" umwandeln)
                $processedExpression = $this->processPipeExpression($expression);

                // Zurück in die richtige Syntax umwandeln
                return $type === 'escaped'
                    ? '{{ ' . $processedExpression . ' }}'
                    : '{!! ' . $processedExpression . ' !!}';
            }, $content);
        }

        return $content;
    }

    /**
     * Verarbeitet einen Pipe-Ausdruck
     *
     * @param string $expression Pipe-Ausdruck (z.B. "variable|filter|anotherFilter")
     * @return string Verarbeiteter Ausdruck (z.B. "anotherFilter(filter(variable))")
     */
    private function processPipeExpression(string $expression): string
    {
        $parts = explode('|', $expression);
        $base = trim(array_shift($parts));

        // Base-Variable mit $ präfixieren, wenn es ein einfacher Variablenname ist
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $base) && strpos($base, '$') !== 0) {
            $base = '$' . $base;
        }

        foreach ($parts as $part) {
            $part = trim($part);

            // Prüfen, ob der Filter Parameter hat (z.B. "limit:10")
            if (strpos($part, ':') !== false) {
                list($filter, $params) = explode(':', $part, 2);
                // Kommas durch Parameter ersetzen
                $params = str_replace(',', "', '", $params);
                $base = "$filter($base, '$params')";
            } else {
                $base = "$part($base)";
            }
        }

        return $base;
    }

    /**
     * Kompiliert Variablen
     *
     * @param string $content Template-Inhalt
     * @return string Kompilierter Inhalt
     */
    private function compileVariables(string $content): string
    {
        // {{ var }} - Escaped Variables
        $content = preg_replace_callback(self::PATTERNS['escaped'], function ($matches) {
            $expression = $matches[1];

            // Prüfen, ob es sich um einen einfachen Variablennamen handelt
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', trim($expression))) {
                // Einfachen Variablennamen mit $ präfixieren, wenn noch nicht vorhanden
                $varName = trim($expression);
                if (strpos($varName, '$') !== 0) {
                    $varName = '$' . $varName;
                }
                return '<?php echo e(' . $varName . '); ?>';
            }

            // Automatisch $ zu Variablen hinzufügen, die kein $ am Anfang haben
            // KORRIGIERT: Verwende eine sichere Methode zur Variablenersetzung
            $expression = preg_replace_callback(
                '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*\()/',
                function($matches) {
                    return '$' . $matches[1];
                },
                $expression
            );

            return '<?php echo e(' . $this->sanitizeExpression($expression) . '); ?>';
        }, $content);

        // {!! var !!} - Unescaped Variables
        $content = preg_replace_callback(self::PATTERNS['raw'], function ($matches) {
            $expression = $matches[1];

            // Prüfen, ob es sich um einen einfachen Variablennamen handelt
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', trim($expression))) {
                // Einfachen Variablennamen mit $ präfixieren, wenn noch nicht vorhanden
                $varName = trim($expression);
                if (strpos($varName, '$') !== 0) {
                    $varName = '$' . $varName;
                }
                return '<?php echo ' . $varName . '; ?>';
            }

            // Automatisch $ zu Variablen hinzufügen, die kein $ am Anfang haben
            // KORRIGIERT: Verwende eine sichere Methode zur Variablenersetzung
            $expression = preg_replace_callback(
                '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*\()/',
                function($matches) {
                    return '$' . $matches[1];
                },
                $expression
            );

            return '<?php echo ' . $this->sanitizeExpression($expression) . '; ?>';
        }, $content);

        return $content;
    }

    /**
     * Säubert einen Ausdruck für die sichere Verwendung in PHP-Code
     *
     * @param string $expression Ausdruck
     * @return string Gesäuberter Ausdruck
     * @throws TemplateException Wenn gefährliche Funktionen verwendet werden
     */
    private function sanitizeExpression(string $expression): string
    {
        // Potenziell gefährliche Funktionen blockieren
        foreach (self::BLOCKED_FUNCTIONS as $func) {
            if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/i', $expression)) {
                throw new TemplateException("Unerlaubte Funktion '$func' im Template-Ausdruck");
            }
        }

        // Hilfsfunktionen ersetzen (z.B. url('home') -> $this->callFunction('url', ['home'])
        return preg_replace_callback(
            '/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\(\s*(.*?)\s*\)/',
            function ($matches) {
                $functionName = $matches[1];
                $args = $matches[2];

                // Wenn es sich um eine registrierte Hilfsfunktion handelt
                if (isset($this->functions[$functionName])) {
                    if (empty($args)) {
                        return '$this->callFunction("' . $functionName . '", [])';
                    }

                    // Füge $ zu Variablennamen in Argumenten hinzu
                    $args = preg_replace_callback(
                        '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b(?!\s*\()/',
                        function($matches) {
                            return '$' . $matches[1];
                        },
                        $args
                    );

                    // Wir müssen die Argumente korrekt parsen, da sie komplex sein können
                    return '$this->callFunction("' . $functionName . '", [' . $args . '])';
                }

                // Ansonsten unverändert zurückgeben
                return $matches[0];
            },
            $expression
        );
    }

    /**
     * Ruft eine registrierte Hilfsfunktion auf
     *
     * @param string $name Funktionsname
     * @param array $args Argumente
     * @return mixed Rückgabewert der Funktion
     * @throws TemplateException Wenn die Funktion nicht registriert ist
     */
    public function callFunction(string $name, array $args): mixed
{
    if (!isset($this->functions[$name])) {
        throw new TemplateException("Hilfsfunktion '$name' ist nicht registriert");
    }

    try {
        return call_user_func_array($this->functions[$name], $args);
    } catch (\Throwable $e) {
        throw new TemplateException("Fehler beim Aufrufen der Hilfsfunktion '$name': " . $e->getMessage(), 0, $e);
    }
}

    /**
     * Führt ein kompiliertes Template aus
     *
     * @param string $compiledPath Pfad zum kompilierten Template
     * @param array $data Daten für das Template
     * @return string Ausgeführtes Template
     */
    private function evaluateTemplate(string $compiledPath, array $data): string
{
    // Extrahiere die Daten als Variablen für das Template
    extract($data, EXTR_SKIP);

    // Output-Pufferung starten
    ob_start();

    // Bei Verfügbarkeit Opcache nutzen
    if (function_exists('opcache_compile_file')) {
        opcache_compile_file($compiledPath);
    }

    try {
        // Template einbinden
        include $compiledPath;
    } catch (\Throwable $e) {
        // Output-Puffer leeren
        ob_end_clean();
        throw $e;
    }

    // Output-Puffer zurückgeben und leeren
    return ob_get_clean();
}

    /**
     * Startet eine neue Section
     *
     * @param string $name Name der Section
     * @return void
     */
    public function startSection(string $name): void
{
    $this->currentSection = $name;
    ob_start();
}

    /**
     * Beendet die aktuelle Section
     *
     * @return void
     * @throws TemplateException Wenn keine Section gestartet wurde
     */
    public function endSection(): void
{
    if ($this->currentSection === null) {
        throw new TemplateException("Es wurde keine Section gestartet");
    }

    $this->sections[$this->currentSection] = ob_get_clean();
    $this->currentSection = null;
}

    /**
     * Gibt den Inhalt einer Section aus
     *
     * @param string $name Name der Section
     * @param string $default Standard-Inhalt, falls die Section nicht existiert
     * @return string Inhalt der Section
     */
    public function yieldContent(string $name, string $default = ''): string
{
    return $this->sections[$name] ?? $default;
}

    /**
     * Bindet ein Template ein
     *
     * @param string $template Template-Name
     * @param array $data Daten für das Template
     * @return string Eingebundenes Template
     */
    public function includeTemplate(string $template, array $data = []): string
{
    return $this->executeTemplate($template, $data);
}

    /**
     * Startet eine neue Komponente
     *
     * @param string $name Name der Komponente
     * @param array $data Daten für die Komponente
     * @return void
     */
    public function startComponent(string $name, array $data = []): void
{
    $this->startSection('component_' . count($this->templateStack));
    $this->components[] = [
        'name' => $name,
        'data' => $data
    ];
}

    /**
     * Beendet die aktuelle Komponente
     *
     * @return string Gerendertes Komponenten-Template
     */
    public function endComponent(): string
{
    $component = array_pop($this->components);
    $content = $this->yieldContent('component_' . count($this->templateStack));

    // Komponenten-Daten mit dem Inhalt zusammenführen
    $data = array_merge($component['data'], ['slot' => $content]);

    // Komponenten-Template rendern
    return $this->includeTemplate('components/' . $component['name'], $data);
}

    /**
     * Löst den vollen Pfad zu einem Template auf
     *
     * @param string $template Template-Name
     * @return string Vollständiger Pfad zum Template
     * @throws TemplateException Wenn das Template nicht gefunden wird
     */
    private function resolvePath(string $template): string
{
    // Dateiendung hinzufügen, wenn nicht vorhanden
    if (!str_ends_with($template, '.php')) {
        $template .= '.php';
    }

    // Vollständigen Pfad erstellen
    $path = $this->templateDir . $template;

    // Prüfen, ob das Template existiert
    if (!file_exists($path)) {
        throw new TemplateException("Template '$path' nicht gefunden");
    }

    return $path;
}

    /**
     * Generiert den Pfad für ein kompiliertes Template
     *
     * @param string $templatePath Pfad zum Template
     * @return string Pfad zum kompilierten Template
     */
    private function getCompiledPath(string $templatePath): string
{
    // Prüfen, ob der Pfad bereits im Cache ist
    if (isset($this->compiledTemplates[$templatePath])) {
        return $this->compiledTemplates[$templatePath];
    }

    // Eindeutigen Namen für das kompilierte Template generieren
    $hash = md5($templatePath);
    $compiledPath = $this->cacheDir . $hash . '.php';

    // Im In-Memory-Cache speichern
    $this->compiledTemplates[$templatePath] = $compiledPath;

    return $compiledPath;
}

    /**
     * Löscht alle kompilierten Templates
     *
     * @return bool True bei Erfolg
     */
    public function clearCache(): bool
{
    try {
        // Alle Dateien im Cache-Verzeichnis löschen
        $files = glob($this->cacheDir . '*.php');

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        // Cache-Array leeren
        $this->compiledTemplates = [];

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

    /**
     * Entfernt ein einzelnes Template aus dem Cache
     *
     * @param string $template Template-Name
     * @return bool True bei Erfolg
     */
    public function clearTemplateCache(string $template): bool
{
    try {
        $templatePath = $this->resolvePath($template);

        // Aus In-Memory-Cache entfernen
        if (isset($this->compiledTemplates[$templatePath])) {
            $compiledPath = $this->compiledTemplates[$templatePath];

            // Aus Dateisystem-Cache entfernen
            if (file_exists($compiledPath)) {
                unlink($compiledPath);
            }

            unset($this->compiledTemplates[$templatePath]);
        }

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

    /**
     * Debug-Methode zum Anzeigen des kompilierten Templates
     *
     * @param string $template Template-Name
     * @return string|null Der kompilierte PHP-Code oder null bei Fehler
     */
    public function debugTemplate(string $template): ?string
{
    try {
        $templatePath = $this->resolvePath($template);
        $content = @file_get_contents($templatePath);

        if ($content === false) {
            return null;
        }

        return $this->compile($content);
    } catch (\Throwable $e) {
        return "Fehler beim Kompilieren des Templates: " . $e->getMessage();
    }
}

    /**
     * Gibt die verfügbaren Hilfsfunktionen zurück
     *
     * @return array Liste der registrierten Hilfsfunktionen
     */
    public function getRegisteredFunctions(): array
{
    return array_keys($this->functions);
}

    /**
     * Magische Methode für die String-Konvertierung
     *
     * @return string Gerendertes Template
     */
    public function __toString(): string
{
    try {
        // Hier muss geklärt werden, was gerendert werden soll, da kein Template angegeben ist
        // In diesem Fall geben wir eine aussagekräftige Fehlermeldung zurück
        return 'TemplateEngine kann nicht direkt in einen String konvertiert werden (kein Template angegeben).';
    } catch (\Throwable $e) {
        // Fehler protokollieren statt trigger_error
        error_log('TemplateEngine Error in __toString: ' . $e->getMessage());
        return 'Fehler in TemplateEngine: ' . $e->getMessage();
    }
}
}