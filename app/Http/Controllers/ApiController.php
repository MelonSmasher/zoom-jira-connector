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
            'zoom_user_id' => 'required',
            'meeting_time' => 'required',
            'topic' => 'required',
            'agenda' => 'required'

        ]);

        if ($validator->fails()) abort(400, $validator->errors()->first());

        $apiKey = $data['api_key'];
        $validKey = config('z2j.api_key', null);

        if (!empty($validKey) && $apiKey === $validKey) {

            $issueKey = $data['issue_key'];
            $meetingTimeZone = config('app.local_timezone');
            $zoomUserId = $data['zoom_user_id'];
            Log::debug('Input time: ' . $data['meeting_time']);
            $meetingTime = Carbon::createFromFormat('n/j/y g:i A', $data['meeting_time'], $meetingTimeZone)->format('Y-m-d\'T\'H:i:s');
            Log::debug('Formed meeting time: ' . $meetingTime);
            $topic = $data['topic'];
            $agenda = $data['agenda'];

            $zoomClient = new Client([
                'base_uri' => $this->zoomBaseUrl,
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . generateZoomJWT()
                ]
            ]);

            $zoomResponse = $zoomClient->post('/v2/users/' . $zoomUserId . '/meetings', [
                RequestOptions::JSON => [
                    'topic' => $topic,
                    'agenda' => $agenda,
                    'type' => 2, //Scheduled Meeting
                    'start_time' => $meetingTime,
                    'timezone' => $meetingTimeZone
                ]
            ]);

            if ($zoomResponse->getStatusCode() === 201) {
                $result = json_decode($zoomResponse->getBody()->getContents(), true);

                $meetingURL = $result['join_url'];

                $jiraClient = new Client([
                    'base_uri' => config('services.jira.api'),
                    'auth' => [
                        config('services.jira.username'),
                        config('services.jira.password')
                    ]
                ]);

                $result1 = $jiraClient->put('/issue/' . $issueKey, [
                    RequestOptions::JSON => [
                        "update" => [
                            "fields" => [
                                "resource" => $meetingURL
                            ]
                        ]
                    ]
                ]);

                $result2 = $jiraClient->put('/issue/' . $issueKey . '/comment', [
                    RequestOptions::JSON => [
                        "body" => 'Your Zoom meeting URL is: ' . $meetingURL
                    ]
                ]);

                return json_encode((object)['ZoomRequest' => $zoomResponse, 'JiraRequests' => [$result1, $result2]]);

            } else {
                Log::error(strval($zoomResponse->getStatusCode()) . ' ' . $zoomResponse->getReasonPhrase(), [$zoomResponse->getBody()->getContents()]);
                abort($zoomResponse->getStatusCode(), $zoomResponse->getBody()->getContents());
            }
        } else {
            abort(403, 'API Key Error');
        }
    }
}
