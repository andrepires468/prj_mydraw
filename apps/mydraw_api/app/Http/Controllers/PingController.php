<?php

namespace App\Http\Controllers;

class PingController extends Controller
{
    /**
     * Endpoint de health check / smoke test.
     */
    public function __invoke()
    {
        return response()->json([
            'pong'      => true,
            'service'   => config('app.name', 'mydraw'),
            'env'       => config('app.env'),
            'timestamp' => \Illuminate\Support\Carbon::now()->toIso8601String(),
        ]);
    }
}
