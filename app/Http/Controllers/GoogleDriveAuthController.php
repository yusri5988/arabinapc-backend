<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;

class GoogleDriveAuthController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirect()
    {
        $client = $this->getGoogleClient();
        $authUrl = $client->createAuthUrl();

        return redirect()->away($authUrl);
    }

    /**
     * Handle the callback from Google.
     */
    public function callback(Request $request)
    {
        $client = $this->getGoogleClient();

        if ($request->has('code')) {
            $token = $client->fetchAccessTokenWithAuthCode($request->get('code'));
            
            if (isset($token['error'])) {
                return response()->json(['error' => $token['error_description']], 400);
            }

            return response()->json([
                'message' => 'Copy the refresh_token below and add it to your .env file as GOOGLE_DRIVE_REFRESH_TOKEN',
                'refresh_token' => $token['refresh_token'] ?? 'No refresh token found. Ensure you are linking for the first time or use prompt=consent',
            ]);
        }

        return response()->json(['error' => 'No authorization code provided'], 400);
    }

    /**
     * Initialize Google Client.
     */
    private function getGoogleClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId(config('filesystems.disks.google.clientId'));
        $client->setClientSecret(config('filesystems.disks.google.clientSecret'));
        $client->setRedirectUri(env('GOOGLE_DRIVE_REDIRECT_URI'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->addScope(GoogleDrive::DRIVE_FILE);

        return $client;
    }
}
