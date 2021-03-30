<?php

/**
 * WSSearch MediaWiki extension
 * Copyright (C) 2021  Wikibase Solutions
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

namespace WSSearch;

use Elasticsearch\ClientBuilder;
use Hooks;
use MediaWiki\MediaWikiServices;
use MWNamespace;
use ONGR\ElasticsearchDSL\Highlight\Highlight;
use Parser;
use WSSearch\QueryEngine\Aggregation\Aggregation;
use WSSearch\QueryEngine\Aggregation\PropertyAggregation;
use WSSearch\QueryEngine\Factory\QueryEngineFactory;
use WSSearch\QueryEngine\Filter\Filter;
use WSSearch\QueryEngine\Filter\SearchTermFilter;
use WSSearch\QueryEngine\Highlighter\FieldHighlighter;
use WSSearch\QueryEngine\QueryEngine;
use WSSearch\QueryEngine\Sort\Sort;
use WSSearch\SMW\PropertyFieldMapper;

/**
 * Class Search
 *
 * @package WSSearch
 */
class SearchEngine {
    /**
     * @var array
     */
    private $translations;

    /**
     * @var QueryEngine
     */
    private $query_engine;

    /**
     * Search constructor.
     *
     * @param SearchEngineConfig|null $config
     */
    public function __construct( SearchEngineConfig $config = null ) {
        $this->translations = $config->getPropertyTranslations();
        $this->query_engine = QueryEngineFactory::fromSearchEngineConfig( $config );
    }

    /**
     * Returns teh QueryEngine for this search engine.
     *
     * @return QueryEngine
     */
    public function getQueryEngine(): QueryEngine {
        return $this->query_engine;
    }

    /**
     * Executes the given ElasticSearch query and returns the result.
     *
     * @param array $query
     * @param array $hosts
     * @return array
     * @throws \Exception
     */
    public function doQuery( array $query, array $hosts ): array {
        // Allow other extensions to modify the query
        Hooks::run( "WSSearchBeforeElasticQuery", [ &$query, &$hosts ] );

        return ClientBuilder::create()->setHosts( $hosts )->build()->search( $query );
    }

    /**
     * Adds the given search term.
     *
     * @param string $search_term
     */
    public function addSearchTerm( string $search_term ) {
        $search_term_filter = new SearchTermFilter( $search_term );
        $this->query_engine->addFunctionScoreFilter( $search_term_filter );
    }

    /**
     * Performs an ElasticSearch query.
     *
     * @return array
     *
     * @throws \Exception
     */
    public function doSearch(): array {
        $elastic_query = $this->query_engine->toArray();

        $results = $this->doQuery( $elastic_query, $this->query_engine->getElasticHosts() );
        $results = $this->applyResultTranslations( $results );

        return [
            "hits"  => json_encode( $results["hits"]["hits"] ),
            "total" => $results["hits"]["total"],
            "aggs"  => $results["aggregations"]
        ];
    }

    /**
     * Applies necessary translations to the ElasticSearch query result.
     *
     * @param array $results
     * @return array
     * @throws \Exception
     */
    private function applyResultTranslations( array $results ): array {
        $results = $this->doFacetTranslations( $results );
        $results = $this->doNamespaceTranslations( $results );

        // Allow other extensions to modify the result
        Hooks::run( "WSSearchApplyResultTranslations", [ &$results ] );

        return $results;
    }

    /**
     * Does facet translations.
     *
     * @param array $results
     * @return array
     */
    private function doFacetTranslations( array $results ): array {
        if ( !isset( $results["aggregations"] ) ) {
            return $results;
        }

        $aggregations = $results["aggregations"];

        foreach ( $aggregations as $property_name => $aggregate_data ) {
            if ( !isset( $this->translations[$property_name] ) ) {
                // No translation available
                continue;
            }

            $parts = explode( ":", $this->translations[$property_name] );

            if ( $parts[0] = "namespace" ) {
                foreach ( $results['aggregations'][$property_name]['buckets'] as $bucket_key => $bucket_value ) {
                    $namespace = MWNamespace::getCanonicalName( $bucket_value['key'] );
                    $results['aggregations'][$property_name]['buckets'][$bucket_key]['name'] = $namespace;
                }
            }
        }

        return $results;
    }

    /**
     * Translates namespace IDs to their canonical name.
     *
     * @param array $results
     * @return array
     */
    private function doNamespaceTranslations( array $results ): array {
        // Translate namespace IDs to their canonical name
        foreach ( $results['hits']['hits'] as $key => $value ) {
            $results['hits']['hits'][$key]['_source']['subject']['namespacename'] = MWNamespace::getCanonicalName( $value['_source']['subject']['namespace'] );
        }

        return $results;
    }
}
