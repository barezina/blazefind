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
        system("find /host -printf \"%T@|%s|%h|%f\n\" > files.txt");

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

            $deleteParams = [
                'index' => $elasticIndexName . '-000001'
            ];

            $response = $client->indices()->delete($deleteParams);

        }

        $this->info('index doesnt exist');

        $params = [
            'index' => $elasticIndexName . '-000001',
            'body' => [
                'mappings' => [
                    'properties' => [
                        'id' => ['type' => 'text'],
                        'created_at' => ['type' => 'date'],
                        'size' => ['type' => 'long'],
                        'path' => ['type' => 'text'],
                        'filename' => [
                            'type' => 'text',
                            'fields' => [
                                'ngram' => [
                                    'type' => 'text',
                                    'analyzer' => 'my_analyzer'
                                ]
                            ]
                        ]
                    ]
                ],
                'settings' => [
                    'analysis' => [
                        'analyzer' => [
                            'my_analyzer' => [
                                'tokenizer' => 'my_tokenizer'
                            ]
                        ],
                        'tokenizer' => [
                            'my_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 3,
                                'max_gram' => 3,
                                'token_chars' => [
                                    'letter',
                                    'digit'
                                ]
                            ]
                        ]
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


        $reader = Reader::createFromPath('files.txt', 'r');
        $reader->setDelimiter('|');
        $stmt = new Statement();

        $records = $stmt->process($reader);

        $params = ['body' => []];
        $counter = 1;

        foreach ($records as $record) {

            $unixWithoutMicro = explode('.', $record[0])[0];
            $createdAt = Carbon::createFromTimestamp($unixWithoutMicro);
            $size = $record[1];
            $path = $record[2];
            $path = str_replace('/host/', '/', $path);
            $filename = $record[3];

            $id = $path . '/' . $filename;

            $this->info("$unixWithoutMicro $size $path $filename");

            $params['body'][] = [
                'index' => [
                    '_index' => $elasticIndexName,
                    '_id'    => $id
                ]
            ];

            $params['body'][] = [
                'created_at' => $createdAt,
                'size' => $size,
                'path' => $path,
                'filename' => $filename
            ];

            // Every 1000 documents stop and send the bulk request
            if ($counter % 1000 == 0) {
                $this->info('sending 1000...');
                $responses = $client->bulk($params);

                // erase the old bulk request
                $params = ['body' => []];

                // unset the bulk response when you are done to save memory
                unset($responses);

                $counter = 1;
            } else {
                $counter++;
            }
        }

        // Send the last batch if it exists
        if (!empty($params['body'])) {
            $responses = $client->bulk($params);
        }
    }
}
