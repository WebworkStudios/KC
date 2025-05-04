<?php

namespace App\Actions;

use Src\Http\Request;
use Src\Http\Response;
use Src\Http\Route;

/**
 * Action für Fehlerseiten
 */
class ErrorAction
{
    #[Route(path: '/error/404', name: 'error.not_found')]
    public function __invoke()
    {
        // TODO: Implement __invoke() method.
    }

    /**
     * 404 Not Found Fehlerseite
     *
     * @param Request $request HTTP-Anfrage
     * @return Response HTTP-Antwort
     */
    #[Route(path: '/error/404', name: 'error.not_found')]
    public function notFound(Request $request): Response
    {
        return new Response($this->renderErrorPage(
            '404 Not Found',
            'Die angeforderte Seite wurde nicht gefunden.',
            $request->getPath()
        ), 404);
    }

    /**
     * 405 Method Not Allowed Fehlerseite
     *
     * @param Request $request HTTP-Anfrage
     * @return Response HTTP-Antwort
     */
    #[Route(path: '/error/405', name: 'error.method_not_allowed')]
    public function methodNotAllowed(Request $request): Response
    {
        return new Response($this->renderErrorPage(
            '405 Method Not Allowed',
            'Die verwendete HTTP-Methode ist für diese Ressource nicht erlaubt.',
            $request->getMethod() . ' ' . $request->getPath()
        ), 405);
    }

    /**
     * 500 Server Error Fehlerseite
     *
     * @param Request $request HTTP-Anfrage
     * @return Response HTTP-Antwort
     */
    #[Route(path: '/error/500', name: 'error.server_error')]
    public function serverError(Request $request): Response
    {
        return new Response($this->renderErrorPage(
            '500 Server Error',
            'Bei der Verarbeitung der Anfrage ist ein Fehler aufgetreten.',
            null
        ), 500);
    }

    /**
     * Rendert eine Fehlerseite
     *
     * @param string $title Fehlertitel
     * @param string $message Fehlermeldung
     * @param string|null $path Pfad, der den Fehler verursacht hat
     * @return string HTML-Code der Fehlerseite
     */
    private function renderErrorPage(string $title, string $message, ?string $path): string
    {
        $pathInfo = $path ? "<p>Pfad: <code>{$path}</code></p>" : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background-color: #f8f9fa;
            color: #343a40;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .error-container {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 2rem;
            max-width: 600px;
            width: 90%;
        }
        h1 {
            margin-top: 0;
            color: #dc3545;
            font-size: 2rem;
        }
        p {
            margin-bottom: 1.5rem;
        }
        .back-button {
            display: inline-block;
            background-color: #0d6efd;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            transition: background-color 0.2s;
        }
        .back-button:hover {
            background-color: #0b5ed7;
        }
        code {
            background-color: #f8f9fa;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>{$title}</h1>
        <p>{$message}</p>
        {$pathInfo}
        <a href="/" class="back-button">Zurück zur Startseite</a>
    </div>
</body>
</html>
HTML;
    }
}