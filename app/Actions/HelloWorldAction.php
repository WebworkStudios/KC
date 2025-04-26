<?php
namespace App\Actions;

use Src\Http\Request;
use Src\Http\Response;

class HelloWorldAction
{
    public function __invoke(Request $request): Response
    {
        return new Response(view('hello_world', [
            'message' => 'Hello, World!'
        ]));
    }
}