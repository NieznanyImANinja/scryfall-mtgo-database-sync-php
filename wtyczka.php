<?php
/**
 * Plugin Name: Scryfall MTGO Database Sync
 * Description: Synchronizes the MTGO card database with the Scryfall API.
 * Version: 1.0.0
 * Author: MTGO Database
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SMTGO_VERSION', '1.0.0');

function smtgo_table() {
    global $wpdb;
    return $wpdb->prefix . 'scryfall_mtgo_cards';
}

/**
 * Create the database table upon plugin activation.
 */
register_activation_hook(__FILE__, 'smtgo_activate');

function smtgo_activate() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = smtgo_table();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        scryfall_id char(36) NOT NULL,
        mtgo_id bigint(20) unsigned DEFAULT NULL,
        name varchar(255) NOT NULL,
        set_code varchar(20) DEFAULT NULL,
        collector_number varchar(50) DEFAULT NULL,
        rarity varchar(30) DEFAULT NULL,
        lang varchar(10) DEFAULT NULL,
        released_at date DEFAULT NULL,
        image_uri text DEFAULT NULL,
        prices longtext DEFAULT NULL,
        card_data longtext NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY scryfall_id (scryfall_id),
        KEY mtgo_id (mtgo_id),
        KEY card_name (name),
        KEY set_code (set_code)
    ) $charset;";

    dbDelta($sql);
}

/**
 * Register the plugin administration menu.
 */
add_action('admin_menu', function () {
    add_menu_page(
        'Scryfall MTGO',
        'Scryfall MTGO',
        'manage_options',
        'scryfall-mtgo',
        'smtgo_admin_page',
        'dashicons-database',
        80
    );
});

/**
 * Display the synchronization administration page.
 */
function smtgo_admin_page() {
    global $wpdb;

    $count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM " . smtgo_table()
    );

    $last_sync = get_option('smtgo_last_sync', 'Never synchronized');
    ?>
    <div class="wrap">
        <h1>Scryfall MTGO Database</h1>

        <div style="background:#fff;padding:25px;
                    max-width:700px;border:1px solid #ddd;
                    margin-top:20px;">

            <h2>MTGO Card Database</h2>

            <p>
                Total cards in the local database:
                <strong id="smtgo-count">
                    <?php echo esc_html($count); ?>
                </strong>
            </p>

            <p>
                Last synchronization:
                <strong>
                    <?php echo esc_html($last_sync); ?>
                </strong>
            </p>

            <hr>

            <button id="smtgo-start"
                    class="button button-primary button-hero">
                Update Scryfall Database
            </button>

            <div id="smtgo-status"
                 style="margin-top:20px;">
                Ready to synchronize.
            </div>

            <div style="background:#ddd;height:20px;
                        margin-top:15px;max-width:600px;">
                <div id="smtgo-progress"
                     style="background:#2271b1;
                            width:0%;height:20px;
                            transition:width .2s;">
                </div>
            </div>

            <p id="smtgo-details"></p>
        </div>
    </div>

    <script>
    jQuery(function($) {

        let running = false;
        let pages = 0;
        let imported = 0;
        let updated = 0;

        $('#smtgo-start').on('click', function() {

            if (running) return;

            if (!confirm(
                'Do you want to start synchronizing all MTGO cards?'
            )) return;

            running = true;
            pages = 0;
            imported = 0;
            updated = 0;

            $('#smtgo-start').prop('disabled', true);
            $('#smtgo-progress').css('width', '0%');
            $('#smtgo-status').text('Starting synchronization...');

            $.post(ajaxurl, {
                action: 'smtgo_start',
                nonce: '<?php echo esc_js(wp_create_nonce('smtgo_sync')); ?>'
            }).done(function(response) {

                if (!response.success) {
                    finish(response.data || 'Unable to start synchronization.');
                    return;
                }

                nextPage();

            }).fail(function() {
                finish('WordPress connection error.');
            });
        });

        function nextPage() {

            $.post(ajaxurl, {
                action: 'smtgo_step',
                nonce: '<?php echo esc_js(wp_create_nonce('smtgo_sync')); ?>'
            }).done(function(response) {

                if (!response.success) {
                    finish(response.data || 'Synchronization error.');
                    return;
                }

                const data = response.data;

                if (data.done) {
                    $('#smtgo-progress').css('width', '100%');

                    $('#smtgo-count').text(data.total);
                    finish('Synchronization completed successfully!');
                    return;
                }

                pages++;
                imported += data.inserted;
                updated += data.updated;

                $('#smtgo-status').text(
                    'Pages downloaded: ' + pages
                );

                $('#smtgo-details').text(
                    'New cards: ' + imported +
                    ' | Updated cards: ' + updated
                );

                // Display an approximate synchronization progress.
                const progress = Math.min(
                    95,
                    pages * 2
                );

                $('#smtgo-progress').css(
                    'width', progress + '%'
                );

                nextPage();

            }).fail(function() {
                finish(
                    'Communication error. You can retry synchronization.'
                );
            });
        }

        function finish(message) {
            running = false;
            $('#smtgo-start').prop('disabled', false);
            $('#smtgo-status').text(message);
        }
    });
    </script>
    <?php
}

/**
 * Verify the AJAX request nonce and administrator permissions.
 */
function smtgo_authorize() {
    check_ajax_referer('smtgo_sync', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions.');
    }
}

