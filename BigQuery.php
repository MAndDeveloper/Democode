<?php
/**
 * BigQuery File Doc Comment
 * 
 * PHP Version 8.0.7
 * 
 * @category WhiteStores
 * @package  WhiteStores_NsConnectorService
 * @author   Mike Andrews <michael.andrews@whitestores.co.uk>
 * @license  https://whitestores.co.uk UNLICENSED
 * @link     https://github.com/White-Stores/nsConnectorService
 */
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use App\Util\NetSuiteRequest;
use App\Util\LogOutput;
use Illuminate\Support\Facades\Cache;
use App\Jobs\DataClean;

/**
 * BigQuery Class
 * 
 * Processes incoming queries from endpoint for sending to netsuite
 *
 * @category WhiteStores
 * @package  WhiteStores_NsConnectorService
 * @author   Mike Andrews <michael.andrews@whitestores.co.uk>
 * @license  https://whitestores.co.uk UNLICENSED
 * @link     https://github.com/White-Stores/nsConnectorService
 */
class BigQuery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $_json;

    /**
     * Create a new job instance.
     *
     * @param json $_json JSON
     * 
     * @return void
     */
    public function __construct($_json)
    {
        $this->_json = $_json;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (!is_array($this->_json->columns)) {
            abort(400, 'Please set an array of columns to fetch');
        }
        if (!isset($this->_json->format)) {
            abort(400, 'Please specify format data');
        }

        $queries = $this->_json->columns;
        $filters = (!isset($this->_json->filters)
            || !is_array($this->_json->filters))
            ? [] : $this->_json->filters;
        $format = $this->_json->format;

        if (count($queries) < 1) {
            abort(400, 'Please set column values');
        }

        if (!isset($format->resultsPerPage)) {
            $format->resultsPerPage = 10;
        }

        if (!isset($format->cache)) {
            $format->cache = false;
            $format->eol = 0;
        }

        if (!isset($format->page)) {
            $format->page = 1;
        }

        if (!isset($format->type)) {
            abort(400, 'Please set a format type');
        }

        if (!isset($format->eol) && $format->cache === true) {
            abort(400, 'Please designate an eol value');
        }

        $unhash = '';
        foreach ($queries as $query) {
            $unhash .= $query->name;
        }

        foreach ($filters as $filter) {
            $unhash .= $filter->filter;
        };

        if ($format->page <= 0) {
            abort(400, 'Invalid page selected, this must be 1 or greater');
        }

        $hash = md5($unhash);

        $cond = [];
        $filt = [];

        foreach ($filters as $filter) {
            $cond = explode(", ", $filter->filter);
            array_push($filt, $cond);
            if (isset($filter->follow)) {
                if ($filter->follow != "") {
                    array_push($filt, $filter->follow);
                }
            } else {
                $filter->follow == "";
                array_push($filt, $filter->follow);
            }
            $cond = [];
        }

        $page = 0;
        $count = 0;

        $this->_details['version'] = "Live";
        
        $date = date("Y-m-d H:i:s");

        /**
         * Fetches data from netsuite
         * 
         * @param array  $queries query columns
         * @param array  $filt    search filters
         * @param object $format  Request formating data
         * @param int    $page    offset page counter
         * @param int    $count   result counter
         * @param string $hash    identifier hash
         * @param string $date    Date
         * 
         * @return void
         */
        function _gather($queries, $filt, $format, $page, $count, $hash, $date)
        {
            LogOutput::print(
                '\App\Jobs\BigQuery',
                'Memory usage: ' . memory_get_usage(true)
            );

            set_time_limit(300);

            $response = NetSuiteRequest::post(
                '/app/site/hosting/restlet.nl?script=859&deploy=1',
                [
                    'lastUpdate' => 0,
                    'columns' => $queries,
                    'maxResults' => 5000,
                    'filters' => $filt,
                    'offsetPage' => $page,
                    'type' => $format->type,
                ]
            );

            if ($response['code'] !== 200) {
                LogOutput::print(
                    '\App\Jobs\BigQuery',
                    'Failed to get item list'
                );
                abort(400, 'Failed to connect to NetSuite');
            }

            $committable = [];

            foreach ($response['data']->items as $index => $item) {
                $count++;
                $committable[] = [
                    'id' => $count,
                    'eol' => $format->eol,
                    'columns' => json_encode($queries),
                    'filters' => json_encode($filt),
                    'results' => $format->resultsPerPage,
                    'page' => $format->page,
                    'type' => $format->type,
                    'response' => json_encode($item),
                    'hash' => $hash,
                    'created_at' => $date,
                    'updated_at' => $date,
                ];

                if (count($committable) >= 1000) {
                    DB::table('query_cache')->upsert(
                        $committable,
                        ['hash', 'id'],
                        ['response', 'updated_at']
                    );
                    $committable = [];
                }
            }
            DB::table('query_cache')->upsert(
                $committable,
                ['hash', 'id'],
                ['response', 'updated_at']
            );
            $committable = [];

            $page++;
            $resultCount = count($response['data']->items);
            unset($response);
            unset($committable);

            if ($resultCount == 5000) {
                _gather($queries, $filt, $format, $page, $count, $hash, $date);
            }
        }
        
        _gather($queries, $filt, $format, $page, $count, $hash, $date);

        DataClean::dispatch($date, $hash);

        Cache::forget($hash);
    }
}
