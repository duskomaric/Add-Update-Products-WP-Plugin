<?php

/**
 * Plugin Name: Add Update Products Plugin
 * Description: Import products from API
 * Version: 1.2.0
 */

error_reporting(0);
ini_set('display_errors', 0);

class Add_Update_Products_Plugin
{
    private string $username;
    private string $password;
    private string $base_api_endpoint_path;

    public function __construct()
    {
        $this->username = get_option('add_update_products_username');
        $this->password = get_option('add_update_products_password');
        $this->base_api_endpoint_path = get_option('add_update_products_base_api_path');
    }

    public function save_settings(): void
    {
        if (
            isset($_POST['username'])
            && isset($_POST['password'])
            && !isset($_POST['run_add_update_products_function'])
        ) {
            $this->username = sanitize_text_field($_POST['username']);
            $this->password = sanitize_text_field($_POST['password']);
            $this->base_api_endpoint_path = sanitize_text_field($_POST['base_api_path']);

            $fields = [
                'username' => 'Username',
                'password' => 'Password',
                'base_api_endpoint_path' => 'API Endpoint URL'
            ];

            foreach ($fields as $field => $label) {
                if (empty($this->$field)) {
                    $this->validation_errors[] = $label . ' is required.';
                }
            }

            if (empty($this->validation_errors)) {
                update_option('add_update_products_username', $this->username);
                update_option('add_update_products_password', $this->password);
                update_option('add_update_products_base_api_path', $this->base_api_endpoint_path);

                echo '<div class="notice-to-remove notice notice-success"><p>Settings saved!</p></div>';
            } else {
                foreach ($this->validation_errors as $error) {
                    echo '<div class="notice-to-remove notice notice-error"><p>' . $error . '</p></div>';
                }
            }
        }
    }

    public function run_add_update_products(): void
    {
        ?>
        <div class="wrap">
            <h1>Add/Update Products</h1>
            <?php

            define("TOKEN_URL", $this->base_api_endpoint_path . '/logIn/');
            define("PRODUCT_URL", $this->base_api_endpoint_path . '/proizvodi/');

            add_filter('https_ssl_verify', '__return_false');
            add_filter('https_local_ssl_verify', '__return_false');

            $api_start_time = microtime(true);

            $token_response = wp_remote_post(TOKEN_URL, array(
                'headers' => array(
                    'Content-Type' => 'application/json'
                ),
                'body' => wp_json_encode(array(
                    'username' => $this->username,
                    'password' => $this->password
                ))
            ));

            if (array_key_exists('code', $token_response['response']) && $token_response['response']['code'] == 500) {
                $error_message = 'Error with API server';
                echo '<div class="notice-to-remove notice notice-error"><p>' . $error_message . '</p></div>';
                $this->display_form_and_button();
                die();
            }

            $token_response_body = wp_remote_retrieve_body($token_response);
            $token_data = json_decode($token_response_body, true);

            if (!is_array($token_data) || !array_key_exists('access', $token_data)) {
                $error_message = $token_data['detail'] ?? 'Error with login';
                echo '<div class="notice-to-remove notice notice-error"><p>' . $error_message . '</p></div>';
                $this->display_form_and_button();
                die();
            }

            $jwt_token = $token_data['access'];

            $products_response = wp_remote_get(PRODUCT_URL, array(
                'headers' => array(
                    'Authorization' => 'JWT ' . $jwt_token,
                    'Content-Type' => 'application/json'
                ),
                'timeout' => 30
            ));

            $products_data = json_decode(wp_remote_retrieve_body($products_response));

            $api_end_time = microtime(true);
            $api_execution_time = $api_end_time - $api_start_time;

            $start_time = microtime(true);

            $product_batches = array_chunk($products_data, 100);

            global $wpdb;
            $products_without_main_category = 0;
            $products_inserted = 0;
            $products_updated = 0;

            foreach ($product_batches as $batch) {
                foreach ($batch as $product_data) {
                    if ($product_data->Kat1 === null) {
                        $products_without_main_category++;
                    }
            
                    $existing_product_id = wc_get_product_id_by_sku($product_data->SKU);
            
                    if ($existing_product_id) {
                        try {
                            $product = wc_get_product($existing_product_id);
            
                            $product->set_name($product_data->NazivProizvoda);
                            $product->set_regular_price($product_data->Cijena);
            
                            if ($product_data->AkcijskaCijena == 0.0) {
                                $product->set_sale_price(null);
                            } else {
                                $product->set_sale_price($product_data->AkcijskaCijena);
                            }
            
                            $product->set_stock_quantity($product_data->Kolicina);
                            $product->set_low_stock_amount($product_data->MinKolicina);
                            $product->set_manage_stock(true);
                            $product->update_meta_data('_wc_points_earned', $product_data->DodatniBodovi);
            
                            $product->save();
            
                            $this->category_add_or_update($product_data->Kat1, $product_data->Kat2, $product_data->Kat3, $existing_product_id);
            
                            echo '<div class="notice notice-success"><p>' . $product_data->NazivProizvoda . ' has been updated.</p></div>';
            
                            $products_updated++;
                        } catch (Exception $e) {
                            echo '<div class="notice notice-error"><p>Error updating ' . $product_data->NazivProizvoda . ': ' . $e->getMessage() . '</p></div>';
                        }
                    } else {
                        try {
                            $new_product = new WC_Product();
            
                            $new_product->set_name($product_data->NazivProizvoda);
                            $new_product->set_regular_price($product_data->Cijena);
            
                            if ($product_data->AkcijskaCijena == 0.0) {
                                $new_product->set_sale_price(null);
                            } else {
                                $new_product->set_sale_price($product_data->AkcijskaCijena);
                            }
            
                            $new_product->set_stock_quantity($product_data->Kolicina);
                            $new_product->set_low_stock_amount($product_data->MinKolicina);
                            $new_product->set_manage_stock(true);
                            $new_product->update_meta_data('_sku', $product_data->SKU);
                            $new_product->update_meta_data('_wc_points_earned', $product_data->DodatniBodovi);
            
                            $new_product_id = $new_product->save();
            
                            $this->category_add_or_update($product_data->Kat1, $product_data->Kat2, $product_data->Kat3, $new_product_id);
            
                            echo '<div class="notice notice-success"><p>' . $product_data->NazivProizvoda . ' has been inserted.</p></div>';
            
                            $products_inserted++;
                        } catch (Exception $e) {
                            echo '<div class="notice notice-error"><p>Error inserting ' . $product_data->NazivProizvoda . ': ' . $e->getMessage() . '</p></div>';
                        }
                    }
                }
            }
            
            

            ?>
        </div>
        <?php

        $end_time = microtime(true);
        $execution_time = $end_time - $start_time;

        echo '<div class="notice notice-success"><p>Finished Import!</p></div>';
        echo '<div class="notice notice-error"><p>Products without main category specified: '. $products_without_main_category .'</p></div>';
        echo '<div class="notice notice-info"><p>Products inserted: '. $products_inserted .' and products updated: '. $products_updated .'</p></div>';
        echo '<div class="notice notice-info"><p>API execution time: ' . $api_execution_time . ' seconds.</p></div>';
        echo '<div class="notice notice-info"><p>DB insert/update time: ' . $execution_time . ' seconds.</p></div>';

        $this->render_go_back_btn();
    }

