<?php
/**
 * Plugin Name: Frequently Bought Together (fbt)
 * Plugin URI: https://dev.bioicawtech.com/
 * Description: A WooCommerce plugin to display frequently bought together products.
 * Version: 1.0.0
 * Author: Maryam Rafiq
 * Author URI: https://dev.bioicawtech.com/
 * License: GPL2
 * Text Domain: frequently-bought-together
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Enqueue styles and scripts
function fbt_enqueue_scripts() {
    if (is_product()) {
        wp_enqueue_style('fbt-style', plugin_dir_url(__FILE__) . 'assets/style.css');
        wp_enqueue_script('fbt-script', plugin_dir_url(__FILE__) . 'assets/script.js', array('jquery'), null, true);
        wp_localize_script('fbt-script', 'fbt_ajax', array('ajax_url' => admin_url('admin-ajax.php')));
    }
}
add_action('wp_enqueue_scripts', 'fbt_enqueue_scripts');

function enqueue_toastify_scripts() {
    wp_enqueue_script('toastify-js', 'https://cdn.jsdelivr.net/npm/toastify-js', array('jquery'), null, true);
    wp_enqueue_style('toastify-css', 'https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css', array(), null);
}
add_action('wp_enqueue_scripts', 'enqueue_toastify_scripts');


// Display the Frequently Bought Together section
function fbt_display_section() {
    global $product;
    
    // Check if we're in a shortcode context
    $is_shortcode = doing_filter('the_content') || (isset($GLOBALS['wp_current_filter']) && in_array('the_content', $GLOBALS['wp_current_filter']));
    
    // Get display setting
    $display_setting = get_option('fbt_display_control', 'default');
    
    // Determine if we should display
    if (($display_setting === 'default' && $is_shortcode) || 
        ($display_setting === 'shortcode' && !$is_shortcode)) {
        return;
    }

    // Rest of your existing fbt_display_section function...
    if (!$product) {
        return;
    }

    $product_id = $product->get_id();
    $fbt_products = get_post_meta($product_id, '_fbt_products', true);

    if (empty($fbt_products)) {
        return;
    }


    // Get the button color from settings
    $button_color = get_option('fbt_button_color', '#96588a');
    $add_all_color = get_option('fbt_add_all_color', '#96588a');
    $button_text_color = get_option('fbt_button_text_color', '#ffffff');
    $add_all_text_color = get_option('fbt_add_all_text_color', '#ffffff');


    // Do NOT automatically add the main product to the list
    // Let the checkbox determine inclusion
    $fbt_products = array_unique(array_merge([$product_id], $fbt_products));

    echo '<div class="fbt-section">';
    echo '<h3 id="fbt-heading">' . esc_html(get_option('fbt_title', 'Frequently Bought Together')) . '</h3>';
    
    echo '<div class="fbt-products">';

    $total_products = count($fbt_products);

    foreach ($fbt_products as $index => $fbt_product_id) {
        $fbt_product = wc_get_product($fbt_product_id);
        if (!$fbt_product) continue;

        // Get the display price for simple or variable products
        $default_price = $fbt_product->is_type('variable') ? 0 : wc_get_price_to_display($fbt_product);
        $display_price = wc_get_price_to_display($fbt_product);
        if ($fbt_product->is_type('variable')) {
            $variations = $fbt_product->get_available_variations();
            if (!empty($variations)) {
                $display_price = wc_get_price_to_display($fbt_product, ['price' => $variations[0]['display_price']]);
            }
        }

        $product_price = wc_price($display_price);

        echo '<div class="fbt-product">';
        $checked_by_default = get_option('fbt_checked_default') ? 'checked' : '';
        echo '<input type="checkbox" class="fbt-checkbox" data-product_id="' . esc_attr($fbt_product_id) . '" data-price="' . esc_attr($default_price) . '" ' . $checked_by_default . '>';
        echo '<a href="' . esc_url(get_permalink($fbt_product_id)) . '">' . $fbt_product->get_image() . '</a>';
        echo '<p>' . esc_html($fbt_product->get_name()) . ' - <span class="woocommerce-Price-amount amount">' . $product_price . '</span></p>';

        if ($fbt_product->is_type('variable')) {
            $modal_id = 'fbt-modal-' . $index;

            // Button to open the modal with event prevention
            echo '<button type="button" class="select-variation-btn button" data-modal-id="' . esc_attr($modal_id) . '" style="background-color: ' . esc_attr($button_color) . '; color: ' . esc_attr($button_text_color) . ';">' . __('Select Variation', 'frequently-bought-together') . '</button>';
            // Modal structure
            echo '<div id="' . esc_attr($modal_id) . '" class="fbt-modal">';
            echo '<div class="fbt-modal-content">';
            echo '<span class="close-modal-cross">×</span>';
            echo '<h4>' . __('Select Variations', 'frequently-bought-together') . '</h4>';
            echo '<div class="fbt-variations" data-product_id="' . esc_attr($fbt_product_id) . '">';

            $variations = $fbt_product->get_available_variations();
            $attributes = $fbt_product->get_attributes();

            // Store variation data for JavaScript
            echo '<script type="application/json" class="fbt-variation-data-' . esc_attr($fbt_product_id) . '">';
            echo json_encode(array_map(function($variation) {
                return [
                    'variation_id' => $variation['variation_id'],
                    'attributes' => $variation['attributes'],
                    'price' => wc_get_price_to_display(wc_get_product($variation['variation_id']), ['price' => $variation['display_price']])
                ];
            }, $variations));
            echo '</script>';

            foreach ($attributes as $attribute_name => $attribute) {
                $attribute_label = wc_attribute_label($attribute_name);
                
                echo '<label>' . esc_html($attribute_label) . '</label>';
                echo '<select class="fbt-variation" data-attribute="' . esc_attr($attribute_name) . '" data-product_id="' . esc_attr($fbt_product_id) . '">';
                echo '<option value="" disabled selected>' . __('Select', 'frequently-bought-together') . ' ' . esc_html($attribute_label) . '</option>';
                
                $is_first = true;
                foreach ($attribute->get_options() as $option) {
                    $variation_price = '';
                    foreach ($variations as $variation) {
                        if (isset($variation['attributes']['attribute_' . $attribute_name]) && $variation['attributes']['attribute_' . $attribute_name] == $option) {
                            $variation_price = wc_get_price_to_display($fbt_product, ['price' => $variation['display_price']]);
                            break;
                        }
                    }

                    $selected = $is_first ? 'selected' : '';
                    echo '<option value="' . esc_attr($option) . '" ' . $selected . '>';
                    echo esc_html($option);

                    if (get_option('fbt_show_variation_prices') && !empty($variation_price) && $variation_price > 0 && strpos($attribute_name, 'size') !== false) {
                        echo ' - ' . wc_price($variation_price);
                    }

                    echo '</option>';
                    $is_first = false;
                }

                echo '</select>';
            }

            echo '<button type="button" class="close-modal-button">Close</button>';
            echo '</div>'; // Close .fbt-variations
            echo '</div>'; // Close .fbt-modal-content
            echo '</div>'; // Close .fbt-modal
        }

        echo '</div>'; // Close .fbt-product

        if ($index < $total_products - 1) {
            echo '<div class="fbt-plus">+</div>';
        }
    }

    echo '</div>'; // Close .fbt-products
    echo '<p id="fbt-total-price-1"><strong>' . __('Total Price:', 'frequently-bought-together') . ' <span id="fbt-total-price">€0.00</span></strong></p>';
    echo '<button type="button" id="add-all-to-cart" class="button" disabled style="background-color: ' . esc_attr($add_all_color) . '; color: ' . esc_attr($add_all_text_color) . ';">' . __('Add All to Cart', 'frequently-bought-together') . '</button>';

    // Pass fbt_discount to JavaScript
    /*
    echo '<script type="text/javascript">';
    echo 'var fbt_discount = ' . intval(get_option('fbt_discount', 0)) . ';';
    echo '</script>';
    */

    // Inline CSS to prevent overlap and ensure proper styling
    echo '<style>';
    echo '.fbt-section { margin-top: 20px; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }';
    echo '.fbt-products { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }';
    echo '.fbt-product { text-align: center; max-width: 150px; }';
    echo '.fbt-plus { font-size: 24px; margin: 0 10px; }';
    echo '.fbt-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }';
    echo '.fbt-modal-content { background: #fff; margin: 15% auto; padding: 20px; width: 80%; max-width: 500px; border-radius: 5px; position: relative; }';
    echo '.close-modal-cross { position: absolute; top: 10px; right: 15px; font-size: 20px; cursor: pointer; }';
    echo '.close-modal-button { display: block; margin: 20px auto 0; padding: 10px 20px; background: #ddd; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }';
    echo '.fbt-variations select { width: 100%; margin: 10px 0; }';
    echo '#add-all-to-cart:disabled { opacity: 0.5; cursor: not-allowed; }';
    echo '</style>';

    echo '</div>'; // Close .fbt-section
}
add_action('woocommerce_after_add_to_cart_button', 'fbt_display_section', 15);

