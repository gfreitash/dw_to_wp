<?php
include 'simple_html_dom.php';
class Search
{
    // List of Index URLs (one for each namespace is required)
    // These will be crawled, all pages will be listed out, then crawled and imported
    private $INDEXES;
    private $DW_FETCH_URL;
    private $AUTHOR_ID;
    private $WIKI_NAME;
    private $PAGE_NAME_PREFIX;
    private $URL_COLON_PREFIX;
    private int $created;
    private array $visited_urls;
    //Essa variável é um array multidimensional que armazenará uma lista de todas as páginas da wiki encontradas de uma partir da página índice
    private array $wiki_pages_per_index;
    private bool $same;

    function __construct(array $indexes, string $dw_fetch_url, int $author_id, string $wiki_name, string $url_colon_prefix)
    {
        $this->INDEXES = $indexes;
        $this->DW_FETCH_URL = $dw_fetch_url;
        $this->AUTHOR_ID = $author_id;
        $this->WIKI_NAME = $wiki_name;
        $this->PAGE_NAME_PREFIX = 'dw-old-';
        $this->URL_COLON_PREFIX = $url_colon_prefix;
        $this->created = 0;
        $this->visited_urls = array();
        $this->wiki_pages_per_index = array();
    }

    function importWikiPages() {
        foreach($this->INDEXES as $index) {
            echo "Crawling $index for page links...\n";
            $this->findWikiPages($index, $index);
            
            echo '<pre>'; print_r($this->wiki_pages_per_index); echo '</pre>';
            //Para cada página encontrada, inicie o processo de importação.
            foreach($this->wiki_pages_per_index[$index] as $slug) {
                $url = $page = $raw = '';
                $dw_data = Array();
                $wp_categories = Array();

                $url = $this->createRootRelativeURL($index, $slug);
                echo "  Importing content from $url...\n";

                // Create page name from dokuwiki ID
                $page_name = $this->PAGE_NAME_PREFIX.$this->created;
                if (preg_match('/\?id\=(.+)$/', $url, $matches)) {
                    $page_name = $this->PAGE_NAME_PREFIX.$matches[1];
                }
         
                $page_name = preg_replace('/[^a-zA-Z\d]/', '-', $page_name);
                echo "Page name: " . $page_name."\n";

                // Get it
                $raw = file_get_contents($url);
                if (!$raw)
                continue;

                // Parse it -- dokuwiki conventiently HTML-comments where it's outputting content for us
                preg_match('#<!-- wikipage start -->(.*)<!-- wikipage stop -->#sUi', $raw, $matches);
                if (!$matches)
                continue;

                $page = $matches[1];

                // Need to clean things up a bit:
                // Remove the table of contents
                $page = preg_replace('#<div class="toc">.*</div>\s*</div>#sUi', '', $page);

                // Strip out the Edit buttons/forms
                $page = preg_replace('#<div class="secedit.*">.*<\/div><\/form><\/div>#sUi', '', $page);

                // Fix internal links by making them root-relative
                $page_bkp = $page;
                $page = preg_replace_callback(
                    '#<a href="/'.$this->WIKI_NAME.'/([^"]+)" class="wikilink1"#si',
                    function($matches) {
                        $str = str_replace( '_', '-', $matches[1] );
                        $str = str_replace(':', '-', $str);
                        $str = $this->removePageAndQuery($str);
                        return '<a href="' . $this->PAGE_NAME_PREFIX . $str . '" class="wikilink1"';
                    },
                    $page
                );
                $this->same = $page===$page_bkp;
                

                // Grab a page title -- first h1 or convert the slug
                if (preg_match('#<h1.*</h1>#sUi', $page, $matches)) {
                    $page_title = strip_tags($matches[0]);
                    $page = str_replace($matches[0], '', $page); // Strip it out of the page, since it'll be rendered separately
                } else {
                    $page_title = str_replace('/'.$this->WIKI_NAME.'/', '', $this->removePageAndQuery($slug, "wikini:"));
                    $page_title = ucwords(str_replace('_', ' ', $page_title));
                }

                // Get last modified from raw content

                // Want to debug raw page content? Uncomment this:
                //echo($raw);

                // Default timestamp for post
                $last_modified = '1970-01-01 00:00:00';

                // Following matches to YYYY/MM/DD HH:MM
                // If this does not work you might change e.g.
                // YYYY-MM-DD HH:MM --> /modified\: (\d+-\d+-\d+ \d+:\d+)/i
                if (preg_match('/modified\: (\d+\/\d+\/\d+ \d+:\d+)/i', $raw, $matches)) {
                    $last_modified = $matches[1] . ':00';
                    $last_modified = preg_replace('/\//', '-', $last_modified);
                }

                // Get tags from raw content
                /*if (preg_match_all('/doku\.php\?id\=tag\:(\w+)/', $raw, $matches)) {
                    $dw_data['tags'] = $matches[1];

                    // Add categories
                    foreach ($matches[1] as $this_dw_tag) {
                        if (array_key_exists(strtolower($this_dw_tag), $TAG_MAP)) {
                            // Yes, we have a match in $TAG_MAP
                            array_push($wp_categories, $TAG_MAP[strtolower($this_dw_tag)]);
                        }
                    }
                }*/

                // Get links to files from raw content
                if (preg_match_all('/[\&\?\;]media\=(.+?)\"/', $raw, $matches)) {
                    $dw_data['attachments'] = $matches[1];
                }

                // Resolve parent if we're in a namespace
                $slug = str_replace('/'.$this->WIKI_NAME.'/', '', $slug);
                if (stristr($slug, '/')) {
                    $parts = explode('/', $slug);
                    $slug = $parts[1];
                    $parts[0] = str_replace('_', '-', $parts[0]);
                    $parent = get_posts(array('post_type' => 'page', 'post_status' => 'publish', 'name' => $parts[0]));
                    if (
                        $parent
                    ) {
                        $parent = $parent[0]->ID;
                    } else {
                        // No parent found -- create a placeholder (will be an empty page with
                        // the same last modified as the page we're working with).
                        $post = array(
                            'post_status'   => 'publish',
                            'post_type'     => 'page',
                            'post_author'   => $this->AUTHOR_ID,
                            'post_parent'   => 0,
                            'post_content'  => '',
                            'post_modified' => $last_modified,
                            'post_title'    => ucwords(str_replace('-', ' ', $parts[0])),
                            'post_name'     => $page_name,
                            'post_category' => $wp_categories,
                        );

                        $parent = wp_insert_post($post);
                        $this->created++;
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
                        $page .= 'DW_tag:' . $this_tag . ' ';
                    }
                    $page .= "</code>\n";
                }

                $post = array(
                    'post_status'   => 'publish',
                    'post_type'     => 'post',
                    'post_author'   => $this->AUTHOR_ID,
                    'post_parent'   => $parent,
                    'post_content'  => $page,
                    'post_title'    => $page_title,
                    'post_modified' => $last_modified,
                    //            'post_name'     => str_replace( '_', '-', $slug ),
                    'post_name'     => str_replace('_', '-', $page_name),
                    'comment_status' => 'closed',
                    'post_category' => array(2),
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
                    $last_modified_month = $matches[1] . '/' . $matches[2];
                }

                $post_id = wp_insert_post($post);
                if ($post_id > 0 and count(@$dw_data['attachments']) > 0) {
                    // Post was added successfully, lets add the attachments

                    foreach ($dw_data['attachments'] as $this_attachment_id) {
                        // $this_attachment_id is DW attachment id

                        $upload_dir = wp_upload_dir($last_modified_month);
                        echo "Found attachment: [[".$this_attachment_id."]]\n";


                        // The attachment should be saved into $upload_dir['path']
                        $dw_file_name = str_replace($this->URL_COLON_PREFIX, '', $this_attachment_id); //remove the suffix the old link might have
                        $dw_file_name = preg_replace('/[^\w\d\.]/', '-', $dw_file_name);
                        $dw_file_name = $upload_dir['path'] . '/' . $dw_file_name;
                        $wp_filetype = wp_check_filetype(basename($dw_file_name), null);

                        // Fetch and save file
                        $dw_file_url = $this->DW_FETCH_URL . $this_attachment_id;
                        $file_content = file_get_contents($dw_file_url);
                        echo "Last modified \$dw_file_name: ".$dw_file_name."\n";
                        $fp = fopen($dw_file_name, 'w');
                        fwrite($fp, $file_content);
                        fclose($fp);

                        $wp_attachment_data = array(
                            'guid' => $dw_file_name,
                            'post_mime_type' => $wp_filetype['type'],
                            'post_title' => basename($dw_file_name),
                            'post_content' => '',
                            'post_status' => 'inherit'
                        );

                        // Attach file
                        $attach_id = wp_insert_attachment($wp_attachment_data, $dw_file_name, $post_id);
                        $attach_data = wp_generate_attachment_metadata($attach_id, $dw_file_name);
                        wp_update_attachment_metadata($attach_id, $attach_data);

                        // Add attachment link to the post
                        $page .= '<code>DW_attachment:' . wp_get_attachment_link($attach_id) . '</code> ';
                    }

                    // Save page again as we may have added attachment links
                    $updated_post_data = array(
                        'ID' => $post_id,
                        'post_content' => $page
                    );

                    wp_update_post($updated_post_data);
                }


                $this->created++;

            }
        }
    }

