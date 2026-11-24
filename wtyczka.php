
/**
 * Retrieve Scryfall bulk data metadata.
 */
function smtgo_get_bulk_metadata() {

    $response = wp_remote_get(
        'https://api.scryfall.com/bulk-data',
        [
            'timeout' => 60,
            'headers' => [
                'User-Agent' => 'WordPress-Scryfall-MTGO-Sync/2.0',
                'Accept' => 'application/json'
            ]
        ]
    );

    if (is_wp_error($response)) {
        return $response;
    }

    if (wp_remote_retrieve_response_code($response) !== 200) {
        return new WP_Error(
            'scryfall_bulk_error',
            'Unable to retrieve Scryfall bulk metadata.'
        );
    }

    $result = json_decode(
        wp_remote_retrieve_body($response),
        true
    );

    if (empty($result['data'])) {
        return new WP_Error(
            'invalid_bulk_data',
            'Invalid Scryfall bulk metadata.'
        );
    }

    foreach ($result['data'] as $bulk) {
        if (($bulk['type'] ?? '') === 'default_cards') {
            return $bulk;
        }
    }

    return new WP_Error(
        'bulk_not_found',
        'Default Cards export was not found.'
    );
}

/**
 * Download and import the Scryfall JSON export.
 */
function smtgo_download_bulk() {

    if (!current_user_can('manage_options')) {
        return new WP_Error('permission_error', 'Access denied.');
    }

    $metadata = smtgo_get_bulk_metadata();

    if (is_wp_error($metadata)) {
        return $metadata;
    }

    $download_url = $metadata['download_uri'] ?? '';

    if (!$download_url) {
        return new WP_Error(
            'missing_download_url',
            'Scryfall download URL is missing.'
        );
    }

    $parts = wp_parse_url($download_url);

    if (
        ($parts['scheme'] ?? '') !== 'https' ||
        strtolower($parts['host'] ?? '') !== 'data.scryfall.io'
    ) {
        return new WP_Error(
            'invalid_download_url',
            'Invalid Scryfall download URL.'
        );
    }

    $file = download_url($download_url, 300);

    if (is_wp_error($file)) {
        return $file;
    }

    return [
        'file' => $file,
        'metadata' => $metadata
    ];
}

/**
 * Import cards from a downloaded JSON export.
 */
function smtgo_import_bulk_json($file) {

    global $wpdb;

    $table = smtgo_table();

    // JSON decoding requires sufficient PHP memory.
    $json = file_get_contents($file);

    if ($json === false) {
        return new WP_Error(
            'file_read_error',
            'Unable to read the downloaded JSON file.'
        );
    }

    $cards = json_decode($json, true);

    unset($json);

    if (!is_array($cards)) {
        return new WP_Error(
            'json_decode_error',
            'Invalid JSON export.'
        );
    }

    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($cards as $card) {

        // Import only cards available on MTGO.
        if (
            empty($card['games']) ||
            !in_array('mtgo', $card['games'], true)
        ) {
            $skipped++;
            continue;
        }

        if (empty($card['id']) || empty($card['name'])) {
            continue;
        }

        $id = sanitize_text_field($card['id']);

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE scryfall_id = %s",
                $id
            )
        );

        $image = '';

        if (!empty($card['image_uris']['normal'])) {
            $image = esc_url_raw(
                $card['image_uris']['normal']
            );
        } elseif (!empty($card['card_faces'][0]['image_uris']['normal'])) {
            $image = esc_url_raw(
                $card['card_faces'][0]['image_uris']['normal']
            );
        }

        $released = null;

        if (
            !empty($card['released_at']) &&
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $card['released_at'])
        ) {
            $released = $card['released_at'];
        }

        $data = [
            'scryfall_id' => $id,
            'mtgo_id' => !empty($card['mtgo_id'])
                ? absint($card['mtgo_id']) : null,
            'name' => sanitize_text_field($card['name']),
            'set_code' => sanitize_text_field($card['set'] ?? ''),
            'collector_number' => sanitize_text_field(
                $card['collector_number'] ?? ''
            ),
            'rarity' => sanitize_text_field($card['rarity'] ?? ''),
            'lang' => sanitize_text_field($card['lang'] ?? ''),
            'released_at' => $released,
            'image_uri' => $image,
            'prices' => wp_json_encode($card['prices'] ?? []),
            'card_data' => wp_json_encode(
                $card,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'updated_at' => current_time('mysql', true)
        ];

        $result = $wpdb->replace(
            $table,
            $data
        );

        if ($result === false) {
            return new WP_Error(
                'database_error',
                $wpdb->last_error
            );
        }

        if ($existing) {
            $updated++;
        } else {
            $inserted++;
        }
    }

    return [
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
        'total' => $inserted + $updated
    ];
}

/**
 * Start bulk JSON synchronization.
 */
add_action('wp_ajax_smtgo_start', 'smtgo_start');

function smtgo_start() {

    check_ajax_referer('smtgo_sync', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions.');
    }

    $download = smtgo_download_bulk();

    if (is_wp_error($download)) {
        wp_send_json_error($download->get_error_message());
    }

    $result = smtgo_import_bulk_json($download['file']);

    @unlink($download['file']);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    update_option(
        'smtgo_last_sync',
        current_time('mysql')
    );

    update_option(
        'smtgo_bulk_updated_at',
        sanitize_text_field(
            $download['metadata']['updated_at'] ?? ''
        )
    );

    wp_send_json_success($result);
}
