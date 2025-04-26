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
        // Option 1: If you want to return HTML content directly
        return new Response('<h1>Hello, World!</h1>', 200);

        // Option 2: If you're trying to use a view renderer (which isn't implemented in the code you provided)
        // You would need to implement a view renderer first, then use something like:
        // return (new Response($viewRenderer->render('hello_world', ['message' => 'Hello, World!']), 200));

        // Option 3: If you want to return JSON
        // return Response::json(['message' => 'Hello, World!']);
    }
}