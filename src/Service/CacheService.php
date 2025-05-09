<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CacheService
{
    public function __construct(
        private string $projectDir,
        private CacheInterface $cache,
    ){}

    function get_texts($instance, $lang) {
        // Cache key id
        $texts_cache_key = "texts_" . $lang . "_" . $instance;

        // Fetch the texts from cache or generate them if not cached
        $texts = $this->cache->get($texts_cache_key, function (ItemInterface $item) use ($instance, $lang): array {
            $translation_path = $this->projectDir . '/translations/';
            $texts_ini = @parse_ini_file($translation_path . $lang . '/texts.ini', true);
            // if fail to load texts from instance, load from english texts.ini
            if (!$texts_ini) {
                $texts_ini = parse_ini_file($translation_path . 'en/texts.ini', true);
            }
            return $texts_ini;
        });

        return $texts;
    }

    function get_texts_decs_locator($lang) {
        // Cache key id
        $texts_cache_key = "texts_" . $lang . "_decslocator";

        // Fetch the texts from cache or generate them if not cached
        $texts = $this->cache->get($texts_cache_key, function (ItemInterface $item) use ($lang): array {
            $translation_path = $this->projectDir . '/translations/';
            $texts_ini = @parse_ini_file($translation_path . $lang . '/decs-locator.ini', true);
            // if fail to load texts from lang, load from english
            if (!$texts_ini) {
                $texts_ini = @parse_ini_file($translation_path . 'en/decs-locator.ini', true);
            }
            return $texts_ini;
        });

        return $texts;
    }

    function get_decs_first_level($lang, $api_key) {
        // Cache key id
        $cache_key = "decs_" . $lang . "_firstlevel";

        // Fetch the first level of decs or generate them if not cached
        $first_level_str = $this->cache->get($cache_key, function (ItemInterface $item) use ($lang, $api_key) {
            $api_url = "https://api.bvsalud.org/decs/v2/get-tree?lang=" . $lang . "&tree_id=";
            $opts = array(
                'http'=>array(
                  'method' => "GET",
                  'header' =>' apikey: ' . $api_key
                )
            );
            $context = stream_context_create($opts);
            $api_response = @file_get_contents($api_url, false, $context);

            return $api_response;
        });

        // Convert string to SimpleXMLElement
        $first_level_xml = simplexml_load_string($first_level_str);

        return $first_level_xml;
    }

}
?>