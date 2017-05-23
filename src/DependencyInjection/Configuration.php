<?php

namespace Fazland\ElasticaBundle\DependencyInjection;

use Fazland\ElasticaBundle\Elastica\Type;
use Fazland\ElasticaBundle\Serializer\Callback;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * If the kernel is running in debug mode.
     *
     * @var bool
     */
    private $debug;

    public function __construct($debug)
    {
        $this->debug = $debug;
    }

    /**
     * Generates the configuration tree.
     *
     * @return TreeBuilder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('fazland_elastica', 'array');

        $this->addClientsSection($rootNode);
        $this->addIndexesSection($rootNode);

        $rootNode
            ->children()
                ->scalarNode('default_client')
                    ->info('Defaults to the first client defined')
                ->end()
                ->arrayNode('cache')
                    ->treatNullLike([])
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('indexable_expression')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('serializer')
                    ->treatNullLike([])
                    ->children()
                        ->scalarNode('callback_class')->defaultValue(Callback::class)->end()
                        ->scalarNode('serializer')->defaultValue('serializer')->end()
                        ->arrayNode('groups')
                            ->treatNullLike([])
                            ->prototype('scalar')->end()
                        ->end()
                        ->scalarNode('version')->end()
                        ->booleanNode('serialize_null')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }

    /**
     * Adds the configuration for the "clients" key.
     */
    private function addClientsSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->fixXmlConfig('client')
            ->children()
                ->arrayNode('clients')
                    ->useAttributeAsKey('id')
                    ->prototype('array')
                        ->performNoDeepMerging()
                        // Elastica names its properties with camel case, support both
                        ->beforeNormalization()
                        ->ifTrue(function ($v) {
                            return isset($v['connection_strategy']);
                        })
                        ->then(function ($v) {
                            $v['connectionStrategy'] = $v['connection_strategy'];
                            unset($v['connection_strategy']);

                            return $v;
                        })
                        ->end()
                        // If there is no connections array key defined, assume a single connection.
                        ->beforeNormalization()
                        ->ifTrue(function ($v) {
                            return is_array($v) && ! array_key_exists('connections', $v);
                        })
                        ->then(function ($v) {
                            return [
                                'connections' => [$v],
                            ];
                        })
                        ->end()
                        ->children()
                            ->arrayNode('connections')
                                ->requiresAtLeastOneElement()
                                ->prototype('array')
                                    ->fixXmlConfig('header')
                                    ->children()
                                        ->scalarNode('url')
                                            ->validate()
                                                ->ifTrue(function ($url) {
                                                    return $url && substr($url, -1) !== '/';
                                                })
                                                ->then(function ($url) {
                                                    return $url.'/';
                                                })
                                            ->end()
                                        ->end()
                                        ->scalarNode('host')->end()
                                        ->scalarNode('port')->end()
                                        ->scalarNode('proxy')->end()
                                        ->scalarNode('aws_access_key_id')->end()
                                        ->scalarNode('aws_secret_access_key')->end()
                                        ->scalarNode('aws_region')->end()
                                        ->scalarNode('aws_session_token')->end()
                                        ->scalarNode('logger')
                                            ->defaultValue($this->debug ? 'fazland_elastica.logger' : false)
                                            ->treatNullLike('fazland_elastica.logger')
                                            ->treatTrueLike('fazland_elastica.logger')
                                        ->end()
                                        ->booleanNode('compression')->defaultFalse()->end()
                                        ->arrayNode('headers')
                                            ->normalizeKeys(false)
                                            ->useAttributeAsKey('name')
                                            ->prototype('scalar')->end()
                                        ->end()
                                        ->scalarNode('transport')->end()
                                        ->scalarNode('timeout')->end()
                                        ->scalarNode('connectTimeout')->end()
                                        ->scalarNode('retryOnConflict')
                                            ->defaultValue(0)
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                            ->scalarNode('timeout')->end()
                            ->scalarNode('connectTimeout')->end()
                            ->scalarNode('headers')->end()
                            ->scalarNode('connectionStrategy')
                                ->defaultValue('Simple')
                                ->validate()
                                    ->ifTrue(function ($strategy) {
                                        return ! is_callable($strategy) && ! in_array($strategy, ['Simple', 'RoundRobin']);
                                    })
                                    ->thenInvalid('ConnectionStrategy must be "Simple", "RoundRobin" or a callable')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * Adds the configuration for the "indexes" key.
     */
    private function addIndexesSection(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->fixXmlConfig('index')
            ->children()
                ->arrayNode('indexes')
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                        ->children()
                            ->scalarNode('index_name')
                                ->info('Defaults to the name of the index, but can be modified if the index name is different in ElasticSearch')
                            ->end()
                            ->scalarNode('use_alias')
                                ->defaultFalse()
                                ->beforeNormalization()
                                    ->ifTrue()->then(function () {
                                        return 'simple';
                                    })
                                ->end()
                            ->end()
                            ->scalarNode('client')->end()
                            ->scalarNode('finder')
                                ->treatNullLike(true)
                                ->defaultFalse()
                            ->end()
                            ->arrayNode('type_prototype')
                                ->children()
                                    ->scalarNode('analyzer')->end()
                                    ->append($this->getPersistenceNode())
                                    ->append($this->getSerializerNode())
                                ->end()
                            ->end()
                            ->variableNode('settings')->defaultValue([])->end()
                        ->end()
                        ->append($this->getTypesNode())
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * Returns the array node used for "types".
     */
    protected function getTypesNode()
    {
        $builder = new TreeBuilder();
        $node = $builder->root('types');

        $node
            ->useAttributeAsKey('name')
            ->prototype('array')
                ->treatNullLike([])
                ->beforeNormalization()
                    ->ifNull()->thenEmptyArray()
                ->end()
                // Support multiple dynamic_template formats to match the old bundle style
                // and the way ElasticSearch expects them
                ->beforeNormalization()
                    ->ifTrue(function ($v) {
                        return isset($v['dynamic_templates']);
                    })
                    ->then(function ($v) {
                        $dt = [];
                        foreach ($v['dynamic_templates'] as $key => $type) {
                            if (is_int($key)) {
                                $dt[] = $type;
                            } else {
                                $dt[][$key] = $type;
                            }
                        }

                        $v['dynamic_templates'] = $dt;

                        return $v;
                    })
                ->end()
                ->children()
                    ->scalarNode('class')
                        ->defaultValue(Type::class)
                        ->validate()
                            ->ifTrue(function ($val) { return $val !== Type::class && ! is_subclass_of($val, Type::class, true); })
                            ->thenInvalid('%s is not a valid type class. Must be a subclass of '.Type::class)
                        ->end()
                    ->end()
                    ->booleanNode('date_detection')->end()
                    ->arrayNode('dynamic_date_formats')
                        ->prototype('scalar')->end()
                    ->end()
                    ->scalarNode('analyzer')->end()
                    ->booleanNode('numeric_detection')->end()
                    ->scalarNode('dynamic')->end()
                    ->variableNode('indexable_callback')->end()
                    ->variableNode('fetch_fields')->defaultNull()->end()
                    ->variableNode('properties')
                        ->defaultNull()
                        ->treatNullLike([])
                    ->end()
                    ->append($this->getPersistenceNode())
                    ->append($this->getSerializerNode())
                ->end()
                ->append($this->getIdNode())
                ->append($this->getDynamicTemplateNode())
                ->append($this->getSourceNode())
                ->append($this->getRoutingNode())
                ->append($this->getParentNode())
                ->append($this->getAllNode())
            ->end()
        ;

        return $node;
    }

    /**
     * Returns the array node used for "properties".
     */
    protected function getPropertiesNode()
    {
        $builder = new TreeBuilder();
        $node = $builder->root('properties');

        $node
            ->useAttributeAsKey('name')
            ->prototype('variable')
                ->treatNullLike([]);

        return $node;
    }

    /**
     * Returns the array node used for "dynamic_templates".
     */
    public function getDynamicTemplateNode()
    {
        $builder = new TreeBuilder();
        $node = $builder->root('dynamic_templates');

        $node
            ->prototype('array')
                ->prototype('array')
                    ->children()
                        ->scalarNode('match')->end()
                        ->scalarNode('unmatch')->end()
                        ->scalarNode('match_mapping_type')->end()
                        ->scalarNode('path_match')->end()
                        ->scalarNode('path_unmatch')->end()
                        ->scalarNode('match_pattern')->end()
                        ->arrayNode('mapping')
                            ->prototype('variable')
                                ->treatNullLike([])
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    /**
     * Returns the array node used for "_id".
     */
    protected function getIdNode()
    {
        $builder = new TreeBuilder();
        $node = $builder->root('_id');

        $node
            ->children()
            ->scalarNode('path')->end()
            ->end()
        ;

        return $node;
    }

    /**
     * Returns the array node used for "_source".
     */
    protected function getSourceNode()
    {
        $builder = new TreeBuilder();
        $node = $builder->root('_source');

        $node
            ->children()
                ->arrayNode('excludes')
                    ->useAttributeAsKey('name')
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('includes')
                    ->useAttributeAsKey('name')
                    ->prototype('scalar')->end()
                ->end()
                ->scalarNode('compress')->end()
                ->scalarNode('compress_threshold')->end()
                ->scalarNode('enabled')->defaultTrue()->end()
            ->end()
        ;

        return $node;
    }

    /**
     * Returns the array node used for "_routing".
     */
    protected function getRoutingNode()
    {
        $builder = new TreeBuilder();
        $node = $builder->root('_routing');

        $node
            ->children()
                ->scalarNode('required')->end()
                ->scalarNode('path')->end()
            ->end()
        ;

        return $node;
    }

    /**
     * Returns the array node used for "_parent".
     */
    protected function getParentNode()
    {
        $builder = new TreeBuilder();
        $node = $builder->root('_parent');

        $node
            ->children()
                ->scalarNode('type')->end()
                ->scalarNode('property')->defaultValue(null)->end()
                ->scalarNode('identifier')->defaultValue('id')->end()
            ->end()
        ;

        return $node;
    }

    /**
     * Returns the array node used for "_all".
     */
    protected function getAllNode()
    {
        $builder = new TreeBuilder();
        $node = $builder->root('_all');

        $node
            ->children()
            ->scalarNode('enabled')->defaultValue(true)->end()
            ->scalarNode('analyzer')->end()
            ->end()
        ;

        return $node;
    }

    /**
     * @return ArrayNodeDefinition|\Symfony\Component\Config\Definition\Builder\NodeDefinition
     */
    protected function getPersistenceNode()
    {
        $builder = new TreeBuilder();
        $node = $builder->root('persistence');

        $node
            ->validate()
                ->ifTrue(function ($v) {
                    return isset($v['driver']) && 'propel' === $v['driver'] && isset($v['listener']);
                })
                    ->thenInvalid('Propel doesn\'t support listeners')
                ->ifTrue(function ($v) {
                    return isset($v['driver']) && 'propel' === $v['driver'] && isset($v['repository']);
                })
                    ->thenInvalid('Propel doesn\'t support the "repository" parameter')
            ->end()
            ->children()
                ->enumNode('driver')
                    ->defaultNull()
                    ->values(['orm', 'mongodb', 'propel', 'phpcr'])
                ->end()
                ->scalarNode('model')->defaultValue(null)->end()
                ->scalarNode('repository')->end()
                ->scalarNode('identifier')->defaultNull()->end()
                ->arrayNode('provider')
                    ->treatNullLike(true)
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('query_builder_method')->defaultValue('createQueryBuilder')->end()
                        ->scalarNode('service')->end()
                    ->end()
                ->end()
                ->arrayNode('listener')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('insert')->defaultTrue()->end()
                        ->scalarNode('update')->defaultTrue()->end()
                        ->scalarNode('delete')->defaultTrue()->end()
                        ->scalarNode('service')->end()
                    ->end()
                ->end()
                ->arrayNode('finder')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('service')->end()
                    ->end()
                ->end()
                ->arrayNode('elastica_to_model_transformer')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('ignore_missing')
                            ->defaultFalse()
                            ->info('Silently ignore results returned from Elasticsearch without corresponding persistent object.')
                        ->end()
                        ->scalarNode('service')->end()
                        ->scalarNode('fetcher')->end()
                    ->end()
                ->end()
                ->arrayNode('model_to_elastica_transformer')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('service')->end()
                    ->end()
                ->end()
                ->arrayNode('persister')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('service')->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    /**
     * @return ArrayNodeDefinition|\Symfony\Component\Config\Definition\Builder\NodeDefinition
     */
    protected function getSerializerNode()
    {
        $builder = new TreeBuilder();
        $node = $builder->root('serializer');

        $node
            ->treatNullLike([])
            ->children()
                ->arrayNode('groups')
                    ->treatNullLike([])
                    ->prototype('scalar')->end()
                ->end()
                ->scalarNode('version')->end()
                ->booleanNode('serialize_null')
                    ->defaultFalse()
                ->end()
            ->end();

        return $node;
    }
}
