<?php
/*
Plugin Name: Bulk Image Resize Utility
Plugin URI: http://mfields.org/wordpress/plugins/bulk-image-resize-utility/
Description: This utility allows you to scan all images in you Media Library and create new versions for any that may need it.
Version: 0.1.5
Author: Michael Fields
Author URI: http://mfields.org/

Copyright 2009  Michael Fields  michael@mfields.org

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


/**
* Bulk Image Resize Utility
* @author Michael Fields <michael@mfields.org>
* @copyright Copyright (c) 2009, Michael Fields.
* @license http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
* @package Administration
* @filesource
* Requires PHP 5.2.0 or greater.
*/
if( !function_exists( 'pr' ) ) {
	function pr( $var ) {
		print '<pre>' . print_r( $var, true ) . '</pre>';
	}
}
/**
* Bulk Image Resize Utility Class Definition
*/
if( !class_exists( 'bulk_image_resize_utility' ) ) {
class bulk_image_resize_utility {
	/**
	* @var (string) $locale - A unique indentifer for this plugin. It is used in creating nonces, localization, etc...
	*/
	public $locale = 'bulk_image_resize_utility';
	/**
	* @var (array) $attachments - An array of objects representing all image attachments.
	*/
	public $attachments = array();
	/**
	* @var (array) $count - Stores the counts of various things.
	*/
	public $count = array(
		'attachments' => 0,
		'sizes' => 0
		);
	/**
	* @var (array) $sizes - An indexed array of all intermediate sizes as defined in wp_options table.
	*/
	public $sizes = array();
	/**
	* @var (string) $php_min_version - The minimun version this plugin needs to run propperly.
	*/
	public $php_min_version = '5.2.0';
	
	public $plugin_basename = '';
	public $permission = 'manage_options';
	
	/**
	* Object Constructor
	*/
	public function __construct() {
		
		$this->plugin_basename = plugin_basename( __FILE__ );
		
		/* Installation */
		register_activation_hook( __FILE__, array( &$this, 'install' ) );
		
		/* Location of uploads directory */
		$this->upload_dir_array = wp_upload_dir();
		$this->upload_dir = $this->upload_dir_array['basedir'];
		$this->upload_url = $this->upload_dir_array['baseurl'];

		/* Javascript */
		add_action( 'admin_head-media_page_' . $this->locale, array( &$this, 'setup_data' ) );
		add_action( 'admin_head-media_page_' . $this->locale, array( &$this, 'print_scripts' ) );
		
		/* Define custom admin pages + handlers */
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		
		add_action( 'wp_ajax_' . $this->locale . '_report', array( &$this, 'setup_data' ) );
		add_action( 'wp_ajax_' . $this->locale . '_report', array( &$this, 'make_report' ) );
		$this->report_url = get_bloginfo( 'wpurl' ) . '/wp-admin/admin-ajax.php?action=' . $this->locale . '_report';
		
		add_action( 'wp_ajax_' . $this->locale . '_resize', array( &$this, 'setup_data' ) );
		add_action( 'wp_ajax_' . $this->locale . '_resize', array( &$this, 'resize_image' ) );
		$this->resize_url = get_bloginfo( 'wpurl' ) . '/wp-admin/admin-ajax.php?action=' . $this->locale . '_resize';
	}
	public function setup_data() {
		$this->sizes = apply_filters( 'intermediate_image_sizes', array('large', 'medium', 'thumbnail') );
		$this->attachments = $this->query_attachments();
		$this->count['attachments'] = count( $this->attachments );
		$this->count['sizes'] = count( $this->sizes );
	}
	public function install() {
		if( version_compare( PHP_VERSION, $this->php_min_version ) === -1 ) {
			deactivate_plugins( $this->plugin_basename, true );
			trigger_error( 'This plugin requires php version ' . $this->php_min_version . ' or greater.', E_USER_ERROR );
			}
	}
	public function create_original_path( $id ) {
		$o = false;
		
		/* Get attachment metadata. */
		$_wp_attached_file = get_post_meta( $id, '_wp_attached_file', true );
		$o = $this->upload_dir . '/' . $_wp_attached_file;
		
		/* Older Version of WordPress stored a full file path in the '_wp_attached_file' row. */
		if( !is_file( $o ) ) {
			$_wp_attached_file = str_replace( $this->upload_dir, '' , $_wp_attached_file );
			$o = $this->upload_dir . '/' . $_wp_attached_file;
		}
		return $o;
	}
	/**
	* Resize an Image
	* This function is called in admin-ajax.php.
	* @uses isset() - PHP function
	* @uses intval() - PHP function
	* @uses in_array() - PHP function
	* @uses empty() - PHP function
	* @uses is_object() - PHP function
	* @uses wp_verify_nonce() - WordPress function
	* @uses get_post() - WordPress function
	* @uses wp_attachment_is_image() - WordPress function
	* @uses get_post_meta() - WordPress function
	* @uses image_make_intermediate_size() - WordPress function
	* @uses wp_get_attachment_metadata() - WordPress function
	* @uses wp_update_attachment_metadata() - WordPress function
	* @uses epic_fail()
	* @uses get_image_size_options()
	* @returns void - WordPress action callback.
	* /wp-admin/admin-ajax.php?action=bulk_image_resize_utility_resize
	*/
	public function resize_image() {
		$o = '';
		if( !current_user_can( $this->permission ) )
			$this->epic_fail( 'Sorry, you do not have the propper permissions to access this tool.' );
		
		/* Clean Request */
		$id = ( isset( $_GET['id'] ) ) ? intval( $_GET['id'] ) : 0;
		$size = ( isset( $_GET['size'] ) && in_array( $_GET['size'], $this->sizes ) ) ? $_GET['size'] : '';
		$nonce = ( isset( $_GET['nonce'] ) ) ? $_GET['nonce'] : '';
				
				/* All Request Vars must have a value. */
				if( empty( $id ) || empty( $size ) || empty( $nonce ) )
					$this->epic_fail( 'Error: Invalid Data Supplied.' );
				
				/* Nonce does not match: Fail. */
				if( !wp_verify_nonce( $nonce, $this->create_nonce_action( 'resize-attachment-image', $id, $size ) ) )
					$this->epic_fail( 'Nonce did not match.' );
		
		/* Query for post. */
		$post = get_post( $id );
		
				/* No Post: Fail. */
				if( !is_object( $post ) )	
					$this->epic_fail( 'We have no record of Post #' . $id . '.' );
				
				/* Post is not an attachment: Fail. */
				if( $post->post_type !== 'attachment' )
					$this->epic_fail( 'Post #' . $id . ' is not an attachment.' );
				
				/* Post is not an image: Fail. */
				if( !wp_attachment_is_image( $post->ID ) )
					$this->epic_fail( 'Post #' . $id . ' is not an image.' );
		
		/* Retrieve all intermediate sizes. */
		$sizes = $this->get_image_size_options();
			
				/* There are no sizes listed in wp_options table. Highly unlikely, but: Fail. */
				if( !is_array( $sizes ) || empty( $sizes ) )
					$this->fail( 'Fatal Error: Could not read wp_options table.' );
				
		/* Retrieve all intermediate sizes. */	
		$new = $sizes[$size];
				
				/* There is no records for the requested intermediate size. Highly unlikely, but: Fail. */
				if( !is_array( $sizes ) || empty( $sizes ) )
					$this->fail( 'Fatal Error: There is no data for the current intermediate size.' );
				
	#	$orig_path = $this->upload_dir . '/' . get_post_meta( $post->ID, '_wp_attached_file', true );
		$orig_path = $this->create_original_path( $post->ID );
		
		$img = image_make_intermediate_size( $orig_path, $new[0], $new[1], $new[2] );
				
				/* Intermediate image could not be created: Fail. */
				if( !$img )
					$this->resize_image_report_item( 'Fatal Error: Intermediate image could not be created.', 'error' );
		
		/* Get the path + url to the image we just created. */
		$path = str_replace( basename( $orig_path ), $img['file'], $orig_path );
		$url = $this->url_from_path( $path );
		
		$o.= "\n" . 'New <em>' . $size . '</em> image has been created for attachement #' . $id . '.';
		$o.= ' - <a target="_blank" href="' . $url . '">View</a>';
		#$o.= print_r( $img );
		
		$meta = wp_get_attachment_metadata( $id, true );
		$meta['sizes'][$size] = $img;
		$meta_update = wp_update_attachment_metadata( $id, $meta );
				
				/* Metadata could not be created: Fail. */
				if( !$meta_update ) {
					$o.= 'Fatal Error: Intermediate image metadata could not be updated.';
					$this->resize_image_report_item( $o, 'error' );
				}
				
		$o.= "\n" . 'Image metadata has been updated.';
		
		if( !empty( $o ) )
			$this->resize_image_report_item( $o, 'success' );
		
		exit();
	}
	
	public function resize_image_report_item( $message, $class ) {
		$o = '';
		$o.= "\n\t" . '<li class="' . $class . '">';
		$o.= "\n\t\t\t" . '<pre>' . $message . '</pre>';
		$o.= "\n\t" . '</li>';
		exit( $o );
	}
	
	/**
	* Create Nonce Action
	* Produces a semi-unique string for use in wp_create_nonce().
	* @param $action (string) - Defines an action.
	* @param $id (int) - An integer representing the post ID of the image.
	* @param $size (string) - The "slug" used for the size of the intermediate image.
	* @uses $locale
	* @returns (string)
	*/
	public function create_nonce_action( $action, $id, $size ) {
		return $this->locale . $action . '-' . $size . '-' . $post->ID;
	}
	/**
	* Epic Fail
	* Issues a 400 - Bad request header, prints an error message and terminates script execution.
	* @param $message (string) - The reason why the user failed.
	* @uses header() - PHP function.
	* @uses exit() - PHP function.
	* @returns (string)
	*/
	public function epic_fail( $message ) {
		header( 'HTTP/1.0 400 Bad Request' );
		print $message;
		exit();
	}
	/**
	* Fail
	* Issues a 500 Internal Server Error header, prints an error message and terminates script execution.
	* @param $message (string) - The reason why this script failed.
	* @uses header() - PHP function.
	* @uses exit() - PHP function.
	* @returns (string)
	*/
	public function fail( $message ) {
		header( 'HTTP/1.0 500 Internal Server Error' );
		print $message;
		exit();
	}
	public function print_scripts() {
		
		/* Declare local varibales */
		$data_type = 'html';
		$post_ids = array();
		$ids = '';
		
		
		/* Loop over image attachments. If there are any. */
		if( $this->attachments )
			foreach( $this->attachments as $order => $image )
				$post_ids[] = $image->ID;
		
		
		/* If we have image attachments, create a comma seperated list of their ids. */
		if( !empty( $post_ids ) )
			$ids = implode( ' ,', $post_ids );
		
		
		/* Set $action + overwrite $data_type for special requests. */
		switch( $_GET['action'] ) {
			case 'full-report' :
				$action = $_GET['action'];
				break;
			default :
				$action = false;
				break;
		}
if( $action === 'full-report' )	{
		print <<<EOF
<script type="text/javascript">
	/* <![CDATA[ */
		
		
	/* GLOBALS */
	var global = {
		report : new Array(),
		resize : new Array(),
		inadequate : 0,
		same : 0
	};
	
	
	
	jQuery( document ).ready( function( $ ) {
		/* An array containing all of the attachment id's that we wish to inspect - generated by php. */
		var ids = [ {$ids} ];
	//	var ids = [ 494, 495 ];
	//	var ids = [ 904, 13, 9 ];
		
		scan_image_attachments( ids, 0 );
		
		function fix( count ) {

			if( count <= global.resize.length ) {
				
				/* This is the LAST time through. */
				if( count == global.resize.length ) {
					// alert( 'GOODIES :)' );
				}
				else {
					$.ajax( {
						type: "GET",
						dataType: 'html',
						url: "{$this->resize_url}",
						data: 'id=' + global.resize[ count ].id + '&size=' + global.resize[ count ].size + '&nonce=' + global.resize[ count ].nonce,
						success: function( data, textStatus ) {
							$( '#resize' ).prepend( data );
						},
						error: function( XMLHttpRequest, textStatus, errorThrown ) {
							$( '#test' ).prepend( '<p style="color:red">AJAX Error: ' + textStatus + ' ' + errorThrown +  '</p>' );
						},
						complete: function() {
							count++;
							$( '#scanning' ).html( '<span>Creating: #' + count + '</span> out of ' + global.resize.length );
							fix( count );
						}
					});
				}
			}
		}
		
		function scan_image_attachments( ids, count ) {
			if( count <= ids.length ) {
				
				$( '#scanning' ).show();
				
				/* This is the LAST time through. */
				if( count == ids.length ) {
					
					$( '#scanning' ).append( ' - Finished.' );
					
					if( global.resize.length === 0 && global.inadequate === 0 && global.same === 0 ) {
						$( '#report' ).prepend( '<li>Congratulations! All of your images are present and accounted for.</li>' );
					}
					else {
						if( global.inadequate > 0 ) {
							$( '#report' ).prepend( '<li>' + global.inadequate + ' images are not able to be created due to the original not being large enough.</li>' );
						}
						
						if( global.same > 0 ) {
							$( '#report' ).prepend( '<li>' + global.same + ' images are the exact same size as the original. To create the desired intermediate sizes, you will need to uload a larger original.</li>' );
						}

						if( global.resize.length > 0 ) {

							/* Create the "Fix" button.  */
							$( '#report' ).prepend( '<li>' + global.resize.length + ' images can be created at the correct intermediate size. <a id="fix" class="button">Create ' + global.resize.length + ' New Images</a></li>' );
							
							/* Define action for "Fix" button. */
							$( '#fix' ).click( function() {
								fix( 0 );
							});
						}
					}
				}
				/* Every other iteration. */
				else {
					$.ajax( {
						type: "GET",
						dataType: 'json',
						url: "{$this->report_url}",
						data: "request={$action}&id=" + ids[count] + '&count=' + count,
						success: function( data, textStatus ) {
							global.report[count] = eval( data );
							$( '#report' ).prepend( parse_report( global.report[count] ) );
						},
						error: function( XMLHttpRequest, textStatus, errorThrown ) {
							$( '#test' ).prepend( '<p style="color:red">AJAX Error: ' + textStatus + ' ' + errorThrown +  '</p>' );
						},
						complete: function() {
							count++;
							$( '#scanning' ).html( '<span>Scanning: #' + count + '</span> out of ' + ids.length );
							scan_image_attachments( ids, count );
						}
					} );
				}
			}
		}
		
		function parse_report( img ) {
			var o = '';
	//		$.each( global.report, function ( order, img ) {
				$.each( img, function ( postID, data ) {
					if( data.zero_action != {$this->count['sizes']} ) {
						o+= "\\n\\t" + '<li>';
						o+= "\\n\\t\\t" + '<div class="image-wrap"><a target="_blank" href="' + data.url + '"><img src="' + data.url + '" width="' + data.thumb_width + '" height="' + data.thumb_height + '" alt="" /></a></div>';
						o+= "\\n\\t\\t" + '<ul class="image-data">';
						o+= "\\n\\t\\t\\t" + '<li>';
						o+= "\\n\\t\\t\\t" + '<h3>' + data.title + '</h3>';
						o+= "\\n\\t\\t\\t" + '<b>Original Size:</b> ' + data.width + ' x ' + data.height + ' pixels.';
						o+= "\\n\\t\\t\\t" + '</li>';
						
						if( data.sizes !== undefined && typeof data.sizes === 'object' ) {
							$.each( data.sizes, function ( size, obj ) {
								if( obj.status === 'wrong-size' )
									global.resize.push( { 
										'id' : postID,
										'size' : size,
										'nonce' : obj.nonce
										} );
								if( obj.status === 'inadequate-original' )
									global.inadequate++;
								if( obj.status === 'same-as-original' )
									global.same++;
										
								/* Generate html output */
								var class = ( obj.status !== undefined ) ? ' class="' + obj.status + '"' : '';
								var message = ( obj.message !== undefined ) ? obj.message : '';
								var options_size = ( obj.options_size !== undefined ) ? obj.options_size : '';
								var url = ( obj.url !== undefined ) ? obj.url : '';
								
								o+= "\\n\\t\\t\\t" + '<li' + class + '>';
								o+= "\\n\\t\\t\\t" + '<h4>' + size + ' <span>' + options_size + '.';
								if ( obj.url !== undefined )
									o+= ' <a href="' + url + '" target="_blank">[view]</a>';
								o+= '</span></h4>';
								o+= "\\n\\t\\t\\t" + '<p>' + message + '</p>';
								o+= "\\n\\t\\t\\t" + '</li>';

							})
						}
						o+= "\\n\\t\\t" + '</ul>';
						o+= "\\n\\t\\t" + '<div class="clear"></div>';
						o+= "\\n\\t" + '</li>';
					}
				})
	//		});
			return o;
		}
	});
	/* ]]> */
</script>
EOF;
}

print <<<EOF
<style type="text/css">
#report li,
#resize li {
	border: 1px solid #ddd;
	padding: 1em;
	background: #fff;
	}
#report li.alternate {
	background: #f9f9f9;
	}
#report li.alternate li{
	background: #fff;
	}
