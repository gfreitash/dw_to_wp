<?php
/**
* Super rough and tumble WordPress import script for Dokuwiki.
* Based on a very old DW install that was using the default theme. Probably won't work for anything else.
* You will want to change some things if your wiki is installed anywhere other than /wiki/.
* Also check out the wp_insert_post() stuff to see if you want to change it.
* 
* First version by Beau Lebens
* http://dentedreality.com.au/2013/01/29/import-dokuwiki-pages-into-wordpress/
*
* Matti Lattu added 2014 these features:
*  - Named DW tags to WB categories
*  - Links to attachments are moved
*  - Some DW HTML is cleaned
*/
 
require 'wp-load.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
// for the function wp_generate_attachment_metadata() to work
require_once ABSPATH . 'wp-admin/includes/image.php';
 
// List of Index URLs (one for each namespace is required)
// These will be crawled, all pages will be listed out, then crawled and imported
$DW_INDEXES = array(
	// Root namespace
	'http://your.site.org/some_path/doku.php?id=start&do=index',
	'http://your.site.org/some_path/doku.php?id=start&idx=namespace1',
	'http://your.site.org/some_path/doku.php?id=start&idx=namespace2:subnamespace1',
	'http://your.site.org/some_path/doku.php?id=start&idx=namespace2:subnamespace2',
	'http://your.site.org/some_path/doku.php?id=start&idx=namespace3',
);

$DW_FETCH_URL = 'http://your.site.org/some_path/lib/exe/fetch.php?media=';

$AUTHOR_ID = 1; // The user_ID of the author to create pages as
 
// This maps some DW tags to WP categories (category IDs)
// Use low-case DW tag names!
$TAG_MAP = Array(
	'dw_tag_one' => 1,
	'dw_tag_two' => 4,
	);
	
$PAGE_NAME_PREFIX = 'dw-old-';

function dokuwiki_link_fix( $matches ) {
    return '<a href="/' . str_replace( '_', '-', $matches[1] ) . '" class="wikilink1"';
}
 
$imported_urls = array(); // Stuff we've already processed
 
$page_count = 0;