// Helper function to determine contrasting text color
function fbt_get_contrast_color($hexcolor) {
    $r = hexdec(substr($hexcolor, 1, 2));
    $g = hexdec(substr($hexcolor, 3, 2));
    $b = hexdec(substr($hexcolor, 5, 2));
    $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
    return ($yiq >= 128) ? '#000000' : '#ffffff';
}

// Enqueue color picker scripts
function fbt_enqueue_color_picker() {
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
}
add_action('admin_enqueue_scripts', 'fbt_enqueue_color_picker');


// AJAX: Add single product to cart
function fbt_ajax_add_to_cart() {
    if (!isset($_POST['product_id'])) {
        wp_send_json_error(['message' => __('Invalid request', 'frequently-bought-together')]);
    }

    $product_id = intval($_POST['product_id']);
    $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;

    $cart_item_key = WC()->cart->add_to_cart($product_id, 1, $variation_id);
    if ($cart_item_key) {
        wp_send_json_success(['message' => __('Product added to cart', 'frequently-bought-together')]);
    } else {
        wp_send_json_error(['message' => __('Could not add product to cart', 'frequently-bought-together')]);
    }

    wp_die();
}
add_action('wp_ajax_fbt_add_to_cart', 'fbt_ajax_add_to_cart');
add_action('wp_ajax_nopriv_fbt_add_to_cart', 'fbt_ajax_add_to_cart');