    public function category_add_or_update(?string $category_name, ?string $sub_category_name, ?string $sub_sub_category_name, int $product_id): void
{
    $category = null;
    if ($category_name) {
        $category = get_term_by('name', $category_name, 'product_cat');
        if (!$category) {
            $category_id = wp_insert_term($category_name, 'product_cat');
            if (is_wp_error($category_id)) {
                echo '<div class="notice notice-error"><p>Error creating category: ' . $category_id->get_error_message() . '</p></div>';
                return;
            }
            $category_id = $category_id['term_id'];
        } else {
            $category_id = $category->term_id;
        }
    }

    $subcategory = null;
    if ($sub_category_name) {
        $subcategory = get_term_by('name', $sub_category_name, 'product_cat');
        if (!$subcategory) {
            $subcategory_id = wp_insert_term($sub_category_name, 'product_cat', array('parent' => $category_id));
            if (is_wp_error($subcategory_id)) {
                echo '<div class="notice notice-error"><p>Error creating subcategory: ' . $subcategory_id->get_error_message() . '</p></div>';
                return;
            }
            $subcategory_id = $subcategory_id['term_id'];
        } else {
            $subcategory_id = $subcategory->term_id;
        }
    }

    $subsubcategory = null;
    if ($sub_sub_category_name) {
        $subsubcategory = get_term_by('name', $sub_sub_category_name, 'product_cat');
        if (!$subsubcategory) {
            $subsubcategory_id = wp_insert_term($sub_sub_category_name, 'product_cat', array('parent' => $subcategory_id));
            if (is_wp_error($subsubcategory_id)) {
                echo '<div class="notice notice-error"><p>Error creating subsubcategory: ' . $subsubcategory_id->get_error_message() . '</p></div>';
                return;
            }
            $subsubcategory_id = $subsubcategory_id['term_id'];
        } else {
            $subsubcategory_id = $subsubcategory->term_id;
        }
    }

    $product = wc_get_product($product_id);
    if ($product) {
        $categories = array();
        if ($category) {
            $categories[] = $category_id;
        }
        if ($subcategory) {
            $categories[] = $subcategory_id;
        }
        if ($subsubcategory) {
            $categories[] = $subsubcategory_id;
        }
        wp_set_object_terms($product_id, $categories, 'product_cat', false);
        echo '<div class="notice notice-success"><p>Categories have been updated for product ID: ' . $product_id . '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>Product not found with ID: ' . $product_id . '</p></div>';
    }
}



