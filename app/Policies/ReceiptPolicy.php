<?php

namespace App\Policies;

use App\Models\Receipt;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReceiptPolicy
{
    public function view(User $user, Receipt $receipt): bool
    {
        return $user->id === $receipt->user_id;
    }

    public function update(User $user, Receipt $receipt): bool
    {
        return $user->id === $receipt->user_id;
    }

    public function delete(User $user, Receipt $receipt): bool
    {
        return $user->id === $receipt->user_id;
    }
}
