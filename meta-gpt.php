<?php
/**
 * Plugin Name: Meta GPT Generator
 * Plugin URI: https://github.com/beuzathor/meta-gpt
 * Description: Génère et met à jour les meta titles et descriptions avec ChatGPT
 * Version: 1.1
 * Author: beuzathor
 * Author URI: https://github.com/beuzathor/meta-gpt
 * License: GPL-2.0+
 */

// Sécurité
defined('ABSPATH') or die('Access denied');

// Ajouter le menu admin
add_action('admin_menu', 'meta_gpt_menu');
function meta_gpt_menu() {
    add_menu_page(
        'Meta GPT Generator',
        'Meta GPT',
        'manage_options',
        'meta-gpt',
        'meta_gpt_page',
        'dashicons-admin-generic'
    );
}

// Ajouter les options du plugin
add_action('admin_init', 'meta_gpt_settings');
function meta_gpt_settings() {
    register_setting('meta-gpt-settings', 'meta_gpt_api_key');
    register_setting('meta-gpt-settings', 'meta_gpt_auto_generate');
}

// Fonction simplifiée pour sauvegarder les meta
function update_seo_meta($post_id, $meta_title, $meta_desc) {
    update_post_meta($post_id, '_meta_gpt_title', $meta_title);
    update_post_meta($post_id, '_meta_gpt_description', $meta_desc);
}

// Ajout des meta tags dans le head
add_action('wp_head', 'add_meta_tags_to_head', 1);
function add_meta_tags_to_head() {
    if (is_singular()) { // Pour les articles et pages
        global $post;
        $meta_title = get_post_meta($post->ID, '_meta_gpt_title', true);
        $meta_desc = get_post_meta($post->ID, '_meta_gpt_description', true);

        if (!empty($meta_title)) {
            echo '<title>' . esc_html($meta_title) . '</title>' . "\n";
            echo '<meta property="og:title" content="' . esc_attr($meta_title) . '" />' . "\n";
            echo '<meta name="twitter:title" content="' . esc_attr($meta_title) . '" />' . "\n";
        }

        if (!empty($meta_desc)) {
            echo '<meta name="description" content="' . esc_attr($meta_desc) . '" />' . "\n";
            echo '<meta property="og:description" content="' . esc_attr($meta_desc) . '" />' . "\n";
            echo '<meta name="twitter:description" content="' . esc_attr($meta_desc) . '" />' . "\n";
        }
    }
}

// Retirer le title par défaut de WordPress
remove_action('wp_head', '_wp_render_title_tag', 1);

// Page d'administration
function meta_gpt_page() {
    ?>
    <div class="wrap">
        <h1>Meta GPT Generator</h1>

        <!-- Formulaire de configuration -->
        <form method="post" action="options.php">
            <?php settings_fields('meta-gpt-settings'); ?>
            <table class="form-table">
                <tr>
                    <th>Clé API ChatGPT</th>
                    <td>
                        <input type="text" name="meta_gpt_api_key"
                               value="<?php echo esc_attr(get_option('meta_gpt_api_key')); ?>"
                               class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th>Génération automatique</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="meta_gpt_auto_generate"
                                   value="1"
                                <?php checked(get_option('meta_gpt_auto_generate', '1'), '1'); ?>>
                            Générer automatiquement les meta tags à la publication
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <!-- Liste des articles -->
        <h2>Articles</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
            <tr>
                <th>Titre</th>
                <th>Meta Title</th>
                <th>Meta Description</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $posts = get_posts(['posts_per_page' => -1]);
            foreach ($posts as $post) {
                $meta_title = get_post_meta($post->ID, '_meta_gpt_title', true);
                $meta_desc = get_post_meta($post->ID, '_meta_gpt_description', true);
                ?>
                <tr>
                    <td><?php echo esc_html($post->post_title); ?></td>
                    <td><?php echo esc_html($meta_title); ?></td>
                    <td><?php echo esc_html($meta_desc); ?></td>
                    <td>
                        <button class="button generate-meta"
                                data-post-id="<?php echo $post->ID; ?>">
                            Générer Meta
                        </button>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
    </div>

    <script>
        jQuery(document).ready(function($) {
            $('.generate-meta').click(function(e) {
                e.preventDefault();
                const button = $(this);
                const row = button.closest('tr');
                const postId = button.data('post-id');

                button.prop('disabled', true);
                button.text('Génération...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'generate_meta',
                        post_id: postId,
                        nonce: '<?php echo wp_create_nonce("meta_gpt_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            row.find('td:nth-child(2)').text(response.data.title);
                            row.find('td:nth-child(3)').text(response.data.description);
                        } else {
                            alert('Erreur: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('Erreur de communication avec le serveur');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                        button.text('Générer Meta');
                    }
                });
            });
        });
    </script>
    <?php
}

