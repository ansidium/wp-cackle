<?php

function cackle_i($text, $params = null) {
    if (!is_array($params)) {
        $params = func_get_args();
        $params = array_slice($params, 1);
    }

    return vsprintf(__($text, 'cackle'), $params);
}

function cackle_normalize_comment_status($status) {
    switch ((string) $status) {
        case '1':
            return 'approved';
        case '0':
            return 'pending';
        case 'spam':
            return 'spam';
        case 'trash':
            return 'deleted';
        default:
            return 'pending';
    }
}

function cackle_send_error($message, $code = 400) {
    wp_send_json_error(
        array(
            'message' => $message,
        ),
        $code
    );
}

function cackle_decode_request_payload() {
    if (!isset($_POST['data'])) {
        cackle_send_error(__('Missing request data.', 'cackle'));
    }

    $raw = wp_unslash($_POST['data']);
    if (!is_string($raw)) {
        cackle_send_error(__('Invalid request payload.', 'cackle'));
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        cackle_send_error(__('Unable to decode request payload.', 'cackle'));
    }

    return $payload;
}

// Main AJAX entry point; validates permissions and CSRF token before delegating heavy work.
function cackle_handle_request() {
    if (!current_user_can('manage_options')) {
        cackle_send_error(__('You do not have permission to perform this action.', 'cackle'), 403);
    }

    // Protect long running operations against CSRF.
    check_ajax_referer('cackle_request', 'nonce');

    $payload = cackle_decode_request_payload();
    $action = isset($payload['cackleApi']) ? sanitize_text_field($payload['cackleApi']) : '';

    switch ($action) {
        case 'export':
            cackle_handle_export($payload);
            break;
        case 'import_prepare':
            cackle_handle_import_prepare($payload);
            break;
        case 'import':
            cackle_handle_import($payload);
            break;
        case 'checkKeys':
            cackle_handle_check_keys($payload);
            break;
        default:
            cackle_send_error(__('Unknown action requested.', 'cackle'));
    }
}

// Export WordPress comments to Cackle in manageable batches.
function cackle_handle_export(array $payload) {
    global $wpdb, $cackle_api;

    $offset = isset($payload['offset']) ? max(0, (int) $payload['offset']) : 0;
    $timestamp = isset($payload['timestamp']) ? (int) $payload['timestamp'] : time();
    $action = isset($payload['action']) ? sanitize_text_field($payload['action']) : '';
    $post_id = isset($payload['post_id']) ? max(0, (int) $payload['post_id']) : 0;

    $manual_export = get_option('cackle_manual_export', '');
    if (!is_object($manual_export)) {
        $manual_export = new stdClass();
        $manual_export->status = 'export';
    }

    if ('export_start' === $action && isset($manual_export->status) && 'stop' === $manual_export->status) {
        $manual_export->status = 'export';
        update_option('cackle_manual_export', $manual_export);
        wp_send_json(
            array(
                'result' => 'fail',
                'timestamp' => $timestamp,
                'status' => 'stopped',
                'post_id' => $post_id,
                'msg' => __('Export was previously stopped. Please try again.', 'cackle'),
                'eof' => 1,
                'response' => null,
            )
        );
    }

    $post = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->posts} WHERE post_type != %s AND post_status = %s AND comment_count > 0 AND ID > %d ORDER BY ID ASC LIMIT 1",
            'revision',
            'publish',
            $post_id
        )
    );

    if (!$post) {
        wp_send_json(
            array(
                'result' => 'fail',
                'timestamp' => $timestamp,
                'status' => 'complete',
                'post_id' => null,
                'eof' => 1,
                'response' => null,
                'debug' => __('No posts found for export.', 'cackle'),
            )
        );
    }

    $post_id = (int) $post->ID;
    $max_post_id = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type != 'revision' AND post_status = 'publish' AND comment_count > 0");
    $eof = (int) ($post_id === $max_post_id);

    $status = $eof ? 'complete' : 'partial';
    if (!$eof) {
        $manual_export->finish = false;
        update_option('cackle_manual_export', $manual_export);
    }

    $comments = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_agent NOT LIKE %s ORDER BY comment_date ASC LIMIT %d OFFSET %d",
            $post_id,
            $wpdb->esc_like('Cackle:') . '%',
            100,
            $offset
        )
    );

    $response = 'success';
    $comments_pack_status = 'complete';
    $comments_prepared = 0;
    $fail_response = null;
    $result = 'success';

    if ($comments) {
        $export_payload = array();
        foreach ($comments as $comment) {
            try {
                $created = new DateTime($comment->comment_date);
            } catch (Exception $exception) {
                // Fallback to the GMT value if the stored date string is malformed.
                $created = new DateTime('@' . strtotime($comment->comment_date_gmt));
            }
            $export_payload[] = array(
                'id' => (int) $comment->comment_ID,
                'ip' => $comment->comment_author_IP,
                'status' => cackle_normalize_comment_status($comment->comment_approved),
                'msg' => $comment->comment_content,
                'created' => (int) $created->getTimestamp() * 1000,
                'user' => ((int) $comment->user_id > 0) ? array(
                    'id' => (int) $comment->user_id,
                    'name' => $comment->comment_author,
                    'email' => $comment->comment_author_email,
                ) : null,
                'parent' => (int) $comment->comment_parent,
                'name' => ((int) $comment->user_id === 0) ? $comment->comment_author : null,
                'email' => ((int) $comment->user_id === 0) ? $comment->comment_author_email : null,
            );
        }

        $api_response = $cackle_api->import_wordpress_comments($export_payload, $post, (bool) $eof);
        $decoded_response = json_decode($api_response, true);
        $fail_response = $decoded_response;
        $response = (isset($decoded_response['responseApi']['status']) && 'ok' === $decoded_response['responseApi']['status']) ? 'success' : 'fail';
        $result = $response;
        $comments_pack_status = 'partial';
        $comments_prepared = count($export_payload);
    }

    if ('success' === $response) {
        if ($eof) {
            $manual_export->finish = true;
        }

        $manual_export->last_post_id = $post_id;
        $manual_export->last_offset = ($comments_pack_status === 'complete') ? 0 : $offset;
        update_option('cackle_manual_export', $manual_export);
    }

    wp_send_json(
        array(
            'result' => $result,
            'timestamp' => $timestamp,
            'status' => $status,
            'post_id' => $post_id,
            'eof' => $eof,
            'response' => $response,
            'debug' => null,
            'export' => 'export',
            'fail_response' => $fail_response,
            'comments_pack_status' => $comments_pack_status,
            'comments_prepared' => $comments_prepared,
        )
    );
}

