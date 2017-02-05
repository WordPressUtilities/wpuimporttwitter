WPU Import Twitter
======

A WordPress plugin to import the latest tweets for an account. MIT License.

Features :
---

* Import in a custom post type "tweets".
* Import attached pictures.
* Use a cron to import automatically every hour, or use a button to import now.
* Import as "Published", or as "Draft" to select displayed tweets.


How to install :
---

* Put this folder to your wp-content/plugins/ folder.
* Activate the plugin in "Plugins" admin section.
* Install & activate the plugin WPU Post types & taxonomies, required to add taxonomies https://github.com/WordPressUtilities/wpuposttypestaxos


How to configure :
---

* Create an app at https://apps.twitter.com.
* Retrieve, in the "Keys and Access Tokens" section, the following values : API Key, API Secret, Access Token, Access Token Secret.
* In the WordPress admin, in the "Site Options" section, set up the plugin with the retrieved values, and the details for the account to import.
* Enjoy !


Special Thanks :
---

* @budidino on http://stackoverflow.com/a/16169848 for his clear Twitter API script.


TODO
---

* [x] Remove WPU Options dependency.
* [x] Add an "import now" button.
* [x] Add a way to test the Twitter credentials.
* [x] Setting to import as Draft.
* [x] Convert t.co urls.
* [x] Links in tweets ( t.co, @username, #hashtags ).
* [x] Hide "test" & "import" if no token values.
* [x] Store original tweet link.
* [x] Import pictures.
* [x] Clean title from URLs before crop.
* [x] Admin front visibility.
* [x] Edit stored values with WPU Post metas.
* [x] Translation.
* [x] Hook for post type id.
* [x] Store if is RT.
* [x] Store if is Reply.
* [x] Hook for cron interval.
* [ ] Import smilies.
* [ ] Add a help link to twitter developper website.
* [ ] Remove Post types & taxos dependency.
* [ ] Delete empty tags : DELETE FROM wp_term_taxonomy WHERE count = 0 and taxonomy = 'twitter_tag'

