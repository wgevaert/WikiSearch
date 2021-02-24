<?php


namespace WSSearch\QueryEngine;

use ONGR\ElasticsearchDSL\BuilderInterface;

/**
 * Interface QueryConvertable
 *
 * Represents an object that can be converted to a BuilderInterface.
 *
 * @package WSSearch\QueryEngine
 */
interface QueryConvertable {
    /**
     * Converts the object to a BuilderInterface for use in the QueryEngine.
     *
     * @return BuilderInterface
     */
    public function toQuery(): BuilderInterface;
}