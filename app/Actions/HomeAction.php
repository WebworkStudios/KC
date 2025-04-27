<?php

namespace App\Actions;

use Src\Http\Request;
use Src\Http\Response;
use Src\Http\Route;

/**
 * Action für die Startseite
 */
class HomeAction
{
    /**
     * Rendert die Startseite
     *
     * @param Request $request HTTP-Anfrage
     * @return Response HTTP-Antwort
     */
    #[Route(path: '/', name: 'home')]
    public function __invoke(Request $request): Response
    {
        return new Response($this->renderHomePage($request), 200);
    }

    /**
     * Rendert die HTML-Startseite
     *
     * @return string HTML-Inhalt
     */
    private function renderHomePage(): string
    {
        // PHP-Version dynamisch ermitteln
        $phpVersion = PHP_VERSION;
        $date = date('Y-m-d H:i:s');

        // Optional: Falls relevante Daten aus dem Request benötigt werden
        // $name = $request->getRouteParameter('name', 'Standard-Name');

        return <<<'HTML'
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP 8.4 ADR Framework</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f8f9fa;
            color: #212529;
            line-height: 1.6;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        header {
            background-color: #4b54e8;
            color: white;
            padding: 3rem 0;
            text-align: center;
            margin-bottom: 2rem;
        }
        h1 {
            font-size: 3rem;
            margin: 0;
        }
        h2 {
            color: #4b54e8;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 0.5rem;
            margin-top: 2.5rem;
        }
        .subtitle {
            font-size: 1.3rem;
            margin-top: 0.5rem;
            font-weight: 300;
        }
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 2rem;
            margin: 2rem 0;
        }
        .feature {
            background-color: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .feature:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        }
        .feature h3 {
            color: #4b54e8;
            margin-top: 0;
        }
        .info-box {
            background-color: #e2e3ff;
            border-left: 5px solid #4b54e8;
            padding: 1rem;
            margin: 2rem 0;
            border-radius: 0 4px 4px 0;
        }
        .footer {
            margin-top: 4rem;
            text-align: center;
            color: #6c757d;
            font-size: 0.9rem;
            padding: 2rem 0;
            border-top: 1px solid #e9ecef;
        }
        pre {
            background-color: #f8f9fa;
            border-radius: 4px;
            padding: 1rem;
            overflow-x: auto;
        }
        code {
            font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.9em;
        }
        .server-info {
            margin-top: 2rem;
            font-size: 0.9rem;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>PHP 8.4 ADR Framework</h1>
            <div class="subtitle">Ein modernes Framework nach Action-Domain-Responder-Muster</div>
        </div>
    </header>
    
    <div class="container">
        <div class="info-box">
            <p>✅ <strong>Herzlichen Glückwunsch!</strong> Ihre Installation des PHP 8.4 ADR Frameworks läuft erfolgreich.</p>
        </div>
        
        <h2>Über das Framework</h2>
        <p>
            Dieses Framework folgt dem <strong>Action-Domain-Responder (ADR)</strong> Architekturmuster,
            einer Weiterentwicklung des klassischen MVC-Musters, speziell für moderne Web-Anwendungen optimiert.
            Es nutzt die neuesten Funktionen von PHP 8.4 für maximale Typsicherheit und Performanz.
        </p>
        
        <h2>Hauptfunktionen</h2>
        <div class="features">
            <div class="feature">
                <h3>ADR-Muster</h3>
                <p>Klare Trennung von Anfrageverarbeitung (Action), Geschäftslogik (Domain) und Antwortgenerierung (Responder).</p>
            </div>
            
            <div class="feature">
                <h3>Attributbasiertes Routing</h3>
                <p>Routendefinition direkt in Action-Klassen mittels PHP 8-Attributen für bessere Code-Organisation.</p>
            </div>
            
            <div class="feature">
                <h3>Dependency Injection</h3>
                <p>Leistungsstarker Container mit Auto-Wiring für einfache Verwaltung von Abhängigkeiten.</p>
            </div>
            
            <div class="feature">
                <h3>Middleware-System</h3>
                <p>Flexibles Middleware-System für HTTP-Anfragen mit einfacher Stack-Verarbeitung.</p>
            </div>
            
            <div class="feature">
                <h3>Caching-Unterstützung</h3>
                <p>Integrierte Cache-Systeme für Datenbank-Abfragen und HTTP-Antworten zur Performance-Optimierung.</p>
            </div>
            
            <div class="feature">
                <h3>Umfassendes Logging</h3>
                <p>Strukturiertes Logging-System mit verschiedenen Handlern und Kontext-Prozessoren.</p>
            </div>
        </div>
        
        <h2>Erste Schritte</h2>
        <p>
            Beginnen Sie mit der Erstellung einer neuen Action-Klasse in <code>app/Actions</code>:
        </p>
        
        <pre><code>namespace App\Actions;

use Src\Http\Request;
use Src\Http\Response;
use Src\Http\Route;

class HelloAction
{
    #[Route(path: '/hello/{name}', name: 'hello')]
    public function __invoke(Request $request): Response
    {
        $name = $request->getRouteParameter('name', 'Welt');
        return new Response("Hallo, {$name}!", 200);
    }
}</code></pre>

        <div class="server-info">
            <p>
                <strong>Server-Informationen:</strong><br>
                PHP Version: {$phpVersion}<br>
                Zeitstempel: {$date}
            </p>
        </div>
        
        <div class="footer">
            <p>PHP 8.4 ADR Framework — Powered by modern PHP practices</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}