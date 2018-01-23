<?php

/*
Plugin Name: WPU Import Twitter
Plugin URI: https://github.com/WordPressUtilities/wpuimporttwitter
Version: 1.12.3
Description: Twitter Import
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
Required plugins: WPU Post Types & Taxos
*/

class WPUImportTwitter {
    private $use_debug_file = false;
    private $log = false;
    private $messages = array();
    private $imported_tweets_ids = array();
    public $transient_import = 'wpuimporttwitter_import_running';
    public $cronhook = 'wpuimporttwitter__cron_hook';

    public function __construct() {
        $this->cron_interval = apply_filters('wpuimporttwitter_croninterval', 1800);
        $this->post_type = apply_filters('wpuimporttwitter_posttypehook', 'tweet');
        $this->log = apply_filters('wpuimporttwitter_log', $this->log);

        add_action('plugins_loaded', array(&$this,
            'load_plugin_textdomain'
        ));
        add_action('init', array(&$this,
            'set_options'
        ));
        add_action('init', array(&$this,
            'register_taxo_type'
        ));
        add_action('init', array(&$this,
            'init'
        ));
        add_action('current_screen', array(&$this,
            'check_config'
        ));
        add_action($this->cronhook, array(&$this,
            'import'
        ));
        add_action($this->cronhook, array(&$this,
            'clean_old_posts'
        ));
    }

    public function load_plugin_textdomain() {
        load_plugin_textdomain('wpuimporttwitter', false, dirname(plugin_basename(__FILE__)) . '/lang/');
    }

    public function register_taxo_type() {

        $labels = array(
            'name' => _x('Tweets', 'post type general name', 'wpuimporttwitter'),
            'singular_name' => _x('Tweet', 'post type singular name', 'wpuimporttwitter'),
            'menu_name' => _x('Tweets', 'admin menu', 'wpuimporttwitter'),
            'name_admin_bar' => _x('Tweet', 'add new on admin bar', 'wpuimporttwitter'),
            'add_new_item' => __('Add New Tweet', 'wpuimporttwitter'),
            'new_item' => __('New Tweet', 'wpuimporttwitter'),
            'edit_item' => __('Edit Tweet', 'wpuimporttwitter'),
            'view_item' => __('View Tweet', 'wpuimporttwitter'),
            'all_items' => __('All Tweets', 'wpuimporttwitter'),
            'search_items' => __('Search Tweets', 'wpuimporttwitter'),
            'parent_item_colon' => __('Parent Tweets:', 'wpuimporttwitter'),
            'not_found' => __('No tweets found.', 'wpuimporttwitter'),
            'not_found_in_trash' => __('No tweets found in Trash.', 'wpuimporttwitter')
        );

        /* Post type */
        register_post_type($this->post_type, apply_filters('wpuimporttwitter__post_type_infos', array(
            'public' => (!isset($this->settings_values['hide_front']) || $this->settings_values['hide_front'] != '1'),
            'show_in_nav_menus' => true,
            'show_ui' => true,
            'menu_icon' => 'dashicons-twitter',
            'name' => __('Tweet', 'wpuimporttwitter'),
            'labels' => $labels,
            'taxonomies' => array('twitter_tag'),
            'supports' => array(
                'title',
                'editor',
                'thumbnail'
            )
        )));

        $labels = array(
            'name' => _x('Twitter tags', 'taxonomy general name', 'wpuimporttwitter'),
            'singular_name' => _x('Twitter tag', 'taxonomy singular name', 'wpuimporttwitter'),
            'search_items' => __('Search Twitter tags', 'wpuimporttwitter'),
            'popular_items' => __('Popular Twitter tags', 'wpuimporttwitter'),
            'all_items' => __('All Twitter tags', 'wpuimporttwitter'),
            'parent_item' => null,
            'parent_item_colon' => null,
            'edit_item' => __('Edit Twitter Tag', 'wpuimporttwitter'),
            'update_item' => __('Update Twitter Tag', 'wpuimporttwitter'),
            'add_new_item' => __('Add New Twitter Tag', 'wpuimporttwitter'),
            'new_item_name' => __('New Twitter Tag Name', 'wpuimporttwitter'),
            'separate_items_with_commas' => __('Separate twitter tags with commas', 'wpuimporttwitter'),
            'add_or_remove_items' => __('Add or remove twitter tags', 'wpuimporttwitter'),
            'choose_from_most_used' => __('Choose from the most used twitter tags', 'wpuimporttwitter'),
            'not_found' => __('No twitter tags found.', 'wpuimporttwitter'),
            'menu_name' => __('Twitter tags', 'wpuimporttwitter')
        );

        /* Taxonomy */
        register_taxonomy(
            'twitter_tag',
            $this->post_type,
            apply_filters('wpuimporttwitter__taxonomy_infos', array(
                'label' => __('Twitter tags', 'wpuimporttwitter'),
                'labels' => $labels,
                'hierarchical' => false,
                'show_admin_column' => true,
                'show_in_nav_menus' => true,
                'show_ui' => true,
                'public' => (!isset($this->settings_values['hide_front']) || $this->settings_values['hide_front'] != '1')
            ))
        );
    }

