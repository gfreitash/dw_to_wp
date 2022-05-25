<?php

class Search
{
    // List of Index URLs (one for each namespace is required)
    // These will be crawled, all pages will be listed out, then crawled and imported
    private array $INDEXES;
    private $AUTHOR_ID = 1;
    private $PAGE_NAME_PREFIX = 'dw-old-';
    private int $page_count;
    private array $visited_urls;
    //Essa variável é um array multidimensional que armazenará uma lista de todas as páginas da wiki encontradas de uma partir da página índice
    private array $wiki_pages_per_index = array();

    function __construct(array $indexes)
    {
        $this->INDEXES = $indexes;
        $this->visited_urls = array();
    }

    function importWikiPages() {
        foreach($this->INDEXES as $index) {
            echo "Crawling $index for page links...\n";
            $this->findWikiPages($index, $index);
            
            //Para cada página encontrada, inicie o processo de importação.
            foreach($this->wiki_pages_per_index[$index] as $slug) {

            }
        }
    }

    //Função recursiva para encontrar todas as páginas da dokuwiki e adicioná-las em uma lista
    function findWikiPages(string $index, string $dw_page): void
    {
        $url = $this->createRootRelativeURL($index, $dw_page);

        //Verifica se a página já foi visitada
        //Se foi, retorna
        if ($this->hasBeenVisited($url)) {
            return;
        }

        //Marca a página como visitada
        $this->visited_urls[] = $url;

        //Obtém a página,
        $html = file_get_html($url);

        //Procura na página algum link de outras páginas da wiki e procura dentro de cada uma das páginas encontradas por outras páginas
        $list_of_links = $html->find('a[class="wikilink1"]');
        foreach($list_of_links as $link) {
            $this->findWikiPages(
                $index, 
                $this->createRootRelativeURL($index, $link->href)
            );
        }

        //Se já pesquisou todos os links ou não há nenhum
        //Adiciona essa página na lista de páginas a serem importadas
        $this->wiki_pages_per_index[$index] = $dw_page;

        //Retorna
        return;
    }

    function hasBeenVisited(string $dw_page): bool {
        return in_array($dw_page, $this->visited_urls);
    }

    function createRootRelativeURL(string $root, string $relative_url): string {
        $bits = parse_url($root);
        $base = $bits['scheme'] . '://' . $bits['host'];
        return $base.$relative_url;
    }
}
