<?php

namespace App\Http\Controllers\Api;

use App\Models\ReceiptItem;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ItemController extends Controller
{
    public function update(Request $request, ReceiptItem $item)
    {
        Gate::authorize('update', $item);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|string|max:255',
            'is_uncertain' => 'sometimes|boolean',
            'price' => 'sometimes|required|numeric|min:0',
        ]);

        $updateData = [];
        
        if ($request->has('name')) {
            $updateData['name'] = $request->name;
        }
        
        if ($request->has('category')) {
            $updateData['category'] = $request->category;
        }
        
        if ($request->has('is_uncertain')) {
            $updateData['is_uncertain'] = $request->is_uncertain;
        }
        
        if ($request->has('price')) {
            $updateData['price'] = $request->price;
        }

        Log::info('Item updated', [
            'item_id' => $item->id,
            'user_id' => $request->user()->id,
            'old_data' => $item->toArray(),
            'update_data' => $updateData
        ]);

        $item->update($updateData);

        $item->load('receipt');

        return response()->json([
            'data' => $item,
            'message' => 'Item updated successfully'
        ]);
    }

    public function show(ReceiptItem $item)
    {
        Gate::authorize('view', $item);
        
        $item->load('receipt');
        
        return response()->json([
            'data' => $item
        ]);
    }
}