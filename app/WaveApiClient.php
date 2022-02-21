<?php

namespace App;

use App\Exceptions\WaveApiClientException;
use Softonic\GraphQL\ClientBuilder;

class WaveApiClient
{
    protected $graphql_client;

    public function __construct()
    {
        $this->graphql_client = ClientBuilder::build(
            config('services.wave.graphql_endpoint'),
            [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . config('services.wave.full_access_token')
                ]
            ]
        );
    }

    public function listBusinesses() : array
    {
        $query = <<<'QUERY'
        query {
            businesses(page: 1, pageSize: 20) {
                pageInfo {
                    currentPage
                    totalPages
                    totalCount
                }
                edges {
                    node {
                        id
                        name
                        isClassicAccounting
                        isClassicInvoicing
                        isPersonal
                    }
                }
            }
        }
        QUERY;

        $response = $this->graphql_client->query($query);

        if ($response->hasErrors()) {
            $exception = new WaveApiClientException('Error fetching businesses');
            $exception->setErrors($response->getErrors());
            throw $exception;
        }

        return $response->getData();
    }

    public function listAccounts(string $businessId) : array
    {
        $query = <<<'QUERY'
        query ($businessId: ID!) {
            business(id: $businessId) {
                id
                accounts(page: 1, pageSize: 500) {
                    pageInfo {
                        currentPage
                        totalPages
                        totalCount
                    }
                    edges {
                        node {
                            id
                            name
                            description
                            displayId
                            type {
                                name
                                value
                            }
                            subtype {
                                name
                                value
                            }
                            normalBalanceType
                            isArchived
                        }
                    }
                }
            }
        }
        QUERY;

        $variables = ['businessId' => $businessId];

        $response = $this->graphql_client->query($query, $variables);

        if ($response->hasErrors()) {
            $exception = new WaveApiClientException('Error fetching accounts');
            $exception->setErrors($response->getErrors());
            throw $exception;
        }

        return $response->getData();
    }

    public function createTransaction(array $payload)
    {
        $query = <<<'QUERY'
        mutation ($input: MoneyTransactionCreateInput!) {
            moneyTransactionCreate(input: $input) {
                didSucceed
                inputErrors {
                    code
                    message
                    path
                }
                transaction {
                    id
                }
            }
        }
        QUERY;

        $response = $this->graphql_client->query($query, $payload);

        if ($response->hasErrors()) {
            $exception = new WaveApiClientException('Error creating transaction');
            $exception->setErrors($response->getErrors());
            throw $exception;
        }

        if (!$response->getData()['moneyTransactionCreate']['didSucceed']) {
            $exception = new WaveApiClientException('Error creating transaction');
            $exception->setErrors($response->getData()['moneyTransactionCreate']['inputErrors']);
            throw $exception;
        }

        return $response->getData();
    }
}