    public function display_form_and_button(): void
    {
        ?>
        <div class="wrap">
            <h1 style="margin-bottom: 20px;">Add Update Products</h1>
            <form method="post" action="">
                <div style="margin-bottom: 15px;">
                    <label for="username" style="display: block; margin-bottom: 5px;">Username:</label>
                    <input type="text" name="username" value="<?php echo esc_attr($this->username); ?>" style="width: 400px;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label for="password" style="display: block; margin-bottom: 5px;">Password:</label>
                    <input type="password" name="password" value="<?php echo esc_attr($this->password); ?>" style="width: 400px;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label for="base_api_path" style="display: block; margin-bottom: 5px;">API Endpoint: <small>(without /
                            at the end)</small></label>

                    <input type="text" name="base_api_path" value="<?php echo esc_attr($this->base_api_endpoint_path); ?>"
                           style="width: 400px;">
                </div>

                <?php
                if (!empty($this->base_api_endpoint_path)) {
                    ?>
                    <div style="margin-bottom: 15px;">
                        <p style="margin-bottom: 0;">API Endpoints in use:</p>
                        <small><?php echo $this->base_api_endpoint_path . '/logIn/' ?></small><br>
                        <small><?php echo $this->base_api_endpoint_path . '/proizvodi/' ?></small>
                    </div>
                    <?php
                }
                ?>

                <div style="margin-bottom: 15px; margin-top: 30px;">
                    <button type="submit" name="save_settings" style="cursor: pointer; padding: 8px 16px; font-size: 14px;">
                        Save Settings
                    </button>
                </div>

                <div style="margin-top: 80px;">
                    <button type="submit" name="run_add_update_products_function"
                            style="cursor: pointer; padding: 12px 24px; font-size: 16px; font-weight: bold; background-color: #007cba; color: #fff; border: none;">
                        Run Products Import
                    </button>
                </div>
            </form>
        </div>
        <?php

        global $hook_suffix;
        if ($hook_suffix === 'product_page_add-update-products') {
            ?>

            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function () {
                    setTimeout(function () {
                        let successNotices = document.querySelectorAll('.notice-to-remove');
                        let delay = 500; // Delay between hiding each notice in milliseconds
                        let counter = 0;

                        successNotices.forEach(function (notice) {
                            counter++;
                            setTimeout(function () {
                                notice.style.transition = 'opacity 1s';
                                notice.style.opacity = '0';
                                notice.addEventListener('transitionend', function () {
                                    notice.style.display = 'none';
                                });
                            }, counter * delay);
                        });
                    }, 2000);
                });
            </script>
        <?php }
    }

    public function render_go_back_btn(): void
    {
        echo '<button onclick="goBack()" style="cursor: pointer; padding: 12px 24px; font-size: 16px; font-weight: bold; background-color: #007cba; color: #fff; border: none;">
              Go Back
              </button>';

        global $hook_suffix;
        if ($hook_suffix === 'product_page_add-update-products') {
            ?>

            <script type="text/javascript">
                function goBack() {
                    window.history.back();
                }
            </script>
        <?php }
    }

    public function add_update_products_menu(): void
    {
        add_submenu_page(
                'edit.php?post_type=product', // Parent menu slug (WooCommerce "Products" menu)
                'Add Update Products', // Page title
                'Add Update Products', // Menu title
                'manage_options', // Capability required to access the menu item
                'add-update-products', // Menu slug
                array($this, 'add_update_products_page') // Callback function to render the page
            );
    }

    public function add_update_products_page(): void
    {
        if (isset($_POST['run_add_update_products_function'])) {
            $this->run_add_update_products();
        } else {
            $this->display_form_and_button();
        }
    }
}

$add_update_products_plugin = new Add_Update_Products_Plugin();
add_action('admin_menu', array($add_update_products_plugin, 'add_update_products_menu'));
add_action('admin_init', array($add_update_products_plugin, 'save_settings'));
