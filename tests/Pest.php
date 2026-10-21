<?php

use App\Services\Sefaz\SefazGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeSefazGateway;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Rede de segurança: nenhum teste chega à SEFAZ real, mesmo que esqueça de configurar o fake.
        $this->app->instance(SefazGateway::class, new FakeSefazGateway);
        Redis::flushdb();
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

function fakeSefaz(): FakeSefazGateway
{
    return app(SefazGateway::class);
}
