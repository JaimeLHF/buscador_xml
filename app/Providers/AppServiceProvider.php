<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Services\Certificates\CertificateVault;
use App\Services\Certificates\EncrypterCertificateVault;
use App\Services\CnpjLookup\BrasilApiProvider;
use App\Services\CnpjLookup\CnpjaOpenProvider;
use App\Services\CnpjLookup\CnpjLookup;
use App\Services\Sefaz\SefazGateway;
use App\Services\Sefaz\SpedNfeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CertificateVault::class, function (): CertificateVault {
            $parse = fn (string $key): string => Str::startsWith($key, 'base64:') ? base64_decode(Str::after($key, 'base64:')) : $key;
            $key = (string) config('certificates.key');

            if ($key === '') {
                throw new RuntimeException('CERT_ENCRYPTION_KEY não configurada.');
            }

            $encrypter = (new Encrypter($parse($key), config('certificates.cipher')))
                ->previousKeys(array_map(fn (string $k) => $parse(trim($k)), config('certificates.previous_keys')));

            return new EncrypterCertificateVault($encrypter);
        });

        $this->app->bind(SefazGateway::class, SpedNfeGateway::class);

        $this->app->bind(CnpjLookup::class, fn ($app): CnpjLookup => new CnpjLookup([
            $app->make(BrasilApiProvider::class),
            $app->make(CnpjaOpenProvider::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // API de integração: limite por token, para um ERP mal configurado não derrubar o painel.
        RateLimiter::for('api', function (Request $request): Limit {
            $token = $request->attributes->get('api_token');

            return Limit::perMinute(120)->by($token instanceof ApiToken ? "api:{$token->id}" : "api-ip:{$request->ip()}");
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
