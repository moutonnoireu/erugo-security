<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: [
            'refresh_token',
        ]);

        // Behind a reverse proxy, set TRUSTED_PROXIES (comma separated IPs/CIDRs,
        // or "*") so the client IP is read from X-Forwarded-For. Nothing is
        // trusted by default, which keeps the client IP from being spoofed.
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));
        if ($trustedProxies !== '') {
            $middleware->trustProxies(
                at: $trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies))
            );
        }

        $middleware->appendToGroup('api', \App\Http\Middleware\RestrictSignInToAllowedIps::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\RestrictSignInToAllowedIps::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();

// In the Docker image, the entrypoint derives APP_KEY, JWT_SECRET and the
// database settings at start and writes them to a private runtime file, so
// that commands run with `docker exec` see the same configuration as the
// server. Real environment variables always take precedence over it.
$runtimeEnv = getenv('ERUGO_RUNTIME_ENV') ?: '/tmp/erugo.env';
if (! is_file($app->basePath('.env')) && is_readable($runtimeEnv)) {
    $app->useEnvironmentPath(dirname($runtimeEnv))->loadEnvironmentFrom(basename($runtimeEnv));
}

return $app;