// Update the fbt_ajax_add_all_to_cart function
function fbt_ajax_add_all_to_cart() {
    if (!isset($_POST['product_data'])) {
        wp_send_json_error(['message' => 'No product data received']);
        return;
    }

    $product_data = json_decode(stripslashes($_POST['product_data']), true);

    if (empty($product_data)) {
        wp_send_json_error(['message' => 'Invalid product data']);
        return;
    }

    $added_count = 0;
    $has_unselected_variations = false;

    foreach ($product_data as $item) {
        $product_id = intval($item['product_id']);
        $variations = isset($item['variations']) ? $item['variations'] : [];
        $variation_id = 0;

        $product = wc_get_product($product_id);
        if (!$product) {
            continue;
        }

        if ($product->is_type('variable')) {
            // Check if variations were selected
            if (empty($variations)) {
                $has_unselected_variations = true;
                continue;
            }

            $variation_id = find_matching_variation_id($product_id, $variations);
            if (!$variation_id) {
                continue;
            }
        }

        $added = WC()->cart->add_to_cart($product_id, 1, $variation_id, $variations);
        if ($added) {
            $added_count++;
        }
    }

    if ($has_unselected_variations) {
        wp_send_json_error([
            'message' => 'Please select variations for all products before adding to cart',
            'type' => 'variation_required'
        ]);
        return;
    }

    if ($added_count > 0) {
        $message = sprintf(
            _n(
                '%d product added to cart successfully.',
                '%d products added to cart successfully.',
                $added_count,
                'frequently-bought-together'
            ),
            $added_count
        );
        $cart_url = esc_url(wc_get_cart_url());
        $message .= ' <a href="' . $cart_url . '" class="button wc-view-cart-button" style="margin-left: 10px; padding: 15px 25px; background: #000; color: #fff; border: 1px solid #000; text-decoration: none; border-radius: 0px; display: inline-block;">View Cart</a>';

        wc_add_notice($message, 'success');

        wp_send_json_success([
            'message' => $message,
            'cart_url' => $cart_url
        ]);
    } else {
        wp_send_json_error(['message' => 'Failed to add products to cart']);
    }

    wp_die();
}
add_action('wp_ajax_fbt_add_all_to_cart', 'fbt_ajax_add_all_to_cart');
add_action('wp_ajax_nopriv_fbt_add_all_to_cart', 'fbt_ajax_add_all_to_cart');



