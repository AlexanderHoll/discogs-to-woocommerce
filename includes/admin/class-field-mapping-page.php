<?php

class D2W_Field_Mapping_Page {

    const OPTION_KEY = 'd2w_field_mapping';

    public static function register() {
        add_action('admin_post_d2w_save_field_mapping', [__CLASS__, 'handle_save']);
        add_action('admin_init', [__CLASS__, 'maybe_reset']);
    }

    public static function maybe_reset() {
        if (empty($_GET['d2w_reset']) || (($_GET['page'] ?? '') !== 'd2w-field-mapping')) {
            return;
        }
        check_admin_referer('d2w_reset_mapping');
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        delete_option(self::OPTION_KEY);
        wp_redirect(admin_url('admin.php?page=d2w-field-mapping&d2w_saved=1'));
        exit;
    }

    public static function get_discogs_fields() {
        return [
            'artist'           => 'Artist',
            'title'            => 'Release Title',
            'description'      => 'Release Description',
            'comments'         => 'Seller Comments',
            'value'            => 'Price',
            'currency'         => 'Currency',
            'media_condition'  => 'Media Condition',
            'sleeve_condition' => 'Sleeve Condition',
            'format'           => 'Format',
            'year'             => 'Release Year',
            'image_main'       => 'Main Image',
        ];
    }

    public static function get_wc_fields() {
        return [
            'post_title'     => ['label' => 'Product Name',     'supports_template' => true],
            'post_content'   => ['label' => 'Description'],
            'post_excerpt'   => ['label' => 'Short Description'],
            '_price'         => ['label' => 'Regular Price'],
            '_sale_price'    => ['label' => 'Sale Price'],
            '_sku'           => ['label' => 'SKU'],
            'featured_image' => ['label' => 'Featured Image',   'image_only' => true],
        ];
    }

    public static function get_defaults() {
        return [
            'post_title'     => ['discogs_field' => '',          'template' => '{artist} - {title}'],
            'post_content'   => ['discogs_field' => 'description'],
            'post_excerpt'   => ['discogs_field' => 'comments'],
            '_price'         => ['discogs_field' => 'value'],
            '_sale_price'    => ['discogs_field' => ''],
            '_sku'           => ['discogs_field' => ''],
            'featured_image' => ['discogs_field' => 'image_main'],
            'custom_meta'    => [
                ['meta_key' => '_media_condition',  'discogs_field' => 'media_condition'],
                ['meta_key' => '_sleeve_condition', 'discogs_field' => 'sleeve_condition'],
                ['meta_key' => '_format',           'discogs_field' => 'format'],
                ['meta_key' => '_year',             'discogs_field' => 'year'],
            ],
        ];
    }

    public static function get_mapping() {
        $saved = get_option(self::OPTION_KEY);
        return ($saved !== false) ? $saved : self::get_defaults();
    }

    public static function render_template($template, $product) {
        foreach (array_keys(self::get_discogs_fields()) as $key) {
            $template = str_replace('{' . $key . '}', $product[$key] ?? '', $template);
        }
        return trim($template);
    }

    public static function handle_save() {
        if (!current_user_can('manage_options') || !check_admin_referer('d2w_save_field_mapping')) {
            wp_die('Unauthorized');
        }

        $wc_fields    = self::get_wc_fields();
        $discogs_keys = array_keys(self::get_discogs_fields());
        $mapping      = [];
        $raw          = $_POST['mapping'] ?? [];

        foreach ($wc_fields as $wc_key => $wc_meta) {
            $df  = sanitize_key($raw[$wc_key]['discogs_field'] ?? '');
            $row = ['discogs_field' => in_array($df, $discogs_keys, true) ? $df : ''];

            if (!empty($wc_meta['supports_template'])) {
                $row['template'] = sanitize_text_field($raw[$wc_key]['template'] ?? '');
            }

            $mapping[$wc_key] = $row;
        }

        $custom_meta       = [];
        $raw_meta_keys     = array_values((array) ($raw['custom_meta']['meta_key']     ?? []));
        $raw_meta_fields   = array_values((array) ($raw['custom_meta']['discogs_field'] ?? []));

        foreach ($raw_meta_keys as $i => $meta_key) {
            $mk = sanitize_key($meta_key);
            $df = sanitize_key($raw_meta_fields[$i] ?? '');
            if (!empty($mk)) {
                $custom_meta[] = [
                    'meta_key'     => $mk,
                    'discogs_field' => in_array($df, $discogs_keys, true) ? $df : '',
                ];
            }
        }

        $mapping['custom_meta'] = $custom_meta;
        update_option(self::OPTION_KEY, $mapping);

        wp_redirect(admin_url('admin.php?page=d2w-field-mapping&d2w_saved=1'));
        exit;
    }

