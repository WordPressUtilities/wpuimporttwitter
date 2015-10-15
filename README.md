WPU Import Twitter
======

A WordPress plugin to import the latest tweets for an account. MIT License.


How to install :
---

* Put this folder to your wp-content/plugins/ folder.
* Activate the plugin in "Plugins" admin section.
* Install & activate the plugin WPUOptions, required to update plugin settings https://github.com/WordPressUtilities/wpuoptions
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

* [*] Remove WPU Options dependency.
* [*] Add an "import now" button.
* [*] Add a way to test the Twitter credentials.
* [ ] Translation.
* [ ] Convert t.co urls.
* [ ] Import attachments.
* [ ] Add a help link to twitter developper website.