function find_matching_variation_id($product_id, $selected_variations) {
    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('variable')) {
        return 0; // Not a variable product
    }

    $variations = $product->get_available_variations();
    
    foreach ($variations as $variation) {
        $match = true;
        
        foreach ($selected_variations as $attribute => $value) {
            $attr_key = 'attribute_' . sanitize_title($attribute);

            if (!isset($variation['attributes'][$attr_key]) || strtolower($variation['attributes'][$attr_key]) !== strtolower($value)) {
                $match = false;
                break;
            }
        }

        if ($match) {
            return $variation['variation_id'];
        }
    }

    return 0; // No matching variation found
}


// Add meta box in product editor
function fbt_add_meta_box() {
    add_meta_box(
        'fbt_meta_box',
        __('Frequently Bought Together', 'frequently-bought-together'),
        'fbt_meta_box_callback',
        'product',
        'side'
    );
}
add_action('add_meta_boxes', 'fbt_add_meta_box');


// Add admin menu
function fbt_add_admin_menu() {
    add_menu_page(
        __('Frequently Bought Together', 'frequently-bought-together'),
        __('FBT Settings', 'frequently-bought-together'),
        'manage_options',
        'fbt-settings',
        'fbt_admin_settings_page',
        'dashicons-cart',
        56
    );
}
add_action('admin_menu', 'fbt_add_admin_menu');
// Admin settings page content