    public function init() {
        add_filter('wputh_post_metas_boxes', array(&$this,
            'post_meta_boxes'
        ), 10, 1);
        add_filter('wputh_post_metas_fields', array(&$this,
            'post_meta_fields'
        ), 10, 1);

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
        add_filter('wputh_post_metas_admin_column_content_callback', array(&$this,
            'admin_column_callback'
        ), 10, 5);
        add_filter('parse_query', array(&$this,
            'filter_admin_results'
        ));

    }

    /* ----------------------------------------------------------
      Check config
    ---------------------------------------------------------- */

    public function check_config() {
        if (!is_admin()) {
            return;
        }

        $screen = get_current_screen();

        if (!is_object($screen) || $screen->base != 'plugins') {
            return;
        }

        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        // Check if WPU Post types & taxonomies is active
        if (!is_plugin_active('wpupostmetas/wpupostmetas.php')) {
            add_action('admin_notices', array(&$this,
                'set_error_missing_wpupostmetas'
            ));
        }
    }

    public function set_error_missing_wpupostmetas() {
        $plugin_link = '<a target="_blank" href="https://github.com/WordPressUtilities/wpupostmetas">WPU Post Metas</a>';
        echo '<div class="update-nag">' . sprintf(__('The plugin <b>%s</b> works better with the <b>%s</b> plugin. Please install and activate it.', 'wpuimporttwitter'), 'WPU Import Twitter', $plugin_link) . '</div>';
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
            'max_nb_posts' => array(
                'label' => __('Max number of posts', 'wpuimporttwitter'),
                'help' => __('Max number of posts kept. The old ones will automatically be deleted.', 'wpuimporttwitter'),
                'type' => 'number'
            ),
            'include_rts' => array(
                'label' => __('Include RTs', 'wpuimporttwitter'),
                'label_check' => __('Include retweets from other accounts by these users.', 'wpuimporttwitter'),
                'type' => 'checkbox'
            ),
            'include_replies' => array(
                'label' => __('Include Replies', 'wpuimporttwitter'),
                'label_check' => __('Include replies to other accounts by these users.', 'wpuimporttwitter'),
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

        // Cron
        include 'inc/WPUBaseCron.php';
        $this->cron = new \wpuimporttwitter\WPUBaseCron();
        $this->cron->init(array(
            'pluginname' => $this->options['plugin_name'],
            'cronhook' => $this->cronhook,
            'croninterval' => $this->cron_interval
        ));
        $this->cron->check_cron();

    }

    public function clean_old_posts() {
        @set_time_limit(0);
        $settings = get_option($this->settings_details['option_id']);

        if (!isset($settings['max_nb_posts']) || !is_numeric($settings['max_nb_posts'])) {
            return;
        }

        if ($settings['max_nb_posts'] < 1) {
            return;
        }

        $old_tweets = get_posts(array(
            'post_type' => $this->post_type,
            'posts_per_page' => 150,
            'offset' => $settings['max_nb_posts'],
            'post_status' => 'any'
        ));
        if (is_array($old_tweets)) {
            foreach ($old_tweets as $post) {
                $this->delete_associated_media($post->ID);
                wp_delete_post($post->ID, true);
            }
        }
    }

    public function delete_associated_media($id) {
        /* http://wordpress.stackexchange.com/a/134917 */
        $media = get_children(array(
            'post_parent' => $id,
            'post_type' => 'attachment'
        ));

        if (empty($media)) {
            return;
        }

        foreach ($media as $file) {
            wp_delete_attachment($file->ID);
        }
    }

    public function import() {
        $this->error_log('Launch import');
        @set_time_limit(0);
        $settings = get_option($this->settings_details['option_id']);

        /* Set a transient  */
        if (false === ($wpuimporttwitter_import_running = get_transient($this->transient_import))) {
            set_transient($this->transient_import, 1, 60 * 10);
        } else {
            $this->error_log('An import is already running');
            $this->messages->set_message('import_already_running', __('An import is already running', 'wpuimporttwitter'), 'error');
            return;
        }

        // Get ids from last imported tweets
        $this->imported_tweets_ids = $this->get_last_imported_tweets_ids();

        // Try to convert if old option model is used
        if (!isset($settings['sources']) && isset($settings['screen_name'])) {
            $settings['sources'] = '@' . $settings['screen_name'];
            $settings['screen_name'] = '';
            update_option($this->settings_details['option_id'], $settings);
        }

        $sources = $this->extract_sources($settings['sources']);
        if (empty($sources) && (!defined('DOING_CRON') || !DOING_CRON)) {
            $this->error_log('Sources are invalid');
            $this->messages->set_message('empty_sources', __('The sources are invalid or empty. Did you follow the rules below the sources box ?', 'wpuimporttwitter'), 'error');
            return false;
        }

        $this->error_log('Parsing sources');
        $imported_tweets = 0;
        foreach ($sources as $source) {
            if ($source['type'] == 'user') {
                $last_tweets = $this->get_last_tweets_for_user($source['id']);
            }
            if ($source['type'] == 'tag') {
                $last_tweets = $this->get_last_tweets_for_tag($source['id']);
            }
            if ($source['type'] == 'search') {
                $last_tweets = $this->get_last_tweets_for_search($source['id']);
            }
            if (!isset($last_tweets) || !is_array($last_tweets)) {
                $last_tweets = array();
            }

            $this->error_log('Importing last tweets for ' . $source['type'] . ':' . $source['id']);
            // Get last tweets from Twitter
            $imported_tweets += $this->import_last_tweets($last_tweets);
        }

        /* Allow a new import */
        delete_transient($this->transient_import);

        return $imported_tweets;
    }

    public function import_last_tweets($last_tweets) {
        $nb_imports = 0;

        // Exclude tweets already imported
        foreach ($last_tweets as $tweet) {
            if (in_array($tweet['id'], $this->imported_tweets_ids)) {
                $this->error_log('The tweet #' . $tweet['id'] . ' is already imported.');
                continue;
            }
            // Create a post for each new tweet
            $post_id = $this->create_post_from_tweet($tweet);
            if (is_numeric($post_id) && $post_id > 0) {
                $this->imported_tweets_ids[] = $tweet['id'];
                $nb_imports++;
            }
        }
        return $nb_imports;

    }

    public function extract_sources($sources) {
        $sources = str_replace("&quot;", '"', $sources);
        $sources = str_replace(array(',', ';'), "\n", $sources);
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
                $src = substr($src, 1);
            } else if ($source[0] == '#') {
                $type_source = 'tag';
                $src = substr($src, 1);
            } else if ($source[0] == '"') {
                $type_source = 'search';
                $src = str_replace('"', '', $src);
            }
            if ($type_source != '') {
                $final_sources[] = array(
                    'type' => $type_source,
                    'id' => $src
                );
            }

        }

