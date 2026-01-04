<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class IPAddress
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $request->ip();
        $clientIpParts = explode('.', $clientIp);
        $clientPrefix = implode('.', array_slice($clientIpParts, 0, 3));

        // Fetch the list of active IP addresses from the database using the query builder
        $ip_addresses_db = DB::table('allowed_ips')->pluck('ip_prefix')->toArray();


        if (!in_array($clientPrefix, $ip_addresses_db)) {
            return response()->view('restricted', ['ip' => $clientIp], 403);
        }

        return $next($request);
    }
}
