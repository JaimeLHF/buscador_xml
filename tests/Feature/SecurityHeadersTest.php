<?php

it('sends security headers', function () {
    $this->get(route('login'))
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('never serves private files by URL', function () {
    expect(config('filesystems.disks.local.serve'))->toBeFalse();
});
