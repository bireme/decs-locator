<?php

namespace App\Controller;

use App\Service\CacheService;
use App\Service\HttpFetchService;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use Detection\MobileDetect;

final class DeCSLocatorController extends AbstractController
{

    public function __construct(
        private CacheService $cache,
        private HttpFetchService $httpFetch,
    ){}

    #[Route('locate/')]
    public function index(Request $request): Response
    {

        $params = [];
        foreach($request->query as $key => $value) {
          $params[$key] = $value;
        }

        $lang = $params['lang'] ?? 'pt';

        // get texts used in template
        $texts = $this->cache->get_texts_decs_locator($lang);

        $tree_id = $request->query->get("tree_id", "");        // D02.065.589.099.750.124
        $descriptor = $request->query->get("descriptor", "");  // get detais of specific descriptor
        $mode = $request->query->get("mode");                  // mode dataentry

        if ($descriptor != ''){
            $api_url = "https://api.bvsalud.org/decs/v2/search-boolean?lang=" . $lang;
            $api_url .= "&bool=101%20" . str_replace(' ','%20', $descriptor); // get descriptor by authorized term
        }else{
            $api_url = "https://api.bvsalud.org/decs/v2/get-tree?lang=" . $lang;
            $api_url .= "&tree_id=" . $tree_id;        // get descriptor by tree
        }

        $API_KEY = ($mode == 'dataentry' ? $_ENV['DECS_APIKEY_DATAENTRY'] : $_ENV['DECS_APIKEY_LOCATE']);

        $opts = array(
            'http'=>array(
              'method' => "GET",
              'header' =>' apikey: ' . $API_KEY
            )
        );

        // for first level page use cache to avoid unnecessary requests to API
        if ( $tree_id == '' && $descriptor == ''){
            $decs_xml = $this->cache->get_decs_first_level($lang, $API_KEY);
        }else{
            $context = stream_context_create($opts);
            $api_result = $this->httpFetch->getContents($api_url, $context);

            $decs_xml = ($api_result !== false) ? @simplexml_load_string($api_result) : false;
        }

        // start session
        $SESSION = $request->getSession();
        $SESSION->start();

        if ($decs_xml !== false && isset($decs_xml->decsws_response->tree->ancestors->term_list)) {
            $ancestors_tree = array();
            foreach ($decs_xml->decsws_response->tree->ancestors->term_list->term as $ancestor) {
                //var_dump($ancestor);
                $tree_id = (string) $ancestor['tree_id'];
                $ancestors_tree[] = $tree_id . '|' . (string) $ancestor;
            }

            $total_ancestors = count($ancestors_tree);
            $ancestors_i_tree = array(); // ancestors individual tree
            $tree = 0;
            for ($i = 0; $i < $total_ancestors; $i++){

                $ancestors_i_tree[$tree][] = $ancestors_tree[$i] ;
                if ($i+1 < $total_ancestors) {
                    $current_tree_id = preg_split('/\|/', $ancestors_tree[$i]);
                    $next_tree_id = preg_split('/\|/', $ancestors_tree[$i+1]);

                    if ( strlen($current_tree_id[0]) >= strlen($next_tree_id[0]) ) {
                        $tree++;
                    }
                }

            }
        }

        // output vars
        $output_array = array();
        $output_array['current_page'] = 'decs_lookup';
        $output_array['lang'] = $lang;
        $output_array['decs'] = ($decs_xml !== false) ? $decs_xml->decsws_response : null;
        $output_array['ancestors_i_tree'] = (isset($ancestors_i_tree) ? $ancestors_i_tree : '');
        $output_array['texts'] = $texts;
        $output_array['tree_id_category'] = substr($tree_id,0,1);
        $output_array['params'] = $params;
        $output_array['filters'] = ( isset($params['filter']) ? $params['filter'] : array() );
        $output_array['mode'] = $mode;
        $output_array['filter_prefix'] = ( isset($config->decs_locate_filter) ? $config->decs_locate_filter : 'mh' );

        return $this->render('regional/decs-locator-page.html', $output_array);

    }
}
