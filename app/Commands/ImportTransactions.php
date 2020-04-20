<?php

namespace App\Commands;

use App\Exceptions\WaveApiClientException;
use App\StripeApiClient;
use App\WaveApiClient;
use Carbon\Carbon;
use Carbon\CarbonTimeZone;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class ImportTransactions extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'import
                            {--live-run : Whether to run the import in live mode (defaults to a dry run)}
                            {--date= : The first date to pull transactions from (Y-m-d, UTC)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Run or test an import of data from Stripe';

    protected $stripe;

    protected $wave;

    public function __construct(StripeApiClient $stripe_client, WaveApiClient $wave_client)
    {
        parent::__construct();

        $this->stripe = $stripe_client;
        $this->wave = $wave_client;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $business_id = $this->getBusinessId();

        [$sales_tax_account_id, $sales_tax_account_rate] = $this->getSalesTaxAccount($business_id);
        $anchor_account_id = $this->getAnchorAccountId($business_id);
        $stripe_fee_account_id = $this->getStripeFeeAccountId($business_id);
        $ticket_account_id = $this->getTicketSalesAccountId($business_id);
        $sponsorship_account_id = $this->getSponsorshipAccountId($business_id);

        $start_date = $this->option('date');
        if ($start_date) {
            $start_date = Carbon::parse($start_date, new CarbonTimeZone('UTC'))->timestamp;
        }

        $payouts = $this->stripe->listPayouts($start_date);

        $bar = $this->output->createProgressBar($payouts->count());

        $bar->start();

        foreach ($payouts as $payout) {
            $transactions = $this->stripe->getTransactionsForPayout($payout->id);

            $payload = [
                'input' => [
                    'businessId' => $business_id,
                    'externalId' => config('services.wave.prefix') . $payout->id,
                    'date' => Carbon::createFromTimestampUTC($payout->arrival_date)->format('Y-m-d'),
                    'description' => $payout->description . ' ' . $payout->id,
                    'anchor' => [
                        'direction' => $payout->amount > 0 ? 'DEPOSIT' : 'WITHDRAWAL',
                        'accountId' => $anchor_account_id,
                        'amount' => abs($payout->amount) / 100,
                    ],
                    'lineItems' => [],
                ]
            ];

            $sponsorships_total = $transactions->filter(function ($transaction) {
                return Str::contains(strtolower($transaction->description), 'sponsorship');
            })
            ->reduce(fn($carry, $transaction) => ($carry + $transaction->amount), 0);
            if ($sponsorships_total !== 0) {
                $payload['input']['lineItems'][] = [
                    'amount' => abs($sponsorships_total) / 100,
                    'accountId' => $sponsorship_account_id,
                    'balance' => $sponsorships_total > 0 ? 'CREDIT' : 'DEBIT',
                    'description' => 'Sponsorships' . ($sponsorships_total > 0 ? '' : ' (Refund)')
                ];
            }

            $sponsorships_fee_total = $transactions->filter(function ($transaction) {
                return Str::contains(strtolower($transaction->description), 'sponsorship');
            })
            ->reduce(fn($carry, $transaction) => ($carry + $transaction->fee), 0);
            if ($sponsorships_fee_total !== 0) {
                $payload['input']['lineItems'][] = [
                    'accountId' => $stripe_fee_account_id,
                    'amount' => $sponsorships_fee_total / 100,
                    'balance' => 'DEBIT',
                    'description' => 'Sponsorship Stripe fees',
                ];
            }

            $sales_total = $transactions->filter(function ($transaction) {
                return !Str::contains(strtolower($transaction->description), 'sponsorship');
            })
            ->reduce(fn($carry, $transaction) => ($carry + $transaction->amount), 0);
            if ($sales_total !== 0) {
                $sales_tax_amount = (
                    abs($sales_total)
                    - abs($sales_total / (1 + $sales_tax_account_rate))
                ) / 100;
                $payload['input']['lineItems'][] = [
                    'amount' => abs($sales_total) / 100,
                    'accountId' => $ticket_account_id,
                    'balance' => $sales_total > 0 ? 'CREDIT' : 'DEBIT',
                    'description' => 'Total ticket purchases amount' . ($sales_total > 0 ? '' : ' (Refund)'),
                    'taxes' => [
                        'salesTaxId' => $sales_tax_account_id,
                        'amount' => round($sales_tax_amount, 2)
                    ]
                ];
            }

            $sales_fee_total = $transactions->filter(function ($transaction) {
                return !Str::contains(strtolower($transaction->description), 'sponsorship');
            })
            ->reduce(fn($carry, $transaction) => ($carry + $transaction->fee), 0);
            if ($sales_fee_total !== 0 && $sales_total > 0) {
                $payload['input']['lineItems'][] = [
                    'accountId' => $stripe_fee_account_id,
                    'amount' => $sales_fee_total / 100,
                    'balance' => 'DEBIT',
                    'description' => 'Stripe fees',
                ];
            }

            if ($this->option('live-run')) {
            try {
                $this->wave->createTransaction($payload);
            } catch (WaveApiClientException $e) {
                dump($e->getMessage());
                dump($e->getErrors());
            }
            } else {
                $this->line('Dry run: not importing payout ' . $payout->id);
                $this->line(json_encode($payload));
            }

            $bar->advance();
        }

        $bar->finish();
    }

    protected function getBusinessId()
    {
        $businesses = collect($this->wave->listBusinesses()['businesses']['edges'] ?? []);

        $business = $this->choice(
            'Select a Business',
            $businesses->pluck('node.name')->all(),
        );

        return $businesses->where('node.name', $business)->first()['node']['id'];
    }

    protected function getAnchorAccountId(string $business_id)
    {
        $accounts = $this->getAccounts($business_id);

        $account = $this->choice(
            'Which account should be used as the anchor?',
            $accounts->pluck('node.name')->all(),
        );

        $this->line('You selected account `' . $account . '` as the anchor account.');

        return $accounts->where('node.name', $account)->first()['node']['id'];
    }

    protected function getTicketSalesAccountId(string $business_id)
    {
        $accounts = $this->getAccounts($business_id);

        $account = $this->choice(
            'Which account should be used for ticket sales income?',
            $accounts->pluck('node.name')->all(),
        );

        $this->line('You selected account `' . $account . '` as the ticket sales account.');

        return $accounts->where('node.name', $account)->first()['node']['id'];
    }

    protected function getSponsorshipAccountId(string $business_id)
    {
        $accounts = $this->getAccounts($business_id);

        $account = $this->choice(
            'Which account should be used for sponsorship income?',
            $accounts->pluck('node.name')->all(),
        );

        $this->line('You selected account `' . $account . '` as the sponsorship account.');

        return $accounts->where('node.name', $account)->first()['node']['id'];
    }

    protected function getStripeFeeAccountId(string $business_id)
    {
        $accounts = $this->getAccounts($business_id);

        $account = $this->choice(
            'Which account should be used for Stripe fees?',
            $accounts->pluck('node.name')->all(),
        );

        $this->line('You selected account `' . $account . '` as the stripe fee account.');

        return $accounts->where('node.name', $account)->first()['node']['id'];
    }

    protected function getSalesTaxAccount(string $business_id)
    {
        try {
            $accounts = $this->wave->getSalesTaxes($business_id);
        } catch (WaveApiClientException $e) {
            $this->error($e->getMessage());
            $this->error(json_encode($e->getErrors()));
            exit;
        }

        $accounts = collect($accounts['business']['salesTaxes']['edges'] ?? []);

        $account = $this->choice(
            'Which account should be used for sales tax?',
            $accounts->pluck('node.name')->all(),
        );

        $this->line('You selected account `' . $account . '` as the sales tax account.');

        return [
            $accounts->where('node.name', $account)->first()['node']['id'],
            $accounts->where('node.name', $account)->first()['node']['rate'],
        ];
    }

    protected function getAccounts(string $business_id)
    {
        try {
            $accounts = $this->wave->listAccounts($business_id);
        } catch (WaveApiClientException $e) {
            $this->error($e->getMessage());
            $this->error(json_encode($e->getErrors()));
            exit;
        }

        return collect($accounts['business']['accounts']['edges'] ?? []);
    }
}
