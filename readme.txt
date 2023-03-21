=== Force Regenerate Thumbnails ===
Contributors: pedro-elsner, nosilver4u
Requires at least: 4.9
Tested up to: 6.2
Requires PHP: 7.2
Stable tag: 2.1.2
License: GPLv2
Tags: force, regenerate thumbnails, thumbnail, thumbnails

Delete and REALLY force thumbnail regeneration.

== Description ==

Force Regenerate Thumbnails allows you to delete all old images sizes and REALLY regenerate the thumbnails for your uploads.

Regenerate all thumbnails from the Tools admin menu. Regenerate batches of images via the Media Library list mode. Select the images to regenerate and then use the Bulk Actions drop-down menu to Force Regenerate Thumbnails. Use attachment actions to regenerate thumbnails for a single image.

Several filters exist for advanced usage. See more via [this gist](https://gist.github.com/nosilver4u/eb858df10521aece2044a3a15ccdd17b).

== Installation ==

1. Go to your admin area and select Plugins -> Add new from the menu.
2. Search for "Force Regenerate Thumbnails".
3. Click install.
4. Click activate.
5. Go to Tools -> Force Regenerate Thumbnails OR select specific images from the Media Library list mode to regenerate.

== Screenshots ==

1. The plugin at work regenerating thumbnails
2. You can resize specific multiples images using the checkboxes and the "Bulk Actions" dropdown

== ChangeLog ==

= 2.1.2 =
* changed: improved i18n for page headings and menu entries, props @alexclassroom

= 2.1.1 =
* fixed: invalid trailing comma syntax in PHP 7.2

= 2.1.0 =
* added: PHP 8.0 compatibility
* added: support generating thumbnails from original (pre-scaled), on by default
* added: support for PDF thumbnail generation
* added: ability to skip an image by regenerate_thumbs_skip_image filter
* changed: escape all output, sanitize all input
* changed: ensure all strings are i18n
* changed: remove HTML from i18n strings
* changed: improve path lookup function
* fixed: call to set_time_limit() when it is not allowed

= 2.0.6 =
* Add PHP7 compatibility

= 2.0.5 =
* No timeout limit

= 2.0.4 =
* Fix issue when "opendir()" return FALSE (thanks Krody Robert)

= 2.0.3 =
* Add debug information on regenerate
* Fix issue with update "_wp_attachment_metadata" and "_wp_attached_file" on windows

= 2.0.2 =
* New style for results (thanks @justonarnar)
* Automatic update "_wp_attachment_metadata" and "_wp_attached_file" (thanks @norecipes)

= 2.0.1 =
* Fix issue with get_option('upload_path') in Wordpress 3.5+ (thanks @DavidLingren)

= 2.0.0 =
* Fix error handle

= Earlier versions =
Please refer to the separate changelog.txt file.
