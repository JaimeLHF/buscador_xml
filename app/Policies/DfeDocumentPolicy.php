<?php

namespace App\Policies;

use App\Models\DfeDocument;
use App\Models\User;

class DfeDocumentPolicy
{
    public function __construct(private CompanyPolicy $companies) {}

    public function view(User $user, DfeDocument $document): bool
    {
        return $this->companies->view($user, $document->company);
    }

    public function manifest(User $user, DfeDocument $document): bool
    {
        return $this->companies->update($user, $document->company);
    }
}
