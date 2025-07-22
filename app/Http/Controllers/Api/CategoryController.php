<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = $request->user()
            ->receipts()
            ->join('receipt_items', 'receipts.id', '=', 'receipt_items.receipt_id')
            ->select([
                'receipt_items.category',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(receipt_items.price) as total'),
                DB::raw('AVG(receipt_items.price) as avgPrice'),
                DB::raw('MAX(receipts.receipt_date) as lastPurchase')
            ])
            ->where('receipts.status', 'completed')
            ->whereNotNull('receipt_items.category')
            ->groupBy('receipt_items.category')
            ->orderBy('total', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'category' => $item->category,
                    'count' => (int) $item->count,
                    'total' => (float) $item->total,
                    'avgPrice' => (float) $item->avgPrice,
                    'lastPurchase' => $item->lastPurchase,
                ];
            });

        return response()->json([
            'data' => $categories
        ]);
    }

    public function weekly(Request $request)
    {
        $startOfWeek = Carbon::now()->startOfWeek();
        
        $categories = $request->user()
            ->receipts()
            ->join('receipt_items', 'receipts.id', '=', 'receipt_items.receipt_id')
            ->select([
                'receipt_items.category',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(receipt_items.price) as total'),
                DB::raw('AVG(receipt_items.price) as avgPrice')
            ])
            ->where('receipts.status', 'completed')
            ->where('receipts.week_of', '>=', $startOfWeek)
            ->whereNotNull('receipt_items.category')
            ->groupBy('receipt_items.category')
            ->orderBy('total', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'category' => $item->category,
                    'count' => (int) $item->count,
                    'total' => (float) $item->total,
                    'avgPrice' => (float) $item->avgPrice,
                ];
            });

        return response()->json([
            'data' => $categories
        ]);
    }

    public function show(Request $request, $category)
    {
        // Decode the category name from URL
        $categoryName = urldecode($category);
        
        $receipts = $request->user()
            ->receipts()
            ->whereHas('items', function ($query) use ($categoryName) {
                $query->where('category', $categoryName);
            })
            ->with(['items' => function ($query) use ($categoryName) {
                $query->where('category', $categoryName);
            }])
            ->where('status', 'completed')
            ->orderBy('receipt_date', 'desc')
            ->get();

        // Calculate category totals
        $totalSpent = $receipts->flatMap->items->sum('price');
        $totalItems = $receipts->flatMap->items->count();
        $avgPrice = $totalItems > 0 ? $totalSpent / $totalItems : 0;

        return response()->json([
            'data' => [
                'category' => $categoryName,
                'receipts' => $receipts,
                'summary' => [
                    'total_spent' => (float) $totalSpent,
                    'total_items' => $totalItems,
                    'avg_price' => (float) $avgPrice,
                    'receipt_count' => $receipts->count()
                ]
            ]
        ]);
    }
}