// Traitement AJAX pour la génération manuelle
add_action('wp_ajax_generate_meta', 'generate_meta_ajax');
function generate_meta_ajax() {
    check_ajax_referer('meta_gpt_nonce', 'nonce');

    $post_id = intval($_POST['post_id']);
    $post = get_post($post_id);

    if (!$post) {
        wp_send_json_error('Article non trouvé');
    }

    $api_key = get_option('meta_gpt_api_key');
    if (!$api_key) {
        wp_send_json_error('Clé API non configurée');
    }

    $content = wp_strip_all_tags($post->post_title . "\n\n" . $post->post_content);
    $content = substr($content, 0, 1500);

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "Tu es un expert SEO. Génère un meta title et une meta description optimisés pour le référencement.
                    Pour le title : entre 50 et 60 caractères, inclure les mots-clés importants.
                    Pour la description : entre 145 et 160 caractères, donner envie de cliquer.
                    
                    Réponds UNIQUEMENT avec ce format exact :
                    TITLE: [meta title]
                    DESC: [meta description]"
                ],
                [
                    'role' => 'user',
                    'content' => $content
                ]
            ],
            'temperature' => 0.7
        ])
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('Erreur API: ' . $response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body['choices'][0]['message']['content'])) {
        wp_send_json_error('Réponse API invalide');
    }

    $content = $body['choices'][0]['message']['content'];

    if (preg_match('/TITLE:\s*(.*?)\s*(?:\n|$)/i', $content, $title_matches) &&
        preg_match('/DESC(?:RIPTION)?:\s*(.*?)(?:\n|$)/i', $content, $desc_matches)) {

        $meta_title = trim($title_matches[1]);
        $meta_desc = trim($desc_matches[1]);

        update_seo_meta($post_id, $meta_title, $meta_desc);

        wp_send_json_success([
            'title' => $meta_title,
            'description' => $meta_desc
        ]);
    } else {
        wp_send_json_error('Format de réponse non reconnu');
    }
}

// Hook pour la génération automatique à la publication
add_action('transition_post_status', 'auto_generate_meta_on_publish', 10, 3);
function auto_generate_meta_on_publish($new_status, $old_status, $post) {
    if (get_option('meta_gpt_auto_generate', '1') !== '1') {
        return;
    }

    if ($new_status === 'publish' && $old_status !== 'publish' &&
        in_array($post->post_type, ['post', 'page'])) {

        $api_key = get_option('meta_gpt_api_key');
        if (empty($api_key)) {
            return;
        }

        $content = wp_strip_all_tags($post->post_title . "\n\n" . $post->post_content);
        $content = substr($content, 0, 1500);

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "Tu es un expert SEO. Je vais te donner le contenu d'un article. 
                    Tu dois générer :
                    1. Un meta title qui inclut les mots-clés principaux
                    2. Une meta description qui donne envie de cliquer
                    
                    IMPORTANT : Le but est de faire un titre et une meta description qui fasse cliquer au maximum.
                    
                    Réponds UNIQUEMENT avec ce format exact :
                    TITLE: [meta title]
                    DESC: [meta description]"
                    ],
                    [
                        'role' => 'user',
                        'content' => $content
                    ]
                ],
                'temperature' => 0.7
            ])
        ]);

        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (!empty($body['choices'][0]['message']['content'])) {
                $content = $body['choices'][0]['message']['content'];

                if (preg_match('/TITLE:\s*(.*?)\s*(?:\n|$)/i', $content, $title_matches) &&
                    preg_match('/DESC(?:RIPTION)?:\s*(.*?)(?:\n|$)/i', $content, $desc_matches)) {

                    update_seo_meta($post->ID, trim($title_matches[1]), trim($desc_matches[1]));
                }
            }
        }
    }
}