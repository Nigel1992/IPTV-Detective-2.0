<?php
// update_matches.php - Script to update matched status for all providers based on similarity
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

try {
    $pdo = get_db();

    // Get all providers
    $stmt = $pdo->query('SELECT id, name, link, price, md5, live_streams, live_categories, series, series_categories, vod_categories FROM providers WHERE is_public = 1 ORDER BY id');
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Updating matches for " . count($providers) . " providers...\n";

    foreach ($providers as $provider) {
        $id = $provider['id'];
        $hash = $provider['md5'];
        $live_streams_count = intval($provider['live_streams']);
        $live_categories_count = intval($provider['live_categories']);
        $series_count = intval($provider['series']);
        $series_categories_count = intval($provider['series_categories']);
        $vod_categories_count = intval($provider['vod_categories']);

        $bestSim = 0.0;
        $bestRow = null;

        // Check for exact MD5 match first
        if (!empty($hash)) {
            $stmt = $pdo->prepare('SELECT id, name, price FROM providers WHERE md5 = ? AND id != ? LIMIT 1');
            $stmt->execute([$hash, $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $bestSim = 100.0;
                $bestRow = $row;
            }
        }

        // If not exact MD5, check exact field matches
        if ($bestSim === 0) {
            foreach ($providers as $candidate) {
                if ($candidate['id'] == $id) continue;

                $exact = true;
                $fieldMatches = [
                    'live_categories' => isset($provider['live_categories']) && isset($candidate['live_categories']) && $provider['live_categories'] == $candidate['live_categories'],
                    'live_streams' => isset($provider['live_streams']) && isset($candidate['live_streams']) && $provider['live_streams'] == $candidate['live_streams'],
                    'series' => isset($provider['series']) && isset($candidate['series']) && $provider['series'] == $candidate['series'],
                    'series_categories' => isset($provider['series_categories']) && isset($candidate['series_categories']) && $provider['series_categories'] == $candidate['series_categories'],
                    'vod_categories' => isset($provider['vod_categories']) && isset($candidate['vod_categories']) && $provider['vod_categories'] == $candidate['vod_categories']
                ];

                // All fields must match exactly
                if (!($fieldMatches['live_categories'] && $fieldMatches['live_streams'] && $fieldMatches['series'] && $fieldMatches['series_categories'] && $fieldMatches['vod_categories'])) {
                    $exact = false;
                }

                if ($exact) {
                    $bestSim = 100.0;
                    $bestRow = $candidate;
                    break;
                }
            }
        }

        // If no exact match, check for partial exact matches
        if ($bestSim === 0) {
            foreach ($providers as $candidate) {
                if ($candidate['id'] == $id) continue;

                $fieldMatches = [
                    'live_categories' => isset($provider['live_categories']) && isset($candidate['live_categories']) && $provider['live_categories'] == $candidate['live_categories'],
                    'live_streams' => isset($provider['live_streams']) && isset($candidate['live_streams']) && $provider['live_streams'] == $candidate['live_streams'],
                    'series' => isset($provider['series']) && isset($candidate['series']) && $provider['series'] == $candidate['series'],
                    'series_categories' => isset($provider['series_categories']) && isset($candidate['series_categories']) && $provider['series_categories'] == $candidate['series_categories'],
                    'vod_categories' => isset($provider['vod_categories']) && isset($candidate['vod_categories']) && $provider['vod_categories'] == $candidate['vod_categories']
                ];

                // Count how many fields match
                $matchingFields = 0;
                $totalFields = 5; // live_categories, live_streams, series, series_categories, vod_categories
                foreach ($fieldMatches as $matches) {
                    if ($matches) $matchingFields++;
                }

                // If all fields match, it's 100%, otherwise 0%
                $overall = ($matchingFields == $totalFields) ? 100.0 : 0.0;

                if ($overall > $bestSim) {
                    $bestSim = $overall;
                    $bestRow = $candidate;
                }
            }
        }

        $matched = ($bestSim >= 100.0) ? 1 : 0;
        $match_name = $bestRow ? $bestRow['name'] : null;
        $match_price = $bestRow ? $bestRow['price'] : null;

        // Update the provider
        $stmt = $pdo->prepare('UPDATE providers SET matched = ?, match_name = ?, match_price = ?, similarity_score = ? WHERE id = ?');
        $stmt->execute([$matched, $match_name, $match_price, round($bestSim, 2), $id]);

        echo "Updated provider $id: matched=$matched, sim=$bestSim\n";
    }

    echo "Done.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>