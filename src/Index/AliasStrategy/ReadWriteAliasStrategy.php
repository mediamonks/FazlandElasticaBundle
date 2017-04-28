<?php

namespace Fazland\ElasticaBundle\Index\AliasStrategy;

use Elastica\Request;
use Elasticsearch\Endpoints\Indices\Alias\Get as GetAlias;
use Elasticsearch\Endpoints\Indices\Aliases\Update as UpdateAlias;
use Elasticsearch\Endpoints\Indices\Delete as DeleteIndex;
use Fazland\ElasticaBundle\Elastica\Index;

final class ReadWriteAliasStrategy implements IndexAwareAliasStrategyInterface
{
    const APPENDIX_READ = '_read';
    const APPENDIX_WRITE = '_write';

    /**
     * @var Index
     */
    private $index;

    /**
     * @var \Elastica\Client
     */
    private $client;

    /**
     * @param Index $index
     */
    public function setIndex(Index $index)
    {
        $this->index = $index;
        $this->client = $index->getClient();
    }

    public function buildName(string $originalName): string
    {
        return sprintf('%s_%s', $originalName, date('Y-m-d-His'));
    }

    public function getName(string $method, string $path): string
    {
        if (Request::GET === $method && preg_match('#/_search(/scroll)?$#i', $path)) {
            return $this->index->getName() . self::APPENDIX_READ;
        }

        return $this->index->getName() . self::APPENDIX_WRITE;
    }

    public function prePopulate()
    {
        $this->prePopulateUpdateAliases();
    }

    public function finalize()
    {
        $aliasName = $this->index->getAlias();

        $indexesAliased = $this->getAliasedIndex($aliasName);
        $this->updateAlias($aliasName, $indexesAliased);
        $this->deleteOldIndex($indexesAliased);
    }

    /**
     * @param $aliasName
     *
     * @return array
     */
    private function getAliasedIndex(string $aliasName): array
    {
        $get = new GetAlias();
        $get->setName($aliasName);

        $data = $this->client->requestEndpoint($get);
        $indexes = array_keys($data->getData());

        return $indexes;
    }

    /**
     * @param $aliasName
     * @param $indexesAliased
     *
     * @return void
     */
    private function updateAlias(string $aliasName, array $indexesAliased)
    {
        $body = [];
        foreach ($indexesAliased as $index) {
            $body['actions'][] = ['remove' => [
                'index' => $index,
                'alias' => $aliasName . self::APPENDIX_READ,
            ]];

            $body['actions'][] = ['remove' => [
                'index' => $index,
                'alias' => $aliasName . self::APPENDIX_WRITE,
            ]];
        }

        $body['actions'][] = ['add' => [
            'index' => $this->index->getName() . self::APPENDIX_READ,
            'alias' => $aliasName,
        ]];

        $update = new UpdateAlias();
        $update->setBody($body);

        $this->client->requestEndpoint($update);
    }

    /**
     * @param array $indexesAliased
     *
     * @return void
     */
    private function deleteOldIndex(array $indexesAliased)
    {
        if (empty($indexesAliased) || count($indexesAliased) > 1) {
            return;
        }

        $delete = new DeleteIndex();
        $delete->setIndex(reset($indexesAliased));
        $this->client->requestEndpoint($delete);
    }

    /**
     * @return void
     */
    private function prePopulateUpdateAliases()
    {
        $body = [];

        $body['actions'][] = ['add' => [
            'index' => $this->index->getName(),
            'alias' => $this->index->getAlias() . self::APPENDIX_WRITE,
        ]];

        $update = new UpdateAlias();
        $update->setBody($body);

        $this->client->requestEndpoint($update);
    }
}
