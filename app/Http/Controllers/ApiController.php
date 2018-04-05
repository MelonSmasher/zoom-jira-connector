<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Carbon\Carbon;


class ApiController extends Controller
{

    protected $zoomBaseUrl = 'https://api.zoom.us/v2';

    public function create(Request $request)
    {
        $data = $request->all();

        Log::debug(json_encode($data));

        $validator = Validator::make($data, [
            'api_key' => 'required',
            'issue_key' => 'required',
            'zoom_user_email' => 'required|email',
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
            $zoomUserEmail = $data['zoom_user_email'];

            Log::debug('Input time: ' . $data['meeting_time']);

            $meetingTime = Carbon::createFromTimestamp($data['meeting_time'], $meetingTimeZone)->format('Y-m-d\'T\'H:i:s');
            $topic = $data['topic'];
            $agenda = $data['agenda'];

            $zoomClient = new Client([
                'base_uri' => $this->zoomBaseUrl,
                'headers' => [
                    'Authorization' => 'Bearer ' . generateZoomJWT()
                ]
            ]);

            try {
                $zoomResponse = $zoomClient->request('POST', '/users/' . $zoomUserEmail . '/meetings', [
                    'form_params' => [
                        'topic' => $topic,
                        'agenda' => $agenda,
                        'type' => 2, //Scheduled Meeting
                        'start_time' => $meetingTime,
                        'timezone' => $meetingTimeZone
                    ]
                ]);
            } catch (GuzzleException $exception) {
                abort($exception->getCode(), $exception->getMessage());
            }

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
                    'body' => [
                        "update" => [
                            "fields" => [
                                "resource" => $meetingURL
                            ]
                        ]
                    ]
                ]);

                $result2 = $jiraClient->put('/issue/' . $issueKey . '/comment', [
                    'body' => [
                        "body" => 'Your Zoom meeting URL is: ' . $meetingURL
                    ]
                ]);

                return json_encode((object)['ZoomRequest' => $zoomResponse, 'JiraRequests' => [$result1, $result2]]);

            } else {
                abort($zoomResponse->getStatusCode(), $zoomResponse->getBody()->getContents());
            }
        } else {
            abort(403, 'API Key Error');
        }
    }
}
