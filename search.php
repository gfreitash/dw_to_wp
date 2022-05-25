<?php

class Search
{
    // List of Index URLs (one for each namespace is required)
    // These will be crawled, all pages will be listed out, then crawled and imported
    private array $indexes;
    private array $visited_urls;

    function __construct(array $indexes)
    {
        $this->indexes = $indexes;
        $this->visited_urls = array();
    }

    //Função recursiva para encontrar todas as páginas da dokuwiki
    function findWikiPages($dw_page)
    {

        //Verifica se a página já foi visitada
        //Se foi, retorna
        if ($this->hasBeenVisited($dw_page)) {
            return;
        }

        //Marca a página como visitada
        $this->visited_urls[] = $dw_page;

        //Verifica os links da página,
        $html = file_get_html($dw_page);

        //Se são links da mesma wiki, chama esta função novamente
        $list_of_links = $html->find('a[class="wikilink1"]');
        foreach($list_of_links as $link) {
            $this->findWikiPages($link);
        }

        //Se são links externos, torna os links disponíveis
        //Se são arquivos, obtém todos os arquivos da página e os torna disponíveis

        //Por fim, transforma esta página em um post e o adiciona na lista de posts a serem incluidos no wordpress

        //Retorna
    }

    function hasBeenVisited(string $dw_page): bool {
        return in_array($dw_page, $this->visited_urls);
    }
}
