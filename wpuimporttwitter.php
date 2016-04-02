<?php

/*
Plugin Name: WPU Import Twitter
Plugin URI: https://github.com/WordPressUtilities/wpuimporttwitter
Version: 1.5.1
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
    public $cronhook = 'wpuimporttwitter__cron_hook';

    public function __construct() {
        $this->cron_interval = apply_filters('wpuimporttwitter_croninterval', 1800);
        $this->post_type = apply_filters('wpuimporttwitter_posttypehook', 'tweet');

        add_action('plugins_loaded', array(&$this,
            'load_plugin_textdomain'
        ));
        add_action('init', array(&$this,
            'set_options'
        ));
        add_action('init', array(&$this,
            'init'
        ));
        add_action('init', array(&$this,
            'check_cron'
        ));
        add_filter('cron_schedules', array(&$this,
            'add_schedule'
        ));
        add_action($this->cronhook, array(&$this,
            'callback_cron'
        ));
    }

    public function load_plugin_textdomain() {
        load_plugin_textdomain('wpuimporttwitter', false, dirname(plugin_basename(__FILE__)) . '/lang/');
    }

    public function init() {
        add_filter('wputh_get_posttypes', array(&$this,
            'create_posttypes'
        ), 10, 1);
        add_filter('wputh_get_taxonomies', array(&$this,
            'create_taxonomies'
        ), 10, 1);
        add_filter('wputh_post_metas_boxes', array(&$this,
            'post_meta_boxes'
        ), 10, 1);
        add_filter('wputh_post_metas_fields', array(&$this,
            'post_meta_fields'
        ), 10, 1);
        add_filter('wputh_post_metas_admin_column_content_callback', array(&$this,
            'post_meta_column_callback'
        ), 10, 5);

        if (!is_admin()) {
            return;
        }

        // Settings & admin
        add_action('admin_menu', array(&$this,
            'admin_menu'
        ));
        add_filter("plugin_action_links_" . plugin_basename(__FILE__), array(&$this,
            'add_settings_link'
        ));
        add_action('admin_post_wpuimporttwitter_postaction', array(&$this,
            'postAction'
        ));
    }

    public function set_options() {

        $this->options = array(
            'plugin_publicname' => 'Twitter Import',
            'plugin_name' => 'Twitter Import',
            'plugin_minusercap' => 'moderate_comments',
            'plugin_usercap' => 'manage_options',
            'plugin_id' => 'wpuimporttwitter',
            'plugin_pageslug' => 'wpuimporttwitter'
        );
        // Messages
        if (is_admin()) {
            include 'inc/WPUBaseMessages.php';
            $this->messages = new \wpuimporttwitter\WPUBaseMessages($this->options['plugin_id']);
        }
        // Settings
        $this->settings_details = array(
            'plugin_id' => 'wpuimporttwitter',
            'option_id' => 'wpuimporttwitter_options',
            'user_cap' => $this->options['plugin_minusercap'],
            'sections' => array(
                'import' => array(
                    'user_cap' => 'moderate_comments',
                    'name' => __('Import Settings', 'wpuimporttwitter')
                ),
                'oauth' => array(
                    'name' => __('Oauth Settings', 'wpuimporttwitter')
                )
            )
        );
        $this->settings = array(
            'sources' => array(
                'label' => __('Sources', 'wpuimporttwitter'),
                'help' => __('One #hashtag or one @user per line.', 'wpuimporttwitter'),
                'type' => 'textarea'
            ),
            'include_rts' => array(
                'label' => __('Include RTs', 'wpuimporttwitter'),
                'label_check' => __('Include retweets from other accounts by this user.', 'wpuimporttwitter'),
                'type' => 'checkbox'
            ),
            'include_replies' => array(
                'label' => __('Include Replies', 'wpuimporttwitter'),
                'label_check' => __('Include replies to other accounts by this user.', 'wpuimporttwitter'),
                'type' => 'checkbox'
            ),
            'import_draft' => array(
                'label' => __('Import as Draft', 'wpuimporttwitter'),
                'label_check' => __('Import tweets as Drafts, to allow moderation.', 'wpuimporttwitter'),
                'type' => 'checkbox'
            ),
            'import_images' => array(
                'label' => __('Import images', 'wpuimporttwitter'),
                'label_check' => __('Import attached images.', 'wpuimporttwitter'),
                'type' => 'checkbox'
            ),
            'hide_front' => array(
                'label' => __('Hide on front', 'wpuimporttwitter'),
                'label_check' => __('Display tweets only in the admin.', 'wpuimporttwitter'),
                'type' => 'checkbox'
            ),
            'oauth_access_token' => array(
                'section' => 'oauth',
                'label' => __('Access token', 'wpuimporttwitter')
            ),
            'oauth_access_token_secret' => array(
                'section' => 'oauth',
                'label' => __('Access token secret', 'wpuimporttwitter')
            ),
            'consumer_key' => array(
                'section' => 'oauth',
                'label' => __('Consumer key', 'wpuimporttwitter')
            ),
            'consumer_secret' => array(
                'section' => 'oauth',
                'label' => __('Consumer secret', 'wpuimporttwitter')
            )
        );
        if (is_admin()) {
            include 'inc/WPUBaseSettings.php';
            new \wpuimporttwitter\WPUBaseSettings($this->settings_details, $this->settings);
        }
        $this->settings_values = get_option($this->settings_details['option_id']);
        // Admin URL
        $this->options['admin_url'] = admin_url('edit.php?post_type=' . $this->post_type . '&page=' . $this->options['plugin_id']);

    }

    public function create_posttypes($post_types) {
        $post_types[$this->post_type] = array(
            'menu_icon' => 'dashicons-twitter',
            'name' => 'Tweet',
            'plural' => 'Tweets',
            'female' => 0,
            'taxonomies' => array('twitter_tag'),
            'wputh__hide_front' => (isset($this->settings_values['hide_front']) && $this->settings_values['hide_front'] == '1')
        );
        return $post_types;
    }

    public function create_taxonomies($taxonomies) {
        $taxonomies['twitter_tag'] = array(
            'name' => __('Twitter tag', 'wputh'),
            'post_type' => $this->post_type,
            'hierarchical' => false
        );
        return $taxonomies;
    }

    public function import() {
        $settings = get_option($this->settings_details['option_id']);

        // Get ids from last imported tweets
        $imported_tweets_ids = $this->get_last_imported_tweets_ids();

        // Try to convert if old option model is used
        if (!isset($settings['sources']) && isset($settings['screen_name'])) {
            $settings['sources'] = '@' . $settings['screen_name'];
            $settings['screen_name'] = '';
            update_option($this->settings_details['option_id'], $settings);
        }

        $sources = $this->extract_sources($settings['sources']);
        $imported_tweets = 0;
        foreach ($sources as $source) {
            if ($source['type'] == 'user') {
                $last_tweets = $this->get_last_tweets_for_user($source['id']);
            }
            if ($source['type'] == 'tag') {
                $last_tweets = $this->get_last_tweets_for_tag($source['id']);
            }
            if (!isset($last_tweets) || !is_array($last_tweets)) {
                $last_tweets = array();
            }
            // Get last tweets from Twitter
            $imported_tweets += $this->import_last_tweets($last_tweets, $imported_tweets_ids);
        }

        return $imported_tweets;
    }

    public function import_last_tweets($last_tweets, $imported_tweets_ids) {
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

    public function extract_sources($sources) {
        $sources = str_replace(array(',', ';', ' '), "\n", $sources);
        $arr_sources = explode("\n", $sources);
        $final_sources = array();
        foreach ($arr_sources as $source) {
            $src = trim($source);
            if (empty($src)) {
                continue;
            }
            $type_source = '';
            if ($source[0] == '@') {
                $type_source = 'user';
            } else if ($source[0] == '#') {
                $type_source = 'tag';
            } else {
                continue;
            }
            if ($type_source != '') {
                $final_sources[] = array(
                    'type' => $type_source,
                    'id' => substr($src, 1)
                );
            }

        }

        return $final_sources;
    }

    public function get_last_imported_tweets_ids() {
        global $wpdb;
        return $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = 'wpuimporttwitter_id' ORDER BY meta_id DESC LIMIT 0,200");
    }

    public function get_last_tweets_for_tag($tag = false) {
        return $this->get_last_tweets_for(array(
            'q' => '#' . $tag . ' exclude:retweets'
        ));
    }

    public function get_last_tweets_for_user($screen_name = false) {
        $settings = get_option($this->settings_details['option_id']);
        $request = array(
            'q' => 'from:' . $screen_name
        );
        $request['q'] .= ($settings['include_replies'] != 1) ? ' exclude:replies' : ' include:replies';
        $request['q'] .= ($settings['include_rts'] != 1) ? ' exclude:retweets' : ' include:retweets';
        return $this->get_last_tweets_for($request);
    }

    public function get_last_tweets_for($request) {
        if (!$this->test_correct_oauth_values()) {
            return false;
        }

        // Create request
        $request['count'] = 40;
        $request['result_type'] = 'recent';

        return $this->get_tweets_from_request($request);
    }

    public function get_tweets_from_request($request) {
        /* Based on http://stackoverflow.com/a/16169848 by @budidino */
        $twitter_url = 'https://api.twitter.com/1.1/search/tweets.json';
        $settings = get_option($this->settings_details['option_id']);
        $oauth = array(
            'oauth_consumer_key' => $settings['consumer_key'],
            'oauth_nonce' => md5(mt_rand()),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => time(),
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
                $this->buildAuthorizationHeader($oauth),
                'Expect:'
            ),
            CURLOPT_HEADER => false,
            CURLOPT_URL => $twitter_url . '?' . http_build_query($request),
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

    public function test_correct_oauth_values() {
        $settings = get_option($this->settings_details['option_id']);
        if (!is_array($settings)) {
            return false;
        }
        $test_settings_ids = array(
            'oauth_access_token',
            'oauth_access_token_secret',
            'consumer_key',
            'consumer_secret'
        );
        foreach ($test_settings_ids as $id) {
            if (!isset($settings[$id]) || empty($settings[$id])) {
                return false;
            }
        }
        return true;
    }

    public function get_tweets_from_response($json_response) {
        $tweets = array();
        $response = json_decode($json_response);
        if (!is_object($response)) {
            return $response;
        }
        foreach ($response->statuses as $tweet) {
            if (!isset($tweet->text)) {
                continue;
            }

            $name = $tweet->user->name;
            $screen_name = $tweet->user->screen_name;
            $text = $tweet->text;
            $original_name = $tweet->user->name;
            $original_screen_name = $tweet->user->screen_name;
            $original_text = $tweet->text;
            $is_retweet = isset($tweet->retweeted_status);
            if ($is_retweet) {
                $original_name = $tweet->retweeted_status->user->name;
                $original_screen_name = $tweet->retweeted_status->user->screen_name;
                $original_text = $tweet->retweeted_status->text;
            }

            $tweets[$tweet->id_str] = array(
                'id' => $tweet->id_str,
                'text' => $tweet->text,
                'name' => $name,
                'screen_name' => $screen_name,
                'time' => strtotime($tweet->created_at),
                'entities' => $tweet->entities,
                'is_retweet' => $is_retweet,
                'is_reply' => isset($tweet->in_reply_to_status_id),
                /* Retweet */
                'original_name' => $original_name,
                'original_screen_name' => $original_screen_name,
                'original_text' => $original_text
            );

        }
        return $tweets;
    }

    public function create_post_from_tweet($tweet) {
        $tweet_text = $this->apply_entities($tweet['text'], $tweet['entities']);
        $original_tweet_text = $this->apply_entities($tweet['original_text'], $tweet['entities']);
        $tweet_title = substr(strip_tags($tweet_text), 0, 50);

        // Extract medias
        $medias = array();
        if (property_exists($tweet['entities'], 'media') && count($tweet['entities']->media) > 0) {
            foreach ($tweet['entities']->media as $media) {
                if ($media->type == 'photo') {
                    $medias[] = $media->media_url;
                }
            }
        }

        // Extract hashtags

        $hashtags = array();
        if (property_exists($tweet['entities'], 'hashtags') && count($tweet['entities']->hashtags) > 0) {
            foreach ($tweet['entities']->hashtags as $hashtag) {
                $hashtags[] = $hashtag->text;
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
            'post_date' => date('Y-m-d H:i:s', $tweet['time']),
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => $this->post_type
        );

        // Insert the post into the database
        $post_id = wp_insert_post($tweet_post);

        // Store hashtags
        if (!empty($hashtags)) {
            wp_set_post_terms($post_id, implode(',', $hashtags), 'twitter_tag', true);
        }

        // Store metas
        add_post_meta($post_id, 'wpuimporttwitter_id', $tweet['id']);
        add_post_meta($post_id, 'wpuimporttwitter_screen_name', $tweet['screen_name']);
        add_post_meta($post_id, 'wpuimporttwitter_name', $tweet['name']);
        add_post_meta($post_id, 'wpuimporttwitter_is_reply', $tweet['is_reply']);
        add_post_meta($post_id, 'wpuimporttwitter_is_retweet', $tweet['is_retweet']);
        add_post_meta($post_id, 'wpuimporttwitter_original_url', 'https://twitter.com/statuses/' . $tweet['id']);
        add_post_meta($post_id, 'wpuimporttwitter_original_tweet', $tweet['text']);
        add_post_meta($post_id, 'wpuimporttwitter_original_name', $tweet['original_name']);
        add_post_meta($post_id, 'wpuimporttwitter_original_screen_name', $tweet['original_screen_name']);
        add_post_meta($post_id, 'wpuimporttwitter_original_text', $tweet['original_text']);
        add_post_meta($post_id, 'wpuimporttwitter_original_tweet_text', $original_tweet_text);

        if (is_array($settings) && isset($settings['import_images']) && $settings['import_images'] == '1') {
            $this->import_medias($post_id, $medias);
        }

        return $post_id;
    }

    public function import_medias($post_id, $medias = array()) {
        if (empty($medias)) {
            return;
        }

        // Add required classes
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

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

    public function apply_entities($text, $entities) {

        // Urls
        if (!empty($entities->urls)) {
            foreach ($entities->urls as $url) {
                $text = str_replace($url->url, '<a class="twitter-links twitter-entities" href="' . $url->expanded_url . '">' . $url->display_url . '</a>', $text);
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
                $text = str_replace($media->url, '<a class="twitter-link twitter-medias" href="' . $media->expanded_url . '">' . $media->display_url . '</a>', $text);
            }
        }

        return $text;
    }

    /* ----------------------------------------------------------
      Admin
    ---------------------------------------------------------- */

    /* Settings link */

    public function add_settings_link($links) {
        $settings_link = '<a href="' . $this->options['admin_url'] . '">' . __('Settings') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /* Menu */

    public function admin_menu() {
        add_submenu_page('edit.php?post_type=' . $this->post_type, $this->options['plugin_name'] . ' - ' . __('Settings'), __('Import settings', 'wpuimporttwitter'), $this->options['plugin_minusercap'], $this->options['plugin_pageslug'], array(&$this,
            'admin_settings'
        ), '', 110);
    }

    /* Settings */

    public function postAction() {

        if (isset($_POST['import_now'])) {
            $nb_imports = $this->import();
            if ($nb_imports > 0) {
                $this->messages->set_message('imported_nb', sprintf(__('Imported tweets : %s', 'wpuimporttwitter'), $nb_imports));
            } else {
                $this->messages->set_message('imported_0', __('No new imports', 'wpuimporttwitter'), 'created');
            }
        }

        if (isset($_POST['test_api'])) {

            $last_tweets = $this->get_last_tweets_for_user('twitter');

            if (is_array($last_tweets) && !empty($last_tweets)) {
                $this->messages->set_message('api_works', __('The API works great !', 'wpuimporttwitter'), 'created');
            } else {
                $this->messages->set_message('api_invalid', __('The credentials seems invalid or the user never tweeted.', 'wpuimporttwitter'), 'error');
            }
        }
        flush_rewrite_rules();
        wp_safe_redirect(wp_get_referer());
        die();
    }

    public function admin_settings() {
        $schedule = wp_next_scheduled($this->cronhook);

        echo '<div class="wrap"><h1>' . apply_filters('wpuimporttwitter_admin_page_title', get_admin_page_title()) . '</h1>';
        settings_errors($this->settings_details['option_id']);

        echo '<hr />';

        if ($this->test_correct_oauth_values()) {

            echo '<h2>' . __('Tools') . '</h2>';
            echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
            echo '<input type="hidden" name="action" value="wpuimporttwitter_postaction">';

            $seconds = $schedule - time();
            $is_next = $seconds > 0;
            $minutes = 0;
            if ($seconds >= 60) {
                $minutes = (int) ($seconds / 60);
                $seconds = $seconds % 60;
            }
            if ($is_next) {
                echo '<p>' . sprintf(__('Next automated import in %s’%s’’', 'wpuimporttwitter'), $minutes, $seconds) . '</p>';
            }

            echo '<p class="submit">';
            submit_button(__('Import now', 'wpuimporttwitter'), 'primary', 'import_now', false);
            echo ' ';
            submit_button(__('Test API', 'wpuimporttwitter'), 'primary', 'test_api', false);
            echo '</p>';
            echo '</form>';
            echo '<hr />';
        }

        if (current_user_can($this->options['plugin_minusercap'])) {
            echo '<h2>' . __('Settings') . '</h2>';
            echo '<form action="' . admin_url('options.php') . '" method="post">';
            settings_fields($this->settings_details['option_id']);
            do_settings_sections($this->options['plugin_id']);
            echo submit_button(__('Save Changes', 'wpuimporttwitter'));
            echo '</form>';
        }
        echo '</div>';
    }

    /* ----------------------------------------------------------
      Twitter API Utils
    ---------------------------------------------------------- */

    public function buildBaseString($baseURI, $method, $params) {
        $r = array();
        ksort($params);
        foreach ($params as $key => $value) {
            $r[] = "$key=" . rawurlencode($value);
        }
        return $method . "&" . rawurlencode($baseURI) . '&' . rawurlencode(implode('&', $r));
    }

    public function buildAuthorizationHeader($oauth) {
        $r = 'Authorization: OAuth ';
        $values = array();
        foreach ($oauth as $key => $value) {
            $values[] = "$key=\"" . rawurlencode($value) . "\"";
        }

        $r .= implode(', ', $values);
        return $r;
    }

    /* ----------------------------------------------------------
      Metas
    ---------------------------------------------------------- */

    public function post_meta_boxes($boxes) {
        $boxes['tweet_box'] = array(
            'name' => __('Tweet details', 'wpuimporttwitter'),
            'post_type' => array($this->post_type)
        );
        $boxes['tweet_original_box'] = array(
            'name' => __('Original Tweet details', 'wpuimporttwitter'),
            'post_type' => array($this->post_type)
        );
        return $boxes;
    }

    public function post_meta_fields($fields) {
        $fields['wpuimporttwitter_screen_name'] = array(
            'box' => 'tweet_box',
            'name' => __('Tweet author', 'wpuimporttwitter'),
            'admin_column_sortable' => true,
            'admin_column' => true
        );
        $fields['wpuimporttwitter_id'] = array(
            'box' => 'tweet_box',
            'name' => __('Tweet ID', 'wpuimporttwitter')
        );
        $fields['wpuimporttwitter_original_screen_name'] = array(
            'box' => 'tweet_original_box',
            'name' => __('Original author', 'wpuimporttwitter')
        );
        $fields['wpuimporttwitter_original_url'] = array(
            'box' => 'tweet_original_box',
            'type' => 'url',
            'name' => __('Original url', 'wpuimporttwitter')
        );
        $fields['wpuimporttwitter_original_tweet_text'] = array(
            'box' => 'tweet_original_box',
            'type' => 'editor',
            'name' => __('Original text', 'wpuimporttwitter')
        );
        return $fields;
    }

    public function post_meta_column_callback($display_value, $field_id, $post_ID, $field, $value) {
        if ($field_id == 'wpuimporttwitter_screen_name' && !empty($value)) {
            $display_value = '<div style="margin-bottom:5px;"><img src="https://twitter.com/' . esc_attr($value) . '/profile_image?size=normal" alt="" /></div>' . $display_value;
        }
        return $display_value;
    }

    /* ----------------------------------------------------------
      Cron
    ---------------------------------------------------------- */

    public function check_cron() {
        $cron_interval = get_option('wpuimporttwitter_croninterval');
        $schedule = wp_next_scheduled($this->cronhook);
        // If no schedule cron or new interval
        if (!$schedule || $cron_interval != $this->cron_interval) {
            $this->install();
        }
    }

    public function add_schedule($schedules) {
        // Adds once weekly to the existing schedules.
        $schedules['wpuimporttwitter_schedule'] = array(
            'interval' => $this->cron_interval,
            'display' => __('Twitter Import')
        );
        return $schedules;
    }

    public function callback_cron() {
        $this->import();
    }

    /* ----------------------------------------------------------
      Activation
    ---------------------------------------------------------- */

    public function install() {
        wp_clear_scheduled_hook($this->cronhook);
        update_option('wpuimporttwitter_croninterval', $this->cron_interval);
        wp_schedule_event(time() + $this->cron_interval, 'wpuimporttwitter_schedule', $this->cronhook);
        flush_rewrite_rules();
    }

    public function deactivation() {
        wp_clear_scheduled_hook($this->cronhook);
        flush_rewrite_rules();
    }

    /* ----------------------------------------------------------
      Uninstall
    ---------------------------------------------------------- */

    public function uninstall() {
        delete_option($this->settings_details['option_id']);
        delete_option('wpuimporttwitter_croninterval');
        delete_post_meta_by_key('wpuimporttwitter_id');
        delete_post_meta_by_key('wpuimporttwitter_is_reply');
        delete_post_meta_by_key('wpuimporttwitter_is_retweet');
        delete_post_meta_by_key('wpuimporttwitter_name');
        delete_post_meta_by_key('wpuimporttwitter_original_name');
        delete_post_meta_by_key('wpuimporttwitter_original_screen_name');
        delete_post_meta_by_key('wpuimporttwitter_original_text');
        delete_post_meta_by_key('wpuimporttwitter_original_tweet');
        delete_post_meta_by_key('wpuimporttwitter_original_url');
        delete_post_meta_by_key('wpuimporttwitter_screen_name');
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
