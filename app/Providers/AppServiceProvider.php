<?php

namespace App\Providers;

use App\Services\Certificates\CertificateVault;
use App\Services\Certificates\EncrypterCertificateVault;
use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
            $key = (string) config('certificates.key');

            if ($key === '') {
                throw new RuntimeException('CERT_ENCRYPTION_KEY não configurada.');
            }

            if (Str::startsWith($key, 'base64:')) {
                $key = base64_decode(Str::after($key, 'base64:'));
            }

            return new EncrypterCertificateVault(new Encrypter($key, config('certificates.cipher')));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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
