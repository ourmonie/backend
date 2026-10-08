<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * Filterable member statement.
     */
    public function index(Request $request): JsonResponse
    {
        $member = $request->user()->member;

        $query = Transaction::where('member_id', $member->id)
            ->orderByDesc('occurred_at');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->input('direction'));
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->input('to').' 23:59:59');
        }

        if ($request->filled('search')) {
            $term = '%'.$request->input('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('reference', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('counterparty', 'like', $term);
            });
        }

        $transactions = $query->paginate(min(50, max(5, (int) $request->input('per_page', 20))));

        return response()->json([
            'data' => collect($transactions->items())->map(fn (Transaction $t) => $this->present($t)),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
            'summary' => $this->summary($member->id),
        ]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $transaction = Transaction::where('member_id', $request->user()->member->id)
            ->where('reference', $reference)
            ->with('entries.ledgerAccount')
            ->firstOrFail();

        return response()->json([
            'transaction' => $this->present($transaction),
            'ledger' => $transaction->entries->map(fn ($e) => [
                'account' => $e->ledgerAccount?->name,
                'group' => $e->ledgerAccount?->group,
                'direction' => $e->direction,
                'amount' => (float) $e->amount,
                'memo' => $e->memo,
                'balance_after' => $e->balance_after !== null ? (float) $e->balance_after : null,
            ]),
        ]);
    }

    private function present(Transaction $t): array
    {
        return [
            'reference' => $t->reference,
            'type' => $t->type,
            'type_label' => $this->typeLabel($t->type),
            'direction' => $t->direction,
            'amount' => (float) $t->amount,
            'fee_amount' => (float) $t->fee_amount,
            'status' => $t->status,
            'channel' => $t->channel,
            'description' => $t->description,
            'counterparty' => $t->counterparty,
            'account_type' => $t->account?->type,
            'occurred_at' => optional($t->occurred_at)->toDateTimeString(),
            'occurred_label' => optional($t->occurred_at)->format('d M Y, h:i A'),
        ];
    }

    private function typeLabel(string $type): string
    {
        return ucwords(str_replace('_', ' ', $type));
    }

    private function summary(int $memberId): array
    {
        $completed = Transaction::where('member_id', $memberId)
            ->where('status', Transaction::STATUS_COMPLETED);

        $credits = (clone $completed)->where('direction', 'credit')->sum('amount');
        $debits = (clone $completed)->where('direction', 'debit')->sum('amount');

        return [
            'total_in' => round((float) $credits, 2),
            'total_out' => round((float) $debits, 2),
            'net' => round((float) $credits - (float) $debits, 2),
            'count' => (clone $completed)->count(),
        ];
    }
}
