<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

abstract class Controller
{
    /**
     * No content
     *
     * @return Response
     */
    protected function noContent(): Response
    {
        return response('', 204);
    }
}
