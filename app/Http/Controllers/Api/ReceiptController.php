<?php

namespace App\Http\Controllers\Api;

use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Jobs\ProcessReceiptJob;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ReceiptController extends Controller
{
    public function index(Request $request)
    {
        $receipts = $request->user()
            ->receipts()
            ->with(['items'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json($receipts);
    }

    public function store(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg|max:10240', // 10MB max
        ]);

        // Store image
        $imagePath = $request->file('image')->store('receipts', 'public');

        // Create receipt record
        $receipt = $request->user()->receipts()->create([
            'image_path' => $imagePath,
            'status' => 'processing',
            'week_of' => Carbon::now()->startOfWeek(),
        ]);

        // Dispatch AI processing job
        ProcessReceiptJob::dispatch($receipt);

        return response()->json([
            'id' => $receipt->id,
            'status' => 'processing',
            'message' => 'Receipt uploaded successfully and is being processed.',
        ], 201);
    }

    public function show(Receipt $receipt)
    {
        Gate::authorize('view', $receipt);

        $receipt->load(['items']);

        return response()->json($receipt);
    }

    public function destroy(Receipt $receipt)
    {
        Gate::authorize('delete', $receipt);

        // Delete image file
        if (Storage::disk('public')->exists($receipt->image_path)) {
            Storage::disk('public')->delete($receipt->image_path);
        }

        $receipt->delete();

        return response()->json(['message' => 'Receipt deleted successfully']);
    }
}