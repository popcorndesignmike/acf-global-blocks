<?php
/**
 * Plugin Name: ACF Global Blocks
 * Description: Save an ACF Flexible Content row as a reusable global block and insert it into compatible Flexible Content fields.
 * Version: 1.3.14
 * Author: Popcorn Web Design
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: advanced-custom-fields-pro
 * Text Domain: acf-global-blocks
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ACF_Global_Blocks
{
    private const VERSION = '1.3.14';
    private const POST_TYPE = 'acf_global_block';
    private const LAYOUT_NAME = 'acf_global_block';
    private const META_FIELD_KEY = '_acf_global_blocks_field_key';
    private const META_FIELD_NAME = '_acf_global_blocks_field_name';
    private const META_LAYOUT = '_acf_global_blocks_layout';
    private const META_LAYOUTS = '_acf_global_blocks_layouts';
    private const META_ROWS = '_acf_global_blocks_rows';
    private const SETTINGS_OPTION = 'acf_global_blocks_settings';
    private const SETTINGS_GROUP = 'acf_global_blocks_settings';

    /** @var array<int, bool> */
    private static array $expansion_stack = [];

    /** @var array<int, array<int, \WP_Post>> */
    private static array $linked_usage = [];

    private static bool $linked_usage_loaded = false;

    public static function boot(): void
    {
        add_action('init', [self::class, 'register_post_type']);
        add_action('admin_notices', [self::class, 'acf_notice']);
        add_action('add_meta_boxes_' . self::POST_TYPE, [self::class, 'add_meta_boxes']);
        add_action('save_post_' . self::POST_TYPE, [self::class, 'save_global_block']);
        add_action('wp_ajax_acf_global_blocks_save_row', [self::class, 'ajax_save_row']);
        add_action('wp_ajax_acf_global_blocks_search', [self::class, 'ajax_search_blocks']);
        add_action('acf/input/admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_list_assets']);
        add_action('admin_menu', [self::class, 'register_settings_page']);
        add_action('admin_init', [self::class, 'register_settings']);

        add_filter('acf/load_field/type=flexible_content', [self::class, 'add_global_block_layout'], 30);
        add_filter('acf/load_value/type=flexible_content', [self::class, 'expand_global_blocks'], 30, 3);
        add_filter('acf/update_value/type=flexible_content', [self::class, 'import_standalone_copies'], 5, 3);
        add_filter('acf/fields/flexible_content/layout_title', [self::class, 'global_block_layout_title'], 20, 3);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [self::class, 'list_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [self::class, 'list_column_value'], 10, 2);
        add_filter('views_edit-' . self::POST_TYPE, [self::class, 'list_views']);
        add_action('pre_get_posts', [self::class, 'filter_admin_list']);
    }

    public static function register_post_type(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Global Blocks', 'acf-global-blocks'),
                'singular_name' => __('Global Block', 'acf-global-blocks'),
                'menu_name' => __('Global Blocks', 'acf-global-blocks'),
                'edit_item' => __('Edit Global Block', 'acf-global-blocks'),
                'view_item' => __('View Global Block', 'acf-global-blocks'),
                'search_items' => __('Search Global Blocks', 'acf-global-blocks'),
                'not_found' => __('No global blocks found.', 'acf-global-blocks'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'menu_icon' => 'dashicons-screenoptions',
            'supports' => ['title'],
            'map_meta_cap' => true,
            'capability_type' => 'post',
            'capabilities' => [
                'create_posts' => 'do_not_allow',
            ],
        ]);
    }

    public static function acf_notice(): void
    {
        if (function_exists('acf_get_field')) {
            return;
        }

        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__('ACF Global Blocks requires Advanced Custom Fields Pro.', 'acf-global-blocks');
        echo '</p></div>';
    }

    public static function register_settings_page(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            __('Global Blocks Settings', 'acf-global-blocks'),
            __('Settings', 'acf-global-blocks'),
            'manage_options',
            'acf-global-blocks-settings',
            [self::class, 'render_settings_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting(self::SETTINGS_GROUP, self::SETTINGS_OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_settings'],
            'default' => [
                'mode' => 'all',
                'field_keys' => [],
            ],
        ]);
    }

    /**
     * @param mixed $settings
     * @return array{mode: string, field_keys: array<int, string>}
     */
    public static function sanitize_settings($settings): array
    {
        $settings = is_array($settings) ? $settings : [];
        $mode = ($settings['mode'] ?? 'all') === 'selected' ? 'selected' : 'all';
        $field_keys = is_array($settings['field_keys'] ?? null) ? $settings['field_keys'] : [];
        $field_keys = array_values(array_unique(array_filter(array_map('sanitize_key', $field_keys))));

        return [
            'mode' => $mode,
            'field_keys' => $field_keys,
        ];
    }

    public static function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::get_settings();
        $fields = self::get_flexible_content_fields();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Global Blocks Settings', 'acf-global-blocks'); ?></h1>
            <?php settings_errors(self::SETTINGS_OPTION); ?>
            <p><?php esc_html_e('Choose which ACF Flexible Content fields show the Global Blocks creation and insertion controls.', 'acf-global-blocks'); ?></p>

            <form action="options.php" method="post">
                <?php settings_fields(self::SETTINGS_GROUP); ?>

                <fieldset>
                    <legend class="screen-reader-text"><?php esc_html_e('Enabled Flexible Content fields', 'acf-global-blocks'); ?></legend>
                    <p>
                        <label>
                            <input type="radio" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[mode]" value="all" <?php checked($settings['mode'], 'all'); ?>>
                            <?php esc_html_e('Enable Global Blocks on every Flexible Content field', 'acf-global-blocks'); ?>
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="radio" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[mode]" value="selected" <?php checked($settings['mode'], 'selected'); ?>>
                            <?php esc_html_e('Enable Global Blocks only on the selected fields', 'acf-global-blocks'); ?>
                        </label>
                    </p>
                </fieldset>

                <table class="widefat striped" style="max-width: 1000px; margin-top: 16px;">
                    <thead>
                        <tr>
                            <th class="check-column"></th>
                            <th><?php esc_html_e('Field label', 'acf-global-blocks'); ?></th>
                            <th><?php esc_html_e('Field name', 'acf-global-blocks'); ?></th>
                            <th><?php esc_html_e('Field group', 'acf-global-blocks'); ?></th>
                            <th><?php esc_html_e('Field key', 'acf-global-blocks'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$fields) : ?>
                            <tr><td colspan="5"><?php esc_html_e('No ACF Flexible Content fields were found.', 'acf-global-blocks'); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ($fields as $field) : ?>
                                <tr>
                                    <th class="check-column">
                                        <input type="checkbox"
                                            name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[field_keys][]"
                                            value="<?php echo esc_attr($field['key']); ?>"
                                            <?php checked(in_array($field['key'], $settings['field_keys'], true)); ?>>
                                    </th>
                                    <td><strong><?php echo esc_html($field['label']); ?></strong></td>
                                    <td><code><?php echo esc_html($field['name']); ?></code></td>
                                    <td><?php echo esc_html($field['group']); ?></td>
                                    <td><code><?php echo esc_html($field['key']); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Add a special reference layout to each enabled Flexible Content field.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    public static function add_global_block_layout(array $field): array
    {
        if (empty($field['key'])) {
            return $field;
        }

        $field_key = (string) $field['key'];
        $field_enabled = self::field_is_enabled($field);
        $has_reference_layout = false;

        foreach ($field['layouts'] as &$layout) {
            $layout_name = (string) ($layout['name'] ?? '');

            if ($layout_name === self::LAYOUT_NAME) {
                $has_reference_layout = true;
                continue;
            }

            if ($layout_name === '' || self::layout_has_fallback_field($layout)) {
                continue;
            }

            $layout['sub_fields'][] = self::prepare_dynamic_field([
                'key' => self::fallback_field_key($field_key, $layout_name),
                'label' => __('Global block fallback', 'acf-global-blocks'),
                'name' => 'acf_global_block_source_id',
                'type' => 'text',
                'instructions' => '',
                'required' => 0,
                'parent' => $layout['key'] ?? '',
                'wrapper' => [
                    'class' => 'acf-global-blocks-fallback-marker',
                ],
            ]);
            $layout['sub_fields'][] = self::prepare_dynamic_field([
                'key' => self::placement_field_key($field_key, $layout_name),
                'label' => __('Global block placement', 'acf-global-blocks'),
                'name' => 'acf_global_block_placement_id',
                'type' => 'text',
                'instructions' => '',
                'required' => 0,
                'parent' => $layout['key'] ?? '',
                'wrapper' => [
                    'class' => 'acf-global-blocks-fallback-marker',
                ],
            ]);
            $layout['sub_fields'][] = self::prepare_dynamic_field([
                'key' => self::row_index_field_key($field_key, $layout_name),
                'label' => __('Global block row position', 'acf-global-blocks'),
                'name' => 'acf_global_block_row_index',
                'type' => 'text',
                'instructions' => '',
                'required' => 0,
                'parent' => $layout['key'] ?? '',
                'wrapper' => [
                    'class' => 'acf-global-blocks-fallback-marker',
                ],
            ]);
        }
        unset($layout);

        // Always keep the hidden linked-placement metadata registered. This
        // lets existing linked blocks continue updating even when creation and
        // insertion controls are disabled for this field in Settings.
        if (!$field_enabled) {
            return $field;
        }

        $wrapper = is_array($field['wrapper'] ?? null) ? $field['wrapper'] : [];
        $classes = preg_split('/\s+/', trim((string) ($wrapper['class'] ?? ''))) ?: [];
        if (!in_array('acf-global-blocks-enabled-field', $classes, true)) {
            $classes[] = 'acf-global-blocks-enabled-field';
        }
        $wrapper['class'] = trim(implode(' ', array_filter($classes)));
        $field['wrapper'] = $wrapper;

        if ($has_reference_layout) {
            return $field;
        }

        $choices = self::get_block_choices($field_key);
        $layout_key = self::layout_key($field_key);

        $field['layouts'][] = [
            'key' => $layout_key,
            'name' => self::LAYOUT_NAME,
            'label' => __('Global Block / Group', 'acf-global-blocks'),
            'display' => 'block',
            'sub_fields' => [self::prepare_dynamic_field([
                'key' => self::reference_field_key($field_key),
                'label' => __('Global block', 'acf-global-blocks'),
                'name' => 'acf_global_block_id',
                'type' => 'select',
                'instructions' => empty($choices)
                    ? __('Create one from an existing Flexible Content row using “Save as global block”.', 'acf-global-blocks')
                    : __('Changes made under Global Blocks are reflected everywhere this block is used.', 'acf-global-blocks'),
                'required' => 1,
                'choices' => $choices,
                'allow_null' => 0,
                'multiple' => 0,
                'ui' => 1,
                'ajax' => 1,
                'return_format' => 'value',
                'parent' => $layout_key,
                'wrapper' => [
                    'width' => 60,
                    'class' => 'acf-global-blocks-reference-field',
                ],
            ]), self::prepare_dynamic_field([
                'key' => self::mode_field_key($field_key),
                'label' => __('Insert as', 'acf-global-blocks'),
                'name' => 'acf_global_block_mode',
                'type' => 'button_group',
                'instructions' => __('Linked stays synced and keeps a local fallback. Standalone copy becomes a normal editable row when this page is saved.', 'acf-global-blocks'),
                'required' => 1,
                'choices' => [
                    'linked' => __('Linked', 'acf-global-blocks'),
                    'copy' => __('Standalone copy', 'acf-global-blocks'),
                ],
                'default_value' => 'linked',
                'return_format' => 'value',
                'layout' => 'horizontal',
                'parent' => $layout_key,
                'wrapper' => [
                    'width' => 40,
                ],
            ])],
            'min' => '',
            'max' => '',
        ];

        return $field;
    }

    /**
     * Replace references with their stored original row before ACF formats the value.
     *
     * @param mixed $value
     * @param mixed $post_id
     * @param array<string, mixed> $field
     * @return mixed
     */
    public static function expand_global_blocks($value, $post_id, array $field)
    {
        if (!is_array($value) || empty($field['key'])) {
            return $value;
        }

        $expanded = [];
        $field_key = (string) $field['key'];
        $reference_key = self::reference_field_key($field_key);
        $mode_key = self::mode_field_key($field_key);
        $handled_placements = [];

        $preserve_indexes = is_admin();

        foreach ($value as $row_index => $row) {
            if (!is_array($row)) {
                self::append_loaded_row($expanded, $row_index, $row, $preserve_indexes);
                continue;
            }

            $layout_name = (string) ($row['acf_fc_layout'] ?? '');

            if ($layout_name !== self::LAYOUT_NAME) {
                $fallback_key = self::fallback_field_key($field_key, $layout_name);
                $block_id = absint($row[$fallback_key] ?? $row['acf_global_block_source_id'] ?? 0);

                if (!$block_id) {
                    self::append_loaded_row($expanded, $row_index, $row, $preserve_indexes);
                    continue;
                }

                $placement_key = self::placement_field_key($field_key, $layout_name);
                $placement_id = self::get_row_value($row, $placement_key, 'acf_global_block_placement_id');
                $placement_id = is_string($placement_id) ? sanitize_text_field($placement_id) : '';

                if ($placement_id !== '' && isset($handled_placements[$placement_id])) {
                    continue;
                }

                $stored_rows = self::get_compatible_block_rows($block_id, $field_key);
                if (!$stored_rows) {
                    // The source is unavailable, so retain every local snapshot row.
                    self::append_loaded_row($expanded, $row_index, $row, $preserve_indexes);
                    continue;
                }

                if ($placement_id !== '') {
                    $handled_placements[$placement_id] = true;
                }

                if ($preserve_indexes) {
                    $expanded[$row_index] = [
                        'acf_fc_layout' => self::LAYOUT_NAME,
                        $reference_key => $block_id,
                        $mode_key => 'linked',
                    ];
                    continue;
                }

                foreach ($stored_rows as $stored_row) {
                    if (self::stored_row_is_disabled($stored_row)) {
                        continue;
                    }
                    $expanded[] = $stored_row;
                }
                continue;
            }

            // Legacy references from versions before local fallbacks were introduced.
            if ($preserve_indexes) {
                $expanded[$row_index] = $row;
                continue;
            }

            $block_id = absint($row[$reference_key] ?? $row['acf_global_block_id'] ?? 0);
            if (!$block_id || isset(self::$expansion_stack[$block_id])) {
                continue;
            }

            self::$expansion_stack[$block_id] = true;
            $stored_rows = self::get_compatible_block_rows($block_id, $field_key);

            if ($stored_rows) {
                foreach ($stored_rows as $stored_row) {
                    if (self::stored_row_is_disabled($stored_row)) {
                        continue;
                    }
                    $expanded[] = $stored_row;
                }
            }

            unset(self::$expansion_stack[$block_id]);
        }

        return $expanded;
    }

    /**
     * Convert Global Block rows set to “Standalone copy” into ordinary rows
     * before ACF writes the Flexible Content value.
     *
     * @param mixed $value
     * @param mixed $post_id
     * @param array<string, mixed> $field
     * @return mixed
     */
    public static function import_standalone_copies($value, $post_id, array $field)
    {
        if (!is_array($value) || empty($field['key'])) {
            return $value;
        }

        $prepared = [];
        $field_key = (string) $field['key'];
        $reference_key = self::reference_field_key($field_key);
        $mode_key = self::mode_field_key($field_key);
        $submitted_modes = self::get_submitted_modes($reference_key, $mode_key);

        foreach ($value as $row) {
            if (!is_array($row)) {
                $prepared[] = $row;
                continue;
            }

            $layout_name = (string) ($row['acf_fc_layout'] ?? '');

            if ($layout_name !== self::LAYOUT_NAME) {
                $fallback_key = self::fallback_field_key($field_key, $layout_name);
                $linked_block_id = absint($row[$fallback_key] ?? $row['acf_global_block_source_id'] ?? 0);

                if (!$linked_block_id) {
                    $prepared[] = $row;
                    continue;
                }

                $stored_rows = self::get_compatible_block_rows($linked_block_id, $field_key);
                if (!$stored_rows) {
                    $prepared[] = $row;
                    continue;
                }

                $existing_placement_key = self::placement_field_key($field_key, $layout_name);
                $placement_id = self::get_row_value($row, $existing_placement_key, 'acf_global_block_placement_id');
                $placement_id = is_string($placement_id) ? sanitize_text_field($placement_id) : '';
                $placement_id = $placement_id ?: wp_generate_uuid4();
                $layout_disabled = !empty($row['acf_fc_layout_disabled']);

                foreach ($stored_rows as $row_index => $stored_row) {
                    $stored_layout = (string) ($stored_row['acf_fc_layout'] ?? '');
                    $stored_row[self::fallback_field_key($field_key, $stored_layout)] = $linked_block_id;
                    $stored_row[self::placement_field_key($field_key, $stored_layout)] = $placement_id;
                    $stored_row[self::row_index_field_key($field_key, $stored_layout)] = $row_index;
                    if ($layout_disabled) {
                        $stored_row['acf_fc_layout_disabled'] = 1;
                    }
                    $prepared[] = $stored_row;
                }
                continue;
            }

            $block_id = absint($row[$reference_key] ?? $row['acf_global_block_id'] ?? 0);
            $stored_rows = self::get_compatible_block_rows($block_id, $field_key);

            if (!$stored_rows) {
                // Keep an invalid selection visible so ACF validation can report it.
                $prepared[] = $row;
                continue;
            }

            $mode = self::take_submitted_mode($submitted_modes, $block_id);
            $mode ??= self::get_row_value($row, $mode_key, 'acf_global_block_mode');
            $mode = is_string($mode) ? sanitize_key($mode) : '';

            // Never silently turn an unknown mode into a linked placement.
            // The rendered field always submits one of these explicit values.
            if (!in_array($mode, ['linked', 'copy'], true)) {
                $prepared[] = $row;
                continue;
            }

            $placement_id = $mode === 'linked' ? wp_generate_uuid4() : '';
            $layout_disabled = !empty($row['acf_fc_layout_disabled']);

            foreach ($stored_rows as $row_index => $stored_row) {
                $stored_layout = (string) ($stored_row['acf_fc_layout'] ?? '');
                $fallback_key = self::fallback_field_key($field_key, $stored_layout);
                $placement_key = self::placement_field_key($field_key, $stored_layout);
                $row_index_key = self::row_index_field_key($field_key, $stored_layout);

                if ($mode === 'linked') {
                    $stored_row[$fallback_key] = $block_id;
                    $stored_row[$placement_key] = $placement_id;
                    $stored_row[$row_index_key] = $row_index;

                } else {
                    // ACF does not delete an existing sub-field value when the
                    // key is omitted from an updated row. Explicitly clear the
                    // previous linked marker when detaching in the same slot.
                    $stored_row[$fallback_key] = '';
                    $stored_row[$placement_key] = '';
                    $stored_row[$row_index_key] = '';
                }

                // ACF records disabled Flexible Content rows by their physical
                // row indexes. One block/group wrapper may expand to several
                // rows, so every expanded row must inherit the marker.
                if ($layout_disabled) {
                    $stored_row['acf_fc_layout_disabled'] = 1;
                }
                $prepared[] = $stored_row;
            }
        }

        return $prepared;
    }

    /**
     * Keep ACF's physical row indexes while preparing values for the editor.
     * Disabled and renamed layout metadata is indexed against these values.
     *
     * @param array<int|string, mixed> $rows
     * @param int|string $row_index
     * @param mixed $row
     */
    private static function append_loaded_row(array &$rows, $row_index, $row, bool $preserve_index): void
    {
        if ($preserve_index) {
            $rows[$row_index] = $row;
            return;
        }

        $rows[] = $row;
    }

    public static function enqueue_admin_assets(): void
    {
        if (!function_exists('acf_get_field')) {
            return;
        }

        wp_enqueue_script(
            'acf-global-blocks-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            ['jquery', 'acf-input'],
            self::VERSION,
            true
        );

        wp_enqueue_style(
            'acf-global-blocks-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            self::VERSION
        );

        wp_localize_script('acf-global-blocks-admin', 'ACFGlobalBlocks', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('acf_global_blocks_save_row'),
            'searchNonce' => wp_create_nonce('acf_global_blocks_search'),
            'layoutName' => self::LAYOUT_NAME,
            'promptTitle' => __('Name this global block:', 'acf-global-blocks'),
            'promptGroupTitle' => __('Name this global block group:', 'acf-global-blocks'),
            'saving' => __('Saving…', 'acf-global-blocks'),
            'saved' => __('Global block saved.', 'acf-global-blocks'),
            'groupSaved' => __('Global block group saved.', 'acf-global-blocks'),
            'error' => __('The global block could not be saved.', 'acf-global-blocks'),
            'button' => __('Save as global block', 'acf-global-blocks'),
            'groupSelect' => __('Group', 'acf-global-blocks'),
            'groupButton' => __('Save selected as global group', 'acf-global-blocks'),
            'groupMinimum' => __('Select at least two rows from this Flexible Content field.', 'acf-global-blocks'),
            'edit' => __('Edit global block', 'acf-global-blocks'),
            'successTitle' => __('Saved', 'acf-global-blocks'),
            'errorTitle' => __('Something went wrong', 'acf-global-blocks'),
            'noticeTitle' => __('Global Blocks', 'acf-global-blocks'),
            'dismiss' => __('Dismiss notification', 'acf-global-blocks'),
        ]);
    }

    public static function enqueue_admin_list_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== 'edit.php') {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }

        wp_enqueue_style(
            'acf-global-blocks-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            self::VERSION
        );
    }

    public static function ajax_save_row(): void
    {
        check_ajax_referer('acf_global_blocks_save_row', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('You are not allowed to create global blocks.', 'acf-global-blocks')], 403);
        }

        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $field_key = sanitize_key(wp_unslash($_POST['field_key'] ?? ''));
        $payload = wp_unslash($_POST['row_payload'] ?? '');
        $payloads_json = wp_unslash($_POST['row_payloads'] ?? '');
        $payloads = [];

        if (is_string($payloads_json) && $payloads_json !== '') {
            $decoded_payloads = json_decode($payloads_json, true);
            if (is_array($decoded_payloads)) {
                $payloads = $decoded_payloads;
            }
        } elseif (is_string($payload) && $payload !== '') {
            $payloads = [$payload];
        }

        if ($title === '' || $field_key === '' || !$payloads) {
            wp_send_json_error(['message' => __('A title and valid Flexible Content selection are required.', 'acf-global-blocks')], 400);
        }

        $field = acf_get_field($field_key);
        if (!is_array($field) || ($field['type'] ?? '') !== 'flexible_content' || !self::field_is_enabled($field)) {
            wp_send_json_error(['message' => __('This Flexible Content field is not available for global blocks.', 'acf-global-blocks')], 400);
        }

        $rows = [];
        $layout_names = [];
        foreach ($payloads as $row_payload) {
            if (!is_string($row_payload)) {
                wp_send_json_error(['message' => __('The selected rows are not valid.', 'acf-global-blocks')], 400);
            }

            parse_str($row_payload, $parsed);
            $row = self::find_flexible_row($parsed);
            $layout_name = is_array($row) ? sanitize_key($row['acf_fc_layout'] ?? '') : '';

            if (!$row || $layout_name === '' || $layout_name === self::LAYOUT_NAME || !self::field_has_layout($field, $layout_name)) {
                wp_send_json_error(['message' => __('A selected row is not valid for this Flexible Content field.', 'acf-global-blocks')], 400);
            }

            $rows[] = self::clean_stored_row($row, $field_key, $layout_name);
            $layout_names[] = $layout_name;
        }

        $post_id = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $title,
        ], true);

        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message()], 500);
        }

        update_post_meta($post_id, self::META_FIELD_KEY, $field_key);
        update_post_meta($post_id, self::META_FIELD_NAME, sanitize_key($field['name'] ?? ''));
        update_post_meta($post_id, self::META_LAYOUT, $layout_names[0]);
        update_post_meta($post_id, self::META_LAYOUTS, $layout_names);
        update_post_meta($post_id, self::META_ROWS, $rows);
        self::sync_global_block_layout_meta($post_id, $field, $rows);

        wp_send_json_success([
            'id' => $post_id,
            'message' => count($rows) > 1
                ? __('Global block group saved.', 'acf-global-blocks')
                : __('Global block saved.', 'acf-global-blocks'),
            'editUrl' => get_edit_post_link($post_id, 'raw'),
        ]);
    }

    public static function ajax_search_blocks(): void
    {
        check_ajax_referer('acf_global_blocks_search', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('You are not allowed to search global blocks.', 'acf-global-blocks')], 403);
        }

        $field_key = sanitize_key(wp_unslash($_POST['global_field_key'] ?? ''));
        $search = sanitize_text_field(wp_unslash($_POST['s'] ?? $_POST['term'] ?? ''));
        $page = max(1, absint($_POST['paged'] ?? $_POST['page'] ?? 1));

        if ($field_key === '') {
            wp_send_json_error(['message' => __('A Flexible Content field is required.', 'acf-global-blocks')], 400);
        }

        $field = acf_get_field($field_key);
        if (!is_array($field) || ($field['type'] ?? '') !== 'flexible_content' || !self::field_is_enabled($field)) {
            wp_send_json_error(['message' => __('This Flexible Content field is not available for global blocks.', 'acf-global-blocks')], 400);
        }

        $query = new \WP_Query([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'paged' => $page,
            'orderby' => 'title',
            'order' => 'ASC',
            's' => $search,
            'meta_key' => self::META_FIELD_KEY,
            'meta_value' => $field_key,
            'no_found_rows' => false,
        ]);

        $grouped = [
            'individual' => [
                'text' => __('Individual blocks', 'acf-global-blocks'),
                'children' => [],
            ],
            'group' => [
                'text' => __('Block groups', 'acf-global-blocks'),
                'children' => [],
            ],
        ];

        foreach ($query->posts as $post) {
            $rows = get_post_meta($post->ID, self::META_ROWS, true);
            $type = is_array($rows) && count($rows) > 1 ? 'group' : 'individual';
            $grouped[$type]['children'][] = [
                'id' => (string) $post->ID,
                'text' => $post->post_title ?: sprintf(__('Global block #%d', 'acf-global-blocks'), $post->ID),
            ];
        }

        // ACF's Select2 adapter expects the results object at the JSON root.
        // Unlike regular WordPress AJAX responses, this must not be wrapped in
        // a `success` / `data` envelope.
        wp_send_json([
            'results' => array_values(array_filter(
                $grouped,
                static fn(array $group): bool => !empty($group['children'])
            )),
            'more' => ($page < $query->max_num_pages),
        ]);
    }

    public static function add_meta_boxes(): void
    {
        add_meta_box(
            'acf-global-blocks-content',
            __('Block content', 'acf-global-blocks'),
            [self::class, 'render_content_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public static function render_content_meta_box(\WP_Post $post): void
    {
        wp_nonce_field('acf_global_blocks_edit_' . $post->ID, 'acf_global_blocks_nonce');

        if (!function_exists('acf_get_field') || !function_exists('acf_render_field_wrap')) {
            echo '<p>' . esc_html__('Advanced Custom Fields Pro must be active to edit this block.', 'acf-global-blocks') . '</p>';
            return;
        }

        $field_key = (string) get_post_meta($post->ID, self::META_FIELD_KEY, true);
        $rows = get_post_meta($post->ID, self::META_ROWS, true);
        $field = $field_key ? acf_get_field($field_key) : null;

        if (!is_array($field) || ($field['type'] ?? '') !== 'flexible_content') {
            echo '<p>' . esc_html__('The original Flexible Content field is no longer registered. Restore that field definition to edit this block.', 'acf-global-blocks') . '</p>';
            return;
        }

        $field['layouts'] = array_values(array_filter(
            $field['layouts'] ?? [],
            static fn(array $layout): bool => ($layout['name'] ?? '') !== self::LAYOUT_NAME
        ));
        $field['label'] = __('Reusable content', 'acf-global-blocks');
        $field['instructions'] = __('Editing these rows updates every page that references this global block or group.', 'acf-global-blocks');
        $field['value'] = is_array($rows) ? $rows : [];
        $field['min'] = 1;
        $field['max'] = '';
        $field['prefix'] = 'acf_global_blocks';

        // This meta box stores its rows in one plugin-owned post meta value,
        // rather than through ACF's normal update pipeline. Mirror the row
        // state into ACF's native layout metadata so Disable/Enable and Rename
        // are restored correctly when the source block is reopened.
        self::sync_global_block_layout_meta($post->ID, $field, $field['value']);

        $field_type = acf_get_field_type('flexible_content');
        if (is_object($field_type) && property_exists($field_type, 'post_id')) {
            $field_type->post_id = $post->ID;
        }
        if (is_object($field_type) && property_exists($field_type, 'layout_meta')) {
            unset($field_type->layout_meta[$post->ID . ':' . $field['name']]);
        }

        acf_render_field_wrap($field);
    }

    public static function save_global_block(int $post_id): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['acf_global_blocks_nonce'] ?? ''));
        if (!$nonce || !wp_verify_nonce($nonce, 'acf_global_blocks_edit_' . $post_id)) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $field_key = (string) get_post_meta($post_id, self::META_FIELD_KEY, true);
        $submitted = wp_unslash($_POST['acf_global_blocks'][$field_key] ?? []);

        if (!is_array($submitted)) {
            return;
        }

        $field = $field_key ? acf_get_field($field_key) : null;
        $rows = self::find_flexible_rows($submitted);
        if (!is_array($field) || !$rows) {
            return;
        }

        $valid_rows = [];
        $layout_names = [];
        foreach ($rows as $row) {
            $layout_name = sanitize_key($row['acf_fc_layout'] ?? '');
            if ($layout_name === '' || $layout_name === self::LAYOUT_NAME || !self::field_has_layout($field, $layout_name)) {
                continue;
            }

            $valid_rows[] = self::clean_stored_row($row, $field_key, $layout_name);
            $layout_names[] = $layout_name;
        }

        if (!$valid_rows) {
            return;
        }

        update_post_meta($post_id, self::META_LAYOUT, $layout_names[0]);
        update_post_meta($post_id, self::META_LAYOUTS, $layout_names);
        update_post_meta($post_id, self::META_ROWS, $valid_rows);
        self::sync_global_block_layout_meta($post_id, $field, $valid_rows);
    }

    /**
     * @param array<string, mixed> $field
     */
    public static function global_block_layout_title(string $title, array $field, array $layout): string
    {
        if (($layout['name'] ?? '') !== self::LAYOUT_NAME || !function_exists('get_sub_field')) {
            return $title;
        }

        $block_id = absint(get_sub_field('acf_global_block_id'));
        if (!$block_id) {
            return $title;
        }

        $rows = get_post_meta($block_id, self::META_ROWS, true);
        $is_group = is_array($rows) && count($rows) > 1;

        return $is_group
            ? sprintf(__('Global Group — %s', 'acf-global-blocks'), get_the_title($block_id))
            : sprintf(__('Global Block — %s', 'acf-global-blocks'), get_the_title($block_id));
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function list_columns(array $columns): array
    {
        $columns['acf_global_blocks_type'] = __('Type', 'acf-global-blocks');
        $columns['acf_global_blocks_layout'] = __('Layout', 'acf-global-blocks');
        $columns['acf_global_blocks_field'] = __('Flexible Content field', 'acf-global-blocks');
        $columns['acf_global_blocks_usage'] = __('Where used', 'acf-global-blocks');
        return $columns;
    }

    public static function list_column_value(string $column, int $post_id): void
    {
        if ($column === 'acf_global_blocks_type') {
            $is_group = self::is_group($post_id);
            printf(
                '<span class="acf-global-blocks-type-badge %s">%s</span>',
                esc_attr($is_group ? 'is-group' : 'is-block'),
                esc_html($is_group ? __('Group', 'acf-global-blocks') : __('Block', 'acf-global-blocks'))
            );
        }

        if ($column === 'acf_global_blocks_layout') {
            $layouts = get_post_meta($post_id, self::META_LAYOUTS, true);
            if (!is_array($layouts) || !$layouts) {
                $layouts = [(string) get_post_meta($post_id, self::META_LAYOUT, true)];
            }
            echo esc_html(implode(' → ', array_filter($layouts)));
        }

        if ($column === 'acf_global_blocks_field') {
            $field_key = (string) get_post_meta($post_id, self::META_FIELD_KEY, true);
            $field_name = (string) get_post_meta($post_id, self::META_FIELD_NAME, true);
            $field = $field_key && function_exists('acf_get_field') ? acf_get_field($field_key) : null;

            if (is_array($field)) {
                echo '<strong>' . esc_html((string) ($field['label'] ?? $field_name)) . '</strong>';
                echo '<br><code>' . esc_html((string) ($field['name'] ?? $field_name)) . '</code>';
            } else {
                echo '<code>' . esc_html($field_name) . '</code>';
            }
        }

        if ($column === 'acf_global_blocks_usage') {
            self::render_linked_usage($post_id);
        }
    }

    private static function render_linked_usage(int $block_id): void
    {
        $usage = self::get_linked_usage($block_id);
        if (!$usage) {
            echo '<span class="acf-global-blocks-usage-empty">' . esc_html__('Not linked', 'acf-global-blocks') . '</span>';
            return;
        }

        $count = count($usage);
        $tooltip_id = 'acf-global-blocks-usage-' . $block_id;
        $count_label = sprintf(
            _n('%d linked item', '%d linked items', $count, 'acf-global-blocks'),
            $count
        );

        echo '<span class="acf-global-blocks-usage">';
        printf(
            '<button type="button" class="acf-global-blocks-usage-trigger" aria-describedby="%s"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span>%s</button>',
            esc_attr($tooltip_id),
            esc_html($count_label)
        );
        printf(
            '<span class="acf-global-blocks-usage-tooltip" id="%s"><strong>%s</strong><span class="acf-global-blocks-usage-list">',
            esc_attr($tooltip_id),
            esc_html__('Linked to', 'acf-global-blocks')
        );

        foreach ($usage as $used_post) {
            $title = get_the_title($used_post);
            $title = $title !== '' ? $title : __('(no title)', 'acf-global-blocks');
            $edit_url = get_edit_post_link($used_post->ID, 'raw');
            $post_type = get_post_type_object($used_post->post_type);
            $type_label = $post_type ? $post_type->labels->singular_name : $used_post->post_type;
            $status = get_post_status_object($used_post->post_status);
            $status_label = $status ? $status->label : $used_post->post_status;

            echo '<span class="acf-global-blocks-usage-item">';
            if ($edit_url) {
                printf('<a href="%s">%s</a>', esc_url($edit_url), esc_html($title));
            } else {
                echo '<span>' . esc_html($title) . '</span>';
            }
            printf(
                '<small>%s · %s</small>',
                esc_html($type_label),
                esc_html($status_label)
            );
            echo '</span>';
        }

        echo '</span></span></span>';
    }

    /**
     * Return unique posts containing a live linked marker for this block.
     * Standalone copies clear that marker and are therefore excluded.
     *
     * @return array<int, \WP_Post>
     */
    private static function get_linked_usage(int $block_id): array
    {
        self::load_linked_usage();
        return self::$linked_usage[$block_id] ?? [];
    }

    private static function load_linked_usage(): void
    {
        if (self::$linked_usage_loaded) {
            return;
        }

        self::$linked_usage_loaded = true;
        global $wpdb;

        $source_pattern = '%' . $wpdb->esc_like('_acf_global_block_source_id');
        $legacy_pattern = '%' . $wpdb->esc_like('_acf_global_block_id');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT pm.meta_value AS block_id, p.ID
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE (pm.meta_key LIKE %s OR pm.meta_key LIKE %s)
               AND pm.meta_value REGEXP '^[0-9]+$'
               AND p.post_type NOT IN ('revision', %s)
               AND p.post_status NOT IN ('trash', 'auto-draft')
             ORDER BY p.post_title ASC, p.ID ASC",
            $source_pattern,
            $legacy_pattern,
            self::POST_TYPE
        ));

        foreach ($rows as $row) {
            $linked_block_id = absint($row->block_id ?? 0);
            $used_post = get_post(absint($row->ID ?? 0));
            if (!$linked_block_id || !$used_post instanceof \WP_Post) {
                continue;
            }

            self::$linked_usage[$linked_block_id][] = $used_post;
        }
    }

    /**
     * @param array<string, string> $views
     * @return array<string, string>
     */
    public static function list_views(array $views): array
    {
        $current_type = sanitize_key(wp_unslash($_GET['acf_global_block_type'] ?? ''));
        $block_count = count(self::get_ids_by_type('block'));
        $group_count = count(self::get_ids_by_type('group'));
        $type_views = [
            'acf_global_blocks_blocks' => sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg([
                    'post_type' => self::POST_TYPE,
                    'acf_global_block_type' => 'block',
                ], admin_url('edit.php'))),
                $current_type === 'block' ? ' class="current" aria-current="page"' : '',
                esc_html__('Individual Blocks', 'acf-global-blocks'),
                $block_count
            ),
            'acf_global_blocks_groups' => sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url(add_query_arg([
                    'post_type' => self::POST_TYPE,
                    'acf_global_block_type' => 'group',
                ], admin_url('edit.php'))),
                $current_type === 'group' ? ' class="current" aria-current="page"' : '',
                esc_html__('Block Groups', 'acf-global-blocks'),
                $group_count
            ),
        ];

        $ordered = [];
        foreach ($views as $key => $view) {
            $ordered[$key] = $view;
            if ($key === 'all') {
                $ordered += $type_views;
            }
        }

        return $ordered + $type_views;
    }

    public static function filter_admin_list(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== self::POST_TYPE) {
            return;
        }

        $type = sanitize_key(wp_unslash($_GET['acf_global_block_type'] ?? ''));
        if (!in_array($type, ['block', 'group'], true)) {
            return;
        }

        $ids = self::get_ids_by_type($type);
        $query->set('post__in', $ids ?: [0]);
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function field_is_enabled(array $field): bool
    {
        $enabled = ($field['name'] ?? '') !== '' && self::field_enabled_in_settings((string) ($field['key'] ?? ''));
        return (bool) apply_filters('acf_global_blocks/enabled_for_field', $enabled, $field);
    }

    private static function is_group(int $post_id): bool
    {
        $rows = get_post_meta($post_id, self::META_ROWS, true);
        return is_array($rows) && count($rows) > 1;
    }

    /**
     * @return array<int, int>
     */
    private static function get_ids_by_type(string $type): array
    {
        $post_ids = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]);

        return array_values(array_map('intval', array_filter(
            $post_ids,
            static fn($post_id): bool => self::is_group((int) $post_id) === ($type === 'group')
        )));
    }

    /**
     * @return array{mode: string, field_keys: array<int, string>}
     */
    private static function get_settings(): array
    {
        $settings = get_option(self::SETTINGS_OPTION, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        return [
            'mode' => ($settings['mode'] ?? 'all') === 'selected' ? 'selected' : 'all',
            'field_keys' => array_values(array_filter(
                is_array($settings['field_keys'] ?? null) ? $settings['field_keys'] : [],
                'is_string'
            )),
        ];
    }

    private static function field_enabled_in_settings(string $field_key): bool
    {
        $settings = self::get_settings();
        return $settings['mode'] === 'all' || in_array($field_key, $settings['field_keys'], true);
    }

    /**
     * @return array<int, array{key: string, label: string, name: string, group: string}>
     */
    private static function get_flexible_content_fields(): array
    {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return [];
        }

        $found = [];
        foreach (acf_get_field_groups() as $group) {
            $group_fields = acf_get_fields($group);
            if (!is_array($group_fields)) {
                continue;
            }

            foreach ($group_fields as $field) {
                self::collect_flexible_content_fields(
                    $field,
                    (string) ($group['title'] ?? ''),
                    $found
                );
            }
        }

        uasort($found, static function (array $left, array $right): int {
            return strcasecmp($left['group'] . $left['label'], $right['group'] . $right['label']);
        });

        return array_values($found);
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, array{key: string, label: string, name: string, group: string}> $found
     */
    private static function collect_flexible_content_fields(array $field, string $group_title, array &$found): void
    {
        $field_key = (string) ($field['key'] ?? '');
        if (($field['type'] ?? '') === 'flexible_content' && $field_key !== '') {
            $found[$field_key] = [
                'key' => $field_key,
                'label' => (string) ($field['label'] ?? $field['name'] ?? $field_key),
                'name' => (string) ($field['name'] ?? ''),
                'group' => $group_title,
            ];
        }

        foreach (($field['sub_fields'] ?? []) as $sub_field) {
            if (is_array($sub_field)) {
                self::collect_flexible_content_fields($sub_field, $group_title, $found);
            }
        }

        foreach (($field['layouts'] ?? []) as $layout) {
            foreach (($layout['sub_fields'] ?? []) as $sub_field) {
                if (is_array($sub_field)) {
                    self::collect_flexible_content_fields($sub_field, $group_title, $found);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $layout
     */
    private static function layout_has_fallback_field(array $layout): bool
    {
        foreach (($layout['sub_fields'] ?? []) as $sub_field) {
            if (($sub_field['name'] ?? '') === 'acf_global_block_source_id') {
                return true;
            }
        }

        return false;
    }

    /**
     * Run dynamically added fields through ACF's own validator so they receive
     * all field-type defaults and internal properties expected during loading,
     * rendering and validation.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function prepare_dynamic_field(array $field): array
    {
        if (function_exists('acf_get_valid_field')) {
            return acf_get_valid_field($field);
        }

        $field['_name'] = $field['name'] ?? '';
        return $field;
    }

    /**
     * Read the mode directly from the submitted ACF payload. This protects the
     * user's explicit choice if a nested field handler normalizes the row before
     * the Flexible Content update filter runs.
     *
     * @return array<int, array{block_id: int, mode: string}>
     */
    private static function get_submitted_modes(string $reference_key, string $mode_key): array
    {
        if (!isset($_POST['acf']) || !is_array($_POST['acf'])) {
            return [];
        }

        $matches = [];
        self::collect_submitted_modes(wp_unslash($_POST['acf']), $reference_key, $mode_key, $matches);
        return $matches;
    }

    /**
     * @param mixed $value
     * @param array<int, array{block_id: int, mode: string}> $matches
     */
    private static function collect_submitted_modes($value, string $reference_key, string $mode_key, array &$matches): void
    {
        if (!is_array($value)) {
            return;
        }

        if (($value['acf_fc_layout'] ?? '') === self::LAYOUT_NAME) {
            $block_id = absint(self::get_row_value($value, $reference_key, 'acf_global_block_id'));
            $mode = self::get_row_value($value, $mode_key, 'acf_global_block_mode');
            $mode = is_string($mode) ? sanitize_key($mode) : '';

            if ($block_id && in_array($mode, ['linked', 'copy'], true)) {
                $matches[] = [
                    'block_id' => $block_id,
                    'mode' => $mode,
                ];
            }
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                self::collect_submitted_modes($child, $reference_key, $mode_key, $matches);
            }
        }
    }

    /**
     * @param array<int, array{block_id: int, mode: string}> $submitted_modes
     */
    private static function take_submitted_mode(array &$submitted_modes, int $block_id): ?string
    {
        foreach ($submitted_modes as $index => $submitted) {
            if ($submitted['block_id'] !== $block_id) {
                continue;
            }

            unset($submitted_modes[$index]);
            return $submitted['mode'];
        }

        return null;
    }

    /**
     * Read a dynamically registered sub-field value by either its ACF field key
     * or its field name. ACF can use either representation in the save pipeline.
     *
     * @param array<string, mixed> $row
     * @return mixed
     */
    private static function get_row_value(array $row, string $field_key, string $field_name)
    {
        if (array_key_exists($field_key, $row)) {
            return $row[$field_key];
        }

        if (array_key_exists($field_name, $row)) {
            return $row[$field_name];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private static function get_compatible_block_rows(int $block_id, string $field_key): ?array
    {
        if (!$block_id
            || get_post_type($block_id) !== self::POST_TYPE
            || get_post_status($block_id) !== 'publish'
            || get_post_meta($block_id, self::META_FIELD_KEY, true) !== $field_key) {
            return null;
        }

        $stored_rows = get_post_meta($block_id, self::META_ROWS, true);
        if (!is_array($stored_rows)) {
            return null;
        }

        $valid_rows = [];
        foreach ($stored_rows as $stored_row) {
            if (is_array($stored_row) && ($stored_row['acf_fc_layout'] ?? '') !== self::LAYOUT_NAME) {
                $valid_rows[] = self::normalize_row_collections($stored_row);
            }
        }

        return $valid_rows ?: null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function stored_row_is_disabled(array $row): bool
    {
        return !empty($row['acf_fc_layout_disabled']);
    }

    /**
     * Keep ACF's layout state in sync for the plugin-owned group editor.
     *
     * @param array<string, mixed> $field
     * @param array<int, array<string, mixed>> $rows
     */
    private static function sync_global_block_layout_meta(int $post_id, array $field, array $rows): void
    {
        if (!function_exists('acf_update_metadata_by_field') || empty($field['name'])) {
            return;
        }

        $disabled = [];
        $renamed = [];

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            if (self::stored_row_is_disabled($row)) {
                $disabled[] = $index;
            }

            $custom_label = sanitize_text_field((string) ($row['acf_fc_layout_custom_label'] ?? ''));
            if ($custom_label !== '') {
                $renamed[$index] = $custom_label;
            }
        }

        acf_update_metadata_by_field(
            $post_id,
            ['name' => '_' . (string) $field['name'] . '_layout_meta'],
            [
                'disabled' => $disabled,
                'renamed' => $renamed,
            ]
        );
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function field_has_layout(array $field, string $layout_name): bool
    {
        foreach (($field['layouts'] ?? []) as $layout) {
            if (($layout['name'] ?? '') === $layout_name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function get_block_choices(string $field_key): array
    {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_key' => self::META_FIELD_KEY,
            'meta_value' => $field_key,
            'suppress_filters' => false,
        ]);

        $choices = [
            __('Individual blocks', 'acf-global-blocks') => [],
            __('Block groups', 'acf-global-blocks') => [],
        ];

        foreach ($posts as $post) {
            $rows = get_post_meta($post->ID, self::META_ROWS, true);
            $group_label = is_array($rows) && count($rows) > 1
                ? __('Block groups', 'acf-global-blocks')
                : __('Individual blocks', 'acf-global-blocks');

            $choices[$group_label][$post->ID] = $post->post_title
                ?: sprintf(__('Global block #%d', 'acf-global-blocks'), $post->ID);
        }

        return array_filter($choices);
    }

    /**
     * Locate the submitted Flexible Content row inside ACF's nested input names.
     *
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private static function find_flexible_row($value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        if (isset($value['acf_fc_layout']) && is_string($value['acf_fc_layout'])) {
            return $value;
        }

        foreach ($value as $child) {
            $row = self::find_flexible_row($child);
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Locate the first collection of sibling Flexible Content rows inside an
     * ACF submission without accidentally collecting nested Flexible Content.
     *
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private static function find_flexible_rows($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if (isset($value['acf_fc_layout']) && is_string($value['acf_fc_layout'])) {
            return [$value];
        }

        $rows = [];
        foreach ($value as $child) {
            if (is_array($child) && isset($child['acf_fc_layout']) && is_string($child['acf_fc_layout'])) {
                $rows[] = $child;
            }
        }

        if ($rows) {
            return $rows;
        }

        foreach ($value as $child) {
            $rows = self::find_flexible_rows($child);
            if ($rows) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * Remove placement metadata before rows become reusable source content.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function clean_stored_row(array $row, string $field_key, string $layout_name): array
    {
        unset(
            $row[self::fallback_field_key($field_key, $layout_name)],
            $row[self::placement_field_key($field_key, $layout_name)],
            $row[self::row_index_field_key($field_key, $layout_name)],
            $row['acf_global_block_source_id'],
            $row['acf_global_block_placement_id'],
            $row['acf_global_block_row_index']
        );

        return self::normalize_row_collections($row);
    }

    /**
     * Convert ACF's temporary `row-*` input keys into sequential indexes.
     * Repeater and nested Flexible Content formatters expect database-style
     * numeric arrays when a stored global row enters the load pipeline.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_row_collections($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = self::normalize_row_collections($child);
        }

        if (!$value) {
            return $value;
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !str_starts_with($key, 'row-')) {
                return $value;
            }
        }

        return array_values($value);
    }

    private static function layout_key(string $field_key): string
    {
        return 'layout_acf_global_' . substr(md5($field_key), 0, 12);
    }

    private static function reference_field_key(string $field_key): string
    {
        return 'field_acf_global_' . substr(md5($field_key), 0, 12);
    }

    private static function mode_field_key(string $field_key): string
    {
        return 'field_acf_global_mode_' . substr(md5($field_key), 0, 12);
    }

    private static function fallback_field_key(string $field_key, string $layout_name): string
    {
        return 'field_acf_global_fallback_' . substr(md5($field_key . ':' . $layout_name), 0, 12);
    }

    private static function placement_field_key(string $field_key, string $layout_name): string
    {
        return 'field_acf_global_placement_' . substr(md5($field_key . ':' . $layout_name), 0, 12);
    }

    private static function row_index_field_key(string $field_key, string $layout_name): string
    {
        return 'field_acf_global_row_index_' . substr(md5($field_key . ':' . $layout_name), 0, 12);
    }
}

ACF_Global_Blocks::boot();
