<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    protected $fillable = ['usuario_id', 'endpoint', 'p256dh', 'auth_token'];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }
}