/**
 * Initialize a new synchronization process.
 */
add_action('wp_ajax_smtgo_start', 'smtgo_start');

function smtgo_start() {
    smtgo_authorize();

    update_option(
        'smtgo_next_page',
        'https://api.scryfall.com/cards/search?q=game%3Amtgo'
    );

    update_option('smtgo_sync_running', 1);

    wp_send_json_success([
        'message' => 'Synchronization started.'
    ]);
}

/**
 * Fetch and save one page of Scryfall search results.
 */
add_action('wp_ajax_smtgo_step', 'smtgo_step');

function smtgo_step() {
    global $wpdb;

    smtgo_authorize();

    if (!get_option('smtgo_sync_running')) {
        wp_send_json_error('Synchronization is not active.');
    }

    $url = get_option('smtgo_next_page');

    if (!$url) {
        update_option('smtgo_sync_running', 0);
        wp_send_json_success([
            'done' => true,
            'total' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . smtgo_table()
            )
        ]);
    }

    // Only allow requests to the official Scryfall API domain.
    $parts = wp_parse_url($url);

    if (
        empty($parts['scheme']) ||
        $parts['scheme'] !== 'https' ||
        empty($parts['host']) ||
        strtolower($parts['host']) !== 'api.scryfall.com'
    ) {
        update_option('smtgo_sync_running', 0);
        wp_send_json_error('Invalid API URL.');
    }

    $response = wp_remote_get($url, [
        'timeout' => 30,
        'redirection' => 0,
        'headers' => [
            'User-Agent' => 'WordPress-Scryfall-MTGO-Sync/1.0',
            'Accept' => 'application/json'
        ]
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }

    $status = wp_remote_retrieve_response_code($response);

    if ($status !== 200) {
        wp_send_json_error(
            'Scryfall returned HTTP ' . $status
        );
    }

    $body = json_decode(
        wp_remote_retrieve_body($response),
        true
    );

    if (
        !is_array($body) ||
        !isset($body['data']) ||
        !is_array($body['data'])
    ) {
        wp_send_json_error('Invalid Scryfall API response.');
    }

    $table = smtgo_table();
    $inserted = 0;
    $updated = 0;

    foreach ($body['data'] as $card) {

        if (empty($card['id']) || empty($card['name'])) {
            continue;
        }

        // Apply an additional MTGO game availability check.
        if (
            empty($card['games']) ||
            !in_array('mtgo', $card['games'], true)
        ) {
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
            $image = $card['image_uris']['normal'];
        } elseif (!empty($card['card_faces'][0]['image_uris']['normal'])) {
            $image = $card['card_faces'][0]['image_uris']['normal'];
        }

        $released = null;

        if (!empty($card['released_at'])) {
            $date = $card['released_at'];

            if (
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) &&
                checkdate(
                    (int) substr($date, 5, 2),
                    (int) substr($date, 8, 2),
                    (int) substr($date, 0, 4)
                )
            ) {
                $released = $date;
            }
        }

        $data = [
            'scryfall_id' => $id,
            'mtgo_id' => !empty($card['mtgo_id'])
                ? absint($card['mtgo_id']) : null,
            'name' => sanitize_text_field($card['name']),
            'set_code' => !empty($card['set'])
                ? sanitize_text_field($card['set']) : null,
            'collector_number' => !empty($card['collector_number'])
                ? sanitize_text_field($card['collector_number']) : null,
            'rarity' => !empty($card['rarity'])
                ? sanitize_text_field($card['rarity']) : null,
            'lang' => !empty($card['lang'])
                ? sanitize_text_field($card['lang']) : null,
            'released_at' => $released,
            'image_uri' => $image,
            'prices' => wp_json_encode(
                $card['prices'] ?? [],
                JSON_UNESCAPED_UNICODE
            ),
            'card_data' => wp_json_encode(
                $card,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'updated_at' => current_time('mysql', true)
        ];

        $formats = [
            '%s', '%d', '%s', '%s', '%s', '%s',
            '%s', '%s', '%s', '%s', '%s', '%s'
        ];

        $result = $wpdb->replace(
            $table,
            $data,
            $formats
        );

        if ($result === false) {
            wp_send_json_error(
                'Database error: ' . $wpdb->last_error
            );
        }

        if ($existing) {
            $updated++;
        } else {
            $inserted++;
        }
    }

    // Retrieve the URL of the next results page.
    $next = !empty($body['has_more'])
        ? ($body['next_page'] ?? '')
        : '';

    if ($next) {
        $next_parts = wp_parse_url($next);

        if (
            empty($next_parts['scheme']) ||
            $next_parts['scheme'] !== 'https' ||
            empty($next_parts['host']) ||
            strtolower($next_parts['host']) !== 'api.scryfall.com'
        ) {
            update_option('smtgo_sync_running', 0);
            wp_send_json_error('Invalid next page URL.');
        }

        update_option('smtgo_next_page', $next);
    } else {
        delete_option('smtgo_next_page');
        update_option('smtgo_sync_running', 0);
        update_option(
            'smtgo_last_sync',
            current_time('mysql')
        );
    }

    // Return synchronization results for the current page.
    wp_send_json_success([
        'done' => !$next,
        'inserted' => $inserted,
        'updated' => $updated,
        'total' => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table"
        )
    ]);
}