$created = 0;
foreach ( $DW_INDEXES as $index ) {
    $page_count++;

    echo "Crawling $index for page links...\n";
    $i = file_get_contents( $index );
 
    if ( !$i )
        die( "Could not download $index\n" );
 
    // Get index page and parse it for links
    preg_match( '!<ul class="idx">(.*)</ul>!sUi', $i, $matches );
    preg_match_all( '!<a href="([^"]+)" class="wikilink1"!i', $matches[0], $matches );
 
    $bits = parse_url( $index );
    $base = $bits['scheme'] . '://' . $bits['host'];
 
    // Now we have a list of root-relative URLs, lets start grabbing them
    foreach ( $matches[1] as $slug ) {
        $url = $page = $raw = '';
        $dw_data = Array();
        $wp_categories = Array();

        if ( in_array( $slug, $imported_urls ) )
            continue;
        $imported_urls[] = $slug; // Even if it fails, we've tried once, don't bother again
 
        // The full URL we're getting
        $url = $base . $slug;
        echo "  Importing content from $url...\n";
 
        // Create page name from dokuwiki ID
        $page_name = $PAGE_NAME_PREFIX.$page_count;

        if (preg_match('/\?id\=(.+)$/', $url, $matches)) {
           $page_name = $PAGE_NAME_PREFIX.$matches[1];
        }

        $page_name = preg_replace('/[^a-zA-Z\d]/', '-', $page_name);

        // Get it
        $raw = file_get_contents( $url );
        if ( !$raw )
            continue;
 
        // Parse it -- dokuwiki conventiently HTML-comments where it's outputting content for us
        preg_match( '#<!-- wikipage start -->(.*)<!-- wikipage stop -->#sUi', $raw, $matches );
        if ( !$matches )
            continue;
 
        $page = $matches[1];
 
        // Need to clean things up a bit:
        // Remove the table of contents
        $page = preg_replace( '#<div class="toc">.*</div>\s*</div>#sUi', '', $page );
 
        // Strip out the Edit buttons/forms
        $page = preg_replace( '#<div class="secedit">.*</div></form></div>#sUi', '', $page );
 
        // Fix internal links by making them root-relative
        $page = preg_replace_callback(
            '#<a href="/wiki/([^"]+)" class="wikilink1"#si',
            'dokuwiki_link_fix',
            $page
        );
 
        // Grab a page title -- first h1 or convert the slug
        if ( preg_match( '#<h1.*</h1>#sUi', $page, $matches ) ) {
            $page_title = strip_tags( $matches[0] );
            $page = str_replace( $matches[0], '', $page ); // Strip it out of the page, since it'll be rendered separately
        } else {
            $page_title = str_replace( '/wiki/', '', $slug );
            $page_title = ucwords( str_replace( '_', ' ', $page_title ) );
        }
 
        // Get last modified from raw content

        // Want to debug raw page content? Uncomment this:
        //echo($raw);

        // Default timestamp for post
        $last_modified = '1970-01-01 00:00:00';
        
        // Following matches to YYYY/MM/DD HH:MM
        // If this does not work you might change e.g.
        // YYYY-MM-DD HH:MM --> /modified\: (\d+-\d+-\d+ \d+:\d+)/i
        if (preg_match( '/modified\: (\d+\/\d+\/\d+ \d+:\d+)/i', $raw, $matches )) {
            $last_modified = $matches[1].':00';
            $last_modified = preg_replace('/\//', '-', $last_modified);
        }

        // Get tags from raw content
        if (preg_match_all('/doku\.php\?id\=tag\:(\w+)/', $raw, $matches)) {
           $dw_data['tags'] = $matches[1];
           
           // Add categories
           foreach ($matches[1] as $this_dw_tag) {
	           if (array_key_exists(strtolower($this_dw_tag), $TAG_MAP)) {
	             // Yes, we have a match in $TAG_MAP
	             array_push($wp_categories, $TAG_MAP[strtolower($this_dw_tag)]);
	           }
	        }
        }

        // Get links to files from raw content
        if (preg_match_all('/[\&\?\;]media\=(.+?)\"/', $raw, $matches)) {
           $dw_data['attachments'] = $matches[1];
        }

        // Resolve parent if we're in a namespace
        $slug = str_replace( '/wiki/', '', $slug );
        if ( stristr( $slug, '/' ) ) {
            $parts = explode( '/', $slug );
            $slug = $parts[1];
            $parts[0] = str_replace( '_', '-', $parts[0] );
            $parent = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'name' => $parts[0] ) );
            if ( $parent ) {
                $parent = $parent[0]->ID;
            }
            else {
                // No parent found -- create a placeholder (will be an empty page with
                // the same last modified as the page we're working with).
                $post = array(
                    'post_status'   => 'publish',
                    'post_type'     => 'page',
                    'post_author'   => $AUTHOR_ID,
                    'post_parent'   => 0,
                    'post_content'  => '',
                    'post_modified' => $last_modified,
                    'post_title'    => ucwords( str_replace( '-', ' ', $parts[0] ) ),
                    'post_name'     => $page_name,
                    'post_category' => $wp_categories,
                );
 
                $parent = wp_insert_post( $post );
                $created++;
                echo "    Created parent page for $url using $parts[0]\n";
            }
        } else {
            $parent = 0; // top-level page
        }

		// Make some cleanup to the $page

		$page = preg_replace('/\n/', '', $page);

		// TOC
		$page = preg_replace('/\<!-- TOC START --\>.+?\<!-- TOC END --\>/mDi', '', $page);
		$page = preg_replace('/\<script.*?\>.+?\<\/script\>/mDi', '', $page);

		// Tags
		$page = preg_replace('/\<div class\=\"tags\"\>.+?\<\/div\>/', '', $page);
		
		$page = preg_replace('/\<li .+?\>\n?/', '', $page);
		$page = preg_replace('/\<\/li\>\n?/', '', $page);