// Prepare the local database before a bulk import from Cackle.
function cackle_handle_import_prepare(array $payload) {
    global $wpdb, $cackle_api;

    $offset = isset($payload['offset']) ? max(0, (int) $payload['offset']) : 0;
    $page = (int) floor($offset / 100);

    $manual_sync = get_option('cackle_manual_sync', '');
    if (!is_object($manual_sync)) {
        $manual_sync = new stdClass();
        $manual_sync->status = 'sync';
    }

    if (0 === $offset) {
        $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE meta_key IN ('cackle_post_id', 'cackle_parent_post_id')");
        $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_agent LIKE 'Cackle:%'");
        $wpdb->query("DELETE FROM {$wpdb->prefix}cackle_channel");
        delete_option('cackle_monitor');
        delete_option('cackle_monitor_short');
        delete_option('cackle_modified_trigger');
        delete_option('cackle_posts_update');
        delete_option('cackle_channel_modified_trigger');
    }

    if (!get_option('cackle_monitor')) {
        $object = new stdClass();
        $object->post_id = 0;
        $object->time = 0;
        $object->mode = 'by_channel';
        $object->status = 'finish';
        $object->counter = 0;
        update_option('cackle_monitor', $object);
    }

    if (!get_option('cackle_monitor_short')) {
        $object = new stdClass();
        $object->post_id = 0;
        $object->time = 0;
        $object->mode = 'by_channel';
        $object->status = 'finish';
        update_option('cackle_monitor_short', $object);
    }

    if (!get_option('cackle_modified_trigger')) {
        update_option('cackle_modified_trigger', new stdClass());
    }

    if (!get_option('cackle_posts_update')) {
        update_option('cackle_posts_update', new stdClass());
    }

    $raw_channels = $cackle_api->get_all_channels(100, $page);
    if (!$raw_channels) {
        cackle_send_error(__('Unable to fetch channel list from Cackle API.', 'cackle'));
    }

    $response = json_decode($raw_channels, true);
    $channels = (isset($response['chans']) && is_array($response['chans'])) ? $response['chans'] : array();

    $monitor = get_option('cackle_monitor');
    $monitor->post_id = 0;
    $monitor->status = 'inprocess';
    $monitor->mode = 'all_comments';
    $monitor->time = time();
    update_option('cackle_monitor', $monitor);

    if (!empty($channels)) {
        foreach ($channels as $chan) {
            $channel_id = isset($chan['channel']) ? (int) $chan['channel'] : 0;
            if (0 === $channel_id) {
                continue;
            }

            $post = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->posts} WHERE post_type != %s AND post_status = %s AND ID = %d ORDER BY ID ASC LIMIT 1",
                    'revision',
                    'publish',
                    $channel_id
                )
            );

            if (!$post) {
                continue;
            }

            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}cackle_channel WHERE id = %d ORDER BY ID ASC LIMIT 1",
                    $post->ID
                )
            );

            if (!$existing) {
                $wpdb->query(
                    $wpdb->prepare(
                        "INSERT INTO {$wpdb->prefix}cackle_channel (id, time) VALUES (%d, %d) ON DUPLICATE KEY UPDATE time = VALUES(time)",
                        $post->ID,
                        0
                    )
                );
            }
        }

        wp_send_json(
            array(
                'status' => 'partial',
                'channels_prepared' => count($channels),
            )
        );
    }

    wp_send_json(
        array(
            'status' => 'complete',
            'channels_prepared' => 0,
        )
    );
}

