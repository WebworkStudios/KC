# Template Engine für PHP 8.4

Diese leistungsstarke und dennoch schlanke Template-Engine bietet eine intuitive Syntax und optimale Performance für
moderne PHP 8.4 Anwendungen. Sie ist speziell für das ADR-Pattern (Action-Domain-Responder) konzipiert und lässt sich
nahtlos in das Framework integrieren.

## Features

- **Moderne Syntax**: Einfache, intuitive Template-Syntax mit `{{ variable }}` für Variablen und `{% directive %}` für
  Kontrollstrukturen.
- **Layout-System**: Flexibles Layout-System mit Template-Vererbung und wiederverwendbaren Sections.
- **Komponenten**: Wiederverwendbare UI-Komponenten mit Parameter-Unterstützung.
- **Hilfsfunktionen**: Umfangreiche Sammlung von Hilfsfunktionen für Formatierung, URL-Generierung und mehr.
- **Erweiterbar**: Einfache Erweiterbarkeit durch benutzerdefinierte Funktionen und Komponenten.
- **Performance**: Compiler- und Cache-System für optimale Leistung.
- **Sicherheit**: Automatisches Escaping von Ausgaben zum Schutz vor XSS-Angriffen.

## Installation

Die Template-Engine ist Teil des PHP 8.4 ADR Frameworks und wird automatisch installiert. Für die manuelle Installation:

```bash
# Verzeichnisstruktur anlegen
mkdir -p resources/views/{layouts,components,partials}
mkdir -p storage/framework/views
chmod -R 755 storage/framework/views
```

## Grundlegende Verwendung

### In einer Action-Klasse

```php
#[Route(path: '/', name: 'home')]
public function __invoke(Request $request, ViewFactory $view): Response
{
    return $view->render('home', [
        'title' => 'Startseite',
        'content' => 'Willkommen auf unserer Website!'
    ]);
}
```

### Template-Syntax

#### Variablen ausgeben

```php
{{ variable }}                 // Escaped
{!! variable !!}               // Unescaped (Raw)
```

#### Kontrollstrukturen

```php
{% if bedingung %}
    // Code
{% elseif andereBedingung %}
    // Code
{% else %}
    // Code
{% endif %}

{% foreach items as item %}
    {{ item.name }}
{% endforeach %}

{% for i in 1..10 %}
    {{ i }}
{% endfor %}
```

#### Layouts und Sections

```php
// layout.php
<!DOCTYPE html>
<html>
<head>
    <title>{% yield 'title' 'Standard-Titel' %}</title>
</head>
<body>
    <div class="content">
        {% yield 'content' %}
    </div>
</body>
</html>

// page.php
{% extends 'layouts/layout' %}

{% section 'title' %}
    Meine Seite
{% endsection %}

{% section 'content' %}
    <h1>Inhalt meiner Seite</h1>
    <p>Hier steht der Text.</p>
{% endsection %}
```

#### Komponenten

```php
// components/alert.php
<div class="alert alert-{{ type }}">
    <h4>{{ title }}</h4>
    <div class="alert-body">
        {{ slot }}
    </div>
</div>

// Verwendung:
{% component 'alert' with 'type': 'success', 'title': 'Erfolg!' %}
    Aktion wurde erfolgreich durchgeführt.
{% endcomponent %}
```

#### Partials einbinden

```php
{% include 'partials/header' %}
```

## Hilfsfunktionen

Die Template-Engine bietet viele nützliche Hilfsfunktionen:

```php
{{ url('route_name', {id: 123}) }}     // URL generieren
{{ asset('css/app.css') }}             // Asset-URL
{{ e(variable) }}                      // HTML escapen
{{ dateFormat(date, 'd.m.Y') }}        // Datum formatieren
{{ numberFormat(1234.56, 2) }}         // Zahlen formatieren
{{ class({'active': isActive}) }}      // Dynamische CSS-Klassen
{{ truncate(text, 100) }}              // Text kürzen
{{ nl2br(text) }}                      // Zeilenumbrüche in <br> konvertieren
```

## Erweitern der Template-Engine

### Eigene Hilfsfunktionen registrieren

```php
// CustomFunctions.php
class CustomFunctions implements FunctionProviderInterface
{
    public function getFunctions(): array
    {
        return [
            'markdown' => [$this, 'markdown'],
            'currency' => [$this, 'currency'],
        ];
    }
    
    public function markdown(string $text): string
    {
        // Markdown zu HTML konvertieren
    }
    
    public function currency(float $amount): string
    {
        // Währungsformatierung
    }
}

// Registrierung:
$viewFactory->registerFunctionProvider(new CustomFunctions());
```

### Einzelne Funktion registrieren

```php
$viewFactory->registerFunction('shorten', function(string $text, int $length = 100) {
    return substr($text, 0, $length) . '...';
});
```

## Performance-Optimierung

Die Template-Engine kompiliert Templates in nativen PHP-Code und cached das Ergebnis. In der Produktionsumgebung müssen
Templates nur bei Änderungen neu kompiliert werden.

```php
// Cache leeren (z.B. nach Deployment)
$viewFactory->clearCache();
```

## Fehlerbehandlung

Template-Fehler werden als `TemplateException` geworfen und enthalten detaillierte Informationen über den Fehler und die
Zeile, in der er aufgetreten ist. In der Entwicklungsumgebung werden diese Fehler mit Debug-Informationen angezeigt.

## Dependency Injection

Die Template-Engine unterstützt Dependency Injection und kann einfach im Container registriert werden. Der
`ViewServiceProvider` übernimmt die Registrierung aller benötigten Komponenten.

## Sicherheit

Alle Ausgaben werden standardmäßig escaped, um XSS-Angriffe zu verhindern. Unescaped-Ausgaben mit `{!! ... !!}` sollten
nur für vertrauenswürdige Inhalte verwendet werden.