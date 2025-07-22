<?php

namespace App\Policies;

use App\Models\ReceiptItem;
use App\Models\User;

class ReceiptItemPolicy
{
    public function view(User $user, ReceiptItem $receiptItem): bool
    {
        return $user->id === $receiptItem->receipt->user_id;
    }

    public function update(User $user, ReceiptItem $receiptItem): bool
    {
        return $user->id === $receiptItem->receipt->user_id;
    }

    public function delete(User $user, ReceiptItem $receiptItem): bool
    {
        return $user->id === $receiptItem->receipt->user_id;
    }
}
