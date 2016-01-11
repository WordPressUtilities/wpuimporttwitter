<?php

/*
Plugin Name: WPU Import Twitter
Plugin URI: http://github.com/Darklg/WPUtilities
Version: 0.7.1
Description: Twitter Import
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
Required plugins: WPU Post Types & Taxos
*/

class WPUImportTwitter {
    private $debug = false;
    private $messages = array();

    function __construct() {
        add_action('plugins_loaded', array(&$this,
            'load_plugin_textdomain'
        ));
        add_action('init', array(&$this,
            'set_options'
        ));
        add_action('init', array(&$this,
            'init'
        ));
    }

    function load_plugin_textdomain() {
        load_plugin_textdomain('wpuimporttwitter', false, dirname(plugin_basename(__FILE__)) . '/lang/');
    }

    function init() {

        add_filter('wputh_get_posttypes', array(&$this,
            'create_posttypes'
        ));

        if (!is_admin()) {
            return;
        }

        // Display notices
        global $current_user;
        $this->transient_msg = $current_user->ID . $this->options['plugin_id'];
        add_action('wpuimporttwitter_admin_notices', array(&$this,
            'admin_notices'
        ));

        // Settings & admin
        add_action('admin_menu', array(&$this,
            'admin_menu'
        ));
        add_action('admin_init', array(&$this,
            'add_settings'
        ));
        add_filter("plugin_action_links_" . plugin_basename(__FILE__) , array(&$this,
            'add_settings_link'
        ));
        add_action('admin_post_wpuimporttwitter_postaction', array(&$this,
            'postAction'
        ));
    }

    function set_options() {
        $this->options = array(
            'plugin_publicname' => 'Twitter Import',
            'plugin_name' => 'Twitter Import',
            'plugin_userlevel' => 'manage_options',
            'plugin_id' => 'wpuimporttwitter',
            'plugin_pageslug' => 'wpuimporttwitter',
        );
        $this->settings_details = array(
            'option_id' => 'wpuimporttwitter_options',
            'sections' => array(
                'import' => array(
                    'name' => __('Import Settings', 'wpuimporttwitter')
                ) ,
                'oauth' => array(
                    'name' => __('Oauth Settings', 'wpuimporttwitter')
                )
            )
        );
        $this->settings_values = get_option($this->settings_details['option_id']);

        $this->options['admin_url'] = admin_url('edit.php?post_type=tweet&page=' . $this->options['plugin_id']);
        $this->settings = array(
            'screen_name' => array(
                'label' => __('Screen name', 'wpuimporttwitter')
            ) ,
            'include_rts' => array(
                'label' => __('Include RTs', 'wpuimporttwitter') ,
                'label_check' => __('Include retweets from other accounts by this user.', 'wpuimporttwitter') ,
                'type' => 'checkbox'
            ) ,
            'include_replies' => array(
                'label' => __('Include Replies', 'wpuimporttwitter') ,
                'label_check' => __('Include replies to other accounts by this user.', 'wpuimporttwitter') ,
                'type' => 'checkbox'
            ) ,
            'import_draft' => array(
                'label' => __('Import as Draft', 'wpuimporttwitter') ,
                'label_check' => __('Import tweets as Drafts, to allow moderation.', 'wpuimporttwitter') ,
                'type' => 'checkbox'
            ) ,
            'hide_front' => array(
                'label' => __('Hide on front', 'wpuimporttwitter') ,
                'label_check' => __('Display tweets only in the admin.', 'wpuimporttwitter') ,
                'type' => 'checkbox'
            ) ,
            'oauth_access_token' => array(
                'section' => 'oauth',
                'label' => __('Access token', 'wpuimporttwitter') ,
            ) ,
            'oauth_access_token_secret' => array(
                'section' => 'oauth',
                'label' => __('Access token secret', 'wpuimporttwitter') ,
            ) ,
            'consumer_key' => array(
                'section' => 'oauth',
                'label' => __('Consumer key', 'wpuimporttwitter') ,
            ) ,
            'consumer_secret' => array(
                'section' => 'oauth',
                'label' => __('Consumer secret', 'wpuimporttwitter') ,
            ) ,
        );
    }