function fbt_admin_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Frequently Bought Together Settings', 'frequently-bought-together'); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('fbt_options_group'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Enable FBT Feature</th>
                    <td>
                        <input type="checkbox" name="fbt_enable" value="1" <?php checked(get_option('fbt_enable'), 1); ?> />
                        <label for="fbt_enable">Check to enable the Frequently Bought Together feature.</label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Show Prices with Variation</th>
                    <td>
                        <input type="checkbox" name="fbt_show_variation_prices" value="1" <?php checked(get_option('fbt_show_variation_prices'), 1); ?> />
                        <label for="fbt_show_variation_prices">Check to show prices next to variation dropdown options (e.g., sizes).</label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Section Title</th>
                    <td>
                        <input type="text" name="fbt_title" value="<?php echo esc_attr(get_option('fbt_title', 'Frequently Bought Together')); ?>" class="regular-text" />
                        <p class="description">Title displayed above the suggested products list.</p>
                    </td>
                </tr>
                
                <!-- <tr>
                    <th scope="row">Default Products (comma-separated IDs)</th>
                    <td>
                        <input type="text" name="fbt_default_products" value="<?php echo esc_attr(get_option('fbt_default_products')); ?>" class="regular-text" />
                        <p class="description">Enter product IDs to always suggest (e.g., 12,15,33).</p>
                    </td>
                </tr> -->
                
                <tr>
                    <th scope="row">Checkbox Checked by Default</th>
                    <td>
                        <input type="checkbox" name="fbt_checked_default" value="1" <?php checked(get_option('fbt_checked_default'), 1); ?> />
                        <label for="fbt_checked_default">Make suggested products checked by default.</label>
                    </td>
                </tr>
                
                <!-- <tr>
                    <th scope="row">Bundle Discount (%)</th>
                    <td>
                        <input type="number" name="fbt_discount" value="<?php echo esc_attr(get_option('fbt_discount', 0)); ?>" min="0" max="100" /> %
                        <p class="description">Apply discount when buying together. Leave 0 for no discount.</p>
                    </td>
                </tr> -->
                
                <tr>
                    <th scope="row">Select Variation Button Color</th>
                    <td>
                        <input type="text" name="fbt_button_color" id="fbt_button_color" value="<?php echo esc_attr(get_option('fbt_button_color', '#96588a')); ?>" class="color-field" />
                        <div class="color-palette">
                            <span class="color-option" data-target="fbt_button_color" data-color="#96588a" style="background-color: #96588a;"></span>
                            <span class="color-option" data-target="fbt_button_color" data-color="#337ab7" style="background-color: #337ab7;"></span>
                            <span class="color-option" data-target="fbt_button_color" data-color="#5cb85c" style="background-color: #5cb85c;"></span>
                            <span class="color-option" data-target="fbt_button_color" data-color="#5bc0de" style="background-color: #5bc0de;"></span>
                            <span class="color-option" data-target="fbt_button_color" data-color="#f0ad4e" style="background-color: #f0ad4e;"></span>
                            <span class="color-option" data-target="fbt_button_color" data-color="#d9534f" style="background-color: #d9534f;"></span>
                            <span class="color-option" data-target="fbt_button_color" data-color="#000000" style="background-color: #000000;"></span>
                            <span class="color-option" data-target="fbt_button_color" data-color="#ffffff" style="background-color: #ffffff; border: 1px solid #ddd;"></span>
                        </div>
                        <p class="description">Select the color for the "Select Variation" button.</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Add All to Cart Button Color</th>
                    <td>
                        <input type="text" name="fbt_add_all_color" id="fbt_add_all_color" value="<?php echo esc_attr(get_option('fbt_add_all_color', '#96588a')); ?>" class="color-field" />
                        <div class="color-palette">
                            <span class="color-option" data-target="fbt_add_all_color" data-color="#96588a" style="background-color: #96588a;"></span>
                            <span class="color-option" data-target="fbt_add_all_color" data-color="#337ab7" style="background-color: #337ab7;"></span>
                            <span class="color-option" data-target="fbt_add_all_color" data-color="#5cb85c" style="background-color: #5cb85c;"></span>
                            <span class="color-option" data-target="fbt_add_all_color" data-color="#5bc0de" style="background-color: #5bc0de;"></span>
                            <span class="color-option" data-target="fbt_add_all_color" data-color="#f0ad4e" style="background-color: #f0ad4e;"></span>
                            <span class="color-option" data-target="fbt_add_all_color" data-color="#d9534f" style="background-color: #d9534f;"></span>
                            <span class="color-option" data-target="fbt_add_all_color" data-color="#000000" style="background-color: #000000;"></span>
                            <span class="color-option" data-target="fbt_add_all_color" data-color="#ffffff" style="background-color: #ffffff; border: 1px solid #ddd;"></span>
                        </div>
                        <p class="description">Select the color for the "Add All to Cart" button.</p>
                    </td>
                </tr>

                <tr>
    <th scope="row">Select Variation Button Text Color</th>
    <td>
        <input type="text" name="fbt_button_text_color" id="fbt_button_text_color" value="<?php echo esc_attr(get_option('fbt_button_text_color', '#ffffff')); ?>" class="color-field" />
        <div class="color-palette">
            <span class="color-option" data-target="fbt_button_text_color" data-color="#ffffff" style="background-color: #ffffff; border: 1px solid #ddd;"></span>
            <span class="color-option" data-target="fbt_button_text_color" data-color="#000000" style="background-color: #000000;"></span>
            <span class="color-option" data-target="fbt_button_text_color" data-color="#333333" style="background-color: #333333;"></span>
            <span class="color-option" data-target="fbt_button_text_color" data-color="#666666" style="background-color: #666666;"></span>
            <span class="color-option" data-target="fbt_button_text_color" data-color="#96588a" style="background-color: #96588a;"></span>
            <span class="color-option" data-target="fbt_button_text_color" data-color="#337ab7" style="background-color: #337ab7;"></span>
            <span class="color-option" data-target="fbt_button_text_color" data-color="#5cb85c" style="background-color: #5cb85c;"></span>
        </div>
        <p class="description">Select the text color for the "Select Variation" button.</p>
    </td>
</tr>

