<?php

namespace App\Services;

use App\Ai\Agents\NarrativeSummaryAgent;
use Illuminate\Support\Facades\Log;

class NarrativeSummaryService
{
    /**
     * Phrase an already-computed comparison result into a natural sentence.
     * Numbers are never generated here — only worded. Same rethrow contract
     * as TransactionParser/QueryIntentParser: a failure must be visible to
     * the caller, not silently swallowed.
     *
     * @throws \Throwable
     */
    public function narrate(array $comparison, ?string $categoryLabel = null): string
    {
        $payload = json_encode([
            'primary_total' => $comparison['primary']['total'],
            'primary_count' => $comparison['primary']['count'],
            'primary_top_category' => $this->topCategory($comparison['primary']['by_category']),
            'comparison_total' => $comparison['comparison']['total'],
            'comparison_count' => $comparison['comparison']['count'],
            'delta_amount' => $comparison['delta_amount'],
            'delta_percent' => $comparison['delta_percent'],
            'category_filter' => $categoryLabel,
        ]);

        try {
            $response = (new NarrativeSummaryAgent)->prompt($payload);
        } catch (\Throwable $e) {
            Log::warning('NarrativeSummaryService: LLM call failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $response['summary'];
    }

    protected function topCategory(array $byCategory): ?string
    {
        if (empty($byCategory)) {
            return null;
        }

        return array_key_first($byCategory) . ' (₹' . reset($byCategory) . ')';
    }
}