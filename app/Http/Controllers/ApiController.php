<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Carbon\Carbon;
use GuzzleHttp\RequestOptions;


class ApiController extends Controller
{

    protected $zoomBaseUrl = 'https://api.zoom.us';

    public function create(Request $request)
    {
        $data = $request->all();

        Log::debug(json_encode($data));

        $validator = Validator::make($data, [
            'api_key' => 'required',
            'issue_key' => 'required',
            'zoom_user_email' => 'required',
            'topic' => 'required',
            'agenda' => 'required'

        ]);

        if ($validator->fails()) abort(400, $validator->errors()->first());

        $apiKey = $data['api_key'];
        $validKey = config('z2j.api_key', null);

        if (!empty($validKey) && $apiKey === $validKey) {

            $issueKey = $data['issue_key'];
            $zoomUserEmail = $data['zoom_user_email'];
            $topic = $data['topic'];
            $agenda = $data['agenda'];

            $zoomClient = new Client([
                'base_uri' => $this->zoomBaseUrl,
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . generateZoomJWT()
                ]
            ]);

            $zoomResponse = $zoomClient->post('/v2/users/' . urlencode($zoomUserEmail) . '/meetings', [
                RequestOptions::JSON => [
                    'topic' => $topic,
                    'agenda' => $agenda,
                    'type' => 1,
                ]
            ]);

            if ($zoomResponse->getStatusCode() === 201) {
                $result = json_decode($zoomResponse->getBody()->getContents(), true);

                $meetingURL = $result['join_url'];

                $jiraClient = new Client([
                    'http_errors' => false,
                    'auth' => [
                        config('services.jira.username'),
                        config('services.jira.password'),
                        'basic'
                    ]
                ]);

                $jiraResult = $jiraClient->put(config('services.jira.api_url') . '/rest/api/2/issue/' . urlencode($issueKey) . '/comment/', [
                    RequestOptions::JSON => [
                        'body' => 'Your Zoom meeting URL is: ' . $meetingURL
                    ]
                ]);

                Log::debug('JiraResult', [$jiraResult]);

                return json_encode((object)['ZoomRequest' => $zoomResponse, 'JiraRequests' => $jiraResult]);

            } else {
                Log::error(strval($zoomResponse->getStatusCode()) . ' ' . $zoomResponse->getReasonPhrase(), [$zoomResponse->getBody()->getContents()]);
                abort($zoomResponse->getStatusCode(), $zoomResponse->getBody()->getContents());
            }
        } else {
            abort(403, 'API Key Error');
        }
    }
}