<tr>
    <th scope="row">Add All to Cart Button Text Color</th>
    <td>
        <input type="text" name="fbt_add_all_text_color" id="fbt_add_all_text_color" value="<?php echo esc_attr(get_option('fbt_add_all_text_color', '#ffffff')); ?>" class="color-field" />
        <div class="color-palette">
            <span class="color-option" data-target="fbt_add_all_text_color" data-color="#ffffff" style="background-color: #ffffff; border: 1px solid #ddd;"></span>
            <span class="color-option" data-target="fbt_add_all_text_color" data-color="#000000" style="background-color: #000000;"></span>
            <span class="color-option" data-target="fbt_add_all_text_color" data-color="#333333" style="background-color: #333333;"></span>
            <span class="color-option" data-target="fbt_add_all_text_color" data-color="#666666" style="background-color: #666666;"></span>
            <span class="color-option" data-target="fbt_add_all_text_color" data-color="#96588a" style="background-color: #96588a;"></span>
            <span class="color-option" data-target="fbt_add_all_text_color" data-color="#337ab7" style="background-color: #337ab7;"></span>
            <span class="color-option" data-target="fbt_add_all_text_color" data-color="#5cb85c" style="background-color: #5cb85c;"></span>
        </div>
        <p class="description">Select the text color for the "Add All to Cart" button.</p>
    </td>
</tr>

                <tr>
                    <th scope="row">Display Control</th>
                    <td>
                        <fieldset>
                            <label>
                                 <input type="radio" name="fbt_display_control" value="default" <?php checked(get_option('fbt_display_control', 'default'), 'default'); ?> />
    <?php _e('Show in default position (after add to cart button)', 'frequently-bought-together'); ?>
</label><br>
                            <label>
                                <input type="radio" name="fbt_display_control" value="shortcode" <?php checked(get_option('fbt_display_control'), 'shortcode'); ?> />
                                <?php _e('Show ONLY via shortcode', 'frequently-bought-together'); ?>
                            </label>
                        </fieldset>
                        <p class="description">Choose where the Frequently Bought Together section should appear.</p>
                    </td>
                </tr>
                
                <tr id="fbt-shortcode-row" style="<?php echo (get_option('fbt_display_control') === 'shortcode') ? '' : 'display: none;'; ?>">
                    <th scope="row">Shortcode</th>
                    <td>
                        <div class="fbt-shortcode-container">
                            <input type="text" id="fbt-shortcode" value="[frequently_bought_together]" readonly class="regular-text">
                            <button type="button" class="button button-secondary fbt-copy-shortcode">Copy Shortcode</button>
                        </div>
                        <p class="description">Use this shortcode to display the section in your content.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    
    <style>
        .color-palette {
            display: flex;
            gap: 5px;
            margin-top: 5px;
        }
        .color-option {
            width: 25px;
            height: 25px;
            border-radius: 3px;
            cursor: pointer;
            border: 1px solid #ddd;
        }
        .color-option:hover {
            transform: scale(1.1);
        }
        .color-field {
            width: 100px;
        }
        .fbt-shortcode-container {
    display: flex;
    gap: 5px;
    align-items: center;
    max-width: 500px;
}
.fbt-shortcode-container input[type="text"] {
    flex-grow: 1;
    background-color: #f1f1f1;
    font-family: monospace;
}
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Initialize color pickers
        $('.color-field').wpColorPicker();
        
        // Handle color palette selection
        $('.color-option').on('click', function() {
            var target = $(this).data('target');
            var color = $(this).data('color');
            $('#' + target).val(color).trigger('change');
            $('#' + target).wpColorPicker('color', color);
        });
        $('input[name="fbt_display_control"]').change(function() {
            if ($(this).val() === 'shortcode') {
                $('#fbt-shortcode-row').show();
            } else {
                $('#fbt-shortcode-row').hide();
            }
        });
        
        $('.fbt-copy-shortcode').on('click', function() {
    var shortcodeInput = $('#fbt-shortcode');
    shortcodeInput.select();
    document.execCommand('copy');
    
    // Change button text temporarily
    var originalText = $(this).text();
    $(this).text('Copied!');
    setTimeout(function() {
        $('.fbt-copy-shortcode').text(originalText);
    }, 2000);
});
    });
    </script>
    <?php
}
// Register settings (optional, for adding actual options later)

//  Create the shortcode function
function fbt_shortcode_display($atts) {
    // Only show if shortcode mode is enabled
    if (get_option('fbt_display_control') !== 'shortcode') {
        return '';
    }
    
    ob_start();
    fbt_display_section();
    return ob_get_clean();
}
add_shortcode('frequently_bought_together', 'fbt_shortcode_display');

