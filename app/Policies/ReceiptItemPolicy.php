<?php

namespace App\Policies;

use App\Models\ReceiptItem;
use App\Models\User;

class ReceiptItemPolicy
{
    public function update(User $user, ReceiptItem $item): bool
    {
        return $user->id === $item->receipt->user_id;
    }
}