// Synchronise a single comment thread from Cackle back into WordPress.
function cackle_handle_import(array $payload) {
    global $wpdb, $cackle_api;

    $post_id = isset($payload['post_id']) ? max(0, (int) $payload['post_id']) : 0;
    $manual_sync = get_option('cackle_manual_sync', '');
    if (!is_object($manual_sync)) {
        $manual_sync = new stdClass();
        $manual_sync->status = 'sync';
    }

    if (0 === $post_id) {
        update_option('cackle_channel_modified_first', time() * 1000);
    }

    $post = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cackle_channel WHERE id > %d ORDER BY ID ASC LIMIT 1",
            $post_id
        )
    );

    if (!$post) {
        wp_send_json(
            array(
                'result' => 'success',
                'timestamp' => time(),
                'status' => 'complete',
                'post_id' => $post_id,
                'msg' => __('No channels available for synchronization.', 'cackle'),
                'eof' => 1,
                'response' => null,
            )
        );
    }

    $post_id = (int) $post->id;
    $max_post_id = (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}cackle_channel");
    $eof = (int) ($post_id === $max_post_id);
    $status = $eof ? 'complete' : 'partial';
    $msg = $eof ? __('Your comments have been synchronized with Cackle!', 'cackle') : cackle_i('Processed comments on post #%s&hellip;', $post_id);
    $manual_sync->finish = $eof;
    if (!$eof) {
        update_option('cackle_manual_sync', $manual_sync);
    }

    $sync = new Sync();
    $response = $sync->init($post_id, 'all_comments');
    $result = ('success' === $response) ? 'success' : 'fail';

    if ('success' === $result) {
        $manual_sync->last_post_id = $post_id;
        if ($eof) {
            $object = new stdClass();
            $object->mode = 'by_channel';
            $object->post_id = 0;
            $object->status = 'finish';
            $object->time = time();
            update_option('cackle_monitor', $object);
            update_option('cackle_manual_sync', $manual_sync);
        } else {
            $manual_sync->finish = false;
            update_option('cackle_manual_sync', $manual_sync);
        }
    } else {
        $msg = '<p class="status cackle-export-fail">' . cackle_i('Sorry, something  happened with the export. Please <a href="#" id="cackle_export_retry">try again</a></p><p>If your API key has changed, you may need to reinstall Cackle (deactivate the plugin and then reactivate it). If you are still having issues, refer to the <a href="%s" onclick="window.open(this.href); return false">WordPress help page</a>.', 'https://cackle.me/help/') . '</p>';
    }

    wp_send_json(
        array(
            'result' => $result,
            'timestamp' => time(),
            'status' => $status,
            'post_id' => $post_id,
            'msg' => $msg,
            'eof' => $eof,
            'response' => $response,
            'import' => 'import',
            'fail_response' => ('success' === $result) ? null : $response,
        )
    );
}

function cackle_handle_check_keys(array $payload) {
    if (!isset($payload['value']) || !is_array($payload['value'])) {
        cackle_send_error(__('Missing activation data.', 'cackle'));
    }

    require_once __DIR__ . '/cackle_activation.php';
    $resp = CackleActivation::check($payload['value']);
    wp_send_json($resp);
}