function fbt_register_settings() {
    register_setting('fbt_options_group', 'fbt_enable', array(
        'type' => 'boolean',
        'sanitize_callback' => 'absint',
        'default' => 0
    ));
    register_setting('fbt_options_group', 'fbt_show_variation_prices', array(
        'type' => 'boolean',
        'sanitize_callback' => 'absint',
        'default' => 0
    ));
    register_setting('fbt_options_group', 'fbt_title', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'Frequently Bought Together'
    ));
    register_setting('fbt_options_group', 'fbt_default_products', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field'
    ));
    register_setting('fbt_options_group', 'fbt_checked_default', array(
        'type' => 'boolean',
        'sanitize_callback' => 'absint',
        'default' => 0
    ));
    // Comment out this registration
/*
    register_setting('fbt_options_group', 'fbt_discount', array(
        'type' => 'integer',
        'sanitize_callback' => 'absint',
        'default' => 0
    ));
    */
    register_setting('fbt_options_group', 'fbt_button_color', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_hex_color',
        'default' => '#000000'
    ));
    
    register_setting('fbt_options_group', 'fbt_add_all_color', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_hex_color',
        'default' => '#000000'
    ));
    register_setting('fbt_options_group', 'fbt_button_text_color', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_hex_color',
        'default' => '#ffffff'
    ));
    register_setting('fbt_options_group', 'fbt_add_all_text_color', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_hex_color',
        'default' => '#ffffff'
    ));
    register_setting('fbt_options_group', 'fbt_display_control', array(
    'type' => 'string',
    'sanitize_callback' => 'sanitize_text_field',
    'default' => 'default' // This ensures 'default' is the initial value
));

}
add_action('admin_init', 'fbt_register_settings');



register_setting('fbt_options_group', 'fbt_title', array(
    'type' => 'string',
    'sanitize_callback' => 'sanitize_text_field',
));

register_setting('fbt_options_group', 'fbt_default_products', array(
    'type' => 'string',
    'sanitize_callback' => 'sanitize_text_field', // or your custom sanitize function
));

register_setting('fbt_options_group', 'fbt_discount', array(
    'type' => 'integer',
    'sanitize_callback' => 'absint',
));



// Meta Box Callback Function
function fbt_meta_box_callback($post) {
    $fbt_products = get_post_meta($post->ID, '_fbt_products', true) ?: [];
    $all_products = wc_get_products(['limit' => -1]);

    // Main product (always displayed)
    $main_product_id = $post->ID;

    echo '<p><strong>Main Product:</strong> ' . get_the_title($main_product_id) . '</p>';

    // Selected Products Display Area
    echo '<div id="selected-products">';
    if (!empty($fbt_products)) {
        foreach ($fbt_products as $product_id) {
            echo '<span class="selected-product" data-id="' . esc_attr($product_id) . '">'
                . esc_html(get_the_title($product_id))
                . ' <span class="remove-product" style="cursor:pointer; color:red;">✖</span></span>';
        }
    }
    echo '</div>';

    // Dropdown to Select Products
    echo '<select id="fbt-products-select" style="width:100%;">';
    echo '<option value="">Select a product</option>';
    foreach ($all_products as $product) {
        if ($product->get_id() == $main_product_id) continue; // Skip main product
        echo '<option value="' . esc_attr($product->get_id()) . '">' . esc_html($product->get_name()) . '</option>';
    }
    echo '</select>';

    // Hidden Input to Store Selected Products
    echo '<input type="hidden" name="fbt_products" id="fbt-products-hidden" value="' . esc_attr(implode(',', $fbt_products)) . '">';

    // JavaScript for Managing Selection and Removal
    ?>
    <script>
    jQuery(document).ready(function ($) {
        let selectedProducts = $('#fbt-products-hidden').val().split(',').filter(Boolean);
        
        // Add Product to Selection
        $('#fbt-products-select').on('change', function () {
            let productId = $(this).val();
            let productName = $(this).find('option:selected').text();

            if (productId && !selectedProducts.includes(productId) && selectedProducts.length < 2) {
                selectedProducts.push(productId);
                $('#selected-products').append(`
                    <span class="selected-product" data-id="${productId}">
                        ${productName} 
                        <span class="remove-product" style="cursor:pointer; color:red;">✖</span>
                    </span>
                `);
                updateHiddenField();
            } else if (selectedProducts.length >= 2) {
                alert('You can select a maximum of 2 additional products.');
            }
        });

        // Remove Selected Product
        $(document).on('click', '.remove-product', function () {
            let productId = $(this).parent().data('id');
            selectedProducts = selectedProducts.filter(id => id !== String(productId));
            $(this).parent().remove();
            updateHiddenField();
        });

        // Update Hidden Input Field
        function updateHiddenField() {
            $('#fbt-products-hidden').val(selectedProducts.join(','));
        }
    });
    </script>

    <style>
        #selected-products { margin-top: 10px; }
        .selected-product {
            display: inline-block;
            background: #e0e0e0;
            padding: 5px;
            margin: 3px;
            border-radius: 3px;
        }
    </style>
    <?php
}

