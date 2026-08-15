<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class StatementGenerator
{
    /**
     * Generates a CSV statement for the given user and date range, writes it
     * to local disk, and returns the full filesystem path. Only confirmed
     * transactions are included — pending_review rows are excluded, same
     * rule TransactionQueryService applies.
     */
    public function generateCsv(User $user, Carbon $start, Carbon $end): string
    {
        $transactions = Transaction::where('user_id', $user->id)
            ->where('status', 'confirmed')
            ->whereBetween('transaction_date', [$start->startOfDay(), $end->endOfDay()])
            ->orderBy('transaction_date')
            ->get();

        $filename = 'statements/' . $user->id . '_' . $start->format('Y-m-d') . '_' . $end->format('Y-m-d') . '_' . uniqid() . '.csv';

        $disk = Storage::disk('local');
        $handle = fopen('php://temp', 'w+');

        fputcsv($handle, ['Date', 'Type', 'Category', 'Note', 'Amount', 'Source'], ',', '"', '\\');

        foreach ($transactions as $t) {
            fputcsv($handle, [
                $t->transaction_date->format('Y-m-d'),
                $t->type,
                $t->category ?? '',
                $t->note ?? '',
                number_format((float) $t->amount, 2, '.', ''),
                $t->source,
            ], ',', '"', '\\');
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        $disk->put($filename, $csvContent);

        return $disk->path($filename);
    }

    /**
     * Generates a PDF statement for the given user and date range, writes it
     * to local disk, and returns the full filesystem path. Same confirmed-only
     * filtering as generateCsv.
     */
    public function generatePdf(User $user, Carbon $start, Carbon $end): string
    {
        $transactions = Transaction::where('user_id', $user->id)
            ->where('status', 'confirmed')
            ->whereBetween('transaction_date', [$start->startOfDay(), $end->endOfDay()])
            ->orderBy('transaction_date')
            ->get();

        $totalDebit = $transactions->where('type', 'debit')->sum('amount');
        $totalCredit = $transactions->where('type', 'credit')->sum('amount');

        $pdf = Pdf::loadView('statements.pdf', [
            'user' => $user,
            'start' => $start,
            'end' => $end,
            'transactions' => $transactions,
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
        ]);

        $filename = 'statements/' . $user->id . '_' . $start->format('Y-m-d') . '_' . $end->format('Y-m-d') . '_' . uniqid() . '.pdf';

        $disk = Storage::disk('local');
        $disk->put($filename, $pdf->output());

        return $disk->path($filename);
    }
}