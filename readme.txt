=== Fast Google Indexing API ===
Contributors: MaiSyDat
Tags: google, indexing, seo, api, automation, search-console
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically submit post URLs to Google Indexing API when content is published or updated. Lightweight and secure.

== Description ==

Fast Google Indexing API is a lightweight WordPress plugin that automatically submits your post URLs to Google's Indexing API when content is published or updated. This helps Google discover and index your content faster.

**Key Features:**

* **Automatic Submission**: Automatically submits URLs to Google Indexing API when posts are published or updated
* **Manual Submission**: Send individual posts to Google with a single click from the post edit screen or Console Results page
* **Bulk Actions**: Submit multiple posts at once from the post list table
* **Post Type Support**: Choose which post types (Posts, Pages, Products, Custom Post Types) to auto-submit
* **Action Types**: Support for both URL_UPDATED and URL_DELETED actions
* **Console Results Dashboard**: View indexed and not-indexed posts with real-time status checking
* **Auto-Scan**: Automatically scan and verify indexing status of your posts (configurable speed: 20, 50, or 100 posts per hour)
* **Status Checking**: Verify if Google has actually indexed your URLs using URL Inspection API
* **Comprehensive Logging**: View all API submissions with status codes and messages
* **Lightweight**: Uses native PHP for JWT authentication - no heavy dependencies
* **Secure**: Follows WordPress coding standards with proper nonces, sanitization, and escaping
* **AJAX-Powered**: Real-time status updates without page reloads

**How It Works:**

1. Configure your Google Cloud Service Account JSON key in the settings
2. Select which post types should be automatically submitted
3. The plugin automatically sends URLs to Google Indexing API when content is published or updated
4. Enable Auto-Scan to automatically verify indexing status
5. View Console Results to see which posts are indexed and which need attention
6. Use "Check Status" to verify if Google has actually indexed your URLs

**Requirements:**

* Google Cloud Project with Indexing API and Search Console API enabled
* Service Account with Indexing API and Search Console API permissions
* Service Account JSON key file

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/fast-google-indexing-api` directory, or install the plugin through the WordPress plugins screen directly
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Google Indexing > Settings to configure your Google Cloud Service Account JSON key
4. Select which post types should be automatically submitted
5. Start publishing content - URLs will be automatically submitted to Google!

== Frequently Asked Questions ==

= How do I get a Google Cloud Service Account JSON key? =

1. Go to Google Cloud Console (https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Enable the Indexing API for your project
4. Go to "IAM & Admin" > "Service Accounts"
5. Create a new service account or select an existing one
6. Create a JSON key for the service account
7. Copy the JSON content and paste it into the plugin settings

= Which post types are supported? =

All public post types are supported, including Posts, Pages, Products (WooCommerce), and any custom post types registered in your WordPress installation.

= Can I manually submit URLs? =

Yes! You can manually submit URLs using the "Send to Google" button in the post edit screen or the action link in the post list table.

= What is the difference between URL_UPDATED and URL_DELETED? =

* URL_UPDATED: Notifies Google that a URL has been updated (use for new posts or when updating existing content)
* URL_DELETED: Notifies Google that a URL has been deleted (use when content is removed)

= Where can I view submission logs? =

Go to Google Indexing > Logs to view all API submissions, including status codes and response messages.

== Screenshots ==

1. Settings page with Service Account JSON configuration
2. Post type selection
3. Logs page showing submission history
4. Manual submission button in post edit screen

== Changelog ==

= 1.2.0 =
* Added Console Results dashboard with Indexed/Not Indexed tabs
* Added Auto-Scan feature to automatically verify indexing status
* Added Status Checking using Google URL Inspection API
* Improved submit logic: URLs are marked as indexed immediately after successful submission
* Added AJAX-powered Index Now and Check Status buttons (no page reload)
* Optimized database queries with caching
* Separated JavaScript and CSS into dedicated asset files
* Improved UI/UX with inline notifications
* Removed debug code and optimized performance
* Fixed PHP 8.0+ compatibility issues

= 1.0.0 =
* Initial release
* Automatic URL submission on post publish/update
* Manual submission from post edit screen
* Bulk actions support
* Comprehensive logging system
* Settings page with post type selection
* Lightweight JWT authentication

== Upgrade Notice ==

= 1.2.0 =
Major update with Console Results dashboard, Auto-Scan feature, and improved performance. Update recommended for all users.

= 1.0.0 =
Initial release of Fast Google Indexing API plugin.

