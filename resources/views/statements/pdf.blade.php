<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #222; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .meta { color: #666; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd; }
        th { background: #f2f2f2; font-size: 11px; text-transform: uppercase; }
        td.amount { text-align: right; }
        .debit { color: #b23b32; }
        .credit { color: #2f7a4f; }
        .totals { margin-top: 16px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Whatspend Statement</h1>
    <div class="meta">{{ $user->name }} · {{ $start->format('d M Y') }} – {{ $end->format('d M Y') }}</div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Category</th>
                <th>Note</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($transactions as $t)
                <tr>
                    <td>{{ $t->transaction_date->format('d M') }}</td>
                    <td class="{{ $t->type }}">{{ ucfirst($t->type) }}</td>
                    <td>{{ $t->category }}</td>
                    <td>{{ $t->note }}</td>
                    <td class="amount {{ $t->type }}">Rs.{{ number_format($t->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        Total debit: Rs.{{ number_format($totalDebit, 2) }} &nbsp;&nbsp;
        Total credit: Rs.{{ number_format($totalCredit, 2) }} &nbsp;&nbsp;
        Net: Rs.{{ number_format($totalCredit - $totalDebit, 2) }}
    </div>
</body>
</html>