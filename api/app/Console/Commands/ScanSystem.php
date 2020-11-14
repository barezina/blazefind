<?php

namespace App\Console\Commands;

use Elasticsearch\Client;
use Illuminate\Console\Command;
use Elasticsearch\ClientBuilder;
use League\Csv\Reader;
use League\Csv\Statement;
use Carbon\Carbon;

class ScanSystem extends Command
{
    protected $signature = 'scan:system';
    protected $description = 'scans the system for files and indexes them';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Blazefind v1.0.0');
        $this->info('Scanning system for files...');
        // system("find /host/code -printf \"%T@|%s|%h|%f\n\" > files.txt");

        // connect to elastic
        $hosts = ['elasticsearch:9200'];

        $clientBuilder = ClientBuilder::create();
        $clientBuilder->setHosts($hosts);
        $client = $clientBuilder->build();

        // set index name
        $elasticIndexName = 'files';

        // check if the index exists
        $params = [
            'index' => $elasticIndexName
        ];

        $indexExists = $client->indices()->exists($params);

        if ($indexExists) {
            $this->info('index exists');
        } else {
            $this->info('index doesnt exist');

            $params = [
                'index' => $elasticIndexName . '-000001',
                'body' => [
                    'mappings' => [
                        'properties' => [
                            'created_at' => ['type' => 'date'],
                            'size' => ['type' => 'long'],
                            'path' => ['type' => 'text'],
                            'filename' => ['type' => 'text']
                        ]
                    ]
                ]
            ];

            $response = $client->indices()->create($params);

            $params = [
                'body' => [
                    'actions' => [
                        [
                            'add' => [
                                'index' => $elasticIndexName . '-000001',
                                'alias' => $elasticIndexName
                            ]
                        ]
                    ]
                ]
            ];

            $response = $client->indices()->updateAliases($params);
        }

        $reader = Reader::createFromPath('files.txt', 'r');
        $reader->setDelimiter('|');
        $stmt = new Statement();

        $records = $stmt->process($reader);

        $params = ['body' => []];

        foreach ($records as $record) {

            $unixWithoutMicro = explode('.', $record[0])[0];
            $createdAt = Carbon::createFromTimestamp($unixWithoutMicro);
            $size = $record[1];
            $path = $record[2];
            $filename = $record[3];

            $this->info("$unixWithoutMicro $size $path $filename");

            $params['body'][] = [
                'index' => [
                    '_index' => $elasticIndexName,
                ]
            ];

            $params['body'][] = [
                'created_at' => $unixWithoutMicro,
                'size' => $size,
                'path' => $path,
                'filename' => $filename
            ];

            break;
        }


        $response = $client->bulk($params);
        print_r($response);


    }
}