#report li div.image-wrap {
	width: 140px;
	float: left;
	text-align: center;
	}
#report li div.image-wrap img{
	border: 1px solid #ddd;
	}
#report li ul.image-data {
	margin-left: 150px;
	}
#report h3,
#report h4,
#report p{
	margin: 0;
	padding: 0 0 .3em;
	font-weight: normal;
	}
#report h3 { font-size: 1.7em; font-style: italic;}
#report h4 { font-size: 1.3em; font-style: italic;}
#report h4 span{ font-size: .8em; font-style: italic;}

#report li li {
	color:#666;
	background: #ffffff;
	padding: .3em 0 0 .5em;
	border:0;
	}
#report li li h4{ font-size: 1.2em; margin-bottom:0; }
#report li li p{ font-size: .83em; }
#report li li.same-as-original h4,
#report li li.inadequate-original h4{ color: #900; }
#report li li.wrong-size h4{ color: #900; }
#report li li.perfect h4{ color: #090; }
.hide{ display:none; }
</style>
EOF;
	}
	public function stats() {
		$o = '';
		$o.= "\n" . '<p>' . $this->count['attachments'] . ' total images have been uploaded to your Media Library.</p>';
		$o.= "\n" . '<p>Your blog\'s configuration enables you to create ' . $this->count['sizes'] . ' different sized images for each one you upload.</p>';
		return $o;
	}
	public function navigation() {
		$o = '';
		$o.= "\n\t" . '<div id="buttons">';
		$o.= "\n\t" . '<a class="button" href="' . add_query_arg( array( 'action' => 'full-report' ) ) . '">Start Scan</a>';
		$o.= "\n\t" . '</div>';
		return $o;
	}
	public function media_upload_button() {
		$o = '';
		$o.= "\n\t" . '<div id="buttons">';
		$o.= "\n\t" . '<a class="button" href="' . get_bloginfo( 'wpurl' ) . '/wp-admin/media-new.php">Upload Images Here</a>';
		$o.= "\n\t" . '</div>';
		return $o;
	}
	public function get_page() {
		print '<div class="wrap">';
		print '<div id="icon-upload" class="icon32"></div>';
		print '<h2>Image Scan &amp; Fix</h2>';
		print $this->stats();
		if( $this->count['attachments'] == 0 )
			print $this->media_upload_button();
		else
			print $this->navigation();
		print '<p id="scanning" class="hide"></p>';
		print '<ul id="resize"></ul>';
		print '<ul id="report"></ul>';
		print '</div>';
	}
	public function guess_projected_url( $url, $img = false ) {
		$size = ( is_array( $img ) ) ? '-' . $img[0] . 'x' . $img[1] : '';
		$ext = substr( $url, strrpos( $url, '.' ) );
		return str_replace( $ext, $size . $ext, $url );
	}
	/**
	* Produce a report in JSON format regarding the intermediate images of an attachment.
	* This function is called in admin-ajax.php.
	* @returns void - WordPress action callback.
	* /wp-admin/admin-ajax.php?action=bulk_image_resize_utility_report
	*/
	public function make_report() {
		
		if( !current_user_can( $this->permission ) )
			$this->epic_fail( 'Sorry, you do not have the propper permissions to access this tool.' );
			
		/* Variables */
		$orig_path = '';
		$sizes = array();
		$meta = array();
		
		/* Output Vars*/
		$json = '';
		$html = '';
		$report = array();
		
		/* Sanitize Query Vars */
		$id = ( isset( $_GET['id'] ) ) ? intval( $_GET['id'] ) : 0;
		$count = ( isset( $_GET['count'] ) ) ? intval( $_GET['count'] ) : 0;
		
		/* Query for attachment. */
		$post = get_post( $id, OBJECT, 'display' );
		$sizes = $this->get_image_size_options();
		
		if( $post ) {
		
			$orig_path = $this->create_original_path( $post->ID );
			
			if( !is_file( $orig_path ) )
				$this->fail( 'xxxOriginal uploaded image could not be found.<br />' . $orig_path );
				
			$meta = get_post_meta( $post->ID, '_wp_attachment_metadata', true );
			
			if( !is_array( $meta ) )
				$this->fail( 'Attachment metadata could not be retrieved.' );
			
			if( !empty( $sizes ) && !empty( $orig_path ) && !empty( $meta ) ) {
				
				/* Dimensions of the original uploaded image. */
				list( $_w, $_h ) = @getimagesize( $orig_path );				
				
				/* Dimensions for "on-the-fly" resizing in the browser. */
				$thumb = $this->projected_size( array( $_w, $_h ), array( 140, 140, false ) );
				
				/* Set report vars with Attachment data. */
				$report[$post->ID]['title'] = apply_filters( 'the_title', $post->post_title ) ;
				$report[$post->ID]['path'] = $orig_path;
				$report[$post->ID]['url'] = $this->url_from_path( $orig_path );
				$report[$post->ID]['width'] = $_w;
				$report[$post->ID]['height'] = $_h;
				$report[$post->ID]['zero_action'] = 0;
				if( is_array( $thumb ) ) {
					$report[$post->ID]['thumb_width'] = $thumb[0];
					$report[$post->ID]['thumb_height'] = $thumb[1];
				}
				/* Loop over all intermediate image sizes. */
				foreach( $sizes as $size => $data ) {
					
					/* Get the path + url to the current image. */
					$path = str_replace( basename( $orig_path ), $meta['sizes'][$size]['file'], $orig_path );
					$url = $this->url_from_path( $path );
					
					/* What size should the intermediate image be? - $test[4] = width. $test[5] = height. */
					$test = image_resize_dimensions( $_w, $_h, $data[0], $data[1], $data[2] );
					
					/* Check to see if the file exists */
					$img = $this->image_exists( $path );
					
					/* See what size the image would be if created. */
					$proj = $this->projected_size( array( $_w, $_h ), $data );
					
					/* Set variables that will exist for all iterations of this loop. */
					$report[$post->ID]['sizes'][$size]['projected_size'] = $this->dim_text( $proj );
					$report[$post->ID]['sizes'][$size]['options_size'] = $this->dim_text( $data );
					
					/* Image exists. */
					if( is_array( $img ) ) {
						/* Image exists and is correct size. */
						if ( $img[0] == $test[4] && $img[1] == $test[5] ) {
							$report[$post->ID]['sizes'][$size]['action'] = false;
							$report[$post->ID]['sizes'][$size]['status'] = 'perfect';
							$report[$post->ID]['sizes'][$size]['message'] = 'This image is the correct size.';
							$report[$post->ID]['sizes'][$size]['path'] = $path;
							$report[$post->ID]['sizes'][$size]['url'] = $url;
							$report[$post->ID]['zero_action']++;
						}
						/* Image exists - Size is incorrect. WE CANNOT FIX IT!!! */
						else if( ( $proj[0] > $_w || $proj[1] > $_h ) ) {
							$message = 'Sorry, we can not generate an image for you at this size because your original is too small. Your original image is: ' . $this->dim_text( array( $_w, $_h )) . ' (width x height). To achieve the projected size of ' . $this->dim_text( $proj ) . ', you will need to upload a new image that is larger than the projected size.';
							$report[$post->ID]['sizes'][$size]['action'] = 'inform';
							$report[$post->ID]['sizes'][$size]['status'] = 'inadequate-original';
							$report[$post->ID]['sizes'][$size]['message'] = $message;
							$report[$post->ID]['sizes'][$size]['path'] = false;
							$report[$post->ID]['sizes'][$size]['url'] = false;
						}
						else {
							$message = 'A ' . $size . ' image does exist for this upload, but it\'s size is incorrect and needs to be updated. Currently, the image is ' . $this->dim_text( $img ) . '. Your media settings suggest a size of ' . $this->dim_text( $proj );
							$report[$post->ID]['sizes'][$size]['action'] = 'update';
							$report[$post->ID]['sizes'][$size]['status'] = 'wrong-size';
							$report[$post->ID]['sizes'][$size]['message'] = $message;
							$report[$post->ID]['sizes'][$size]['path'] = $path;
							$report[$post->ID]['sizes'][$size]['url'] = $url;
							$report[$post->ID]['sizes'][$size]['nonce'] = wp_create_nonce( $this->create_nonce_action( 'resize-attachment-image', $post->ID, $size ) );
						}
					}
					
					/* Image does not exist. */
					else {
						if( is_array( $proj ) ) {
							/* Intermediate image would be the same size as original */
							if( $proj[0] == $_w && $proj[1] == $_h ){
								$message = 'Sorry, we can not generate an image for you at this size because your original is exactly the same size as the intermediate size would be. If you need an image at this size, please upload a larger original.';
								$report[$post->ID]['sizes'][$size]['action'] = 'inform';
								$report[$post->ID]['sizes'][$size]['status'] = 'same-as-original';
								$report[$post->ID]['sizes'][$size]['message'] = $message;
								$report[$post->ID]['sizes'][$size]['path'] = false;
								$report[$post->ID]['sizes'][$size]['url'] = false;
							}
							/* Intermediate image appears to be larger than original. */
							else if( ( $proj[0] > $_w || $proj[1] > $_h ) ) {
								$message = 'Sorry, we can not generate an image for you at this size because your original is too small. Your original image is: ' . $this->dim_text( array( $_w, $_h )) . ' (width x height). To achieve the projected size of ' . $this->dim_text( $proj ) . ', you will need to upload a new image that is larger than the projected size.';
								$report[$post->ID]['sizes'][$size]['action'] = 'inform';
								$report[$post->ID]['sizes'][$size]['status'] = 'inadequate-original';
								$report[$post->ID]['sizes'][$size]['message'] = $message;
								$report[$post->ID]['sizes'][$size]['path'] = false;
								#$report[$post->ID]['sizes'][$size]['url'] = null; /* AKA "undefined" */
							}
							else {
								$message = 'This image could not be found on your server. It is possible that you have deleted it or you uploaded it before your installation added support for it. No worries, we can recreate it for you.';
								$report[$post->ID]['sizes'][$size]['action'] = 'update';
								$report[$post->ID]['sizes'][$size]['status'] = 'wrong-size';
								$report[$post->ID]['sizes'][$size]['message'] = $message;
								$report[$post->ID]['sizes'][$size]['path'] = false;
								$report[$post->ID]['sizes'][$size]['url'] = $this->guess_projected_url( $url, $proj );
								$report[$post->ID]['sizes'][$size]['nonce'] = wp_create_nonce( $this->create_nonce_action( 'resize-attachment-image', $post->ID, $size ) );
							}
						}
					}
				}
			}
			header( 'Content-type: application/jsonrequest' );
		#	$json = json_encode( $report, JSON_FORCE_OBJECT );
			$json = json_encode( $report );
			print $json;
			exit();
		}
		$this->fail( 'Sorry, but we could not find the post you were looking for.' );
	}
	/**
	Image Exists
	* @uses is_file() - PHP function.
	* @uses getimagesize() - PHP function.
	* @param $path
	* @returns (mixed) array on success, false on failure
	*/
	public function image_exists( $path ) {
		if( !is_file( $path ) )
			return false;
		return @getimagesize( $path );
	}
	/**
	* Get formatted text for image dimensions.
	* @param (array) $img - looks like: $img[0] = width; $img[1] = height;
	* @uses intval() - PHP function.
	* @uses is_array() - PHP function.
	* @returns (string) - empty string on failure.
	*/
	public function dim_text( $img ) {
		$o = '';
		if ( is_array( $img ) ) {
			$o = intval( $img[0] ) . ' x ' . intval( $img[1] ) . ' pixels';
		}
		return $o;
	}
	/**
	* Get Image Size Options
	* Retrieves the user defined settings for all images uploaded through WordPress' Media interface.
	* @uses $sizes - output from: apply_filters( 'intermediate_image_sizes', $this->sizes );
	* @uses is_array() - PHP function.
	* @uses get_option() - WordPress core function.
	* @returns (array) - empty array on failure.
	*/
	public function get_image_size_options() {
		$o = array();
		if( is_array( $this->sizes ) ) {
			foreach( $this->sizes as $order => $size ) {
				$o[$size] = array(
					get_option( $size . '_size_w' ),
					get_option( $size . '_size_h' ),
					get_option( $size . '_crop' )
				);
			}
		}
		return $o;
	}
	/**
	* Add Page To Administation Menu
	* @uses add_submenu_page() - WordPress function.
	*/
	public function admin_menu() {
		add_submenu_page( 'upload.php', 'Scan Images', 'Scan Images', 10, $this->locale, array( &$this, 'get_page' ) );
	}
	/**
	* Query Attachments which are images.
	* @global $wpdb - WordPress variable.
	* @uses get_results() - WordPress method of $wpdb.
	* returns (mixed) array on success, null on failure (I think?)
	*/
	public function query_attachments() {
		global $wpdb;
		$q = "
			SELECT `ID`, `post_parent` FROM `{$wpdb->prefix}posts`
			WHERE `post_type` = 'attachment'
			AND 'image' = LEFT( `post_mime_type`, 5 )
			";
		return $wpdb->get_results( $q );
	}
	/**
	* Creates a url from the given path.
	* @param $path - Full file path.
	* @uses ABSPATH - WordPress constant.
	* @uses strlen() - PHP function.
	* @uses substr_replace() - PHP function.
	* @uses preg_replace() - PHP function.
	* @uses get_bloginfo() - WordPress function.
	* returns (string)
	*/
	public function url_from_path( $path ){
		/* Length of ABSPATH */		
		$l = strlen( ABSPATH );
		
		/* Strip $path from left side of $path */
		$path = substr_replace( $path, '', 0, $l );
		
		/* Replace back slashes with forward slashes */
		$path = preg_replace( '/\\\\/', '/', $path );
		
		/* Prepend Domain */
		$path = get_bloginfo( 'wpurl' ) . '/' . $path;
		
		return $path;
	}
	/**
	* Calculate the "Projected Size" of an image
	* @param $original_dims - (array) The dimensions of the original image expressed as array( (int) width, (int) height, (bool) crop ).
	* @param $original_dims - (array) The dimensions of the new image expressed as array( (int) width, (int) height, (bool) crop ).
	* @uses intval() - PHP function.
	* @uses round() - PHP function.
	* returns (array) - The dimensions of the new imageexpressed as: array( (int) width, (int) height ).
	*/
	public function projected_size( $original_dims, $intermediate_dims ) {
		/* Sanitize input */
		$_w = intval( $original_dims[0] );
		$_h = intval( $original_dims[1] );
		
		$w = intval( $intermediate_dims[0] );
		$h = intval( $intermediate_dims[1] );
		$c =  (bool) $intermediate_dims[2];
		
		$width = 'false';
		$height = 'false';
		
		/* Return intermediate values.
		1. If the image is to be cropped.
		2. If the original image is square.
		------------------------------------------------------- */
		if( $c || ( $_w === $_h ) ) {
			$width = $w;
			$height = $h;
		}
		
		/* Variable Width & Fixed Height: Solve for new width.
		------------------------------------------------------- */
		else if( ( $w === 0 & $h > 0 ) ) {
			$width =  ( $h * $_w ) / $_h;
			$height = $h;
		}
		
		/* Fixed Width & Variable Height: Solve for new height.
		------------------------------------------------------- */
		else if( ( $w > 0 & $h === 0 ) ) {
			$width =  $w;
			$height = ( $w * $_h ) / $_w;
		}
		
		/* Constrain Proportions
		------------------------------------------------------- */
		else {
			/* Horizontal Images */
			if( $_w > $_h ) {
				$width =  $w;
				$height = ( $width * $_h ) / $_w;
			}
			
			/* Vertical Images */
			else {
				$height = $h;
				$width =  ( $height * $_w ) / $_h;
			}
		}
		
		return array( round( $width ), round( $height ) );
	}
}
}
if( class_exists( 'bulk_image_resize_utility' ) ) {
	/**
	* @var (object) Bulk Image Resize Utility Object
	*/
	$bulk_image_resize_utility = new bulk_image_resize_utility();
}
?>