/*
		$page = preg_replace('/\<ul\>\n?/', '', $page);
		$page = preg_replace('/\<ul .+?\>\n?/', '', $page);
		$page = preg_replace('/\<\/ul\>\n?/', '', $page);
*/

		$page = preg_replace('/\<div .+?\>\n?/', '', $page);
		$page = preg_replace('/\<div\>\n?/', '', $page);
		$page = preg_replace('/\<\/div\>\n?/', '', $page);

		$page = preg_replace('/\<strong *?\>\n?/', '', $page);
		$page = preg_replace('/\<\/strong *?\>\n?/', '', $page);

		// Dokuwiki links
		$page = preg_replace('/\<a href=\"\/dw-int-local.+?\>.*?\<\/a\>/', '', $page);
		$page = preg_replace('/\<a href=\"http\:\/\/sensori\.digabi\.fi\/dw-int-local.+?\>.*?\<\/a\>/', '', $page);

		// Forms
		$page = preg_replace('/\<form .+?\>/', '', $page);
		$page = preg_replace('/\<input .+?\>/', '', $page);
		$page = preg_replace('/\<\/form.*?\>/', '', $page);

		$page = preg_replace('/class=\".+?\" */', '', $page);
		$page = preg_replace('/rel=\".+?\" */', '', $page);
		$page = preg_replace('/id=\".+?\" */', '', $page);

		// Table
		$page = preg_replace('/\<table *?\>/', '<table>', $page);
		$page = preg_replace('/\<\/table *?\>/', '</table>', $page);
		$page = preg_replace('/\<tr *?>(.*?)<\/tr *?\>/', '<tr>\1</tr>', $page);
		$page = preg_replace('/\<th *?>(.*?)<\/th *?\>/', '<th>\1</th>', $page);
		$page = preg_replace('/\<td *?>(.*?)<\/td *?\>/', '<td>\1</td>', $page);

		// Empty paragraps
		$page = preg_replace('/\<p\>\<\/p\>/', '', $page);

		$page = preg_replace('/\>/', ">\n", $page);

      // Add DW tags
      if (count(@$dw_data['tags']) > 0) {
         $page .= '<code>';
         foreach ($dw_data['tags'] as $this_tag) {
            $page .= 'DW_tag:'.$this_tag.' ';
         }
         $page .= "</code>\n";
      }
      
        $post = array(
            'post_status'   => 'publish',
            'post_type'     => 'post',
            'post_author'   => $AUTHOR_ID,
            'post_parent'   => $parent,
            'post_content'  => $page,
            'post_title'    => $page_title,
            'post_modified' => $last_modified,
//            'post_name'     => str_replace( '_', '-', $slug ),
            'post_name'     => str_replace( '_', '-', $page_name ),
				'comment_status' => 'closed',
				'post_category' => Array(2),
				'post_category' => $wp_categories,
        );
 
        // Uncomment to debug things
//        echo("------------------------------------------------\n");
//        print_r($post);
//        echo("------------------------------------------------\n");
//        print_r($dw_data);
//        echo("------------------------------------------------\n");

        // Get post year and month (default to universum dawn)
        $last_modified_month = '1970/01';
        if (preg_match('/(\d+)\-(\d+)\-/', $last_modified, $matches)) {
           $last_modified_month = $matches[1].'/'.$matches[2];
        }

        $post_id = wp_insert_post( $post );
        if ($post_id > 0 and count(@$dw_data['attachments']) > 0) {
           // Post was added successfully, lets add the attachments

          foreach ($dw_data['attachments'] as $this_attachment_id) {
             // $this_attachment_id is DW attachment id
             
             $upload_dir = wp_upload_dir($last_modified_month);

            // The attachment should be saved into $upload_dir['path']
            $dw_file_name = preg_replace('/[^\w\d\.]/', '-', $this_attachment_id);
            $dw_file_name = $upload_dir['path'].'/'.$dw_file_name;
            $wp_filetype = wp_check_filetype(basename($dw_file_name), null);
            
            // Fetch and save file
            $dw_file_url = $DW_FETCH_URL.$this_attachment_id;
            $file_content = file_get_contents($dw_file_url);
            $fp = fopen($dw_file_name, 'w');
            fwrite($fp, $file_content);
            fclose($fp);
            
            $wp_attachment_data = Array(
            	'guid' => $dw_file_name,
            	'post_mime_type' => $wp_filetype['type'],
           		'post_title' => basename($dw_file_name),
           		'post_content' => '',
            	'post_status' => 'inherit'
            );
            
            // Attach file
            $attach_id = wp_insert_attachment( $wp_attachment_data, $dw_file_name, $post_id);
            $attach_data = wp_generate_attachment_metadata( $attach_id, $dw_file_name);
            wp_update_attachment_metadata( $attach_id, $attach_data );
            
            // Add attachment link to the post
            $page .= '<code>DW_attachment:'.wp_get_attachment_link($attach_id).'</code> ';
          }

          // Save page again as we may have added attachment links
          $updated_post_data = Array(
             'ID' => $post_id,
             'post_content' => $page
          );
          
          wp_update_post($updated_post_data);
        }
        
        
        $created++;
    }
}