// Save Selected Products (Excluding Main Product)
function fbt_save_meta_box($post_id) {
    if (isset($_POST['fbt_products'])) {
        $selected_products = explode(',', sanitize_text_field($_POST['fbt_products']));
        $selected_products = array_slice(array_filter($selected_products), 0, 2); // Ensure max 2
        update_post_meta($post_id, '_fbt_products', $selected_products);
    }
}
add_action('save_post', 'fbt_save_meta_box');


function fbt_update_cart_count() {
    wp_send_json_success(['cart_count' => WC()->cart->get_cart_contents_count()]);
    wp_die();
}
add_action('wp_ajax_fbt_update_cart_count', 'fbt_update_cart_count');
add_action('wp_ajax_nopriv_fbt_update_cart_count', 'fbt_update_cart_count');

function ensure_woocommerce_cart_fragments() {
    if (is_cart() || is_checkout()) return;

    wp_enqueue_script('wc-cart-fragments', WC()->plugin_url() . '/assets/js/frontend/cart-fragments.min.js', array('jquery'), null, true);
}
add_action('wp_enqueue_scripts', 'ensure_woocommerce_cart_fragments');

add_action('woocommerce_after_single_product_summary', 'fbt_display_suggestions', 15);

function fbt_display_suggestions() {
    // Check if FBT is enabled
    if (get_option('fbt_enable') != 1) {
        return;
    }

    // Get settings
    $title = get_option('fbt_title', 'Frequently Bought Together');
    $default_ids = get_option('fbt_default_products', '');
    $checked = get_option('fbt_checked_default') ? 'checked' : '';
    //$discount = floatval(get_option('fbt_discount', 0));

    // Convert default IDs to array
    $product_ids = array_filter(array_map('trim', explode(',', $default_ids)));

    if (empty($product_ids)) {
        echo '<p>No recommended products configured.</p>';
        return;
    }

    echo '<div class="fbt-wrapper" style="margin-top:30px; padding:20px; border:1px solid #ccc;">';
    echo '<h2>' . esc_html($title) . '</h2>';
    echo '<form method="post" action="">';

    foreach ($product_ids as $id) {
        $product = wc_get_product($id);
        if (!$product) continue;

        echo '<label style="display:block; margin:10px 0;">';
        echo '<input type="checkbox" name="fbt_products[]" value="' . esc_attr($id) . '" ' . $checked . '> ';
        echo esc_html($product->get_name()) . ' - ' . wc_price($product->get_price());
        echo '</label>';
    }

    echo '<button type="submit" class="button alt">Add to Cart</button>';
    echo '</form>';
    echo '</div>';
}


add_action('template_redirect', 'fbt_handle_add_to_cart');

function fbt_handle_add_to_cart() {
    if (isset($_POST['fbt_products']) && is_array($_POST['fbt_products'])) {
        foreach ($_POST['fbt_products'] as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                //$price = $product->get_price();
                //$discount = floatval(get_option('fbt_discount', 0));

                // Apply discount if set
                //if ($discount > 0) {
                    //$discounted_price = $price - ($price * $discount / 100);
                    // Store price in session or custom meta to override later (needs custom solution)
                    // For now, just add to cart normally
                //}

                WC()->cart->add_to_cart($product_id);
            }
        }

        // Redirect to avoid resubmission
        wp_redirect(wc_get_cart_url());
        exit;
    }
}

