<?php

namespace App\Http\Controllers\Api;

use App\Models\ReceiptItem;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;

class ItemController extends Controller
{
    public function update(Request $request, ReceiptItem $item)
    {
        Gate::authorize('update', $item);

        $request->validate([
            'category' => 'required|string|max:255',
            'is_uncertain' => 'boolean',
        ]);

        $item->update([
            'category' => $request->category,
            'is_uncertain' => $request->is_uncertain ?? false,
        ]);

        return response()->json($item);
    }
}