        return $final_sources;
    }

    public function get_last_imported_tweets_ids() {
        global $wpdb;
        return $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = 'wpuimporttwitter_id' ORDER BY meta_id DESC LIMIT 0,500");
    }

    public function get_last_tweets_for_search($search = false) {
        return $this->get_last_tweets_for(array(
            'q' => urlencode('"' . $search . '"') . ' exclude:retweets'
        ));
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

        $request['tweet_mode'] = 'extended';

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

        $upload_dir = wp_upload_dir();
        $debug_file = $upload_dir['baseurl'] . '/tweets.txt';

        $response = false;
        if ($this->use_debug_file && file_exists($debug_file)) {
            $this->error_log('Using debug file');
            $response = file_get_contents($debug_file);
            $response_j = json_decode($response);
            if (!is_object($response_j)) {
                $this->error_log('Invalid debug file');
                $response = false;
            }
        }

        if (!$response) {
            $feed = curl_init();
            curl_setopt_array($feed, $options);
            $response = curl_exec($feed);
            curl_close($feed);
            if ($this->use_debug_file) {
                $this->error_log('Caching in debug file');
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
            if (!isset($tweet->full_text)) {
                continue;
            }

            $name = $tweet->user->name;
            $screen_name = $tweet->user->screen_name;
            $text = $tweet->full_text;
            $original_name = $tweet->user->name;
            $original_screen_name = $tweet->user->screen_name;
            $original_text = $tweet->full_text;
            $is_retweet = isset($tweet->retweeted_status);
            if ($is_retweet) {
                $original_name = $tweet->retweeted_status->user->name;
                $original_screen_name = $tweet->retweeted_status->user->screen_name;
                $original_text = $tweet->retweeted_status->full_text;
            }

            $tweets[$tweet->id_str] = array(
                'id' => $tweet->id_str,
                'text' => $tweet->full_text,
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

            if (property_exists($tweet, 'extended_entities')) {
                $tweets[$tweet->id_str]['extended_entities'] = $tweet->extended_entities;
            }
        }
        return $tweets;
    }

    public function create_post_from_tweet($tweet) {
        $tweet_text = $this->apply_entities($tweet['text'], $tweet['entities']);
        $original_tweet_text = $this->apply_entities($tweet['original_text'], $tweet['entities']);
        $tweet_title = $this->truncate_text(strip_tags($tweet_text), 60);

        // Extract medias
        $medias = array();
        if (property_exists($tweet['entities'], 'media') && count($tweet['entities']->media) > 0) {
            foreach ($tweet['entities']->media as $media) {
                if ($media->type == 'photo' && !in_array($media->media_url, $medias)) {
                    $medias[] = $media->media_url;
                }
            }
        }

        // Extended entities
        if (isset($tweet['extended_entities'])) {
            if (property_exists($tweet['extended_entities'], 'media') && count($tweet['extended_entities']->media) > 0) {
                foreach ($tweet['extended_entities']->media as $media) {
                    if ($media->type == 'photo' && !in_array($media->media_url, $medias)) {
                        $medias[] = $media->media_url;
                    }
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
            'post_date_gmt' => date('Y-m-d H:i:s', $tweet['time']),
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => $this->post_type
        );

        $this->error_log('Create a tweet for ' . $tweet['screen_name'] . ' / id:' . $tweet['id']);

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
            $this->error_log('Import medias for #' . $post_id);
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
            $hashtags = array();
            foreach ($entities->hashtags as $hashtag) {
                $hashtags[] = $hashtag->text;
            }
            usort($hashtags, array(&$this, 'sort_by_length'));
            foreach ($hashtags as $hashtag) {
                $text = str_ireplace('#' . $hashtag, '<a class="twitter-hashtags" href="https://twitter.com/hashtag/' . $hashtag . '?src=hash"><span>#</span>' . $hashtag . '</a>', $text);
            }
        }

        // Users
        if (!empty($entities->user_mentions)) {
            usort($entities->user_mentions, array(&$this, 'sort_by_length_screen_name'));
            foreach ($entities->user_mentions as $user_mention) {
                $text = str_ireplace('@' . $user_mention->screen_name, '<a class="twitter-users" href="https://twitter.com/' . $user_mention->screen_name . '" title="' . esc_attr($user_mention->name) . '"><span>@</span>' . $user_mention->screen_name . '</a>', $text);
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
            if (is_numeric($nb_imports)) {
                if ($nb_imports > 0) {
                    $this->messages->set_message('imported_nb', sprintf(__('Imported tweets : %s', 'wpuimporttwitter'), $nb_imports));
                } else {
                    $this->messages->set_message('imported_0', __('No new imports', 'wpuimporttwitter'), 'created');
                }
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
        } else {
            $twitterapp_url = 'https://apps.twitter.com/';
            echo '<p>';
            echo '<strong>' . __('You need correct IDs!', 'wpuimporttwitter') . '</strong><br />';
            echo sprintf(__('Please fill the IDs below or create <a target="_blank" href="%s">a twitter app</a>.', 'wpuimporttwitter'), $twitterapp_url);
            echo '</p>';
        }

        if (current_user_can($this->options['plugin_minusercap'])) {
            echo '<hr />';
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

    public function admin_column_callback($display_value, $field_id, $post_ID, $field, $value) {
        if ($field_id == 'wpuimporttwitter_screen_name' && !empty($value)) {
            $usernames = array(
                array(
                    'name' => $value,
                    'original' => 1
                )
            );
            $original_screen_name = get_post_meta($post_ID, 'wpuimporttwitter_original_screen_name', 1);
            if ($value != $original_screen_name) {
                array_push($usernames, array(
                    'name' => $original_screen_name,
                    'original' => 0
                ));
            }
            $display_value = '';
            foreach ($usernames as $username) {
                $twitter_url = 'https://twitter.com/' . esc_attr($username['name']) . '/profile_image?size=normal';
                $url = admin_url('edit.php?post_type=' . $this->post_type . '&wpuimporttwitter_screen_name=' . esc_attr($username['name']));
                $style = 'text-decoration:none;display:inline-block;font-size:0.9em;margin-right:5px';
                $content = '<img width="48" height="48" style="margin-bottom:5px" src="' . $twitter_url . '" alt="" /><br />' . $username['name'];
                if ($username['original']) {
                    $display_value .= '<a style="' . $style . '" href="' . $url . '">' . $content . '</a>';
                } else {
                    $display_value .= '<span style="' . $style . '">' . $content . '</span>';
                }
            }
        }
        return $display_value;
    }

    public function filter_admin_results($query) {
        global $pagenow;
        $type = 'post';
        if (isset($_GET['post_type'])) {
            $type = $_GET['post_type'];
        }
        if ($this->post_type == $type && is_admin() && $pagenow == 'edit.php' && isset($_GET['wpuimporttwitter_screen_name']) && $_GET['wpuimporttwitter_screen_name'] != '') {
            $query->query_vars['meta_key'] = 'wpuimporttwitter_screen_name';
            $query->query_vars['meta_value'] = $_GET['wpuimporttwitter_screen_name'];
        }
    }

    /* ----------------------------------------------------------
      Tools
    ---------------------------------------------------------- */

    public function sort_by_length($a, $b) {
        return strlen($b) - strlen($a);
    }

    public function sort_by_length_screen_name($a, $b) {
        return strlen($b->screen_name) - strlen($a->screen_name);
    }

    public function truncate_text($string, $length, $more = '...') {
        $_new_string = '';
        $_maxlen = $length - strlen($more);
        $_words = explode(' ', $string);

        /* Add word to word */
        foreach ($_words as $_word) {
            if (strlen($_word) + strlen($_new_string) >= $_maxlen) {
                break;
            }

            /* Separate by spaces */
            if (!empty($_new_string)) {
                $_new_string .= ' ';
            }
            $_new_string .= $_word;
        }

        /* If new string is shorter than original */
        if (strlen($_new_string) < strlen($string)) {

            /* Add the after text */
            $_new_string .= $more;
        }

        return $_new_string;
    }

    public function error_log($message) {
        if (!$this->log || !WP_DEBUG) {
            return;
        }
        error_log('Twitter Import - ' . $message);
    }

    /* ----------------------------------------------------------
      Activation
    ---------------------------------------------------------- */

    public function install() {
        flush_rewrite_rules();
    }

    public function deactivation() {
        flush_rewrite_rules();
        $this->cron->uninstall();
    }

    /* ----------------------------------------------------------
      Uninstall
    ---------------------------------------------------------- */

    public function uninstall() {
        $this->cron->uninstall();
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
