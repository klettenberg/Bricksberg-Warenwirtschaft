<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * LWW_Demand_Analyzer
 *
 * Analyzes import history and returns basic demand metrics:
 * - total_imports
 * - avg_per_import
 * - last_import_count
 * - trend (increasing|decreasing|stable)
 * - forecast_next (integer)
 */
class LWW_Demand_Analyzer {

    /**
     * Analyze history array of ['time' => timestamp, 'count' => int]
     *
     * @param array $history
     * @param int $window Optional window size for moving averages
     * @return array
     */
    public static function analyze_from_history(array $history, int $window = 3) : array {
        $result = [
            'total_imports' => 0,
            'avg_per_import' => 0.0,
            'last_import_count' => 0,
            'trend' => 'stable',
            'forecast_next' => 0,
            'entries' => count($history),
        ];

        if (empty($history)) {
            return $result;
        }

        $counts = array_map(function ($entry) {
            return (int)$entry['count'];
        }, $history);

        $total = array_sum($counts);
        $countEntries = count($counts);
        $avg = $countEntries > 0 ? $total / $countEntries : 0.0;
        $last = end($counts) ?: 0;

        // Simple trend analysis: compare moving averages of two consecutive windows
        $window = max(1, $window);
        $last_window = array_slice($counts, -$window);
        $prev_window = array_slice($counts, -$window*2, $window);

        $avg_last_window = !empty($last_window) ? array_sum($last_window) / count($last_window) : 0;
        $avg_prev_window = !empty($prev_window) ? array_sum($prev_window) / count($prev_window) : 0;

        $threshold = max(1, $avg); // relative threshold (simple)
        if ($avg_last_window > $avg_prev_window + ($threshold * 0.1)) {
            $trend = 'increasing';
        } elseif ($avg_last_window < $avg_prev_window - ($threshold * 0.1)) {
            $trend = 'decreasing';
        } else {
            $trend = 'stable';
        }

        // Forecast: simple linear extrapolation by delta between avg windows
        $delta = $avg_last_window - $avg_prev_window;
        $forecast = (int) max(0, round($last + $delta));

        $result['total_imports'] = $total;
        $result['avg_per_import'] = round($avg, 2);
        $result['last_import_count'] = (int)$last;
        $result['trend'] = $trend;
        $result['forecast_next'] = $forecast;

        return $result;
    }

    /**
     * Helper to generate a short message summarizing the analysis (for UI).
     * Keep this stateless and safe.
     *
     * @param array $analysis
     * @return string
     */
    public static function summary_text(array $analysis) : string {
        $parts = [];
        $parts[] = sprintf(__('Inspektion: %d Einträge gesamt', 'lego-wawi'), (int)$analysis['total_imports']);
        $parts[] = sprintf(__('Durchschnitt pro Import: %s', 'lego-wawi'), esc_html((string)$analysis['avg_per_import']));
        $parts[] = sprintf(__('Letzter Import: %d Einträge', 'lego-wawi'), (int)$analysis['last_import_count']);
        $trend = isset($analysis['trend']) ? $analysis['trend'] : 'stable';
        $trendText = ($trend === 'increasing') ? __('steigend', 'lego-wawi') : (($trend === 'decreasing') ? __('fallend', 'lego-wawi') : __('stabil', 'lego-wawi'));
        $parts[] = sprintf(__('Trend: %s', 'lego-wawi'), $trendText);
        $parts[] = sprintf(__('Vorhersage nächster Import: %d', 'lego-wawi'), (int)$analysis['forecast_next']);

        return implode(' • ', $parts);
    }
}
