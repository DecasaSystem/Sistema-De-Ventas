<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint'   => 'required|string',
            'p256dh'     => 'required|string',
            'auth_token' => 'required|string',
        ]);

        PushSubscription::updateOrCreate(
            ['usuario_id' => $request->user()->id, 'endpoint' => $data['endpoint']],
            ['p256dh' => $data['p256dh'], 'auth_token' => $data['auth_token']]
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request)
    {
        $endpoint = $request->input('endpoint');

        PushSubscription::where('usuario_id', $request->user()->id)
            ->where('endpoint', $endpoint)
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function vapidKey()
    {
        return response()->json(['key' => config('app.vapid_public_key')]);
    }
}
