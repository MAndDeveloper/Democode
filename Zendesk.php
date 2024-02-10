<?php

/**
 * Zendesk File Doc Comment
 *
 * PHP Version 8.0.7
 *
 * @category WhiteStores
 * @package  WhiteStores_CustomerService
 * @author   Mike Andrews <michael.andrews@whitestores.co.uk>
 * @license  https://whitestores.co.uk UNLICENSED
 * @link     https://github.com/White-Stores/customer-service/
 */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Zendesk API Controller
 *
 * @category WhiteStores
 * @package  WhiteStores_CustomerService
 * @author   Mike Andrews <michael.andrews@whitestores.co.uk>
 * @license  https://whitestores.co.uk UNLICENSED
 * @link     https://github.com/White-Stores/customer-service/
 */

class Zendesk extends Controller
{
    /**
     * Create Zendesk Ticket
     *
     * @param string                   $orderNumber The order number
     * @param \Illuminate\Http\Request $request     The request object
     *
     * @return void
     */
    public function create($orderNumber, Request $request) {

        $ticket = $request->input('ticket');
        $endpoint = "https://whitestores.zendesk.com/api/v2/tickets.json";

        $body = $ticket['comment'];

        $ticketSend = new \stdClass();
        $ticketSend->comment = $body;
        $ticketSend->priority = $ticket['priority'];
        $ticketSend->subject = $orderNumber . " - " . $ticket['subject'];

        $request = Http::withHeaders([
                'Authorization' => 'Basic ****',
                'Content-Type' => 'application/json'
            ])->post($endpoint, [
                'ticket' => $ticketSend,
            ]);

        $return = [
            'status' => 200,
            'result' => $request->json(),
        ];

        return response()->json($return);
    }
}
