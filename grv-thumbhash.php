<?php
/**
 * Plugin Name: GRV ThumbHash
 * Plugin URI: https://github.com/General-RV/GRV-ThumbHash
 * GitHub Plugin URI: https://github.com/General-RV/GRV-ThumbHash
 * Description: A WordPress plugin for generating ThumbHash values
 * Author: GeneralRV
 * Author URI: https://github.com/General-RV
 * Update URI: https://github.com/General-RV/GRV-ThumbHash
 * Version: 0.1.0
 * Requires at least: 6.0
 * Tested up to: 6.6
 * Requires Imagick: true
 * Requires Plugins: wp-graphql
 * WPGraphQL requires at least: 1.14.0
 * License: GPL-3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 */

if (!defined('ABSPATH')) {
    exit;
}

class GRV_ThumbHash {
    public function __construct() {
        // Debug hook registration
        error_log('GRV_ThumbHash constructor called');
        
        // Image processing hooks
        add_action('add_attachment', array($this, 'process_new_image'));
        
        // Admin UI hooks
        add_filter('attachment_fields_to_edit', array($this, 'add_thumbhash_field'), 10, 2);
        
        // GraphQL hooks
        add_action('graphql_register_types', array($this, 'register_graphql_fields'));
    }

    public function process_new_image($attachment_id) {
        error_log('Processing attachment: ' . $attachment_id);
        
        if (!wp_attachment_is_image($attachment_id)) {
            error_log('Not an image, skipping');
            return;
        }

        try {
            require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
            
            $file_path = get_attached_file($attachment_id);
            error_log('Processing image: ' . $file_path);
            
            if (!file_exists($file_path)) {
                throw new Exception('File not found: ' . $file_path);
            }

            // Get image content
            $content = file_get_contents($file_path);
            
            // Process with Imagick
            $imagick = new Imagick();
            $imagick->readImageBlob($content);
            $imagick->resizeImage(100, 100, Imagick::FILTER_LANCZOS, 1);
            
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            
            // Get RGBA pixels
            $pixels = [];
            $iterator = $imagick->getPixelIterator();
            foreach ($iterator as $row => $pixels_row) {
                foreach ($pixels_row as $pixel) {
                    $color = $pixel->getColor();
                    $pixels[] = $color['r'];
                    $pixels[] = $color['g'];
                    $pixels[] = $color['b'];
                    $pixels[] = $color['a'] ?? 255;
                }
            }

            // Generate thumbhash
            $hash = \Thumbhash\Thumbhash::RGBAToHash($width, $height, $pixels);
            $thumbhash = \Thumbhash\Thumbhash::convertHashToString($hash);
            $preview_url = \Thumbhash\Thumbhash::toDataURL($hash);

            error_log('Generated thumbhash: ' . $thumbhash);

            // Store the results
            update_post_meta($attachment_id, 'thumbhash', $thumbhash);
            update_post_meta($attachment_id, 'thumbhash_preview', $preview_url);

        } catch (Exception $e) {
            error_log('Thumbhash Error: ' . $e->getMessage());
            update_post_meta($attachment_id, 'thumbhash_error', $e->getMessage());
        }
    }

    public function add_thumbhash_field($form_fields, $post) {
        error_log('Adding thumbhash field for attachment: ' . $post->ID);
        
        if (!wp_attachment_is_image($post->ID)) {
            return $form_fields;
        }

        $thumbhash = get_post_meta($post->ID, 'thumbhash', true);
        $preview_url = get_post_meta($post->ID, 'thumbhash_preview', true);
        $error = get_post_meta($post->ID, 'thumbhash_error', true);

        $html = '<div class="thumbhash-field">';
        
        if ($error) {
            $html .= '<p style="color: red;">Error: ' . esc_html($error) . '</p>';
        } elseif ($thumbhash) {
            if ($preview_url) {
                $html .= '<img src="' . esc_attr($preview_url) . '" style="width: 100px; height: 100px; object-fit: cover;" /><br>';
            }
            $html .= '<code>' . esc_html($thumbhash) . '</code>';
        } else {
            $html .= '<p>No thumbhash generated yet.</p>';
        }
        
        $html .= '</div>';

        $form_fields['thumbhash'] = array(
            'label' => 'Thumbhash',
            'input' => 'html',
            'html'  => $html
        );

        return $form_fields;
    }

    public function register_graphql_fields() {
        error_log('Registering GraphQL fields');
        
        if (!function_exists('register_graphql_field')) {
            error_log('WPGraphQL plugin not active');
            return;
        }

        register_graphql_field('MediaItem', 'thumbhash', [
            'type' => 'String',
            'description' => 'Thumbhash code for the image',
            'resolve' => function($post) {
                error_log('Resolving thumbhash for: ' . $post->ID);
                return get_post_meta($post->ID, 'thumbhash', true);
            }
        ]);

        register_graphql_field('MediaItem', 'thumbhashPreview', [
            'type' => 'String',
            'description' => 'Thumbhash preview URL',
            'resolve' => function($post) {
                return get_post_meta($post->ID, 'thumbhash_preview', true);
            }
        ]);
    }
}

// Initialize plugin
add_action('init', function() {
    error_log('Initializing GRV_ThumbHash plugin');
    new GRV_ThumbHash();
});