    public static function render_page() {
        $mapping        = self::get_mapping();
        $discogs_fields = self::get_discogs_fields();
        $wc_fields      = self::get_wc_fields();
        $custom_meta    = $mapping['custom_meta'] ?? [];

        $token_list = implode(' ', array_map(
            fn($k) => '<code>{' . esc_html($k) . '}</code>',
            array_keys($discogs_fields)
        ));
        ?>
        <div class="wrap d2w-mapping-wrap">
            <h1>Field Mapping</h1>
            <p class="description">Drag Discogs fields onto WooCommerce slots to control how products are imported. Changes apply to future imports only.</p>

            <?php if (isset($_GET['d2w_saved'])): ?>
                <div class="notice notice-success is-dismissible"><p>Field mapping saved.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="d2w-mapping-form">
                <input type="hidden" name="action" value="d2w_save_field_mapping" />
                <?php wp_nonce_field('d2w_save_field_mapping'); ?>

                <div class="d2w-mapping-layout">

                    <div class="d2w-mapping-sources">
                        <h3>Discogs Fields</h3>
                        <p class="d2w-mapping-hint">Drag onto a WooCommerce field</p>
                        <ul class="d2w-source-list" id="d2w-source-list">
                            <?php foreach ($discogs_fields as $key => $label): ?>
                                <li class="d2w-source-chip <?php echo $key === 'image_main' ? 'd2w-source-chip--image' : ''; ?>"
                                    draggable="true"
                                    data-discogs-field="<?php echo esc_attr($key); ?>"
                                    data-label="<?php echo esc_attr($label); ?>">
                                    <?php echo esc_html($label); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="d2w-mapping-targets">
                        <h3>WooCommerce Fields</h3>

                        <table class="d2w-mapping-table widefat striped">
                            <thead>
                                <tr>
                                    <th class="d2w-col-wc">WooCommerce Field</th>
                                    <th class="d2w-col-df">Mapped Discogs Field</th>
                                </tr>
                            </thead>
                            <tbody id="d2w-mapping-tbody">

                                <?php foreach ($wc_fields as $wc_key => $wc_meta):
                                    $current_map = $mapping[$wc_key] ?? [];
                                    $df          = $current_map['discogs_field'] ?? '';
                                    $df_label    = $discogs_fields[$df] ?? '';
                                    $is_tpl      = !empty($wc_meta['supports_template']);
                                    $tpl_val     = $current_map['template'] ?? '';
                                ?>
                                <tr class="d2w-target-row">
                                    <td class="d2w-target-label">
                                        <strong><?php echo esc_html($wc_meta['label']); ?></strong>
                                        <code class="d2w-wc-key"><?php echo esc_html($wc_key); ?></code>
                                    </td>
                                    <td class="d2w-target-slot-cell">

                                        <?php if ($is_tpl): ?>
                                            <div class="d2w-template-row">
                                                <label class="d2w-template-label">Title template</label>
                                                <input type="text"
                                                       name="mapping[<?php echo esc_attr($wc_key); ?>][template]"
                                                       value="<?php echo esc_attr($tpl_val); ?>"
                                                       class="d2w-template-input regular-text"
                                                       placeholder="{artist} - {title}" />
                                                <p class="description d2w-token-hint">
                                                    Tokens: <?php echo $token_list; ?>
                                                </p>
                                            </div>
                                            <div class="d2w-or-divider">— or map a single field —</div>
                                        <?php endif; ?>

                                        <div class="d2w-drop-area <?php echo $df ? 'has-field' : ''; ?>"
                                             data-wc-field="<?php echo esc_attr($wc_key); ?>">
                                            <?php if ($df): ?>
                                                <span class="d2w-assigned-chip">
                                                    <?php echo esc_html($df_label); ?>
                                                    <button type="button" class="d2w-clear-btn" aria-label="Remove">&times;</button>
                                                </span>
                                                <input type="hidden"
                                                       name="mapping[<?php echo esc_attr($wc_key); ?>][discogs_field]"
                                                       value="<?php echo esc_attr($df); ?>"
                                                       class="d2w-field-input" />
                                            <?php else: ?>
                                                <span class="d2w-drop-hint">Drop field here</span>
                                                <input type="hidden"
                                                       name="mapping[<?php echo esc_attr($wc_key); ?>][discogs_field]"
                                                       value=""
                                                       class="d2w-field-input" />
                                            <?php endif; ?>
                                        </div>

                                    </td>
                                </tr>
                                <?php endforeach; ?>

                                <tr class="d2w-meta-section-header">
                                    <th colspan="2">
                                        Custom Meta Fields
                                        <span class="d2w-meta-section-desc">Saved as <code>post_meta</code> on the WooCommerce product</span>
                                    </th>
                                </tr>

                                <?php foreach ($custom_meta as $i => $custom):
                                    $mk  = $custom['meta_key']     ?? '';
                                    $cdf = $custom['discogs_field'] ?? '';
                                    $cdl = $discogs_fields[$cdf]   ?? '';
                                ?>
                                <tr class="d2w-target-row d2w-custom-meta-row">
                                    <td class="d2w-target-label">
                                        <input type="text"
                                               name="mapping[custom_meta][meta_key][]"
                                               value="<?php echo esc_attr($mk); ?>"
                                               placeholder="e.g. _media_condition"
                                               class="d2w-meta-key-input" />
                                    </td>
                                    <td class="d2w-target-slot-cell d2w-custom-meta-slot">
                                        <div class="d2w-drop-area <?php echo $cdf ? 'has-field' : ''; ?>"
                                             data-wc-field="custom_meta_<?php echo esc_attr($i); ?>">
                                            <?php if ($cdf): ?>
                                                <span class="d2w-assigned-chip">
                                                    <?php echo esc_html($cdl); ?>
                                                    <button type="button" class="d2w-clear-btn" aria-label="Remove">&times;</button>
                                                </span>
                                                <input type="hidden"
                                                       name="mapping[custom_meta][discogs_field][]"
                                                       value="<?php echo esc_attr($cdf); ?>"
                                                       class="d2w-field-input" />
                                            <?php else: ?>
                                                <span class="d2w-drop-hint">Drop field here</span>
                                                <input type="hidden"
                                                       name="mapping[custom_meta][discogs_field][]"
                                                       value=""
                                                       class="d2w-field-input" />
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="button-link d2w-remove-custom-row">Remove</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>

                            </tbody>
                        </table>

                        <p style="margin-top:10px;">
                            <button type="button" class="button" id="d2w-add-custom-meta">+ Add Custom Meta Field</button>
                        </p>

                    </div>
                </div>

                <p class="d2w-save-row">
                    <?php submit_button('Save Field Mapping', 'primary', 'submit', false); ?>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=d2w-field-mapping&d2w_reset=1'), 'd2w_reset_mapping')); ?>"
                       class="button button-secondary d2w-reset-btn">Reset to Defaults</a>
                </p>
            </form>
        </div>

        <template id="d2w-custom-row-tpl">
            <tr class="d2w-target-row d2w-custom-meta-row">
                <td class="d2w-target-label">
                    <input type="text"
                           name="mapping[custom_meta][meta_key][]"
                           value=""
                           placeholder="e.g. _my_meta_key"
                           class="d2w-meta-key-input" />
                </td>
                <td class="d2w-target-slot-cell d2w-custom-meta-slot">
                    <div class="d2w-drop-area" data-wc-field="custom_meta_new">
                        <span class="d2w-drop-hint">Drop field here</span>
                        <input type="hidden"
                               name="mapping[custom_meta][discogs_field][]"
                               value=""
                               class="d2w-field-input" />
                    </div>
                    <button type="button" class="button-link d2w-remove-custom-row">Remove</button>
                </td>
            </tr>
        </template>
        <?php
    }
}