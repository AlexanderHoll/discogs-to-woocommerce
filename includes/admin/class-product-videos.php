<?php

defined('ABSPATH') || exit;

/**
 * "Discogs videos" box on the product edit screen: review the videos D2W saved from the
 * release, change which track each one belongs to, remove or add videos.
 *
 * Discogs video data is user submitted, so matches are not always right. Once saved here,
 * a product's videos are marked as edited and later release fetches leave them alone,
 * until "Use the Discogs videos again" is ticked.
 */
class D2W_Product_Videos {

    const META        = '_d2w_videos';
    const EDITED_META = '_d2w_videos_edited';
    const NONCE       = 'd2w_product_videos';
    const NEW_ROWS    = 2;

    public static function register() {
        add_action('add_meta_boxes_product', [__CLASS__, 'add_box']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save'], 20);
    }

    public static function add_box(WP_Post $post) {
        if (!get_post_meta($post->ID, '_discogs_release_id', true) && !get_post_meta($post->ID, self::META, true)) {
            return;
        }
        add_meta_box('d2w-product-videos', 'Discogs videos', [__CLASS__, 'render'], 'product', 'normal', 'low');
    }

    // YouTube video ID from a URL or a bare ID; '' if it is not one.
    public static function video_id(string $value): string {
        $value = trim($value);
        if (preg_match('/^[\w-]{11}$/', $value)) {
            return $value;
        }
        return preg_match('#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/|youtube-nocookie\.com/embed/)([\w-]{11})#', $value, $m) ? $m[1] : '';
    }

    public static function render(WP_Post $post) {
        $videos = json_decode((string) get_post_meta($post->ID, self::META, true), true);
        $videos = is_array($videos) ? $videos : [];
        $tracks = json_decode((string) get_post_meta($post->ID, '_d2w_tracklist', true), true);
        $tracks = is_array($tracks) ? $tracks : [];
        $edited = (bool) get_post_meta($post->ID, self::EDITED_META, true);

        wp_nonce_field(self::NONCE, self::NONCE . '_nonce');
        ?>
        <p class="description">
            Each video can be linked to a track, so the product page plays the right video when a track is chosen.
            Matches come from Discogs and are user submitted, so check them.
            <?php echo $edited ? '<strong>These videos have been edited here, so release fetches no longer change them.</strong>' : 'Release fetches replace this list until it is saved here.'; ?>
        </p>
        <table class="widefat striped d2w-videos-table">
            <thead><tr><th style="width:30%">YouTube link or ID</th><th>Title</th><th style="width:22%">Track</th><th style="width:8%">Remove</th></tr></thead>
            <tbody>
            <?php
            $rows = array_merge($videos, array_fill(0, self::NEW_ROWS, ['id' => '', 'title' => '', 'track' => '', 'duration' => 0]));
            foreach ($rows as $i => $v) :
                $id = (string) ($v['id'] ?? '');
                ?>
                <tr>
                    <td>
                        <input type="text" class="widefat" name="d2w_videos[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr($id ? 'https://www.youtube.com/watch?v=' . $id : ''); ?>" placeholder="<?php echo $id ? '' : esc_attr('Add a video: paste a YouTube link'); ?>" />
                        <input type="hidden" name="d2w_videos[<?php echo (int) $i; ?>][duration]" value="<?php echo (int) ($v['duration'] ?? 0); ?>" />
                    </td>
                    <td><input type="text" class="widefat" name="d2w_videos[<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr((string) ($v['title'] ?? '')); ?>" /></td>
                    <td>
                        <select class="widefat" name="d2w_videos[<?php echo (int) $i; ?>][track]">
                            <option value="">Not linked to a track</option>
                            <?php foreach ($tracks as $t) :
                                $position = (string) ($t['position'] ?? '');
                                ?>
                                <option value="<?php echo esc_attr($position); ?>" <?php selected($position !== '' && $position === (string) ($v['track'] ?? '')); ?>><?php echo esc_html(trim($position . ' ' . ($t['title'] ?? ''))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><?php if ($id) : ?><label><input type="checkbox" name="d2w_videos[<?php echo (int) $i; ?>][remove]" value="1" /> <span class="screen-reader-text">Remove this video</span></label><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($edited) : ?>
            <p><label><input type="checkbox" name="d2w_videos_reset" value="1" /> Use the Discogs videos again (the next release fetch replaces this list)</label></p>
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

        if (!empty($_POST['d2w_videos_reset'])) {
            delete_post_meta($post_id, self::EDITED_META);
            return;
        }

        $rows = isset($_POST['d2w_videos']) && is_array($_POST['d2w_videos']) ? wp_unslash($_POST['d2w_videos']) : [];
        $videos = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !empty($row['remove'])) {
                continue;
            }
            $id = self::video_id((string) ($row['id'] ?? ''));
            if ($id === '' || isset($videos[$id])) {
                continue;
            }
            $videos[$id] = [
                'id'       => $id,
                'title'    => sanitize_text_field((string) ($row['title'] ?? '')),
                'duration' => absint($row['duration'] ?? 0),
                'track'    => sanitize_text_field((string) ($row['track'] ?? '')),
            ];
        }

        $current = json_decode((string) get_post_meta($post_id, self::META, true), true);
        $new = array_values($videos);
        if (is_array($current) && $current == $new) {
            return; // Nothing changed: keep following Discogs.
        }
        update_post_meta($post_id, self::META, wp_slash(wp_json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        update_post_meta($post_id, self::EDITED_META, time());
    }
}
