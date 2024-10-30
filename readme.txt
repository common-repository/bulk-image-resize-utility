=== Plugin Name ===
Bulk Image Resize Utility

Contributors: mfields
Donate link: http://mfields.org/donate/
Tags: image, attachment, bulk, update, resize
Requires at least: 2.8.6
Tested up to: 2.9.2
Stable tag: trunk

Bulk Image Resize Utility offers WordPress users a method of updating all of their images at once after changes have been made to the Media Upload settings.

== Description ==

Bulk Image Resize Utility offers WordPress users a method of updating all of their images at once after changes have been made to the Media Upload settings.

[youtube http://www.youtube.com/watch?v=l0g4u5QbeX8]

= Usage =
After this plugin has been successfully installed, you will notice a new link under "Media" in your Admin Menu. This link is called "Scan Images". Click this link and then click the "Start Scan" button. This will scan every single image attachment on your WordPress installation. Report items with a green header are in "Perfect Condition" while items with a red background are incorrect and may or may not be resizable by WordPress. Please read the notices for details.

= Why make this plugin? =

1. WordPress 2.9 has included "post images" into the core, which if turned on, will allow users to associate any attachment to any post to be displayed in the loop on any theme page they choose. Older blogs, like mine, have images that were uploaded before WordPress started creating 3 intermediate images per attachment. A user with and older blog can back-resize all of their images in a few minutes rather than few hours.

1. More times than not, beginners will install their blog and upload images without adjusting the size in Media -Upload. After a while, they figure out about these options and set them to their tastes. If they want all of their images to match, they will have to re-upload to re-size.


= Disclaimer =

Currently, this plugin is in the Beta stages and **SHOULD NOT** be deployed on live sites. The download link is provided for testing purposes only. If you are interested in aiding in the development of this plugin, please feel free to download and and post your comments below.

If, however, you really want to use this plugin and have your heart set on deploying it on a live server. Please delete it after you are done.



== Screenshots ==

A short instructional screencast can be viewed here:
http://www.youtube.com/watch?v=l0g4u5QbeX8



== Installation ==
1. Download
1. Unzip the package and upload to your /wp-content/plugins/ directory.
1. Log into WordPress and navigate to the "Plugins" panel.
1. Activate the plugin.


== Changelog ==

= 0.1.6 =
* Replaced lowercase 'x' with '&times;' where appropriate.
* Formalized report messages. No more gremlins in this plugin...
* "View " links have been given title attributes.
* "Scan Images" button disabled during scan.
* `resize_image()` will now fail if projected size already exists.
* If no images have been upload to the Media Library, user is presented with a button to upload some.

= 0.1.5 =
* General Housekeeping
* Changed css styles for each report item. Font size was made smaller, margins were reduced.
* Moved the Create Image Button to top of report div.

= 0.1.4 =
* Added support for images that were uploaded to a version of WordPress (<2.5?) where the full file path was stored in the `_wp_attached_file` postmeta row. This is handled by `create_original_path()`.

= 0.1.3 =
* General Housekeeping
* Deleted second parameter from `json_encode()` - This was causing runtime errors due to WordPress forgetting to add support for the second parameter in it's version of json_encode in /wp-includes/compat.php.

= 0.1.2 =
* General Housekeeping

= 0.1.1 =
* Updated how the property $sizes is defined.
* Created the method `resize_image_report_item()`.

= 0.1 =
* Original Release - Works With: wp 2.9 rare.


=== To Do ===

* Remove all `target="_blank"` atributes from links and use javascript substitution.
* Plugin page should display a warning when $sizes is empty. "Start Scan" button should be removed.
* Remove "Start Scan" Button when image count === 0.
* Allows plugin to display image that may have been uploaded an a different server.