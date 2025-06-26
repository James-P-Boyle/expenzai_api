<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ExpenseController extends Controller
{
    public function weekly(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date',
        ]);

        $date = $request->date ? Carbon::parse($request->date) : Carbon::now();
        $weekStart = $date->startOfWeek();

        $receipts = $request->user()
            ->receipts()
            ->with(['items'])
            ->where('week_of', $weekStart->toDateString())
            ->where('status', 'completed')
            ->get();

        $totalAmount = $receipts->sum('total_amount');
        
        // Group by category
        $categories = $receipts->flatMap->items
            ->groupBy('category')
            ->map(function ($items, $category) {
                return [
                    'category' => $category,
                    'total' => $items->sum('price'),
                    'count' => $items->count(),
                    'uncertain_count' => $items->where('is_uncertain', true)->count(),
                ];
            })->values();

        return response()->json([
            'week_start' => $weekStart->toDateString(),
            'total_amount' => $totalAmount,
            'receipts_count' => $receipts->count(),
            'categories' => $categories,
            'receipts' => $receipts,
        ]);
    }

    public function summary(Request $request)
    {
        $user = $request->user();
        
        // Last 4 weeks summary
        $weeklySummary = collect(range(0, 3))->map(function ($weeksAgo) use ($user) {
            $weekStart = Carbon::now()->subWeeks($weeksAgo)->startOfWeek();
            $total = $user->receipts()
                ->where('week_of', $weekStart->toDateString())
                ->where('status', 'completed')
                ->sum('total_amount');
            
            return [
                'week' => $weekStart->toDateString(),
                'total' => $total,
            ];
        });

        return response()->json([
            'weekly_summary' => $weeklySummary,
        ]);
    }
}