    //Função recursiva para encontrar todas as páginas da dokuwiki e adicioná-las em uma lista
    function findWikiPages(string $index, string $dw_page): void
    {
        $url = $this->createRootRelativeURL($index, $dw_page);
        echo "Searching for links in " . $url . "\n";

        //Verifica se a página já foi visitada
        //Se foi, retorna
        if ($this->hasBeenVisited($url)) {
            echo "This url have already been visited \n";
            return;
        }

        //Marca a página como visitada
        $this->visited_urls[] = $url;

        //Obtém a página,
        $html = file_get_html($url);

        //Procura na página algum link de outras páginas da wiki e procura dentro de cada uma das páginas encontradas por outras páginas
        $list_of_links = $html->find('a[class="wikilink1"]');
        foreach($list_of_links as $link) {
            echo "Link [[".$link."]] have been found in ".$url."\n";
            $this->findWikiPages(
                $index, 
                $link->href
            );
        }


        //Se já pesquisou todos os links ou não há nenhum
        //Adiciona essa página na lista de páginas a serem importadas
        echo "Adding this page [[".$url."]] to the list of pages to be imported\n";
        echo "----------------------------------------------------------------\n";
        $this->wiki_pages_per_index[$index][] = $dw_page;

        //Retorna
        return;
    }

    function hasBeenVisited(string $dw_page): bool {
        return in_array($dw_page, $this->visited_urls);
    }

    function createRootRelativeURL(string $root, string $relative_url): string {
        $bits = parse_url($root);
        $base = $bits['scheme'] . '://' . $bits['host'];
        echo "Creating root relative url: ". $base . "\t" . "$relative_url" . "\n";
        if(strpos($relative_url, $base)===false){
            return $base.$relative_url;
        }
        //Já é relativo a raiz
        return $relative_url;
    }

    function removePageAndQuery(string $url, string $extra = ''): string {
        $str = str_replace("doku.php?id=", '', $url);
        $str = str_replace($extra, '', $str);
        return $str;
    }
}