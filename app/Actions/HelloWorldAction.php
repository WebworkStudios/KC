<?php
namespace App\Actions;

use Src\Http\Request;
use Src\Http\Response;
use Src\Http\Route;

class HelloWorldAction
{
    #[Route(path: '/', name: 'home')]
    public function __invoke(Request $request): Response
    {
        return new Response('hello_world', [
            'message' => 'Hello, World!'
        ]);
    }
}