    function create_posttypes($post_types) {
        $post_types['tweet'] = array(
            'menu_icon' => 'dashicons-twitter',
            'name' => 'Tweet',
            'plural' => 'Tweets',
            'female' => 0,
            'wputh__hide_front' => (isset($this->settings_values['hide_front']) && $this->settings_values['hide_front'] == '1')
        );
        return $post_types;
    }

    function import() {

        // Get last tweets from Twitter
        $last_tweets = $this->get_last_tweets_for_user();

        // Get ids from last imported tweets
        $imported_tweets_ids = $this->get_last_imported_tweets_ids();

        $nb_imports = 0;

        // Exclude tweets already imported
        foreach ($last_tweets as $tweet) {
            if (!in_array($tweet['id'], $imported_tweets_ids)) {

                // Create a post for each new tweet
                $post_id = $this->create_post_from_tweet($tweet);
                if (is_numeric($post_id) && $post_id > 0) {
                    $nb_imports++;
                }
            }
        }

        return $nb_imports;
    }

    function get_last_imported_tweets_ids() {
        $ids = array();
        $posts = get_posts(array(
            'posts_per_page' => 100,
            'post_type' => 'tweet',
            'post_status' => array(
                'publish',
                'pending',
                'draft',
                'future',
                'private',
                'inherit'
            )
        ));
        foreach ($posts as $tweet) {
            $id = get_post_meta($tweet->ID, 'wpuimporttwitter_id', 1);
            if (is_numeric($id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    function get_last_tweets_for_user($screen_name = false) {
        $settings = get_option($this->settings_details['option_id']);
        if (!$this->test_correct_oauth_values()) {
            return false;
        }

        if ($screen_name == false) {
            $screen_name = $settings['screen_name'];
        }

        /* Based on http://stackoverflow.com/a/16169848 by @budidino */

        $twitter_url = 'https://api.twitter.com/1.1/statuses/user_timeline.json';

        // Create request
        $request = array(
            'screen_name' => $screen_name,
            'contributor_details' => 'false',
            'count' => 50
        );

        $request['exclude_replies'] = ($settings['include_replies'] != 1) ? 'true' : 'false';
        $request['include_rts'] = ($settings['include_rts'] == 1) ? 'true' : 'false';

        $oauth = array(
            'oauth_consumer_key' => $settings['consumer_key'],
            'oauth_nonce' => md5(mt_rand()) ,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => time() ,
            'oauth_token' => $settings['oauth_access_token'],
            'oauth_version' => '1.0'
        );

        $oauth = array_merge($oauth, $request);

        $base_info = $this->buildBaseString($twitter_url, 'GET', $oauth);
        $composite_key = rawurlencode($settings['consumer_secret']) . '&' . rawurlencode($settings['oauth_access_token_secret']);
        $oauth_signature = base64_encode(hash_hmac('sha1', $base_info, $composite_key, true));
        $oauth['oauth_signature'] = $oauth_signature;

        //  make request
        $options = array(
            CURLOPT_HTTPHEADER => array(
                $this->buildAuthorizationHeader($oauth) ,
                'Expect:'
            ) ,
            CURLOPT_HEADER => false,
            CURLOPT_URL => $twitter_url . '?' . http_build_query($request) ,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        );

        $debug_file = ABSPATH . '/tweets.txt';

        $response = false;
        if ($this->debug && file_exists($debug_file)) {
            error_log('Using debug file');
            $response = file_get_contents($debug_file);
        }

        if (!$response) {
            $feed = curl_init();
            curl_setopt_array($feed, $options);
            $response = curl_exec($feed);
            curl_close($feed);
            if ($this->debug) {
                error_log('Caching in debug file');
                file_put_contents($debug_file, $response);
            }
        }

        return $this->get_tweets_from_response($response);
    }

    function test_correct_oauth_values() {
        $settings = get_option($this->settings_details['option_id']);
        if (!is_array($settings)) {
            return false;
        }
        $test_settings_ids = array(
            'oauth_access_token',
            'oauth_access_token_secret',
            'consumer_key',
            'consumer_secret',
            'screen_name'
        );
        foreach ($test_settings_ids as $id) {
            if (!isset($settings[$id]) || empty($settings[$id])) {
                return false;
            }
        }
        return true;
    }

    function get_tweets_from_response($json_response) {
        $tweets = array();
        $response = json_decode($json_response);
        if (!is_array($response)) {
            return $response;
        }
        foreach ($response as $tweet) {
            if (!isset($tweet->text)) {
                continue;
            }
            $tweets[$tweet->id] = array(
                'id' => $tweet->id,
                'text' => $tweet->text,
                'screen_name' => $tweet->user->screen_name,
                'time' => strtotime($tweet->created_at) ,
                'entities' => $tweet->entities,
            );
        }
        return $tweets;
    }

    function create_post_from_tweet($tweet) {

        $tweet_text = $this->apply_entities($tweet['text'], $tweet['entities']);
        $tweet_title = substr(strip_tags($tweet_text) , 0, 50);

        $medias = array();
        if (property_exists($tweet['entities'], 'media') && count($tweet['entities']->media) > 0) {
            foreach ($tweet['entities']->media as $media) {
                if ($media->type == 'photo') {
                    $medias[] = $media->media_url;
                }
            }
        }

        $settings = get_option($this->settings_details['option_id']);

        $post_status = 'publish';
        if (is_array($settings) && isset($settings['import_draft']) && $settings['import_draft'] == '1') {
            $post_status = 'draft';
        }

        $tweet_post = array(
            'post_title' => $tweet_title,
            'post_content' => $tweet_text,
            'post_date' => date('Y-m-d H:i:s', $tweet['time']) ,
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => 'tweet'
        );

        // Insert the post into the database
        $post_id = wp_insert_post($tweet_post);

        // Store metas
        add_post_meta($post_id, 'wpuimporttwitter_id', $tweet['id']);
        add_post_meta($post_id, 'wpuimporttwitter_screen_name', $tweet['screen_name']);
        add_post_meta($post_id, 'wpuimporttwitter_original_url', 'https://twitter.com/statuses/' . $tweet['id']);
        add_post_meta($post_id, 'wpuimporttwitter_original_tweet', $tweet['text']);

        $this->import_medias($post_id, $medias);

        return $post_id;
    }

    function import_medias($post_id, $medias = array()) {
        if (empty($medias)) {
            return;
        }

        // Add required classes
        require_once (ABSPATH . 'wp-admin/includes/media.php');
        require_once (ABSPATH . 'wp-admin/includes/file.php');
        require_once (ABSPATH . 'wp-admin/includes/image.php');

        // Upload medias
        foreach ($medias as $media) {
            $image = media_sideload_image($media, $post_id);
        }

        // then find the last image added to the post attachments
        $attachments = get_posts(array(
            'numberposts' => 1,
            'post_parent' => $post_id,
            'post_type' => 'attachment',
            'post_mime_type' => 'image'
        ));

        if (sizeof($attachments) > 0) {
            set_post_thumbnail($post_id, $attachments[0]->ID);
        }
    }

    function apply_entities($text, $entities) {

        // Urls
        if (!empty($entities->urls)) {
            foreach ($entities->urls as $url) {
                $text = str_replace($url->url, '<a class="twitter-link" href="' . $url->expanded_url . '">' . $url->display_url . '</a>', $text);
            }
        }

        // Hashtags
        if (!empty($entities->hashtags)) {
            foreach ($entities->hashtags as $hashtag) {
                $text = str_ireplace('#' . $hashtag->text, '<a class="twitter-hashtags" href="https://twitter.com/hashtag/' . $hashtag->text . '?src=hash">#' . $hashtag->text . '</a>', $text);
            }
        }

        // Users
        if (!empty($entities->user_mentions)) {
            foreach ($entities->user_mentions as $user_mention) {
                $text = str_ireplace('@' . $user_mention->screen_name, '<a class="twitter-users" href="https://twitter.com/' . $user_mention->screen_name . '" title="' . $user_mention->name . '">@' . $user_mention->screen_name . '</a>', $text);
            }
        }

        // Medias
        if (!empty($entities->media)) {
            foreach ($entities->media as $media) {
                $text = str_replace($media->url, '<a class="twitter-link" href="' . $media->expanded_url . '">' . $media->display_url . '</a>', $text);
            }
        }

        return $text;
    }

    /* ----------------------------------------------------------
      Admin
    ---------------------------------------------------------- */

    /* Settings link */

    function add_settings_link($links) {
        $settings_link = '<a href="' . $this->options['admin_url'] . '">' . __('Settings') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /* Menu */

    function admin_menu() {
        add_submenu_page('edit.php?post_type=tweet', $this->options['plugin_name'] . ' - ' . __('Settings') , __('Import settings', 'wpuimporttwitter') , $this->options['plugin_userlevel'], $this->options['plugin_pageslug'], array(&$this,
            'admin_settings'
        ) , '', 110);
    }

    /* Settings */

    function postAction() {

        if (isset($_POST['import_now'])) {
            $nb_imports = $this->import();
            if ($nb_imports > 0) {
                $this->set_message(sprintf(__('Imported tweets : %s', 'wpuimporttwitter') , $nb_imports));
            }
            else {
                $this->set_message(__('No new imports', 'wpuimporttwitter') , 'created');
            }
        }

        if (isset($_POST['test_api'])) {

            $last_tweets = $this->get_last_tweets_for_user();

            if (is_array($last_tweets) && !empty($last_tweets)) {
                $this->set_message(__('The API works great !', 'wpuimporttwitter') , 'created');
            }
            else {
                $this->set_message(__('The credentials seems invalid or the user never tweeted.', 'wpuimporttwitter') , 'error');
            }
        }
        flush_rewrite_rules();
        wp_safe_redirect(wp_get_referer());
        die();
    }

    function admin_settings() {

        echo '<div class="wrap"><h1>' . get_admin_page_title() . '</h1>';
        do_action('wpuimporttwitter_admin_notices');
        echo '<hr />';

        if ($this->test_correct_oauth_values()) {

            echo '<h2>' . __('Tools') . '</h2>';
            echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
            echo '<input type="hidden" name="action" value="wpuimporttwitter_postaction">';
            $schedule = wp_next_scheduled('wpuimporttwitter__cron_hook');
            $seconds = $schedule - time();
            if ($seconds >= 60) {
                $minutes = (int)($seconds / 60);
                $seconds = $seconds % 60;
            }
            echo '<p>' . sprintf(__('Next automated import in %s’%s’’', 'wpuimporttwitter') , $minutes, $seconds) . '</p>';

            echo '<p class="submit">';
            submit_button(__('Import now', 'wpuimporttwitter') , 'primary', 'import_now', false);
            echo ' ';
            submit_button(__('Test API', 'wpuimporttwitter') , 'primary', 'test_api', false);
            echo '</p>';
            echo '</form>';
            echo '<hr />';
        }

        echo '<h2>' . __('Settings') . '</h2>';
        echo '<form action="' . admin_url('options.php') . '" method="post">';
        settings_fields($this->settings_details['option_id']);
        do_settings_sections($this->options['plugin_id']);
        echo submit_button(__('Save Changes', 'wpuimporttwitter'));
        echo '</form>';
        echo '</div>';
    }

    /* ----------------------------------------------------------
      Plugin Settings
    ---------------------------------------------------------- */

    function add_settings() {
        register_setting($this->settings_details['option_id'], $this->settings_details['option_id'], array(&$this,
            'options_validate'
        ));
        $default_section = key($this->settings_details['sections']);
        foreach ($this->settings_details['sections'] as $id => $section) {
            add_settings_section($id, $section['name'], '', $this->options['plugin_id']);
        }

        foreach ($this->settings as $id => $input) {
            $label = isset($input['label']) ? $input['label'] : '';
            $label_check = isset($input['label_check']) ? $input['label_check'] : '';
            $type = isset($input['type']) ? $input['type'] : 'text';
            $section = isset($input['section']) ? $input['section'] : $default_section;
            add_settings_field($id, $label, array(&$this,
                'render__field'
            ) , $this->options['plugin_id'], $section, array(
                'name' => $this->settings_details['option_id'] . '[' . $id . ']',
                'id' => $id,
                'label_for' => $id,
                'type' => $type,
                'label_check' => $label_check
            ));
        }
    }

    function options_validate($input) {
        $options = get_option($this->settings_details['option_id']);
        foreach ($this->settings as $id => $name) {
            $options[$id] = esc_html(trim($input[$id]));
        }
        return $options;
    }

    function render__field($args = array()) {
        $options = get_option($this->settings_details['option_id']);
        $label_check = isset($args['label_check']) ? $args['label_check'] : '';
        $type = isset($args['type']) ? $args['type'] : 'text';
        $name = ' name="wpuimporttwitter_options[' . $args['id'] . ']" ';
        $id = ' id="' . $args['id'] . '" ';

        switch ($type) {
            case 'checkbox':
                echo '<label><input type="checkbox" ' . $name . ' ' . $id . ' ' . checked($options[$args['id']], '1', 0) . ' value="1" /> ' . $label_check . '</label>';
            break;
            default:
                echo '<input ' . $name . ' ' . $id . ' type="' . $type . '" value="' . esc_attr($options[$args['id']]) . '" />';
        }
    }

    /* ----------------------------------------------------------
      Twitter API Utils
    ---------------------------------------------------------- */

    function buildBaseString($baseURI, $method, $params) {
        $r = array();
        ksort($params);
        foreach ($params as $key => $value) {
            $r[] = "$key=" . rawurlencode($value);
        }
        return $method . "&" . rawurlencode($baseURI) . '&' . rawurlencode(implode('&', $r));
    }

    function buildAuthorizationHeader($oauth) {
        $r = 'Authorization: OAuth ';
        $values = array();
        foreach ($oauth as $key => $value) $values[] = "$key=\"" . rawurlencode($value) . "\"";
        $r.= implode(', ', $values);
        return $r;
    }

    /* ----------------------------------------------------------
      Messages
    ---------------------------------------------------------- */

    /* Set notices messages */
    private function set_message($message, $group = false) {
        $groups = array(
            'updated',
            'error'
        );
        if (!in_array($group, $groups)) {
            $group = $groups[0];
        }
        $messages = (array)get_transient($this->transient_msg);

        $messages[$group][] = $message;
        set_transient($this->transient_msg, $messages);
    }

    /* Display notices */
    function admin_notices() {
        $messages = (array)get_transient($this->transient_msg);
        if (!empty($messages)) {
            foreach ($messages as $group_id => $group) {
                if (is_array($group)) {
                    foreach ($group as $message) {
                        echo '<div class="' . $group_id . '"><p>' . $message . '</p></div>';
                    }
                }
            }
        }

        // Empty messages
        delete_transient($this->transient_msg);
    }

    /* ----------------------------------------------------------
      Activation
    ---------------------------------------------------------- */

    function install() {
        wp_schedule_event(time() , 'hourly', 'wpuimporttwitter__cron_hook');
        flush_rewrite_rules();
    }

    function deactivation() {
        wp_clear_scheduled_hook('wpuimporttwitter__cron_hook');
        flush_rewrite_rules();
    }

    /* ----------------------------------------------------------
      Uninstall
    ---------------------------------------------------------- */

    function uninstall() {
        delete_option($this->settings_details['option_id']);
        delete_post_meta_by_key('wpuimporttwitter_id');
        delete_post_meta_by_key('wpuimporttwitter_screen_name');
        delete_post_meta_by_key('wpuimporttwitter_original_url');
        delete_post_meta_by_key('wpuimporttwitter_original_tweet');
        flush_rewrite_rules();
    }
}

$WPUImportTwitter = new WPUImportTwitter();

register_activation_hook(__FILE__, array(&$WPUImportTwitter,
    'install'
));
register_deactivation_hook(__FILE__, array(&$WPUImportTwitter,
    'deactivation'
));

add_action('wpuimporttwitter__cron_hook', 'wpuimporttwitter__import');
function wpuimporttwitter__import() {
    global $WPUImportTwitter;
    $WPUImportTwitter->set_options();
    $WPUImportTwitter->import();
}
