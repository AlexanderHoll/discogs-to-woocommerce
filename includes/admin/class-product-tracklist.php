<?php

defined('ABSPATH') || exit;

/**
 * "Discogs tracklist" box on the product edit screen: review the tracklist D2W saved from
 * the release, correct positions, titles and durations, remove or add tracks.
 *
 * Like the videos box, a tracklist saved here is marked as edited: later release fetches
 * keep it (and match videos against it) until "Use the Discogs tracklist again" is ticked.
 */
class D2W_Product_Tracklist {

    const META        = '_d2w_tracklist';
    const EDITED_META = '_d2w_tracklist_edited';
    const NONCE       = 'd2w_product_tracklist';
    const NEW_ROWS    = 2;

    public static function register() {
        add_action('add_meta_boxes_product', [__CLASS__, 'add_box']);
        // Before the videos box saves, so both read the same request.
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save'], 15);
    }

    public static function add_box(WP_Post $post) {
        if (!get_post_meta($post->ID, '_discogs_release_id', true) && !get_post_meta($post->ID, self::META, true)) {
            return;
        }
        add_meta_box('d2w-product-tracklist', 'Discogs tracklist', [__CLASS__, 'render'], 'product', 'normal', 'low');
    }

    public static function render(WP_Post $post) {
        $tracks = json_decode((string) get_post_meta($post->ID, self::META, true), true);
        $tracks = is_array($tracks) ? $tracks : [];
        $edited = (bool) get_post_meta($post->ID, self::EDITED_META, true);

        wp_nonce_field(self::NONCE, self::NONCE . '_nonce');
        ?>
        <p class="description">
            The tracklist shown on the product page. It comes from Discogs, which is user submitted, so check it.
            <?php echo $edited ? '<strong>This tracklist has been edited here, so release fetches no longer change it.</strong>' : 'Release fetches replace this list until it is saved here.'; ?>
        </p>
        <table class="widefat striped d2w-tracklist-table">
            <thead><tr><th style="width:12%">Position</th><th>Title</th><th style="width:12%">Duration</th><th style="width:8%">Remove</th></tr></thead>
            <tbody>
            <?php
            $rows = array_merge($tracks, array_fill(0, self::NEW_ROWS, ['position' => '', 'title' => '', 'duration' => '']));
            foreach ($rows as $i => $t) :
                $is_new = ($t['title'] ?? '') === '' && ($t['position'] ?? '') === '';
                ?>
                <tr>
                    <td><input type="text" class="widefat" name="d2w_tracklist[<?php echo (int) $i; ?>][position]" value="<?php echo esc_attr((string) ($t['position'] ?? '')); ?>" placeholder="<?php echo $is_new ? esc_attr('e.g. A1') : ''; ?>" /></td>
                    <td><input type="text" class="widefat" name="d2w_tracklist[<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr((string) ($t['title'] ?? '')); ?>" placeholder="<?php echo $is_new ? esc_attr('Add a track') : ''; ?>" /></td>
                    <td><input type="text" class="widefat" name="d2w_tracklist[<?php echo (int) $i; ?>][duration]" value="<?php echo esc_attr((string) ($t['duration'] ?? '')); ?>" placeholder="<?php echo $is_new ? esc_attr('e.g. 5:23') : ''; ?>" /></td>
                    <td><?php if (!$is_new) : ?><label><input type="checkbox" name="d2w_tracklist[<?php echo (int) $i; ?>][remove]" value="1" /> <span class="screen-reader-text">Remove this track</span></label><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($edited) : ?>
            <p><label><input type="checkbox" name="d2w_tracklist_reset" value="1" /> Use the Discogs tracklist again (the next release fetch replaces this list)</label></p>
        <?php endif; ?>
        <?php
    }

    public static function save(int $post_id) {
        if (!isset($_POST[self::NONCE . '_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE . '_nonce'])), self::NONCE)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (!empty($_POST['d2w_tracklist_reset'])) {
            delete_post_meta($post_id, self::EDITED_META);
            return;
        }

        $rows = isset($_POST['d2w_tracklist']) && is_array($_POST['d2w_tracklist']) ? wp_unslash($_POST['d2w_tracklist']) : [];
        $tracks = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !empty($row['remove'])) {
                continue;
            }
            $track = [
                'position' => sanitize_text_field((string) ($row['position'] ?? '')),
                'title'    => sanitize_text_field((string) ($row['title'] ?? '')),
                'duration' => sanitize_text_field((string) ($row['duration'] ?? '')),
            ];
            if ($track['title'] === '') {
                continue;
            }
            $tracks[] = $track;
        }

        $current = json_decode((string) get_post_meta($post_id, self::META, true), true);
        if (is_array($current) && $current == $tracks) {
            return; // Nothing changed: keep following Discogs.
        }
        update_post_meta($post_id, self::META, wp_slash(wp_json_encode($tracks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        update_post_meta($post_id, self::EDITED_META, time());
    }
}
